<?php
/**
 * WordPress User Access Control Provider.
 *
 * Restricts access to a specific list of WordPress users.
 * Administrators always bypass this check (handled by AccessControlManager).
 *
 * Stored format
 * -------------
 * User IDs are stored as strings in the options array:
 *   { "type": "wp_user", "options": ["1", "42", "7"] }
 *
 * Why IDs and not usernames/emails
 * ---------------------------------
 * AccessControlTable::sanitize() runs sanitize_key() on every option value,
 * which strips @, dots, and other characters from email addresses. Storing the
 * integer user ID as a string ("42") is the only value that survives the
 * sanitization pipeline unchanged.
 *
 * Admin UI
 * --------
 * get_options() returns an empty array — there is no static checkbox list.
 * render_options() is overridden to emit an AJAX search input + selected-user
 * tags. AccessControlUI registers the AJAX handler and enqueues the JS/CSS;
 * consuming plugins call AccessControlUI::render() and enqueue_assets() and
 * need no additional code for user search.
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.1.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider that gates access by specific WordPress user IDs.
 *
 * @since 1.1.0
 */
class WpUserProvider extends AbstractProvider {

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'wp_user';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Users', 'wpb-access-control' );
	}

	/**
	 * Users are selected dynamically via AJAX search — no static list.
	 *
	 * The consuming plugin must render the selected user IDs itself. Use
	 * WpUserProvider::get_users_by_ids() to hydrate IDs back into display data.
	 *
	 * @since 1.1.0
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public function get_options(): array {
		return array();
	}

	/**
	 * Return true when the user's ID appears in the allowed list.
	 *
	 * IDs in $selected_options are compared as strings to match what is stored
	 * in the database after sanitize_key() processing.
	 *
	 * @since 1.1.0
	 *
	 * @param int      $user_id          WordPress user ID.
	 * @param string[] $selected_options User IDs (as strings) the admin has allowed.
	 *
	 * @return bool
	 */
	public function user_has_access( int $user_id, array $selected_options ): bool {
		if ( empty( $selected_options ) ) {
			// No users selected — nobody is permitted (admins already bypassed).
			return false;
		}

		$result = in_array( (string) $user_id, $selected_options, true );

		/**
		 * Filter the final access decision for a WP-user check.
		 *
		 * @since 1.1.0
		 *
		 * @param bool     $has_access       Result before the filter.
		 * @param int      $user_id          User being checked.
		 * @param string[] $selected_options Allowed user IDs as strings.
		 */
		return (bool) apply_filters( 'wpb_access_control_wp_user_has_access', $result, $user_id, $selected_options );
	}

	/**
	 * Render the user search input and selected-user tags.
	 *
	 * Called by AccessControlUI::render() — do not call directly.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $selected_options User IDs (as strings) currently saved.
	 * @param string   $form_id          Unique DOM ID scoping this panel instance.
	 *
	 * @return void
	 */
	public function render_options( array $selected_options, string $form_id ): void {
		$saved_users = self::get_users_by_ids( $selected_options );
		?>
		<p class="description" style="margin-bottom:10px;">
			<?php esc_html_e( 'Search by username or email and select one or more users. Administrators always have access regardless of this list.', 'wpb-access-control' ); ?>
		</p>

		<!-- Search input -->
		<div class="wpb-ac-user-search-wrap">
			<input type="text"
			       class="wpb-ac-user-search regular-text"
			       data-wpb-ac-form="<?php echo esc_attr( $form_id ); ?>"
			       placeholder="<?php esc_attr_e( 'Search by username or email…', 'wpb-access-control' ); ?>"
			       autocomplete="off">
			<div class="wpb-ac-search-results" style="display:none;"></div>
		</div>

		<!-- Selected user tags -->
		<div class="wpb-ac-selected-users">
			<?php foreach ( $saved_users as $u ) : ?>
				<span class="wpb-ac-user-tag" data-id="<?php echo esc_attr( $u['id'] ); ?>">
					<span><?php echo esc_html( $u['display_name'] ); ?></span>
					<span class="wpb-ac-user-tag-login">(<?php echo esc_html( $u['login'] ); ?>)</span>
					<button type="button" class="wpb-ac-remove-user"
					        aria-label="<?php esc_attr_e( 'Remove user', 'wpb-access-control' ); ?>">&times;</button>
					<input type="hidden" name="ac_options[]" value="<?php echo esc_attr( $u['id'] ); ?>">
				</span>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Static helpers — used internally by AccessControlUI and available to
	// consuming plugins that need to query users outside the standard flow.
	// -------------------------------------------------------------------------

	/**
	 * Search for WordPress users by login, email, or display name.
	 *
	 * AccessControlUI registers a wp_ajax_ handler that calls this method.
	 * Consuming plugins do not need to create their own AJAX handler when
	 * using AccessControlUI::render().
	 *
	 * @since 1.1.0
	 *
	 * @param string $search Search term (partial login, email, or display name).
	 * @param int    $limit  Maximum number of results to return. Default 10.
	 *
	 * @return array<int, array{id: string, login: string, email: string, display_name: string}>
	 */
	public static function search_users( string $search, int $limit = 10 ): array {
		$search = sanitize_text_field( $search );

		if ( '' === $search ) {
			return array();
		}

		$users = get_users(
			array(
				'search'         => '*' . $search . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => max( 1, $limit ),
				'fields'         => array( 'ID', 'user_login', 'user_email', 'display_name' ),
				'orderby'        => 'display_name',
				'order'          => 'ASC',
			)
		);

		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'id'           => (string) $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
			);
		}

		return $results;
	}

	/**
	 * Hydrate a list of stored user ID strings back into display data.
	 *
	 * Use this when rendering the admin settings page to show who is currently
	 * allowed, rather than just their raw IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $user_ids Array of user ID strings (as stored in options).
	 *
	 * @return array<int, array{id: string, login: string, email: string, display_name: string}>
	 */
	public static function get_users_by_ids( array $user_ids ): array {
		$ids = array_filter( array_map( 'absint', $user_ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => array_values( $ids ),
				'fields'  => array( 'ID', 'user_login', 'user_email', 'display_name' ),
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'id'           => (string) $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
			);
		}

		return $results;
	}
}
