import type { Bot, Context } from "grammy";
import { runAgentPhase } from "../shared/agent-session.js";
import { buildPage } from "../shared/page-builder.js";
import { isLocked, getLockInfo } from "../shared/lock.js";
import { getBudgetSummary } from "../shared/budget-tracker.js";
import { AgentStreamHandler } from "./streaming.js";
import { sendScreenshot, extractScreenshotsFromOutput } from "./screenshots.js";
import type { IndustryBrief, BatchContext } from "../shared/types.js";
import { readFile, readdir } from "node:fs/promises";
import { join, dirname } from "node:path";
import { existsSync } from "node:fs";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const BRIEFS_DIR = join(__dirname, "../../briefs");
const DATA_DIR = process.env.DATA_DIR || "/data";

async function loadBrief(name: string): Promise<IndustryBrief | null> {
  const path = join(BRIEFS_DIR, `${name}.json`);
  if (!existsSync(path)) return null;
  return JSON.parse(await readFile(path, "utf-8"));
}

async function loadBatchContext(): Promise<BatchContext | null> {
  const today = new Date().toISOString().split("T")[0];
  const path = join(DATA_DIR, "batch-context", `${today}.json`);
  if (!existsSync(path)) return null;
  return JSON.parse(await readFile(path, "utf-8"));
}

async function listAvailableBriefs(): Promise<string[]> {
  const files = await readdir(BRIEFS_DIR).catch(() => []);
  return files.filter((f) => f.endsWith(".json")).map((f) => f.replace(".json", ""));
}

export function registerCommands(bot: Bot) {
  bot.command("start", async (ctx) => {
    await ctx.reply(
      `🤖 *Bricks Agent Service*\n\n` +
        `Befehle:\n` +
        `/build <branche> — Page bauen (z.B. /build zahnarzt)\n` +
        `/qa <page_id> — QA-Check\n` +
        `/screenshot <page_id> — Screenshot holen\n` +
        `/status — Aktueller Status\n` +
        `/briefs — Verfügbare Branchen\n` +
        `/budget — Budget-Übersicht\n` +
        `/help — Diese Hilfe`,
      { parse_mode: "Markdown" }
    );
  });

  bot.command("help", async (ctx) => {
    await ctx.reply(
      `🔧 *Alle Befehle*\n\n` +
        `*Bauen:*\n` +
        `/build zahnarzt — Kompletter 5-Agent-Build\n` +
        `/build anwalt — Anwalts-Page bauen\n\n` +
        `*QA & Fixes:*\n` +
        `/qa 3001 — QA-Check auf Page\n` +
        `/fix 3001 overflow — Bug fixen\n` +
        `/screenshot 3001 — Screenshot holen\n` +
        `/score 3001 — Design-Score\n\n` +
        `*Status:*\n` +
        `/status — Overnight-Build Status\n` +
        `/budget — Budget-Übersicht\n` +
        `/briefs — Verfügbare Branchen\n` +
        `/learnings — Neueste Learnings`,
      { parse_mode: "Markdown" }
    );
  });

  bot.command("briefs", async (ctx) => {
    const briefs = await listAvailableBriefs();
    if (briefs.length === 0) {
      await ctx.reply("Keine Branchen-Briefs gefunden.");
      return;
    }
    await ctx.reply(
      `📋 *Verfügbare Branchen* (${briefs.length}):\n\n` +
        briefs.map((b) => `• \`/build ${b}\``).join("\n"),
      { parse_mode: "Markdown" }
    );
  });

  bot.command("status", async (ctx) => {
    if (isLocked()) {
      const info = getLockInfo();
      if (info) {
        await ctx.reply(
          `🌙 *Overnight-Build läuft*\n\n` +
            `Page ${info.currentPage}/${info.totalPages}\n` +
            `Branche: ${info.currentIndustry}\n` +
            `Gestartet: ${info.startedAt}`,
          { parse_mode: "Markdown" }
        );
        return;
      }
    }
    await ctx.reply("💤 Kein Build aktiv. Bereit für Befehle.");
  });

  bot.command("budget", async (ctx) => {
    const summary = getBudgetSummary();
    await ctx.reply(
      `💰 *Budget*\n\n` +
        `Aktuelle Page: $${summary.currentPage.spent.toFixed(2)} / $${summary.currentPage.limit}\n` +
        `Batch gesamt: $${summary.batch.spent.toFixed(2)} / $${summary.batch.limit}\n` +
        `Pages gebaut: ${summary.batch.pagesBuilt}`,
      { parse_mode: "Markdown" }
    );
  });

  bot.command("build", async (ctx) => {
    const briefName = ctx.match?.trim();
    if (!briefName) {
      const briefs = await listAvailableBriefs();
      await ctx.reply(
        `Nutzung: \`/build <branche>\`\n\nVerfügbar: ${briefs.join(", ")}`,
        { parse_mode: "Markdown" }
      );
      return;
    }

    if (isLocked()) {
      const info = getLockInfo();
      await ctx.reply(
        `⏳ Overnight-Build läuft (${info?.currentPage}/${info?.totalPages}). Warte oder /stop.`
      );
      return;
    }

    const brief = await loadBrief(briefName.toLowerCase());
    if (!brief) {
      const briefs = await listAvailableBriefs();
      await ctx.reply(
        `❌ Branche "${briefName}" nicht gefunden.\n\nVerfügbar: ${briefs.join(", ")}`
      );
      return;
    }

    const stream = new AgentStreamHandler(ctx);
    await stream.start(`Build: ${brief.title}`);

    try {
      const batchCtx = await loadBatchContext();
      const result = await buildPage(brief, 0, batchCtx, (phase, phaseResult) => {
        const icon = phaseResult.success ? "✅" : "❌";
        stream.updatePhase(
          phase,
          `${icon} $${phaseResult.costUsd.toFixed(3)} · ${(phaseResult.durationMs / 1000).toFixed(0)}s`
        );
      });

      const screenshots = extractScreenshotsFromOutput(
        Object.values(result.phases)
          .map((p) => p.output)
          .join("\n")
      );

      for (const ss of screenshots.slice(0, 3)) {
        await sendScreenshot(ctx, ss, `${brief.industry} — Page ${result.pageId}`);
      }

      await stream.finish(
        `*${brief.industry}* — Page ${result.pageId}\n` +
          `Score: ${result.qaScore}/100\n` +
          `Fix-Loops: ${result.fixIterations}\n` +
          `Kosten: $${result.totalCostUsd.toFixed(2)}\n` +
          `Dauer: ${(result.totalDurationMs / 1000 / 60).toFixed(1)} min\n` +
          `Status: ${result.status}`
      );
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      await stream.error(msg);
    } finally {
      stream.destroy();
    }
  });

  bot.command("qa", async (ctx) => {
    const pageId = parseInt(ctx.match?.trim() || "", 10);
    if (!pageId) {
      await ctx.reply("Nutzung: `/qa <page_id>` (z.B. `/qa 3001`)", {
        parse_mode: "Markdown",
      });
      return;
    }

    const stream = new AgentStreamHandler(ctx);
    await stream.start(`QA: Page ${pageId}`);

    try {
      const result = await runAgentPhase({
        agentName: "qa",
        systemPrompt: await readFile(join(__dirname, "../../agents/qa.md"), "utf-8"),
        userPrompt: `QA-Check für Page ${pageId}. Führe bricks_verify_page, bricks_design_score, bricks_accessibility_audit, bricks_performance, bricks_check_contrast und bricks_screenshot aus. Erstelle ein QA-Report-JSON.`,
        tools: [
          "mcp__bricks__bricks_verify_page",
          "mcp__bricks__bricks_screenshot",
          "mcp__bricks__bricks_design_score",
          "mcp__bricks__bricks_accessibility_audit",
          "mcp__bricks__bricks_performance",
          "mcp__bricks__bricks_check_contrast",
          "mcp__bricks__bricks_readability",
        ],
        maxTurns: 20,
        maxBudgetUsd: 0.3,
      });

      const screenshots = extractScreenshotsFromOutput(result.output);
      for (const ss of screenshots.slice(0, 2)) {
        await sendScreenshot(ctx, ss, `QA Page ${pageId}`);
      }

      await stream.finish(
        `*QA Page ${pageId}*\n$${result.costUsd.toFixed(3)} · ${(result.durationMs / 1000).toFixed(0)}s\n\n${result.output.slice(0, 3000)}`
      );
    } catch (err) {
      await stream.error(err instanceof Error ? err.message : String(err));
    } finally {
      stream.destroy();
    }
  });

  bot.command("screenshot", async (ctx) => {
    const pageId = parseInt(ctx.match?.trim() || "", 10);
    if (!pageId) {
      await ctx.reply("Nutzung: `/screenshot <page_id>`", { parse_mode: "Markdown" });
      return;
    }

    await ctx.api.sendChatAction(ctx.chat!.id, "upload_photo");

    try {
      const result = await runAgentPhase({
        agentName: "screenshot",
        systemPrompt: "Du machst Screenshots von Bricks-Pages.",
        userPrompt: `Mache einen Desktop- und Mobile-Screenshot von Page ${pageId} mit bricks_screenshot.`,
        tools: ["mcp__bricks__bricks_screenshot"],
        maxTurns: 5,
        maxBudgetUsd: 0.1,
      });

      const screenshots = extractScreenshotsFromOutput(result.output);
      if (screenshots.length > 0) {
        for (const ss of screenshots) {
          await sendScreenshot(ctx, ss, `Page ${pageId}`);
        }
      } else {
        await ctx.reply(`Screenshot für Page ${pageId} konnte nicht erstellt werden.`);
      }
    } catch (err) {
      await ctx.reply(`❌ Fehler: ${err instanceof Error ? err.message : String(err)}`);
    }
  });

  bot.command("fix", async (ctx) => {
    const args = ctx.match?.trim() || "";
    const parts = args.split(/\s+/);
    const pageId = parseInt(parts[0] || "", 10);
    const issue = parts.slice(1).join(" ");

    if (!pageId || !issue) {
      await ctx.reply("Nutzung: `/fix <page_id> <problem>` (z.B. `/fix 3001 overflow auf mobile`)", {
        parse_mode: "Markdown",
      });
      return;
    }

    const stream = new AgentStreamHandler(ctx);
    await stream.start(`Fix: Page ${pageId} — ${issue}`);

    try {
      const result = await runAgentPhase({
        agentName: "fix",
        systemPrompt: await readFile(join(__dirname, "../../agents/update.md"), "utf-8"),
        userPrompt: `Fixe folgendes Problem auf Page ${pageId}: "${issue}"\n\nErstelle ZUERST einen Snapshot mit bricks_create_snapshot, dann patche mit bricks_patch_page. Danach bricks_purge_cache.`,
        tools: [
          "mcp__bricks__bricks_create_snapshot",
          "mcp__bricks__bricks_patch_page",
          "mcp__bricks__bricks_get_page",
          "mcp__bricks__bricks_update_scripts",
          "mcp__bricks__bricks_update_page_assets",
          "mcp__bricks__bricks_purge_cache",
          "mcp__bricks__bricks_screenshot",
        ],
        maxTurns: 15,
        maxBudgetUsd: 0.3,
      });

      await stream.finish(
        `*Fix Page ${pageId}*\n$${result.costUsd.toFixed(3)} · ${(result.durationMs / 1000).toFixed(0)}s\n\n${result.output.slice(0, 2000)}`
      );
    } catch (err) {
      await stream.error(err instanceof Error ? err.message : String(err));
    } finally {
      stream.destroy();
    }
  });

  bot.command("score", async (ctx) => {
    const pageId = parseInt(ctx.match?.trim() || "", 10);
    if (!pageId) {
      await ctx.reply("Nutzung: `/score <page_id>`", { parse_mode: "Markdown" });
      return;
    }

    await ctx.api.sendChatAction(ctx.chat!.id, "typing");

    try {
      const result = await runAgentPhase({
        agentName: "score",
        systemPrompt: "Du bewertest Bricks-Pages mit Design-Score und Accessibility-Audit.",
        userPrompt: `Bewerte Page ${pageId}: bricks_design_score + bricks_accessibility_audit + bricks_check_contrast_apca. Gib eine kurze Zusammenfassung mit Score und Top-3-Issues.`,
        tools: [
          "mcp__bricks__bricks_design_score",
          "mcp__bricks__bricks_accessibility_audit",
          "mcp__bricks__bricks_check_contrast_apca",
          "mcp__bricks__bricks_sophistication_score",
        ],
        maxTurns: 10,
        maxBudgetUsd: 0.15,
      });

      await ctx.reply(
        `📊 *Score Page ${pageId}*\n\n${result.output.slice(0, 3500)}`,
        { parse_mode: "Markdown" }
      );
    } catch (err) {
      await ctx.reply(`❌ ${err instanceof Error ? err.message : String(err)}`);
    }
  });

  bot.command("learnings", async (ctx) => {
    await ctx.api.sendChatAction(ctx.chat!.id, "typing");

    try {
      const result = await runAgentPhase({
        agentName: "learnings",
        systemPrompt: "Du zeigst die neuesten Learnings aus dem Bricks-MCP Learning-System.",
        userPrompt: "Rufe bricks_get_learnings auf und zeige die 5 neuesten Learnings mit Datum, Typ und Zusammenfassung.",
        tools: [
          "mcp__bricks__bricks_get_learnings",
          "mcp__bricks__bricks_learning_stats",
        ],
        maxTurns: 5,
        maxBudgetUsd: 0.1,
      });

      await ctx.reply(
        `📚 *Neueste Learnings*\n\n${result.output.slice(0, 3500)}`,
        { parse_mode: "Markdown" }
      );
    } catch (err) {
      await ctx.reply(`❌ ${err instanceof Error ? err.message : String(err)}`);
    }
  });

  bot.on("message:text", async (ctx) => {
    const text = ctx.message.text;
    if (text.startsWith("/")) return;

    const stream = new AgentStreamHandler(ctx);
    await stream.start("Agent");

    try {
      const result = await runAgentPhase({
        agentName: "chat",
        systemPrompt: `Du bist ein Bricks Builder Assistent mit Zugriff auf 105 MCP-Tools.
Du kannst Pages bauen, editieren, QA-Checks machen, Screenshots erstellen, SEO optimieren, und vieles mehr.

Erkenne die Absicht des Users und nutze die passenden Tools:
- "Bau mir eine Zahnarzt-Seite" → bricks_create_page + bricks_suggest_design_profile + bricks_generate_section
- "Zeig mir Page 3001" → bricks_get_page + bricks_screenshot
- "Fix den Overflow auf Page 3001" → bricks_create_snapshot + bricks_patch_page + bricks_purge_cache
- "QA Check auf Page 3001" → bricks_verify_page + bricks_design_score + bricks_accessibility_audit
- "Screenshot von Page 3001" → bricks_screenshot
- "Welche Learnings gibt es?" → bricks_get_learnings
- "Liste alle Pages" → bricks_list_pages

WICHTIG: Vor jedem Push (bricks_update_page, bricks_patch_page) IMMER zuerst bricks_create_snapshot.
Antworte auf Deutsch, kurz und hilfreich.`,
        userPrompt: text,
        tools: [],  // Leere Liste = ALLE MCP-Tools verfügbar
        maxTurns: 25,
        maxBudgetUsd: 0.5,
      });

      const screenshots = extractScreenshotsFromOutput(result.output);
      for (const ss of screenshots.slice(0, 3)) {
        await sendScreenshot(ctx, ss, "Screenshot");
      }

      await stream.finish(result.output.slice(0, 3500));
    } catch (err) {
      await stream.error(err instanceof Error ? err.message : String(err));
    } finally {
      stream.destroy();
    }
  });
}
