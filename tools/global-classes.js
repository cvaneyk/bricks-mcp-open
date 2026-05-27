/**
 * Bricks Builder Global CSS Classes Tools
 * Manage reusable CSS classes across the site
 */
import { wpGet, wpGetCached, wpPost, wpPut, wpDelete } from '../utils/wp-api.js';
import { cache, TTL } from '../utils/cache.js';

// --- BEM Component Generator ---

function generateBEMComponent(block, elements, modifiers = {}) {
  const classes = [];

  // Block-Klasse (from _root element or empty settings)
  classes.push({
    id: block,
    name: block,
    settings: elements._root || {},
  });

  // Element-Klassen (block__element)
  for (const [el, settings] of Object.entries(elements)) {
    if (el === '_root') continue;
    classes.push({
      id: `${block}__${el}`,
      name: `${block}__${el}`,
      settings,
    });
  }

  // Modifier-Klassen (block--modifier)
  for (const [mod, settings] of Object.entries(modifiers)) {
    classes.push({
      id: `${block}--${mod}`,
      name: `${block}--${mod}`,
      settings,
    });
  }

  return classes;
}

// --- MCP Tools ---

const globalClassesTools = [
  {
    name: 'bricks_list_global_classes',
    description: 'List all Bricks Builder global CSS classes. Returns class names, settings, and usage count.',
    inputSchema: {
      type: 'object',
      properties: {
        search: {
          type: 'string',
          description: 'Search term to filter classes by name',
        },
      },
    },
    handler: async (args) => {
      try {
        let endpoint = '/global-classes';
        if (args.search) {
          endpoint += `?search=${encodeURIComponent(args.search)}`;
        }

        const data = await wpGetCached(endpoint, TTL.GLOBAL_CLASSES);

        const classes = data.classes || data || [];
        if (!classes || !Array.isArray(classes) || classes.length === 0) {
          return {
            content: [{ type: 'text', text: 'No global classes found.' }],
          };
        }

        const list = classes.map(c => {
          const settings = Object.keys(c.settings || {}).join(', ');
          return `${c.name} (${c.id}) | Settings: ${settings || 'none'}`;
        }).join('\n');

        return {
          content: [{
            type: 'text',
            text: `Found ${classes.length} global class(es):\n\n${list}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error listing global classes: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_create_global_class',
    description: 'Create a new Bricks Builder global CSS class with settings.',
    inputSchema: {
      type: 'object',
      properties: {
        name: {
          type: 'string',
          description: 'Class name (e.g., "btn-primary", "card-hover")',
        },
        settings: {
          type: 'object',
          description: 'Bricks settings object (e.g., _background, _typography, _padding)',
        },
      },
      required: ['name', 'settings'],
    },
    handler: async (args) => {
      try {
        const { name, settings } = args;

        if (!name) {
          return { content: [{ type: 'text', text: 'Error: name is required' }] };
        }
        if (!settings || typeof settings !== 'object') {
          return { content: [{ type: 'text', text: 'Error: settings must be an object' }] };
        }

        cache.invalidatePrefix('/global-classes');

        const id = name.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-|-$/g, '');
        const result = await wpPost('/global-classes', { id, name, settings });

        return {
          content: [{
            type: 'text',
            text: `Global class "${name}" created.\nID: ${result.class?.id || result.id || id}\nSettings: ${Object.keys(settings).join(', ')}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error creating global class: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_update_global_class',
    description: 'Update an existing Bricks Builder global CSS class. Merges settings recursively (partial update).',
    inputSchema: {
      type: 'object',
      properties: {
        class_id: {
          type: 'string',
          description: 'Class ID to update',
        },
        name: {
          type: 'string',
          description: 'New class name (optional)',
        },
        settings: {
          type: 'object',
          description: 'Updated Bricks settings object (merged with existing)',
        },
      },
      required: ['class_id', 'settings'],
    },
    handler: async (args) => {
      try {
        const { class_id, name, settings } = args;

        if (!class_id) {
          return { content: [{ type: 'text', text: 'Error: class_id is required' }] };
        }

        const body = { settings };
        if (name) body.name = name;

        cache.invalidatePrefix('/global-classes');

        const result = await wpPut(`/global-classes/${encodeURIComponent(class_id)}`, body);

        return {
          content: [{
            type: 'text',
            text: `Global class "${class_id}" updated.${name ? ` Renamed to: ${name}` : ''}\nSettings: ${Object.keys(settings).join(', ')}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error updating global class: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_apply_class_to_element',
    description: 'Apply or remove global CSS classes on page elements. Supports single element or bulk operations.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: {
          type: 'number',
          description: 'Page ID containing the element(s)',
        },
        element_id: {
          type: 'string',
          description: 'Element ID (for single operation)',
        },
        class_id: {
          type: 'string',
          description: 'Global class ID (for single operation)',
        },
        operations: {
          type: 'array',
          description: 'Bulk operations: [{ element_id, class_ids: [...], action: "add"|"remove" }]',
          items: {
            type: 'object',
            properties: {
              element_id: { type: 'string' },
              class_ids: { type: 'array', items: { type: 'string' } },
              action: { type: 'string', enum: ['add', 'remove'] },
            },
            required: ['element_id', 'class_ids'],
          },
        },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, element_id, class_id, operations } = args;

        if (!page_id) {
          return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        }

        // Build request body — single or bulk
        const body = { page_id };
        if (operations && operations.length > 0) {
          body.operations = operations;
        } else if (element_id && class_id) {
          body.element_id = element_id;
          body.class_id = class_id;
        } else {
          return { content: [{ type: 'text', text: 'Error: provide operations array OR element_id + class_id' }] };
        }

        const result = await wpPost('/global-classes/apply', body);

        cache.invalidatePrefix(`/pages/${page_id}`);

        const count = result.modified || 0;
        const mode = operations ? `${operations.length} operation(s)` : `"${class_id}" → "${element_id}"`;
        return {
          content: [{
            type: 'text',
            text: `Applied ${mode} on page ${page_id}. ${count} element(s) modified.`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error applying class: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_bulk_create_global_classes',
    description: 'Create or update multiple global CSS classes in one request. Upserts by ID.',
    inputSchema: {
      type: 'object',
      properties: {
        classes: {
          type: 'array',
          description: 'Array of class objects: [{ id, name, settings }]',
          items: {
            type: 'object',
            properties: {
              id: { type: 'string' },
              name: { type: 'string' },
              settings: { type: 'object' },
            },
            required: ['id', 'name', 'settings'],
          },
        },
      },
      required: ['classes'],
    },
    handler: async (args) => {
      try {
        const { classes } = args;

        if (!classes || !Array.isArray(classes) || classes.length === 0) {
          return { content: [{ type: 'text', text: 'Error: classes array is required' }] };
        }

        cache.invalidatePrefix('/global-classes');

        const result = await wpPost('/global-classes/bulk', { classes });

        return {
          content: [{
            type: 'text',
            text: `Bulk operation complete: ${result.created} created, ${result.updated} updated. Total: ${result.total} classes.`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error bulk creating classes: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_get_global_class_usage',
    description: 'Get usage report: which global classes are used on which pages/elements. Optionally filter by class_id.',
    inputSchema: {
      type: 'object',
      properties: {
        class_id: {
          type: 'string',
          description: 'Filter to a specific class ID (optional)',
        },
      },
    },
    handler: async (args) => {
      try {
        let endpoint = '/global-classes/usage';
        if (args.class_id) {
          endpoint += `?class_id=${encodeURIComponent(args.class_id)}`;
        }

        const data = await wpGet(endpoint);

        if (!data.usage || data.classes_found === 0) {
          return { content: [{ type: 'text', text: 'No global class usage found.' }] };
        }

        const lines = [];
        for (const [cid, info] of Object.entries(data.usage)) {
          const pages = info.pages.map(p => `${p.page_title} (ID ${p.page_id}, ${p.element_ids.length} elements)`).join(', ');
          lines.push(`${cid}: ${info.total_elements} elements on ${info.total_pages} page(s) → ${pages}`);
        }

        return {
          content: [{
            type: 'text',
            text: `Global class usage (${data.classes_found} classes found):\n\n${lines.join('\n')}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error getting usage: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_delete_global_class',
    description: 'Delete a global CSS class by ID.',
    inputSchema: {
      type: 'object',
      properties: {
        class_id: {
          type: 'string',
          description: 'Class ID to delete',
        },
      },
      required: ['class_id'],
    },
    handler: async (args) => {
      try {
        const { class_id } = args;
        if (!class_id) {
          return { content: [{ type: 'text', text: 'Error: class_id is required' }] };
        }

        cache.invalidatePrefix('/global-classes');
        await wpDelete(`/global-classes/${encodeURIComponent(class_id)}`);

        return {
          content: [{
            type: 'text',
            text: `Global class "${class_id}" deleted.`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error deleting global class: ${error.message}` }],
        };
      }
    },
  },

  // --- BEM Component Tools ---

  {
    name: 'bricks_generate_bem_component',
    description: 'Generate a complete BEM component as global CSS classes. Creates block, element (__), and modifier (--) classes in one call. Uses bulk upsert so it is safe to re-run. Example: block="pricing-card" with elements title, price, cta creates: pricing-card, pricing-card__title, pricing-card__price, pricing-card__cta.',
    inputSchema: {
      type: 'object',
      properties: {
        block: {
          type: 'string',
          description: 'BEM block name (e.g., "pricing-card", "hero", "testimonial")',
        },
        elements: {
          type: 'object',
          description: 'Map of element names to Bricks settings. Use "_root" for the block-level styles. Example: { "_root": { _padding: {...} }, "title": { _typography: {...} } }',
        },
        modifiers: {
          type: 'object',
          description: 'Optional map of modifier names to Bricks settings. Example: { "featured": { _borderColor: {...} }, "dark": { _background: {...} } }',
        },
      },
      required: ['block', 'elements'],
    },
    handler: async (args) => {
      try {
        const { block, elements, modifiers = {} } = args;

        if (!block || typeof block !== 'string') {
          return { content: [{ type: 'text', text: 'Error: block name is required (string)' }] };
        }
        if (!elements || typeof elements !== 'object') {
          return { content: [{ type: 'text', text: 'Error: elements must be an object mapping element names to settings' }] };
        }

        const classes = generateBEMComponent(block, elements, modifiers);

        cache.invalidatePrefix('/global-classes');

        const result = await wpPost('/global-classes/bulk', { classes });

        const classNames = classes.map(c => c.id).join(', ');
        return {
          content: [{
            type: 'text',
            text: `Created BEM component "${block}" with ${classes.length} classes: ${classNames}\n  ${result.created} created, ${result.updated} updated.`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error generating BEM component: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_apply_bem_component',
    description: 'Apply BEM component classes to page elements. Maps element IDs to their BEM roles (_root, title, price, cta, etc.) and assigns the corresponding global classes. Optionally adds a modifier class to the root element.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: {
          type: 'number',
          description: 'Page ID containing the elements',
        },
        block: {
          type: 'string',
          description: 'BEM block name (must match a previously generated component)',
        },
        mapping: {
          type: 'object',
          description: 'Map of Bricks element IDs to BEM element names. Use "_root" for the block element. Example: { "rt0074": "_root", "rt0075": "title", "rt0076": "price" }',
        },
        modifier: {
          type: 'string',
          description: 'Optional modifier to apply to the root element (e.g., "featured", "dark")',
        },
      },
      required: ['page_id', 'block', 'mapping'],
    },
    handler: async (args) => {
      try {
        const { page_id, block, mapping, modifier } = args;

        if (!page_id) {
          return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        }
        if (!block) {
          return { content: [{ type: 'text', text: 'Error: block name is required' }] };
        }
        if (!mapping || typeof mapping !== 'object' || Object.keys(mapping).length === 0) {
          return { content: [{ type: 'text', text: 'Error: mapping must be a non-empty object { elementId: "bemElement" }' }] };
        }

        // Build bulk operations from the mapping
        const operations = [];
        for (const [elementId, bemElement] of Object.entries(mapping)) {
          const classIds = [];

          if (bemElement === '_root') {
            // Root gets the block class + optional modifier
            classIds.push(block);
            if (modifier) {
              classIds.push(`${block}--${modifier}`);
            }
          } else {
            // Element gets block__element class
            classIds.push(`${block}__${bemElement}`);
          }

          operations.push({
            element_id: elementId,
            class_ids: classIds,
            action: 'add',
          });
        }

        const result = await wpPost('/global-classes/apply', {
          page_id,
          operations,
        });

        cache.invalidatePrefix(`/pages/${page_id}`);

        const count = result.modified || 0;
        const modStr = modifier ? ` (modifier: ${block}--${modifier})` : '';
        return {
          content: [{
            type: 'text',
            text: `Applied BEM component "${block}"${modStr} on page ${page_id}: ${operations.length} operations, ${count} element(s) modified.`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error applying BEM component: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_validate_bem',
    description: 'Validate existing global classes against BEM convention. Checks naming patterns, orphaned blocks/elements, conflicts with utility classes, and reports structure issues.',
    inputSchema: {
      type: 'object',
      properties: {
        prefix: {
          type: 'string',
          description: 'Filter to classes starting with this prefix (e.g., "pricing"). Leave empty to validate all non-utility classes.',
        },
      },
    },
    handler: async (args) => {
      try {
        const { prefix } = args;

        const data = await wpGetCached('/global-classes', TTL.GLOBAL_CLASSES);
        const allClasses = data.classes || data || [];

        if (!Array.isArray(allClasses) || allClasses.length === 0) {
          return { content: [{ type: 'text', text: 'No global classes found to validate.' }] };
        }

        // Separate utility (ds-*) from potential BEM classes
        const utilityClasses = allClasses.filter(c => c.id.startsWith('ds-'));
        const candidates = prefix
          ? allClasses.filter(c => c.id.startsWith(prefix) && !c.id.startsWith('ds-'))
          : allClasses.filter(c => !c.id.startsWith('ds-'));

        if (candidates.length === 0) {
          return { content: [{ type: 'text', text: prefix ? `No classes found with prefix "${prefix}".` : 'No non-utility classes found to validate.' }] };
        }

        // Parse BEM structure
        const bemPattern = /^([a-z][a-z0-9]*(?:-[a-z0-9]+)*)(?:__([a-z][a-z0-9]*(?:-[a-z0-9]+)*))?(?:--([a-z][a-z0-9]*(?:-[a-z0-9]+)*))?$/;
        const blocks = new Map(); // blockName → { elements: Set, modifiers: Set }
        const warnings = [];
        const nonBem = [];

        for (const cls of candidates) {
          const match = cls.id.match(bemPattern);
          if (!match) {
            nonBem.push(cls.id);
            continue;
          }

          const [, blockName, element, modifier] = match;

          if (!blocks.has(blockName)) {
            blocks.set(blockName, { hasRoot: false, elements: new Set(), modifiers: new Set() });
          }
          const b = blocks.get(blockName);

          if (!element && !modifier) {
            b.hasRoot = true;
          } else if (element && !modifier) {
            b.elements.add(element);
          } else if (!element && modifier) {
            b.modifiers.add(modifier);
          } else {
            // element + modifier combined (element--modifier is unusual but valid in some BEM dialects)
            b.elements.add(element);
            warnings.push(`"${cls.id}": combined element+modifier pattern (block__element--modifier) — consider separating`);
          }
        }

        // Check for issues
        for (const [blockName, b] of blocks) {
          if (!b.hasRoot && (b.elements.size > 0 || b.modifiers.size > 0)) {
            warnings.push(`Block "${blockName}" has elements/modifiers but no root class`);
          }
          if (b.hasRoot && b.elements.size === 0 && b.modifiers.size === 0) {
            warnings.push(`Block "${blockName}" has root class but no elements or modifiers (standalone)`);
          }
        }

        // Check for naming conflicts with utility classes
        const utilityIds = new Set(utilityClasses.map(c => c.id));
        for (const cls of candidates) {
          if (utilityIds.has(cls.id)) {
            warnings.push(`"${cls.id}" conflicts with utility class of same ID`);
          }
        }

        // Build report
        const lines = [];
        lines.push(`BEM Validation Report${prefix ? ` (prefix: "${prefix}")` : ''}:`);
        lines.push(`  Scanned: ${candidates.length} classes, ${blocks.size} block(s) detected`);
        lines.push('');

        for (const [blockName, b] of blocks) {
          const elList = b.elements.size > 0 ? [...b.elements].map(e => `__${e}`).join(', ') : 'none';
          const modList = b.modifiers.size > 0 ? [...b.modifiers].map(m => `--${m}`).join(', ') : 'none';
          lines.push(`  ${blockName}${b.hasRoot ? ' ✓' : ' ✗ (no root)'}`);
          lines.push(`    Elements: ${elList}`);
          lines.push(`    Modifiers: ${modList}`);
        }

        if (nonBem.length > 0) {
          lines.push('');
          lines.push(`  Non-BEM classes: ${nonBem.join(', ')}`);
        }

        if (warnings.length > 0) {
          lines.push('');
          lines.push('  Warnings:');
          for (const w of warnings) {
            lines.push(`    ⚠ ${w}`);
          }
        } else {
          lines.push('');
          lines.push('  No warnings — BEM structure looks good.');
        }

        return {
          content: [{ type: 'text', text: lines.join('\n') }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error validating BEM: ${error.message}` }],
        };
      }
    },
  },
];

export { globalClassesTools, generateBEMComponent };
