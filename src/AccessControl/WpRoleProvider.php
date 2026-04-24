<?php
/**
 * WordPress Role Access Control Provider.
 *
 * Restricts MCP server access to specific WordPress user roles.
 * Administrators always bypass this check (handled by AccessControlManager).
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
 * Provider that gates MCP server access by WordPress user role.
 *
 * When an admin selects "WordPress Role" as the access control type for a
 * server and checks e.g. "Editor" and "Author", only users whose primary
 * role is one of those two values will be permitted to call that server's
 * MCP REST endpoint.
 *
 * Administrators are exempt and always pass — this is enforced centrally in
 * {@see AccessControlManager::current_user_can_access()}.
 *
 * @since 1.4.0
 */
class WpRoleProvider extends AbstractProvider {

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'wp_role';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'WordPress Role', 'acrossai-mcp-manager' );
	}

	/**
	 * Return all editable WordPress roles except Administrator.
	 *
	 * Administrators are always granted access, so listing them as a
	 * selectable option would be misleading.
	 *
	 * Uses `get_editable_roles()` so that roles added by themes/plugins are
	 * included automatically.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public function get_options(): array {
		$editable_roles = get_editable_roles();
		$options        = array();

		foreach ( $editable_roles as $role_slug => $role_data ) {
			// Skip administrator — they always have access.
			if ( 'administrator' === $role_slug ) {
				continue;
			}

			$options[] = array(
				'id'    => $role_slug,
				'label' => translate_user_role( $role_data['name'] ),
			);
		}

		/**
		 * Filter the WordPress role options shown in the Access Control UI.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, array{id: string, label: string}> $options List of role options.
		 */
		return apply_filters( 'acrossai_mcp_access_control_wp_role_options', $options );
	}

	/**
	 * Return true when the user holds at least one of the allowed roles.
	 *
	 * Administrators bypass this check via AccessControlManager, so they
	 * will never reach this method.
	 *
	 * @since 1.4.0
	 *
	 * @param int      $user_id          WordPress user ID.
	 * @param string[] $selected_options Role slugs the admin has allowed.
	 *
	 * @return bool
	 */
	public function user_has_access( int $user_id, array $selected_options ): bool {
		if ( empty( $selected_options ) ) {
			// No roles selected means the rule is effectively "nobody"
			// (admin already passed earlier). Deny by default.
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		foreach ( (array) $user->roles as $role ) {
			if ( in_array( $role, $selected_options, true ) ) {
				return true;
			}
		}

		/**
		 * Filter the final access decision for a WP-role check.
		 *
		 * @since 1.4.0
		 *
		 * @param bool     $has_access       Result before the filter.
		 * @param int      $user_id          User being checked.
		 * @param string[] $selected_options Allowed role slugs.
		 */
		return (bool) apply_filters( 'acrossai_mcp_access_control_wp_role_has_access', false, $user_id, $selected_options );
	}
}
