/**
 * Bricks Builder HTML-to-Bricks Converter Tool
 *
 * Accepts standard HTML + optional CSS and returns valid Bricks Builder
 * JSON elements, ready for use with bricks_update_page or bricks_append_elements.
 *
 * Bricks 2.3 update: Tries the native Bricks REST endpoint first (if available),
 * falls back to our local converter. Native converter has better CSS mapping for
 * Bricks-specific properties.
 */
import { htmlToBricks } from '../utils/html-converter.js';
import { validateContent } from '../utils/validator.js';
import { autofix } from '../utils/autofix.js';
import { wpPost, wpGetCached } from '../utils/wp-api.js';
import { TTL } from '../utils/cache.js';

/**
 * Try the native Bricks 2.3 HTML-to-Bricks conversion endpoint.
 *
 * @param {string} html - HTML markup
 * @param {string} css - Optional CSS
 * @returns {Array|null} Converted elements or null if endpoint not available
 */
/**
 * Build a class registry from the site's global classes.
 * Maps class names to their Bricks settings, so the HTML converter can
 * resolve CSS class names to existing global classes instead of inlining.
 *
 * @returns {Map<string, {id: string, name: string, settings: object}>}
 */
async function buildClassRegistry() {
  try {
    const data = await wpGetCached('/global-classes', TTL.GLOBAL_CLASSES);
    const classes = data?.classes || data || [];
    if (!Array.isArray(classes)) return new Map();

    const registry = new Map();
    for (const cls of classes) {
      if (cls.name && cls.id) {
        registry.set(cls.name, { id: cls.id, name: cls.name, settings: cls.settings || {} });
      }
    }
    return registry;
  } catch {
    return new Map();
  }
}

/**
 * Resolve HTML class names against the global class registry.
 * For each element, check if its source CSS classes match any global class.
 * If a match is found, add the global class ID to _cssGlobalClasses and
 * remove redundant inline styles that the global class already provides.
 *
 * @param {Array} elements - Bricks elements (mutated in place)
 * @param {Map} registry - Class registry from buildClassRegistry()
 * @returns {{ resolved: number, classes: string[] }} Stats
 */
function resolveGlobalClasses(elements, registry) {
  if (!registry || registry.size === 0) return { resolved: 0, classes: [] };

  let resolved = 0;
  const resolvedNames = new Set();

  for (const el of elements) {
    if (!el.label && !el._sourceClasses) continue;

    // Source classes are stored in label (from html-converter) or _sourceClasses
    const sourceClasses = (el._sourceClasses || el.label || '').split(/\s+/).filter(Boolean);
    const matchedIds = [];

    for (const cls of sourceClasses) {
      const globalClass = registry.get(cls);
      if (globalClass) {
        matchedIds.push(globalClass.id);
        resolvedNames.add(cls);
        resolved++;
      }
    }

    if (matchedIds.length > 0) {
      const existing = el.settings?._cssGlobalClasses || [];
      el.settings._cssGlobalClasses = [...new Set([...existing, ...matchedIds])];
    }

    // Clean up internal tracking field
    delete el._sourceClasses;
  }

  return { resolved, classes: [...resolvedNames] };
}

async function tryNativeConverter(html, css) {
  try {
    // Bricks 2.3 may expose an HTML import endpoint
    const result = await wpPost('/html-to-bricks', { html, css }, {
      // Use Bricks native API base, not our bridge
      baseOverride: '/wp-json/bricks/v1',
    });
    if (result && Array.isArray(result.elements || result.content || result)) {
      return result.elements || result.content || result;
    }
    return null;
  } catch {
    // Endpoint not available — expected for Bricks <2.3 or Builder-UI-only feature
    return null;
  }
}

const converterTools = [
  {
    name: 'bricks_html_to_bricks',
    description: 'Convert HTML/CSS to Bricks Builder JSON elements. Tries Bricks 2.3 native converter first, falls back to our local converter. Handles flex→container, grid→_cssCustom, px stripping, and all Bricks conventions automatically.',
    inputSchema: {
      type: 'object',
      properties: {
        html: {
          type: 'string',
          description: 'HTML markup to convert. Can be a full page or a fragment.',
        },
        css: {
          type: 'string',
          description: 'Optional CSS stylesheet to apply. Class-based rules will be matched to elements.',
        },
        wrap_in_section: {
          type: 'boolean',
          description: 'Whether to wrap top-level non-section elements in section > container. Default: true.',
        },
        prefer_native: {
          type: 'boolean',
          description: 'Try Bricks 2.3 native HTML converter first (default: true). Falls back to local converter if unavailable.',
          default: true,
        },
        resolve_classes: {
          type: 'boolean',
          description: 'Resolve HTML CSS class names against the site\'s global class registry. Matches are added as _cssGlobalClasses. Default: true.',
          default: true,
        },
      },
      required: ['html'],
    },
    handler: async (args) => {
      try {
        const { html, css = '', wrap_in_section = true, prefer_native = true, resolve_classes = true } = args;

        if (!html || !html.trim()) {
          return { content: [{ type: 'text', text: 'Error: html is required and must not be empty' }] };
        }

        let elements = null;
        let usedNative = false;

        // Try native Bricks 2.3 converter first
        if (prefer_native) {
          elements = await tryNativeConverter(html, css);
          if (elements && elements.length > 0) {
            usedNative = true;
          }
        }

        // Fallback to local converter
        if (!elements || elements.length === 0) {
          elements = htmlToBricks(html, css, { wrapInSection: wrap_in_section });
        }

        if (elements.length === 0) {
          return { content: [{ type: 'text', text: 'No convertible HTML elements found. Check that the input contains valid HTML tags.' }] };
        }

        // Run through autofix
        const fixResult = autofix(elements);
        elements = fixResult.content;

        // Resolve CSS classes against global class registry
        let classResolution = { resolved: 0, classes: [] };
        if (resolve_classes) {
          const registry = await buildClassRegistry();
          if (registry.size > 0) {
            classResolution = resolveGlobalClasses(elements, registry);
          }
        }

        // Validate
        const validation = validateContent(elements);

        // Build response
        const converter = usedNative ? 'Bricks 2.3 native' : 'local';
        const parts = [`Converted HTML to ${elements.length} Bricks element(s) (${converter} converter).`];

        if (fixResult.log.length > 0) {
          parts.push(`Auto-fixed ${fixResult.log.length} issue(s): ${fixResult.log.join('; ')}`);
        }

        if (classResolution.resolved > 0) {
          parts.push(`Resolved ${classResolution.resolved} CSS class(es) to global classes: ${classResolution.classes.join(', ')}`);
        }

        if (validation.warnings.length > 0) {
          parts.push(`Warnings: ${validation.warnings.join('; ')}`);
        }

        if (validation.info.length > 0) {
          parts.push(`Info: ${validation.info.join('; ')}`);
        }

        if (!validation.valid) {
          parts.push(`Errors (manual fix needed): ${validation.errors.join('; ')}`);
        }

        parts.push('');
        parts.push(JSON.stringify(elements, null, 2));

        return { content: [{ type: 'text', text: parts.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error converting HTML: ${error.message}` }] };
      }
    },
  },
];

export { converterTools };
