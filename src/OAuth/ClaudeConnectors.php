<?php
/**
 * Experimental direct Claude Connectors OAuth flow.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage OAuth
 */

namespace ACROSSAI_MCP_MANAGER\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;
use ACROSSAI_MCP_MANAGER\Database\ConnectorAuditLogTable;
use OAuth2\HttpFoundationBridge\Request as BridgeRequest;
use OAuth2\HttpFoundationBridge\Response as BridgeResponse;

/**
 * Hosts metadata, browser authorization, token exchange, and bearer auth.
 */
class ClaudeConnectors {

	/**
	 * Feature setting toggle.
	 */
	const OPTION_ENABLED = 'acrossai_mcp_claude_connectors_enabled';

	/**
	 * OAuth client settings.
	 */
	const OPTION_CLIENT_ID     = 'acrossai_mcp_claude_connector_client_id';
	const OPTION_CLIENT_SECRET = 'acrossai_mcp_claude_connector_client_secret';
	const OPTION_REDIRECT_URI  = 'acrossai_mcp_claude_connector_redirect_uri';

	/**
	 * Virtual authorize page path.
	 */
	const AUTHORIZE_PATH = 'acrossai-mcp-connectors/oauth/authorize';

	/**
	 * Query vars for rewrites.
	 */
	const AUTHORIZE_QUERY_VAR      = 'acrossai_mcp_connector_authorize';
	const AUTH_SERVER_QUERY_VAR    = 'acrossai_mcp_oauth_authorization_server';
	const RESOURCE_QUERY_VAR       = 'acrossai_mcp_oauth_protected_resource';

	/**
	 * Cached storage instance.
	 *
	 * @var Storage|null
	 */
	private $storage = null;

	/**
	 * Current bearer token value for this request.
	 *
	 * @var string
	 */
	private $current_access_token = '';

	/**
	 * Current bearer token context for this request.
	 *
	 * @var array<string,mixed>
	 */
	private $current_access_token_context = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'disable_canonical_redirects' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'handle_frontend_request' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'determine_current_user', array( $this, 'determine_current_user_from_bearer' ), 20 );
		add_filter( 'rest_post_dispatch', array( $this, 'decorate_mcp_response' ), 10, 3 );
		add_action( 'acrossai_mcp_access_denied', array( $this, 'log_access_denied_event' ), 10, 4 );
	}

	/**
	 * Return whether the feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * Return whether OAuth settings exist for a server, or for any server.
	 *
	 * @param array<string,mixed>|null $server_row Optional server row.
	 *
	 * @return bool
	 */
	public static function is_configured( $server_row = null ) {
		if ( is_array( $server_row ) ) {
			return self::is_server_configured( $server_row );
		}

		return self::has_configured_server();
	}

	/**
	 * Return whether at least one server has Claude connector OAuth settings.
	 *
	 * @return bool
	 */
	public static function has_configured_server() {
		foreach ( MCPServerTable::get_all() as $row ) {
			if ( self::is_server_configured( $row ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return whether a specific server has Claude connector OAuth settings.
	 *
	 * @param array<string,mixed> $server_row Server row.
	 *
	 * @return bool
	 */
	public static function is_server_configured( array $server_row ) {
		$config = self::get_server_connector_config( $server_row );

		return '' !== $config['client_id'] && '' !== $config['redirect_uri'];
	}

	/**
	 * Return normalized Claude connector OAuth settings for a server.
	 *
	 * @param array<string,mixed> $server_row Server row.
	 *
	 * @return array{client_id:string,client_secret:string,redirect_uri:string}
	 */
	public static function get_server_connector_config( array $server_row ) {
		return array(
			'client_id'     => isset( $server_row['claude_connector_client_id'] ) ? (string) $server_row['claude_connector_client_id'] : '',
			'client_secret' => isset( $server_row['claude_connector_client_secret'] ) ? (string) $server_row['claude_connector_client_secret'] : '',
			'redirect_uri'  => isset( $server_row['claude_connector_redirect_uri'] ) ? (string) $server_row['claude_connector_redirect_uri'] : '',
		);
	}

	/**
	 * Return the authorization page URL.
	 *
	 * @return string
	 */
	public static function get_authorize_url() {
		return trailingslashit( home_url( self::AUTHORIZE_PATH ) );
	}

	/**
	 * Return the issuer identifier.
	 *
	 * @return string
	 */
	public static function get_issuer() {
		return trailingslashit( home_url( '/' ) );
	}

	/**
	 * Return the auth server metadata URL.
	 *
	 * @return string
	 */
	public static function get_authorization_server_metadata_url() {
		return home_url( '/.well-known/oauth-authorization-server' );
	}

	/**
	 * Return the protected resource metadata URL.
	 *
	 * @param string $resource Optional MCP resource URL.
	 *
	 * @return string
	 */
	public static function get_resource_metadata_url( $resource = '' ) {
		$url = home_url( '/.well-known/oauth-protected-resource' );

		if ( '' !== $resource ) {
			$url = add_query_arg( 'resource', $resource, $url );
		}

		return $url;
	}

	/**
	 * Return the token endpoint URL.
	 *
	 * @return string
	 */
	public static function get_token_endpoint_url() {
		return rest_url( 'acrossai-mcp-manager/v1/connector/oauth/token' );
	}

	/**
	 * Register virtual routes.
	 *
	 * @return void
	 */
	public function register_rewrite_rules() {
		add_rewrite_rule(
			'^' . self::AUTHORIZE_PATH . '/?$',
			'index.php?' . self::AUTHORIZE_QUERY_VAR . '=1',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?' . self::AUTH_SERVER_QUERY_VAR . '=1',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/?$',
			'index.php?' . self::RESOURCE_QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Flush rewrites once when needed.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		$rules = get_option( 'rewrite_rules' );

		if ( empty( $rules )
			|| ! isset( $rules[ '^' . self::AUTHORIZE_PATH . '/?$' ] )
			|| ! isset( $rules['^\.well-known/oauth-authorization-server/?$'] )
			|| ! isset( $rules['^\.well-known/oauth-protected-resource/?$'] ) ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Register public query vars.
	 *
	 * @param string[] $vars Current query vars.
	 *
	 * @return string[]
	 */
	public function add_query_vars( array $vars ) {
		$vars[] = self::AUTHORIZE_QUERY_VAR;
		$vars[] = self::AUTH_SERVER_QUERY_VAR;
		$vars[] = self::RESOURCE_QUERY_VAR;

		return $vars;
	}

	/**
	 * Prevent WordPress canonical redirects on virtual OAuth routes.
	 *
	 * @param string|false $redirect_url Canonical redirect URL.
	 * @param string       $requested_url Requested URL.
	 *
	 * @return string|false
	 */
	public function disable_canonical_redirects( $redirect_url, $requested_url ) {
		unset( $requested_url );

		if ( get_query_var( self::AUTHORIZE_QUERY_VAR )
			|| get_query_var( self::AUTH_SERVER_QUERY_VAR )
			|| get_query_var( self::RESOURCE_QUERY_VAR ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Register the token endpoint.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'acrossai-mcp-manager/v1',
			'/connector/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Serve the token endpoint.
	 *
	 * @param \WP_REST_Request $wp_request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_token_request( \WP_REST_Request $wp_request ) {
		if ( ! self::is_enabled() ) {
			return new \WP_REST_Response(
				array(
					'error'             => 'temporarily_unavailable',
					'error_description' => __( 'Direct Claude Connectors mode is disabled or incomplete.', 'acrossai-mcp-manager' ),
				),
				503
			);
		}

		$request          = BridgeRequest::createFromGlobals();
		$exchange_context = $this->get_token_exchange_context( $request );
		$server_row       = $this->resolve_server_for_token_request( $request, $exchange_context );

		if ( ! $server_row || ! self::is_server_configured( $server_row ) ) {
			return new \WP_REST_Response(
				array(
					'error'             => 'temporarily_unavailable',
					'error_description' => __( 'This MCP server does not have Claude connector OAuth settings yet.', 'acrossai-mcp-manager' ),
				),
				503
			);
		}

		$server           = $this->build_server( $server_row );
		$response         = new BridgeResponse();
		$grant_type       = (string) $request->request( 'grant_type' );
		$response         = $server->handleTokenRequest( $request, $response );
		$payload          = json_decode( (string) $response->getContent(), true );
		$payload          = is_array( $payload ) ? $payload : array();

		if ( 200 === $response->getStatusCode() && ! empty( $payload['access_token'] ) ) {
			$this->attach_token_contexts( $payload, $exchange_context );
			$this->log_event(
				'token_exchange',
				'success',
				array(
					'user_id'                 => isset( $exchange_context['user_id'] ) ? (int) $exchange_context['user_id'] : 0,
					'server_id'               => isset( $exchange_context['server_id'] ) ? (int) $exchange_context['server_id'] : 0,
					'server_slug'             => $exchange_context['server_slug'] ?? '',
					'client_id'               => $exchange_context['client_id'] ?? (string) $request->request( 'client_id' ),
					'resource_url'            => $exchange_context['resource_url'] ?? '',
					'scope'                   => $exchange_context['scope'] ?? '',
					'request_method'          => $wp_request->get_method(),
					'request_route'           => $wp_request->get_route(),
					'response_code'           => $response->getStatusCode(),
					'authorization_code_hash' => $this->hash_value( (string) $request->request( 'code' ) ),
					'access_token_hash'       => $this->hash_value( (string) $payload['access_token'] ),
					'details'                 => array(
						'grant_type'        => $grant_type,
						'refresh_issued'    => ! empty( $payload['refresh_token'] ),
						'refresh_token_hash'=> ! empty( $payload['refresh_token'] ) ? $this->hash_value( (string) $payload['refresh_token'] ) : '',
						'message'           => 'OAuth token issued successfully.',
					),
				)
			);
		} else {
			$this->log_event(
				'token_exchange',
				'failed',
				array(
					'user_id'                 => isset( $exchange_context['user_id'] ) ? (int) $exchange_context['user_id'] : 0,
					'server_id'               => isset( $exchange_context['server_id'] ) ? (int) $exchange_context['server_id'] : 0,
					'server_slug'             => $exchange_context['server_slug'] ?? '',
					'client_id'               => $exchange_context['client_id'] ?? (string) $request->request( 'client_id' ),
					'resource_url'            => $exchange_context['resource_url'] ?? '',
					'scope'                   => $exchange_context['scope'] ?? '',
					'request_method'          => $wp_request->get_method(),
					'request_route'           => $wp_request->get_route(),
					'response_code'           => $response->getStatusCode(),
					'failure_code'            => isset( $payload['error'] ) ? sanitize_key( $payload['error'] ) : 'token_request_failed',
					'authorization_code_hash' => $this->hash_value( (string) $request->request( 'code' ) ),
					'details'                 => array(
						'grant_type' => $grant_type,
						'message'    => $payload['error_description'] ?? 'OAuth token request failed.',
					),
				)
			);
		}

		$result = new \WP_REST_Response( $payload, $response->getStatusCode() );

		foreach ( $response->headers->all_preserve_case() as $name => $values ) {
			$value = is_array( $values ) ? implode( ', ', $values ) : (string) $values;
			$result->header( $name, $value );
		}

		$result->header( 'Content-Type', 'application/json' );

		return $result;
	}

	/**
	 * Handle virtual frontend requests.
	 *
	 * @return void
	 */
	public function handle_frontend_request() {
		if ( get_query_var( self::AUTH_SERVER_QUERY_VAR ) ) {
			$this->render_authorization_server_metadata();
		}

		if ( get_query_var( self::RESOURCE_QUERY_VAR ) ) {
			$this->render_protected_resource_metadata();
		}

		if ( get_query_var( self::AUTHORIZE_QUERY_VAR ) ) {
			$this->handle_authorize_request();
		}
	}

	/**
	 * Resolve bearer tokens to a WordPress user.
	 *
	 * @param int|false $user_id Current resolved user ID.
	 *
	 * @return int|false
	 */
	public function determine_current_user_from_bearer( $user_id ) {
		$this->current_access_token         = '';
		$this->current_access_token_context = array();

		if ( ! self::is_enabled() || ! empty( $user_id ) || ! $this->is_current_request_for_mcp_server() ) {
			return $user_id;
		}

		$header = $this->get_authorization_header();

		if ( ! $header || ! preg_match( '/^\s*Bearer\s+(.+)\s*$/i', $header, $matches ) ) {
			return $user_id;
		}

		$token = trim( $matches[1] );
		$data  = $this->get_storage()->getAccessToken( $token );
		$this->current_access_token = $token;

		if ( empty( $data ) || empty( $data['user_id'] ) ) {
			return $user_id;
		}

		$this->current_access_token_context = $data;

		$this->log_event(
			'bearer_auth',
			'success',
			array(
				'user_id'           => (int) $data['user_id'],
				'server_id'         => isset( $data['server_id'] ) ? (int) $data['server_id'] : 0,
				'server_slug'       => $data['server_slug'] ?? '',
				'client_id'         => $data['client_id'] ?? '',
				'resource_url'      => $data['resource_url'] ?? '',
				'scope'             => $data['scope'] ?? '',
				'request_method'    => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'request_route'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				'access_token_hash' => $this->hash_value( $token ),
				'details'           => array(
					'message' => 'Bearer token resolved to a WordPress user.',
				),
			)
		);

		return (int) $data['user_id'];
	}

	/**
	 * Add a discovery header to 401 MCP responses.
	 *
	 * @param \WP_HTTP_Response $response REST response.
	 * @param \WP_REST_Server   $server   REST server.
	 * @param \WP_REST_Request  $request  REST request.
	 *
	 * @return \WP_HTTP_Response
	 */
	public function decorate_mcp_response( $response, $server, $request ) {
		unset( $server );

		if ( ! self::is_enabled() || ! $response instanceof \WP_HTTP_Response ) {
			return $response;
		}

		$server_row = $this->get_server_for_rest_route( $request->get_route() );

		if ( ! $server_row ) {
			return $response;
		}

		$status_code = (int) $response->get_status();
		$resource_url = self::get_resource_url_for_server( $server_row );
		$token_hash = $this->current_access_token ? $this->hash_value( $this->current_access_token ) : '';

		if ( 401 === $status_code ) {
			$metadata_url  = self::get_resource_metadata_url( $resource_url );
			$header_value  = sprintf( 'Bearer realm="%s", resource_metadata="%s"', 'AcrossAI MCP Manager', esc_url_raw( $metadata_url ) );
			$response->header( 'WWW-Authenticate', $header_value );

			if ( $this->current_access_token && empty( $this->current_access_token_context ) ) {
				$this->log_event(
					'bearer_auth',
					'failed',
					array(
						'server_id'         => (int) $server_row['id'],
						'server_slug'       => $server_row['server_slug'] ?? '',
						'resource_url'      => $resource_url,
						'request_method'    => $request->get_method(),
						'request_route'     => $request->get_route(),
						'response_code'     => $status_code,
						'access_token_hash' => $token_hash,
						'failure_code'      => 'invalid_or_expired_token',
						'details'           => array(
							'message' => 'Bearer token was presented but did not resolve to a valid stored token.',
						),
					)
				);
			}
		}

		$this->log_event(
			'mcp_request',
			$status_code >= 400 ? 'failed' : 'success',
			array(
				'user_id'           => isset( $this->current_access_token_context['user_id'] ) ? (int) $this->current_access_token_context['user_id'] : get_current_user_id(),
				'server_id'         => (int) $server_row['id'],
				'server_slug'       => $server_row['server_slug'] ?? '',
				'client_id'         => $this->current_access_token_context['client_id'] ?? '',
				'resource_url'      => $resource_url,
				'scope'             => $this->current_access_token_context['scope'] ?? '',
				'request_method'    => $request->get_method(),
				'request_route'     => $request->get_route(),
				'response_code'     => $status_code,
				'access_token_hash' => $token_hash,
				'details'           => array(
					'message' => $status_code >= 400 ? 'MCP request completed with an error response.' : 'MCP request completed successfully.',
				),
			)
		);

		return $response;
	}

	/**
	 * Render auth server metadata.
	 *
	 * @return void
	 */
	private function render_authorization_server_metadata() {
		if ( ! self::is_enabled() || ! self::has_configured_server() ) {
			$this->render_json(
				array(
					'error'   => 'temporarily_unavailable',
					'message' => __( 'Direct Claude Connectors mode is disabled or incomplete.', 'acrossai-mcp-manager' ),
				),
				503
			);
			return;
		}

		$this->log_event(
			'authorization_server_metadata',
			'success',
			array(
				'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'request_route'  => '/.well-known/oauth-authorization-server',
				'response_code'  => 200,
				'details'        => array(
					'message' => 'Authorization server metadata served.',
				),
			)
		);

		$this->render_json(
			array(
				'issuer'                                => self::get_issuer(),
				'authorization_endpoint'                => self::get_authorize_url(),
				'token_endpoint'                        => self::get_token_endpoint_url(),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'token_endpoint_auth_methods_supported' => $this->get_supported_token_auth_methods(),
				'code_challenge_methods_supported'      => array( 'S256' ),
				'scopes_supported'                      => Storage::SUPPORTED_SCOPES,
			)
		);
	}

	/**
	 * Render protected resource metadata.
	 *
	 * @return void
	 */
	private function render_protected_resource_metadata() {
		if ( ! self::is_enabled() ) {
			$this->render_json(
				array(
					'error'   => 'temporarily_unavailable',
					'message' => __( 'Direct Claude Connectors mode is disabled or incomplete.', 'acrossai-mcp-manager' ),
				),
				503
			);
			return;
		}

		$resource   = isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( $_GET['resource'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$server_row = $this->get_server_by_resource( $resource );

		if ( ! $server_row ) {
			$this->log_event(
				'resource_metadata',
				'failed',
				array(
					'resource_url'   => $resource,
					'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
					'request_route'  => '/.well-known/oauth-protected-resource',
					'response_code'  => 404,
					'failure_code'   => 'invalid_target',
					'details'        => array(
						'message' => 'Protected resource metadata requested for an unknown or disabled resource.',
					),
				)
			);
			$this->render_json(
				array(
					'error'   => 'invalid_target',
					'message' => __( 'Unknown or disabled MCP server resource.', 'acrossai-mcp-manager' ),
				),
				404
			);
			return;
		}

		if ( ! self::is_server_configured( $server_row ) ) {
			$this->log_event(
				'resource_metadata',
				'failed',
				array(
					'server_id'      => (int) $server_row['id'],
					'server_slug'    => $server_row['server_slug'] ?? '',
					'resource_url'   => $resource,
					'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
					'request_route'  => '/.well-known/oauth-protected-resource',
					'response_code'  => 503,
					'failure_code'   => 'server_not_configured',
					'details'        => array(
						'message' => 'Protected resource metadata requested for a server without connector OAuth settings.',
					),
				)
			);
			$this->render_json(
				array(
					'error'   => 'temporarily_unavailable',
					'message' => __( 'This MCP server does not have Claude connector OAuth settings yet.', 'acrossai-mcp-manager' ),
				),
				503
			);
			return;
		}

		$this->log_event(
			'resource_metadata',
			'success',
			array(
				'server_id'      => (int) $server_row['id'],
				'server_slug'    => $server_row['server_slug'] ?? '',
				'resource_url'   => $resource,
				'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'request_route'  => '/.well-known/oauth-protected-resource',
				'response_code'  => 200,
				'details'        => array(
					'message' => 'Protected resource metadata served.',
				),
			)
		);

		$this->render_json(
			array(
				'resource'                 => $resource,
				'authorization_servers'    => array( self::get_issuer() ),
				'bearer_methods_supported' => array( 'header' ),
				'scopes_supported'         => Storage::SUPPORTED_SCOPES,
			)
		);
	}

	/**
	 * Handle the browser authorization screen.
	 *
	 * @return void
	 */
	private function handle_authorize_request() {
		nocache_headers();

		if ( ! self::is_enabled() ) {
			$this->render_error_page(
				__( 'Claude Connectors are disabled.', 'acrossai-mcp-manager' ),
				__( 'Enable the experimental Claude Connectors setting first.', 'acrossai-mcp-manager' ),
				403
			);
		}

		$oauth_request  = BridgeRequest::createFromGlobals();
		$oauth_response = new BridgeResponse();
		$resource       = (string) $oauth_request->query( 'resource', $oauth_request->request( 'resource' ) );
		$server_row     = $this->get_server_by_resource( $resource );

		if ( ! $server_row ) {
			$this->set_oauth_error_response( $oauth_request, $oauth_response, 'invalid_target', __( 'The requested MCP server resource is not available.', 'acrossai-mcp-manager' ) );
			$this->send_oauth_response( $oauth_response );
		}

		if ( ! self::is_server_configured( $server_row ) ) {
			$this->set_oauth_error_response( $oauth_request, $oauth_response, 'temporarily_unavailable', __( 'This MCP server does not have Claude connector OAuth settings yet.', 'acrossai-mcp-manager' ) );
			$this->send_oauth_response( $oauth_response );
		}

		if ( ! is_user_logged_in() ) {
			$this->log_event(
				'authorize_request',
				'pending',
				array(
					'client_id'      => isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
					'resource_url'   => isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( $_GET['resource'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
					'scope'          => isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
					'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
					'request_route'  => self::AUTHORIZE_PATH,
					'response_code'  => 302,
					'details'        => array(
						'message' => 'Redirected to the WordPress login screen before OAuth approval.',
					),
				)
			);
			wp_safe_redirect(
				wp_login_url(
					add_query_arg(
						$this->get_allowed_authorize_query_args(), // phpcs:ignore WordPress.Security.NonceVerification
						self::get_authorize_url()
					)
				)
			);
			exit;
		}

		$server = $this->build_server( $server_row );

		if ( ! $server->validateAuthorizeRequest( $oauth_request, $oauth_response ) ) {
			$this->send_oauth_response( $oauth_response );
		}

		if ( ! $this->validate_resource_request( $oauth_request, $oauth_response ) ) {
			$this->send_oauth_response( $oauth_response );
		}

		if ( ! $this->validate_pkce_request( $oauth_request, $oauth_response ) ) {
			$this->send_oauth_response( $oauth_response );
		}

		$action = isset( $_GET['acrossai_action'] ) ? sanitize_key( $_GET['acrossai_action'] ) : 'authorize'; // phpcs:ignore WordPress.Security.NonceVerification

		if ( in_array( $action, array( 'approve', 'deny' ), true ) ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! wp_verify_nonce( $nonce, 'acrossai_mcp_connector_authorize' ) ) {
				wp_die( esc_html__( 'Invalid authorization request.', 'acrossai-mcp-manager' ) );
			}

			$this->log_event(
				'authorize_decision',
				'approve' === $action ? 'success' : 'failed',
				array(
					'user_id'        => get_current_user_id(),
					'server_id'      => $server_row ? (int) $server_row['id'] : 0,
					'server_slug'    => $server_row['server_slug'] ?? '',
					'client_id'      => (string) $oauth_request->query( 'client_id', $oauth_request->request( 'client_id' ) ),
					'resource_url'   => $resource,
					'scope'          => (string) $oauth_request->query( 'scope', $oauth_request->request( 'scope', 'mcp' ) ),
					'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
					'request_route'  => self::AUTHORIZE_PATH,
					'response_code'  => 'approve' === $action ? 302 : 302,
					'details'        => array(
						'message' => 'approve' === $action ? 'OAuth access approved by the logged-in user.' : 'OAuth access denied by the logged-in user.',
					),
				)
			);

			$server->handleAuthorizeRequest(
				$oauth_request,
				$oauth_response,
				'approve' === $action,
				get_current_user_id()
			);

			$this->send_oauth_response( $oauth_response );
		}

		$this->log_event(
			'authorize_request',
			'success',
			array(
				'user_id'        => get_current_user_id(),
				'server_id'      => $server_row ? (int) $server_row['id'] : 0,
				'server_slug'    => $server_row['server_slug'] ?? '',
				'client_id'      => (string) $oauth_request->query( 'client_id', $oauth_request->request( 'client_id' ) ),
				'resource_url'   => $resource,
				'scope'          => (string) $oauth_request->query( 'scope', $oauth_request->request( 'scope', 'mcp' ) ),
				'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
				'request_route'  => self::AUTHORIZE_PATH,
				'response_code'  => 200,
				'details'        => array(
					'message' => 'OAuth consent screen displayed.',
				),
			)
		);

		$this->render_authorize_page( $oauth_request, $server_row, $resource );
	}

	/**
	 * Validate the requested MCP resource.
	 *
	 * @param mixed $request  OAuth request.
	 * @param mixed $response OAuth response.
	 *
	 * @return bool
	 */
	private function validate_resource_request( $request, $response ) {
		$resource = $request->query( 'resource', $request->request( 'resource' ) );

		if ( empty( $resource ) ) {
			$this->set_oauth_error_response( $request, $response, 'invalid_target', __( 'A valid MCP server resource URL is required.', 'acrossai-mcp-manager' ) );
			return false;
		}

		if ( ! $this->get_server_by_resource( $resource ) ) {
			$this->set_oauth_error_response( $request, $response, 'invalid_target', __( 'The requested MCP server resource is not available.', 'acrossai-mcp-manager' ) );
			return false;
		}

		return true;
	}

	/**
	 * Validate PKCE for public clients.
	 *
	 * @param mixed $request  OAuth request.
	 * @param mixed $response OAuth response.
	 *
	 * @return bool
	 */
	private function validate_pkce_request( $request, $response ) {
		$client_id = (string) $request->query( 'client_id', $request->request( 'client_id' ) );

		if ( ! $this->get_storage()->isPublicClient( $client_id ) ) {
			return true;
		}

		$code_challenge = (string) $request->query( 'code_challenge', $request->request( 'code_challenge' ) );
		$method         = (string) $request->query( 'code_challenge_method', $request->request( 'code_challenge_method', 'S256' ) );

		if ( '' === $code_challenge ) {
			$this->set_oauth_error_response( $request, $response, 'invalid_request', __( 'Public clients must send a PKCE code_challenge.', 'acrossai-mcp-manager' ) );
			return false;
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $code_challenge ) ) {
			$this->set_oauth_error_response( $request, $response, 'invalid_request', __( 'The PKCE code_challenge is invalid.', 'acrossai-mcp-manager' ) );
			return false;
		}

		if ( 'S256' !== $method ) {
			$this->set_oauth_error_response( $request, $response, 'invalid_request', __( 'Unsupported PKCE code_challenge_method.', 'acrossai-mcp-manager' ) );
			return false;
		}

		return true;
	}

	/**
	 * Set an OAuth-style error response, redirecting when possible.
	 *
	 * @param mixed  $request             OAuth request.
	 * @param mixed  $response            OAuth response.
	 * @param string           $error               Error code.
	 * @param string           $error_description   Human-readable message.
	 *
	 * @return void
	 */
	private function set_oauth_error_response( $request, $response, $error, $error_description ) {
		$client_id    = (string) $request->query( 'client_id', $request->request( 'client_id' ) );
		$redirect_uri = (string) $request->query( 'redirect_uri', $request->request( 'redirect_uri' ) );
		$state        = (string) $request->query( 'state', $request->request( 'state' ) );
		$redirect_uri = $this->get_valid_error_redirect_uri( $client_id, $redirect_uri );

		$this->log_event(
			'authorize_request',
			'failed',
			array(
				'client_id'      => $client_id,
				'resource_url'   => (string) $request->query( 'resource', $request->request( 'resource' ) ),
				'scope'          => (string) $request->query( 'scope', $request->request( 'scope', '' ) ),
				'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'request_route'  => self::AUTHORIZE_PATH,
				'response_code'  => 400,
				'failure_code'   => sanitize_key( $error ),
				'details'        => array(
					'message' => $error_description,
				),
			)
		);

		if ( '' !== $redirect_uri ) {
			$response->setRedirect( 302, $redirect_uri, $state ?: null, $error, $error_description );
			return;
		}

		$response->setError( 400, $error, $error_description );
	}

	/**
	 * Render the authorize consent page.
	 *
	 * @param mixed  $request    OAuth request.
	 * @param array           $server_row Matched MCP server row.
	 * @param string          $resource   Requested resource URL.
	 *
	 * @return void
	 */
	private function render_authorize_page( $request, array $server_row, $resource ) {
		$nonce       = wp_create_nonce( 'acrossai_mcp_connector_authorize' );
		$query_args  = $this->get_allowed_authorize_query_args(); // phpcs:ignore WordPress.Security.NonceVerification
		$approve_url = add_query_arg(
			array_merge(
				$query_args,
				array(
					'acrossai_action' => 'approve',
					'_wpnonce'        => $nonce,
				)
			),
			self::get_authorize_url()
		);
		$deny_url    = add_query_arg(
			array_merge(
				$query_args,
				array(
					'acrossai_action' => 'deny',
					'_wpnonce'        => $nonce,
				)
			),
			self::get_authorize_url()
		);
		$client_id   = (string) $request->query( 'client_id', $request->request( 'client_id' ) );
		$scope       = (string) $request->query( 'scope', $request->request( 'scope', 'mcp' ) );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex, nofollow">
			<title><?php esc_html_e( 'Authorize Claude Connector', 'acrossai-mcp-manager' ); ?></title>
			<style>
				*,*::before,*::after{box-sizing:border-box}
				body{margin:0;padding:40px 20px;background:#f0f0f1;color:#1d2327;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,sans-serif}
				.wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.13);padding:32px}
				h1{margin:0 0 8px;font-size:20px}
				p{margin:0 0 16px}
				table{width:100%;border-collapse:collapse;margin:16px 0}
				th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #f0f0f1;vertical-align:top}
				th{width:34%;color:#646970;font-weight:500}
				code{background:#f6f7f7;padding:2px 6px;border-radius:3px;word-break:break-all}
				.notice{border-left:4px solid #dba617;background:#fcf9e8;padding:10px 14px;margin:20px 0;border-radius:0 3px 3px 0}
				.actions{display:flex;gap:10px;margin-top:24px}
				.btn{display:inline-block;padding:8px 18px;border-radius:3px;border:1px solid #c3c4c7;background:#f6f7f7;color:#1d2327;text-decoration:none}
				.btn-primary{background:#2271b1;border-color:#2271b1;color:#fff}
			</style>
		</head>
		<body>
		<div class="wrap">
			<h1><?php esc_html_e( 'Authorize Claude Connector', 'acrossai-mcp-manager' ); ?></h1>
			<p><?php esc_html_e( 'Claude wants to connect to this MCP server using your current WordPress account.', 'acrossai-mcp-manager' ); ?></p>

			<table>
				<tr>
					<th><?php esc_html_e( 'Server', 'acrossai-mcp-manager' ); ?></th>
					<td><strong><?php echo esc_html( $server_row['server_name'] ); ?></strong></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Resource URL', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $resource ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Client ID', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $client_id ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Scope', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $scope ?: 'mcp' ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Logged in as', 'acrossai-mcp-manager' ); ?></th>
					<td><strong><?php echo esc_html( wp_get_current_user()->user_login ); ?></strong></td>
				</tr>
			</table>

			<div class="notice">
				<p><?php esc_html_e( 'Approving access signs Claude in as your current WordPress user for this site. Per-server Access Control is still enforced on every MCP request, so approval does not bypass this server’s access rules.', 'acrossai-mcp-manager' ); ?></p>
			</div>

			<div class="actions">
				<a class="btn btn-primary" href="<?php echo esc_url( $approve_url ); ?>"><?php esc_html_e( 'Approve access', 'acrossai-mcp-manager' ); ?></a>
				<a class="btn" href="<?php echo esc_url( $deny_url ); ?>"><?php esc_html_e( 'Deny', 'acrossai-mcp-manager' ); ?></a>
			</div>
		</div>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Render a standalone error page.
	 *
	 * @param string $title   Error title.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status.
	 *
	 * @return void
	 */
	private function render_error_page( $title, $message, $status ) {
		status_header( $status );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex, nofollow">
			<title><?php echo esc_html( $title ); ?></title>
		</head>
		<body>
			<div style="max-width:560px;margin:40px auto;padding:24px;font:14px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
				<h1><?php echo esc_html( $title ); ?></h1>
				<p><?php echo esc_html( $message ); ?></p>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Send an OAuth response and end execution.
	 *
	 * @param BridgeResponse $response OAuth response.
	 *
	 * @return void
	 */
	private function send_oauth_response( BridgeResponse $response ) {
		$response->send();
		exit;
	}

	/**
	 * Render a JSON payload and end execution.
	 *
	 * @param array $payload JSON payload.
	 * @param int   $status  HTTP status.
	 *
	 * @return void
	 */
	private function render_json( array $payload, $status = 200 ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Build the OAuth server.
	 *
	 * @return \OAuth2\Server
	 */
	private function build_server( array $server_row ) {
		$storage         = $this->get_storage( $server_row );
		$scope_util      = new \OAuth2\Scope( $storage );
		$response_types  = array(
			'code' => new AuthorizationCodeResponseType(
				$storage,
				array(
					'auth_code_lifetime' => 300,
				)
			),
		);
		$grant_types     = array(
			'authorization_code' => new \OAuth2\GrantType\AuthorizationCode( $storage ),
			'refresh_token'      => new \OAuth2\GrantType\RefreshToken( $storage ),
		);
		$server          = new \OAuth2\Server(
			array(
				'client_credentials' => $storage,
				'client'             => $storage,
				'access_token'       => $storage,
				'authorization_code' => $storage,
				'refresh_token'      => $storage,
				'scope'              => $storage,
			),
			array(
				'access_lifetime'                => HOUR_IN_SECONDS,
				'always_issue_new_refresh_token' => true,
				'unset_refresh_token_after_use' => true,
				'allow_public_clients'          => true,
				'allow_credentials_in_request_body' => true,
				'enforce_state'                 => true,
				'require_exact_redirect_uri'    => true,
			),
			$grant_types,
			$response_types,
			null,
			$scope_util
		);

		$server->setAuthorizeController(
			new AuthorizeController(
				$storage,
				$response_types,
				array(
					'enforce_state'              => true,
					'require_exact_redirect_uri' => true,
				),
				$scope_util
			)
		);

		return $server;
	}

	/**
	 * Return context for the current token exchange request.
	 *
	 * @param BridgeRequest $request OAuth bridge request.
	 *
	 * @return array<string,mixed>
	 */
	private function get_token_exchange_context( BridgeRequest $request ) {
		$grant_type = (string) $request->request( 'grant_type' );
		$context    = array(
			'client_id' => (string) $request->request( 'client_id' ),
		);

		if ( 'authorization_code' === $grant_type ) {
			$auth_code = $this->get_storage()->getAuthorizationCode( (string) $request->request( 'code' ) );
			if ( is_array( $auth_code ) ) {
				$context = array_merge( $context, $auth_code );
			}
		} elseif ( 'refresh_token' === $grant_type ) {
			$refresh_token = $this->get_storage()->getRefreshToken( (string) $request->request( 'refresh_token' ) );
			if ( is_array( $refresh_token ) ) {
				$context = array_merge( $context, $refresh_token );
			}
		}

		if ( ! empty( $context['resource_url'] ) && empty( $context['server_id'] ) ) {
			$server_row = $this->get_server_by_resource( (string) $context['resource_url'] );
			if ( $server_row ) {
				$context['server_id']   = (int) $server_row['id'];
				$context['server_slug'] = $server_row['server_slug'] ?? '';
			}
		}

		return $context;
	}

	/**
	 * Attach server/resource context to newly issued tokens.
	 *
	 * @param array<string,mixed> $payload  Token response payload.
	 * @param array<string,mixed> $context  Existing exchange context.
	 *
	 * @return void
	 */
	private function attach_token_contexts( array $payload, array $context ) {
		if ( empty( $payload['access_token'] ) ) {
			return;
		}

		$this->get_storage()->attach_access_token_context( (string) $payload['access_token'], $context );

		if ( ! empty( $payload['refresh_token'] ) ) {
			$this->get_storage()->attach_refresh_token_context( (string) $payload['refresh_token'], $context );
		}
	}

	/**
	 * Record an audit event in the connector log table.
	 *
	 * @param string $event_type Event type.
	 * @param string $status     Event status.
	 * @param array  $context    Event context.
	 *
	 * @return void
	 */
	private function log_event( $event_type, $status, array $context = array() ) {
		ConnectorAuditLogTable::record_event(
			array(
				'server_id'               => isset( $context['server_id'] ) ? (int) $context['server_id'] : 0,
				'server_slug'             => $context['server_slug'] ?? '',
				'user_id'                 => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
				'client_id'               => $context['client_id'] ?? '',
				'event_type'              => $event_type,
				'status'                  => $status,
				'resource_url'            => $context['resource_url'] ?? '',
				'scope'                   => $context['scope'] ?? '',
				'request_method'          => $context['request_method'] ?? ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ),
				'request_route'           => $context['request_route'] ?? ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ),
				'response_code'           => isset( $context['response_code'] ) ? (int) $context['response_code'] : 0,
				'failure_code'            => $context['failure_code'] ?? '',
				'authorization_code_hash' => $context['authorization_code_hash'] ?? '',
				'access_token_hash'       => $context['access_token_hash'] ?? '',
				'ip_address'              => $this->get_request_ip(),
				'user_agent'              => $this->get_user_agent(),
				'details'                 => $context['details'] ?? array(),
			)
		);
	}

	/**
	 * Log per-server access-control denials for OAuth-backed MCP requests.
	 *
	 * @param int    $user_id      Requesting user ID.
	 * @param array  $row          Server row.
	 * @param string $ns           Namespace.
	 * @param string $server_route Server route.
	 *
	 * @return void
	 */
	public function log_access_denied_event( $user_id, $row, $ns, $server_route ) {
		unset( $ns, $server_route );

		$this->log_event(
			'access_control',
			'failed',
			array(
				'user_id'           => (int) $user_id,
				'server_id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'server_slug'       => $row['server_slug'] ?? '',
				'client_id'         => $this->current_access_token_context['client_id'] ?? '',
				'resource_url'      => self::get_resource_url_for_server( $row ),
				'scope'             => $this->current_access_token_context['scope'] ?? '',
				'request_method'    => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'request_route'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				'response_code'     => $user_id ? 403 : 401,
				'access_token_hash' => $this->current_access_token ? $this->hash_value( $this->current_access_token ) : '',
				'failure_code'      => 'access_denied',
				'details'           => array(
					'message' => 'Access Control denied the MCP request.',
				),
			)
		);
	}

	/**
	 * Hash a secret value for audit storage.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private function hash_value( $value ) {
		return '' === (string) $value ? '' : hash( 'sha256', (string) $value );
	}

	/**
	 * Return the request IP address.
	 *
	 * @return string
	 */
	private function get_request_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Return the current request user agent.
	 *
	 * @return string
	 */
	private function get_user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	/**
	 * Return the storage singleton.
	 *
	 * @return Storage
	 */
	private function get_storage( $server_row = null ) {
		if ( null === $this->storage ) {
			$this->storage = new Storage();
		}

		$this->storage->set_server_context( $server_row );

		return $this->storage;
	}

	/**
	 * Return the raw Authorization header when present.
	 *
	 * @return string
	 */
	private function get_authorization_header() {
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = (array) apache_request_headers();
			foreach ( $headers as $name => $value ) {
				if ( 'authorization' === strtolower( $name ) ) {
					return (string) $value;
				}
			}
		}

		return '';
	}

	/**
	 * Return the token endpoint auth methods supported by the configured client.
	 *
	 * @return string[]
	 */
	private function get_supported_token_auth_methods( $server_row = null ) {
		if ( is_array( $server_row ) ) {
			$config = self::get_server_connector_config( $server_row );

			return '' === $config['client_secret']
				? array( 'none' )
				: array( 'client_secret_post', 'client_secret_basic' );
		}

		$methods = array();

		foreach ( MCPServerTable::get_all() as $row ) {
			if ( ! self::is_server_configured( $row ) ) {
				continue;
			}

			$methods = array_merge( $methods, $this->get_supported_token_auth_methods( $row ) );
		}

		$methods = array_values( array_unique( $methods ) );

		return empty( $methods ) ? array( 'none' ) : $methods;
	}

	/**
	 * Resolve the target server for a token request.
	 *
	 * @param BridgeRequest       $request OAuth request.
	 * @param array<string,mixed> $context Current token context.
	 *
	 * @return array<string,mixed>|null
	 */
	private function resolve_server_for_token_request( BridgeRequest $request, array $context ) {
		if ( ! empty( $context['resource_url'] ) ) {
			$server_row = $this->get_server_by_resource( (string) $context['resource_url'] );
			if ( $server_row ) {
				return $server_row;
			}
		}

		$client_id = $context['client_id'] ?? (string) $request->request( 'client_id' );

		return $this->get_server_by_client_id( (string) $client_id );
	}

	/**
	 * Return the whitelisted query args allowed on the authorize screen.
	 *
	 * @return array<string,string>
	 */
	private function get_allowed_authorize_query_args() {
		$allowed = array(
			'response_type',
			'client_id',
			'redirect_uri',
			'state',
			'scope',
			'resource',
			'code_challenge',
			'code_challenge_method',
			'acrossai_action',
			'_wpnonce',
		);
		$args = array();

		foreach ( $allowed as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}

		return $args;
	}

	/**
	 * Return whether the current request URI targets a known MCP server route.
	 *
	 * @return bool
	 */
	private function is_current_request_for_mcp_server() {
		$uri_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );

		if ( ! is_string( $uri_path ) || '' === $uri_path ) {
			return false;
		}

		foreach ( MCPServerTable::get_all() as $row ) {
			if ( empty( $row['is_enabled'] ) ) {
				continue;
			}

			$resource_path = wp_parse_url( self::get_resource_url_for_server( $row ), PHP_URL_PATH );
			if ( is_string( $resource_path ) && 0 === strpos( $uri_path, $resource_path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find a server row by its MCP resource URL.
	 *
	 * @param string $resource Resource URL.
	 *
	 * @return array|null
	 */
	private function get_server_by_resource( $resource ) {
		$resource = untrailingslashit( (string) $resource );

		if ( '' === $resource ) {
			return null;
		}

		foreach ( MCPServerTable::get_all() as $row ) {
			if ( empty( $row['is_enabled'] ) ) {
				continue;
			}

			if ( untrailingslashit( $this->get_resource_url_for_server( $row ) ) === $resource ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Find a server row by its configured Claude connector client ID.
	 *
	 * @param string $client_id OAuth client ID.
	 *
	 * @return array<string,mixed>|null
	 */
	private function get_server_by_client_id( $client_id ) {
		$client_id = (string) $client_id;

		if ( '' === $client_id ) {
			return null;
		}

		$matched_server = null;

		foreach ( MCPServerTable::get_all() as $row ) {
			if ( empty( $row['is_enabled'] ) ) {
				continue;
			}

			if ( $client_id !== (string) ( $row['claude_connector_client_id'] ?? '' ) ) {
				continue;
			}

			if ( null !== $matched_server ) {
				return null;
			}

			$matched_server = $row;
		}

		return $matched_server;
	}

	/**
	 * Return a safe redirect URI for OAuth error redirects.
	 *
	 * @param string $client_id            OAuth client ID.
	 * @param string $requested_redirect   Requested redirect URI.
	 *
	 * @return string
	 */
	private function get_valid_error_redirect_uri( $client_id, $requested_redirect ) {
		$client = $this->get_storage()->getClientDetails( (string) $client_id );

		if ( ! $client || empty( $client['redirect_uri'] ) ) {
			return '';
		}

		$registered_redirect = (string) $client['redirect_uri'];
		$requested_redirect  = (string) $requested_redirect;

		if ( '' === $requested_redirect ) {
			return $registered_redirect;
		}

		return hash_equals( $registered_redirect, $requested_redirect ) ? $registered_redirect : '';
	}

	/**
	 * Match a REST request route to a known server row.
	 *
	 * @param string $route REST request route.
	 *
	 * @return array|null
	 */
	private function get_server_for_rest_route( $route ) {
		foreach ( MCPServerTable::get_all() as $row ) {
			if ( empty( $row['is_enabled'] ) ) {
				continue;
			}

			$namespace = ! empty( $row['server_route_namespace'] ) ? $row['server_route_namespace'] : 'mcp';
			$server_route = ! empty( $row['server_route'] ) ? $row['server_route'] : $row['server_slug'];
			$expected_prefix = '/' . trim( $namespace, '/' ) . '/' . ltrim( $server_route, '/' );

			if ( 0 === strpos( $route, $expected_prefix ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Return the MCP URL for a server row.
	 *
	 * @param array $server_row Server row.
	 *
	 * @return string
	 */
	public static function get_resource_url_for_server( array $server_row ) {
		$namespace = ! empty( $server_row['server_route_namespace'] ) ? $server_row['server_route_namespace'] : 'mcp';
		$route     = ! empty( $server_row['server_route'] ) ? $server_row['server_route'] : $server_row['server_slug'];

		return rest_url( trim( $namespace, '/' ) . '/' . ltrim( $route, '/' ) );
	}
}
