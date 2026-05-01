<?php
/**
 * Access Control Manager.
 *
 * Provider registry and single entry-point for access decisions.
 * Answers one question: "Does this user have access to this resource?"
 *
 * This class has no knowledge of REST API, route matching, or any specific
 * product. The consuming plugin decides when and where to call
 * user_has_access() — on a REST hook, a form submission, WP-CLI, etc.
 *
 * Usage
 * -----
 *   // In your plugin bootstrap (Plugin.php or equivalent):
 *   $manager = new AccessControlManager( 'my_plugin_access_control_providers' );
 *
 *   // Anywhere you need to gate access:
 *   if ( ! $manager->user_has_access( get_current_user_id(), 'my-namespace', 'my-resource' ) ) {
 *       wp_die( 'Access denied.', 403 );
 *   }
 *
 * Provider registry
 * -----------------
 * Providers are registered via the WordPress filter tag passed to the
 * constructor (default: 'wpb_access_control_providers'). Always use a
 * plugin-specific tag to avoid providers from one plugin leaking into another.
 *
 * The filter fires on init at priority 5, or immediately when the manager is
 * constructed after init has already fired (e.g. during an admin page render).
 *
 *   add_filter( 'my_plugin_access_control_providers', function( array $providers ) {
 *       $providers[] = new My\Plugin\MembershipProvider();
 *       return $providers;
 *   } );
 *
 * Access hierarchy (evaluated by user_has_access)
 * ------------------------------------------------
 *   1. access_control empty or type = 'everyone' → allow.
 *   2. User has manage_options (administrator)   → always allow.
 *   3. User not authenticated (id = 0)           → deny.
 *   4. No provider found for the configured type → deny.
 *   5. provider->user_has_access()               → allow or deny.
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider registry and access decision engine.
 *
 * @since 1.0.0
 */
class AccessControlManager {

	/**
	 * Special type value meaning "no restriction — allow everyone".
	 */
	const TYPE_EVERYONE = 'everyone';

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
	 * @param string $providers_filter WordPress filter tag for provider registration.
	 *                                 Defaults to 'wpb_access_control_providers'.
	 *                                 Use a plugin-specific tag to avoid collisions.
	 */
	public function __construct( string $providers_filter = 'wpb_access_control_providers' ) {
		$this->providers_filter = $providers_filter;

		// Load providers immediately if init has already fired (e.g. during
		// admin page rendering), otherwise wait for the normal lifecycle.
		if ( did_action( 'init' ) ) {
			$this->load_providers();
		} else {
			add_action( 'init', array( $this, 'load_providers' ), 5 );
		}
	}

	// -------------------------------------------------------------------------
	// Provider registry
	// -------------------------------------------------------------------------

	/**
	 * Resolve all enabled providers via the configured filter.
	 *
	 * Idempotent — calling it more than once rebuilds the list from scratch.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_providers(): void {
		$default_providers = array(
			new WpRoleProvider(),
			new WpUserProvider(),
		);

		/**
		 * Filter the list of registered access-control providers.
		 *
		 * Each element must be an instance of AbstractProvider.
		 * Always use the plugin-specific filter tag passed to the constructor
		 * to prevent providers from leaking between plugins.
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
	// Access decision
	// -------------------------------------------------------------------------

	/**
	 * Determine whether a user may access a specific resource.
	 *
	 * Reads the stored rule from AccessControlTable using the (namespace, key)
	 * pair, then applies the access hierarchy. Returns true when access is
	 * granted, false when it is denied.
	 *
	 * The consuming plugin is responsible for:
	 *   - deciding when to call this method (REST hook, form submit, etc.)
	 *   - acting on the return value (WP_Error, wp_die, redirect, etc.)
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id   WordPress user ID (0 = unauthenticated).
	 * @param string $namespace Resource namespace (e.g. 'mcp', 'procureco/v1').
	 * @param string $key       Resource key within that namespace.
	 *
	 * @return bool True when access is granted.
	 */
	public function user_has_access( int $user_id, string $namespace, string $key ): bool {
		$raw       = AccessControlTable::get( $namespace, $key );
		$ac_config = $this->parse_access_control( $raw );

		// No restriction configured → allow.
		if ( self::TYPE_EVERYONE === $ac_config['type'] || '' === $ac_config['type'] ) {
			return true;
		}

		// Administrators always bypass access rules.
		if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Unauthenticated → deny.
		if ( ! $user_id ) {

			/**
			 * Fires when access is denied.
			 *
			 * @since 1.0.0
			 *
			 * @param int    $user_id   The requesting user ID (0 = unauthenticated).
			 * @param string $namespace Resource namespace.
			 * @param string $key       Resource key.
			 * @param array  $ac_config Parsed access control config.
			 */
			do_action( 'wpb_access_control_denied', $user_id, $namespace, $key, $ac_config );

			return false;
		}

		$provider = $this->get_provider( $ac_config['type'] );

		// Unknown provider type → deny.
		if ( null === $provider ) {
			do_action( 'wpb_access_control_denied', $user_id, $namespace, $key, $ac_config );
			return false;
		}

		$selected_options = (array) ( $ac_config['options'] ?? array() );
		$allowed          = $provider->user_has_access( $user_id, $selected_options );

		if ( ! $allowed ) {
			do_action( 'wpb_access_control_denied', $user_id, $namespace, $key, $ac_config );
		}

		return $allowed;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Decode an access_control JSON string into a normalised array.
	 *
	 * Falls back to type = 'everyone' for empty or malformed input so that
	 * unconfigured resources are never accidentally locked out.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw JSON string from AccessControlTable::get().
	 *
	 * @return array{type: string, options: string[]}
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
