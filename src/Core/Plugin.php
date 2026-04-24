<?php
/**
 * Main Plugin class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Core
 */

namespace ACROSSAI_MCP_MANAGER\Core;

use WPBoilerplate\AccessControl\AccessControlManager;
use ACROSSAI_MCP_MANAGER\Admin\Settings;
use ACROSSAI_MCP_MANAGER\Frontend\FrontendAuth;
use ACROSSAI_MCP_MANAGER\MCP\Controller;
use ACROSSAI_MCP_MANAGER\REST\CliController;

/**
 * Plugin singleton — boots the admin UI and MCP controller.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * MCP controller instance.
	 *
	 * @var Controller
	 */
	private $controller;

	/**
	 * CLI REST controller instance.
	 *
	 * @var CliController
	 */
	private $cli_controller;

	/**
	 * Frontend auth page instance.
	 *
	 * @var FrontendAuth
	 */
	private $frontend_auth;

	/**
	 * Access control manager instance.
	 *
	 * @var AccessControlManager
	 */
	private $access_control;

	/**
	 * Private constructor — use instance() instead.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->settings        = new Settings();
		$this->controller      = new Controller();
		$this->cli_controller  = new CliController();
		$this->frontend_auth   = new FrontendAuth();
		$this->access_control  = new AccessControlManager(
			// Fetcher: returns all server rows used to match REST routes.
			function () {
				return \ACROSSAI_MCP_MANAGER\Database\MCPServerTable::get_all();
			},
			// Custom filter tag so this plugin's providers don't collide
			// with other plugins using the same wpb-access-control library.
			'acrossai_mcp_access_control_providers'
		);

	}

	/**
	 * Return the singleton instance, creating it on first call.
	 *
	 * @since 1.0.0
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Return the access control manager instance.
	 *
	 * Used by Settings::render_access_control_tab() so that it reuses the
	 * already-bootstrapped manager (providers loaded) rather than creating a
	 * fresh instance after `init` has fired.
	 *
	 * @since 1.4.0
	 *
	 * @return AccessControlManager
	 */
	public function get_access_control_manager(): AccessControlManager {
		return $this->access_control;
	}

	/**
	 * Return the plugin URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_plugin_url() {
		return ACROSSAI_MCP_MANAGER_URL;
	}
}
