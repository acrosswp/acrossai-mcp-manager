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
| Current version | `1.4.0`                                       |
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
- A WP-CLI command (`wp acrossai-mcp setup`) for server-side credential generation.
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
                               Also registers the WP-CLI command when WP_CLI is defined.

  Admin/
    Settings.php               Admin menu + submenu, WP Settings API, server list/edit pages.
                               Tab renderer for: overview, npm, wp-cli, and all client tabs.
    SettingsRenderer.php       Reserved utility class (intentionally empty for now).
    MCPServerListTable.php     WP_List_Table subclass for the server list view.
    ApplicationPasswords.php   Manages WP Application Passwords for MCP clients.
                               Exposes REST endpoints for admin JS to call.
                               Builds the dynamic server key: {sitename}-{serverslug}.

  CLI/
    SetupCommand.php           WP-CLI command class: `wp acrossai-mcp setup`.
                               Generates Application Passwords and writes/displays
                               MCP client config files directly on the server.

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

  AccessControl/
    AbstractProvider.php       Abstract base every provider must extend.
                               Defines: get_id(), get_label(), get_options(), user_has_access(), is_available().
    WpRoleProvider.php         Built-in provider: restricts by WordPress user role.
                               get_options() returns all editable roles except Administrator.
                               user_has_access() checks $user->roles against $selected_options.
    AccessControlManager.php   Registry + REST enforcer.
                               Loads providers via `acrossai_mcp_access_control_providers` filter.
                               Hooks rest_pre_dispatch at priority 10 to gate MCP routes.
                               Access logic: admin always passes → everyone type passes → provider check.

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
| `wp-cli`         | WP-CLI setup commands with copy buttons                                          |
| `tools`          | Read-only list of 3 built-in MCP tools                                           |
| `abilities`      | Read-only list of WordPress abilities (MCP-public + private)                     |
| `access-control` | Per-server access control rules (all server types)                               |
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

**WP-CLI tab**: Always visible. Shows three commands with copy buttons:
1. Print config only: `wp acrossai-mcp setup --server={slug}`
2. Write config files: `wp acrossai-mcp setup --server={slug} --write`
3. With specific user: `wp acrossai-mcp setup --server={slug} --write --user=admin`

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
- `SetupCommand::setup()` — used in the WP-CLI command
- The `@acrossai/mcp-manager` npm CLI (using `siteSlug` from `/health` + server `id` from `/servers`)

**Never change this format** without updating all four locations above simultaneously, and bumping the npm CLI version.

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
4. Non-admin users: `wp_die()` with 403.
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

## WP-CLI command

**Registered in**: `Plugin::__construct()` — only when `defined('WP_CLI') && WP_CLI`.
**Class**: `ACROSSAI_MCP_MANAGER\CLI\SetupCommand`
**Command**: `wp acrossai-mcp setup`

### Options

| Flag              | Type    | Required | Description                                            |
|-------------------|---------|----------|--------------------------------------------------------|
| `--server=<slug>` | string  | No       | Server slug. Prompted interactively if omitted.        |
| `--write`         | flag    | No       | Write config directly to client config files.          |
| `--format=<fmt>`  | string  | No       | `json` (default) or `table`.                           |

**`--user` is a WP-CLI global flag** — it is not declared in the command's own `@option` list. Pass it as `wp acrossai-mcp setup --user=admin`. WP-CLI sets the current user context before the command runs, so `wp_get_current_user()` inside the command returns the correct user. The command errors if no user context is available.

### What it does

1. Resolves current WP user via `wp_get_current_user()`.
2. Loads all servers from `MCPServerTable::get_all()`. With one server it auto-selects; with multiple it prompts interactively.
3. Generates a WP Application Password via `WP_Application_Passwords::create_new_application_password()`.
4. Displays the server key, MCP URL, username, and raw password.
5. Prints JSON config blocks for: Claude Desktop, Cursor, VS Code, Claude Code.
6. With `--write`: backs up each client's existing config file (`.bak.<timestamp>`), then merges the new server entry.

### Supported clients (write targets)

| ID               | Config file                                                          | JSON key structure              |
|------------------|----------------------------------------------------------------------|---------------------------------|
| `claude-desktop` | `~/Library/Application Support/Claude/claude_desktop_config.json`   | `{ mcpServers: { ... } }`       |
| `cursor`         | `~/.cursor/mcp.json`                                                 | `{ mcpServers: { ... } }`       |
| `vscode`         | `~/Library/Application Support/Code/User/settings.json`             | `{ mcp: { servers: { ... } } }` |
| `claude-code`    | `~/.claude.json`                                                     | `{ mcpServers: { ... } }`       |

A client is skipped (with a log message) if its config directory does not exist on the machine.

---

## Database table

Table: `{prefix}acrossai_mcp_servers`
Class: `ACROSSAI_MCP_MANAGER\Database\MCPServerTable`
Current schema version: `1.4.0` (option: `acrossai_mcp_manager_db_version`)

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
| `access_control`        | TEXT          | JSON: `{"type":"wp_role","options":["editor"]}`; `''` = everyone  |
| `created_at`            | DATETIME      | UTC, default CURRENT_TIMESTAMP                                    |

`maybe_create_table()` runs on every `plugins_loaded` and is a no-op unless the stored version differs. Seeds a default "Default MCP Server" row if the table is empty.

`sanitize_access_control( $raw )` — static helper that validates and re-encodes the JSON before any write. Resets to `''` on invalid input.

All read methods use the `acrossai_mcp` object-cache group. Write methods (`toggle_status`, `update_server`) flush affected cache keys.

---

## Access Control

### Overview

Per-server access control restricts which WordPress users may call a server's MCP REST endpoint. The feature is implemented in the `ACROSSAI_MCP_MANAGER\AccessControl` namespace and is entirely independent of the BuddyBoss Platform Pro plugin (which was used as a reference for the architecture only).

### Architecture

```
AbstractProvider          — abstract base; every provider extends this
WpRoleProvider            — built-in provider for WordPress user roles
AccessControlManager      — registry + REST enforcer
```

The manager is instantiated in `Plugin::__construct()` and registers two hooks:
- `init` (priority 5) — loads provider instances via filter
- `rest_pre_dispatch` (priority 10) — enforces access on every MCP REST request

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
add_filter( 'acrossai_mcp_access_control_providers', function( $providers ) {
    $providers[] = new \My\Plugin\MyProvider();
    return $providers;
} );
```

The filter fires on `init` at priority 5. Providers added after that point are ignored.

### Access decision hierarchy

1. If the server's `access_control` column is empty or `type = 'everyone'` → **allow**.
2. If the requesting user has `manage_options` capability (administrator) → **allow**.
3. If the user is not authenticated → **deny** with HTTP 401.
4. If no provider is registered for the configured `type` → **deny** with HTTP 403.
5. Call `provider->user_has_access( $user_id, $options )` → allow or **deny** with HTTP 403.

### Storage format (`access_control` column)

```json
{ "type": "wp_role", "options": ["editor", "author"] }
```

- `type` — provider ID. `'everyone'` or `''` means no restriction.
- `options` — array of option IDs from `provider->get_options()`.
- Empty string `''` is the default; the manager treats it as `type = 'everyone'`.

Always write through `MCPServerTable::sanitize_access_control( $raw )` before storing.

### Admin UI

**Tab slug**: `access-control` — visible on all server types (plugin and database).

- Dropdown (`ac_type`) populated from `AccessControlManager::get_providers()`.
- Per-provider fieldset with checkboxes shown/hidden by inline JS as the dropdown changes.
- Submits to `?action=save_access_control&server={id}` (POST, nonce: `acrossai_mcp_access_control_{id}`).
- On success, redirects back to the `access-control` tab with `?updated=1`.

### Firing the `acrossai_mcp_access_denied` action

When a request is denied, the manager fires:

```php
do_action( 'acrossai_mcp_access_denied', $current_user_id, $server_row, $ac_config );
```

Third-party code can hook this for logging or notifications.

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
- **Server key format** (`{sitename}-{serverslug}`) must stay in sync across `ApplicationPasswords`, `Settings`, `SetupCommand`, and the npm CLI. Changing it in one place without updating the others will produce mismatched config keys.
- **`--user` is a WP-CLI global flag**. Do not declare it as a custom option in `SetupCommand`. Do not attempt to read it from `$assoc_args`; use `wp_get_current_user()` instead.
- **Auth codes are single-use**. `auth_exchange` deletes both the auth-code and session-token transients on success. Do not attempt a second exchange with the same code.
- **`mcp_url` in `/servers` response** is what the CLI uses as `WP_API_URL`. It must always be a full REST URL pointing to `mcp/mcp-adapter-default-server`. Do not change it to the site base URL.
- **Access control defaults to "everyone"**. A missing or empty `access_control` column value is treated as `type='everyone'` by `AccessControlManager::parse_access_control()`. Pre-existing rows are never accidentally locked out.
- **Administrators always bypass access control**. The `manage_options` capability check in `AccessControlManager::enforce_access()` is unconditional and must not be removed.
- **Access control providers are loaded on `init` priority 5**. Providers registered after that action will be silently ignored. Third-party code must hook at priority 4 or earlier.
- **`sanitize_access_control()` must be called before every DB write**. `update_server()` already does this automatically when `access_control` is in the `$data` array. Do not bypass it.
- **The `access_control` column is TEXT, not VARCHAR**. Never add a length constraint to it — the options array can theoretically be long for future providers.
