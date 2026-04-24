<?php
/**
 * CLI REST Controller class.
 *
 * Provides REST endpoints consumed by the @acrossai/mcp-manager CLI tool.
 *
 * Endpoints (namespace: acrossai-mcp-manager/v1)
 * -----------------------------------------------
 *   GET  /health             – plugin status check (no auth)
 *   POST /auth/start         – create a pending auth session
 *   GET  /auth/status        – poll for browser approval
 *   GET  /servers            – list accessible servers (session token required)
 *   POST /auth/exchange      – exchange approved auth code for app password
 *
 * Auth flow
 * ---------
 *   1. CLI posts to /auth/start  → gets auth_code + auth_url
 *   2. CLI opens auth_url in browser; admin approves via WP admin page
 *   3. CLI polls /auth/status    → gets session_token when approved
 *   4. CLI calls /servers with   Bearer session_token
 *   5. CLI posts to /auth/exchange with auth_code → gets app password
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage REST
 */

namespace ACROSSAI_MCP_MANAGER\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;

/**
 * Registers and handles all CLI-facing REST routes.
 *
 * @since 1.0.0
 */
class CliController {

	/**
	 * Lifetime in seconds for a pending auth code.
	 */
	const AUTH_CODE_TTL = 300;

	/**
	 * Lifetime in seconds for a session token after approval.
	 */
	const SESSION_TOKEN_TTL = 600;

	/**
	 * Transient key prefix for auth code records.
	 */
	const AUTH_TRANSIENT_PREFIX = 'acrossai_cli_auth_';

	/**
	 * Transient key prefix for approved session tokens.
	 */
	const SESSION_TRANSIENT_PREFIX = 'acrossai_session_';

	/**
	 * Constructor — registers REST routes.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	// -------------------------------------------------------------------------
	// Route registration
	// -------------------------------------------------------------------------

	/**
	 * Register all CLI REST routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = 'acrossai-mcp-manager/v1';

		register_rest_route(
			$ns,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/auth/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'auth_start' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'server_id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/auth/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'auth_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'server' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/servers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_servers' ),
				'permission_callback' => array( $this, 'verify_session_token' ),
			)
		);

		register_rest_route(
			$ns,
			'/auth/exchange',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'auth_exchange' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code'      => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'server_id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Endpoint callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /health — confirm the plugin is installed and active.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response
	 */
	public function health() {
		return rest_ensure_response(
			array(
				'plugin_installed' => true,
				'plugin_active'    => true,
				'version'          => ACROSSAI_MCP_MANAGER_VERSION,
				'site_slug'        => sanitize_title( get_bloginfo( 'name' ) ),
			)
		);
	}

	/**
	 * POST /auth/start — create a pending auth session.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function auth_start( \WP_REST_Request $request ) {
		$server_id = $request->get_param( 'server_id' );
		$auth_code = bin2hex( random_bytes( 16 ) );

		$auth_url = add_query_arg(
			array(
				'action' => 'cli_auth',
				'code'   => $auth_code,
				'server' => rawurlencode( $server_id ),
			),
			\ACROSSAI_MCP_MANAGER\Frontend\FrontendAuth::get_base_url()
		);

		set_transient(
			self::AUTH_TRANSIENT_PREFIX . $auth_code,
			array(
				'server_id'     => $server_id,
				'status'        => 'pending',
				'user_id'       => null,
				'session_token' => null,
				'created_at'    => time(),
			),
			self::AUTH_CODE_TTL
		);

		return rest_ensure_response(
			array(
				'auth_code'  => $auth_code,
				'auth_url'   => $auth_url,
				'expires_in' => self::AUTH_CODE_TTL,
			)
		);
	}

	/**
	 * GET /auth/status — poll for approval status.
	 *
	 * Returns approved=true with a session token once the admin has approved
	 * the request in the browser.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function auth_status( \WP_REST_Request $request ) {
		$code = $request->get_param( 'code' );
		$data = get_transient( self::AUTH_TRANSIENT_PREFIX . $code );

		if ( false === $data ) {
			return new \WP_Error(
				'invalid_code',
				__( 'Auth code not found or expired.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}

		if ( 'approved' === $data['status'] ) {
			return rest_ensure_response(
				array(
					'approved' => true,
					'token'    => $data['session_token'],
				)
			);
		}

		return rest_ensure_response( array( 'approved' => false ) );
	}

	/**
	 * Permission callback for GET /servers — validates Bearer session token.
	 *
	 * Sets the current user from the session token so downstream code that
	 * calls get_current_user_id() returns the correct value.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return bool|\WP_Error
	 */
	public function verify_session_token( \WP_REST_Request $request ) {
		$auth_header = $request->get_header( 'Authorization' );

		if ( ! $auth_header || 0 !== strpos( $auth_header, 'Bearer ' ) ) {
			return new \WP_Error(
				'missing_token',
				__( 'Authorization token required.', 'acrossai-mcp-manager' ),
				array( 'status' => 401 )
			);
		}

		$token   = substr( $auth_header, 7 );
		$user_id = get_transient( self::SESSION_TRANSIENT_PREFIX . $token );

		if ( ! $user_id ) {
			return new \WP_Error(
				'invalid_token',
				__( 'Invalid or expired session token.', 'acrossai-mcp-manager' ),
				array( 'status' => 401 )
			);
		}

		wp_set_current_user( (int) $user_id );
		return true;
	}

	/**
	 * GET /servers — return all servers accessible to the authenticated user.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response
	 */
	public function list_servers() {
		$rows    = MCPServerTable::get_all();
		$servers = array();

		foreach ( $rows as $row ) {
			$servers[] = array(
				'id'          => sanitize_title( $row['server_name'] ),
				'name'        => $row['server_name'],
				'description' => $row['description'],
				'enabled'     => (bool) $row['is_enabled'],
				'mcp_url'     => rest_url( 'mcp/mcp-adapter-default-server' ),
			);
		}

		return rest_ensure_response( array( 'servers' => $servers ) );
	}

	/**
	 * POST /auth/exchange — trade approved auth code for a WP Application Password.
	 *
	 * This endpoint is single-use: both the auth code and session token
	 * transients are deleted on success.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function auth_exchange( \WP_REST_Request $request ) {
		$code      = $request->get_param( 'code' );
		$server_id = $request->get_param( 'server_id' );

		$data = get_transient( self::AUTH_TRANSIENT_PREFIX . $code );

		if ( false === $data ) {
			return new \WP_Error(
				'invalid_code',
				__( 'Auth code not found or expired.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( 'approved' !== $data['status'] ) {
			return new \WP_Error(
				'not_approved',
				__( 'Auth code has not been approved yet.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$user_id = (int) $data['user_id'];
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new \WP_Error(
				'invalid_user',
				__( 'Approving user not found.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new \WP_Error(
				'not_supported',
				__( 'Application Passwords are not supported on this server.', 'acrossai-mcp-manager' ),
				array( 'status' => 501 )
			);
		}

		$app_name = sprintf( 'AcrossAI MCP Manager CLI - %s', sanitize_text_field( $server_id ) );
		$result   = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => $app_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		list( $password, ) = $result;

		// Consume both transients — auth code is single-use.
		delete_transient( self::AUTH_TRANSIENT_PREFIX . $code );
		if ( ! empty( $data['session_token'] ) ) {
			delete_transient( self::SESSION_TRANSIENT_PREFIX . $data['session_token'] );
		}

		return rest_ensure_response(
			array(
				'app_password' => $password,
				'username'     => $user->user_login,
				'user_id'      => $user_id,
				'expires_in'   => 2592000,
				'server_id'    => $server_id,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Static helper (called from Settings.php approval handler)
	// -------------------------------------------------------------------------

	/**
	 * Approve a pending auth code on behalf of the given user.
	 *
	 * Creates both the updated auth-code record (approved) and a separate
	 * short-lived session token transient consumed by GET /servers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $auth_code 32-char hex auth code.
	 * @param int    $user_id   WordPress user ID of the approving admin.
	 *
	 * @return bool True on success, false if code not found or already approved.
	 */
	public static function approve_auth_code( $auth_code, $user_id ) {
		$data = get_transient( self::AUTH_TRANSIENT_PREFIX . $auth_code );

		if ( false === $data || 'pending' !== $data['status'] ) {
			return false;
		}

		$session_token = bin2hex( random_bytes( 16 ) );

		$data['status']        = 'approved';
		$data['user_id']       = (int) $user_id;
		$data['session_token'] = $session_token;

		// Keep auth code alive for the exchange call.
		set_transient( self::AUTH_TRANSIENT_PREFIX . $auth_code, $data, self::AUTH_CODE_TTL );

		// Shorter-lived token used only for the /servers request.
		set_transient( self::SESSION_TRANSIENT_PREFIX . $session_token, (int) $user_id, self::SESSION_TOKEN_TTL );

		return true;
	}
}
