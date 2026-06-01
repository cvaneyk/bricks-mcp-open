/**
 * Structured call/diff log — observability for the MCP.
 *
 * Records every tool call (tool, summarized args, status, duration) as one
 * JSON line, and for page-mutating calls also a structural before/after diff
 * (element-count delta + added/removed element IDs). This answers the question
 * a named snapshot cannot after a bad build: "which call changed the page, and
 * with what input?" — without throwing away the evidence the way a rollback does.
 *
 * Design goals (kept deliberately cheap so it does not bloat or slow the server):
 *   - Light log for ALL calls = zero extra requests (the call already passes through).
 *   - Full before/after diff only for the handful of page-data-mutating tools.
 *   - The "before" snapshot is served from cache when the page was read recently
 *     (the common read→mutate agent flow), so it usually costs no extra request.
 *   - Args are SUMMARIZED, not stored verbatim — large arrays/strings collapse to
 *     a shape descriptor, keeping each log line small.
 *   - JSONL with a size cap + single-file rotation, so the log never grows unbounded.
 *   - Every operation here is best-effort and swallow-on-error: logging must NEVER
 *     break a tool call.
 *
 * Flags:
 *   BAB_CALL_LOG=0       → disable logging entirely (default: on)
 *   BAB_CALL_LOG_DIFF=0  → keep the light log but skip before/after diffs (default: on)
 *   BAB_CALL_LOG_PATH    → override log file path
 */
import fs from 'fs';
import path from 'path';
import os from 'os';
import { wpGet, wpGetCached } from './wp-api.js';

const MAX_BYTES   = 5 * 1024 * 1024; // rotate at 5 MB
const ARG_STR_MAX = 200;             // truncate long string args
const BEFORE_TTL  = 15000;           // reuse a page read within 15s as the "before"

// Tools whose element tree (bricks_data) changes → eligible for a structural diff.
const ELEMENT_DIFF_TOOLS = new Set([
  'bricks_update_page',
  'bricks_patch_page',
  'bricks_append_elements',
  'bricks_build_page',
]);

// Raw code-surface writes → logged as mutating, but no element diff (raw blob).
const RAW_WRITE_TOOLS = new Set([
  'bricks_update_scripts',
  'bricks_update_page_assets',
]);

function logPath() {
  if (process.env.BAB_CALL_LOG_PATH) return process.env.BAB_CALL_LOG_PATH;
  return path.join(os.homedir(), '.bricks-mcp', 'call-log.jsonl');
}

export function isEnabled() {
  return process.env.BAB_CALL_LOG !== '0';
}

function diffEnabled() {
  return process.env.BAB_CALL_LOG_DIFF !== '0';
}

export function isMutating(tool) {
  return ELEMENT_DIFF_TOOLS.has(tool) || RAW_WRITE_TOOLS.has(tool);
}

/**
 * Collapse args into a compact, log-safe shape. Scalars kept; long strings
 * truncated with their length; arrays/objects reduced to a size descriptor.
 */
function summarizeArgs(args) {
  if (!args || typeof args !== 'object') return args ?? null;
  const out = {};
  for (const [k, v] of Object.entries(args)) {
    if (v == null) { out[k] = v; }
    else if (typeof v === 'string') { out[k] = v.length > ARG_STR_MAX ? `${v.slice(0, ARG_STR_MAX)}…(${v.length} chars)` : v; }
    else if (typeof v === 'number' || typeof v === 'boolean') { out[k] = v; }
    else if (Array.isArray(v)) { out[k] = `[Array(${v.length})]`; }
    else if (typeof v === 'object') { out[k] = `{Object(${Object.keys(v).length} keys)}`; }
    else { out[k] = String(v); }
  }
  return out;
}

/** Extract the element-id list of a page's content tree. Returns null on failure. */
async function elementIds(pageId, { fresh } = {}) {
  try {
    const data = fresh
      ? await wpGet(`/pages/${pageId}`)
      : await wpGetCached(`/pages/${pageId}`, BEFORE_TTL);
    const els = Array.isArray(data?.bricks_data) ? data.bricks_data : [];
    return els.map(e => e && e.id).filter(Boolean);
  } catch {
    return null;
  }
}

/**
 * Capture the pre-call state for a mutating tool (best-effort).
 * Returns { pageId, ids } or null.
 */
export async function captureBefore(tool, args) {
  if (!diffEnabled() || !ELEMENT_DIFF_TOOLS.has(tool)) return null;
  const pageId = args && (args.page_id ?? args.id);
  if (!pageId) return null;
  const ids = await elementIds(pageId, { fresh: false });
  return ids ? { pageId, ids } : null;
}

/**
 * Compute the structural diff after a mutating call (best-effort).
 * Returns a diff object, or null when not applicable.
 */
export async function finalizeDiff(tool, args, before) {
  if (!diffEnabled()) return null;
  const pageId = args && (args.page_id ?? args.id);
  if (RAW_WRITE_TOOLS.has(tool)) {
    return { kind: 'raw', page_id: pageId ?? null };
  }
  if (!ELEMENT_DIFF_TOOLS.has(tool) || !pageId) return null;

  const after = await elementIds(pageId, { fresh: true });
  if (!before || !after) {
    return { kind: 'element', page_id: pageId, before_count: before ? before.ids.length : null, after_count: after ? after.length : null, note: 'partial (before or after unavailable)' };
  }
  const beforeSet = new Set(before.ids);
  const afterSet  = new Set(after);
  const removed = before.ids.filter(id => !afterSet.has(id));
  const added   = after.filter(id => !beforeSet.has(id));
  return {
    kind: 'element',
    page_id: pageId,
    before_count: before.ids.length,
    after_count: after.length,
    delta: after.length - before.ids.length,
    removed_ids: removed.slice(0, 50),
    added_ids: added.slice(0, 50),
  };
}

function rotateIfNeeded(file) {
  try {
    const st = fs.statSync(file);
    if (st.size > MAX_BYTES) fs.renameSync(file, `${file}.1`);
  } catch { /* no file yet */ }
}

/** Append one log entry (best-effort, never throws). */
export function logCall(entry) {
  try {
    const file = logPath();
    fs.mkdirSync(path.dirname(file), { recursive: true });
    rotateIfNeeded(file);
    const line = JSON.stringify({
      ts: new Date().toISOString(),
      tool: entry.tool,
      status: entry.status,
      duration_ms: entry.durationMs,
      args: summarizeArgs(entry.args),
      ...(entry.error ? { error: entry.error } : {}),
      ...(entry.diff ? { diff: entry.diff } : {}),
    });
    fs.appendFileSync(file, line + '\n');
  } catch { /* logging must never break a call */ }
}

/** Read + filter the log for the query tool. Returns an array of entries. */
export function queryLog({ limit = 30, page_id = null, failures_only = false, mutating_only = false } = {}) {
  const file = logPath();
  let raw;
  try { raw = fs.readFileSync(file, 'utf8'); } catch { return { file, entries: [] }; }
  const lines = raw.split('\n').filter(Boolean);
  const entries = [];
  for (let i = lines.length - 1; i >= 0 && entries.length < limit; i--) {
    let e;
    try { e = JSON.parse(lines[i]); } catch { continue; }
    if (failures_only && e.status !== 'error') continue;
    if (mutating_only && !isMutating(e.tool)) continue;
    if (page_id != null) {
      const pid = e.diff?.page_id ?? e.args?.page_id ?? e.args?.id;
      if (String(pid) !== String(page_id)) continue;
    }
    entries.push(e);
  }
  return { file, entries };
}
