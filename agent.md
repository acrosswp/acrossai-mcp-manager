# MCP Manager — AI Agent Reference

> This file is intended for AI agents, code assistants, and automated tools. It provides a complete, structured reference for the MCP Manager WordPress plugin — its architecture, features, files, APIs, hooks, and data model.

---

## 1. Plugin Identity

| Field | Value |
|-------|-------|
| Plugin Name | MCP Manager |
| Slug | mcp-manager |
| Version | 1.0.0 |
| Author | raftaar1191 |
| License | GPL-2.0-or-later |
| Text Domain | mcp-manager |
| Requires PHP | 7.4+ |
| Requires WP | 5.9+ |
| Tested Up To | 7.0 |
| Plugin URI | https://wordpress.org/plugins/mcp-manager/ |

**Purpose:** Enables seamless integration between a WordPress site and Model Context Protocol (MCP) clients (VS Code Copilot, Claude Desktop, GitHub Codex, ChatGPT, custom clients) by generating secure Application Passwords and producing ready-to-paste JSON configurations.

---

## 2. Directory Structure

```
acrossai-mcp-manager/
├── mcp-manager.php                   # Entry point — constants, activation/deactivation hooks, bootstrap
├── composer.json                     # Composer config (dependencies, autoload)
├── composer.lock                     # Locked dependency versions
├── README.md                         # Human-readable feature documentation
├── readme.txt                        # WordPress.org plugin readme
├── LICENSE / LICENSE.txt             # GPL-2.0-or-later
├── agent.md                          # THIS FILE — AI agent reference
│
├── assets/
│   ├── admin.css                     # Admin UI styles (tabs, config boxes, responsive)
│   └── admin.js                      # Admin UI JavaScript (tab nav, clipboard, REST calls)
│
├── languages/                        # Translation directory (empty — no .po/.mo yet)
│
├── src/
│   ├── Core/
│   │   └── Plugin.php                # Singleton plugin coordinator
│   ├── Admin/
│   │   ├── Settings.php              # Admin menu, Settings API, asset enqueueing, page rendering
│   │   ├── SettingsRenderer.php      # Utility — generates LLM/MCP JSON configuration blocks
│   │   └── ApplicationPasswords.php  # Application password CRUD + REST API endpoints
│   └── MCP/
│       └── Controller.php            # MCP adapter lifecycle (enable/disable/status)
│
└── vendor/                           # Composer packages (do not edit)
    ├── autoload.php
    ├── autoload_packages.php         # Jetpack autoloader entry point
    ├── automattic/jetpack-autoloader/
    └── wordpress/mcp-adapter/        # MCP Adapter package v0.4.1
```

---

## 3. PHP Namespace Map

All plugin classes live under the `ACROSSAI_MCP_MANAGER\` root namespace.

| Class (FQCN) | File | Responsibility |
|---|---|---|
| `ACROSSAI_MCP_MANAGER\Core\Plugin` | `src/Core/Plugin.php` | Singleton; owns Settings & Controller instances |
| `ACROSSAI_MCP_MANAGER\Admin\Settings` | `src/Admin/Settings.php` | Admin menu, Settings API, page render, asset enqueue |
| `ACROSSAI_MCP_MANAGER\Admin\SettingsRenderer` | `src/Admin/SettingsRenderer.php` | JSON config block generator |
| `ACROSSAI_MCP_MANAGER\Admin\ApplicationPasswords` | `src/Admin/ApplicationPasswords.php` | App password generation, REST endpoints, client config |
| `ACROSSAI_MCP_MANAGER\MCP\Controller` | `src/MCP/Controller.php` | MCP adapter init, status tracking |

---

## 4. Constants

Defined in `mcp-manager.php`:

| Constant | Value |
|---|---|
| `ACROSSAI_MCP_MANAGER_VERSION` | `'1.0.0'` |
| `ACROSSAI_MCP_MANAGER_FILE` | Absolute path to `mcp-manager.php` |
| `ACROSSAI_MCP_MANAGER_DIR` | Absolute directory path (trailing slash) |
| `ACROSSAI_MCP_MANAGER_URL` | Plugin URL (trailing slash) |

---

## 5. Bootstrap & Initialization Flow

```
plugins_loaded (priority 10)
  └── ACROSSAI_MCP_MANAGER\Core\Plugin::instance()           # Singleton created
        ├── new Settings()
        │     ├── admin_menu        → register_menu()
        │     ├── admin_init        → register_settings()
        │     └── admin_enqueue_scripts → enqueue_assets()
        ├── new ApplicationPasswords()
        │     └── rest_api_init     → register_rest_routes()
        └── new Controller()
              └── init (priority 20) → initialize_adapter()
```

**Activation hook** (`mcp-manager.php`): placeholder — no DB writes on activation.  
**Deactivation hook** (`mcp-manager.php`): placeholder — no cleanup on deactivation.

---

## 6. Admin Interface

### 6.1 Menu Registration

- **Menu Type:** Top-level page (`add_menu_page`)
- **Menu Title / Page Title:** MCP Manager
- **Slug:** `mcp_manager`
- **Capability:** `manage_options`
- **Icon:** `dashicons-hammer`
- **Position:** `99`

### 6.2 Settings Page Tabs

| Tab ID | Label | Icon |
|---|---|---|
| `overview` | Overview | (none) |
| `vscode` | VS Code | 󰨞 |
| `claude` | Claude | 🤖 |
| `codex` | GitHub Codex | 🐙 |
| `chatgpt` | OpenAI ChatGPT Codex | 🧠 |
| `custom` | Custom Client | ⚙️ |

Tab selection is stored in `?tab=` query parameter and sanitized with `sanitize_key()`.

### 6.3 Overview Tab Content
- Enable/Disable toggle for MCP Adapter (`acrossai_mcp_manager_enabled`)
- List of supported MCP clients with icons
- Link to current user profile (for managing Application Passwords)

### 6.4 Client Tab Content
Each client tab renders:
1. Client description
2. "Generate New Application Password" button
3. Password display field (toggleable visibility)
4. JSON configuration textarea (pre-filled after generation)
5. Copy-to-clipboard button
6. Config file path hint
7. Step-by-step setup instructions

---

## 7. WordPress Options / Settings

| Option Key | Type | Default | REST Exposed | Sanitizer |
|---|---|---|---|---|
| `acrossai_mcp_manager_enabled` | bool | `false` | `true` | `rest_sanitize_boolean` |

- **Settings Group:** `acrossai_mcp_manager_settings`
- **Settings Section:** `acrossai_mcp_manager_section`
- **Registered Field:** `acrossai_mcp_manager_enabled` (checkbox, renders via `Settings::render_enabled_field()`)

---

## 8. REST API Endpoints

Base namespace: `mcp-manager/v1`

### 8.1 POST `/mcp-manager/v1/generate-app-password`

**Purpose:** Generate a new WordPress Application Password for a given MCP client.

| Item | Detail |
|---|---|
| Method | POST |
| Capability | `manage_options` |
| Body Param | `client` (string, required) — one of: `vscode`, `claude`, `codex`, `chatgpt`, `custom` |
| Sanitizer | `sanitize_text_field` |

**Success Response (200):**
```json
{
  "success": true,
  "password": "xxxx xxxx xxxx xxxx xxxx xxxx",
  "username": "admin",
  "client": "claude",
  "app_id": "uuid-string",
  "message": "Application password generated successfully."
}
```

Password is shown **once only** — it is not retrievable after this response.

---

### 8.2 GET `/mcp-manager/v1/get-client-config/{client}`

**Purpose:** Retrieve the MCP JSON configuration for a given client.

| Item | Detail |
|---|---|
| Method | GET |
| Capability | `manage_options` |
| URL Param | `client` — regex `[a-z\-]+` |

**Success Response (200):**
```json
{
  "success": true,
  "client": "claude",
  "mcp_config": {
    "command": "npx",
    "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
    "env": {
      "WP_API_URL": "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server",
      "WP_API_USERNAME": "admin",
      "WP_API_PASSWORD": "(paste generated password here)"
    }
  },
  "full_config": {
    "mcpServers": {
      "mcp-wordpress": { "...": "same as mcp_config" }
    }
  },
  "username": "admin",
  "top_level_key": "mcpServers",
  "config_file_path": "~/Library/Application Support/Claude/claude_desktop_config.json"
}
```

---

## 9. Supported MCP Clients

| ID | Label | Top-Level JSON Key | Config File Path |
|---|---|---|---|
| `vscode` | Visual Studio Code | `servers` | `~/.config/Code/User/globalStorage/Copilot.copilot-chat/mcp.json` |
| `claude` | Claude | `mcpServers` | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| `codex` | GitHub Codex | `servers` | `~/.gh-copilot/config.json` |
| `chatgpt` | OpenAI ChatGPT Codex | `servers` | `~/.config/chatgpt/config.json` |
| `custom` | Custom Client | `mcpServers` | `./your-project/.mcp/config.json` |

**Server Name (always):** `mcp-wordpress`

---

## 10. Application Password Integration

- Uses WordPress native `WP_Application_Passwords` class (available WP 5.6+).
- Password name format: `"MCP Manager - {ClientLabel}"` (e.g. `"MCP Manager - Claude"`).
- Passwords are listed and revocable from **Users → Profile → Application Passwords**.
- Passwords are **scoped per client** — one password per client ID per user.
- Generation: `WP_Application_Passwords::create_new_application_password( $user_id, $data )`.

---

## 11. MCP Adapter Controller

**File:** `src/MCP/Controller.php`

**Status values:**

| Status | Meaning |
|---|---|
| `'running'` | `\WP\MCP\Plugin::instance()` initialized successfully |
| `'disabled'` | `acrossai_mcp_manager_enabled` option is `false` |
| `'not-found'` | `\WP\MCP\Plugin` class does not exist (adapter not installed) |
| `'error'` | Exception thrown during initialization |
| `'unknown'` | `initialize_adapter()` not yet called |

**Status flow:**
```
init (priority 20) → initialize_adapter()
  ├─ acrossai_mcp_manager_enabled == false  → status = 'disabled'
  ├─ class \WP\MCP\Plugin not found → status = 'not-found'
  │                                    + admin_notices warning displayed
  ├─ \WP\MCP\Plugin::instance() OK  → status = 'running'
  └─ Exception thrown               → status = 'error'
                                       + fires acrossai_mcp_manager_adapter_init_error action (WP_DEBUG only)
```

---

## 12. Hooks Reference

### Actions (plugin registers)

| Hook | Priority | Callback | Description |
|---|---|---|---|
| `plugins_loaded` | 10 | `Plugin::instance()` | Bootstrap plugin |
| `admin_menu` | default | `Settings::register_menu()` | Add admin menu page |
| `admin_init` | default | `Settings::register_settings()` | Register Settings API |
| `admin_enqueue_scripts` | default | `Settings::enqueue_assets()` | Enqueue CSS/JS on plugin page |
| `admin_notices` | default | `Controller::admin_notice()` | Show missing adapter warning |
| `rest_api_init` | default | `ApplicationPasswords::register_rest_routes()` | Register REST endpoints |
| `init` | 20 | `Controller::initialize_adapter()` | Init MCP adapter |

### Custom Actions (plugin fires)

| Action | When Fired | Args |
|---|---|---|
| `acrossai_mcp_manager_adapter_init_error` | Adapter init exception (WP_DEBUG only) | `$exception` |

---

## 13. Frontend Assets

### admin.css (`assets/admin.css`)

- Tab navigation (`.nav-tab-wrapper`, `.nav-tab`, `.nav-tab-active`)
- Tab content panels with CSS fade-in animation
- Configuration JSON textarea blocks
- Password input flex layout
- Copy button states (hover, active)
- Info/status boxes (success, error, info variants with left-border accent)
- Responsive breakpoints at `max-width: 768px`

### admin.js (`assets/admin.js`)

Exposed as `MCPAdmin` (IIFE). Localized data at `acrossaiMcpManagerData`:

| Key | Type | Content |
|---|---|---|
| `acrossaiMcpManagerData.nonce` | string | `wp_create_nonce('wp_rest')` |
| `acrossaiMcpManagerData.rest_url` | string | REST API base URL |
| `acrossaiMcpManagerData.current_user` | object | Current user data |

**Key methods:**

| Method | Description |
|---|---|
| `init()` | Called on `DOMContentLoaded` |
| `setupEventListeners()` | Binds tab click handlers |
| `selectTab(tabId)` | Switches visible tab |
| `setupClipboardButtons()` | Wires copy-to-clipboard |
| `setupGeneratePassword()` | Wires generate-password button |
| `generatePassword(clientId)` | POST to REST API, stores in `sessionStorage` |
| `loadExistingPasswords()` | Checks if passwords already exist per client |
| `loadClientConfigurations()` | Loads all client JSON configs via REST |
| `loadClientConfiguration(clientId)` | GET from REST API for specific client |
| `updateConfig(clientId, password)` | Injects password into displayed config JSON |

**Session storage keys:**  
`acrossai_mcp_password_{clientId}` — stores generated passwords for the browser session only.

---

## 14. Security Model

| Concern | Implementation |
|---|---|
| Capability check | `current_user_can('manage_options')` on all admin pages and REST endpoints |
| Input sanitization | `sanitize_text_field()`, `sanitize_key()`, `rest_sanitize_boolean()` |
| Output escaping | `esc_html()`, `esc_attr()`, `esc_textarea()`, `esc_url()`, `wp_kses_post()` |
| CSRF (admin forms) | WordPress Settings API handles nonces automatically |
| CSRF (REST calls) | `wp_create_nonce('wp_rest')` passed via `acrossaiMcpManagerData.nonce`; sent as `X-WP-Nonce` header |
| Password storage | Never stored in DB by plugin — only WordPress core `WP_Application_Passwords` table |
| Password display | Shown once in browser session (`sessionStorage`); never re-exposed by server |

---

## 15. Composer Dependencies

| Package | Version | Purpose |
|---|---|---|
| `automattic/jetpack-autoloader` | ^2.0 | PSR-4 autoloading; prevents dependency conflicts across plugins |
| `wordpress/mcp-adapter` | >=0.4.1 | MCP protocol server implementation for WordPress |

**Autoload entry point:** `vendor/autoload_packages.php` (Jetpack style, included in `mcp-manager.php`).

---

## 16. MCP Adapter Package (`wordpress/mcp-adapter` v0.4.1)

Main class: `\WP\MCP\Plugin` (singleton)

**Internal components:**
- Transport layer (HTTP Infrastructure)
- MCP Server & Component Registry
- Core Adapter & Transport Factory
- CLI support (`StdioServerBridge`)
- Abilities API (`Discover`, `GetInfo`, `Execute`)
- Error handling & Observability
- Domain Tools with MCP Tool validation

The plugin calls `\WP\MCP\Plugin::instance()` to initialize the adapter. The adapter registers its own REST route and handles inbound MCP protocol requests.

**MCP API URL pattern:** `{site_url}/wp-json/mcp/mcp-adapter-default-server`

---

## 17. Localization

- **Text domain:** `mcp-manager`
- **Domain path:** `/languages`
- All user-facing strings wrapped in `__()`, `esc_html__()`, `esc_html_e()`
- No translation files yet — PRs welcome

---

## 18. User Setup Workflow (End-to-End)

1. Install and activate the plugin.
2. Go to **WordPress Admin → MCP Manager**.
3. On the **Overview** tab, check **Enable MCP Adapter integration** and save.
4. Click the tab for your MCP client (e.g. **Claude**).
5. Click **Generate New Application Password** — a one-time password is shown.
6. Copy the displayed JSON configuration block.
7. Paste it into the client's config file (path shown in plugin UI).
8. Paste the generated password into the `WP_API_PASSWORD` env var field in the config.
9. Restart the MCP client.
10. The client can now interact with WordPress via the MCP protocol.

---

## 19. Known Limitations / Edge Cases

- Application Passwords require **HTTPS** on production (WordPress enforces this).
- The MCP adapter (`wordpress/mcp-adapter`) must be bundled via Composer — it is not a separate plugin.
- Passwords are displayed **once**. If lost, delete and regenerate via the plugin or via **Users → Profile**.
- `acrossai_mcp_manager_adapter_init_error` custom action fires **only when `WP_DEBUG` is true**.
- Languages directory is empty — the plugin is not yet translated.
- No uninstall hook — `acrossai_mcp_manager_enabled` option persists after plugin deletion unless manually removed.

---

## 20. Extension Points for Developers

| Point | How |
|---|---|
| React to adapter init error | Hook `acrossai_mcp_manager_adapter_init_error` action (passes `$exception`) |
| Add more REST endpoints | Hook `rest_api_init` and call `register_rest_route('mcp-manager/v1', ...)` |
| Add more settings fields | Hook `admin_init`, add section/field to `acrossai_mcp_manager_settings` group |
| Add more MCP clients | Extend `ApplicationPasswords::get_clients()` array |
| Override config generation | Extend or override `SettingsRenderer::generate_config()` |

---

## 21. File Line Counts (approximate)

| File | Lines |
|---|---|
| `mcp-manager.php` | ~85 |
| `src/Core/Plugin.php` | ~95 |
| `src/Admin/Settings.php` | ~372 |
| `src/Admin/SettingsRenderer.php` | ~56 |
| `src/Admin/ApplicationPasswords.php` | ~303 |
| `src/MCP/Controller.php` | ~116 |
| `assets/admin.css` | ~294 |
| `assets/admin.js` | ~300 |

---

*Last updated: 2026-04-21. Generated by AI agent from full codebase analysis.*
