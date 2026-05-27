/**
 * Bricks Builder Connection Test Tool
 */
import { wpGet } from '../utils/wp-api.js';
import { getActiveSite } from '../site-manager.js';

const connectionTools = [
  {
    name: 'bricks_connection_test',
    description: 'Test the WordPress connection, verify credentials, and check plugin status. Use this to diagnose connection issues.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      const site = getActiveSite();
      try {
        const data = await wpGet('/stats');
        const lines = [
          'Connection OK',
          `Site: ${site.label} [${site.key}]`,
          `URL: ${site.url}`,
          `User: ${site.user}`,
          `Plugin: ${data.bab_version || 'unknown'}`,
          `Pages: ${data.pages ?? 'unknown'}`,
          `Templates: ${data.templates ?? 'unknown'}`,
          `Elements: ${data.total_elements ?? 'unknown'}`,
        ];
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Connection FAILED (${site.label} [${site.key}]): ${error.message}` }] };
      }
    },
  },
];

export { connectionTools };
