/**
 * Multi-Site Management Tools
 * List, switch, and query active site.
 */
import { listSites, switchSite, getActiveSite } from '../site-manager.js';
import { cache } from '../utils/cache.js';
import { wpGetCached } from '../utils/wp-api.js';
import { TTL } from '../utils/cache.js';

const siteTools = [
  {
    name: 'bricks_list_sites',
    description: 'List all configured WordPress sites. Shows which site is currently active.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      const sites = listSites();
      const lines = sites.map(s =>
        `${s.active ? '▸ ' : '  '}${s.key} — ${s.label} (${s.url}, user: ${s.username})`
      );
      return {
        content: [{
          type: 'text',
          text: `Sites (${sites.length}):\n${lines.join('\n')}`,
        }],
      };
    },
  },

  {
    name: 'bricks_switch_site',
    description: 'Switch the active WordPress site. Clears cache and warms the new site. Use bricks_list_sites to see available keys.',
    inputSchema: {
      type: 'object',
      properties: {
        key: {
          type: 'string',
          description: 'Site key from sites.json (e.g. "bricks-main", "staging")',
        },
      },
      required: ['key'],
    },
    handler: async ({ key }) => {
      const prev = getActiveSite();

      // Switch
      switchSite(key);

      // Clear cache for the new site (old prefixed entries won't match anyway)
      cache.clear();

      const current = getActiveSite();

      // Warm cache in background
      Promise.allSettled([
        wpGetCached('/theme-styles', TTL.THEME_STYLES),
        wpGetCached('/global-classes', TTL.GLOBAL_CLASSES),
        wpGetCached('/presets', TTL.PRESETS),
        wpGetCached('/color-palette', TTL.COLOR_PALETTE),
      ]).then(results => {
        const ok = results.filter(r => r.status === 'fulfilled').length;
        console.error(`CACHE WARM (${key}): ${ok}/${results.length} endpoints prefetched`);
      });

      return {
        content: [{
          type: 'text',
          text: [
            `Switched site: ${prev.key} → ${current.key}`,
            `Label: ${current.label}`,
            `URL: ${current.url}`,
            `User: ${current.username}`,
            `Cache cleared, warming in background...`,
          ].join('\n'),
        }],
      };
    },
  },

  {
    name: 'bricks_active_site',
    description: 'Show the currently active WordPress site (key, label, URL, user).',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      const site = getActiveSite();
      return {
        content: [{
          type: 'text',
          text: [
            `Active site: ${site.key}`,
            `Label: ${site.label}`,
            `URL: ${site.url}`,
            `User: ${site.username}`,
          ].join('\n'),
        }],
      };
    },
  },
];

export { siteTools };
