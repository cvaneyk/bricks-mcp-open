/**
 * Menu Tools — Extended CRUD for WordPress Navigation Menus
 *
 * Adds create_menu, delete_menu, add_menu_item, delete_menu_item
 * to complement existing get_menus, get_menu_items, update_menu_items
 * in site-tools.js.
 *
 * Uses native WP REST API /wp/v2/menus and /wp/v2/menu-items.
 */
import { wpGet, wpGetStandard, wpPostStandard, wpDeleteStandard } from '../utils/wp-api.js';

export const menuTools = [

  {
    name: 'bricks_create_menu',
    description: 'Create a new WordPress navigation menu. Returns the new menu ID.',
    inputSchema: {
      type: 'object',
      properties: {
        name: { type: 'string', description: 'Menu name (e.g. "Main Navigation", "Footer Menu")' },
        locations: {
          type: 'array',
          items: { type: 'string' },
          description: 'Theme locations to assign (e.g. ["primary", "footer"]). Use bricks_get_menus to see available locations.',
        },
      },
      required: ['name'],
    },
    handler: async (args) => {
      try {
        const body = { name: args.name };
        if (args.locations?.length) body.locations = args.locations;
        const menu = await wpPostStandard(`/wp/v2/menus`, body, { raw: true });
        return {
          content: [{
            type: 'text',
            text: `Menu created!\n  ID: ${menu.id}\n  Name: ${menu.name}\n  Locations: ${(menu.locations || []).join(', ') || 'none'}`,
          }],
        };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  {
    name: 'bricks_delete_menu',
    description: 'Delete a WordPress navigation menu and all its items.',
    inputSchema: {
      type: 'object',
      properties: {
        menu_id: { type: 'number', description: 'Menu ID to delete' },
      },
      required: ['menu_id'],
    },
    handler: async (args) => {
      try {
        await wpDeleteStandard(`/wp/v2/menus/${args.menu_id}?force=true`, { raw: true });
        return { content: [{ type: 'text', text: `Menu ${args.menu_id} deleted.` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  {
    name: 'bricks_add_menu_item',
    description: 'Add a single item to an existing menu. Supports custom links, pages, posts, and categories. Use parent for submenus.',
    inputSchema: {
      type: 'object',
      properties: {
        menu_id: { type: 'number', description: 'Menu ID to add item to' },
        title: { type: 'string', description: 'Menu item display text' },
        url: { type: 'string', description: 'URL for custom links' },
        type: { type: 'string', description: 'Link type: custom (default), post_type, taxonomy', default: 'custom' },
        object: { type: 'string', description: 'Object type when type=post_type: page, post. When type=taxonomy: category, post_tag' },
        object_id: { type: 'number', description: 'Post/page/category ID when linking to existing content' },
        parent: { type: 'number', description: 'Parent menu item ID for creating submenus (0 = top level)' },
        position: { type: 'number', description: 'Menu order position (1-based)' },
        target: { type: 'string', description: '"_blank" to open in new tab' },
        classes: { type: 'array', items: { type: 'string' }, description: 'CSS classes for styling' },
      },
      required: ['menu_id', 'title'],
    },
    handler: async (args) => {
      try {
        const body = {
          title: args.title,
          menus: args.menu_id,
          type: args.type || 'custom',
          status: 'publish',
        };
        if (args.url) body.url = args.url;
        if (args.object) body.object = args.object;
        if (args.object_id) body.object_id = args.object_id;
        if (args.parent) body.parent = args.parent;
        if (args.position) body.menu_order = args.position;
        if (args.target) body.target = args.target;
        if (args.classes?.length) body.classes = args.classes;

        const item = await wpPostStandard(`/wp/v2/menu-items`, body, { raw: true });
        return {
          content: [{
            type: 'text',
            text: `Menu item added!\n  ID: ${item.id}\n  Title: ${item.title?.rendered || args.title}\n  Menu: ${args.menu_id}\n  Type: ${item.type_label || args.type}`,
          }],
        };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  {
    name: 'bricks_delete_menu_item',
    description: 'Remove a single item from a menu.',
    inputSchema: {
      type: 'object',
      properties: {
        item_id: { type: 'number', description: 'Menu item ID to remove' },
      },
      required: ['item_id'],
    },
    handler: async (args) => {
      try {
        await wpDeleteStandard(`/wp/v2/menu-items/${args.item_id}?force=true`, { raw: true });
        return { content: [{ type: 'text', text: `Menu item ${args.item_id} deleted.` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  {
    name: 'bricks_get_menu_locations',
    description: 'List all registered theme menu locations and which menus are assigned to them.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      try {
        // Try bricks-bridge endpoint first (more detail)
        const data = await wpGet('/menus');
        const menus = data.menus || [];
        const locations = data.locations || {};

        const lines = [];
        if (Object.keys(locations).length) {
          for (const [loc, menuId] of Object.entries(locations)) {
            const menu = menus.find(m => m.id === menuId);
            lines.push(`  ${loc}: ${menu ? `${menu.name} (ID: ${menuId})` : menuId || 'empty'}`);
          }
        } else {
          lines.push('  No theme locations registered.');
        }

        return { content: [{ type: 'text', text: `Menu Locations:\n\n${lines.join('\n')}` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },
];
