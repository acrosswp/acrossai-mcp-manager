<?php
/**
 * Access Control Manager.
 *
 * Central registry for all access-control providers and the gatekeeper
 * that enforces per-server rules on every MCP REST request.
 *
 * Provider registry
 * -----------------
 * Providers are registered via the `acrossai_mcp_access_control_providers`
 * filter (fired once on `init` at priority 5). At launch only the built-in
 * `WpRoleProvider` is registered. Third-party code can add more:
 *
 *   add_filter( 'acrossai_mcp_access_control_providers', function( $providers ) {
 *       $providers[] = new \My\Plugin\MyMcpAccessProvider();
 *       return $providers;
 *   } );
 *
 * Enforcement
 * -----------
 * The manager hooks `rest_pre_dispatch` at priority 10. For every incoming
 * REST request it checks whether the route belongs to a registered MCP server
 * and, if so, loads that server's access_control config from the DB and runs
 * the appropriate provider's `user_has_access()` method.
 *
 * Access logic
 * ------------
 *   1. If the server has no access_control config (type = 'everyone') → allow.
 *   2. If the current user is an Administrator             → always allow.
 *   3. If the user is not logged in                        → deny (401).
 *   4. If no provider is found for the configured type     → deny (403).
 *   5. Delegate to the provider's `user_has_access()`     → allow or deny (403).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage AccessControl
 * @since 1.4.0
 */

namespace ACROSSAI_MCP_MANAGER\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;

/**
 * Manages provider registration and enforces per-server access rules.
 *
 * @since 1.4.0
 */
class AccessControlManager {

	/**
	 * The option key used when access control is disabled (allow everyone).
	 */
	const TYPE_EVERYONE = 'everyone';

	/**
	 * Registered provider instances, keyed by provider ID.
	 *
	 * @var array<string, AbstractProvider>
	 */
	private $providers = array();

	/**
	 * Constructor — loads providers and registers the enforcement hook.
	 *
	 * Providers are resolved after `init` so that third-party plugins have had
	 * a chance to register their own providers via the filter.
	 *
	 * @since 1.4.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'load_providers' ), 5 );
		add_filter( 'rest_pre_dispatch', array( $this, 'enforce_access' ), 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Provider registry
	// -------------------------------------------------------------------------

	/**
	 * Resolve all enabled providers and store them in `$this->providers`.
	 *
	 * Hooked on `init` at priority 5.  Fires the
	 * `acrossai_mcp_access_control_providers` filter so external code can add
	 * custom providers.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function load_providers(): void {
		$default_providers = array(
			new WpRoleProvider(),
		);

		/**
		 * Filter the list of registered access-control providers.
		 *
		 * Each element must be an instance of
		 * {@see ACROSSAI_MCP_MANAGER\AccessControl\AbstractProvider}.
		 *
		 * @since 1.4.0
		 *
		 * @param AbstractProvider[] $providers Default provider list.
		 */
		$providers = apply_filters( 'acrossai_mcp_access_control_providers', $default_providers );

		foreach ( $providers as $provider ) {
			if ( $provider instanceof AbstractProvider ) {
				$this->providers[ $provider->get_id() ] = $provider;
			}
		}
	}

	/**
	 * Return all registered providers indexed by their ID.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, AbstractProvider>
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Return a single provider by its ID, or null if not found.
	 *
	 * @since 1.4.0
	 *
	 * @param string $provider_id Provider identifier (e.g. `'wp_role'`).
	 *
	 * @return AbstractProvider|null
	 */
	public function get_provider( string $provider_id ): ?AbstractProvider {
		return $this->providers[ $provider_id ] ?? null;
	}

	// -------------------------------------------------------------------------
	// Enforcement
	// -------------------------------------------------------------------------

	/**
	 * Intercept every REST request and enforce access control for MCP routes.
	 *
	 * Hooked on `rest_pre_dispatch` at priority 10. Non-MCP routes pass through
	 * untouched. For MCP routes the method:
	 *   1. Finds the matching server row by comparing the request route against
	 *      each server's stored `{namespace}/{route}` pattern.
	 *   2. Reads the server's `access_control` JSON.
	 *   3. Applies the access logic described in this class's docblock.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed            $result  Current dispatch result (null = not handled).
	 * @param \WP_REST_Server   $server  REST server instance.
	 * @param \WP_REST_Request  $request Incoming REST request.
	 *
	 * @return mixed Original `$result` when the request is allowed; a
	 *               `WP_Error` object to short-circuit dispatch when denied.
	 */
	public function enforce_access( $result, $server, \WP_REST_Request $request ) {
		// Only inspect if nothing has already handled this request.
		if ( null !== $result ) {
			return $result;
		}

		$route = ltrim( $request->get_route(), '/' );

		// Find the DB server whose REST path matches this route.
		$server_row = $this->match_server_by_route( $route );

		if ( null === $server_row ) {
			// Not an MCP server route — do not interfere.
			return $result;
		}

		// Parse stored access control config.
		$ac_config = $this->parse_access_control( $server_row['access_control'] ?? '' );

		// Type 'everyone' (or empty config) → always allow.
		if ( self::TYPE_EVERYONE === $ac_config['type'] || empty( $ac_config['type'] ) ) {
			return $result;
		}

		$current_user_id = get_current_user_id();

		// Administrators bypass all access rules.
		if ( $current_user_id && user_can( $current_user_id, 'manage_options' ) ) {
			return $result;
		}

		// Unauthenticated user — deny with 401.
		if ( ! $current_user_id ) {
			return new \WP_Error(
				'acrossai_mcp_not_authenticated',
				__( 'Authentication required to access this MCP server.', 'acrossai-mcp-manager' ),
				array( 'status' => 401 )
			);
		}

		// Find the provider for the configured type.
		$provider = $this->get_provider( $ac_config['type'] );

		if ( null === $provider ) {
			// Unknown provider — fail closed.
			return new \WP_Error(
				'acrossai_mcp_unknown_provider',
				__( 'This MCP server uses an unsupported access control type.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		$selected_options = $ac_config['options'] ?? array();

		if ( $provider->user_has_access( $current_user_id, (array) $selected_options ) ) {
			return $result;
		}

		/**
		 * Fires when an MCP server access control check fails.
		 *
		 * @since 1.4.0
		 *
		 * @param int    $current_user_id  ID of the requesting user.
		 * @param array  $server_row       The DB row of the server being accessed.
		 * @param array  $ac_config        Parsed access control config.
		 */
		do_action( 'acrossai_mcp_access_denied', $current_user_id, $server_row, $ac_config );

		return new \WP_Error(
			'acrossai_mcp_access_denied',
			__( 'You do not have permission to access this MCP server.', 'acrossai-mcp-manager' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Determine whether the current user can access a given server.
	 *
	 * Convenience method for use outside the REST context (e.g. in the admin UI
	 * or WP-CLI command) to preview what the current user would see.
	 *
	 * @since 1.4.0
	 *
	 * @param array $server_row DB row from {@see MCPServerTable::get_by_id()}.
	 *
	 * @return bool True when access is granted.
	 */
	public function current_user_can_access( array $server_row ): bool {
		$ac_config = $this->parse_access_control( $server_row['access_control'] ?? '' );

		if ( self::TYPE_EVERYONE === $ac_config['type'] || empty( $ac_config['type'] ) ) {
			return true;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$provider = $this->get_provider( $ac_config['type'] );

		if ( null === $provider ) {
			return false;
		}

		return $provider->user_has_access( $user_id, (array) ( $ac_config['options'] ?? array() ) );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Find the DB server row whose REST path matches the given route string.
	 *
	 * Compares `{namespace}/{route}` from every enabled server row against the
	 * beginning of the incoming request route (to tolerate sub-paths like
	 * `/mcp/mcp-adapter-default-server/sse`).
	 *
	 * Returns the first matching row, or null when no server matches.
	 *
	 * @since 1.4.0
	 *
	 * @param string $route Request route with leading slash removed.
	 *
	 * @return array|null Server DB row or null.
	 */
	private function match_server_by_route( string $route ): ?array {
		$servers = MCPServerTable::get_all();

		foreach ( $servers as $row ) {
			$namespace   = ! empty( $row['server_route_namespace'] ) ? $row['server_route_namespace'] : 'mcp';
			$server_route = ! empty( $row['server_route'] ) ? $row['server_route'] : ( $row['server_slug'] ?? '' );
			$expected    = ltrim( $namespace . '/' . $server_route, '/' );

			// Match exact path or any sub-path beneath it.
			if ( $route === $expected || 0 === strpos( $route, $expected . '/' ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Decode the JSON access_control column value into a normalised array.
	 *
	 * Expected JSON structure:
	 * ```json
	 * { "type": "wp_role", "options": ["editor", "author"] }
	 * ```
	 *
	 * Falls back to `type = 'everyone'` for empty or malformed values so that
	 * pre-existing servers without access control are never accidentally locked.
	 *
	 * @since 1.4.0
	 *
	 * @param string $raw Raw JSON string from the DB column.
	 *
	 * @return array{type: string, options: string[]} Normalised config.
	 */
	private function parse_access_control( string $raw ): array {
		$defaults = array(
			'type'    => self::TYPE_EVERYONE,
			'options' => array(),
		);

		if ( '' === $raw ) {
			return $defaults;
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return $defaults;
		}

		return array(
			'type'    => isset( $decoded['type'] ) && is_string( $decoded['type'] )
				? sanitize_key( $decoded['type'] )
				: self::TYPE_EVERYONE,
			'options' => isset( $decoded['options'] ) && is_array( $decoded['options'] )
				? array_map( 'sanitize_key', $decoded['options'] )
				: array(),
		);
	}
}
