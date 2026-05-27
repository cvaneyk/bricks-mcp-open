/**
 * Advanced SEO Tools
 * Auto-fix, bulk update, readability, sitemap, social preview,
 * plugin detection, broken links, competitor extract, redirects,
 * internal linking suggestions.
 */
import { wpGet, wpGetCached, wpPut, wpPost, wpDelete } from '../utils/wp-api.js';
import { cache, TTL } from '../utils/cache.js';

const advancedSeoTools = [
  // 1. Auto-Fix
  {
    name: 'bricks_seo_auto_fix',
    description: 'Auto-generate missing SEO data from page content. Creates SEO title (from post title), meta description (from first text content), canonical URL, and og:type for pages that lack them.',
    inputSchema: {
      type: 'object',
      properties: {
        page_ids: { type: 'array', items: { type: 'number' }, description: 'Array of page IDs to fix' },
        all: { type: 'boolean', description: 'Set true to fix ALL published pages' },
        dry_run: { type: 'boolean', description: 'Preview changes without saving (default: false)' },
      },
    },
    handler: async (args) => {
      try {
        const body = {};
        if (args.all) body.all = true;
        else if (args.page_ids) body.page_ids = args.page_ids;
        else return { content: [{ type: 'text', text: 'Error: provide page_ids or set all:true' }] };
        if (args.dry_run) body.dry_run = true;

        const result = await wpPost('/seo/auto-fix', body);
        const prefix = result.dry_run ? '[DRY RUN] ' : '';
        const lines = [`${prefix}SEO Auto-Fix: ${result.pages_fixed} pages fixed`];
        result.results.forEach(p => {
          lines.push(`\n  Page ${p.page_id} "${p.title}":`);
          p.fixes.forEach(f => lines.push(`    + ${f.field}: ${f.value.substring(0, 80)}${f.value.length > 80 ? '...' : ''}`));
        });
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 2. Bulk Update
  {
    name: 'bricks_seo_bulk_update',
    description: 'Update SEO fields for multiple pages at once. Useful for setting noindex on all demo pages, or applying the same og_type to multiple pages.',
    inputSchema: {
      type: 'object',
      properties: {
        page_ids: { type: 'array', items: { type: 'number' }, description: 'Array of page IDs to update' },
        fields: { type: 'object', description: 'SEO fields to set on all pages. Keys: seo_title, description, og_image, keywords, og_type, canonical, noindex, nofollow, focus_keyword, og_title, twitter_title, twitter_description, twitter_image' },
      },
      required: ['page_ids', 'fields'],
    },
    handler: async (args) => {
      try {
        const result = await wpPut('/seo/bulk-update', { page_ids: args.page_ids, fields: args.fields });
        const fieldNames = Object.keys(args.fields).join(', ');
        return { content: [{ type: 'text', text: `Bulk SEO update: ${result.pages_updated} pages updated.\nFields: ${fieldNames}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 3. Readability
  {
    name: 'bricks_readability',
    description: 'Analyze page content readability using Flesch-Kincaid (English) and Flesch-Amstad (German) scores. Returns word count, sentence length, syllable stats, and readability grade.',
    inputSchema: {
      type: 'object',
      properties: { page_id: { type: 'number', description: 'WordPress page/post ID' } },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const data = await wpGet(`/pages/${args.page_id}/readability`);
        if (data.error) return { content: [{ type: 'text', text: data.error }] };
        const lines = [
          `Readability for page ${args.page_id}:`,
          `  Words: ${data.word_count} | Sentences: ${data.sentence_count} | Syllables: ${data.syllable_count}`,
          `  Avg sentence length: ${data.avg_sentence_length} words`,
          `  Avg syllables/word: ${data.avg_syllables_word}`,
          '',
          `  Flesch-Kincaid (EN): ${data.flesch_kincaid.score}/100 — ${data.flesch_kincaid.grade}`,
          `  Flesch-Amstad  (DE): ${data.flesch_amstad.score}/100 — ${data.flesch_amstad.grade}`,
          '',
          `  ${data.recommendation}`,
        ];
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 4. Sitemap Ping
  {
    name: 'bricks_sitemap_ping',
    description: 'Ping Google, Bing, and IndexNow to notify them about sitemap updates. Triggers faster re-crawling after content changes.',
    inputSchema: {
      type: 'object',
      properties: {
        sitemap_url: { type: 'string', description: 'Sitemap URL (default: {site}/sitemap.xml)' },
      },
    },
    handler: async (args) => {
      try {
        const body = {};
        if (args.sitemap_url) body.sitemap_url = args.sitemap_url;
        const result = await wpPost('/seo/sitemap-ping', body);
        const lines = [`Sitemap ping: ${result.sitemap_url}`];
        Object.entries(result.pinged).forEach(([engine, r]) => {
          const icon = r.status === 'ok' ? '✓' : '✗';
          lines.push(`  ${icon} ${engine}: ${r.status} (HTTP ${r.code})${r.message ? ' — ' + r.message : ''}`);
        });
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 5. Social Preview
  {
    name: 'bricks_social_preview',
    description: 'Check social media sharing preview for a page. Validates OG image dimensions (1200x630), title/description lengths, and shows Facebook + Twitter preview data.',
    inputSchema: {
      type: 'object',
      properties: { page_id: { type: 'number', description: 'WordPress page/post ID' } },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const data = await wpGet(`/pages/${args.page_id}/social-preview`);
        const lines = [
          `Social Preview for page ${args.page_id}:`,
          '',
          '--- Facebook ---',
          `  Title: ${data.facebook.title}`,
          `  Description: ${data.facebook.description || '(not set)'}`,
          `  Image: ${data.facebook.image || '(not set)'}`,
        ];
        if (data.facebook.image_info) {
          lines.push(`  Image size: ${data.facebook.image_info.width}x${data.facebook.image_info.height} (ratio: ${data.facebook.image_info.ratio})`);
        }
        lines.push('', '--- Twitter ---');
        lines.push(`  Card: ${data.twitter.card}`);
        lines.push(`  Title: ${data.twitter.title}`);
        lines.push(`  Description: ${data.twitter.description || '(not set)'}`);
        lines.push(`  Image: ${data.twitter.image || '(not set)'}`);
        if (data.issues.length > 0) {
          lines.push('', '--- Issues ---');
          data.issues.forEach(i => lines.push(`  ⚠ ${i}`));
        } else {
          lines.push('', '✓ No issues found');
        }
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 6. SEO Plugin Detection
  {
    name: 'bricks_seo_plugin_info',
    description: 'Detect installed SEO plugins (Yoast, Rank Math, AIOSEO, The SEO Framework) and their meta key mappings. Helps avoid conflicts with BAB SEO output.',
    inputSchema: {
      type: 'object',
      properties: {},
    },
    handler: async () => {
      try {
        const data = await wpGet('/seo/plugin-info');
        const lines = [`SEO Plugins: ${data.seo_plugins_found} found`];
        if (data.plugins.length === 0) {
          lines.push('  No external SEO plugins detected.');
        }
        data.plugins.forEach(p => {
          lines.push(`\n  ${p.name} v${p.version} (${p.active ? 'active' : 'inactive'})`);
          lines.push('  Meta keys:');
          Object.entries(p.meta_keys).forEach(([k, v]) => lines.push(`    ${k}: ${v}`));
        });
        lines.push('', data.recommendation);
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 7. Broken Link Checker
  {
    name: 'bricks_check_links',
    description: 'Check all links on a Bricks page for broken URLs (404, timeouts, DNS failures). Tests both content links and element links via HTTP HEAD requests.',
    inputSchema: {
      type: 'object',
      properties: { page_id: { type: 'number', description: 'WordPress page/post ID' } },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const data = await wpPost(`/pages/${args.page_id}/check-links`, {});
        const lines = [
          `Link Check for page ${args.page_id}: ${data.links_total} links found`,
          `  ✓ OK: ${data.links_ok} | ✗ Broken: ${data.links_broken}`,
        ];
        if (data.links_broken > 0) {
          lines.push('', '--- Broken Links ---');
          data.results.filter(r => !r.ok).forEach(r => {
            lines.push(`  ✗ [${r.status || 'ERR'}] ${r.url} (in: ${r.elements.join(', ')})${r.error ? ' — ' + r.error : ''}`);
          });
        }
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 8. Sitemap Analysis
  {
    name: 'bricks_sitemap_analyze',
    description: 'Analyze the XML sitemap — parses URLs, detects sitemap index, finds published pages missing from sitemap, and flags noindex pages that are listed.',
    inputSchema: {
      type: 'object',
      properties: {
        url: { type: 'string', description: 'Sitemap URL (default: {site}/sitemap.xml)' },
      },
    },
    handler: async (args) => {
      try {
        const params = args.url ? `?url=${encodeURIComponent(args.url)}` : '';
        const data = await wpGet(`/seo/sitemap-analyze${params}`);
        const lines = [
          `Sitemap Analysis: ${data.sitemap_url}`,
          `  Type: ${data.is_index ? 'Sitemap Index' : 'URL Sitemap'}`,
          `  URLs: ${data.url_count}`,
        ];
        if (data.is_index && data.sub_sitemaps) {
          lines.push('', '--- Sub-Sitemaps ---');
          data.sub_sitemaps.forEach(s => lines.push(`  ${s.loc}${s.lastmod ? ' (modified: ' + s.lastmod + ')' : ''}`));
        }
        if (data.missing_from_sitemap.length > 0) {
          lines.push('', '--- Missing from Sitemap ---');
          data.missing_from_sitemap.forEach(p => lines.push(`  ⚠ ${p.title} (ID ${p.page_id}) — ${p.url}`));
        }
        if (data.noindex_in_sitemap.length > 0) {
          lines.push('', '--- Noindex in Sitemap (conflict) ---');
          data.noindex_in_sitemap.forEach(p => lines.push(`  ✗ ${p.title} (ID ${p.page_id}) — ${p.issue}`));
        }
        lines.push('', `Issues: ${data.issues_count}`);
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 9. Competitor SEO Extract
  {
    name: 'bricks_competitor_extract',
    description: 'Extract SEO meta tags, OG/Twitter data, JSON-LD schemas, and H1 headings from any external URL. Useful for benchmarking against competitors.',
    inputSchema: {
      type: 'object',
      properties: {
        url: { type: 'string', description: 'URL to extract SEO data from' },
      },
      required: ['url'],
    },
    handler: async (args) => {
      try {
        const data = await wpPost('/seo/competitor-extract', { url: args.url });
        const lines = [`Competitor SEO: ${data.url}`];
        if (data.title) lines.push(`  Title: ${data.title}`);
        if (data.description) lines.push(`  Description: ${data.description}`);
        if (data.canonical) lines.push(`  Canonical: ${data.canonical}`);
        if (data.keywords) lines.push(`  Keywords: ${data.keywords}`);
        if (data.robots) lines.push(`  Robots: ${data.robots}`);
        if (data.og_title) lines.push(`  OG Title: ${data.og_title}`);
        if (data.og_description) lines.push(`  OG Description: ${data.og_description}`);
        if (data.og_image) lines.push(`  OG Image: ${data.og_image}`);
        if (data.twitter_title) lines.push(`  Twitter Title: ${data.twitter_title}`);
        if (data.h1_tags && data.h1_tags.length > 0) {
          lines.push(`  H1 Tags: ${data.h1_tags.join(' | ')}`);
        }
        if (data.json_ld && data.json_ld.length > 0) {
          lines.push(`  JSON-LD: ${data.json_ld.length} schema(s) — ${data.json_ld.map(s => s['@type'] || 'unknown').join(', ')}`);
        }
        if (data.analysis) {
          lines.push('', '--- Quality ---');
          if (data.analysis.title_length) lines.push(`  Title: ${data.analysis.title_length} chars (${data.analysis.title_quality})`);
          if (data.analysis.description_length) lines.push(`  Description: ${data.analysis.description_length} chars (${data.analysis.description_quality})`);
        }
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 10. Redirects — List
  {
    name: 'bricks_list_redirects',
    description: 'List all 301/302/307 redirects with hit counters.',
    inputSchema: {
      type: 'object',
      properties: {},
    },
    handler: async () => {
      try {
        const data = await wpGet('/seo/redirects');
        if (data.count === 0) {
          return { content: [{ type: 'text', text: 'No redirects configured.' }] };
        }
        const lines = [`${data.count} redirect(s):`];
        data.redirects.forEach(r => {
          lines.push(`  [${r.type}] ${r.source} → ${r.target} (${r.hits} hits, ID: ${r.id})`);
        });
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 10b. Redirects — Create
  {
    name: 'bricks_create_redirect',
    description: 'Create a 301/302/307 redirect. Hits are tracked automatically on the frontend.',
    inputSchema: {
      type: 'object',
      properties: {
        source: { type: 'string', description: 'Source path (e.g. /old-page)' },
        target: { type: 'string', description: 'Target URL (e.g. https://example.com/new-page)' },
        type: { type: 'number', description: 'Redirect type: 301 (permanent), 302 (temporary), 307 (temporary preserve method). Default: 301' },
      },
      required: ['source', 'target'],
    },
    handler: async (args) => {
      try {
        const body = { source: args.source, target: args.target };
        if (args.type) body.type = args.type;
        const result = await wpPost('/seo/redirects', body);
        const r = result.redirect;
        return { content: [{ type: 'text', text: `Redirect created: [${r.type}] ${r.source} → ${r.target} (ID: ${r.id})` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 10c. Redirects — Delete
  {
    name: 'bricks_delete_redirect',
    description: 'Delete a redirect by ID.',
    inputSchema: {
      type: 'object',
      properties: {
        redirect_id: { type: 'string', description: 'Redirect ID (from bricks_list_redirects)' },
      },
      required: ['redirect_id'],
    },
    handler: async (args) => {
      try {
        await wpDelete(`/seo/redirects/${args.redirect_id}`);
        return { content: [{ type: 'text', text: `Redirect ${args.redirect_id} deleted.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  // 11. Internal Linking Suggestions
  {
    name: 'bricks_internal_links',
    description: 'Get internal linking suggestions for a page based on keyword overlap with other published pages. Returns top 10 most relevant pages to link to.',
    inputSchema: {
      type: 'object',
      properties: { page_id: { type: 'number', description: 'WordPress page/post ID' } },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const data = await wpGet(`/pages/${args.page_id}/internal-links`);
        if (data.suggestions.length === 0) {
          return { content: [{ type: 'text', text: `No linking suggestions for page ${args.page_id} — not enough shared keywords.` }] };
        }
        const lines = [
          `Internal Linking Suggestions for "${data.page_title}" (page ${args.page_id}):`,
          '',
        ];
        data.suggestions.forEach((s, i) => {
          lines.push(`  ${i + 1}. "${s.title}" (ID ${s.page_id}, score: ${s.relevance_score})`);
          lines.push(`     URL: ${s.url}`);
          lines.push(`     Keywords: ${s.shared_keywords.join(', ')}`);
        });
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },
];

export { advancedSeoTools };
