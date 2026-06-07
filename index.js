#!/usr/bin/env node
/**
 * Bricks Builder MCP Server v1.0.0 — Open Source Edition
 *
 * A Model Context Protocol server that provides 100+ tools for managing
 * Bricks Builder pages, templates, styles, and content via the
 * bricks-api-bridge WordPress plugin REST endpoints.
 *
 * https://github.com/developer2013/bricks-mcp-open
 */
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import express from "express";
import { SSEServerTransport } from "@modelcontextprotocol/sdk/server/sse.js";
import {
  ListToolsRequestSchema, CallToolRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import config from './config.js';
import { initSites, getActiveSite, validateActiveSite } from './site-manager.js';
import { wpGetCached } from './utils/wp-api.js';
import { TTL } from './utils/cache.js';

import {
  pageTools, searchTools, scriptTools, assetTools, seoTools, schemaTools, seoAuditTools,
  templateTools, backupTools, snapshotTools, mediaTools,
  themeStylesTools, batchTools, presetTools, globalClassesTools,
  styleSystemTools, connectionTools, converterTools,
  siteTools, advancedSeoTools, siteManagementTools,
  wpContentTools, menuTools, securityTools, observabilityTools,
} from './tools/index.js';
import { setServer as setBatchServer } from './tools/batch.js';
import * as callLog from './utils/call-log.js';

// Initialize multi-site manager (loads sites.json or falls back to env)
initSites();

// Log startup information (stderr so it doesn't interfere with stdio transport)
const activeSite = getActiveSite();
const { valid: configValid, missing } = validateActiveSite();
console.error(`STARTING ${config.SERVER_NAME.toUpperCase()} MCP SERVER v${config.SERVER_VERSION}`);
console.error(`Active site: ${activeSite.label} [${activeSite.key}] → ${activeSite.url}`);
if (!configValid) {
  console.error(`Missing credentials: ${missing.join(', ')}`);
}

// Combine all tools
const TOOLS = [
  ...siteTools,
  ...pageTools,
  ...searchTools,
  ...scriptTools,
  ...assetTools,
  ...seoTools,
  ...schemaTools,
  ...seoAuditTools,
  ...templateTools,
  ...backupTools,
  ...snapshotTools,
  ...mediaTools,
  ...themeStylesTools,
  ...batchTools,
  ...presetTools,
  ...globalClassesTools,
  ...styleSystemTools,
  ...connectionTools,
  ...converterTools,
  ...advancedSeoTools,
  ...siteManagementTools,
  ...wpContentTools,
  ...menuTools,
  ...securityTools,
  ...observabilityTools,
];

// O(1) tool lookup via Map (instead of O(n) array.find)
const TOOL_MAP = new Map();
for (const t of TOOLS) TOOL_MAP.set(t.name, t);
console.error(`Registered ${TOOLS.length} tools (Map dispatch)`);

// Pre-compute tools/list response (computed once, returned on every list call)
const TOOLS_LIST_RESPONSE = Object.freeze({
  tools: TOOLS.map(t => ({
    name: t.name,
    description: t.description,
    inputSchema: t.inputSchema,
  })),
});

// Create server
const server = new Server(
  { name: config.SERVER_NAME, version: config.SERVER_VERSION },
  { capabilities: { tools: {} }, instructions: config.BUILD_INSTRUCTIONS }
);

// Pass server reference for progress notifications
setBatchServer(server);

// Handle tools/list — return pre-computed response
server.setRequestHandler(ListToolsRequestSchema, async () => TOOLS_LIST_RESPONSE);

// Handle tools/call — O(1) Map dispatch
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args = {} } = request.params;
  console.error(`TOOL CALL: ${name}`);

  const tool = TOOL_MAP.get(name);
  if (!tool) {
    return {
      content: [{ type: "text", text: `Tool not found: ${name}` }],
      isError: true,
    };
  }

  // Structured call/diff log (best-effort, never affects the call result).
  const logging = callLog.isEnabled();
  const started = Date.now();
  let before = null;
  if (logging) {
    try { before = await callLog.captureBefore(name, args); } catch { /* ignore */ }
  }

  try {
    const result = await tool.handler(args);
    if (logging) {
      try {
        const diff = await callLog.finalizeDiff(name, args, before);
        callLog.logCall({
          tool: name, args,
          status: result && result.isError ? 'error' : 'ok',
          durationMs: Date.now() - started,
          diff,
        });
      } catch { /* logging must never break a call */ }
    }
    return result;
  } catch (error) {
    if (logging) {
      try {
        callLog.logCall({ tool: name, args, status: 'error', error: error.message, durationMs: Date.now() - started, diff: null });
      } catch { /* ignore */ }
    }
    console.error(`Error in ${name}:`, error);
    return {
      content: [{ type: "text", text: `Error: ${error.message}` }],
      isError: true,
    };
  }
});


const app = express();
const PORT = process.env.PORT || 3000;

// Almacenar los transportes activos por sessionId
const transports = new Map();

// 1. Endpoint SSE: n8n se conectará aquí para recibir eventos del servidor
app.get("/sse", async (req, res) => {
  console.error("Nueva conexión SSE establecida");
  
  // El segundo parámetro "/messages" es la ruta donde se enviarán los mensajes POST
  const transport = new SSEServerTransport("/messages", res);
  transports.set(transport.sessionId, transport);
  
  res.on("close", () => {
    console.error(`Conexión SSE cerrada para la sesión: ${transport.sessionId}`);
    transports.delete(transport.sessionId);
  });
  
  await server.connect(transport);
});

// 2. Endpoint de mensajes: n8n enviará peticiones POST aquí
app.post("/messages", express.json(), async (req, res) => {
  const sessionId = req.query.sessionId;
  const transport = transports.get(sessionId);
  
  if (transport) {
    await transport.handlePostMessage(req, res);
  } else {
    res.status(404).send("Sesión de transporte no encontrada");
  }
});

// Levantar el servidor HTTP
app.listen(PORT, () => {
  console.error(`Servidor Bricks MCP escuchando en SSE en http://localhost:${PORT}`);
  console.error(`- Endpoint SSE: http://localhost:${PORT}/sse`);
  console.error(`- Endpoint de Mensajes: http://localhost:${PORT}/messages`);
});
