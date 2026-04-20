# MCP Manager

A production-ready WordPress plugin for enabling/disabling MCP (Model Context Protocol) Adapter integration.

## Features

- **Toggle-based MCP Integration** — Enable or disable MCP adapter from WordPress admin settings
- **Modern OOP Architecture** — Built with singleton pattern and proper namespacing
- **PSR-4 Autoloading** — Full Composer integration with Jetpack autoloader support
- **Settings API** — WordPress-standard settings management with proper sanitization
- **Admin UI** — Clean admin settings page with enable/disable checkbox
- **LLM Configuration** — Dynamically generates JSON configuration for VS Code/Copilot integration
- **Safety First** — Graceful handling of missing dependencies with admin notices
- **Output Escaping** — All HTML properly escaped using WordPress functions
- **PHPDoc Documentation** — Complete documentation on all public methods

## Installation

1. Plugin is already in: `/wp-content/plugins/mcp-manager/`
2. Dependencies installed via Composer: `vendor/autoload.php` and Jetpack autoloader

## Activation

1. Go to **WordPress Admin** → **Plugins**
2. Find **MCP Manager**
3. Click **Activate**

## Configuration

1. Navigate to **MCP Manager** in the WordPress admin sidebar
2. Check the box to **Enable MCP Adapter**
3. Click **Save Changes**
4. Copy the JSON configuration from the **LLM Configuration** section

## File Structure

```
mcp-manager/
├── mcp-manager.php          (Main plugin file with hooks)
├── composer.json            (Composer configuration)
├── composer.lock            (Composer lock file)
├── vendor/                  (Composer dependencies - generated)
│   ├── autoload.php        (PSR-4 autoloader)
│   ├── autoload_packages.php (Jetpack autoloader)
│   ├── automattic/
│   ├── jetpack-autoloader/
│   └── wordpress/
└── src/
    ├── Core/
    │   └── Plugin.php       (Singleton plugin class)
    ├── Admin/
    │   ├── Settings.php     (Settings API implementation)
    │   └── SettingsRenderer.php (JSON config generator)
    └── MCP/
        └── Controller.php   (MCP adapter initialization)
```

## Architecture

### Core Components

- **Plugin.php** — Singleton that initializes all plugin components
- **Settings.php** — Handles admin menu, settings registration, and field rendering
- **SettingsRenderer.php** — Generates MCP configuration JSON
- **Controller.php** — Manages conditional MCP adapter initialization

### Namespacing

All classes use the `MCP_MANAGER\` namespace:
- `MCP_MANAGER\Core\Plugin`
- `MCP_MANAGER\Admin\Settings`
- `MCP_MANAGER\Admin\SettingsRenderer`
- `MCP_MANAGER\MCP\Controller`

### Hooks Used

- `plugins_loaded` — Initialize plugin singleton
- `admin_menu` — Register admin menu
- `admin_init` — Register settings and fields
- `init` (priority 20) — Initialize MCP adapter if enabled

## Configuration Storage

Settings are stored in WordPress options table:
- `mcp_manager_enabled` (boolean) — Enable/disable MCP adapter

## Dependencies

- **automattic/jetpack-autoloader** (^2.0) — Handles Composer autoloading
- **wordpress/mcp-adapter** (>=0.4.1) — MCP adapter integration

## Requirements

- PHP 7.4+
- WordPress 5.9+
- Composer (for development/installation)

## Security

- All output is properly escaped using WordPress functions:
  - `esc_html()` — For HTML content
  - `esc_attr()` — For HTML attributes
  - `esc_textarea()` — For textarea values
  - `wp_kses_post()` — For formatted text

- All input is sanitized via Settings API callbacks
- Capability checks ensure only admin users can access settings
- Safe MCP adapter initialization with try-catch error handling

## Development

To modify or extend the plugin:

1. Edit files in `src/` directory
2. If adding dependencies, update `composer.json`
3. Run `composer install` to regenerate autoloaders
4. Test in WordPress admin

## Status Codes

The MCP Controller provides status reporting:
- `'running'` — MCP adapter is initialized and running
- `'disabled'` — MCP is disabled in settings
- `'not-found'` — MCP adapter class not installed
- `'error'` — Error during MCP adapter initialization
- `'unknown'` — Status not yet determined

## Version

Current Version: 1.0.0

## License

GPL-2.0-or-later
