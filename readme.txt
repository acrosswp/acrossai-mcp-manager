=== AcrossAI MCP Manager ===
Contributors: raftaar1191
Tags: mcp, ai, copilot, vscode, claude
Requires at least: 6.9
Requires PHP: 8.0
Tested up to: 7.0
Stable tag: 0.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to MCP clients like VS Code, Claude, and Copilot using secure application passwords.

== Description ==

MCP Manager uses the standard `@automattic/mcp-wordpress-remote@latest` package with WordPress Application Passwords for the default remote flow. It also includes an optional experimental direct Claude Connectors mode backed by a WordPress-hosted OAuth approval flow.

MCP Manager is a WordPress plugin that enables seamless integration with Model Context Protocol (MCP) servers, allowing AI assistants and code editors to safely access your WordPress instance through secure application passwords.

= Key Features =

* **Multi-Client Support**: Configure MCP for:
  - VS Code with Copilot
  - Claude Desktop App
  - GitHub Copilot & Codex
  - OpenAI ChatGPT Codex
  - Custom MCP Clients

* **Secure Authentication**: Uses WordPress native Application Passwords system
  - One-click password generation
  - Secure credential management
  - Password revocation support
  - Per-server Access Control still enforced after authentication

* **Easy Configuration**: 
  - Copy-paste ready JSON configurations
  - Per-provider configuration file paths
  - Automatic top-level key detection

* **Format #1 Standard**: Uses the Automattic-recommended MCP configuration format
  - npx command execution
  - @automattic/mcp-wordpress-remote@latest package
  - Full environment variable support

= How It Works =

1. Navigate to Settings → MCP Manager
2. Select your MCP client (VS Code, Claude, GitHub Copilot, ChatGPT, or Custom)
3. Click "Generate New Application Password"
4. Copy the ready-to-use JSON configuration
5. Paste into your client's configuration file
6. Restart your MCP client

All application passwords are managed through WordPress's native Application Passwords system and appear in your profile under Account Management.

= CLI Connection and Authorization Flow =

MCP Manager also supports a browser-assisted CLI connection flow for local MCP clients.

Typical command:

`npx -y @acrossai/mcp-manager --siteurl=https://example.com --server=default-mcp-server`

Flow summary:

1. The CLI checks `/wp-json/acrossai-mcp-manager/v1/health`
2. The CLI starts auth with `/wp-json/acrossai-mcp-manager/v1/auth/start`
3. WordPress returns an `auth_code` and frontend `auth_url`
4. The CLI opens the frontend approval page at `/acrossai-mcp-manager/`
5. If needed, the user signs in through normal WordPress login
6. The signed-in user approves access in the browser
7. The CLI polls `/auth/status` until the request is approved
8. The CLI fetches the approved user's accessible servers from `/servers`
9. The CLI exchanges the approved code at `/auth/exchange`
10. WordPress creates a one-time Application Password and the CLI writes the MCP client config

Terminology:

* **Sign in / Log in** = WordPress account authentication
* **Connect** = starting the CLI-to-site linking flow
* **Authorize / Approve access** = granting the CLI permission in the browser

Important notes:

* The frontend authorization page must never be cached
* Auth codes are single-use
* `/servers` and `/auth/exchange` respect per-server access control
* User-facing copy should say **CLI Connections** rather than **npm Login**
* Generated remote MCP configs use Application Passwords and explicitly disable OAuth discovery in `@automattic/mcp-wordpress-remote`

= Experimental Direct Claude Connectors =

An optional **Claude Connectors Screen (Experimental)** setting can enable a direct OAuth flow for Claude's hosted connectors.

When the global feature toggle is enabled and a specific server is configured in its **Claude Connector** tab, the plugin exposes:

* `/.well-known/oauth-authorization-server`
* `/.well-known/oauth-protected-resource?resource=<mcp-url>`
* `/acrossai-mcp-connectors/oauth/authorize/`
* `/wp-json/acrossai-mcp-manager/v1/connector/oauth/token`

Important notes:

* Disabled by default
* The Application Password flow remains available and supported
* The master experimental toggle is global, but OAuth client settings are stored per server
* Direct connector approval signs Claude in as a WordPress user
* Per-server Access Control still applies to every MCP request after OAuth
* Public HTTPS is recommended for hosted connector usage

= Provider Configuration Paths =

* **VS Code**: ~/.config/Code/User/globalStorage/Copilot.copilot-chat/mcp.json (top-level key: "servers")
* **Claude**: ~/Library/Application Support/Claude/claude_desktop_config.json (top-level key: "mcpServers")
* **GitHub Copilot**: ~/.gh-copilot/config.json (top-level key: "servers")
* **OpenAI ChatGPT**: ~/.config/chatgpt/config.json (top-level key: "servers")
* **Custom**: ./your-project/.mcp/config.json (top-level key: configurable)

= Requirements =

* WordPress 5.9 or higher
* PHP 8.0 or higher
* WordPress Application Passwords support (built-in since WP 5.6)

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Settings → MCP Manager to configure

Or:

1. Go to Admin → Plugins → Add New
2. Search for "MCP Manager"
3. Click "Install Now" then "Activate"

== Frequently Asked Questions ==

= Is my password secure? =

Yes! MCP Manager uses WordPress's native Application Passwords system. Each password is:
- Generated using WordPress's secure methods
- Associated with your user account
- Visible in your profile for management
- Revocable at any time

= Can I use this with multiple MCP clients? =

Yes! You can generate separate passwords for each client (VS Code, Claude, GitHub Copilot, ChatGPT, and any custom client).

= Where are my application passwords saved? =

All application passwords are managed through WordPress's native Application Passwords system. View and manage them at:
User Profile → Account Management → Application Passwords

= What MCP clients are supported? =

- Visual Studio Code (with Copilot)
- Anthropic Claude Desktop App
- GitHub Copilot
- OpenAI ChatGPT Codex
- Any custom MCP client supporting the standard format

= Can I revoke a password? =

Yes! You can revoke any application password from your profile page under Account Management → Application Passwords.

= Is this compatible with multisite? =

Yes! MCP Manager works with WordPress multisite installations. Each site can be configured independently.

= Do I need to install additional software? =

No additional software is needed on the WordPress side. Your MCP clients (VS Code extension, Claude app, etc.) handle the integration.

== Screenshots ==

1. Settings page with client tabs for easy configuration
2. Copy-paste ready JSON configuration
3. One-click password generation
4. Per-provider configuration file locations and top-level keys

== Changelog ==

= 0.0.2 =
* Security: sanitize and validate all $_GET/$_POST inputs with sanitize_key(), sanitize_text_field(), absint(), and wp_unslash()
* Paths: replace hardcoded ABSPATH with get_home_path() for correct subdirectory-install support
* Enqueue: remove all inline <style>/<script> blocks; move to external CSS/JS files loaded via wp_enqueue_style() and wp_enqueue_script()

= 0.0.1 =
* Initial release
* Support for VS Code, Claude, GitHub Copilot, ChatGPT Codex, and custom clients
* Format #1 (Automattic-recommended) MCP configuration
* Native WordPress Application Passwords integration
* Dynamic configuration generation per provider
* Full REST API support
* Admin UI with client tabs
* Copy-to-clipboard functionality

== Support & Contribution ==

For issues, feature requests, or contributions, visit the plugin repository.

Questions? Check the FAQ section or look for documentation in the plugin settings page.

== Development ==

This plugin follows WordPress coding standards and best practices:
- PHP 8.0+ compatible
- Full object-oriented architecture
- Secure nonce verification
- Proper capability checks
- Sanitized input validation
- Escaped output

== License ==

This plugin is licensed under the GPL-2.0-or-later license. See LICENSE file for details.

== Credits ==

MCP Manager is built with:
- WordPress native APIs
- Automattic's MCP WordPress Remote package
- WordPress Application Passwords system

Developed with ❤️ for the WordPress community.
