/**
 * WordPress Media Library Tools
 */
import fs from 'fs';
import path from 'path';
import { wpPostFile, wpGetStandard, wpPostStandard } from '../utils/wp-api.js';

const DOWNLOAD_DIR = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../../downloads');

async function downloadToLocal(url) {
  if (!fs.existsSync(DOWNLOAD_DIR)) fs.mkdirSync(DOWNLOAD_DIR, { recursive: true });

  const urlPath = new URL(url).pathname;
  const filename = path.basename(urlPath) || `download-${Date.now()}.jpg`;
  const localPath = path.join(DOWNLOAD_DIR, filename);

  const response = await fetch(url);
  if (!response.ok) throw new Error(`Failed to download ${url}: ${response.status}`);

  const buffer = Buffer.from(await response.arrayBuffer());
  fs.writeFileSync(localPath, buffer);

  return localPath;
}

const mediaTools = [
  {
    name: 'bricks_upload_media',
    description: 'Upload an image to the WordPress Media Library. Provide either a local file_path or a URL. Returns the attachment ID, URL, and metadata needed for Bricks image elements.',
    inputSchema: {
      type: 'object',
      properties: {
        file_path: { type: 'string', description: 'Absolute path to a local image file' },
        url: { type: 'string', description: 'URL of an image to download and upload' },
        title: { type: 'string', description: 'Title for the media attachment (optional)' },
        alt_text: { type: 'string', description: 'Alt text for the image (optional)' },
        caption: { type: 'string', description: 'Caption for the image (optional)' },
      },
    },
    handler: async (args) => {
      try {
        const { file_path, url, title, alt_text, caption } = args;
        if (!file_path && !url) return { content: [{ type: 'text', text: 'Error: Provide either file_path or url' }] };

        let localPath = file_path;
        let tempFile = false;

        if (url && !file_path) {
          localPath = await downloadToLocal(url);
          tempFile = true;
        }

        if (!fs.existsSync(localPath)) return { content: [{ type: 'text', text: `Error: File not found: ${localPath}` }] };

        const metadata = {};
        if (title) metadata.title = title;
        if (alt_text) metadata.alt_text = alt_text;
        if (caption) metadata.caption = caption;

        const result = await wpPostFile('/wp/v2/media', localPath, metadata);

        // Cleanup
        if (tempFile && fs.existsSync(localPath)) fs.unlinkSync(localPath);

        const response = { id: result.id, url: result.source_url, title: result.title?.rendered || '', mime_type: result.mime_type, sizes: {} };
        if (result.media_details?.sizes) {
          for (const [sizeName, sizeData] of Object.entries(result.media_details.sizes)) {
            response.sizes[sizeName] = { url: sizeData.source_url, width: sizeData.width, height: sizeData.height };
          }
        }

        const outputLines = [
          `Media uploaded successfully!`, '', `Attachment ID: ${response.id}`, `URL: ${response.url}`,
          `Title: ${response.title}`, `MIME: ${response.mime_type}`,
          `Sizes: ${Object.keys(response.sizes).join(', ') || 'processing...'}`,
        ];

        outputLines.push('', `Use in Bricks image element:`, `"image": { "id": ${response.id}, "url": "${response.url}", "size": "large" }`);

        return { content: [{ type: 'text', text: outputLines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error uploading media: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_list_media',
    description: 'List media attachments from the WordPress Media Library. Use to find existing images and their attachment IDs.',
    inputSchema: {
      type: 'object',
      properties: {
        search: { type: 'string', description: 'Search term to filter media (optional)' },
        per_page: { type: 'number', description: 'Number of results (default: 10, max: 100)', default: 10 },
        mime_type: { type: 'string', description: 'Filter by MIME type, e.g. "image/jpeg" or "image" (optional)' },
      },
    },
    handler: async (args) => {
      try {
        const { search, per_page = 10, mime_type } = args;
        let endpoint = `/wp/v2/media?per_page=${Math.min(per_page, 100)}`;
        if (search) endpoint += `&search=${encodeURIComponent(search)}`;
        if (mime_type) endpoint += `&mime_type=${encodeURIComponent(mime_type)}`;

        const data = await wpGetStandard(endpoint);
        if (!Array.isArray(data) || data.length === 0) return { content: [{ type: 'text', text: 'No media found.' }] };

        const items = data.map(item => {
          const sizes = item.media_details?.sizes || {};
          const sizeList = Object.keys(sizes).join(', ');
          const alt = item.alt_text || '';
          const seoStatus = alt ? 'SEO: OK' : 'SEO: MISSING alt';
          return `ID: ${item.id} | ${item.mime_type} | ${item.title?.rendered || 'Untitled'} | ${seoStatus}\n  Alt: ${alt || '(empty)'}\n  URL: ${item.source_url}\n  Sizes: ${sizeList || 'N/A'}`;
        });

        return { content: [{ type: 'text', text: `Found ${data.length} media item(s):\n\n${items.join('\n\n')}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error listing media: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_bulk_media_seo',
    description: 'Find all media items missing SEO texts and auto-generate alt_text + title from filename context. Optionally apply changes. Shows a preview table first.',
    inputSchema: {
      type: 'object',
      properties: {
        apply: { type: 'boolean', description: 'If true, write the generated SEO texts. If false (default), only preview.', default: false },
        per_page: { type: 'number', description: 'How many media items to scan (default: 100, max: 100)', default: 100 },
        overrides: {
          type: 'object',
          description: 'Manual overrides by media ID, e.g. {"1707": {"alt_text": "Custom alt", "title": "Custom title"}}',
          additionalProperties: {
            type: 'object',
            properties: {
              alt_text: { type: 'string' },
              title: { type: 'string' },
              caption: { type: 'string' },
              description: { type: 'string' },
            },
          },
        },
      },
    },
    handler: async (args) => {
      try {
        const { apply = false, per_page = 100, overrides = {} } = args;

        // Fetch all media (paginate if needed)
        const allMedia = [];
        let page = 1;
        const batchSize = Math.min(per_page, 100);

        while (allMedia.length < per_page) {
          const batch = await wpGetStandard(`/wp/v2/media?per_page=${batchSize}&page=${page}&_fields=id,title,alt_text,caption,description,source_url,mime_type`);
          if (!Array.isArray(batch) || batch.length === 0) break;
          allMedia.push(...batch);
          if (batch.length < batchSize) break;
          page++;
        }

        if (allMedia.length === 0) {
          return { content: [{ type: 'text', text: 'No media items found.' }] };
        }

        // Find items missing alt text
        const missing = allMedia.filter(item => !item.alt_text);
        const withAlt = allMedia.length - missing.length;

        // Generate SEO texts from filename
        function generateSeoFromFilename(url, existingTitle) {
          const filename = url.split('/').pop().split('?')[0];
          // Remove extension, hash suffixes, size suffixes
          const base = filename
            .replace(/\.[^.]+$/, '')           // remove extension
            .replace(/-\d+x\d+$/, '')          // remove -WxH size suffix
            .replace(/-edited(-\d+)?$/, '')     // remove -edited suffix
            .replace(/-scaled$/, '')            // remove -scaled suffix
            .replace(/-\d+$/, '');              // remove trailing number
          // Convert slugs to readable text
          const readable = base
            .replace(/[-_]+/g, ' ')
            .replace(/\b\w/g, c => c.toUpperCase())
            .trim();
          return {
            alt_text: readable,
            title: existingTitle || readable,
          };
        }

        // Build proposals
        const proposals = missing.map(item => {
          const manualOverride = overrides[String(item.id)];
          if (manualOverride) {
            return { id: item.id, url: item.source_url, ...manualOverride, source: 'manual' };
          }
          const generated = generateSeoFromFilename(item.source_url, item.title?.rendered);
          return { id: item.id, url: item.source_url, alt_text: generated.alt_text, title: generated.title, source: 'auto' };
        });

        // Preview table
        const lines = [
          `## Media SEO Audit`,
          '',
          `Total media: ${allMedia.length} | With alt: ${withAlt} | **Missing alt: ${missing.length}**`,
          '',
        ];

        if (proposals.length === 0) {
          lines.push('All media items have alt text. Nothing to do.');
          return { content: [{ type: 'text', text: lines.join('\n') }] };
        }

        lines.push(`| ID | Current Title | Proposed Alt Text | Source |`);
        lines.push(`|----|---------------|-------------------|--------|`);
        proposals.forEach(p => {
          const shortUrl = p.url.split('/').pop();
          lines.push(`| ${p.id} | ${shortUrl} | ${p.alt_text} | ${p.source} |`);
        });

        // Apply if requested
        if (apply) {
          let updated = 0;
          let errors = 0;

          for (const p of proposals) {
            try {
              const body = {};
              if (p.alt_text) body.alt_text = p.alt_text;
              if (p.title) body.title = p.title;
              if (p.caption) body.caption = p.caption;
              if (p.description) body.description = p.description;
              await wpPostStandard(`/wp/v2/media/${p.id}`, body);
              updated++;
            } catch (e) {
              errors++;
            }
          }

          lines.push('', `### Applied: ${updated} updated, ${errors} errors`);
        } else {
          lines.push('', `*Preview only — run with \`apply: true\` to write these changes.*`);
          lines.push(`*Use \`overrides\` to customize specific items before applying.*`);
        }

        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error in bulk media SEO: ${error.message}` }] };
      }
    },
  },
];

export { mediaTools };
