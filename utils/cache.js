/**
 * In-memory TTL + LRU cache for WordPress API responses.
 *
 * Uses Map insertion-order for O(1) LRU eviction:
 * - On access: delete + re-insert moves entry to end (most recent)
 * - On evict: first entry in Map is the least recently used
 *
 * Proactive cleanup runs every 60s to purge expired entries.
 */
import { getActiveSiteKey } from '../site-manager.js';
import logger from './logger.js';

/** Estimate byte size of a cached value without full serialization. */
function estimateBytes(value) {
  if (value === null || value === undefined) return 8;
  if (typeof value === 'string') return value.length * 2;
  if (typeof value === 'number' || typeof value === 'boolean') return 8;
  // For objects/arrays, use JSON length as rough estimate (cheaper than Buffer.byteLength)
  try { return JSON.stringify(value).length; } catch { return 1024; }
}

class TTLCache {
  constructor(maxSize = 500, maxBytes = 25 * 1024 * 1024) {
    this.cache = new Map();
    this.maxSize = maxSize;
    this.maxBytes = maxBytes;
    this.currentBytes = 0;
    this.stats = { hits: 0, misses: 0 };

    // Proactive expired-entry cleanup ~60s (prime interval, Cicada Strategy).
    this._cleanupInterval = setInterval(() => this._purgeExpired(), 60013);
    if (this._cleanupInterval.unref) this._cleanupInterval.unref();
  }

  get(key) {
    const entry = this.cache.get(key);
    if (!entry) { this.stats.misses++; return undefined; }
    if (Date.now() > entry.expiry) {
      this.cache.delete(key);
      this.stats.misses++;
      return undefined;
    }
    // O(1) LRU: delete + re-insert moves to end of Map.
    this.cache.delete(key);
    this.cache.set(key, entry);
    this.stats.hits++;
    logger.debug('cache', `HIT ${key} (rate: ${this.hitRate})`);
    return entry.value;
  }

  /** Cache hit rate as percentage string */
  get hitRate() {
    const total = this.stats.hits + this.stats.misses;
    return total === 0 ? '0%' : `${Math.round(this.stats.hits / total * 100)}%`;
  }

  set(key, value, ttlMs) {
    const entryBytes = estimateBytes(value);

    // If key exists, subtract old size and delete for insertion-order update.
    if (this.cache.has(key)) {
      this.currentBytes -= (this.cache.get(key).bytes || 0);
      this.cache.delete(key);
    }

    // Evict until under both limits
    while (this.cache.size >= this.maxSize || (this.currentBytes + entryBytes > this.maxBytes && this.cache.size > 0)) {
      this._evictOldest();
    }

    this.currentBytes += entryBytes;
    this.cache.set(key, { value, expiry: Date.now() + ttlMs, bytes: entryBytes });
  }

  /** O(1) eviction: first Map entry is LRU. */
  _evictOldest() {
    const firstKey = this.cache.keys().next().value;
    if (firstKey !== undefined) {
      const entry = this.cache.get(firstKey);
      this.currentBytes -= (entry?.bytes || 0);
      logger.debug('cache', `EVICT LRU ${firstKey}`);
      this.cache.delete(firstKey);
      this.stats.evictions = (this.stats.evictions || 0) + 1;
    }
  }

  /** Purge all expired entries. Runs on timer. */
  _purgeExpired() {
    const now = Date.now();
    let purged = 0;
    for (const [key, entry] of this.cache.entries()) {
      if (now > entry.expiry) {
        this.currentBytes -= (entry.bytes || 0);
        this.cache.delete(key);
        purged++;
      }
    }
    if (purged > 0) {
      logger.debug('cache', `Purged ${purged} expired entries, ${this.cache.size} remaining, ${Math.round(this.currentBytes / 1024)}KB used`);
    }
  }

  delete(key) {
    const entry = this.cache.get(key);
    if (entry) this.currentBytes -= (entry.bytes || 0);
    this.cache.delete(key);
  }

  invalidatePrefix(prefix) {
    for (const [key, entry] of this.cache.entries()) {
      if (key.startsWith(prefix)) {
        this.currentBytes -= (entry.bytes || 0);
        this.cache.delete(key);
      }
    }
  }

  clear() {
    this.cache.clear();
    this.currentBytes = 0;
  }

  get size() {
    return this.cache.size;
  }
}

/**
 * Site-aware cache wrapper.
 * Prefixes all keys with the active site key so data from
 * different sites never collides.
 */
class SiteAwareCache {
  constructor(inner) {
    this.inner = inner;
  }

  _key(key) {
    return `${getActiveSiteKey()}:${key}`;
  }

  get(key) {
    return this.inner.get(this._key(key));
  }

  set(key, value, ttlMs) {
    this.inner.set(this._key(key), value, ttlMs);
  }

  delete(key) {
    this.inner.delete(this._key(key));
  }

  invalidatePrefix(prefix) {
    this.inner.invalidatePrefix(`${getActiveSiteKey()}:${prefix}`);
  }

  /** Clear all entries for the active site */
  clearSite() {
    this.inner.invalidatePrefix(`${getActiveSiteKey()}:`);
  }

  /** Clear everything (all sites) */
  clear() {
    this.inner.clear();
  }

  get size() {
    return this.inner.size;
  }
}

const innerCache = new TTLCache();
const cache = new SiteAwareCache(innerCache);

/**
 * TTL constants use prime-number intervals (Cicada Strategy).
 *
 * Biological basis: Periodical cicadas use 13/17-year prime cycles to avoid
 * synchronizing with predator cycles. LCM of two primes = their product,
 * maximizing time between collisions.
 *
 * Technical benefit: Prevents "thundering herd" — when multiple cache keys
 * expire simultaneously, all hit the WordPress API at once. Prime intervals
 * ensure near-zero simultaneous expiry across different TTL categories.
 *
 * Example: PAGE_LIST (29989ms) and TEMPLATES (30011ms) collide every
 * 29989 × 30011 ≈ 10.4 days instead of every 30s with round 30000ms values.
 */
const TTL = {
  PAGE_LIST:      29989,    // ~30s (prime)
  PAGE_DETAIL:    14983,    // ~15s (prime)
  THEME_STYLES:   300017,   // ~5m  (prime)
  TEMPLATES:      30011,    // ~30s (prime, distinct from PAGE_LIST)
  MEDIA:          60013,    // ~1m  (prime)
  PRESETS:        300043,   // ~5m  (prime, distinct from THEME_STYLES)
  GLOBAL_CLASSES: 299993,   // ~5m  (prime, distinct from both)
  COLOR_PALETTE:  300073,   // ~5m  (prime, distinct from all others)
};

/**
 * Expose raw cache stats for telemetry.
 * @returns {{hits: number, misses: number, evictions: number, size: number, hitRate: string}}
 */
export function getCacheStats() {
  return {
    ...innerCache.stats,
    size: innerCache.size,
    hitRate: innerCache.hitRate,
    bytesUsed: innerCache.currentBytes,
    bytesUsedMB: `${(innerCache.currentBytes / 1024 / 1024).toFixed(1)}MB`,
    maxBytes: innerCache.maxBytes,
  };
}

export { TTLCache, cache, TTL };
