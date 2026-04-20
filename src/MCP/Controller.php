<?php
/**
 * MCP Controller class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCP
 */

namespace ACROSSAI_MCP_MANAGER\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Core\Plugin;

/**
 * Manages MCP Adapter integration.
 *
 * @since 1.0.0
 */
class Controller {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Adapter status cache.
	 *
	 * @var string|null
	 */
	private $adapter_status = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		// Register initialization hook.
		add_action( 'init', array( $this, 'initialize_adapter' ), 20 );
	}

	/**
	 * Initialize MCP Adapter if enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function initialize_adapter() {
		// Check if MCP Adapter is enabled.
		if ( ! $this->is_enabled() ) {
			$this->adapter_status = 'disabled';
			return;
		}

		// Check if MCP Adapter Plugin class exists.
		// The MCP Adapter uses WP\MCP namespace, not \MCP
		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			$this->adapter_status = 'not-found';
			return;
		}

		// Initialize MCP Adapter.
		try {
			\WP\MCP\Plugin::instance();
			$this->adapter_status = 'running';
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				do_action( 'acrossai_mcp_manager_adapter_init_error', $e );
			}
			$this->adapter_status = 'error';
		}
	}

	/**
	 * Check if MCP Adapter is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled() {
		return (bool) $this->plugin->get_option( 'acrossai_mcp_manager_enabled', false );
	}

	/**
	 * Get adapter status.
	 *
	 * @since 1.0.0
	 *
	 * @return string Adapter status: 'running', 'disabled', 'not-found', or 'error'.
	 */
	public function get_adapter_status() {
		if ( null === $this->adapter_status ) {
			// Initialize if not already done.
			$this->initialize_adapter();
		}

		if ( null === $this->adapter_status ) {
			return 'unknown';
		}

		return $this->adapter_status;
	}
}
