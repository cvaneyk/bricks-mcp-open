import { runAgentPhase } from "./agent-session.js";
import type {
  IndustryBrief,
  BatchContext,
  PageBuildResult,
  PhaseResult,
} from "./types.js";
import { readFile } from "node:fs/promises";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const AGENTS_DIR = join(__dirname, "../../agents");

async function loadAgentPrompt(name: string): Promise<string> {
  return readFile(join(AGENTS_DIR, `${name}.md`), "utf-8");
}

function buildHistorianBriefingPrompt(
  brief: IndustryBrief,
  batchCtx: BatchContext | null
): string {
  let prompt = `Erstelle ein Historian Briefing für einen ${brief.industry}-Page-Build.
Branche: ${brief.industry}
Titel: ${brief.title}
Beschreibung: ${brief.description}
Design-Profil: ${brief.designProfile}

Nutze bricks_get_learnings, bricks_build_suggestions und bricks_learning_patterns um relevante Learnings zu laden.
Erstelle eine strukturierte Briefing-JSON mit: warnings, anti_patterns, recommended_patterns, design_hints.`;

  if (batchCtx && batchCtx.pagesCompleted.length > 0) {
    prompt += `\n\n## Context von heute Nacht (${batchCtx.pagesCompleted.length} Pages bereits gebaut)
Bekannte Anti-Patterns die NICHT wiederholt werden sollen:
${batchCtx.tonightAntiPatterns.map((p) => `- ${p}`).join("\n")}

Effektive Fixes die proaktiv angewendet werden sollen:
${batchCtx.tonightEffectiveFixes.map((f) => `- ${f}`).join("\n")}`;
  }

  return prompt;
}

function buildDesignPrompt(brief: IndustryBrief, historianOutput: string): string {
  return `Erstelle Design-Tokens für eine ${brief.industry}-Website.

## Brief
Titel: ${brief.title}
Beschreibung: ${brief.description}
Sections: ${brief.sections.join(", ")}
Design-Profil: ${brief.designProfile}
${brief.colorHints ? `Farb-Hints: ${brief.colorHints.join(", ")}` : ""}
${brief.typography ? `Typographie: ${brief.typography}` : ""}

## Historian Briefing
${historianOutput}

Nutze bricks_suggest_design_profile, bricks_oklch_palette, bricks_suggest_typography, bricks_suggest_page_flow.
Erstelle ein komplettes Design-Handoff-JSON mit: palette, typography, section_plan, id_prefix, spacing.`;
}

function buildCodePrompt(
  brief: IndustryBrief,
  pageId: number,
  designOutput: string,
  antiPatterns: string[]
): string {
  let prompt = `Baue die Bricks-Elemente für Page ${pageId} (${brief.industry}).

## Design Handoff
${designOutput}

Nutze bricks_generate_section und bricks_instantiate_section aus den bestehenden Presets.
Validiere mit bricks_validate_elements und bricks_auto_check_known_bugs.
Erstelle ein CodeHandoff-JSON mit: elements (Array), scripts (CSS/JS), element_count.`;

  if (antiPatterns.length > 0) {
    prompt += `\n\n## KRITISCH: Diese Anti-Patterns NICHT wiederholen
${antiPatterns.map((p) => `- ❌ ${p}`).join("\n")}`;
  }

  if (brief.specialInstructions) {
    prompt += `\n\n## Spezielle Anweisungen\n${brief.specialInstructions}`;
  }

  return prompt;
}

function buildUpdatePrompt(pageId: number, codeOutput: string): string {
  return `Pushe die Elemente auf Page ${pageId}.

## Code Handoff
${codeOutput}

WICHTIG: Erstelle ZUERST einen Snapshot mit bricks_create_snapshot, DANN pushe mit bricks_update_page.
Danach: bricks_update_page_assets für CSS/JS, bricks_set_gsap_flag falls GSAP verwendet, bricks_purge_cache.
Bestätige mit: snapshot_id, push_status, element_count.`;
}

function buildQaPrompt(pageId: number, brief: IndustryBrief): string {
  return `QA-Check für Page ${pageId} (${brief.industry}).

Führe aus:
1. bricks_verify_page — Element-Integrität + Responsive-Check
2. bricks_design_score — Visueller Score
3. bricks_accessibility_audit — WCAG-Compliance
4. bricks_performance — Ladezeit-Analyse
5. bricks_check_contrast — Kontrast-Prüfung
6. bricks_screenshot — Screenshots für Report

Erstelle ein QA-Report-JSON mit: score (0-100), issues[], screenshots[], recommendations[].
Score-Bedeutung: 90+ = excellent, 80-89 = good, 70-79 = acceptable, <70 = needs_fixes.`;
}

function buildFixPrompt(pageId: number, qaOutput: string, attempt: number): string {
  return `Fix-Iteration ${attempt}/3 für Page ${pageId}.

## QA-Report mit Issues
${qaOutput}

Behebe die kritischsten Issues zuerst. Nutze bricks_patch_page für CSS-Fixes, bricks_update_scripts für JS-Fixes.
WICHTIG: bricks_create_snapshot ZUERST, dann patchen, dann bricks_purge_cache.
Beschreibe was du gefixt hast als JSON: fixes_applied[], remaining_issues[].`;
}

function buildRetrospectivePrompt(
  brief: IndustryBrief,
  result: Partial<PageBuildResult>
): string {
  return `Historian Retrospective für ${brief.industry}-Build (Page ${result.pageId}).

QA-Score: ${result.qaScore}/100
Fix-Iterations: ${result.fixIterations}
Anti-Patterns entdeckt: ${result.antiPatternsDiscovered?.join(", ") || "keine"}
Effektive Fixes: ${result.effectiveFixes?.join(", ") || "keine"}

Logge Learnings mit bricks_log_critique, bricks_log_anti_pattern, bricks_log_build.
Analysiere Patterns mit bricks_learning_patterns und bricks_learning_effectiveness.
Erstelle ein Retrospective-JSON mit: learnings_created, anti_patterns_logged, build_logged.`;
}

// Tool lists use the MCP tool names without the mcp__bricks__ prefix.
// The filterTools() function strips the prefix automatically.
// Tools marked [premium] require the premium MCP edition and are
// skipped gracefully if unavailable.

const LEARNING_TOOLS: string[] = [];
// Premium: bricks_get_learnings, bricks_build_suggestions,
// bricks_learning_patterns, bricks_log_critique, bricks_log_anti_pattern

const DESIGN_TOOLS = [
  "mcp__bricks__bricks_list_presets",
  "mcp__bricks__bricks_get_color_palette",
  "mcp__bricks__bricks_list_fonts",
  "mcp__bricks__bricks_get_breakpoints",
  "mcp__bricks__bricks_get_theme_styles",
];

const CODE_TOOLS = [
  "mcp__bricks__bricks_instantiate_section",
  "mcp__bricks__bricks_list_presets",
  "mcp__bricks__bricks_validate_elements",
  "mcp__bricks__bricks_generate_bem_component",
  "mcp__bricks__bricks_html_to_bricks",
];

const UPDATE_TOOLS = [
  "mcp__bricks__bricks_create_snapshot",
  "mcp__bricks__bricks_update_page",
  "mcp__bricks__bricks_patch_page",
  "mcp__bricks__bricks_update_page_assets",
  "mcp__bricks__bricks_update_scripts",
  "mcp__bricks__bricks_set_gsap_flag",
  "mcp__bricks__bricks_purge_cache",
  "mcp__bricks__bricks_register_font",
  "mcp__bricks__bricks_upload_media",
];

const QA_TOOLS = [
  "mcp__bricks__bricks_get_page",
  "mcp__bricks__bricks_seo_analyze",
  "mcp__bricks__bricks_readability",
  "mcp__bricks__bricks_check_links",
  "mcp__bricks__bricks_validate_elements",
];

function extractScore(output: string): number {
  const match = output.match(/"score"\s*:\s*(\d+)/);
  return match ? parseInt(match[1], 10) : 0;
}

function extractList(output: string, key: string): string[] {
  const regex = new RegExp(`"${key}"\\s*:\\s*\\[([^\\]]*)]`, "s");
  const match = output.match(regex);
  if (!match) return [];
  return match[1]
    .split(",")
    .map((s) => s.trim().replace(/^"|"$/g, ""))
    .filter(Boolean);
}

export async function buildPage(
  brief: IndustryBrief,
  pageId: number,
  batchCtx: BatchContext | null,
  onPhaseComplete?: (phase: string, result: PhaseResult) => void
): Promise<PageBuildResult> {
  const startTime = Date.now();
  const phases: Record<string, PhaseResult> = {};
  const antiPatterns: string[] = [
    ...(batchCtx?.tonightAntiPatterns || []),
  ];
  const effectiveFixes: string[] = [];
  let qaScore = 0;
  let fixIterations = 0;

  // Phase 1: Historian Briefing
  const historianPrompt = await loadAgentPrompt("historian");
  const briefingResult = await runAgentPhase({
    agentName: "historian-briefing",
    systemPrompt: historianPrompt,
    userPrompt: buildHistorianBriefingPrompt(brief, batchCtx),
    tools: LEARNING_TOOLS,
    maxTurns: 10,
    maxBudgetUsd: 0.20,
  });
  phases["historian-briefing"] = briefingResult;
  onPhaseComplete?.("historian-briefing", briefingResult);

  // Phase 2: Design Agent
  const designPrompt = await loadAgentPrompt("design");
  const designResult = await runAgentPhase({
    agentName: "design",
    systemPrompt: designPrompt,
    userPrompt: buildDesignPrompt(brief, briefingResult.output),
    tools: DESIGN_TOOLS,
    maxTurns: 20,
    maxBudgetUsd: 0.30,
  });
  phases["design"] = designResult;
  onPhaseComplete?.("design", designResult);

  if (!designResult.success) {
    return buildFailedResult(pageId, brief, phases, startTime, "Design failed");
  }

  // Phase 3: Code Agent
  const codePrompt = await loadAgentPrompt("code");
  const codeResult = await runAgentPhase({
    agentName: "code",
    systemPrompt: codePrompt,
    userPrompt: buildCodePrompt(brief, pageId, designResult.output, antiPatterns),
    tools: CODE_TOOLS,
    maxTurns: 30,
    maxBudgetUsd: 0.50,
  });
  phases["code"] = codeResult;
  onPhaseComplete?.("code", codeResult);

  if (!codeResult.success) {
    return buildFailedResult(pageId, brief, phases, startTime, "Code failed");
  }

  // Phase 4: Update Agent
  const updatePrompt = await loadAgentPrompt("update");
  const updateResult = await runAgentPhase({
    agentName: "update",
    systemPrompt: updatePrompt,
    userPrompt: buildUpdatePrompt(pageId, codeResult.output),
    tools: UPDATE_TOOLS,
    maxTurns: 15,
    maxBudgetUsd: 0.20,
  });
  phases["update"] = updateResult;
  onPhaseComplete?.("update", updateResult);

  if (!updateResult.success) {
    return buildFailedResult(pageId, brief, phases, startTime, "Update/Push failed");
  }

  // Phase 5: QA Agent
  const qaPrompt = await loadAgentPrompt("qa");
  const qaResult = await runAgentPhase({
    agentName: "qa",
    systemPrompt: qaPrompt,
    userPrompt: buildQaPrompt(pageId, brief),
    tools: QA_TOOLS,
    maxTurns: 20,
    maxBudgetUsd: 0.30,
  });
  phases["qa"] = qaResult;
  onPhaseComplete?.("qa", qaResult);
  qaScore = extractScore(qaResult.output);

  // Phase 6: Fix Loop (max 3 attempts if score < 85)
  let lastQaOutput = qaResult.output;
  while (qaScore < 85 && fixIterations < 3) {
    fixIterations++;

    const fixResult = await runAgentPhase({
      agentName: `fix-${fixIterations}`,
      systemPrompt: updatePrompt,
      userPrompt: buildFixPrompt(pageId, lastQaOutput, fixIterations),
      tools: [...UPDATE_TOOLS, ...CODE_TOOLS],
      maxTurns: 20,
      maxBudgetUsd: 0.30,
    });
    phases[`fix-${fixIterations}`] = fixResult;
    onPhaseComplete?.(`fix-${fixIterations}`, fixResult);

    effectiveFixes.push(...extractList(fixResult.output, "fixes_applied"));

    const reQaResult = await runAgentPhase({
      agentName: `reqa-${fixIterations}`,
      systemPrompt: qaPrompt,
      userPrompt: buildQaPrompt(pageId, brief),
      tools: QA_TOOLS,
      maxTurns: 15,
      maxBudgetUsd: 0.20,
    });
    phases[`reqa-${fixIterations}`] = reQaResult;
    onPhaseComplete?.(`reqa-${fixIterations}`, reQaResult);

    qaScore = extractScore(reQaResult.output);
    lastQaOutput = reQaResult.output;
  }

  // Phase 7: Historian Retrospective
  const partialResult: Partial<PageBuildResult> = {
    pageId,
    qaScore,
    fixIterations,
    antiPatternsDiscovered: extractList(lastQaOutput, "issues"),
    effectiveFixes,
  };

  const retroResult = await runAgentPhase({
    agentName: "historian-retrospective",
    systemPrompt: historianPrompt,
    userPrompt: buildRetrospectivePrompt(brief, partialResult),
    tools: LEARNING_TOOLS,
    maxTurns: 10,
    maxBudgetUsd: 0.20,
  });
  phases["historian-retrospective"] = retroResult;
  onPhaseComplete?.("historian-retrospective", retroResult);

  const totalCost = Object.values(phases).reduce((sum, p) => sum + p.costUsd, 0);

  return {
    pageId,
    industry: brief.industry,
    phases,
    qaScore,
    fixIterations,
    totalCostUsd: totalCost,
    totalDurationMs: Date.now() - startTime,
    antiPatternsDiscovered: extractList(lastQaOutput, "issues"),
    effectiveFixes,
    status: qaScore >= 70 ? "success" : "failed",
  };
}

function buildFailedResult(
  pageId: number,
  brief: IndustryBrief,
  phases: Record<string, PhaseResult>,
  startTime: number,
  error: string
): PageBuildResult {
  const totalCost = Object.values(phases).reduce((sum, p) => sum + p.costUsd, 0);
  return {
    pageId,
    industry: brief.industry,
    phases,
    qaScore: 0,
    fixIterations: 0,
    totalCostUsd: totalCost,
    totalDurationMs: Date.now() - startTime,
    antiPatternsDiscovered: [],
    effectiveFixes: [],
    status: "failed",
    error,
  };
}
