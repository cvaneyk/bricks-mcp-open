/**
 * Configuration for Bricks Builder MCP Server — Open Source Edition
 * Credentials are managed by site-manager.js (multi-site support).
 */

const config = {
  SERVER_NAME: "bricks-builder",
  SERVER_VERSION: "1.0.3",

  // Custom plugin endpoint base path
  WP_API_BASE: '/wp-json/bricks-bridge/v1',

  // Server-level guidance injected into the client's system context via the MCP
  // `instructions` field. Steers any model toward Bricks' native, structured path
  // over freelancing raw code — matters most for models with weaker tool discipline.
  BUILD_INSTRUCTIONS: [
    'This server builds and edits Bricks Builder pages. Prefer the native, structured Bricks path over freelancing raw code:',
    '- Before generating elements, call bricks_get_theme_styles and bricks_list_global_classes, then reuse those tokens and classes instead of inline styling.',
    '- Style through settings (settings.style:"primary"|"secondary", _typography, global classes). Use _cssCustom only for what settings cannot express.',
    '- Use native elements (text-link for links, button for buttons, heading/text-basic for copy) rather than generic divs with inline CSS.',
    '- Prefer bricks_patch_page for edits and the section/preset tools for new layouts over hand-writing large element arrays or raw HTML.',
  ].join('\n'),
};

export default config;
