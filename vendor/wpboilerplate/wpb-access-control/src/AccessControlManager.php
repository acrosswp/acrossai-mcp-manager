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
 * Minimal — rows must have keys: route_namespace, route, access_control.
 *
 *   $manager = new AccessControlManager(
 *       fn() => MyPlugin::get_resources()
 *   );
 *
 * Custom row shape — pass a $row_mapper to translate your DB row structure:
 *
 *   $manager = new AccessControlManager(
 *       fn() => MyTable::get_all(),
 *       'my_plugin_access_control_providers',
 *       function( array $row ): array {
 *           return array(
 *               'namespace'      => $row['ns'],
 *               'route'          => $row['path'] ?: $row['slug'],
 *               'access_control' => $row['ac_json'] ?? '',
 *           );
 *       }
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
	 * The shape of each row is determined by $row_mapper. When no mapper is
	 * provided, rows are expected to contain:
	 *   'route_namespace' (string) — REST namespace, e.g. 'myplugin/v1'
	 *   'route'           (string) — REST route path, e.g. 'products'
	 *   'access_control'  (string) — JSON config or empty string
	 *
	 * @var callable(): array<int, array<string, mixed>>
	 */
	private $resource_fetcher;

	/**
	 * WordPress filter tag used to collect provider instances.
	 *
	 * @var string
	 */
	private $providers_filter;

	/**
	 * Optional callable that maps a raw resource row to a normalised shape.
	 *
	 * Signature: ( array $row ): array{ namespace: string, route: string, access_control: string }
	 *
	 * When null the manager reads 'route_namespace', 'route', and 'access_control'
	 * directly from each row.
	 *
	 * @var callable|null
	 */
	private $row_mapper;

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
	 * @param callable      $resource_fetcher Callable that returns resource rows.
	 *                                        Signature: (): array<int, array<string,mixed>>
	 * @param string        $providers_filter WordPress filter tag for provider registration.
	 *                                        Defaults to 'wpb_access_control_providers'.
	 * @param callable|null $row_mapper       Optional. Maps a raw row to
	 *                                        array{ namespace: string, route: string, access_control: string }.
	 *                                        When null, the manager reads 'route_namespace', 'route',
	 *                                        and 'access_control' directly from each row.
	 */
	public function __construct(
		callable $resource_fetcher,
		string $providers_filter = 'wpb_access_control_providers',
		?callable $row_mapper = null
	) {
		$this->resource_fetcher = $resource_fetcher;
		$this->providers_filter = $providers_filter;
		$this->row_mapper       = $row_mapper;

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

		$mapped    = $this->map_row( $resource );
		$ac_config = $this->parse_access_control( $mapped['access_control'] );

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
	 * @param array $resource Raw resource row as returned by the fetcher.
	 *
	 * @return bool True when access is granted.
	 */
	public function current_user_can_access( array $resource ): bool {
		$mapped    = $this->map_row( $resource );
		$ac_config = $this->parse_access_control( $mapped['access_control'] );

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
	 * Normalise a raw resource row using the configured mapper.
	 *
	 * Returns an array with keys: namespace, route, access_control.
	 * When no $row_mapper was provided the method reads 'route_namespace',
	 * 'route', and 'access_control' directly from the row.
	 *
	 * @since 1.1.0
	 *
	 * @param array $row Raw resource row.
	 *
	 * @return array{namespace: string, route: string, access_control: string}
	 */
	private function map_row( array $row ): array {
		if ( null !== $this->row_mapper ) {
			$mapped = (array) call_user_func( $this->row_mapper, $row );
		} else {
			$mapped = array(
				'namespace'      => $row['route_namespace'] ?? '',
				'route'          => $row['route'] ?? '',
				'access_control' => $row['access_control'] ?? '',
			);
		}

		return array(
			'namespace'      => (string) ( $mapped['namespace'] ?? '' ),
			'route'          => (string) ( $mapped['route'] ?? '' ),
			'access_control' => (string) ( $mapped['access_control'] ?? '' ),
		);
	}

	/**
	 * Find the resource row whose REST path matches the given route string.
	 *
	 * Normalises each row via map_row() then compares {namespace}/{route}
	 * against the beginning of the request route.
	 *
	 * Returns the first matching raw row, or null when no resource matches.
	 *
	 * @since 1.0.0
	 *
	 * @param string $route Request route with leading slash removed.
	 *
	 * @return array|null Raw resource row or null.
	 */
	private function match_resource_by_route( string $route ): ?array {
		$resources = (array) call_user_func( $this->resource_fetcher );

		foreach ( $resources as $row ) {
			$mapped   = $this->map_row( $row );
			$expected = ltrim( $mapped['namespace'] . '/' . $mapped['route'], '/' );

			if ( '' === $expected ) {
				continue;
			}

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
