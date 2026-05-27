# Bricks Agent Service

An autonomous page-building agent that uses the Bricks MCP server to design, build, deploy, and QA WordPress pages — all driven by Claude via the Anthropic SDK.

**Build entire WordPress pages from your iPhone.** The Telegram bot lets you trigger builds, run QA checks, fix bugs, and take screenshots — all from your phone. No laptop needed.

## Architecture

```
iPhone / Android / Desktop
      ↕ Telegram
Agent Service (TypeScript)
      ↕ Anthropic SDK (Claude Sonnet/Opus)
      ↕ MCP Client (stdio)
Bricks MCP Server (105 tools)
      ↕ REST API
WordPress + Bricks Builder
```

## How It Works

The agent service runs a **5-phase pipeline** for each page build:

1. **Historian Briefing** — Loads learnings from past builds, generates warnings and recommendations
2. **Design Agent** — Creates design tokens (palette, typography, spacing, section plan) from an industry brief
3. **Code Agent** — Generates Bricks elements and CSS/JS using presets and design tokens
4. **Update Agent** — Pushes elements to WordPress (snapshot first, then deploy, then cache purge)
5. **QA Agent** — Validates the page (SEO, accessibility, readability, links) and scores it 0-100

If the QA score is below 85, a **fix loop** (up to 3 iterations) patches issues and re-runs QA.

After completion, a **Historian Retrospective** logs what worked and what didn't for future builds.

## Modes

### CLI: Single Build
```bash
npx tsx src/main.ts --build zahnarzt
```

### CLI: Overnight Batch
```bash
npx tsx src/main.ts --batch
```
Builds all briefs in `briefs/` sequentially, accumulating anti-patterns across builds.

### Telegram Bot (Mobile Control)
```bash
TELEGRAM_BOT_TOKEN=xxx npx tsx src/main.ts
```

Control the entire pipeline from your phone — the Telegram app on iPhone or Android becomes your build dashboard:

**Build commands:**
- `/build zahnarzt` — Full 5-agent pipeline build
- `/qa 42` — QA check on page 42
- `/fix 42 overflow` — Fix a specific issue
- `/screenshot 42` — Take desktop + mobile screenshots
- `/score 42` — Design score + accessibility audit

**Status & monitoring:**
- `/status` — Current build status
- `/budget` — Cost tracking
- `/briefs` — Available industry templates
- `/learnings` — Recent learnings from past builds

**Free chat:**
- Send any message to chat with the agent — all 105 MCP tools available
- *"Bau mir eine Zahnarzt-Seite"* — triggers page creation
- *"Fix den Overflow auf Page 42"* — snapshot + patch + cache purge
- *"Zeig mir Page 42"* — fetches page data + screenshot

The bot streams phase progress in real-time, sends screenshots as images, and tracks costs per build.

## Setup

### Prerequisites

- Node.js >= 18
- The Bricks MCP server (parent directory) installed with `npm install`
- An Anthropic API key
- WordPress site with bricks-api-bridge plugin

### Environment Variables

```env
ANTHROPIC_API_KEY=sk-ant-...
WORDPRESS_URL=https://your-site.com
WORDPRESS_USER=admin
WORDPRESS_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx

# Optional
TELEGRAM_BOT_TOKEN=xxx
CLAUDE_MODEL=claude-sonnet-4-6
DATA_DIR=/data
PORT=8080
MCP_COMMAND=node
MCP_SERVER_PATH=../index.js
```

### Install & Run

```bash
cd agent-service
npm install

# Single build
ANTHROPIC_API_KEY=sk-ant-... npx tsx src/main.ts --build zahnarzt

# Telegram bot
ANTHROPIC_API_KEY=sk-ant-... TELEGRAM_BOT_TOKEN=xxx npx tsx src/main.ts
```

### Docker

```bash
docker build -t bricks-agent .
docker run -e ANTHROPIC_API_KEY=sk-ant-... \
           -e WORDPRESS_URL=https://your-site.com \
           -e WORDPRESS_USER=admin \
           -e WORDPRESS_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
           -e TELEGRAM_BOT_TOKEN=xxx \
           bricks-agent
```

### Railway (Recommended)

The agent service is production-tested on [Railway](https://railway.com). Deploy in one click:

1. Push this repo to GitHub
2. Create a new Railway project → **Deploy from GitHub repo**
3. Set the **Root Directory** to `agent-service`
4. Add environment variables (`ANTHROPIC_API_KEY`, `WORDPRESS_URL`, `WORDPRESS_USER`, `WORDPRESS_APP_PASSWORD`, `TELEGRAM_BOT_TOKEN`)
5. Railway auto-detects the Dockerfile and deploys

The health endpoint on port 8080 serves as Railway's health check. The Telegram bot connects via long polling — no webhook URL needed.

## Industry Briefs

Briefs are JSON files in `briefs/` that describe what to build:

```json
{
  "industry": "zahnarzt",
  "title": "Zahnarztpraxis Dr. Weber",
  "locale": "de",
  "description": "Modern dental practice...",
  "sections": ["hero", "services", "team", "reviews", "contact"],
  "designProfile": "premium",
  "colorHints": ["medical-blue", "clean-white"],
  "typography": "Modern Serif Headlines + Clean Sans Body",
  "specialInstructions": "Calming colors for anxious patients."
}
```

## Cost Control

Each agent phase has a budget cap (`maxBudgetUsd`). The total pipeline typically costs $1-3 per page build depending on complexity and fix iterations. The budget tracker prevents runaway costs.

## Premium Features

The open-source edition uses the 105-tool MCP server. With the [premium edition](mailto:info@bricksmcp.com) (260+ tools), additional pipeline features are available:

- **Learning System** — Historian agent loads/saves learnings across builds
- **Visual QA** — Puppeteer screenshots, pixel comparison, design scoring
- **Design Intelligence** — Brand archetypes, OKLCH palette generation, typography suggestions
- **Accessibility Audit** — WCAG compliance, contrast checking, readability analysis
