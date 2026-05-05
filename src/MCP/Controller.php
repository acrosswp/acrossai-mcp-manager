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

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * Manages the MCP Adapter lifecycle.
 *
 * Reads enabled servers from the DB on every init hook and boots
 * the \WP\MCP\Plugin adapter singleton when at least one server is active.
 *
 * Status values
 * -------------
 *   'running'   — adapter initialised successfully
 *   'disabled'  — no enabled server rows in the DB
 *   'not-found' — \WP\MCP\Plugin class not available
 *   'error'     — exception thrown during adapter init
 *   'unknown'   — initialize_adapter() not yet called
 *
 * @since 1.0.0
 */
class Controller {

	/**
	 * Adapter status.
	 *
	 * @var string|null
	 */
	private $adapter_status = null;

	/**
	 * Constructor — registers the init hook.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'initialize_adapter' ), 1 );
	}

	/**
	 * Boot the MCP Adapter when at least one server is enabled.
	 *
	 * Registers database server hook at priority 20 (after DefaultServerFactory
	 * runs at priority 10) before calling Plugin::instance() so that our hook
	 * is in place when mcp_adapter_init fires.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function initialize_adapter() {
		if ( ! MCPServerTable::has_any_enabled() ) {
			$this->adapter_status = 'disabled';
			return;
		}

		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			$this->adapter_status = 'not-found';
			return;
		}

		try {
			// Register database servers at priority 11 — after DefaultServerFactory (priority 10).
			// Must be added before Plugin::instance() triggers the mcp_adapter_init action chain.
			add_action( 'mcp_adapter_init', array( $this, 'register_database_servers' ), 11 );

			\WP\MCP\Plugin::instance();
			$this->adapter_status = 'running';
		} catch ( \Exception $e ) {
			$this->adapter_status = 'error';
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				do_action( 'acrossai_mcp_manager_adapter_init_error', $e );
			}
		}
	}

	/**
	 * Boot all enabled database-registered MCP servers.
	 *
	 * Hooked on mcp_adapter_init at priority 20, after DefaultServerFactory (priority 10).
	 * Each enabled row with registered_from = 'database' gets its own MCP server instance.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The MCP Adapter singleton instance.
	 *
	 * @return void
	 */
	public function register_database_servers( $adapter ) {
		$servers = MCPServerTable::get_enabled_database_servers();

		if ( empty( $servers ) ) {
			return;
		}

		foreach ( $servers as $server ) {
			$slug      = $server['server_slug'];
			$namespace = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
			$route     = ! empty( $server['server_route'] ) ? $server['server_route'] : $slug;
			$version   = ! empty( $server['server_version'] ) ? $server['server_version'] : 'v1.0.0';

			if ( empty( $slug ) ) {
				continue;
			}

			$result = $adapter->create_server(
				$slug,
				$namespace,
				$route,
				$server['server_name'],
				$server['description'] ?? '',
				$version,
				array( HttpTransport::class ),
				ErrorLogMcpErrorHandler::class,
				NullMcpObservabilityHandler::class,
				array(
					'mcp-adapter/discover-abilities',
					'mcp-adapter/get-ability-info',
					'mcp-adapter/execute-ability',
				),
				array(),
				array()
			);

			if ( is_wp_error( $result ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'AcrossAI MCP Manager: Failed to create database server "%s". Error: %s (Code: %s)',
						esc_html( $slug ),
						esc_html( $result->get_error_message() ),
						esc_html( (string) $result->get_error_code() )
					),
					'1.2.0'
				);
			}
		}
	}

	/**
	 * Return the current adapter status string.
	 *
	 * Calls initialize_adapter() if it hasn't run yet.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_adapter_status() {
		if ( null === $this->adapter_status ) {
			$this->initialize_adapter();
		}

		return $this->adapter_status ?? 'unknown';
	}
}
