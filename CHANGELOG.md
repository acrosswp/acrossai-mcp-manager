# Changelog

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
