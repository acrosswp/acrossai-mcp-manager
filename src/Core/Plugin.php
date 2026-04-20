<?php
/**
 * Main Plugin class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Core
 */

namespace ACROSSAI_MCP_MANAGER\Core;

use ACROSSAI_MCP_MANAGER\Admin\Settings;
use ACROSSAI_MCP_MANAGER\MCP\Controller;

/**
 * Plugin singleton class.
 *
 * Manages plugin initialization and provides main plugin instance.
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
	 * Private constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->settings   = new Settings( $this );
		$this->controller = new Controller( $this );
	}

	/**
	 * Get singleton instance.
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
	 * Get plugin URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string Plugin URL.
	 */
	public function get_plugin_url() {
		return ACROSSAI_MCP_MANAGER_URL;
	}

	/**
	 * Get plugin option.
	 *
	 * Wrapper for WordPress get_option function.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed Option value.
	 */
	public function get_option( $key, $default = false ) {
		return get_option( $key, $default );
	}
}
