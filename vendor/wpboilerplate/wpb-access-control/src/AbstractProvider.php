<?php
/**
 * Abstract Access Control Provider.
 *
 * Every access-control back-end (WP role, membership plugin, etc.) must extend
 * this class and implement the abstract methods.
 *
 * Adding a new provider
 * ---------------------
 * 1. Create a class extending AbstractProvider in your own codebase.
 * 2. Implement get_id(), get_label(), get_options(), and user_has_access().
 * 3. Override is_available() when the provider depends on an optional plugin.
 * 4. Register it via the filter passed to AccessControlManager::load_providers()
 *    — by default this is 'wpb_access_control_providers', but it can be
 *    customised per-manager-instance.
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for all access-control providers.
 *
 * @since 1.0.0
 */
abstract class AbstractProvider {

	// -------------------------------------------------------------------------
	// Abstract interface — every provider must implement these
	// -------------------------------------------------------------------------

	/**
	 * Return the unique machine-readable identifier for this provider.
	 *
	 * The ID is stored in the access_control JSON blob under the `type` key.
	 * Must be a lowercase ASCII string with no spaces (underscores are fine).
	 *
	 * Example: `'wp_role'`
	 *
	 * @since 1.0.0
	 *
	 * @return string Provider ID.
	 */
	abstract public function get_id(): string;

	/**
	 * Return the human-readable label shown in the admin UI dropdown.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated label.
	 */
	abstract public function get_label(): string;

	/**
	 * Return the selectable options for this provider.
	 *
	 * Each element is an associative array with at minimum:
	 *   'id'    (string) — machine-readable option value stored in DB
	 *   'label' (string) — human-readable text shown in checkboxes
	 *
	 * Example:
	 * ```php
	 * [
	 *   [ 'id' => 'editor',     'label' => 'Editor' ],
	 *   [ 'id' => 'author',     'label' => 'Author' ],
	 *   [ 'id' => 'subscriber', 'label' => 'Subscriber' ],
	 * ]
	 * ```
	 *
	 * Administrators should be excluded because AccessControlManager grants
	 * them unconditional access.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{id: string, label: string}> Selectable options.
	 */
	abstract public function get_options(): array;

	/**
	 * Check whether a given user passes the access rules for this provider.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $user_id          WordPress user ID to check.
	 * @param string[] $selected_options Option IDs saved by the admin
	 *                                   (subset of what get_options() returns).
	 *
	 * @return bool True when the user is allowed; false when denied.
	 */
	abstract public function user_has_access( int $user_id, array $selected_options ): bool;

	// -------------------------------------------------------------------------
	// Shared helpers (may be overridden)
	// -------------------------------------------------------------------------

	/**
	 * Return whether this provider is currently available/enabled.
	 *
	 * Providers that depend on third-party plugins should override this method
	 * and return false when the required plugin is inactive. The admin UI hides
	 * unavailable providers automatically.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the provider can be used.
	 */
	public function is_available(): bool {
		return true;
	}
}
