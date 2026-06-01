/**
 * Observability tools — query the structured call/diff log.
 *
 * The log records every tool call with its summarized args, status, duration,
 * and (for page-mutating calls) the before/after element diff. Use it after a
 * bad build to answer "which call changed the page, and with what input?" —
 * the thing a snapshot rollback erases at the exact moment you need it.
 */
import { queryLog, isEnabled } from '../utils/call-log.js';

const observabilityTools = [
  {
    name: 'bricks_call_log',
    description: 'Query the structured MCP call log — every tool call with its args, status, duration, and (for page-mutating calls) a before/after element diff (count delta + added/removed element IDs). Use this to trace which call changed or corrupted a page and what input triggered it, instead of guessing from a snapshot. Filters: limit, page_id, failures_only, mutating_only.',
    inputSchema: {
      type: 'object',
      properties: {
        limit: { type: 'number', description: 'Max entries to return, newest first (default 30).' },
        page_id: { type: 'number', description: 'Only calls that targeted this page ID.' },
        failures_only: { type: 'boolean', description: 'Only calls that errored.' },
        mutating_only: { type: 'boolean', description: 'Only page-mutating calls (update/patch/append/build/scripts/assets).' },
      },
    },
    handler: async (args = {}) => {
      if (!isEnabled()) {
        return { content: [{ type: 'text', text: 'Call log is disabled (BAB_CALL_LOG=0). Set BAB_CALL_LOG=1 to enable.' }] };
      }
      const { file, entries } = queryLog({
        limit: args.limit || 30,
        page_id: args.page_id ?? null,
        failures_only: !!args.failures_only,
        mutating_only: !!args.mutating_only,
      });

      if (entries.length === 0) {
        return { content: [{ type: 'text', text: `No matching call-log entries.\nLog file: ${file}` }] };
      }

      const lines = [`Call log — ${entries.length} entr${entries.length === 1 ? 'y' : 'ies'} (newest first)`, `file: ${file}`, ''];
      for (const e of entries) {
        const flag = e.status === 'error' ? '✗' : '✓';
        const argStr = e.args ? Object.entries(e.args).map(([k, v]) => `${k}=${typeof v === 'string' ? v : JSON.stringify(v)}`).join(' ') : '';
        lines.push(`${flag} ${e.ts} ${e.tool} (${e.duration_ms}ms)`);
        if (argStr) lines.push(`    args: ${argStr}`);
        if (e.error) lines.push(`    error: ${e.error}`);
        if (e.diff) {
          if (e.diff.kind === 'element') {
            const d = e.diff;
            const delta = d.delta > 0 ? `+${d.delta}` : `${d.delta}`;
            lines.push(`    diff: page ${d.page_id} · ${d.before_count}→${d.after_count} elements (${delta})${d.note ? ' · ' + d.note : ''}`);
            if (d.removed_ids && d.removed_ids.length) lines.push(`      removed: ${d.removed_ids.join(', ')}`);
            if (d.added_ids && d.added_ids.length) lines.push(`      added: ${d.added_ids.join(', ')}`);
          } else if (e.diff.kind === 'raw') {
            lines.push(`    diff: raw write to page ${e.diff.page_id} (scripts/assets blob)`);
          }
        }
      }
      return { content: [{ type: 'text', text: lines.join('\n') }] };
    },
  },
];

export { observabilityTools };
