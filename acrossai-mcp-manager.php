<?php
/**
 * Plugin Name: AcrossAI MCP Manager
 * Plugin URI: https://acrossai.co/
 * Description: Enable/Disable MCP Adapter Integration for WordPress
 * Version: 0.0.1
 * Author: raftaar1191
 * Author URI: https://profiles.wordpress.org/raftaar1191/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: acrossai-mcp-manager
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires WP: 6.9
 *
 * @package AcrossAI_MCP_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACROSSAI_MCP_MANAGER_VERSION', '0.0.1' );
define( 'ACROSSAI_MCP_MANAGER_FILE', __FILE__ );
define( 'ACROSSAI_MCP_MANAGER_DIR', __DIR__ );
define( 'ACROSSAI_MCP_MANAGER_URL', plugin_dir_url( __FILE__ ) );

// Load Jetpack Autoloader.
$acrossai_mcp_manager_jetpack_autoloader = __DIR__ . '/vendor/autoload_packages.php';
if ( is_file( $acrossai_mcp_manager_jetpack_autoloader ) ) {
	require_once $acrossai_mcp_manager_jetpack_autoloader;
}

/**
 * Boot the plugin.
 *
 * Creates/upgrades the DB table if the schema version changed, then
 * initialises the plugin singleton.
 *
 * @since 1.2.0
 */
add_action(
	'plugins_loaded',
	function () {
		ACROSSAI_MCP_MANAGER\Database\MCPServerTable::maybe_create_table();
		ACROSSAI_MCP_MANAGER\Core\Plugin::instance();
	},
	10
);

/**
 * Activation hook — create table and seed the default server row.
 *
 * @since 1.2.0
 */
register_activation_hook(
	__FILE__,
	function () {
		ACROSSAI_MCP_MANAGER\Database\MCPServerTable::create_table();
		ACROSSAI_MCP_MANAGER\Database\MCPServerTable::insert_default_server();
		ACROSSAI_MCP_MANAGER\Database\CliAuthLogTable::maybe_create_table();
		ACROSSAI_MCP_MANAGER\Database\ConnectorAuditLogTable::maybe_create_table();
		WPBoilerplate\AccessControl\AccessControlTable::maybe_create_table();

		// Register and flush the frontend CLI auth page rewrite rule.
		add_rewrite_rule(
			'^' . ACROSSAI_MCP_MANAGER\Frontend\FrontendAuth::PAGE_SLUG . '/?$',
			'index.php?' . ACROSSAI_MCP_MANAGER\Frontend\FrontendAuth::QUERY_VAR . '=1',
			'top'
		);
		add_rewrite_rule(
			'^' . ACROSSAI_MCP_MANAGER\OAuth\ClaudeConnectors::AUTHORIZE_PATH . '/?$',
			'index.php?' . ACROSSAI_MCP_MANAGER\OAuth\ClaudeConnectors::AUTHORIZE_QUERY_VAR . '=1',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?' . ACROSSAI_MCP_MANAGER\OAuth\ClaudeConnectors::AUTH_SERVER_QUERY_VAR . '=1',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/?$',
			'index.php?' . ACROSSAI_MCP_MANAGER\OAuth\ClaudeConnectors::RESOURCE_QUERY_VAR . '=1',
			'top'
		);
		flush_rewrite_rules();
	}
);

/**
 * Deactivation hook — placeholder for future cleanup.
 *
 * @since 1.2.0
 */
register_deactivation_hook(
	__FILE__,
	function () {
		// Intentionally left empty.
	}
);
