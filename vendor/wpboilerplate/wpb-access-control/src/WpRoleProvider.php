<?php
/**
 * WordPress Role Access Control Provider.
 *
 * Restricts access to specific WordPress user roles.
 * Administrators always bypass this check (handled by AccessControlManager).
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider that gates access by WordPress user role.
 *
 * When selected as the access control type, only users whose role appears in
 * the saved options list will be permitted. Administrators are exempt and
 * always pass — this is enforced centrally in AccessControlManager.
 *
 * @since 1.0.0
 */
class WpRoleProvider extends AbstractProvider {

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'wp_role';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'WordPress Role', 'wpb-access-control' );
	}

	/**
	 * Return all editable WordPress roles, including Administrator and any custom roles.
	 *
	 * Uses get_editable_roles() so roles added by other plugins/themes are
	 * included automatically. Administrator always has access regardless of
	 * whether it is checked (enforced by AccessControlManager).
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public function get_options(): array {
		$editable_roles = get_editable_roles();
		$options        = array();

		foreach ( $editable_roles as $role_slug => $role_data ) {
			$options[] = array(
				'id'    => $role_slug,
				'label' => translate_user_role( $role_data['name'] ),
			);
		}

		/**
		 * Filter the WordPress role options shown in the access control UI.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array{id: string, label: string}> $options List of role options.
		 */
		return (array) apply_filters( 'wpb_access_control_wp_role_options', $options );
	}

	/**
	 * Return true when the user holds at least one of the allowed roles.
	 *
	 * Administrators bypass this check via AccessControlManager and will never
	 * reach this method.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $user_id          WordPress user ID.
	 * @param string[] $selected_options Role slugs the admin has allowed.
	 *
	 * @return bool
	 */
	public function user_has_access( int $user_id, array $selected_options ): bool {
		if ( empty( $selected_options ) ) {
			// No roles selected means the rule is "nobody" (admins already passed).
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
		 * @since 1.0.0
		 *
		 * @param bool     $has_access       Result before the filter.
		 * @param int      $user_id          User being checked.
		 * @param string[] $selected_options Allowed role slugs.
		 */
		return (bool) apply_filters( 'wpb_access_control_wp_role_has_access', false, $user_id, $selected_options );
	}
}
