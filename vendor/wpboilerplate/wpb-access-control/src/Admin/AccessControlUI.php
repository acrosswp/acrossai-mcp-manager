<?php
/**
 * Access Control Admin UI.
 *
 * Ships a complete, reusable access-control settings panel — type dropdown,
 * per-provider option rows, user search-as-you-type with multi-select tags —
 * so consuming plugins drop in a single render() call instead of implementing
 * the UI themselves.
 *
 * Quick-start for consuming plugins
 * -----------------------------------
 * 1. Instantiate once alongside your AccessControlManager (e.g. in plugins_loaded):
 *
 *      $ui = new \WPBoilerplate\AccessControl\Admin\AccessControlUI( $manager );
 *
 * 2. Call enqueue_assets() from your admin_enqueue_scripts hook:
 *
 *      add_action( 'admin_enqueue_scripts', function() use ( $ui ) {
 *          $ui->enqueue_assets();
 *      } );
 *
 * 3. Call render() wherever you want the panel:
 *
 *      $ui->render( 'my-namespace', 'my-resource', [
 *          'submit_label' => __( 'Save', 'my-plugin' ),
 *      ] );
 *
 * The library handles AJAX saves internally. Consuming plugins only need to
 * instantiate the UI, enqueue assets, and render the panel.
 *
 * @package WPBoilerplate\AccessControl\Admin
 * @since   1.2.0
 */

namespace WPBoilerplate\AccessControl\Admin;

use WPBoilerplate\AccessControl\AccessControlManager;
use WPBoilerplate\AccessControl\AccessControlTable;
use WPBoilerplate\AccessControl\WpUserProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the access-control admin panel and handles its AJAX actions.
 *
 * @since 1.2.0
 */
class AccessControlUI {

	/**
	 * The manager instance that supplies the registered provider list.
	 *
	 * @var AccessControlManager
	 */
	private $manager;

	/**
	 * Explicit asset base URL. Null = auto-detect from WP_CONTENT_DIR/URL.
	 *
	 * @var string|null
	 */
	private $assets_url = null;

	/**
	 * Guard: AJAX action registered exactly once across all instances.
	 *
	 * @var bool
	 */
	private static $ajax_registered = false;

	/**
	 * Monotonic counter to generate unique per-form DOM IDs.
	 *
	 * @var int
	 */
	private static $instance_count = 0;

	/**
	 * Constructor.
	 *
	 * Stores the manager and ensures the shared AJAX actions are registered.
	 *
	 * @since 1.2.0
	 *
	 * @param AccessControlManager $manager Provider registry from the consuming plugin.
	 */
	public function __construct( AccessControlManager $manager ) {
		$this->manager = $manager;
		self::bootstrap();
	}

	/**
	 * Register the shared AJAX callbacks for user search and saving.
	 *
	 * Call this during plugin bootstrap when the UI instance is created later
	 * than `plugins_loaded` or only inside screen-specific callbacks. The
	 * constructor calls this automatically, so plugins that instantiate the UI
	 * once during bootstrap do not need a separate call.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		if ( self::$ajax_registered ) {
			return;
		}

		self::$ajax_registered = true;
		add_action( 'wp_ajax_wpb_access_control_search_users', array( __CLASS__, 'ajax_search_users' ) );
		add_action( 'wp_ajax_wpb_access_control_save', array( __CLASS__, 'ajax_save' ) );
	}

	/**
	 * Override the auto-detected asset base URL.
	 *
	 * Use this when the package is installed in an unusual location (symlinked,
	 * outside wp-content, etc.) and the automatic WP_CONTENT_DIR/URL resolver
	 * produces a wrong URL.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url Absolute URL pointing at the package's assets/ directory,
	 *                    without a trailing slash. Example: plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets', __FILE__ )
	 *
	 * @return void
	 */
	public function set_assets_url( string $url ): void {
		$this->assets_url = untrailingslashit( $url );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Render the access-control settings panel.
	 *
	 * The form submits via AJAX — no form_action or nonce_action needed from
	 * the consumer. The library registers the save action and handles the
	 * response inline (success notice or error message). Namespace and key
	 * are embedded as hidden inputs so the handler knows which rule to update.
	 *
	 * @since 1.2.0
	 *
	 * @param string $namespace Resource namespace (e.g. 'mcp', 'procureco/v1').
	 * @param string $key       Resource key within that namespace.
	 * @param array  $args {
	 *     @type string $submit_label Submit button label. Default "Save Access Control".
	 *     @type string $description  Paragraph shown below the heading.
	 * }
	 *
	 * @return void
	 */
	public function render( string $namespace, string $key, array $args = array() ): void {
		self::$instance_count++;
		$form_id = 'wpb-ac-' . self::$instance_count;

		$submit_label = isset( $args['submit_label'] ) ? (string) $args['submit_label'] : __( 'Save Access Control', 'wpb-access-control' );
		$description  = isset( $args['description'] )
			? (string) $args['description']
			: __( 'Control which users are allowed to access this resource. Administrators always have access regardless of this setting.', 'wpb-access-control' );

		// Resolve current stored config.
		$row       = AccessControlTable::get( $namespace, $key );
		$ac_key    = $row['key'];
		$ac_values = $row['value'];

		$providers = $this->manager->get_providers();
		?>
		<div class="wpb-ac-panel" data-wpb-ac-form="<?php echo esc_attr( $form_id ); ?>">

			<!-- Inline notice shown after AJAX save (hidden until JS populates it). -->
			<div class="wpb-ac-notice" style="display:none;" aria-live="polite"></div>

			<form method="post"
			      action=""
			      class="wpb-ac-form"
			      data-wpb-ac-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

				<!-- Library-owned save action and nonce — consumer sets neither. -->
				<input type="hidden" name="action"       value="wpb_access_control_save">
				<input type="hidden" name="wpb_ac_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpb_access_control_save' ) ); ?>">
				<input type="hidden" name="wpb_ac_ns"    value="<?php echo esc_attr( $namespace ); ?>">
				<input type="hidden" name="wpb_ac_key"   value="<?php echo esc_attr( $key ); ?>">

				<h2><?php esc_html_e( 'Access Control', 'wpb-access-control' ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>

						<!-- Type selector -->
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $form_id . '-type' ); ?>">
									<?php esc_html_e( 'Who can access', 'wpb-access-control' ); ?>
								</label>
							</th>
							<td>
								<select name="ac_type"
								        id="<?php echo esc_attr( $form_id . '-type' ); ?>"
								        class="regular-text wpb-ac-type-select">
									<option value="" <?php selected( $ac_key, '' ); ?>>
										<?php esc_html_e( 'No user access added by admin', 'wpb-access-control' ); ?>
									</option>
									<option value="everyone" <?php selected( $ac_key, 'everyone' ); ?>>
										<?php esc_html_e( 'Everyone (no restriction)', 'wpb-access-control' ); ?>
									</option>
									<?php foreach ( $providers as $provider_id => $provider ) : ?>
										<?php if ( $provider->is_available() ) : ?>
											<option value="<?php echo esc_attr( $provider_id ); ?>"
											        <?php selected( $ac_key, $provider_id ); ?>>
												<?php echo esc_html( $provider->get_label() ); ?>
											</option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

						<!-- Per-provider option rows (shown/hidden by JS) -->
						<?php foreach ( $providers as $provider_id => $provider ) : ?>
							<?php if ( ! $provider->is_available() ) { continue; } ?>
							<tr class="wpb-ac-options-row wpb-ac-options-<?php echo esc_attr( $provider_id ); ?>"
							    style="<?php echo $ac_key === $provider_id ? '' : 'display:none'; ?>">
								<th scope="row"><?php echo esc_html( $provider->get_label() ); ?></th>
								<td>
									<?php $provider->render_options( $ac_values, $form_id ); ?>
								</td>
							</tr>
						<?php endforeach; ?>

					</tbody>
				</table>

				<p class="submit">
					<?php submit_button( $submit_label, 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div><!-- .wpb-ac-panel -->
		<?php
	}

	/**
	 * Enqueue the library's admin CSS and JS.
	 *
	 * Call this from your plugin's admin_enqueue_scripts hook. Safe to call
	 * multiple times — WordPress skips double-enqueues by handle.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$base = $this->resolve_assets_url();
		$ver  = filemtime( dirname( __DIR__, 2 ) . '/assets/css/admin.css' ) ?: '1.2.0';

		wp_enqueue_style(
			'wpb-access-control-admin',
			$base . '/css/admin.css',
			array(),
			$ver
		);

		wp_enqueue_script(
			'wpb-access-control-admin',
			$base . '/js/admin.js',
			array(),
			filemtime( dirname( __DIR__, 2 ) . '/assets/js/admin.js' ) ?: '1.2.0',
			true
		);

		wp_localize_script(
			'wpb-access-control-admin',
			'wpbAcAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpb_access_control_search_users' ),
				'i18n'    => array(
					'searching'   => __( 'Searching…', 'wpb-access-control' ),
					'noResults'   => __( 'No users found.', 'wpb-access-control' ),
					'placeholder' => __( 'Search by username or email…', 'wpb-access-control' ),
					'remove'      => __( 'Remove user', 'wpb-access-control' ),
					'saving'      => __( 'Saving…', 'wpb-access-control' ),
					'save'        => __( 'Save Access Control', 'wpb-access-control' ),
					'saveSuccess' => __( 'Access control saved.', 'wpb-access-control' ),
					'saveError'   => __( 'Unable to save access control.', 'wpb-access-control' ),
				),
			)
		);
	}

	/**
	 * Extract and return the access-control rule from POST data.
	 *
	 * Reads ac_type and ac_options[] from the supplied array (pass $_POST).
	 * Used internally by ajax_save(), but remains public so consuming plugins
	 * can reuse the same extraction logic in custom save flows when needed.
	 * Sanitization of the key and option values is handled inside
	 * AccessControlTable::update() to avoid double-processing.
	 *
	 * @since 2.0.0
	 *
	 * @param array $post Raw POST data (typically $_POST).
	 *
	 * @return array{key: string, value: string[]}
	 */
	public static function extract_posted_config( array $post ): array {
		$ac_key = isset( $post['ac_type'] ) ? sanitize_key( wp_unslash( $post['ac_type'] ) ) : '';

		if ( '' === $ac_key || 'everyone' === $ac_key ) {
			return array( 'key' => $ac_key, 'value' => array() );
		}

		$ac_options = array();
		if ( isset( $post['ac_options'] ) && is_array( $post['ac_options'] ) ) {
			$ac_options = array_values( array_map( 'strval', wp_unslash( (array) $post['ac_options'] ) ) );
		}

		return array( 'key' => $ac_key, 'value' => $ac_options );
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	/**
	 * Handle wp_ajax_wpb_access_control_search_users.
	 *
	 * Returns an array of matching users for the live search UI. Never call
	 * this method directly — it is registered as a WP AJAX callback.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function ajax_search_users(): void {
		check_ajax_referer( 'wpb_access_control_search_users' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'wpb-access-control' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$term    = sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) );
		$results = WpUserProvider::search_users( $term, 10 );

		wp_send_json_success( $results );
	}

	/**
	 * Handle wp_ajax_wpb_access_control_save.
	 *
	 * Verifies the library-owned nonce, validates the target namespace + key,
	 * then persists the extracted config through AccessControlTable::update().
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function ajax_save(): void {
		check_ajax_referer( 'wpb_access_control_save', 'wpb_ac_nonce' );

		$user_id = get_current_user_id();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'wpb-access-control' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$namespace = isset( $_POST['wpb_ac_ns'] ) ? sanitize_text_field( wp_unslash( $_POST['wpb_ac_ns'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$key = isset( $_POST['wpb_ac_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wpb_ac_key'] ) ) : '';

		if ( '' === $namespace || '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing access-control target.', 'wpb-access-control' ) ), 400 );
		}

		if ( strlen( $namespace ) > AccessControlTable::NAMESPACE_LENGTH || strlen( $key ) > AccessControlTable::KEY_LENGTH ) {
			wp_send_json_error( array( 'message' => __( 'Invalid access-control target.', 'wpb-access-control' ) ), 400 );
		}

		/**
		 * Filter whether the current request may save access control for a target.
		 *
		 * Use this to restrict which namespaces/keys a specific admin screen is
		 * allowed to manage, especially when multiple plugins use this library.
		 *
		 * @since 1.2.0
		 *
		 * @param bool   $can_save  Whether the save is allowed. Default true.
		 * @param string $namespace Resource namespace from the request.
		 * @param string $key       Resource key from the request.
		 * @param int    $user_id   Current WordPress user ID.
		 */
		$can_save = (bool) apply_filters( 'wpb_access_control_can_save', true, $namespace, $key, $user_id );

		if ( ! $can_save ) {
			wp_send_json_error( array( 'message' => __( 'Not permitted to save this access control target.', 'wpb-access-control' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$config  = self::extract_posted_config( $_POST );
		$updated = AccessControlTable::update( $namespace, $key, $config['key'], $config['value'] );

		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save access control.', 'wpb-access-control' ) ), 500 );
		}

		/**
		 * Fires after access control is saved successfully via the built-in UI.
		 *
		 * @since 2.0.0
		 *
		 * @param string   $namespace  Saved resource namespace.
		 * @param string   $key        Saved resource key.
		 * @param string   $ac_key     Rule type slug ('', 'everyone', 'wp_role', 'wp_user', …).
		 * @param string[] $ac_options Rule options (role slugs, user ID strings, etc.).
		 * @param int      $user_id    Current WordPress user ID.
		 */
		do_action( 'wpb_access_control_saved', $namespace, $key, $config['key'], $config['value'], $user_id );

		wp_send_json_success(
			array(
				'message' => __( 'Access control saved.', 'wpb-access-control' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the base URL of the library's assets/ directory.
	 *
	 * Auto-detection strips the absolute WP_CONTENT_DIR prefix from the
	 * package root and prepends WP_CONTENT_URL. Works whether the package is
	 * installed directly under wp-content/ or inside a plugin's vendor/.
	 * Override with set_assets_url() when the auto-detection is wrong.
	 *
	 * @since 1.2.0
	 *
	 * @return string Base URL without trailing slash.
	 */
	private function resolve_assets_url(): string {
		if ( null !== $this->assets_url ) {
			return $this->assets_url;
		}

		// Package root = two directories above this file (src/Admin → src → root).
		$pkg_root    = wp_normalize_path( dirname( __DIR__, 2 ) );
		$content_dir = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		$content_url = untrailingslashit( WP_CONTENT_URL );

		if ( 0 === strpos( $pkg_root, $content_dir ) ) {
			$relative = substr( $pkg_root, strlen( $content_dir ) );
			return set_url_scheme( $content_url . $relative . '/assets' );
		}

		// Fallback: caller should set_assets_url() explicitly.
		return set_url_scheme( $content_url . '/wpb-access-control/assets' );
	}
}
