/**
 * Structured logger for Bricks MCP Server.
 * Outputs to stderr (MCP protocol uses stdout for JSON-RPC).
 * Log level controlled via LOG_LEVEL env var (default: INFO).
 *
 * Levels: ERROR=0, WARN=1, INFO=2, DEBUG=3
 */

const LEVELS = { ERROR: 0, WARN: 1, INFO: 2, DEBUG: 3 };
const LEVEL_NAMES = ['ERROR', 'WARN', 'INFO', 'DEBUG'];

const currentLevel = LEVELS[
  (process.env.LOG_LEVEL || 'INFO').toUpperCase()
] ?? LEVELS.INFO;

function write(level, category, message, data) {
  if (level > currentLevel) return;
  const entry = {
    ts: new Date().toISOString(),
    level: LEVEL_NAMES[level],
    cat: category,
    msg: message,
  };
  if (data !== undefined) entry.data = data;
  console.error(JSON.stringify(entry));
}

const logger = {
  error: (cat, msg, data) => write(LEVELS.ERROR, cat, msg, data),
  warn:  (cat, msg, data) => write(LEVELS.WARN, cat, msg, data),
  info:  (cat, msg, data) => write(LEVELS.INFO, cat, msg, data),
  debug: (cat, msg, data) => write(LEVELS.DEBUG, cat, msg, data),

  /** Current log level name */
  get level() { return LEVEL_NAMES[currentLevel]; },
};

export default logger;
export { logger, LEVELS };
