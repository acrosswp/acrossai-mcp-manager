<?php
/**
 * Admin Settings class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin
 */

namespace ACROSSAI_MCP_MANAGER\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\AccessControl\AccessControlManager;
use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;

/**
 * Handles the admin menu, list page, and per-server edit page.
 *
 * URL routing
 * -----------
 *   ?page=acrossai_mcp_manager                        → server list (WP_List_Table)
 *   ?page=acrossai_mcp_manager&action=edit&server=ID  → tabbed edit page for one server
 *   ?page=acrossai_mcp_manager&action=toggle_status&server=ID&_wpnonce=... → toggle + redirect
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Application Passwords manager.
	 *
	 * @var ApplicationPasswords
	 */
	private $app_passwords;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->app_passwords = new ApplicationPasswords();

		add_action( 'admin_init', array( $this, 'handle_actions' ), 5 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Show notice if the MCP adapter package is not installed.
		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_adapter_notice' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Process actions (toggle_status) before any HTML output.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_actions() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['page'] ) || 'acrossai_mcp_manager' !== $_GET['page'] ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		if ( ! in_array( $action, array( 'toggle_status', 'delete', 'create', 'update', 'save_access_control' ), true ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'acrossai-mcp-manager' ) );
		}

		// ── toggle_status ────────────────────────────────────────────────────────
		if ( 'toggle_status' === $action ) {
			$server_id   = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_key( $_GET['redirect_to'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification

			check_admin_referer( 'acrossai_mcp_toggle_' . $server_id );

			if ( $server_id > 0 ) {
				MCPServerTable::toggle_status( $server_id );
			}

			if ( 'edit' === $redirect_to ) {
				$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'    => 'acrossai_mcp_manager',
							'action'  => 'edit',
							'server'  => $server_id,
							'tab'     => $active_tab,
							'updated' => '1',
						),
						admin_url( 'admin.php' )
					)
				);
			} else {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'    => 'acrossai_mcp_manager',
							'updated' => '1',
						),
						admin_url( 'admin.php' )
					)
				);
			}

			exit;
		}

		// ── delete ───────────────────────────────────────────────────────────────
		if ( 'delete' === $action ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

			check_admin_referer( 'acrossai_mcp_delete_' . $server_id );

			if ( $server_id > 0 ) {
				MCPServerTable::delete_server( $server_id );
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'acrossai_mcp_manager',
						'deleted' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// ── create (POST) ────────────────────────────────────────────────────────
		if ( 'create' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			check_admin_referer( 'acrossai_mcp_create_server' );

			$name      = isset( $_POST['server_name'] ) ? sanitize_text_field( wp_unslash( $_POST['server_name'] ) ) : '';
			$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
			$namespace   = isset( $_POST['server_route_namespace'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route_namespace'] ) ) : 'mcp';
			$route       = isset( $_POST['server_route'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route'] ) ) : '';
			$version     = isset( $_POST['server_version'] ) ? sanitize_text_field( wp_unslash( $_POST['server_version'] ) ) : 'v1.0.0';

			if ( empty( $name ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'   => 'acrossai_mcp_manager',
							'action' => 'create',
							'error'  => 'empty_name',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$slug = sanitize_title( $name );

			if ( MCPServerTable::slug_exists( $slug ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'   => 'acrossai_mcp_manager',
							'action' => 'create',
							'error'  => 'slug_conflict',
							'slug'   => rawurlencode( $slug ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			// Route defaults to the slug when left blank.
			if ( '' === $route ) {
				$route = $slug;
			}

			$new_id = MCPServerTable::create_server( $name, $description, $namespace, $route, $version );

			if ( ! $new_id ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'   => 'acrossai_mcp_manager',
							'action' => 'create',
							'error'  => 'db_error',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'acrossai_mcp_manager',
						'action'  => 'edit',
						'server'  => $new_id,
						'tab'     => 'overview',
						'created' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// ── update (POST) ────────────────────────────────────────────────────────
		if ( 'update' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

			check_admin_referer( 'acrossai_mcp_update_' . $server_id );

			$server = $server_id ? MCPServerTable::get_by_id( $server_id ) : null;

			if ( ! $server || 'database' !== $server['registered_from'] ) {
				wp_die( esc_html__( 'Invalid server or this server cannot be edited.', 'acrossai-mcp-manager' ) );
			}

			$data = array(
				'server_name'            => isset( $_POST['server_name'] ) ? sanitize_text_field( wp_unslash( $_POST['server_name'] ) ) : '',
				'description'            => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
				'server_route_namespace' => isset( $_POST['server_route_namespace'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route_namespace'] ) ) : 'mcp',
				'server_route'           => isset( $_POST['server_route'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route'] ) ) : '',
				'server_version'         => isset( $_POST['server_version'] ) ? sanitize_text_field( wp_unslash( $_POST['server_version'] ) ) : 'v1.0.0',
			);

			// Fallback: keep existing route if submitted value is blank.
			if ( '' === $data['server_route'] ) {
				$data['server_route'] = $server['server_slug'];
			}

			if ( empty( $data['server_name'] ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'   => 'acrossai_mcp_manager',
							'action' => 'edit',
							'server' => $server_id,
							'tab'    => 'overview',
							'error'  => 'empty_name',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			MCPServerTable::update_server( $server_id, $data );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'acrossai_mcp_manager',
						'action'  => 'edit',
						'server'  => $server_id,
						'tab'     => 'update-server',
						'updated' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// ── save_access_control (POST) ────────────────────────────────────────────
		if ( 'save_access_control' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

			check_admin_referer( 'acrossai_mcp_access_control_' . $server_id );

			$server = $server_id ? MCPServerTable::get_by_id( $server_id ) : null;

			if ( ! $server ) {
				wp_die( esc_html__( 'Invalid server.', 'acrossai-mcp-manager' ) );
			}

			$ac_type    = isset( $_POST['ac_type'] ) ? sanitize_key( wp_unslash( $_POST['ac_type'] ) ) : 'everyone';
			$ac_options = array();

			if ( 'everyone' !== $ac_type && isset( $_POST['ac_options'] ) && is_array( $_POST['ac_options'] ) ) {
				$ac_options = array_map( 'sanitize_key', wp_unslash( $_POST['ac_options'] ) );
			}

			$access_control_json = ( 'everyone' === $ac_type )
				? ''
				: wp_json_encode( array( 'type' => $ac_type, 'options' => array_values( $ac_options ) ) );

			MCPServerTable::update_server( $server_id, array( 'access_control' => $access_control_json ) );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'acrossai_mcp_manager',
						'action'  => 'edit',
						'server'  => $server_id,
						'tab'     => 'access-control',
						'updated' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Register the top-level admin menu page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'AcrossAI MCP Manager', 'acrossai-mcp-manager' ),
			__( 'MCP Manager', 'acrossai-mcp-manager' ),
			'manage_options',
			'acrossai_mcp_manager',
			array( $this, 'render_settings_page' ),
			'dashicons-hammer',
			99
		);

		add_submenu_page(
			'acrossai_mcp_manager',
			__( 'MCP Manager Settings', 'acrossai-mcp-manager' ),
			__( 'Settings', 'acrossai-mcp-manager' ),
			'manage_options',
			'acrossai_mcp_manager_settings',
			array( $this, 'render_plugin_settings_page' )
		);
	}

	/**
	 * Register WP Settings API options.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'acrossai_mcp_manager_settings',
			'acrossai_mcp_npm_login_enabled',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		add_settings_section(
			'acrossai_mcp_npm_section',
			__( 'npm / CLI Settings', 'acrossai-mcp-manager' ),
			array( $this, 'render_npm_section_description' ),
			'acrossai_mcp_manager_settings'
		);

		add_settings_field(
			'acrossai_mcp_npm_login_enabled',
			__( 'Enable npm Login', 'acrossai-mcp-manager' ),
			array( $this, 'render_npm_login_field' ),
			'acrossai_mcp_manager_settings',
			'acrossai_mcp_npm_section'
		);
	}

	/**
	 * Render the description for the npm settings section.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_npm_section_description() {
		$auth_url = \ACROSSAI_MCP_MANAGER\Frontend\FrontendAuth::get_base_url();
		?>
		<p class="description">
			<?php esc_html_e( 'Control whether the npm / npx CLI login feature is available on server edit pages.', 'acrossai-mcp-manager' ); ?>
		</p>
		<div class="notice notice-warning inline" style="margin:8px 0 0;">
			<p>
				<strong><?php esc_html_e( 'Do not cache the CLI auth URL.', 'acrossai-mcp-manager' ); ?></strong>
				<?php
				printf(
					/* translators: %s: the frontend auth URL */
					wp_kses_post( __( 'The frontend authorization page at <code>%s</code> contains time-sensitive auth codes and nonces. If your hosting, CDN, or caching plugin caches this URL, authentication will silently fail. Exclude this path from all page-caching rules.', 'acrossai-mcp-manager' ) ),
					esc_html( $auth_url )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the npm login enable/disable field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_npm_login_field() {
		$enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
		?>
		<label for="acrossai_mcp_npm_login_enabled">
			<input
				type="checkbox"
				id="acrossai_mcp_npm_login_enabled"
				name="acrossai_mcp_npm_login_enabled"
				value="1"
				<?php checked( $enabled ); ?>
			>
			<?php esc_html_e( 'Allow CLI login via npm / npx', 'acrossai-mcp-manager' ); ?>
		</label>
		<p class="description">
			<?php
			esc_html_e(
				'When enabled, the npm tab on each server\'s edit page will display the npx CLI command and allow users to authenticate the AcrossAI MCP Manager CLI tool with this site. This lets terminal users connect to your MCP servers without manually configuring JSON files. Keep this disabled if you do not want to expose CLI-based authentication.',
				'acrossai-mcp-manager'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the plugin settings page (submenu).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_plugin_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ) );
		}
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'acrossai_mcp_manager_settings' );
				do_settings_sections( 'acrossai_mcp_manager_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Enqueue CSS and JS only on our admin page.
	 *
	 * Passes server_id to JS so REST calls can be server-scoped.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_acrossai_mcp_manager' !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'acrossai-mcp-manager-admin',
			ACROSSAI_MCP_MANAGER_URL . 'assets/admin.css',
			array(),
			ACROSSAI_MCP_MANAGER_VERSION
		);

		wp_enqueue_script(
			'acrossai-mcp-manager-admin',
			ACROSSAI_MCP_MANAGER_URL . 'assets/admin.js',
			array( 'wp-api' ),
			ACROSSAI_MCP_MANAGER_VERSION,
			true
		);

		wp_localize_script(
			'acrossai-mcp-manager-admin',
			'acrossaiMcpManagerData',
			array(
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'rest_url'     => rest_url( 'acrossai-mcp-manager/v1/' ),
				'current_user' => wp_get_current_user(),
				'server_id'    => isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'clients'      => array_keys( $this->app_passwords->get_clients() ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Page routing
	// -------------------------------------------------------------------------

	/**
	 * Route to list or edit view based on the ?action= param.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'edit' === $action ) {
			$this->render_edit_page();
		} elseif ( 'create' === $action ) {
			$this->render_create_page();
		} else {
			$this->render_list_page();
		}
	}

	// -------------------------------------------------------------------------
	// List page
	// -------------------------------------------------------------------------

	/**
	 * Render the MCP server list (WP_List_Table).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_list_page() {
		$list_table = new MCPServerListTable();
		$list_table->prepare_items();

		$updated = isset( $_GET['updated'] ) && '1' === $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification
		$deleted = isset( $_GET['deleted'] ) && '1' === $_GET['deleted']; // phpcs:ignore WordPress.Security.NonceVerification

		$add_new_url = add_query_arg(
			array(
				'page'   => 'acrossai_mcp_manager',
				'action' => 'create',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<a href="<?php echo esc_url( $add_new_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'acrossai-mcp-manager' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'MCP Server status updated successfully.', 'acrossai-mcp-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $deleted ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'MCP Server deleted successfully.', 'acrossai-mcp-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="acrossai_mcp_manager">
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Create page
	// -------------------------------------------------------------------------

	/**
	 * Render the "Add New MCP Server" form.
	 *
	 * Handles both the blank form (GET) and form errors after a failed POST
	 * (redirect back with ?error= param).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_create_page() {
		$back_url  = admin_url( 'admin.php?page=acrossai_mcp_manager' );
		$form_url  = add_query_arg(
			array(
				'page'   => 'acrossai_mcp_manager',
				'action' => 'create',
			),
			admin_url( 'admin.php' )
		);

		// Error messages from redirect.
		$error = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$slug  = isset( $_GET['slug'] ) ? sanitize_text_field( wp_unslash( $_GET['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1>
				<a href="<?php echo esc_url( $back_url ); ?>" class="acrossai-back-link">
					&#8592; <?php esc_html_e( 'MCP Servers', 'acrossai-mcp-manager' ); ?>
				</a>
				<?php esc_html_e( 'Add New MCP Server', 'acrossai-mcp-manager' ); ?>
			</h1>

			<?php if ( 'empty_name' === $error ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Server name is required.', 'acrossai-mcp-manager' ); ?></p>
				</div>
			<?php elseif ( 'slug_conflict' === $error ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: %s: generated slug */
							esc_html__( 'A server with the slug "%s" already exists. Please choose a different name.', 'acrossai-mcp-manager' ),
							esc_html( $slug )
						);
						?>
					</p>
				</div>
			<?php elseif ( 'db_error' === $error ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Could not save the server. Please try again.', 'acrossai-mcp-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $form_url ); ?>">
				<?php wp_nonce_field( 'acrossai_mcp_create_server' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="server_name"><?php esc_html_e( 'Server Name', 'acrossai-mcp-manager' ); ?> <span class="required" aria-hidden="true">*</span></label>
						</th>
						<td>
							<input
								type="text"
								id="server_name"
								name="server_name"
								class="regular-text"
								required
								value=""
								placeholder="<?php esc_attr_e( 'e.g. My Custom Server', 'acrossai-mcp-manager' ); ?>"
							>
							<p class="description">
								<?php esc_html_e( 'A unique human-readable name. The URL slug is auto-generated from this name and cannot be changed later.', 'acrossai-mcp-manager' ); ?>
							</p>
							<p class="description" id="slug-preview" style="margin-top:4px;"></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="description"><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></label>
						</th>
						<td>
							<textarea
								id="description"
								name="description"
								class="large-text"
								rows="3"
								placeholder="<?php esc_attr_e( 'Optional description for this server.', 'acrossai-mcp-manager' ); ?>"></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="server_route_namespace"><?php esc_html_e( 'Route Namespace', 'acrossai-mcp-manager' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="server_route_namespace"
								name="server_route_namespace"
								class="regular-text"
								value="mcp"
							>
							<p class="description">
								<?php esc_html_e( 'REST API namespace for this server. Default: mcp', 'acrossai-mcp-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="server_route"><?php esc_html_e( 'Route', 'acrossai-mcp-manager' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="server_route"
								name="server_route"
								class="regular-text"
								value=""
								placeholder="<?php esc_attr_e( 'Leave blank to use the auto-generated slug', 'acrossai-mcp-manager' ); ?>"
							>
							<p class="description">
								<?php esc_html_e( 'REST route path for this server. Defaults to the slug derived from the server name.', 'acrossai-mcp-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="server_version"><?php esc_html_e( 'Version', 'acrossai-mcp-manager' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="server_version"
								name="server_version"
								class="regular-text"
								value="v1.0.0"
							>
							<p class="description">
								<?php esc_html_e( 'MCP server version string. Default: v1.0.0', 'acrossai-mcp-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'MCP URL Preview', 'acrossai-mcp-manager' ); ?></th>
						<td>
							<code id="mcp-url-preview"><?php echo esc_html( rest_url( '' ) ); ?><span id="mcp-url-namespace">mcp</span>/<span id="mcp-url-slug">&hellip;</span></code>
							<p class="description">
								<?php esc_html_e( 'This URL will be active once you save and enable the server.', 'acrossai-mcp-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Add Server', 'acrossai-mcp-manager' ) ); ?>
			</form>
		</div>

		<script>
		(function() {
			var nameInput  = document.getElementById('server_name');
			var nsInput    = document.getElementById('server_route_namespace');
			var routeInput = document.getElementById('server_route');
			var slugPreview  = document.getElementById('slug-preview');
			var mcpSlugEl    = document.getElementById('mcp-url-slug');
			var mcpNsEl      = document.getElementById('mcp-url-namespace');

			if ( ! nameInput ) return;

			function toSlug(str) {
				return str
					.toLowerCase()
					.replace(/[^a-z0-9\s-]/g, '')
					.trim()
					.replace(/[\s]+/g, '-')
					.replace(/-+/g, '-');
			}

			function updatePreview() {
				var slug      = routeInput && routeInput.value.trim()
					? routeInput.value.trim()
					: toSlug(nameInput.value);
				var namespace = nsInput && nsInput.value.trim() ? nsInput.value.trim() : 'mcp';

				if ( slug ) {
					slugPreview.textContent = '<?php esc_html_e( 'Slug:', 'acrossai-mcp-manager' ); ?> ' + toSlug(nameInput.value);
					mcpSlugEl.textContent = slug;
				} else {
					slugPreview.textContent = '';
					mcpSlugEl.textContent = '\u2026';
				}
				if ( mcpNsEl ) {
					mcpNsEl.textContent = namespace;
				}
			}

			nameInput.addEventListener('input', updatePreview);
			if ( nsInput )    nsInput.addEventListener('input', updatePreview);
			if ( routeInput ) routeInput.addEventListener('input', updatePreview);
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Edit page
	// -------------------------------------------------------------------------

	/**
	 * Render the tabbed edit page for a single MCP server.
	 *
	 * Loads the server row from the DB using the ?server= param.
	 * Redirects to the list if the server ID is missing or invalid.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_edit_page() {
		$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$server    = $server_id ? MCPServerTable::get_by_id( $server_id ) : null;

		if ( ! $server ) {
			wp_safe_redirect( admin_url( 'admin.php?page=acrossai_mcp_manager' ) );
			exit;
		}

		$active_tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$active_client = isset( $_GET['client'] ) ? sanitize_key( $_GET['client'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$clients       = $this->app_passwords->get_clients();
		$back_url      = admin_url( 'admin.php?page=acrossai_mcp_manager' );
		$updated       = isset( $_GET['updated'] ) && '1' === $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification
		$created       = isset( $_GET['created'] ) && '1' === $_GET['created']; // phpcs:ignore WordPress.Security.NonceVerification
		$edit_error    = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// Default to first client when the clients tab is active but no sub-tab chosen.
		if ( 'clients' === $active_tab && '' === $active_client ) {
			$active_client = (string) array_key_first( $clients );
		}

		$make_tab_url = function( $tab ) use ( $server_id ) {
			return add_query_arg(
				array(
					'page'   => 'acrossai_mcp_manager',
					'action' => 'edit',
					'server' => $server_id,
					'tab'    => $tab,
				),
				admin_url( 'admin.php' )
			);
		};
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1>
				<a href="<?php echo esc_url( $back_url ); ?>" class="acrossai-back-link">
					&#8592; <?php esc_html_e( 'MCP Servers', 'acrossai-mcp-manager' ); ?>
				</a>
				<?php echo esc_html( $server['server_name'] ); ?>
			</h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'MCP Server status updated successfully.', 'acrossai-mcp-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $created ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'MCP Server created successfully.', 'acrossai-mcp-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $edit_error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
						<?php
						if ( 'empty_name' === $edit_error ) {
							esc_html_e( 'Server name is required.', 'acrossai-mcp-manager' );
						} else {
							esc_html_e( 'An error occurred. Please try again.', 'acrossai-mcp-manager' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php
			// Build a helper for client sub-tab URLs (tab=clients&client=X).
			$make_client_url = function( $client_id ) use ( $server_id ) {
				return add_query_arg(
					array(
						'page'   => 'acrossai_mcp_manager',
						'action' => 'edit',
						'server' => $server_id,
						'tab'    => 'clients',
						'client' => $client_id,
					),
					admin_url( 'admin.php' )
				);
			};
			// "clients" tab is considered active for any individual client sub-tab too.
			$clients_tab_active = in_array( $active_tab, array( 'clients' ), true );
			?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $make_tab_url( 'overview' ) ); ?>"
				   class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Overview', 'acrossai-mcp-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $make_tab_url( 'npm' ) ); ?>"
				   class="nav-tab <?php echo 'npm' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'npm', 'acrossai-mcp-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $make_client_url( array_key_first( $clients ) ) ); ?>"
				   class="nav-tab <?php echo $clients_tab_active ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'MCP Clients', 'acrossai-mcp-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $make_tab_url( 'wp-cli' ) ); ?>"
				   class="nav-tab <?php echo 'wp-cli' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'WP-CLI', 'acrossai-mcp-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $make_tab_url( 'tools' ) ); ?>"
				   class="nav-tab <?php echo 'tools' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Tools', 'acrossai-mcp-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $make_tab_url( 'abilities' ) ); ?>"
				   class="nav-tab <?php echo 'abilities' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Abilities', 'acrossai-mcp-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $make_tab_url( 'access-control' ) ); ?>"
				   class="nav-tab <?php echo 'access-control' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Access Control', 'acrossai-mcp-manager' ); ?>
				</a>
				<?php if ( 'database' === ( $server['registered_from'] ?? 'plugin' ) ) : ?>
				<a href="<?php echo esc_url( $make_tab_url( 'update-server' ) ); ?>"
				   class="nav-tab <?php echo 'update-server' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Update Server', 'acrossai-mcp-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $make_tab_url( 'danger-zone' ) ); ?>"
				   class="nav-tab acrossai-tab-danger <?php echo 'danger-zone' === $active_tab ? 'nav-tab-active acrossai-tab-danger-active' : ''; ?>">
					<?php esc_html_e( 'Danger Zone', 'acrossai-mcp-manager' ); ?>
				</a>
				<?php endif; ?>
			</nav>

			<div class="tab-content">
				<?php
				if ( 'overview' === $active_tab ) {
					$this->render_overview_tab( $server );
				} elseif ( 'npm' === $active_tab ) {
					$this->render_npm_tab( $server );
				} elseif ( 'clients' === $active_tab ) {
					$this->render_clients_tab( $server, $clients, $active_client, $server_id, $make_client_url );
				} elseif ( 'wp-cli' === $active_tab ) {
					$this->render_wpcli_tab( $server );
				} elseif ( 'tools' === $active_tab ) {
					$this->render_tools_tab( $server );
				} elseif ( 'abilities' === $active_tab ) {
					$this->render_abilities_tab( $server );
				} elseif ( 'access-control' === $active_tab ) {
					$this->render_access_control_tab( $server );
				} elseif ( 'update-server' === $active_tab ) {
					$this->render_update_server_tab( $server );
				} elseif ( 'danger-zone' === $active_tab ) {
					$this->render_danger_zone_tab( $server );
				}
				?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the Overview tab for a server.
	 *
	 * Shows the server name, description, live status, and an Enable/Disable
	 * toggle that redirects back to the same tab after the action.
	 *
	 * @since 1.0.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_overview_tab( array $server ) {
		$server_id       = (int) $server['id'];
		$enabled         = (bool) $server['is_enabled'];
		$registered_from = $server['registered_from'] ?? 'plugin';
		$is_database     = ( 'database' === $registered_from );

		$nonce      = wp_create_nonce( 'acrossai_mcp_toggle_' . $server_id );
		$toggle_url = add_query_arg(
			array(
				'page'        => 'acrossai_mcp_manager',
				'action'      => 'toggle_status',
				'server'      => $server_id,
				'redirect_to' => 'edit',
				'tab'         => 'overview',
				'_wpnonce'    => $nonce,
			),
			admin_url( 'admin.php' )
		);

		// Derive MCP URL from stored values; fall back gracefully for legacy rows.
		$slug      = ! empty( $server['server_slug'] ) ? $server['server_slug'] : sanitize_title( $server['server_name'] );
		$namespace = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
		$route     = ! empty( $server['server_route'] ) ? $server['server_route'] : $slug;
		$version   = ! empty( $server['server_version'] ) ? $server['server_version'] : 'v1.0.0';
		$mcp_url   = rest_url( $namespace . '/' . $route );

		$source_labels = array(
			'plugin'   => __( 'Plugin', 'acrossai-mcp-manager' ),
			'database' => __( 'Database', 'acrossai-mcp-manager' ),
			'theme'    => __( 'Theme', 'acrossai-mcp-manager' ),
			'core'     => __( 'Core', 'acrossai-mcp-manager' ),
		);
		$source_label = isset( $source_labels[ $registered_from ] ) ? $source_labels[ $registered_from ] : esc_html( $registered_from );

		$update_url = add_query_arg(
			array(
				'page'   => 'acrossai_mcp_manager',
				'action' => 'update',
				'server' => $server_id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = add_query_arg(
			array(
				'page'     => 'acrossai_mcp_manager',
				'action'   => 'delete',
				'server'   => $server_id,
				'_wpnonce' => wp_create_nonce( 'acrossai_mcp_delete_' . $server_id ),
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="mcp-tab-panel">

			<!-- ── Server info (read-only) ── -->
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Server Name', 'acrossai-mcp-manager' ); ?></th>
					<td><strong><?php echo esc_html( $server['server_name'] ); ?></strong></td>
				</tr>
				<?php if ( $server['description'] ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></th>
					<td><?php echo esc_html( $server['description'] ); ?></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Source', 'acrossai-mcp-manager' ); ?></th>
					<td>
						<span class="acrossai-source-badge acrossai-source-<?php echo esc_attr( sanitize_html_class( $registered_from ) ); ?>">
							<?php echo esc_html( $source_label ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Slug', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'acrossai-mcp-manager' ); ?></th>
					<td>
						<?php if ( $enabled ) : ?>
							<span class="acrossai-status-badge acrossai-status-active">
								<?php esc_html_e( 'Active', 'acrossai-mcp-manager' ); ?>
							</span>
							&nbsp;
							<a href="<?php echo esc_url( $toggle_url ); ?>" class="button button-small">
								<?php esc_html_e( 'Disable', 'acrossai-mcp-manager' ); ?>
							</a>
						<?php else : ?>
							<span class="acrossai-status-badge acrossai-status-inactive">
								<?php esc_html_e( 'Inactive', 'acrossai-mcp-manager' ); ?>
							</span>
							&nbsp;
							<a href="<?php echo esc_url( $toggle_url ); ?>" class="button button-primary button-small">
								<?php esc_html_e( 'Enable', 'acrossai-mcp-manager' ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP API URL', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $mcp_url ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Route Namespace', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $namespace ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Route', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $route ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Version', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $version ); ?></code></td>
				</tr>
			</table>

			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: link to profile page */
						wp_kses_post( __( 'Passwords generated in the client tabs are stored as WordPress Application Passwords. View, revoke, or manage them on your <a href="%s">profile page</a>.', 'acrossai-mcp-manager' ) ),
						esc_url( admin_url( 'profile.php' ) )
					);
					?>
				</p>
			</div>

			<h3><?php esc_html_e( 'Supported MCP Clients', 'acrossai-mcp-manager' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Click a client tab above to generate credentials and copy the ready-to-paste JSON configuration.', 'acrossai-mcp-manager' ); ?>
			</p>
			<ul class="mcp-clients-list">
				<?php foreach ( $this->app_passwords->get_clients() as $client_data ) : ?>
					<li>
						<strong><?php echo esc_html( $client_data['label'] ); ?></strong>
						— <?php echo esc_html( $client_data['description'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			</div>
		<?php
	}

	/**
	 * Render the npm tab showing the npx CLI command for this server.
	 *
	 * @since 1.0.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_npm_tab( array $server ) {
		$npm_login_enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
		$settings_url      = admin_url( 'admin.php?page=acrossai_mcp_manager_settings' );
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'npm / npx CLI', 'acrossai-mcp-manager' ); ?></h2>

			<?php if ( ! $npm_login_enabled ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'npm Login is currently disabled.', 'acrossai-mcp-manager' ); ?></strong>
					</p>
					<p>
						<?php
						printf(
							/* translators: %s: link to settings page */
							wp_kses_post( __( 'To use the npm / npx CLI feature, please <a href="%s">enable npm Login in Settings</a> first.', 'acrossai-mcp-manager' ) ),
							esc_url( $settings_url )
						);
						?>
					</p>
					<p class="description">
						<?php
						esc_html_e(
							'Enabling this feature allows terminal users to authenticate the AcrossAI MCP Manager CLI tool with this WordPress site using the npx command. It generates an Application Password automatically so you do not need to configure JSON files by hand. Only enable this if you intend to use the CLI for local development or trusted environments.',
							'acrossai-mcp-manager'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<?php
				$server_slug = ! empty( $server['server_slug'] )
					? $server['server_slug']
					: sanitize_title( $server['server_name'] );
				$site_url    = get_site_url();
				$command     = sprintf(
					'npx -y @acrossai/mcp-manager --siteurl=%s --server=%s',
					$site_url,
					$server_slug
				);
				?>
				<p class="description">
					<?php esc_html_e( 'Run this command in your terminal to connect the AcrossAI MCP Manager CLI to this server.', 'acrossai-mcp-manager' ); ?>
				</p>

				<div class="mcp-config-json">
					<label for="npm_command_<?php echo esc_attr( $server['id'] ); ?>">
						<strong><?php esc_html_e( 'Command', 'acrossai-mcp-manager' ); ?></strong>
					</label>
					<textarea
						id="npm_command_<?php echo esc_attr( $server['id'] ); ?>"
						class="widefat code mcp-cmd"
						rows="1"
						readonly><?php echo esc_textarea( $command ); ?></textarea>
					<button
						type="button"
						class="button copy-to-clipboard"
						data-field="npm_command_<?php echo esc_attr( $server['id'] ); ?>">
						<?php esc_html_e( 'Copy Command', 'acrossai-mcp-manager' ); ?>
					</button>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Site URL', 'acrossai-mcp-manager' ); ?></th>
						<td><code><?php echo esc_html( $site_url ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Server', 'acrossai-mcp-manager' ); ?></th>
						<td><code><?php echo esc_html( $server_slug ); ?></code></td>
					</tr>
				</table>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Render the grouped MCP Clients tab with a secondary sub-tab nav.
	 *
	 * @since 1.3.0
	 *
	 * @param array    $server          Server row from DB.
	 * @param array    $clients         All registered client definitions.
	 * @param string   $active_client   Currently active client ID.
	 * @param int      $server_id       DB ID of the server being edited.
	 * @param callable $make_client_url Closure: (string $client_id) → URL string.
	 *
	 * @return void
	 */
	private function render_clients_tab( array $server, array $clients, string $active_client, int $server_id, callable $make_client_url ) {
		?>
		<div class="mcp-tab-panel acrossai-clients-panel">

			<!-- Secondary sub-tab nav -->
			<div class="acrossai-client-tabs-nav">
				<?php foreach ( $clients as $client_id => $client_data ) : ?>
					<?php
					$is_active   = ( $client_id === $active_client );
					$tab_classes = 'acrossai-client-tab' . ( $is_active ? ' acrossai-client-tab-active' : '' );
					?>
					<a href="<?php echo esc_url( $make_client_url( $client_id ) ); ?>"
					   class="<?php echo esc_attr( $tab_classes ); ?>">
						<?php if ( ! empty( $client_data['icon'] ) ) : ?>
							<span class="acrossai-client-tab-icon"><?php echo esc_html( $client_data['icon'] ); ?></span>
						<?php endif; ?>
						<?php echo esc_html( $client_data['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div><!-- .acrossai-client-tabs-nav -->

			<!-- Active client content -->
			<?php
			if ( isset( $clients[ $active_client ] ) ) {
				$this->render_client_tab( $active_client, $clients[ $active_client ], $server_id );
			}
			?>

		</div><!-- .acrossai-clients-panel -->
		<?php
	}

	/**
	 * Render a client-specific configuration tab.
	 *
	 * @since 1.0.0
	 *
	 * @param string $client_id   Client ID key (e.g. 'vscode', 'claude').
	 * @param array  $client_data Client metadata array.
	 * @param int    $server_id   DB ID of the server being edited.
	 *
	 * @return void
	 */
	private function render_client_tab( $client_id, array $client_data, $server_id ) {
		?>
		<div class="mcp-tab-panel">

			<h2>
				<span class="mcp-client-icon"><?php echo esc_html( $client_data['icon'] ); ?></span>
				<?php echo esc_html( $client_data['label'] ); ?>
			</h2>
			<p class="description"><?php echo esc_html( $client_data['description'] ); ?></p>

			<!-- Step 1: Generate password -->
			<div class="password-actions">
				<button
					type="button"
					class="button button-primary generate-app-password"
					data-client="<?php echo esc_attr( $client_id ); ?>"
					data-server="<?php echo esc_attr( $server_id ); ?>">
					<?php esc_html_e( 'Generate New Application Password', 'acrossai-mcp-manager' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Creates a one-time password via WordPress Application Passwords. Shown only once — store it safely.', 'acrossai-mcp-manager' ); ?>
				</p>
			</div>

			<!-- Step 2: Config metadata -->
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Config File', 'acrossai-mcp-manager' ); ?></th>
					<td>
						<code id="config_path_<?php echo esc_attr( $client_id ); ?>">
							<?php esc_html_e( 'Loading…', 'acrossai-mcp-manager' ); ?>
						</code>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Top-Level Key', 'acrossai-mcp-manager' ); ?></th>
					<td>
						<code id="top_level_key_<?php echo esc_attr( $client_id ); ?>">
							<?php esc_html_e( 'Loading…', 'acrossai-mcp-manager' ); ?>
						</code>
					</td>
				</tr>
			</table>

			<!-- Step 3: JSON config -->
			<div class="mcp-config-json">
				<label for="config_json_<?php echo esc_attr( $client_id ); ?>">
					<strong><?php esc_html_e( 'Configuration JSON', 'acrossai-mcp-manager' ); ?></strong>
				</label>
				<textarea
					id="config_json_<?php echo esc_attr( $client_id ); ?>"
					class="widefat code"
					rows="12"
					readonly></textarea>
				<button
					type="button"
					class="button copy-to-clipboard"
					data-field="config_json_<?php echo esc_attr( $client_id ); ?>">
					<?php esc_html_e( 'Copy Configuration', 'acrossai-mcp-manager' ); ?>
				</button>
			</div>

			<!-- How-to reminder -->
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: MCP client label */
						esc_html__( 'Generate a password → copy the JSON → open the config file path above → paste under the top-level key → restart %s.', 'acrossai-mcp-manager' ),
						esc_html( $client_data['label'] )
					);
					?>
				</p>
			</div>

		</div>
		<?php
	}

	/**
	 * Render the WP-CLI tab for a server.
	 *
	 * Shows the wp-cli command to run locally for one-step credential generation
	 * and optional automatic config-file writing.
	 *
	 * @since 1.2.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_wpcli_tab( array $server ) {
		$server_slug  = ! empty( $server['server_slug'] ) ? $server['server_slug'] : sanitize_title( $server['server_name'] );
		$namespace    = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
		$route        = ! empty( $server['server_route'] ) ? $server['server_route'] : $server_slug;
		$site_slug    = sanitize_title( get_bloginfo( 'name' ) );
		$server_key   = $site_slug ? $site_slug . '-' . $server_slug : $server_slug;
		$mcp_url      = rest_url( $namespace . '/' . $route );

		$cmd_basic      = sprintf( 'wp acrossai-mcp setup --server=%s', $server_slug );
		$cmd_write      = sprintf( 'wp acrossai-mcp setup --server=%s --write', $server_slug );
		$cmd_with_user  = sprintf( 'wp acrossai-mcp setup --server=%s --write --user=admin', $server_slug );
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'WP-CLI Setup', 'acrossai-mcp-manager' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Run one of the commands below from your server\'s terminal. The command generates a WordPress Application Password and outputs (or writes) the ready-to-use MCP config for every supported client.', 'acrossai-mcp-manager' ); ?>
			</p>

			<!-- Print config only -->
			<div class="mcp-config-json">
				<label for="wpcli_cmd_print_<?php echo esc_attr( $server['id'] ); ?>">
					<strong><?php esc_html_e( 'Print config (no files written)', 'acrossai-mcp-manager' ); ?></strong>
				</label>
				<textarea
					id="wpcli_cmd_print_<?php echo esc_attr( $server['id'] ); ?>"
					class="widefat code mcp-cmd"
					rows="1"
					readonly><?php echo esc_textarea( $cmd_basic ); ?></textarea>
				<button
					type="button"
					class="button copy-to-clipboard"
					data-field="wpcli_cmd_print_<?php echo esc_attr( $server['id'] ); ?>">
					<?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?>
				</button>
			</div>

			<!-- Write config files automatically -->
			<div class="mcp-config-json" style="margin-top:16px;">
				<label for="wpcli_cmd_write_<?php echo esc_attr( $server['id'] ); ?>">
					<strong><?php esc_html_e( 'Generate credentials and write config files', 'acrossai-mcp-manager' ); ?></strong>
				</label>
				<textarea
					id="wpcli_cmd_write_<?php echo esc_attr( $server['id'] ); ?>"
					class="widefat code mcp-cmd"
					rows="1"
					readonly><?php echo esc_textarea( $cmd_write ); ?></textarea>
				<button
					type="button"
					class="button copy-to-clipboard"
					data-field="wpcli_cmd_write_<?php echo esc_attr( $server['id'] ); ?>">
					<?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?>
				</button>
			</div>

			<!-- Optional --user flag -->
			<div class="mcp-config-json" style="margin-top:16px;">
				<label for="wpcli_cmd_user_<?php echo esc_attr( $server['id'] ); ?>">
					<strong><?php esc_html_e( 'Specify a WordPress user (optional)', 'acrossai-mcp-manager' ); ?></strong>
				</label>
				<p class="description" style="margin-bottom:6px;">
					<?php esc_html_e( 'By default WP-CLI runs as the OS user. If the Application Password should belong to a specific WordPress account, append the global --user flag:', 'acrossai-mcp-manager' ); ?>
				</p>
				<textarea
					id="wpcli_cmd_user_<?php echo esc_attr( $server['id'] ); ?>"
					class="widefat code mcp-cmd"
					rows="1"
					readonly><?php echo esc_textarea( $cmd_with_user ); ?></textarea>
				<button
					type="button"
					class="button copy-to-clipboard"
					data-field="wpcli_cmd_user_<?php echo esc_attr( $server['id'] ); ?>">
					<?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?>
				</button>
			</div>

			<!-- Details table -->
			<table class="form-table" role="presentation" style="margin-top:16px;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Server slug', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $server_slug ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Config key', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $server_key ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP URL', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $mcp_url ); ?></code></td>
				</tr>
			</table>

			<!-- What --write does -->
			<div class="notice notice-info inline" style="margin-top:16px;">
				<p>
					<strong><?php esc_html_e( 'What --write does:', 'acrossai-mcp-manager' ); ?></strong>
					<?php esc_html_e( 'Detects Claude Desktop, Cursor, VS Code, and Claude Code config files on the machine and merges the new entry directly. A .bak backup is created before each file is modified.', 'acrossai-mcp-manager' ); ?>
				</p>
				<p>
					<?php
					printf(
						/* translators: %s: link to WP-CLI site */
						wp_kses_post( __( 'Requires <a href="https://wp-cli.org" target="_blank" rel="noopener">WP-CLI</a> to be installed on the server.', 'acrossai-mcp-manager' ) )
					);
					?>
				</p>
			</div>

		</div>
		<?php
	}

	/**
	 * Render the Tools tab — lists the MCP tools exposed by this server.
	 *
	 * Shows nothing meaningful when the server is disabled.
	 *
	 * @since 1.2.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_tools_tab( array $server ) {
		$enabled = (bool) $server['is_enabled'];

		// Tools exposed by every MCP adapter server.
		$tools = array(
			array(
				'name'        => 'mcp-adapter/discover-abilities',
				'label'       => __( 'Discover Abilities', 'acrossai-mcp-manager' ),
				'description' => __( 'Lists all publicly available WordPress abilities registered on this site. AI clients use this to discover what actions the server can perform.', 'acrossai-mcp-manager' ),
			),
			array(
				'name'        => 'mcp-adapter/get-ability-info',
				'label'       => __( 'Get Ability Info', 'acrossai-mcp-manager' ),
				'description' => __( 'Returns detailed information about a specific ability, including its input/output schema and description. Used by AI clients before executing an ability.', 'acrossai-mcp-manager' ),
			),
			array(
				'name'        => 'mcp-adapter/execute-ability',
				'label'       => __( 'Execute Ability', 'acrossai-mcp-manager' ),
				'description' => __( 'Executes a WordPress ability with the provided input parameters and returns the result. This is the primary tool used by AI clients to interact with WordPress.', 'acrossai-mcp-manager' ),
			),
		);
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'MCP Tools', 'acrossai-mcp-manager' ); ?></h2>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Server is disabled.', 'acrossai-mcp-manager' ); ?></strong>
						<?php esc_html_e( 'Enable the server on the Overview tab to make these tools available to MCP clients.', 'acrossai-mcp-manager' ); ?>
					</p>
				</div>
			<?php else : ?>

				<p class="description">
					<?php esc_html_e( 'These are the MCP tools this server exposes to connected AI clients. Every server in this plugin provides the same three core tools backed by the WordPress Abilities API.', 'acrossai-mcp-manager' ); ?>
				</p>

				<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
					<thead>
						<tr>
							<th style="width:30%"><?php esc_html_e( 'Tool ID', 'acrossai-mcp-manager' ); ?></th>
							<th style="width:20%"><?php esc_html_e( 'Name', 'acrossai-mcp-manager' ); ?></th>
							<th><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tools as $tool ) : ?>
							<tr>
								<td><code><?php echo esc_html( $tool['name'] ); ?></code></td>
								<td><strong><?php echo esc_html( $tool['label'] ); ?></strong></td>
								<td><?php echo esc_html( $tool['description'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="notice notice-info inline" style="margin-top:16px;">
					<p>
						<?php
						esc_html_e( 'Tools are defined by the wordpress/mcp-adapter package and are the same for all servers. They act as a bridge — AI clients call these tools to discover and execute the WordPress abilities listed in the Abilities tab.', 'acrossai-mcp-manager' );
						?>
					</p>
				</div>

			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Render the Abilities tab — lists all registered WordPress abilities.
	 *
	 * Shows nothing meaningful when the server is disabled.
	 * Requires the WordPress Abilities API (wp_get_abilities) to be available.
	 *
	 * @since 1.2.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_abilities_tab( array $server ) {
		$enabled = (bool) $server['is_enabled'];
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'WordPress Abilities', 'acrossai-mcp-manager' ); ?></h2>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Server is disabled.', 'acrossai-mcp-manager' ); ?></strong>
						<?php esc_html_e( 'Enable the server on the Overview tab to expose these abilities to MCP clients.', 'acrossai-mcp-manager' ); ?>
					</p>
				</div>
			<?php elseif ( ! function_exists( 'wp_get_abilities' ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'The WordPress Abilities API is not available on this installation.', 'acrossai-mcp-manager' ); ?></p>
				</div>
			<?php else : ?>
				<?php
				$abilities = wp_get_abilities();

				// Separate MCP-public abilities from all others for display.
				$mcp_public = array();
				$mcp_private = array();
				foreach ( $abilities as $ability ) {
					$meta = $ability->get_meta();
					if ( ! empty( $meta['mcp']['public'] ) ) {
						$mcp_public[] = $ability;
					} else {
						$mcp_private[] = $ability;
					}
				}
				?>

				<p class="description">
					<?php
					printf(
						/* translators: 1: count of MCP-exposed abilities, 2: total count */
						esc_html__( '%1$d of %2$d registered abilities are exposed via MCP (mcp.public = true).', 'acrossai-mcp-manager' ),
						count( $mcp_public ),
						count( $abilities )
					);
					?>
				</p>

				<?php if ( empty( $abilities ) ) : ?>
					<div class="notice notice-info inline" style="margin-top:12px;">
						<p><?php esc_html_e( 'No abilities are registered yet. Abilities are registered by plugins and themes using the WordPress Abilities API.', 'acrossai-mcp-manager' ); ?></p>
					</div>
				<?php else : ?>

					<h3 style="margin-top:20px;">
						<?php
						printf(
							/* translators: %d: count */
							esc_html__( 'MCP-Exposed Abilities (%d)', 'acrossai-mcp-manager' ),
							count( $mcp_public )
						);
						?>
					</h3>
					<p class="description"><?php esc_html_e( 'These abilities are visible and executable by connected AI clients.', 'acrossai-mcp-manager' ); ?></p>

					<?php if ( empty( $mcp_public ) ) : ?>
						<p class="description" style="margin-top:8px;font-style:italic;">
							<?php esc_html_e( 'None. Set mcp.public = true in an ability\'s meta to expose it.', 'acrossai-mcp-manager' ); ?>
						</p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
							<thead>
								<tr>
									<th style="width:30%"><?php esc_html_e( 'Ability Name', 'acrossai-mcp-manager' ); ?></th>
									<th style="width:20%"><?php esc_html_e( 'Label', 'acrossai-mcp-manager' ); ?></th>
									<th style="width:12%"><?php esc_html_e( 'Type', 'acrossai-mcp-manager' ); ?></th>
									<th style="width:12%"><?php esc_html_e( 'Category', 'acrossai-mcp-manager' ); ?></th>
									<th><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $mcp_public as $ability ) :
									$meta      = $ability->get_meta();
									$mcp_type  = $meta['mcp']['type'] ?? 'tool';
								?>
									<tr>
										<td><code><?php echo esc_html( $ability->get_name() ); ?></code></td>
										<td><?php echo esc_html( $ability->get_label() ); ?></td>
										<td>
											<span class="acrossai-source-badge acrossai-source-<?php echo esc_attr( sanitize_html_class( $mcp_type ) ); ?>">
												<?php echo esc_html( ucfirst( $mcp_type ) ); ?>
											</span>
										</td>
										<td><code><?php echo esc_html( $ability->get_category() ); ?></code></td>
										<td><?php echo esc_html( $ability->get_description() ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<?php if ( ! empty( $mcp_private ) ) : ?>
						<h3 style="margin-top:24px;">
							<?php
							printf(
								/* translators: %d: count */
								esc_html__( 'Other Registered Abilities (%d)', 'acrossai-mcp-manager' ),
								count( $mcp_private )
							);
							?>
						</h3>
						<p class="description"><?php esc_html_e( 'These abilities are registered but not exposed via MCP (mcp.public is not set).', 'acrossai-mcp-manager' ); ?></p>

						<table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
							<thead>
								<tr>
									<th style="width:30%"><?php esc_html_e( 'Ability Name', 'acrossai-mcp-manager' ); ?></th>
									<th style="width:20%"><?php esc_html_e( 'Label', 'acrossai-mcp-manager' ); ?></th>
									<th style="width:12%"><?php esc_html_e( 'Category', 'acrossai-mcp-manager' ); ?></th>
									<th><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $mcp_private as $ability ) : ?>
									<tr>
										<td><code><?php echo esc_html( $ability->get_name() ); ?></code></td>
										<td><?php echo esc_html( $ability->get_label() ); ?></td>
										<td><code><?php echo esc_html( $ability->get_category() ); ?></code></td>
										<td><?php echo esc_html( $ability->get_description() ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

				<?php endif; ?>

			<?php endif; ?>

		</div>
		<?php
	}

	/**
	/**
	 * Render the Access Control tab — per-server access rules.
	 *
	 * The tab is available for all server types (plugin-registered and database).
	 * It shows:
	 *   - A dropdown to choose the access control type (Everyone / WordPress Role / …).
	 *   - When a type other than "Everyone" is selected, a checkbox list of allowed
	 *     options rendered by the chosen provider.
	 *   - A save button that POSTs to `?action=save_access_control&server={id}`.
	 *
	 * Access decision hierarchy (enforced by AccessControlManager):
	 *   1. Administrators always pass.
	 *   2. "Everyone" type → any authenticated user passes.
	 *   3. Other types → the provider's user_has_access() is called.
	 *
	 * @since 1.4.0
	 *
	 * @param array $server DB row for the server being viewed.
	 *
	 * @return void
	 */
	private function render_access_control_tab( array $server ) {
		$server_id = (int) $server['id'];

		// Parse stored config.
		$raw_ac    = $server['access_control'] ?? '';
		$ac_config = array( 'type' => 'everyone', 'options' => array() );
		if ( '' !== $raw_ac ) {
			$decoded = json_decode( $raw_ac, true );
			if ( is_array( $decoded ) ) {
				$ac_config['type']    = sanitize_key( $decoded['type'] ?? 'everyone' );
				$ac_config['options'] = array_map( 'sanitize_key', (array) ( $decoded['options'] ?? array() ) );
			}
		}

		// Collect registered providers.
		$manager   = new AccessControlManager();
		$providers = $manager->get_providers();

		$form_action = add_query_arg(
			array(
				'page'   => 'acrossai_mcp_manager',
				'action' => 'save_access_control',
				'server' => $server_id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'Access Control', 'acrossai-mcp-manager' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Control which users are allowed to connect to this MCP server. Administrators always have access regardless of this setting.', 'acrossai-mcp-manager' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( $form_action ); ?>" id="acrossai-ac-form">
				<?php wp_nonce_field( 'acrossai_mcp_access_control_' . $server_id ); ?>

				<table class="form-table" role="presentation">
					<tbody>

						<!-- Access control type selector -->
						<tr>
							<th scope="row">
								<label for="ac_type"><?php esc_html_e( 'Who can access', 'acrossai-mcp-manager' ); ?></label>
							</th>
							<td>
								<select name="ac_type" id="ac_type" class="regular-text acrossai-ac-type-select">
									<option value="everyone" <?php selected( $ac_config['type'], 'everyone' ); ?>>
										<?php esc_html_e( 'Everyone (no restriction)', 'acrossai-mcp-manager' ); ?>
									</option>
									<?php foreach ( $providers as $provider_id => $provider ) : ?>
										<?php if ( $provider->is_available() ) : ?>
											<option value="<?php echo esc_attr( $provider_id ); ?>"
											        <?php selected( $ac_config['type'], $provider_id ); ?>>
												<?php echo esc_html( $provider->get_label() ); ?>
											</option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Select "Everyone" to allow any authenticated user. Select a provider to restrict access to specific groups.', 'acrossai-mcp-manager' ); ?>
								</p>
							</td>
						</tr>

						<!-- Provider-specific options (shown/hidden via JS) -->
						<?php foreach ( $providers as $provider_id => $provider ) : ?>
							<?php if ( ! $provider->is_available() ) { continue; } ?>
							<?php $options = $provider->get_options(); ?>
							<?php if ( empty( $options ) ) { continue; } ?>

							<tr class="acrossai-ac-options-row acrossai-ac-options-<?php echo esc_attr( $provider_id ); ?>"
							    style="<?php echo $ac_config['type'] === $provider_id ? '' : 'display:none'; ?>">
								<th scope="row">
									<?php echo esc_html( $provider->get_label() ); ?>
								</th>
								<td>
									<fieldset>
										<legend class="screen-reader-text">
											<?php
											printf(
												/* translators: %s: provider label */
												esc_html__( 'Allowed %s values', 'acrossai-mcp-manager' ),
												esc_html( $provider->get_label() )
											);
											?>
										</legend>
										<p class="description" style="margin-bottom:8px;">
											<?php
											printf(
												/* translators: %s: provider label */
												esc_html__( 'Select which %s values may access this server. Leave all unchecked to deny everyone (except administrators).', 'acrossai-mcp-manager' ),
												esc_html( $provider->get_label() )
											);
											?>
										</p>
										<?php foreach ( $options as $option ) : ?>
											<?php $checked = in_array( $option['id'], $ac_config['options'], true ); ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox"
												       name="ac_options[]"
												       value="<?php echo esc_attr( $option['id'] ); ?>"
												       <?php checked( $checked ); ?>>
												<?php echo esc_html( $option['label'] ); ?>
											</label>
										<?php endforeach; ?>
									</fieldset>
								</td>
							</tr>

						<?php endforeach; ?>

					</tbody>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Save Access Control', 'acrossai-mcp-manager' ), 'primary', 'submit', false ); ?>
				</p>
			</form>

		</div><!-- .mcp-tab-panel -->

		<script>
		(function() {
			var select = document.getElementById('ac_type');
			if ( ! select ) { return; }

			function toggleRows() {
				var chosen = select.value;
				document.querySelectorAll('.acrossai-ac-options-row').forEach(function(row) {
					var isMatch = row.classList.contains('acrossai-ac-options-' + chosen);
					row.style.display = isMatch ? '' : 'none';
					// Uncheck hidden options so they are not submitted.
					if ( ! isMatch ) {
						row.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
							cb.checked = false;
						});
					}
				});
			}

			select.addEventListener('change', toggleRows);
		}());
		</script>
		<?php
	}

	/**
	 * Render the Update Server tab — editable fields for database-registered servers.
	 *
	 * @since 1.2.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_update_server_tab( array $server ) {
		if ( 'database' !== ( $server['registered_from'] ?? 'plugin' ) ) {
			wp_die( esc_html__( 'This server cannot be edited.', 'acrossai-mcp-manager' ) );
		}

		$server_id = (int) $server['id'];
		$slug      = ! empty( $server['server_slug'] ) ? $server['server_slug'] : sanitize_title( $server['server_name'] );
		$namespace = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
		$route     = ! empty( $server['server_route'] ) ? $server['server_route'] : $slug;
		$version   = ! empty( $server['server_version'] ) ? $server['server_version'] : 'v1.0.0';

		$update_url = add_query_arg(
			array(
				'page'   => 'acrossai_mcp_manager',
				'action' => 'update',
				'server' => $server_id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'Update Server', 'acrossai-mcp-manager' ); ?></h2>

			<form method="post" action="<?php echo esc_url( $update_url ); ?>">
				<?php wp_nonce_field( 'acrossai_mcp_update_' . $server_id ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="edit_server_name"><?php esc_html_e( 'Server Name', 'acrossai-mcp-manager' ); ?> <span aria-hidden="true">*</span></label>
						</th>
						<td>
							<input
								type="text"
								id="edit_server_name"
								name="server_name"
								class="regular-text"
								required
								value="<?php echo esc_attr( $server['server_name'] ); ?>"
							>
							<p class="description">
								<?php esc_html_e( 'Display name only. The slug is permanent and cannot be changed.', 'acrossai-mcp-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="edit_description"><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></label>
						</th>
						<td>
							<textarea
								id="edit_description"
								name="description"
								class="large-text"
								rows="3"
							><?php echo esc_textarea( $server['description'] ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="edit_namespace"><?php esc_html_e( 'Route Namespace', 'acrossai-mcp-manager' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="edit_namespace"
								name="server_route_namespace"
								class="regular-text"
								value="<?php echo esc_attr( $namespace ); ?>"
							>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="edit_route"><?php esc_html_e( 'Route', 'acrossai-mcp-manager' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="edit_route"
								name="server_route"
								class="regular-text"
								value="<?php echo esc_attr( $route ); ?>"
							>
							<p class="description">
								<?php esc_html_e( 'Changing the route will change the MCP URL. Update any existing client configs if you do this.', 'acrossai-mcp-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="edit_version"><?php esc_html_e( 'Version', 'acrossai-mcp-manager' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="edit_version"
								name="server_version"
								class="regular-text"
								value="<?php echo esc_attr( $version ); ?>"
							>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Slug', 'acrossai-mcp-manager' ); ?></th>
						<td>
							<code><?php echo esc_html( $slug ); ?></code>
							<p class="description">
								<?php esc_html_e( 'The slug is set at creation and cannot be changed.', 'acrossai-mcp-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Update Server', 'acrossai-mcp-manager' ) ); ?>
			</form>

		</div>
		<?php
	}

	/**
	 * Render the Danger Zone tab — delete action for database-registered servers.
	 *
	 * Only reachable for servers where registered_from = 'database'.
	 * Plugin-registered servers do not show this tab in the nav.
	 *
	 * @since 1.2.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_danger_zone_tab( array $server ) {
		// Guard: only database servers may be deleted.
		if ( 'database' !== ( $server['registered_from'] ?? 'plugin' ) ) {
			wp_die( esc_html__( 'This server cannot be deleted.', 'acrossai-mcp-manager' ) );
		}

		$server_id  = (int) $server['id'];
		$delete_url = add_query_arg(
			array(
				'page'     => 'acrossai_mcp_manager',
				'action'   => 'delete',
				'server'   => $server_id,
				'_wpnonce' => wp_create_nonce( 'acrossai_mcp_delete_' . $server_id ),
			),
			admin_url( 'admin.php' )
		);

		$slug    = ! empty( $server['server_slug'] ) ? $server['server_slug'] : sanitize_title( $server['server_name'] );
		$ns      = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
		$route   = ! empty( $server['server_route'] ) ? $server['server_route'] : $slug;
		$mcp_url = rest_url( $ns . '/' . $route );
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'Danger Zone', 'acrossai-mcp-manager' ); ?></h2>

			<div class="acrossai-danger-zone">

				<h3><?php esc_html_e( 'Delete This Server', 'acrossai-mcp-manager' ); ?></h3>

				<table class="form-table" role="presentation" style="margin-bottom:16px;">
					<tr>
						<th scope="row"><?php esc_html_e( 'Server Name', 'acrossai-mcp-manager' ); ?></th>
						<td><strong><?php echo esc_html( $server['server_name'] ); ?></strong></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Slug', 'acrossai-mcp-manager' ); ?></th>
						<td><code><?php echo esc_html( $slug ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'MCP URL', 'acrossai-mcp-manager' ); ?></th>
						<td><code><?php echo esc_html( $mcp_url ); ?></code></td>
					</tr>
				</table>

				<p>
					<?php esc_html_e( 'Deleting this server will:', 'acrossai-mcp-manager' ); ?>
				</p>
				<ul style="list-style:disc;margin-left:20px;margin-bottom:16px;">
					<li><?php esc_html_e( 'Permanently remove it from the database.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'Deactivate the MCP endpoint at the URL above.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'Break any AI client configs pointing to that URL.', 'acrossai-mcp-manager' ); ?></li>
				</ul>

				<p><strong><?php esc_html_e( 'This action cannot be undone.', 'acrossai-mcp-manager' ); ?></strong></p>

				<a
					href="<?php echo esc_url( $delete_url ); ?>"
					class="button acrossai-btn-delete"
					onclick="return confirm('<?php echo esc_js( sprintf( __( 'Delete "%s" permanently? This cannot be undone.', 'acrossai-mcp-manager' ), $server['server_name'] ) ); ?>')">
					<?php esc_html_e( 'Delete Server', 'acrossai-mcp-manager' ); ?>
				</a>

			</div>

		</div>
		<?php
	}

	/**
	 * Render the admin notice shown when the MCP adapter package is missing.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_missing_adapter_notice() {
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				echo wp_kses_post(
					__( 'AcrossAI MCP Manager: the <code>wordpress/mcp-adapter</code> package is not installed. Please run <code>composer install</code> inside the plugin directory.', 'acrossai-mcp-manager' )
				);
				?>
			</p>
		</div>
		<?php
	}
}
