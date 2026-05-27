/**
 * WordPress REST API Client
 * Uses fetch + Basic Auth with Application Passwords
 * Connects to the bricks-api-bridge plugin endpoints
 *
 * Auth is dynamic per-request via site-manager (multi-site support).
 */
import config from '../config.js';
import { cache } from './cache.js';
import { getActiveSite, getActiveSiteKey } from '../site-manager.js';

// Auth header cache — avoids Base64 encoding on every request
const authHeaderCache = new Map();

function getAuthHeader() {
  const key = getActiveSiteKey();
  const cached = authHeaderCache.get(key);
  if (cached) return cached;
  const site = getActiveSite();
  const header = 'Basic ' + Buffer.from(`${site.username}:${site.password}`).toString('base64');
  authHeaderCache.set(key, header);
  return header;
}

function getSiteUrl() {
  return getActiveSite().url;
}

/**
 * Clear cached auth header (e.g. after site switch or credential change)
 */
function clearAuthCache(siteKey) {
  if (siteKey) authHeaderCache.delete(siteKey);
  else authHeaderCache.clear();
}

// Retry configuration
const RETRYABLE_STATUSES = [429, 502, 503, 504];
const RETRYABLE_ERRORS = ['ECONNRESET', 'ECONNREFUSED', 'ETIMEDOUT', 'UND_ERR_SOCKET'];
const MAX_RETRIES = 3;

/**
 * Retry wrapper with exponential backoff.
 * Only retries idempotent methods (GET, PUT).
 * 429 uses Retry-After header if present.
 */
async function withRetry(fn, method = 'GET') {
  const canRetry = ['GET', 'PUT'].includes(method.toUpperCase());

  for (let attempt = 0; attempt <= (canRetry ? MAX_RETRIES : 0); attempt++) {
    try {
      return await fn();
    } catch (error) {
      const isRetryable = RETRYABLE_ERRORS.some(e => error.message.includes(e)) ||
        (error.message.includes('API error') && RETRYABLE_STATUSES.some(s => error.message.includes(String(s))));

      if (!isRetryable || attempt >= MAX_RETRIES || !canRetry) {
        error.message += ` (after ${attempt} retries)`;
        throw error;
      }

      // Use Retry-After for 429, otherwise exponential backoff + jitter.
      let delay = Math.pow(2, attempt) * 1000 + Math.random() * 500;
      if (error.retryAfter) {
        delay = Math.max(error.retryAfter * 1000, 1000);
      }
      console.error(`RETRY ${attempt + 1}/${MAX_RETRIES}: ${error.message} — waiting ${delay}ms`);
      await new Promise(r => setTimeout(r, delay));
    }
  }
}

// Circuit breaker — stops hammering failing endpoints
const circuitBreaker = new Map(); // endpoint-base → { failures, openUntil }
const CB_THRESHOLD = 3;
const CB_COOLDOWN = 30000; // 30s cooldown after 3 consecutive failures

function recordFailure(endpoint) {
  const base = endpoint.split('?')[0];
  const cb = circuitBreaker.get(base) || { failures: 0, openUntil: 0 };
  cb.failures++;
  if (cb.failures >= CB_THRESHOLD) {
    cb.openUntil = Date.now() + CB_COOLDOWN;
    console.error(`CIRCUIT OPEN: ${base} — ${CB_COOLDOWN / 1000}s cooldown after ${cb.failures} failures`);
  }
  circuitBreaker.set(base, cb);
}

function recordSuccess(endpoint) {
  const base = endpoint.split('?')[0];
  if (circuitBreaker.has(base)) circuitBreaker.delete(base);
}

function isCircuitOpen(endpoint) {
  const base = endpoint.split('?')[0];
  const cb = circuitBreaker.get(base);
  if (!cb || cb.failures < CB_THRESHOLD) return false;
  if (Date.now() > cb.openUntil) { circuitBreaker.delete(base); return false; }
  return true;
}

/**
 * Make an authenticated request to the WordPress REST API
 */
async function wpFetch(endpoint, options = {}) {
  const method = options.method || 'GET';

  // Circuit breaker check
  if (isCircuitOpen(endpoint)) {
    throw new Error(`Circuit breaker open for ${endpoint.split('?')[0]} — retry after cooldown`);
  }

  return withRetry(async () => {
    const timeout = options.timeout || 10000;
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);

    const url = `${getSiteUrl()}${config.WP_API_BASE}${endpoint}`;

    try {
      const response = await fetch(url, {
        ...options,
        signal: controller.signal,
        headers: {
          'Authorization': getAuthHeader(),
          'Content-Type': 'application/json',
          'Accept-Encoding': 'gzip, br',
          ...options.headers,
        },
      });

      if (!response.ok) {
        const errorBody = await response.text();
        // Try to extract JSON error message for cleaner output.
        let message = errorBody;
        try {
          const parsed = JSON.parse(errorBody);
          message = parsed.message || parsed.error || errorBody;
        } catch { /* raw text fallback */ }
        const err = new Error(`WordPress API error ${response.status}: ${message}`);
        // Attach Retry-After for 429 handling.
        if (response.status === 429) {
          err.retryAfter = parseInt(response.headers.get('Retry-After') || '5', 10);
        }
        // Track failure for circuit breaker (server errors + overloaded)
        if (response.status >= 500 || response.status === 429) recordFailure(endpoint);
        throw err;
      }

      // Track success — resets circuit breaker
      recordSuccess(endpoint);
      const text = await response.text();
      return text ? JSON.parse(text) : {};
    } catch (fetchErr) {
      // Track network errors (ECONNRESET, ETIMEDOUT, etc.) for circuit breaker
      if (RETRYABLE_ERRORS.some(e => fetchErr.message.includes(e))) {
        recordFailure(endpoint);
      }
      throw fetchErr;
    } finally {
      clearTimeout(timeoutId);
    }
  }, method);
}

// Request deduplication map for in-flight GET requests.
// Entries auto-expire after 30s to prevent leaks on hung requests.
const inFlight = new Map();
const INFLIGHT_TTL = 30000;

/**
 * GET request to WP API
 */
async function wpGet(endpoint) {
  return wpFetch(endpoint);
}

/**
 * GET with deduplication — collapses concurrent identical requests
 */
async function wpGetDeduped(endpoint) {
  const key = `${getActiveSiteKey()}:GET:${endpoint}`;
  const existing = inFlight.get(key);
  if (existing && Date.now() - existing.ts < INFLIGHT_TTL) {
    return existing.promise;
  }

  const promise = wpGet(endpoint).finally(() => inFlight.delete(key));
  inFlight.set(key, { promise, ts: Date.now() });
  return promise;
}

/**
 * GET with TTL cache + deduplication
 */
async function wpGetCached(endpoint, ttl) {
  const cached = cache.get(endpoint);
  if (cached !== undefined) return cached;

  const data = await wpGetDeduped(endpoint);
  cache.set(endpoint, data, ttl);
  return data;
}

/**
 * POST request to WP API with JSON body
 */
async function wpPost(endpoint, body) {
  const basePath = endpoint.split('?')[0];
  const segment = basePath.split('/').filter(Boolean)[0] || '';
  cache.invalidatePrefix('/' + segment);

  return wpFetch(endpoint, {
    method: 'POST',
    body: JSON.stringify(body),
  });
}

/**
 * PUT request to WP API with JSON body
 * @param {string} endpoint
 * @param {object} body
 * @param {object} [options] - Additional options (e.g. { contentHash: '...' } for optimistic locking)
 */
async function wpPut(endpoint, body, options = {}) {
  cache.invalidatePrefix(endpoint.split('?')[0]);
  if (endpoint.match(/\/pages\/\d+/)) cache.invalidatePrefix('/pages');
  if (endpoint.match(/\/templates\/\d+/)) cache.invalidatePrefix('/templates');
  if (endpoint.includes('/theme-styles')) cache.delete('/theme-styles');
  if (endpoint.includes('/color-palette')) cache.invalidatePrefix('/color-palette');
  if (endpoint.includes('/global-css')) cache.invalidatePrefix('/global-css');
  if (endpoint.includes('/fonts')) cache.invalidatePrefix('/fonts');
  if (endpoint.includes('/css-variables')) cache.invalidatePrefix('/css-variables');

  const headers = {};
  if (options.contentHash) {
    headers['If-Match'] = options.contentHash;
  }

  return wpFetch(endpoint, {
    method: 'PUT',
    body: JSON.stringify(body),
    headers,
  });
}

/**
 * PATCH request to WP API with JSON body
 * @param {string} endpoint
 * @param {object} body
 * @param {object} [options] - Additional options (e.g. { contentHash: '...' } for optimistic locking)
 */
async function wpPatch(endpoint, body, options = {}) {
  cache.invalidatePrefix(endpoint.split('?')[0]);
  if (endpoint.match(/\/pages\/\d+/)) cache.invalidatePrefix('/pages');

  const headers = {};
  if (options.contentHash) {
    headers['If-Match'] = options.contentHash;
  }

  return wpFetch(endpoint, {
    method: 'PATCH',
    body: JSON.stringify(body),
    headers,
  });
}

/**
 * DELETE request to WP API
 */
async function wpDelete(endpoint) {
  cache.invalidatePrefix(endpoint.split('?')[0]);
  return wpFetch(endpoint, { method: 'DELETE' });
}

/**
 * Upload a file to the WordPress REST API.
 * Uses direct binary upload (Content-Disposition header) instead of multipart/form-data.
 * This is more reliable with Node.js native fetch() — the form-data package has
 * compatibility issues with undici's fetch (boundary not transmitted correctly).
 *
 * Metadata (title, alt_text, caption) is applied in a separate PATCH request after upload.
 */
async function wpPostFile(endpoint, filePath, metadata = {}) {
  const fs = await import('fs');
  const path = await import('path');

  const timeout = 60000;
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);

  const filename = path.basename(filePath);
  const fileBuffer = fs.readFileSync(filePath);

  // Detect MIME type from extension
  const ext = path.extname(filePath).toLowerCase();
  const mimeTypes = {
    '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
    '.gif': 'image/gif', '.webp': 'image/webp', '.avif': 'image/avif',
    '.svg': 'image/svg+xml', '.ico': 'image/x-icon',
    '.pdf': 'application/pdf', '.mp4': 'video/mp4', '.mp3': 'audio/mpeg',
    '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.otf': 'font/otf',
  };
  const contentType = mimeTypes[ext] || 'application/octet-stream';

  const url = `${getSiteUrl()}/wp-json${endpoint}`;

  try {
    // Step 1: Upload file via direct binary (matches curl --data-binary approach)
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Authorization': getAuthHeader(),
        'Content-Type': contentType,
        'Content-Disposition': `attachment; filename="${filename}"`,
      },
      body: fileBuffer,
      signal: controller.signal,
    });

    if (!response.ok) {
      const errorBody = await response.text();
      throw new Error(`WordPress API error ${response.status}: ${errorBody}`);
    }

    // Parse JSON response — strip PHP warnings that may precede the JSON
    // (e.g. SVG uploads trigger "Undefined array key width/height" warnings)
    const responseText = await response.text();
    const jsonStart = responseText.indexOf('{');
    if (jsonStart < 0) throw new Error(`Invalid response (no JSON): ${responseText.slice(0, 200)}`);
    const result = JSON.parse(responseText.slice(jsonStart));

    // Step 2: Apply metadata (title, alt_text, caption) via PATCH if provided
    const metaKeys = Object.keys(metadata).filter(k => metadata[k] != null);
    if (metaKeys.length > 0 && result.id) {
      try {
        const metaResponse = await fetch(`${getSiteUrl()}/wp-json/wp/v2/media/${result.id}`, {
          method: 'POST', // WP REST API uses POST for updates too
          headers: {
            'Authorization': getAuthHeader(),
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(metadata),
        });
        if (metaResponse.ok) {
          const updated = await metaResponse.json();
          return updated;
        }
      } catch {
        // Metadata update failed but upload succeeded — return original result
      }
    }

    return result;
  } finally {
    clearTimeout(timeoutId);
  }
}

/**
 * GET request to the standard WordPress REST API (/wp-json)
 */
async function wpGetStandard(endpoint) {
  return withRetry(async () => {
    const timeout = 10000;
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);

    const url = `${getSiteUrl()}/wp-json${endpoint}`;

    try {
      const response = await fetch(url, {
        headers: { 'Authorization': getAuthHeader(), 'Accept-Encoding': 'gzip, br' },
        signal: controller.signal,
      });

      if (!response.ok) {
        const errorBody = await response.text();
        throw new Error(`WordPress API error ${response.status}: ${errorBody}`);
      }

      const text = await response.text();
      return text ? JSON.parse(text) : {};
    } finally {
      clearTimeout(timeoutId);
    }
  }, 'GET');
}

/**
 * POST request to the standard WordPress REST API (/wp-json)
 */
async function wpPostStandard(endpoint, body) {
  return withRetry(async () => {
    const timeout = 15000;
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);

    const url = `${getSiteUrl()}/wp-json${endpoint}`;

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Authorization': getAuthHeader(),
          'Content-Type': 'application/json',
          'Accept-Encoding': 'gzip, br',
        },
        body: JSON.stringify(body),
        signal: controller.signal,
      });

      if (!response.ok) {
        const errorBody = await response.text();
        throw new Error(`WordPress API error ${response.status}: ${errorBody}`);
      }

      const text = await response.text();
      return text ? JSON.parse(text) : {};
    } finally {
      clearTimeout(timeoutId);
    }
  }, 'POST');
}

/**
 * PUT request to the standard WordPress REST API (/wp-json)
 */
async function wpPutStandard(endpoint, body) {
  return withRetry(async () => {
    const timeout = 15000;
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);

    const url = `${getSiteUrl()}/wp-json${endpoint}`;

    try {
      const response = await fetch(url, {
        method: 'PUT',
        headers: {
          'Authorization': getAuthHeader(),
          'Content-Type': 'application/json',
          'Accept-Encoding': 'gzip, br',
        },
        body: JSON.stringify(body),
        signal: controller.signal,
      });

      if (!response.ok) {
        const errorBody = await response.text();
        throw new Error(`WordPress API error ${response.status}: ${errorBody}`);
      }

      const text = await response.text();
      return text ? JSON.parse(text) : {};
    } finally {
      clearTimeout(timeoutId);
    }
  }, 'PUT');
}

/**
 * DELETE request to the standard WordPress REST API (/wp-json)
 */
async function wpDeleteStandard(endpoint) {
  const timeout = 10000;
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);

  const url = `${getSiteUrl()}/wp-json${endpoint}`;

  try {
    const response = await fetch(url, {
      method: 'DELETE',
      headers: {
        'Authorization': getAuthHeader(),
        'Accept-Encoding': 'gzip, br',
      },
      signal: controller.signal,
    });

    if (!response.ok) {
      const errorBody = await response.text();
      throw new Error(`WordPress API error ${response.status}: ${errorBody}`);
    }

    const text = await response.text();
    return text ? JSON.parse(text) : {};
  } finally {
    clearTimeout(timeoutId);
  }
}

export {
  wpFetch, wpGet, wpGetCached, wpGetDeduped,
  wpPost, wpPut, wpPatch, wpDelete,
  wpPostFile, wpGetStandard, wpPostStandard, wpPutStandard, wpDeleteStandard,
  cache, clearAuthCache,
};
