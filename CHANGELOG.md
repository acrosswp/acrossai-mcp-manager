# Changelog

## [0.0.3] - 2026-05-14

### Dependencies

- Updated `wpboilerplate/wpb-access-control` to latest (BerlinDB-backed storage, flat-row schema)
- Updated `bshaffer/oauth2-server-httpfoundation-bridge` v1.7.1 → v1.7.2
- Updated `symfony/deprecation-contracts` v3.6.0 → v3.7.0
- Added `berlindb/core` 2.0.2 (new transitive dependency of wpb-access-control)

### Fixed

- Remove all references to the removed `AccessControlTable` class (replaced by `RuleQuery` in wpb-access-control v3.0.0); fixes fatal error on plugin activation
- Remove manual `AccessControlTable::maybe_create_table()` calls from the activation hook and `Plugin::__construct()`; the access-control table is now auto-bootstrapped by `RuleQuery` on first use
- Remove the dead `save_access_control` POST action handler in `Settings::handle_actions()`; access-control rules are now saved exclusively via the library's built-in AJAX handler (`wp_ajax_wpb_access_control_save`)
- Update the v1.5.0 legacy migration in `MCPServerTable` to parse the old JSON blob and call `RuleQuery::set_rule()` instead of the removed `AccessControlTable::update()`

## [0.0.2] - 2026-05-08

### Security & Code Quality

#### Fixed
- Sanitize, validate, and escape all `$_GET` and `$_POST` superglobal inputs using `sanitize_key()`, `sanitize_text_field()`, `absint()`, and `wp_unslash()` throughout the plugin
- Replace hardcoded `ABSPATH` path reference with `get_home_path()` for correct subdirectory-install compatibility
- Remove all inline `<style>` and `<script>` blocks; move CSS to `assets/frontend-auth.css` and `assets/frontend-oauth.css`, enqueued via `wp_enqueue_style()`; move JS to `assets/admin.js`, enqueued via `wp_enqueue_script()` with data passed through `wp_localize_script()`

## [0.0.1] - 2026-05-04

### Initial Release

This is the first public release of the AcrossAI MCP Manager plugin.

#### Added
- Initial plugin structure and setup
- MCP Server management interface
- REST API endpoints for CLI integration
- Application Password support for MCP clients
- Frontend authentication page for CLI auth flow
- Database tables for MCP servers and audit logs
- Admin settings and configuration pages
- Support for multiple MCP clients (VS Code, Claude, Copilot, etc.)
- Access control system for MCP servers
- WordPress 6.9+ compatibility
- PHP 8.0+ requirement

#### Features
- **Multi-Client Support**: Configure MCP for VS Code, Claude Desktop, GitHub Copilot, and custom clients
- **Secure Authentication**: Uses WordPress native Application Passwords system
- **Server Management**: Create, edit, enable/disable MCP servers from WordPress admin
- **REST API**: Programmatic access to MCP servers and authentication
- **Audit Logging**: Track CLI authentication attempts and server usage
- **Access Control**: Restrict server access by user role

#### Technical Details
- PHPCS & PluginCheck compliant
- Uses WordPress %i placeholder for database identifiers (WordPress 6.2+)
- Proper SQL prepared statement handling
- Security-hardened database queries

---

All versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
