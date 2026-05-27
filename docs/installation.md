# Installation Guide

## Prerequisites

- WordPress >= 5.6 with Bricks Builder >= 2.0 installed
- Node.js >= 18.0.0
- An MCP-compatible AI assistant (Claude Code, Cursor, Windsurf, etc.)

## Step 1: Install the WordPress Plugin

### Option A: Manual Upload

1. Download the `plugin/bricks-api-bridge/` folder from this repository
2. Upload it to `wp-content/plugins/bricks-api-bridge/` on your WordPress site
3. Go to WordPress Admin > Plugins
4. Activate "Bricks API Bridge"

### Option B: ZIP Upload

1. Create a ZIP of the `plugin/bricks-api-bridge/` folder
2. Go to WordPress Admin > Plugins > Add New > Upload Plugin
3. Upload the ZIP and activate

## Step 2: Create an Application Password

1. Go to WordPress Admin > Users > Your Profile
2. Scroll down to "Application Passwords"
3. Enter a name: `MCP Server`
4. Click "Add New Application Password"
5. **Copy the password immediately** — it won't be shown again

> If you don't see "Application Passwords", your hosting may have disabled it. The plugin re-enables this automatically, but you may need to reload the page after activating the plugin.

## Step 3: Install the MCP Server

```bash
git clone https://github.com/developer2013/bricks-mcp-open.git
cd bricks-mcp-open
npm install
```

## Step 4: Configure Credentials

### Single Site

Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

Edit `.env` with your WordPress credentials:

```env
WORDPRESS_URL=https://your-site.com
WORDPRESS_USER=admin
WORDPRESS_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
```

### Multiple Sites

Copy `sites.json.example` to `sites.json`:

```bash
cp sites.json.example sites.json
```

Edit `sites.json` with your sites. When `sites.json` exists, it takes priority over `.env`.

## Step 5: Connect to Your AI Assistant

### Claude Code

Add to `~/.claude/.mcp.json`:

```json
{
  "mcpServers": {
    "bricks": {
      "command": "node",
      "args": ["/absolute/path/to/bricks-mcp/index.js"]
    }
  }
}
```

Or configure per-project in `.mcp.json` at your project root.

### Cursor

Add to `.cursor/mcp.json` in your project directory.

### Other MCP Clients

The server uses stdio transport. Configure your client to run:

```
node /path/to/bricks-mcp/index.js
```

## Step 6: Verify

Ask your AI assistant:

> "Test the connection to my Bricks Builder site."

It should call `bricks_connection_test` and report success with your site URL and WordPress version.

## Troubleshooting

### "Missing credentials" on startup

Check that your `.env` or `sites.json` contains the correct URL, username, and application password.

### 401 Unauthorized

- Verify the Application Password is correct (no extra spaces)
- Make sure the WordPress user has the `edit_posts` capability (Editor or Administrator role)
- Some hosting providers block the `Authorization` header — the plugin attempts to recover it automatically

### 403 Forbidden

- Your user needs `edit_posts` for most operations
- Admin operations (theme styles, global CSS) require `manage_options` (Administrator role)

### Plugin not found (404 on API calls)

- Make sure the Bricks API Bridge plugin is activated
- Verify the URL in your config matches your WordPress site URL exactly (including `https://`)
- Check that permalinks are enabled (Settings > Permalinks > any option other than "Plain")
