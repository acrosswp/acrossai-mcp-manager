<?php
/**
 * Main Plugin class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Core
 */

namespace ACROSSAI_MCP_MANAGER\Core;

use ACROSSAI_MCP_MANAGER\AccessControl\AccessControlManager;
use ACROSSAI_MCP_MANAGER\Admin\Settings;
use ACROSSAI_MCP_MANAGER\CLI\SetupCommand;
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
		$this->access_control  = new AccessControlManager();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'acrossai-mcp', SetupCommand::class );
		}
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
