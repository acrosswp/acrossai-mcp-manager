<?php
/**
 * Access Control Manager.
 *
 * Central registry for all access-control providers and the gatekeeper that
 * enforces per-resource rules on every WordPress REST request that matches a
 * registered resource.
 *
 * Usage (in your plugin bootstrap)
 * ---------------------------------
 *   $manager = new AccessControlManager(
 *       // Server fetcher: callable that returns an array of resource rows.
 *       // Each row must have: server_route_namespace, server_route, server_slug,
 *       // and access_control (JSON string).
 *       function() { return MCPServerTable::get_all(); }
 *   );
 *
 * Provider registry
 * -----------------
 * Providers are registered via the WordPress filter named in the
 * $providers_filter constructor argument (default: 'wpb_access_control_providers').
 * The filter fires on init at priority 5 (or immediately if init has passed).
 *
 *   add_filter( 'wpb_access_control_providers', function( $providers ) {
 *       $providers[] = new \My\Plugin\MyProvider();
 *       return $providers;
 *   } );
 *
 * Enforcement
 * -----------
 * The manager hooks rest_pre_dispatch at priority 10. For every request it:
 *   1. Checks whether the route belongs to a registered resource row.
 *   2. Reads that row's access_control JSON config.
 *   3. Applies the access hierarchy below.
 *
 * Access hierarchy
 * ----------------
 *   1. access_control empty or type = 'everyone' → allow.
 *   2. User has manage_options capability (admin)  → always allow.
 *   3. User not authenticated                      → deny (401).
 *   4. No provider found for the configured type   → deny (403).
 *   5. provider->user_has_access()                 → allow or deny (403).
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages provider registration and enforces per-resource access rules.
 *
 * @since 1.0.0
 */
class AccessControlManager {

	/**
	 * Special type value meaning "no restriction — allow everyone".
	 */
	const TYPE_EVERYONE = 'everyone';

	/**
	 * Callable that returns an array of resource rows when invoked.
	 *
	 * Each row must be an associative array with at minimum:
	 *   'server_route_namespace' (string) — e.g. 'mcp'
	 *   'server_route'          (string) — e.g. 'mcp-adapter-default-server'
	 *   'server_slug'           (string) — fallback route identifier
	 *   'access_control'        (string) — JSON or empty string
	 *
	 * @var callable(): array<int, array<string, mixed>>
	 */
	private $server_fetcher;

	/**
	 * WordPress filter tag used to collect provider instances.
	 *
	 * @var string
	 */
	private $providers_filter;

	/**
	 * Registered provider instances, keyed by provider ID.
	 *
	 * @var array<string, AbstractProvider>
	 */
	private $providers = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $server_fetcher   Callable that returns resource rows.
	 *                                   Signature: (): array<int, array<string,mixed>>
	 * @param string   $providers_filter WordPress filter tag for provider registration.
	 *                                   Defaults to 'wpb_access_control_providers'.
	 */
	public function __construct( callable $server_fetcher, string $providers_filter = 'wpb_access_control_providers' ) {
		$this->server_fetcher   = $server_fetcher;
		$this->providers_filter = $providers_filter;

		// Load providers immediately if init has already fired (e.g. during admin
		// page rendering), otherwise hook for the normal request lifecycle.
		if ( did_action( 'init' ) ) {
			$this->load_providers();
		} else {
			add_action( 'init', array( $this, 'load_providers' ), 5 );
		}

		add_filter( 'rest_pre_dispatch', array( $this, 'enforce_access' ), 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Provider registry
	// -------------------------------------------------------------------------

	/**
	 * Resolve all enabled providers and cache them in $this->providers.
	 *
	 * Fires the configured filter so third-party code can register providers.
	 * This method is idempotent — calling it more than once rebuilds the list.
	 *
	 * @since 1.0.0
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
		 * Each element must be an instance of AbstractProvider.
		 *
		 * @since 1.0.0
		 *
		 * @param AbstractProvider[] $providers Default providers list.
		 */
		$providers = (array) apply_filters( $this->providers_filter, $default_providers );

		$this->providers = array();
		foreach ( $providers as $provider ) {
			if ( $provider instanceof AbstractProvider ) {
				$this->providers[ $provider->get_id() ] = $provider;
			}
		}
	}

	/**
	 * Return all registered providers indexed by their ID.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, AbstractProvider>
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Return a single provider by its ID, or null if not found.
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider_id Provider identifier (e.g. 'wp_role').
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
	 * Intercept REST requests and enforce access control for matching routes.
	 *
	 * Hooked on rest_pre_dispatch at priority 10. Non-matching routes pass
	 * through untouched.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed            $result  Current dispatch result (null = not handled yet).
	 * @param \WP_REST_Server   $server  REST server instance.
	 * @param \WP_REST_Request  $request Incoming REST request.
	 *
	 * @return mixed Original $result when allowed; WP_Error when denied.
	 */
	public function enforce_access( $result, $server, \WP_REST_Request $request ) {
		// Only inspect if nothing has already short-circuited this request.
		if ( null !== $result ) {
			return $result;
		}

		$route = ltrim( $request->get_route(), '/' );

		$resource = $this->match_resource_by_route( $route );

		if ( null === $resource ) {
			return $result;
		}

		$ac_config = $this->parse_access_control( $resource['access_control'] ?? '' );

		// Type 'everyone' or empty → allow.
		if ( self::TYPE_EVERYONE === $ac_config['type'] || '' === $ac_config['type'] ) {
			return $result;
		}

		$current_user_id = get_current_user_id();

		// Administrators bypass all access rules.
		if ( $current_user_id && user_can( $current_user_id, 'manage_options' ) ) {
			return $result;
		}

		// Unauthenticated — deny with 401.
		if ( ! $current_user_id ) {
			return new \WP_Error(
				'wpb_ac_not_authenticated',
				__( 'Authentication required to access this resource.', 'wpb-access-control' ),
				array( 'status' => 401 )
			);
		}

		$provider = $this->get_provider( $ac_config['type'] );

		if ( null === $provider ) {
			return new \WP_Error(
				'wpb_ac_unknown_provider',
				__( 'This resource uses an unsupported access control type.', 'wpb-access-control' ),
				array( 'status' => 403 )
			);
		}

		$selected_options = (array) ( $ac_config['options'] ?? array() );

		if ( $provider->user_has_access( $current_user_id, $selected_options ) ) {
			return $result;
		}

		/**
		 * Fires when an access control check fails.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $current_user_id  ID of the requesting user.
		 * @param array  $resource         The matched resource row.
		 * @param array  $ac_config        Parsed access control config.
		 */
		do_action( 'wpb_access_control_denied', $current_user_id, $resource, $ac_config );

		return new \WP_Error(
			'wpb_ac_access_denied',
			__( 'You do not have permission to access this resource.', 'wpb-access-control' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Determine whether the current user can access a given resource.
	 *
	 * Convenience method for use outside the REST context (e.g. admin UI,
	 * WP-CLI) to preview the access result for the logged-in user.
	 *
	 * @since 1.0.0
	 *
	 * @param array $resource Resource row with an 'access_control' key.
	 *
	 * @return bool True when access is granted.
	 */
	public function current_user_can_access( array $resource ): bool {
		$ac_config = $this->parse_access_control( $resource['access_control'] ?? '' );

		if ( self::TYPE_EVERYONE === $ac_config['type'] || '' === $ac_config['type'] ) {
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
	 * Find the resource row whose REST path matches the given route string.
	 *
	 * Calls the server_fetcher callable to get resource rows and compares
	 * {namespace}/{route} against the beginning of the request route.
	 *
	 * Returns the first matching row, or null when no resource matches.
	 *
	 * @since 1.0.0
	 *
	 * @param string $route Request route with leading slash removed.
	 *
	 * @return array|null Resource row or null.
	 */
	private function match_resource_by_route( string $route ): ?array {
		$resources = (array) call_user_func( $this->server_fetcher );

		foreach ( $resources as $row ) {
			$namespace = ! empty( $row['server_route_namespace'] ) ? $row['server_route_namespace'] : 'mcp';
			$srv_route = ! empty( $row['server_route'] ) ? $row['server_route'] : ( $row['server_slug'] ?? '' );
			$expected  = ltrim( $namespace . '/' . $srv_route, '/' );

			if ( $route === $expected || 0 === strpos( $route, $expected . '/' ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Decode the access_control JSON value into a normalised array.
	 *
	 * Expected JSON:
	 * ```json
	 * { "type": "wp_role", "options": ["editor", "author"] }
	 * ```
	 *
	 * Falls back to type = 'everyone' for empty or malformed values so that
	 * resources without access control are never accidentally locked.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw JSON string.
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
