/**
 * Bricks Builder Theme Styles Tools
 */
import { wpGet, wpGetCached, wpPut } from '../utils/wp-api.js';
import { TTL } from '../utils/cache.js';

function formatStyleEntry(name, style) {
  const groups = Object.keys(style || {});
  return `  ${name}: ${groups.length} group(s) [${groups.join(', ')}]`;
}

const themeStylesTools = [
  {
    name: 'bricks_get_theme_styles',
    description: 'Get Bricks Builder global Theme Styles. Returns all styles or a single named style.',
    inputSchema: {
      type: 'object',
      properties: { name: { type: 'string', description: 'Name of a specific theme style to retrieve (optional).' } },
    },
    handler: async (args) => {
      try {
        const { name } = args || {};
        if (name) {
          const data = await wpGetCached(`/theme-styles/${encodeURIComponent(name)}`, TTL.THEME_STYLES);
          return { content: [{ type: 'text', text: `Theme Style: ${data.name}\n\n${JSON.stringify(data.style, null, 2)}` }] };
        }

        const data = await wpGetCached('/theme-styles', TTL.THEME_STYLES);
        const styles = data.theme_styles || {};
        const count = data.count || 0;
        if (count === 0) return { content: [{ type: 'text', text: 'No theme styles found.' }] };

        const overview = Object.entries(styles).map(([n, s]) => formatStyleEntry(n, s)).join('\n');
        return { content: [{ type: 'text', text: `Found ${count} theme style(s):\n\n${overview}\n\nFull data:\n${JSON.stringify(styles, null, 2)}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error getting theme styles: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_update_theme_styles',
    description: 'Update Bricks Builder global Theme Styles. Supports merge mode (default). Bricks 2.3: Supports responsive breakpoint keys — include ":tablet_portrait" and ":mobile_portrait" nested keys for breakpoint-aware theme styles (e.g. responsive heading sizes).',
    inputSchema: {
      type: 'object',
      properties: {
        theme_styles: {
          type: 'object',
          description: 'Object with style name(s) as keys and their settings as values. Bricks 2.3: Supports responsive keys like { "headings": { "h1": { "_typography": { "font-size": "64" }, ":tablet_portrait": { "_typography": { "font-size": "42" } }, ":mobile_portrait": { "_typography": { "font-size": "32" } } } } }',
        },
        merge: { type: 'boolean', description: 'If true (default), merge with existing styles.', default: true },
      },
      required: ['theme_styles'],
    },
    handler: async (args) => {
      try {
        const { theme_styles, merge = true } = args;
        if (!theme_styles || typeof theme_styles !== 'object') return { content: [{ type: 'text', text: 'Error: theme_styles must be an object' }] };

        // Check for responsive keys and log them
        const responsiveKeys = [];
        function scanForResponsiveKeys(obj, path = '') {
          for (const [key, val] of Object.entries(obj)) {
            if (key.startsWith(':tablet') || key.startsWith(':mobile')) {
              responsiveKeys.push(`${path}.${key}`);
            }
            if (val && typeof val === 'object' && !Array.isArray(val)) {
              scanForResponsiveKeys(val, path ? `${path}.${key}` : key);
            }
          }
        }
        scanForResponsiveKeys(theme_styles);

        const result = await wpPut('/theme-styles', { theme_styles, merge });
        const styleNames = Object.keys(theme_styles).join(', ');
        const responsiveNote = responsiveKeys.length > 0
          ? `\nResponsive keys: ${responsiveKeys.length} breakpoint override(s)`
          : '';

        return { content: [{ type: 'text', text: `Theme styles updated successfully.\nMode: ${result.merge ? 'merge' : 'replace'}\nUpdated: ${styleNames}\nTotal styles: ${result.count}${responsiveNote}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error updating theme styles: ${error.message}` }] };
      }
    },
  },
];

export { themeStylesTools };
