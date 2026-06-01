# Changelog

## 1.0.3 (2026-06-01)

### Added
- **Security audit** — two new read-only tools: `bricks_security_audit` and `bricks_security_inventory`. The audit scores the site 0–100 (A–F) across Bricks-core CVE exposure, bridge route permissions (a self-audit of the bridge's own write surfaces), code-element exposure, platform currency (WordPress core / PHP / plugin updates), configuration hygiene, and access/transport. Findings are returned worst-first with remediation; any open CRITICAL hard-caps the grade to F. It is a posture/exposure report, **not** a malware scanner. Outdated-component detection reads WordPress' own update transients — no external network calls. Plugin-side, behind the `bab_security_audit_enabled` option (default on). Brings the MCP to **107 tools**.
- New bridge endpoints (admin-only): `GET /security/audit` and `GET /security/inventory/components`.

### Security
- **Hardened code-surface routes (defense in depth).** The `sign-code` and page-`assets` write endpoints now require `manage_options` instead of `edit_posts`, so signing executable Bricks code or storing raw page CSS/JS is restricted to administrators. Gated by the `bab_harden_code_routes` option (default on); set it to `false` to restore the previous behavior. Structured element writes (`PUT /pages/{id}`) and the GSAP flag remain at `edit_posts`. The `scripts` endpoint already required `manage_options`.

### Changed
- Plugin version bumped to 1.0.1 (security audit class + route hardening).

## 1.0.2 (2026-05-28)

### Changed
- Tool descriptions for `bricks_patch_page`, `bricks_append_elements`, and `bricks_build_page` now explicitly advertise the plugin-side auto-backup guarantee that was previously documented only on `bricks_update_page`. All four destructive write operations create a backup before writing — enforced server-side, cannot be skipped from the MCP/LLM. No behavior change; closes a documentation gap. (h/t [u/justinnealey](https://www.reddit.com/r/BricksBuilder/) for flagging it during the v1.0.1 launch discussion.)

## 1.0.1 (2026-05-28)

### Fixed
- Validator allowlist expanded from 24 to 60 elements. Previously rejected: `text-link`, `list`, `svg`, `html`, `divider`, `alert`, `progress-bar`, `counter`, `countdown`, `breadcrumbs`, `search`, `social-icons`, `icon-box`, `audio`, `map`, `shortcode`, `logo`, `slider`, `lottie`, `nav-nested`, `offcanvas`, `toggle`, `sidebar`, `tabs`, `div`, and 12 `post-*` template elements. (h/t [u/MysteryBros](https://www.reddit.com/r/BricksBuilder/) for flagging the missing `text-link`.)

### Changed
- Build-tool descriptions (`bricks_build_page`, `bricks_create_page`, `bricks_append_elements`) now nudge the LLM toward theme-first workflow: fetch `bricks_get_theme_styles` and `bricks_list_global_classes` before generating elements, prefer `settings.style: "primary"` on buttons over manual styling, use `text-link` for links instead of styled text-basic. Same tools, sharper guidance.

## 1.0.0 (2026-04-10)

### Initial Release

- 100+ MCP tools for Bricks Builder
- Full page CRUD (list, get, update, patch, append, build, clone, search)
- Template management (CRUD, clone, import, search)
- Global CSS classes with BEM support
- Complete style system (colors, fonts, CSS variables, theme styles)
- Backup and snapshot management
- SEO tools (meta, schema, sitemap, redirects, link checking)
- WordPress content management (posts, categories, tags)
- Navigation menu management
- Section presets (list, instantiate, save)
- Media management (upload, list, edit)
- Multi-site support with runtime switching
- HTML to Bricks converter
- Batch operations (up to 20 per request)
- Per-page asset management (CSS/JS separation)
- Security hardening (rate limiting, enumeration protection)
- Responsive inference for automatic breakpoint generation
