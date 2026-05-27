/**
 * Standardized MCP tool response helpers.
 * Ensures consistent response format across all 185+ tools.
 */
import logger from './logger.js';

/**
 * Format a successful tool response.
 * @param {string|object} data - Response data (string or JSON-serializable object)
 * @returns {{ content: Array<{type: string, text: string}> }}
 */
function toolResult(data) {
  const text = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
  return { content: [{ type: 'text', text }] };
}

/**
 * Format an error tool response.
 * @param {string|Error} error - Error message or Error object
 * @param {string} [toolName] - Optional tool name for logging
 * @returns {{ content: Array<{type: string, text: string}>, isError: true }}
 */
function toolError(error, toolName) {
  const message = error instanceof Error ? error.message : String(error);
  if (toolName) {
    logger.error('tool', `${toolName} failed: ${message}`);
  }
  return {
    content: [{ type: 'text', text: `Error: ${message}` }],
    isError: true,
  };
}

/**
 * Wrap an async tool handler with automatic error catching.
 * @param {string} toolName - Tool name for error logging
 * @param {Function} handler - Async handler function
 * @returns {Function} Wrapped handler
 */
function withToolErrorHandler(toolName, handler) {
  return async (args) => {
    try {
      return await handler(args);
    } catch (error) {
      return toolError(error, toolName);
    }
  };
}

export { toolResult, toolError, withToolErrorHandler };
