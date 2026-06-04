/**
 * Multi-Site Manager for Bricks MCP Server
 * Manages site registry, active site tracking, and switching logic.
 * Backward-compatible: falls back to env vars when no sites.json exists.
 */
import { readFileSync, statSync } from 'fs';
import { join, dirname, isAbsolute, resolve } from 'path';
import { fileURLToPath } from 'url';
import { homedir } from 'os';

const __dirname = dirname(fileURLToPath(import.meta.url));

// sites.json path: BRICKS_SITES_PATH env override (supports ~, relative, absolute)
// → falls back to the bundled ./sites.json. Lets users keep configs outside the MCP folder.
const SITES_OVERRIDE = process.env.BRICKS_SITES_PATH?.trim();
const SITES_PATH = resolveSitesPath(SITES_OVERRIDE);
const SITES_PATH_EXPLICIT = !!SITES_OVERRIDE;

function resolveSitesPath(override) {
  if (!override) return join(__dirname, 'sites.json');
  let p = override;
  if (p === '~' || p.startsWith('~/')) p = join(homedir(), p.slice(1));
  return isAbsolute(p) ? p : resolve(process.cwd(), p);
}

let sites = {};       // key → { label, url, username, password }
let activeSiteKey = null;

/**
 * Load sites from sites.json or fall back to env vars.
 * Called once at startup.
 */
function initSites() {
  try {
    const raw = readFileSync(SITES_PATH, 'utf-8');
    const data = JSON.parse(raw);

    if (!data.sites || typeof data.sites !== 'object') {
      throw new Error('sites.json missing "sites" object');
    }

    sites = data.sites;
    activeSiteKey = data.default || Object.keys(sites)[0];

    if (!sites[activeSiteKey]) {
      throw new Error(`Default site "${activeSiteKey}" not found in sites.json`);
    }

    console.error(`SITES: Loaded ${Object.keys(sites).length} site(s) from ${SITES_PATH_EXPLICIT ? SITES_PATH : 'sites.json'} (active: ${activeSiteKey})`);
  } catch (err) {
    if (err.code === 'ENOENT' && !SITES_PATH_EXPLICIT) {
      // No sites.json — fall back to env vars (backward-compatible)
      const url = process.env.WORDPRESS_URL || '';
      const username = process.env.WORDPRESS_USER || '';
      const password = process.env.WORDPRESS_APP_PASSWORD || '';

      activeSiteKey = 'env';
      sites = {
        env: { label: 'Environment Config', url, username, password },
      };

      console.error('SITES: No sites.json found — using environment variables');
    } else if (err.code === 'ENOENT') {
      // Explicit BRICKS_SITES_PATH set but file missing — fail loudly, don't silently fall back
      console.error(`SITES ERROR: BRICKS_SITES_PATH="${SITES_PATH}" but no file exists there`);
      throw err;
    } else {
      console.error(`SITES ERROR: ${err.message}`);
      throw err;
    }
  }
}

/**
 * Get the currently active site config.
 * @returns {{ key: string, url: string, username: string, password: string, label: string }}
 */
function getActiveSite() {
  const site = sites[activeSiteKey];
  if (!site) throw new Error(`No active site configured (key: ${activeSiteKey})`);
  return { key: activeSiteKey, ...site };
}

/**
 * Get just the active site key (for cache prefixing).
 * @returns {string}
 */
function getActiveSiteKey() {
  return activeSiteKey;
}

/**
 * Switch to a different site by key.
 * @param {string} key - Site key from sites.json
 */
function switchSite(key) {
  // Always re-read sites.json to pick up credential changes
  try {
    const raw = readFileSync(SITES_PATH, 'utf-8');
    const data = JSON.parse(raw);
    if (data.sites && typeof data.sites === 'object') {
      sites = data.sites;
    }
  } catch { /* ignore */ }
  if (!sites[key]) {
    const available = Object.keys(sites).join(', ');
    throw new Error(`Unknown site "${key}". Available: ${available}`);
  }
  const prev = activeSiteKey;
  activeSiteKey = key;
  console.error(`SITES: Switched ${prev} → ${key} (${sites[key].label})`);
}

/**
 * List all configured sites with active flag.
 * @returns {Array<{ key: string, label: string, url: string, username: string, active: boolean }>}
 */
function listSites() {
  // Re-read only if file changed (stat mtime check — avoids full fs read on every call)
  try {
    const stat = statSync(SITES_PATH);
    if (!listSites._lastMtime || stat.mtimeMs > listSites._lastMtime) {
      const raw = readFileSync(SITES_PATH, 'utf-8');
      const data = JSON.parse(raw);
      if (data.sites && typeof data.sites === 'object') {
        sites = data.sites;
      }
      listSites._lastMtime = stat.mtimeMs;
    }
  } catch { /* ignore — keep existing in-memory sites */ }

  return Object.entries(sites).map(([key, site]) => ({
    key,
    label: site.label,
    url: site.url,
    username: site.username,
    active: key === activeSiteKey,
  }));
}

/**
 * Validate that the active site has all required fields.
 * @returns {{ valid: boolean, missing: string[] }}
 */
function validateActiveSite() {
  const site = sites[activeSiteKey];
  const missing = [];
  if (!site) {
    return { valid: false, missing: ['site config'] };
  }
  if (!site.url) missing.push('url');
  if (!site.username) missing.push('username');
  if (!site.password) missing.push('password');
  return { valid: missing.length === 0, missing };
}

export {
  initSites,
  getActiveSite,
  getActiveSiteKey,
  switchSite,
  listSites,
  validateActiveSite,
};
