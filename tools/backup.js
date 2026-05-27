/**
 * Bricks Builder Backup & Snapshot Tools
 */
import { wpGet, wpPost, wpDelete } from '../utils/wp-api.js';

const backupTools = [
  {
    name: 'bricks_get_backup',
    description: 'Get the backup of a page\'s Bricks data. Supports multi-slot backups (1-5, newest first).',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        slot: { type: 'number', description: 'Backup slot (1-5, default: 1 = most recent)', default: 1 },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, slot = 1 } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const endpoint = slot > 1 ? `/pages/${page_id}/backup?slot=${slot}` : `/pages/${page_id}/backup`;
        const data = await wpGet(endpoint);
        if (!data || !data.backup_data) return { content: [{ type: 'text', text: `No backup found for page ${page_id} (slot ${slot}). Backups are created automatically before each update.` }] };

        const elements = Array.isArray(data.backup_data) ? data.backup_data.length : 0;
        return { content: [{ type: 'text', text: `Backup for page ${page_id} (slot ${slot}):\nCreated: ${data.timestamp || 'unknown'}\nElements: ${elements}\n\nBackup Data:\n${JSON.stringify(data.backup_data, null, 2)}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error getting backup: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_list_backups',
    description: 'List all available backup slots for a page. Shows slot number, timestamp, and element count for each backup.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const data = await wpGet(`/pages/${page_id}/backups`);
        const backups = data.backups || [];

        if (backups.length === 0) {
          return { content: [{ type: 'text', text: `No backups found for page ${page_id}.` }] };
        }

        const list = backups.map(b =>
          `Slot ${b.slot}: ${b.timestamp || 'unknown'} | ${b.element_count || 0} elements`
        ).join('\n');

        return { content: [{ type: 'text', text: `Backups for page ${page_id} (${backups.length} slot(s)):\n\n${list}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error listing backups: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_restore_backup',
    description: 'Restore a page\'s Bricks data from a backup slot.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        slot: { type: 'number', description: 'Backup slot to restore (1-5, default: 1 = most recent)', default: 1 },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, slot = 1 } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const body = slot > 1 ? { slot } : {};
        const result = await wpPost(`/pages/${page_id}/restore`, body);
        return { content: [{ type: 'text', text: `Page ${page_id} restored from backup (slot ${slot}) successfully.\nElements restored: ${result.element_count || 'unknown'}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error restoring backup: ${error.message}` }] };
      }
    },
  },
];

const snapshotTools = [
  {
    name: 'bricks_create_snapshot',
    description: 'Create a named snapshot of the current page state. Use for milestones like "client-approved", "before-redesign", etc.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        name: { type: 'string', description: 'Snapshot name (e.g. "client-approved", "before-redesign")' },
        description: { type: 'string', description: 'Optional description of this snapshot' },
      },
      required: ['page_id', 'name'],
    },
    handler: async (args) => {
      try {
        const { page_id, name, description } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!name) return { content: [{ type: 'text', text: 'Error: name is required' }] };

        const body = { name };
        if (description) body.description = description;

        const result = await wpPost(`/pages/${page_id}/snapshots`, body);
        return { content: [{ type: 'text', text: `Snapshot "${result.name}" created for page ${page_id}.\nID: ${result.snapshot_id}\nElements: ${result.element_count}\nTimestamp: ${result.timestamp}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error creating snapshot: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_list_snapshots',
    description: 'List all named snapshots for a page. Shows name, description, element count, and timestamp.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const data = await wpGet(`/pages/${page_id}/snapshots`);
        const snapshots = data.snapshots || [];

        if (snapshots.length === 0) {
          return { content: [{ type: 'text', text: `No snapshots found for page ${page_id}.` }] };
        }

        const list = snapshots.map(s => {
          const desc = s.description ? ` — ${s.description}` : '';
          return `"${s.name}" [${s.id}] | ${s.element_count} elements | ${s.timestamp}${desc}`;
        }).join('\n');

        return { content: [{ type: 'text', text: `Snapshots for page ${page_id} (${snapshots.length}):\n\n${list}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error listing snapshots: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_restore_snapshot',
    description: 'Restore a page from a named snapshot. Creates an auto-backup before restoring. Accepts snapshot ID or name.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        snapshot_id: { type: 'string', description: 'Snapshot ID (snap_...) or name to restore' },
      },
      required: ['page_id', 'snapshot_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, snapshot_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!snapshot_id) return { content: [{ type: 'text', text: 'Error: snapshot_id is required' }] };

        const result = await wpPost(`/pages/${page_id}/snapshots/${encodeURIComponent(snapshot_id)}/restore`, {});
        return { content: [{ type: 'text', text: `Snapshot "${result.name}" restored for page ${page_id}.\nElements: ${result.element_count}\nAuto-backup created before restore.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error restoring snapshot: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_delete_snapshot',
    description: 'Delete a named snapshot. Accepts snapshot ID or name.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        snapshot_id: { type: 'string', description: 'Snapshot ID (snap_...) or name to delete' },
      },
      required: ['page_id', 'snapshot_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, snapshot_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!snapshot_id) return { content: [{ type: 'text', text: 'Error: snapshot_id is required' }] };

        const result = await wpDelete(`/pages/${page_id}/snapshots/${encodeURIComponent(snapshot_id)}`);
        return { content: [{ type: 'text', text: result.message || `Snapshot deleted.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error deleting snapshot: ${error.message}` }] };
      }
    },
  },
];

export { backupTools, snapshotTools };
