/**
 * Bricks Builder Section Presets + Auto-Learn Tools
 * Manage reusable section presets and style preferences
 */
import { wpGet, wpGetCached, wpPost, wpPut, wpDelete } from '../utils/wp-api.js';
import { cache, TTL } from '../utils/cache.js';

const presetTools = [
  {
    name: 'bricks_list_presets',
    description: 'List all available section presets. Presets are reusable section templates that can be instantiated on any page.',
    inputSchema: {
      type: 'object',
      properties: {
        category: {
          type: 'string',
          description: 'Filter by category (e.g., "hero", "features", "cta", "footer")',
        },
      },
    },
    handler: async (args) => {
      try {
        let endpoint = '/presets';
        if (args.category) {
          endpoint += `?category=${encodeURIComponent(args.category)}`;
        }

        const data = await wpGetCached(endpoint, TTL.PRESETS);

        // PHP returns {presets: {name: {elements, variables, description}, ...}, count}
        // Convert associative object to array with name included
        let presets = [];
        if (Array.isArray(data)) {
          presets = data;
        } else if (data?.presets && typeof data.presets === 'object') {
          presets = Object.entries(data.presets).map(([name, preset]) => ({
            name,
            ...preset,
          }));
        }

        if (presets.length === 0) {
          return {
            content: [{ type: 'text', text: 'No section presets found.' }],
          };
        }

        const list = presets.map(p => {
          const elements = Array.isArray(p.elements) ? p.elements.length : (p.element_count || 0);
          const vars = Array.isArray(p.variables) ? p.variables.join(', ') : '';
          return `  - **${p.name}** (${elements} elements) — ${p.description || 'No description'}${vars ? `\n    Variables: ${vars}` : ''}`;
        }).join('\n');

        return {
          content: [{
            type: 'text',
            text: `Found ${presets.length} preset(s):\n\n${list}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error listing presets: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_instantiate_section',
    description: 'Create an instance of a section preset with optional overrides. Returns the Bricks elements ready to be added to a page.',
    inputSchema: {
      type: 'object',
      properties: {
        preset_name: {
          type: 'string',
          description: 'Name of the preset to instantiate',
        },
        overrides: {
          type: 'object',
          description: 'Settings overrides to apply by element key or index (e.g., { "0": { "_background": {...} } })',
        },
        variables: {
          type: 'object',
          description: 'Variable substitutions for {{placeholder}} values in the preset (e.g., { heading: "Hello", button_text: "Click" })',
        },
        use_learned_styles: {
          type: 'boolean',
          description: 'If true, fill unfilled {{placeholders}} with learned site preferences (top colors, fonts, spacing). Explicit variables always take priority.',
        },
      },
      required: ['preset_name'],
    },
    handler: async (args) => {
      try {
        const { preset_name, overrides = {}, variables = {}, use_learned_styles = false } = args;

        if (!preset_name) {
          return { content: [{ type: 'text', text: 'Error: preset_name is required' }] };
        }

        const result = await wpPost('/presets/instantiate', {
          name: preset_name,
          variables,
          overrides,
          use_learned_styles,
        });

        return {
          content: [{
            type: 'text',
            text: `Preset "${preset_name}" instantiated.\nElements: ${result.elements ? result.elements.length : 0}\n\n${JSON.stringify(result.elements || [], null, 2)}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error instantiating preset: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_save_preset',
    description: 'Save a section as a reusable preset. Extracts elements from a page section and stores as a named preset.',
    inputSchema: {
      type: 'object',
      properties: {
        name: {
          type: 'string',
          description: 'Unique preset name (e.g., "hero-dark-gradient")',
        },
        category: {
          type: 'string',
          description: 'Category (e.g., "hero", "features", "cta", "testimonials")',
        },
        description: {
          type: 'string',
          description: 'Brief description of the preset',
        },
        elements: {
          type: 'array',
          items: { type: 'object' },
          description: 'Array of Bricks elements that make up this section',
        },
      },
      required: ['name', 'elements'],
    },
    handler: async (args) => {
      try {
        const { name, category, description, elements } = args;

        if (!name) {
          return { content: [{ type: 'text', text: 'Error: name is required' }] };
        }
        if (!elements || !Array.isArray(elements) || elements.length === 0) {
          return { content: [{ type: 'text', text: 'Error: elements must be a non-empty array' }] };
        }

        const result = await wpPost('/presets', {
          name,
          category: category || 'uncategorized',
          description: description || '',
          elements,
        });

        return {
          content: [{
            type: 'text',
            text: `Preset "${name}" saved.\nCategory: ${category || 'uncategorized'}\nElements: ${elements.length}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error saving preset: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_delete_preset',
    description: 'Delete a section preset by name.',
    inputSchema: {
      type: 'object',
      properties: {
        name: { type: 'string', description: 'Preset name to delete' },
      },
      required: ['name'],
    },
    handler: async (args) => {
      try {
        const { name } = args;
        if (!name) return { content: [{ type: 'text', text: 'Error: name is required' }] };
        cache.invalidatePrefix('/presets');
        await wpDelete(`/presets/${encodeURIComponent(name)}`);
        return { content: [{ type: 'text', text: `Preset "${name}" deleted.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error deleting preset: ${error.message}` }] };
      }
    },
  },
];

export { presetTools };
