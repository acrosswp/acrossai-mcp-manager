<?php
/**
 * Abstract Access Control Provider.
 *
 * Every access-control back-end (WP role, membership plugin, etc.) must extend
 * this class and implement the two abstract methods.
 *
 * Adding a new provider
 * ---------------------
 * 1. Create `src/AccessControl/YourProvider.php` extending this class.
 * 2. Implement `get_id()`, `get_label()`, and `get_options()`.
 * 3. Implement `user_has_access( $user_id, array $selected_options )`.
 * 4. Register it via the `acrossai_mcp_access_control_providers` filter.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage AccessControl
 * @since 1.4.0
 */

namespace ACROSSAI_MCP_MANAGER\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for all MCP server access-control providers.
 *
 * @since 1.4.0
 */
abstract class AbstractProvider {

	// -------------------------------------------------------------------------
	// Abstract interface — every provider must implement these
	// -------------------------------------------------------------------------

	/**
	 * Return the unique machine-readable identifier for this provider.
	 *
	 * The ID is stored in the `access_control` JSON blob under the `type` key.
	 * It must be a lowercase ASCII string with no spaces (underscores are fine).
	 *
	 * Example: `'wp_role'`
	 *
	 * @since 1.4.0
	 *
	 * @return string Provider ID.
	 */
	abstract public function get_id(): string;

	/**
	 * Return the human-readable label shown in the admin UI dropdown.
	 *
	 * Example: `__( 'WordPress Role', 'acrossai-mcp-manager' )`
	 *
	 * @since 1.4.0
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
	 * Example return value for the WP Role provider:
	 * ```php
	 * [
	 *   [ 'id' => 'editor',      'label' => 'Editor' ],
	 *   [ 'id' => 'author',      'label' => 'Author' ],
	 *   [ 'id' => 'subscriber',  'label' => 'Subscriber' ],
	 * ]
	 * ```
	 *
	 * Administrators are never returned here — they always have access
	 * (enforced in `AccessControlManager::current_user_can_access()`).
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array{id: string, label: string}> Selectable options.
	 */
	abstract public function get_options(): array;

	/**
	 * Check whether a given user passes the access rules for this provider.
	 *
	 * @since 1.4.0
	 *
	 * @param int      $user_id          WordPress user ID to check.
	 * @param string[] $selected_options Option IDs that were saved by the admin
	 *                                   (subset of what `get_options()` returns).
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
	 * and return `false` when the required plugin is inactive.
	 * The admin UI hides disabled providers automatically.
	 *
	 * @since 1.4.0
	 *
	 * @return bool True when the provider can be used.
	 */
	public function is_available(): bool {
		return true;
	}
}
