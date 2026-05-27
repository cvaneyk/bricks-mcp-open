import { startHealthServer, setActivity } from "./shared/health.js";
import { buildPage } from "./shared/page-builder.js";
import type { IndustryBrief, BatchContext } from "./shared/types.js";
import { readFile, readdir, mkdir, writeFile } from "node:fs/promises";
import { join, dirname } from "node:path";
import { existsSync } from "node:fs";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = process.env.DATA_DIR || "/data";
const BRIEFS_DIR = join(__dirname, "../briefs");
const PORT = parseInt(process.env.PORT || "8080", 10);

async function ensureDataDirs() {
  const dirs = ["mcp-data", "batch-context", "reports", "screenshots"];
  for (const dir of dirs) {
    const path = join(DATA_DIR, dir);
    if (!existsSync(path)) await mkdir(path, { recursive: true });
  }
}

async function loadBrief(name: string): Promise<IndustryBrief> {
  const path = join(BRIEFS_DIR, `${name}.json`);
  return JSON.parse(await readFile(path, "utf-8"));
}

async function loadBatchContext(): Promise<BatchContext | null> {
  const today = new Date().toISOString().split("T")[0];
  const path = join(DATA_DIR, "batch-context", `${today}.json`);
  if (!existsSync(path)) return null;
  return JSON.parse(await readFile(path, "utf-8"));
}

async function saveBatchContext(ctx: BatchContext) {
  const path = join(DATA_DIR, "batch-context", `${ctx.date}.json`);
  await writeFile(path, JSON.stringify(ctx, null, 2));
}

async function runSingleBuild(briefName: string) {
  console.log(`\n🔨 Building page: ${briefName}`);
  setActivity(`building: ${briefName}`);

  const brief = await loadBrief(briefName);
  const batchCtx = await loadBatchContext();

  const result = await buildPage(brief, 0, batchCtx, (phase, phaseResult) => {
    const status = phaseResult.success ? "✅" : "❌";
    console.log(
      `  ${status} ${phase} — ${phaseResult.durationMs}ms — $${phaseResult.costUsd.toFixed(3)}`
    );
  });

  console.log(`\n📊 Result: ${result.status}`);
  console.log(`   Score: ${result.qaScore}/100`);
  console.log(`   Fix iterations: ${result.fixIterations}`);
  console.log(`   Cost: $${result.totalCostUsd.toFixed(2)}`);
  console.log(`   Duration: ${(result.totalDurationMs / 1000 / 60).toFixed(1)} min`);

  return result;
}

async function main() {
  await ensureDataDirs();

  // Startup diagnostics
  console.log("=== STARTUP DIAGNOSTICS ===");
  console.log("Architecture: Anthropic Client SDK + MCP Client (no Claude Code binary)");
  console.log(`ANTHROPIC_API_KEY: ${process.env.ANTHROPIC_API_KEY ? `set (${process.env.ANTHROPIC_API_KEY.slice(0, 12)}...)` : "⚠️  MISSING"}`);
  console.log(`WORDPRESS_URL: ${process.env.WORDPRESS_URL || "⚠️  MISSING"}`);
  console.log(`WORDPRESS_USER: ${process.env.WORDPRESS_USER || "⚠️  MISSING"}`);
  console.log(`WORDPRESS_APP_PASSWORD: ${process.env.WORDPRESS_APP_PASSWORD ? "set" : "⚠️  MISSING"}`);
  console.log(`TELEGRAM_BOT_TOKEN: ${process.env.TELEGRAM_BOT_TOKEN ? "set" : "not set"}`);
  console.log(`DATA_DIR: ${DATA_DIR}`);
  console.log(`PORT: ${PORT}`);
  console.log("===========================");

  startHealthServer(PORT);

  const args = process.argv.slice(2);

  if (args.includes("--build")) {
    const briefName = args[args.indexOf("--build") + 1];
    if (!briefName) {
      console.error("Usage: --build <brief-name> (e.g., --build zahnarzt)");
      process.exit(1);
    }
    await runSingleBuild(briefName);
    process.exit(0);
  }

  if (args.includes("--batch")) {
    const files = await readdir(BRIEFS_DIR);
    const briefs = files
      .filter((f) => f.endsWith(".json"))
      .map((f) => f.replace(".json", ""));

    console.log(`\n🌙 Starting overnight batch: ${briefs.length} pages`);

    const batchCtx: BatchContext = {
      date: new Date().toISOString().split("T")[0],
      pagesCompleted: [],
      tonightAntiPatterns: [],
      tonightEffectiveFixes: [],
    };

    for (const briefName of briefs) {
      try {
        const result = await runSingleBuild(briefName);
        batchCtx.pagesCompleted.push(result);
        batchCtx.tonightAntiPatterns.push(...result.antiPatternsDiscovered);
        batchCtx.tonightEffectiveFixes.push(...result.effectiveFixes);
        await saveBatchContext(batchCtx);
      } catch (err) {
        console.error(`❌ Failed: ${briefName}`, err);
      }
    }

    console.log(`\n📋 Batch complete: ${batchCtx.pagesCompleted.length}/${briefs.length} pages`);
    const totalCost = batchCtx.pagesCompleted.reduce((s, p) => s + p.totalCostUsd, 0);
    console.log(`   Total cost: $${totalCost.toFixed(2)}`);

    await saveBatchContext(batchCtx);
    process.exit(0);
  }

  // Default: Telegram bot mode
  const token = process.env.TELEGRAM_BOT_TOKEN;
  if (!token) {
    console.log("🤖 Bricks Agent Service running (no TELEGRAM_BOT_TOKEN set)");
    console.log("   Modes: --build <name> | --batch");
    console.log("   Set TELEGRAM_BOT_TOKEN to enable Telegram bot");
    return;
  }

  const { createBot, getWebhookHandler, setupWebhook } = await import("./telegram/bot.js");
  const { registerCommands } = await import("./telegram/commands.js");

  const bot = createBot(token);
  registerCommands(bot);

  const webhookUrl = process.env.RAILWAY_PUBLIC_DOMAIN
    ? `https://${process.env.RAILWAY_PUBLIC_DOMAIN}/telegram`
    : process.env.WEBHOOK_URL;

  // Long polling with retry on 409
  async function startPolling() {
    console.log("🤖 Telegram bot: clearing old sessions...");
    await bot.api.deleteWebhook({ drop_pending_updates: true });
    await new Promise((r) => setTimeout(r, 5000));

    bot.catch((err) => {
      console.error("Grammy error:", err.message);
    });

    console.log("🤖 Telegram bot starting (long polling)...");
    try {
      await bot.start({
        drop_pending_updates: true,
        onStart: () => console.log("✅ Bot running via long polling — ready for commands"),
      });
    } catch (err: any) {
      if (String(err).includes("409") || String(err).includes("Conflict")) {
        console.log("Grammy 409 — retrying in 10s...");
        await new Promise((r) => setTimeout(r, 10000));
        return startPolling();
      }
      throw err;
    }
  }

  process.on("uncaughtException", (err) => {
    if (String(err).includes("409") || String(err).includes("Conflict")) {
      console.log("Grammy 409 uncaught (ignored)");
      return;
    }
    console.error("Uncaught:", err);
    process.exit(1);
  });

  await startPolling();
}

main().catch((err) => {
  console.error("Fatal:", err);
  process.exit(1);
});
