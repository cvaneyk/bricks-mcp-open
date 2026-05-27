/**
 * Bricks Builder Batch Tool
 * Execute multiple API operations in a single request
 */
import { wpPost, wpGetDeduped } from '../utils/wp-api.js';

let _server = null;
function setServer(s) { _server = s; }

const batchTools = [
  {
    name: 'bricks_batch',
    description: 'Execute multiple API operations in a single request. Reduces HTTP roundtrips for multi-step workflows. Max 20 operations per batch.',
    inputSchema: {
      type: 'object',
      properties: {
        operations: {
          type: 'array',
          description: 'Array of operations to execute. Max 20.',
          items: {
            type: 'object',
            properties: {
              method: { type: 'string', enum: ['GET', 'POST', 'PUT', 'DELETE'] },
              endpoint: { type: 'string', description: 'API endpoint path (e.g., /pages/3)' },
              body: { type: 'object', description: 'Request body (for POST/PUT)' },
            },
            required: ['method', 'endpoint'],
          },
        },
      },
      required: ['operations'],
    },
    handler: async (args) => {
      try {
        const { operations } = args;
        if (!operations || !Array.isArray(operations) || operations.length === 0) {
          return { content: [{ type: 'text', text: 'Error: operations must be a non-empty array' }] };
        }
        if (operations.length > 20) {
          return { content: [{ type: 'text', text: 'Error: maximum 20 operations per batch' }] };
        }

        // Send progress notification before batch starts
        if (_server) {
          try {
            _server.notification({
              method: 'notifications/progress',
              params: { progressToken: 'batch', progress: 0, total: operations.length },
            });
          } catch (_) {}
        }

        // Partition: read-only ops run in parallel client-side, writes go to WP batch endpoint
        const readOps = [];
        const writeOps = [];
        operations.forEach((op, i) => {
          if (op.method === 'GET') readOps.push({ ...op, _idx: i });
          else writeOps.push({ ...op, _idx: i });
        });

        // Execute reads in parallel via Promise.all (deduped)
        const allResults = new Array(operations.length);

        if (readOps.length > 0) {
          const readPromises = readOps.map(async (op) => {
            try {
              const data = await wpGetDeduped(op.endpoint);
              return { _idx: op._idx, status: 200, body: data };
            } catch (e) {
              const statusMatch = e.message.match(/(\d{3})/);
              return { _idx: op._idx, status: statusMatch ? parseInt(statusMatch[1]) : 500, body: { message: e.message } };
            }
          });
          const readResults = await Promise.all(readPromises);
          for (const r of readResults) allResults[r._idx] = { status: r.status, body: r.body };
        }

        // Execute writes via WP batch endpoint (server handles ordering)
        if (writeOps.length > 0) {
          const batchResult = await wpPost('/batch', { operations: writeOps.map(({ _idx, ...op }) => op) });
          batchResult.results.forEach((r, i) => {
            allResults[writeOps[i]._idx] = r;
          });
        } else if (readOps.length === operations.length) {
          // All reads — skip batch endpoint entirely
        }

        const result = { results: allResults };

        // Send completion progress
        if (_server) {
          try {
            _server.notification({
              method: 'notifications/progress',
              params: { progressToken: 'batch', progress: operations.length, total: operations.length },
            });
          } catch (_) {}
        }

        // Collapse results by category (reads vs writes) — show details only for errors
        const reads = [];
        const writes = [];
        const errors = [];
        result.results.forEach((r, i) => {
          const op = operations[i];
          const entry = { i: i + 1, method: op.method, endpoint: op.endpoint, status: r.status };
          if (r.status >= 400) {
            errors.push({ ...entry, error: r.body?.message || r.body || `HTTP ${r.status}` });
          } else if (op.method === 'GET') {
            reads.push(entry);
          } else {
            writes.push(entry);
          }
        });

        const parts = [];
        if (reads.length > 0) parts.push(`${reads.length} read(s): all OK`);
        if (writes.length > 0) parts.push(`${writes.length} write(s): all OK`);
        if (errors.length > 0) {
          parts.push(`${errors.length} error(s):`);
          for (const e of errors) parts.push(`  ${e.i}. ${e.method} ${e.endpoint} → ${e.status}: ${e.error}`);
        }

        // Only include full results JSON if there are errors or few operations
        const showFull = errors.length > 0 || operations.length <= 5;
        const fullResults = showFull ? `\n\nFull results:\n${JSON.stringify(result.results, null, 2)}` : '';

        return {
          content: [{
            type: 'text',
            text: `Batch executed: ${operations.length} operations (${reads.length} reads, ${writes.length} writes, ${errors.length} errors)\n${parts.join('\n')}${fullResults}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error executing batch: ${error.message}` }],
        };
      }
    },
  },
];

export { batchTools, setServer };
