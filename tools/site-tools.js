/**
 * Site Tools — MCP wrappers for the Site Controller REST endpoints
 *
 * 9 tools: settings, page creation, page-settings, cache purge,
 * validation, stats, post types.
 */
import { wpGet, wpPost, wpPut, wpDelete, wpGetCached } from '../utils/wp-api.js';
import { TTL } from '../utils/cache.js';

const siteManagementTools = [

  // ═══ 1. Bricks Global Settings ══════════════════════════════

  {
    name: 'bricks_get_settings',
    description: 'Get Bricks Builder global settings (container width, builder mode, post types, disabled features, custom header scripts, etc.).',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      try {
        const data = await wpGet('/settings');
        const settings = data.settings || {};
        const keys = Object.keys(settings);

        const lines = keys.map(k => {
          let v = settings[k];
          if (typeof v === 'object') v = JSON.stringify(v);
          if (typeof v === 'string' && v.length > 80) v = v.slice(0, 80) + '...';
          return `  ${k}: ${v}`;
        });

        return {
          content: [{
            type: 'text',
            text: `Bricks Global Settings (${keys.length} keys):\n\n${lines.join('\n')}`,
          }],
        };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_update_settings',
    description: 'Update Bricks Builder global settings. Supports merge mode (default). Use for container width, post types, disabled features, etc.',
    inputSchema: {
      type: 'object',
      properties: {
        settings: { type: 'object', description: 'Settings key-value pairs to update' },
        merge: { type: 'boolean', description: 'Merge with existing (default: true)', default: true },
      },
      required: ['settings'],
    },
    handler: async (args) => {
      try {
        const result = await wpPut('/settings', { settings: args.settings, merge: args.merge ?? true });
        const keys = Object.keys(args.settings);
        return {
          content: [{
            type: 'text',
            text: `Settings updated: ${keys.join(', ')}\nMerge: ${args.merge ?? true}`,
          }],
        };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // ═══ 2. Create Page ═════════════════════════════════════════

  {
    name: 'bricks_create_page',
    description: 'Create a new WordPress page with Bricks Builder data in one call. No need for separate WP page creation + Bricks data push. THEME-FIRST: before generating elements, call bricks_get_theme_styles and bricks_list_global_classes — reuse existing tokens (button styles, utility classes, color palette) instead of inline styling. For buttons prefer settings.style: "primary"|"secondary" over manual _background/_typography. For links use the text-link element, not a styled text-basic.',
    inputSchema: {
      type: 'object',
      properties: {
        title: { type: 'string', description: 'Page title' },
        slug: { type: 'string', description: 'URL slug (optional, auto-generated from title)' },
        status: { type: 'string', enum: ['publish', 'draft', 'private'], description: 'Post status (default: draft)', default: 'draft' },
        elements: { type: 'array', description: 'Bricks elements array (optional)', items: { type: 'object' } },
        parent: { type: 'number', description: 'Parent page ID (optional)', default: 0 },
        post_type: { type: 'string', description: 'Post type (default: page)', default: 'page' },
      },
      required: ['title'],
    },
    handler: async (args) => {
      try {
        const result = await wpPost('/pages/create', args);
        return {
          content: [{
            type: 'text',
            text: `Page created!\n  ID: ${result.page_id}\n  URL: ${result.url}\n  Elements: ${result.element_count}\n  Status: ${args.status || 'draft'}`,
          }],
        };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error creating page: ${error.message}` }] };
      }
    },
  },

  // ═══ 3. Page-Level Settings ═════════════════════════════════

  {
    name: 'bricks_get_page_settings',
    description: 'Get Bricks page-level settings (metaRobots, custom header/footer assignment, per-page overrides).',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const data = await wpGet(`/pages/${args.page_id}/page-settings`);
        const settings = data.settings || {};
        const keys = Object.keys(settings);

        if (keys.length === 0) {
          return { content: [{ type: 'text', text: `Page ${args.page_id}: No page-level settings (using defaults).` }] };
        }

        return {
          content: [{
            type: 'text',
            text: `Page ${args.page_id} settings:\n${JSON.stringify(settings, null, 2)}`,
          }],
        };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_update_page_settings',
    description: 'Update Bricks page-level settings (metaRobots, header/footer template, per-page overrides).',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        settings: { type: 'object', description: 'Settings to update' },
        merge: { type: 'boolean', description: 'Merge with existing (default: true)', default: true },
      },
      required: ['page_id', 'settings'],
    },
    handler: async (args) => {
      try {
        await wpPut(`/pages/${args.page_id}/page-settings`, { settings: args.settings, merge: args.merge ?? true });
        return {
          content: [{
            type: 'text',
            text: `Page ${args.page_id} settings updated: ${Object.keys(args.settings).join(', ')}`,
          }],
        };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // ═══ 4. Server-Side Validation ══════════════════════════════

  {
    name: 'bricks_validate_elements',
    description: 'Validate Bricks elements server-side BEFORE pushing. Catches all known Bricks bugs: invalid IDs, div type, missing children, duplicate IDs, px line-height, parent/child consistency, _display grid.',
    inputSchema: {
      type: 'object',
      properties: {
        elements: { type: 'array', description: 'Bricks elements array to validate', items: { type: 'object' } },
      },
      required: ['elements'],
    },
    handler: async (args) => {
      try {
        const result = await wpPost('/pages/validate', { elements: args.elements });

        let text = result.valid
          ? `Valid! ${result.element_count} elements, no errors.`
          : `Invalid! ${result.error_count} error(s), ${result.warning_count} warning(s).`;

        if (result.errors.length > 0) {
          text += '\n\nErrors:\n' + result.errors.map(e => `  - ${e}`).join('\n');
        }
        if (result.warnings.length > 0) {
          text += '\n\nWarnings:\n' + result.warnings.map(w => `  - ${w}`).join('\n');
        }

        return { content: [{ type: 'text', text }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // ═══ 5. Site Stats ══════════════════════════════════════════

  {
    name: 'bricks_get_stats',
    description: 'Get site-wide statistics: total pages, templates, elements, global classes, fonts, colors, presets, media, PHP/WP/Bricks versions.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      try {
        const s = await wpGet('/stats');
        const text = `## Site Stats

| Metric | Value |
|--------|-------|
| Pages (with Bricks) | ${s.pages} |
| Templates | ${s.templates} |
| Total Elements | ${s.total_elements.toLocaleString()} |
| Global Classes | ${s.global_classes} |
| Fonts | ${s.fonts} |
| CSS Variables | ${s.css_variables} |
| Colors | ${s.colors} |
| Presets | ${s.presets} |
| Media | ${s.media} |
| Active Plugins | ${s.active_plugins} |
| PHP | ${s.php_version} |
| WordPress | ${s.wp_version} |
| Bricks | ${s.bricks_version} |
| API Bridge | ${s.bab_version} |`;

        return { content: [{ type: 'text', text }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // ═══ 6. Post Types ═════════════════════════════════════════

  {
    name: 'bricks_get_post_types',
    description: 'List all public WordPress post types with their Bricks support status, REST API base, and post count.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      try {
        const data = await wpGet('/post-types');
        const types = data.post_types || [];

        const lines = types.map(t =>
          `  ${t.label} (${t.name}) — ${t.count} posts, REST: /wp/v2/${t.rest_base}${t.has_bricks ? ' [Bricks]' : ''}${t.hierarchical ? ' [hierarchical]' : ''}`
        );

        return {
          content: [{
            type: 'text',
            text: `Post Types (${types.length}):\n\n${lines.join('\n')}`,
          }],
        };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // ═══ 7. Cache Purge ═════════════════════════════════════════

  {
    name: 'bricks_purge_cache',
    description: 'Purge all caches: Bricks CSS, WP object cache, BAB transients, and any active cache plugins (LiteSpeed, WP Rocket, W3TC, WP Super Cache). Optionally target a specific page.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'Target specific page (optional, omit for site-wide purge)' },
      },
    },
    handler: async (args) => {
      try {
        const result = await wpPost('/cache/purge', args.page_id ? { page_id: args.page_id } : {});
        return {
          content: [{
            type: 'text',
            text: `Cache purged: ${result.purged.join(', ')}${result.page_id ? ` (page ${result.page_id})` : ' (site-wide)'}`,
          }],
        };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },
];

export { siteManagementTools };
