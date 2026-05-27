import Anthropic from "@anthropic-ai/sdk";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StdioClientTransport } from "@modelcontextprotocol/sdk/client/stdio.js";
import type { AgentPhaseConfig, PhaseResult } from "./types.js";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));

const DATA_DIR = process.env.DATA_DIR || "/data";
const MODEL = process.env.CLAUDE_MODEL || "claude-sonnet-4-6";

const anthropic = new Anthropic();

let mcpClient: Client | null = null;
let mcpTools: Anthropic.Tool[] = [];

async function ensureMcpClient(): Promise<Client> {
  if (mcpClient) return mcpClient;

  console.log("[mcp] Starting bricks-mcp server...");
  const transport = new StdioClientTransport({
    command: process.env.MCP_COMMAND || "node",
    args: [process.env.MCP_SERVER_PATH || join(__dirname, "../../../index.js")],
    env: {
      ...process.env,
      WORDPRESS_URL: process.env.WORDPRESS_URL || "",
      WORDPRESS_USER: process.env.WORDPRESS_USER || "",
      WORDPRESS_APP_PASSWORD: process.env.WORDPRESS_APP_PASSWORD || "",
      BRICKS_MCP_DATA_DIR: `${DATA_DIR}/mcp-data`,
    } as Record<string, string>,
  });

  mcpClient = new Client({ name: "bricks-agent-service", version: "0.1.0" });
  await mcpClient.connect(transport);

  const { tools } = await mcpClient.listTools();
  console.log(`[mcp] Connected — ${tools.length} tools available`);

  mcpTools = tools.map((t) => ({
    name: t.name,
    description: t.description || "",
    input_schema: t.inputSchema as Anthropic.Tool["input_schema"],
  }));

  return mcpClient;
}

function filterTools(
  allTools: Anthropic.Tool[],
  allowedPrefixed: string[]
): Anthropic.Tool[] {
  if (allowedPrefixed.length === 0) return allTools;
  const allowed = new Set(
    allowedPrefixed.map((t) => t.replace("mcp__bricks__", ""))
  );
  return allTools.filter((t) => allowed.has(t.name));
}

async function callMcpTool(
  toolName: string,
  toolInput: Record<string, unknown>
): Promise<string> {
  const client = await ensureMcpClient();
  try {
    const result = await client.callTool({ name: toolName, arguments: toolInput });
    const text = (result.content as any[])
      ?.map((c: any) => c.text || JSON.stringify(c))
      .join("\n") || JSON.stringify(result);
    return text;
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return `Error calling ${toolName}: ${msg}`;
  }
}

const sessionState = { snapshotTaken: false };

// Hermes-inspired nudge intervals
const MEMORY_NUDGE_INTERVAL = 5; // Review learnings every 5 tool-use turns
const LEARNING_REVIEW_PROMPT = `Review what happened in the last few tool calls.
If something noteworthy occurred — a fix, a workaround, a pattern that worked well,
or an anti-pattern to avoid — save it using bricks_learn_correction or bricks_log_anti_pattern.
If nothing is worth saving, continue with the task.`;

export async function runAgentPhase(
  config: AgentPhaseConfig
): Promise<PhaseResult> {
  const startTime = Date.now();
  sessionState.snapshotTaken = false;

  await ensureMcpClient();
  const tools = filterTools(mcpTools, config.tools);

  console.log(
    `[agent:${config.agentName}] Starting (model=${MODEL}, tools=${tools.length}, maxTurns=${config.maxTurns})`
  );
  console.log(
    `[agent:${config.agentName}] Prompt: ${config.userPrompt.slice(0, 200)}...`
  );

  const messages: Anthropic.MessageParam[] = [
    { role: "user", content: config.userPrompt },
  ];

  let turns = 0;
  let toolTurns = 0;
  let totalInputTokens = 0;
  let totalOutputTokens = 0;
  let finalText = "";

  try {
    while (turns < config.maxTurns) {
      turns++;

      const response = await anthropic.messages.create({
        model: MODEL,
        max_tokens: 8192,
        system: config.systemPrompt,
        tools: tools.length > 0 ? tools : undefined,
        messages,
      });

      totalInputTokens += response.usage.input_tokens;
      totalOutputTokens += response.usage.output_tokens;

      const assistantContent = response.content;
      messages.push({ role: "assistant", content: assistantContent });

      // Collect text blocks
      for (const block of assistantContent) {
        if (block.type === "text") {
          finalText = block.text;
        }
      }

      // Check for tool use
      const toolUseBlocks = assistantContent.filter(
        (b): b is Anthropic.ContentBlock & { type: "tool_use" } =>
          b.type === "tool_use"
      );

      if (toolUseBlocks.length === 0) {
        console.log(
          `[agent:${config.agentName}] Done after ${turns} turns (${response.stop_reason})`
        );
        break;
      }

      // Execute tools
      const toolResults: Anthropic.ToolResultBlockParam[] = [];
      for (const toolUse of toolUseBlocks) {
        const toolName = toolUse.name;
        console.log(`[agent:${config.agentName}] Tool: ${toolName}`);

        // Snapshot enforcement
        if (toolName === "bricks_create_snapshot") {
          sessionState.snapshotTaken = true;
        }
        if (
          (toolName === "bricks_update_page" ||
            toolName === "bricks_patch_page") &&
          !sessionState.snapshotTaken
        ) {
          toolResults.push({
            type: "tool_result",
            tool_use_id: toolUse.id,
            content: "BLOCKED: Snapshot required before push. Call bricks_create_snapshot first.",
            is_error: true,
          });
          continue;
        }

        const result = await callMcpTool(
          toolName,
          toolUse.input as Record<string, unknown>
        );
        toolResults.push({
          type: "tool_result",
          tool_use_id: toolUse.id,
          content: result.slice(0, 50000),
        });
      }

      messages.push({ role: "user", content: toolResults });

      // Hermes-inspired learning nudge: every N tool turns, prompt Claude
      // to reflect on what it learned and save useful patterns
      toolTurns++;
      if (toolTurns > 0 && toolTurns % MEMORY_NUDGE_INTERVAL === 0) {
        const hasLearningTools = tools.some(t =>
          t.name.includes("learn_correction") || t.name.includes("log_anti_pattern")
        );
        if (hasLearningTools) {
          console.log(`[agent:${config.agentName}] 💡 Learning nudge (after ${toolTurns} tool turns)`);
          messages.push({
            role: "user",
            content: `[System: ${LEARNING_REVIEW_PROMPT}]`,
          });
        }
      }
    }

    const costUsd = estimateCost(totalInputTokens, totalOutputTokens);
    console.log(
      `[agent:${config.agentName}] ✅ ${turns} turns, ${totalInputTokens}+${totalOutputTokens} tokens, ~$${costUsd.toFixed(3)}`
    );

    return {
      success: true,
      output: finalText,
      tokenUsage: { input: totalInputTokens, output: totalOutputTokens },
      costUsd,
      durationMs: Date.now() - startTime,
    };
  } catch (error) {
    const errMsg = error instanceof Error ? error.message : String(error);
    console.error(`[agent:${config.agentName}] ERROR:`, errMsg);
    const costUsd = estimateCost(totalInputTokens, totalOutputTokens);
    return {
      success: false,
      output: finalText,
      tokenUsage: { input: totalInputTokens, output: totalOutputTokens },
      costUsd,
      durationMs: Date.now() - startTime,
      error: errMsg,
    };
  }
}

function estimateCost(inputTokens: number, outputTokens: number): number {
  // Sonnet 4.6 pricing: $3/M input, $15/M output
  return (inputTokens * 3 + outputTokens * 15) / 1_000_000;
}
