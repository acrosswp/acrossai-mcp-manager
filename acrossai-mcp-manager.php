<?php
/**
 * Plugin Name: AcrossAI MCP Manager
 * Plugin URI: https://wordpress.org/plugins/mcp-manager/
 * Description: Enable/Disable MCP Adapter Integration for WordPress
 * Version: 1.0.0
 * Author: raftaar1191
 * Author URI: https://profiles.wordpress.org/raftaar1191/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: acrossai-mcp-manager
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires WP: 5.9
 *
 * @package AcrossAI_MCP_Manager
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'ACROSSAI_MCP_MANAGER_VERSION', '1.0.0' );
define( 'ACROSSAI_MCP_MANAGER_FILE', __FILE__ );
define( 'ACROSSAI_MCP_MANAGER_DIR', __DIR__ );
define( 'ACROSSAI_MCP_MANAGER_URL', plugin_dir_url( __FILE__ ) );

// Load Jetpack Autoloader if available.
$acrossai_mcp_manager_jetpack_autoloader = __DIR__ . '/vendor/autoload_packages.php';
if ( is_file( $acrossai_mcp_manager_jetpack_autoloader ) ) {
	require_once $acrossai_mcp_manager_jetpack_autoloader;
}

// Load Composer Autoloader.
if ( is_file( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Initialize basic auth handler for REST API
if ( class_exists( 'ACROSSAI_MCP_MANAGER\Auth\BasicAuthHandler' ) ) {
	ACROSSAI_MCP_MANAGER\Auth\BasicAuthHandler::init();
}

/**
 * Plugin initialization hook.
 *
 * @since 1.0.0
 */
add_action(
	'plugins_loaded',
	function () {
		// Initialize the plugin.
		ACROSSAI_MCP_MANAGER\Core\Plugin::instance();
	},
	10
);

/**
 * Plugin activation hook.
 *
 * @since 1.0.0
 */
register_activation_hook(
	__FILE__,
	function () {
		// Activation logic (placeholder for future).
		// Could be used for setting default options, creating tables, etc.
	}
);

/**
 * Plugin deactivation hook.
 *
 * @since 1.0.0
 */
register_deactivation_hook(
	__FILE__,
	function () {
		// Deactivation logic (placeholder for future).
		// Could be used for cleanup operations.
	}
);
