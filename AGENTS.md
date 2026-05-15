# AGENTS.md — acrossai-mcp-manager (WordPress plugin)

> Full reference for AI coding agents working on this repository.

---

## Plugin identity

| Field           | Value                                         |
|-----------------|-----------------------------------------------|
| Plugin slug     | `acrossai-mcp-manager`                        |
| Text domain     | `acrossai-mcp-manager`                        |
| PHP NS root     | `ACROSSAI_MCP_MANAGER\`                       |
| PSR-4 root      | `src/`                                        |
| Current version | `1.5.0`                                       |
| Min PHP         | 7.4                                           |
| Min WP          | 5.9                                           |
| License         | GPL-2.0-or-later                              |
| WP.org slug     | `mcp-manager`                                 |

---

## Purpose

Manages MCP (Model Context Protocol) server entries stored in a custom DB table and exposes them to the `@acrossai/mcp-manager` CLI tool via a REST API. Provides:

- A WP admin list/edit UI for MCP servers.
- A plugin settings page (WP Settings API).
- A frontend-hosted CLI auth page (virtual URL `/acrossai-mcp-manager/`).
- REST endpoints consumed by the `@acrossai/mcp-manager` npm CLI tool.
- Per-server access control — restrict which WordPress users may call a server's MCP endpoint.

---

## Repository layout

```
acrossai-mcp-manager.php      Main plugin file. Defines constants, boots Plugin::instance(),
                               registers activation/deactivation hooks, flushes rewrite rules.

src/
  Core/
    Plugin.php                 Singleton. Instantiates all subsystems:
                               Settings, Controller, CliController, FrontendAuth.

  Admin/
    Settings.php               Admin menu + submenu, WP Settings API, server list/edit pages.
                               Tab renderer for: overview, npm, wp-cli, and all client tabs.
    SettingsRenderer.php       Reserved utility class (intentionally empty for now).
    MCPServerListTable.php     WP_List_Table subclass for the server list view.
    ApplicationPasswords.php   Manages WP Application Passwords for MCP clients.
                               Exposes REST endpoints for admin JS to call.
                               Builds the dynamic server key: {sitename}-{serverslug}.

  Frontend/
    FrontendAuth.php           Virtual frontend page at /acrossai-mcp-manager/.
                               Handles CLI auth approval flow outside WP admin.

  REST/
    CliController.php          REST routes under acrossai-mcp-manager/v1/*.
                               Consumed by the @acrossai/mcp-manager CLI tool.

  Database/
    MCPServerTable.php         Custom DB table: {prefix}acrossai_mcp_servers.
                               CRUD helpers + schema versioning via DB_VERSION option.

  MCP/
    Controller.php             Bridges WP to the wordpress/mcp-adapter package.

  [AccessControl classes live in the wpboilerplate/wpb-access-control composer package]
  [Installed at: vendor/wpboilerplate/wpb-access-control/src/]
  [Namespace: WPBoilerplate\AccessControl\]

assets/
  admin.css                   Styles for all admin pages.
                               .mcp-config-json   — wraps JSON textarea blocks.
                               .mcp-cmd           — compact single-line command textarea.
  admin.js                    JS for client tab credential generation (calls REST).
```

---

## Admin menu structure

```
WP Admin sidebar
└── MCP Manager  (slug: acrossai_mcp_manager)
    ├── MCP Manager  (the list page — auto-added by add_menu_page)
    └── Settings     (slug: acrossai_mcp_manager_settings)
```

### List page — `?page=acrossai_mcp_manager`

`WP_List_Table` displaying all servers with Status and Actions columns.

### Edit page — `?page=acrossai_mcp_manager&action=edit&server={id}`

Tabbed view per server. Tabs (in order):

| Tab slug         | Contents                                                                          |
|------------------|-----------------------------------------------------------------------------------|
| `overview`       | Server name, description, status toggle, MCP API URL                             |
| `npm`            | npx CLI command — gated by `acrossai_mcp_npm_login_enabled`                      |
| `clients`        | Grouped MCP clients tab with pill sub-nav (see sub-tabs below)                   |
| `wp-cli`         | STDIO transport commands from the mcp-adapter package                            |
| `tools`          | Read-only list of 3 built-in MCP tools                                           |
| `abilities`      | Read-only list of WordPress abilities (MCP-public + private)                     |
| `access-control` | Per-server access control rules (all server types)                               |
| `mcp-tracker`    | MCP Tracker plugin promotion + link to request log (all server types)            |
| `update-server`  | Editable fields — database servers only                                          |
| `danger-zone`    | Delete server — database servers only                                            |

**MCP Clients sub-tabs** (rendered inside `clients` as pill-style secondary nav):

| Client ID  | Client name           |
|------------|-----------------------|
| `openai`   | OpenAI (ChatGPT)      |
| `claude`   | Anthropic Claude      |
| `claude-code` | Claude Code CLI    |
| `vscode`   | VS Code               |
| `copilot`  | GitHub Copilot        |
| `codex`    | OpenAI Codex CLI      |
| `cursor`   | Cursor AI Editor      |
| `custom`   | Custom Client         |

URL format for client sub-tabs: `?tab=clients&client={client_id}`

**npm tab gate**: if `acrossai_mcp_npm_login_enabled` is `false` (default), the npm tab shows a warning notice with a link to Settings instead of the command.

**WP-CLI tab**: Always visible. Shows the STDIO transport commands from the `wordpress/mcp-adapter` package.

The `wordpress/mcp-adapter` package registers its own `wp mcp-adapter` command group:

| Command | Description |
|---------|-------------|
| `wp mcp-adapter list` | List all registered MCP servers (ID, name, version, tool/resource/prompt counts) |
| `wp mcp-adapter serve --server={slug} --user={login\|id\|email}` | Start server via STDIO; blocks until the MCP client disconnects |

The STDIO transport lets MCP clients (Claude Desktop, Cursor, etc.) spawn `wp` as a subprocess instead of connecting over HTTP. The tab shows:
- `wp mcp-adapter list` command
- `wp mcp-adapter serve --server={slug} --user=admin` command
- A ready-to-paste JSON config block using `command: "wp"` with `--path={ABSPATH}`

STDIO config structure:
```json
{
  "command": "wp",
  "args": ["mcp-adapter", "serve", "--server={slug}", "--user=admin", "--path=/abs/path/to/wordpress"]
}
```

The `--path` flag points to `ABSPATH` on the current server. Users must adjust it if the `wp` binary cannot locate WordPress automatically.

**STDIO vs HTTP**:
- STDIO — wp spawned as subprocess; best for local development; no network exposure
- HTTP — REST endpoint over network; best for remote/shared servers; uses `npx @automattic/mcp-wordpress-remote`

Command textareas in the npm and WP-CLI tabs use the `mcp-cmd` CSS class for compact single-line display (`resize: none`, `white-space: nowrap`, horizontal scroll).

### Settings page — `?page=acrossai_mcp_manager_settings`

Registered via WP Settings API. Options group: `acrossai_mcp_manager_settings`.

| Option key                       | Type    | Default | Description                                   |
|----------------------------------|---------|---------|-----------------------------------------------|
| `acrossai_mcp_npm_login_enabled` | boolean | `false` | Enables the CLI auth flow and npm tab content |

---

## Server key format

The JSON config key written into MCP client config files follows the format:

```
{sitename}-{serverslug}
```

- `sitename` = `sanitize_title( get_bloginfo('name') )` — e.g. `wordpress`
- `serverslug` = `sanitize_title( $server['server_name'] )` — e.g. `default-mcp-server`
- Combined: `wordpress-default-mcp-server`

This format is produced by:
- `ApplicationPasswords::build_server_key()` — used in admin client tabs
- `Settings::render_wpcli_tab()` — used in WP-CLI tab display
- The `@acrossai/mcp-manager` npm CLI (using `siteSlug` from `/health` + server `id` from `/servers`)

**Never change this format** without updating all three locations above simultaneously, and bumping the npm CLI version.

---

## Frontend auth page

**Class**: `ACROSSAI_MCP_MANAGER\Frontend\FrontendAuth`
**URL slug**: `acrossai-mcp-manager` (constant `FrontendAuth::PAGE_SLUG`)
**Query var**: `acrossai_mcp_auth` (constant `FrontendAuth::QUERY_VAR`)
**Base URL helper**: `FrontendAuth::get_base_url()` → `home_url('/acrossai-mcp-manager/')`

### Rewrite rule

```
^acrossai-mcp-manager/?$  →  index.php?acrossai_mcp_auth=1
```

Registered on `init`. The activation hook also registers + flushes it immediately.
**To activate after plugin is already active**: go to Settings → Permalinks → Save Changes.

### Request lifecycle

1. `template_redirect` fires; skips if `acrossai_mcp_auth` query var is absent.
2. `nocache_headers()` sent unconditionally — this page must never be cached.
3. Unauthenticated users: redirect to `wp_login_url()` with a return URL.
4. **Any logged-in user** may proceed — no `manage_options` check. Each user approves
   access for their own account; the resulting Application Password belongs to them.
5. Dispatch by `?action=`:

| `action`            | `acrossai_mcp_npm_login_enabled` | Result                                                      |
|---------------------|----------------------------------|-------------------------------------------------------------|
| `cli_auth`          | `true`                           | Render approval/consent form                                |
| `cli_auth`          | `false`                          | Render "feature disabled" notice                            |
| `cli_auth_approve`  | `true`                           | Validate nonce, approve code, redirect to `cli_auth_approved` |
| `cli_auth_approve`  | `false`                          | `wp_die()` 403                                              |
| `cli_auth_approved` | any                              | Render confirmation page                                    |

The page renders a **standalone HTML shell** (no theme loaded) with minimal inline CSS — immune to theme breakage.

---

## REST API endpoints

Namespace: `acrossai-mcp-manager/v1`
Class: `ACROSSAI_MCP_MANAGER\REST\CliController`

| Method | Path             | Auth          | Purpose                                                  |
|--------|------------------|---------------|----------------------------------------------------------|
| GET    | `/health`        | None          | Plugin active check + version + `site_slug`              |
| POST   | `/auth/start`    | None          | Begin auth session → `auth_code`, `auth_url`, `expires_in` |
| GET    | `/auth/status`   | None          | Poll: `?code=&server=` → `{ approved, token }`           |
| GET    | `/servers`       | Bearer token  | List all servers with `mcp_url` per entry                |
| POST   | `/auth/exchange` | None          | Trade approved code → `app_password`, `username`         |

### `/health` response shape

```json
{
  "plugin_installed": true,
  "plugin_active":    true,
  "version":          "1.2.0",
  "site_slug":        "wordpress"
}
```

`site_slug` = `sanitize_title( get_bloginfo('name') )`. The CLI uses this to build the server config key.

### `/servers` response shape

```json
{
  "servers": [
    {
      "id":          "default-mcp-server",
      "name":        "Default MCP Server",
      "description": "...",
      "enabled":     true,
      "mcp_url":     "https://example.com/wp-json/mcp/mcp-adapter-default-server"
    }
  ]
}
```

`id` = `sanitize_title( $row['server_name'] )`. The CLI uses `mcp_url` directly as `WP_API_URL` in the generated config entry.

### `auth_url` format

`POST /auth/start` returns an `auth_url` pointing to the **frontend** page, not the WP admin:

```
{home_url}/acrossai-mcp-manager/?action=cli_auth&code={auth_code}&server={server_id}
```

Constructed via `FrontendAuth::get_base_url()`. **Never** change this to `admin_url('admin.php')`.

### Auth transient keys

| Transient prefix             | TTL   | Contains                                                        |
|------------------------------|-------|-----------------------------------------------------------------|
| `acrossai_cli_auth_{code}`   | 300 s | `{ server_id, status, user_id, session_token, created_at }`    |
| `acrossai_session_{token}`   | 600 s | `user_id` (int)                                                 |

Auth codes are **single-use**: both transients are deleted in `auth_exchange` on success.

---

## Database table

Table: `{prefix}acrossai_mcp_servers`
Class: `ACROSSAI_MCP_MANAGER\Database\MCPServerTable`
Current schema version: `1.5.0` (option: `acrossai_mcp_manager_db_version`)

| Column                  | Type          | Notes                                                             |
|-------------------------|---------------|-------------------------------------------------------------------|
| `id`                    | BIGINT PK AI  |                                                                   |
| `server_name`           | VARCHAR(255)  | Human-readable name                                               |
| `server_slug`           | VARCHAR(255)  | `sanitize_title(server_name)`; set at creation, never changes     |
| `description`           | VARCHAR(500)  | Optional                                                          |
| `is_enabled`            | TINYINT(1)    | 0 = inactive, 1 = active                                          |
| `registered_from`       | VARCHAR(50)   | `'plugin'` \| `'database'` \| `'theme'` \| `'core'`              |
| `server_route_namespace`| VARCHAR(100)  | REST namespace, default `'mcp'`                                   |
| `server_route`          | VARCHAR(255)  | REST route path, default slug                                     |
| `server_version`        | VARCHAR(50)   | MCP server version, default `'v1.0.0'`                            |
| `created_at`            | DATETIME      | UTC, default CURRENT_TIMESTAMP                                    |

**No `access_control` column** — access rules are stored in the library-owned `{prefix}wpb_access_control` table, keyed by `(server_route_namespace, server_route)`.

`maybe_create_table()` runs on every `plugins_loaded` and is a no-op unless the stored version differs. Seeds a default "Default MCP Server" row if the table is empty.

**v1.5.0 migration**: On upgrade from `1.4.0`, `migrate_legacy_rows()` detects the old `access_control` column via `information_schema.COLUMNS`, copies non-empty values to `AccessControlTable`, then `ALTER TABLE DROP COLUMN access_control`. `dbDelta` never drops columns — the explicit `ALTER` is required.

All read methods use the `acrossai_mcp` object-cache group. Write methods (`toggle_status`, `update_server`) flush affected cache keys.

---

## Access Control

### Overview

Per-server access control restricts which WordPress users may call a server's MCP REST endpoint. Rules are stored in the `wpboilerplate/wpb-access-control` library's own DB table (`{prefix}wpb_access_control`), keyed by `(server_route_namespace, server_route)`. The plugin calls `AccessControlManager::user_has_access()` on every matching REST request.

### Package

`wpboilerplate/wpb-access-control` — `github.com/WPBoilerplate/wpb-access-control`

Installed at `vendor/wpboilerplate/wpb-access-control/src/`. Namespace: `WPBoilerplate\AccessControl`.

```
AbstractProvider          — abstract base; every provider extends this
WpRoleProvider            — built-in provider for WordPress user roles
WpUserProvider            — built-in provider for specific WP users (AJAX search)
AccessControlManager      — provider registry + user_has_access() decision engine
RuleQuery                 — owns {prefix}wpb_access_control; CRUD for access rules
```

The manager is instantiated in `Plugin::__construct()` with a single argument:
- A custom filter tag `'acrossai_mcp_access_control_providers'` — avoids collisions when
  multiple plugins use the same wpb-access-control library.

The library registers one hook automatically:
- `init` (priority 5) — loads provider instances via filter (or immediately if init already fired)

REST enforcement is the consuming plugin's responsibility. `Plugin::enforce_mcp_access_control()` hooks `rest_pre_dispatch` at priority 10, iterates enabled servers, and calls `user_has_access()` for matching routes.

### Provider contract (`AbstractProvider`)

| Method               | Required | Purpose                                                             |
|----------------------|----------|---------------------------------------------------------------------|
| `get_id(): string`   | Yes      | Unique machine-readable ID stored in DB (`'wp_role'`, etc.)        |
| `get_label(): string`| Yes      | Human-readable label shown in admin dropdown                        |
| `get_options(): array`| Yes     | Returns `[['id'=>'slug','label'=>'Name'], ...]` for checkboxes      |
| `user_has_access( $user_id, $selected_options ): bool` | Yes | Core access check |
| `is_available(): bool`| No      | Override to return `false` when a required plugin is inactive       |

### Registering a custom provider

```php
// Use the plugin-specific filter tag (not the library default 'wpb_access_control_providers').
add_filter( 'acrossai_mcp_access_control_providers', function( array $providers ) {
    $providers[] = new \My\Plugin\MyProvider();
    return $providers;
} );
```

The tag `'acrossai_mcp_access_control_providers'` is passed as the second constructor
argument to `AccessControlManager` in `Plugin::__construct()`. Do not change it without
updating both places.

The filter fires on `init` at priority 5. Providers added after that point are ignored.

### Access decision hierarchy

1. If `RuleQuery::get_rule(namespace, route)` returns `key = ''` or `key = 'everyone'` → **allow**.
2. If the requesting user has `manage_options` capability (administrator) → **allow**.
3. If the user is not authenticated → **deny** with HTTP 401.
4. If no provider is registered for the configured `key` → **deny** with HTTP 403.
5. Call `provider->user_has_access( $user_id, $options )` → allow or **deny** with HTTP 403.

### Storage format

Rules are stored in `{prefix}wpb_access_control` as multiple rows (one per option value) with `namespace = server_route_namespace` and `key = server_route`.

`RuleQuery::get_rule( $ns, $key )` returns:
```php
['key' => 'wp_role', 'value' => ['editor', 'author']]
// or when no rule is set:
['key' => '', 'value' => []]
```

`RuleQuery::set_rule( $ns, $key, $ac_key, $ac_options )` writes the rule (sanitized internally).

- `key` (a.k.a. `access_control_key`) — provider ID. `'everyone'` or `''` means no restriction.
- `value` (a.k.a. `access_control_value`) — array of option IDs from `provider->get_options()`.
- Empty string `''` is the default; the manager treats it as `type = 'everyone'`.

Always write through `RuleQuery::set_rule()`. Never write raw `$wpdb` — BerlinDB manages its own object cache.

### Admin UI

**Tab slug**: `access-control` — visible on all server types (plugin and database).

- Assets (CSS + JS) are loaded from the **vendor package's own** `assets/` folder via `AccessControlUI::set_assets_url( plugins_url('vendor/wpboilerplate/wpb-access-control/assets', PLUGIN_FILE) )`.
- `AccessControlUI::bootstrap()` is called once in `Plugin::__construct()` to register the AJAX handlers.
- `AccessControlUI::enqueue_assets()` is called from `Settings::enqueue_assets()` to load styles + scripts.
- Dropdown (`ac_type`) populated from `AccessControlManager::get_providers()`.
- Per-provider fieldset with checkboxes shown/hidden by inline JS as the dropdown changes.
- **Saves via AJAX** to `admin-ajax.php` action `wpb_access_control_save` (no page reload).
- The `wpb_access_control_can_save` filter defaults to `true` — no additional authorization needed for this plugin.

### Firing the `acrossai_mcp_access_denied` action

When a MCP REST request is denied, `Plugin::enforce_mcp_access_control()` fires:

```php
do_action( 'acrossai_mcp_access_denied', $user_id, $server_row, $ns, $server_route );
```

The library also fires `wpb_access_control_denied` (its own action) on every denial from `user_has_access()`. Third-party code can hook either for logging or notifications.

### Future providers

To add support for a new back-end (membership plugin, BuddyBoss profile type, etc.):
1. Create `src/AccessControl/YourProvider.php` extending `AbstractProvider`.
2. Implement all abstract methods. Override `is_available()` to check for the required plugin.
3. Register via `acrossai_mcp_access_control_providers` filter.
4. No changes to the DB schema, manager, or UI are required — the new provider is automatically shown in the admin dropdown when it is available.

---

## ApplicationPasswords (admin REST endpoints)

Namespace: `acrossai-mcp-manager/v1`
Class: `ACROSSAI_MCP_MANAGER\Admin\ApplicationPasswords`

| Method | Path                          | Auth         | Purpose                                        |
|--------|-------------------------------|--------------|------------------------------------------------|
| POST   | `/generate-app-password`      | Admin only   | Create a WP Application Password for a client |
| GET    | `/get-client-config/{client}` | Admin only   | Return full JSON config for a client           |
| GET    | `/list-app-passwords`         | Admin only   | List passwords created by this plugin          |

`build_server_key( $server_id )` — private method, produces `{sitename}-{serverslug}`. Used by `get_client_config()` to set the key inside the returned JSON.

Registered clients: `openai`, `claude`, `vscode`, `codex`, `cursor`, `custom`.

---

## Key invariants for agents

- **`FrontendAuth::get_base_url()`** is the single source of truth for the CLI auth URL. Both `CliController::auth_start()` and any link pointing to the auth page must use this method.
- **`auth_url` must point to the frontend**, not `admin_url('admin.php')`. The CLI opens this in a browser; admin redirects break public/non-admin WordPress installs.
- **`nocache_headers()` must fire before any output** on the frontend auth page. Caching an auth code or nonce breaks the flow silently.
- **Rewrite rules must be flushed** after changing `FrontendAuth::PAGE_SLUG` or `FrontendAuth::QUERY_VAR`. The activation hook does this automatically; manual changes require Settings → Permalinks save.
- **`acrossai_mcp_npm_login_enabled` defaults to `false`**. The npm tab and entire frontend auth page show a disabled state until an admin explicitly turns it on.
- **`Settings.php` has no CLI auth logic**. All CLI auth rendering and processing lives exclusively in `FrontendAuth.php`. Do not re-add it.
- **WP Application Passwords contain spaces** (e.g. `"xxxx xxxx xxxx xxxx xxxx xxxx"`). Never trim or sanitise the password value anywhere.
- **`CliController::approve_auth_code()`** is a static helper called by `FrontendAuth::handle_approve()`. It must remain static and side-effect-free beyond transient writes.
- **Server key format** (`{sitename}-{serverslug}`) must stay in sync across `ApplicationPasswords`, `Settings::render_wpcli_tab()`, and the npm CLI. Changing it in one place without updating the others will produce mismatched config keys.
- **Auth codes are single-use**. `auth_exchange` deletes both the auth-code and session-token transients on success. Do not attempt a second exchange with the same code.
- **`mcp_url` in `/servers` response** is what the CLI uses as `WP_API_URL`. It must always be a full REST URL pointing to `mcp/mcp-adapter-default-server`. Do not change it to the site base URL.
- **Access control defaults to "everyone"**. A missing or empty rule in `RuleQuery` is treated as `key='everyone'` by `AccessControlManager`. Pre-existing servers are never accidentally locked out.
- **Administrators always bypass access control**. The `manage_options` capability check in `AccessControlManager::user_has_access()` is unconditional and must not be removed.
- **Access control providers are loaded on `init` priority 5** (or immediately if init already fired). Providers registered after that point will be silently ignored. Third-party code must hook at priority 4 or earlier. The filter tag is `'acrossai_mcp_access_control_providers'` — NOT the library default `'wpb_access_control_providers'`.
- **Access rules are stored in the library table** (`{prefix}wpb_access_control`), not `wp_acrossai_mcp_servers`. Use `RuleQuery::set_rule( $ns, $route, $key, $options )` to write and `RuleQuery::get_rule( $ns, $route )` to read. Never write the `access_control` column directly on the server table — it was removed in v1.5.0.
- **Access control assets come from the vendor package** at `vendor/wpboilerplate/wpb-access-control/assets/`. The plugin sets this path via `AccessControlUI::set_assets_url()`. Do not create or maintain plugin-bundled copies of these assets.
- **REST enforcement lives in `Plugin::enforce_mcp_access_control()`**, not the library. The library never adds `rest_pre_dispatch` hooks. Do not add REST enforcement to the library.
