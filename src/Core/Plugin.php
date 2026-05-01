<?php
/**
 * Main Plugin class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Core
 */

namespace ACROSSAI_MCP_MANAGER\Core;

use WPBoilerplate\AccessControl\Admin\AccessControlUI;
use WPBoilerplate\AccessControl\AccessControlManager;
use WPBoilerplate\AccessControl\AccessControlTable;
use ACROSSAI_MCP_MANAGER\Admin\Settings;
use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;
use ACROSSAI_MCP_MANAGER\Frontend\FrontendAuth;
use ACROSSAI_MCP_MANAGER\MCP\Controller;
use ACROSSAI_MCP_MANAGER\OAuth\Server as OAuthServer;
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
	 * OAuth 2.1 server instance.
	 *
	 * @var OAuthServer
	 */
	private $oauth_server;

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
		// Ensure the access control table exists before any provider logic runs.
		AccessControlTable::maybe_create_table();

		// Custom filter tag so this plugin's providers don't collide with
		// other plugins using the same wpb-access-control library.
		$this->access_control = new AccessControlManager( 'acrossai_mcp_access_control_providers' );
		AccessControlUI::bootstrap();

		// OAuth 2.1 server — registers rewrite rules, REST routes, and bearer validation (priority 5).
		$this->oauth_server = new OAuthServer();
		$this->oauth_server->boot();

		// Enforce per-server access control on MCP REST requests (priority 10, after OAuth at 5).
		add_filter( 'rest_pre_dispatch', array( $this, 'enforce_mcp_access_control' ), 10, 3 );
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
	 * Enforce per-server access control on MCP REST requests.
	 *
	 * Hooked on rest_pre_dispatch at priority 10. Iterates the enabled server
	 * list and short-circuits with WP_Error 403 if the current user fails the
	 * access control rule for the matched server.
	 *
	 * The library (AccessControlManager::user_has_access) handles the full
	 * decision hierarchy: everyone → admin bypass → unauthenticated → provider.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed            $result  Short-circuit value (null = not yet handled).
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return mixed Original $result, or WP_Error on access denial.
	 */
	public function enforce_mcp_access_control( $result, $server, $request ) {
		// Another filter already short-circuited — respect that.
		if ( null !== $result ) {
			return $result;
		}

		$route = $request->get_route(); // e.g. "/mcp/default-mcp-server"

		foreach ( MCPServerTable::get_all() as $row ) {
			if ( empty( $row['is_enabled'] ) ) {
				continue;
			}

			$ns          = ! empty( $row['server_route_namespace'] ) ? $row['server_route_namespace'] : 'mcp';
			$server_route = ! empty( $row['server_route'] ) ? $row['server_route'] : $row['server_slug'];

			// Match the leading portion of the REST route against this server.
			$expected_prefix = '/' . trim( $ns, '/' ) . '/' . ltrim( $server_route, '/' );

			if ( 0 !== strpos( $route, $expected_prefix ) ) {
				continue;
			}

			// Route matched — evaluate access control.
			$user_id = get_current_user_id();
			if ( ! $this->access_control->user_has_access( $user_id, $ns, $server_route ) ) {
				/**
				 * Fires when a MCP REST request is denied by access control.
				 *
				 * @since 1.5.0
				 *
				 * @param int    $user_id      The requesting user ID (0 = unauthenticated).
				 * @param array  $row          The server DB row.
				 * @param string $ns           REST namespace.
				 * @param string $server_route REST route.
				 */
				do_action( 'acrossai_mcp_access_denied', $user_id, $row, $ns, $server_route );

				return new \WP_Error(
					'acrossai_mcp_access_denied',
					__( 'You do not have permission to access this MCP server.', 'acrossai-mcp-manager' ),
					array( 'status' => $user_id ? 403 : 401 )
				);
			}

			// Access granted — stop checking further servers.
			break;
		}

		return $result;
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
