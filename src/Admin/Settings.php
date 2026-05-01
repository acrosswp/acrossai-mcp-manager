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

use WPBoilerplate\AccessControl\AccessControlTable;
use WPBoilerplate\AccessControl\Admin\AccessControlUI;
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

		if ( ! in_array( $action, array( 'toggle_status', 'delete', 'create', 'update', 'save_access_control', 'save_connector_settings', 'create_oauth_client', 'revoke_oauth_client' ), true ) ) {
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

			$ns    = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
			$route = ! empty( $server['server_route'] ) ? $server['server_route'] : $server['server_slug'];
			AccessControlTable::update( $ns, $route, AccessControlUI::extract_posted_config( $_POST ) );

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

		// ── save_connector_settings (POST) ───────────────────────────────────────
		if ( 'save_connector_settings' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

			check_admin_referer( 'acrossai_mcp_connector_' . $server_id );

			$server = $server_id ? MCPServerTable::get_by_id( $server_id ) : null;
			if ( ! $server ) {
				wp_die( esc_html__( 'Invalid server.', 'acrossai-mcp-manager' ) );
			}

			$enabled = isset( $_POST['connector_enabled'] ) && '1' === $_POST['connector_enabled'];
			MCPServerTable::set_connector_enabled( $server_id, $enabled );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'acrossai_mcp_manager',
						'action'  => 'edit',
						'server'  => $server_id,
						'tab'     => 'connector',
						'updated' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// ── create_oauth_client (POST) ────────────────────────────────────────────
		if ( 'create_oauth_client' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

			check_admin_referer( 'acrossai_mcp_oauth_create_client_' . $server_id );

			$server = $server_id ? MCPServerTable::get_by_id( $server_id ) : null;
			if ( ! $server ) {
				wp_die( esc_html__( 'Invalid server.', 'acrossai-mcp-manager' ) );
			}

			$client_name  = isset( $_POST['client_name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['client_name'] ) ), 0, 100 ) : '';
			$redirect_uri = isset( $_POST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_uri'] ) ) : 'https://claude.ai/api/mcp/auth_callback';

			if ( empty( $client_name ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'   => 'acrossai_mcp_manager',
							'action' => 'edit',
							'server' => $server_id,
							'tab'    => 'connector',
							'error'  => 'missing_name',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$client_id     = 'mcpc_' . bin2hex( random_bytes( 12 ) );
			$client_secret = bin2hex( random_bytes( 32 ) );
			$secret_hash   = wp_hash_password( $client_secret );

			\ACROSSAI_MCP_MANAGER\Database\OAuthClientsTable::insert(
				$client_id,
				$secret_hash,
				$client_name,
				array( $redirect_uri )
			);

			// Store plaintext secret once (60 s) so the renderer can show it.
			set_transient(
				'acrossai_mcp_oauth_new_secret_' . get_current_user_id(),
				array(
					'client_id'   => $client_id,
					'client_name' => $client_name,
					'secret'      => $client_secret,
				),
				60
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'acrossai_mcp_manager',
						'action'  => 'edit',
						'server'  => $server_id,
						'tab'     => 'connector',
						'created' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// ── revoke_oauth_client (POST) ────────────────────────────────────────────
		if ( 'revoke_oauth_client' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

			check_admin_referer( 'acrossai_mcp_oauth_revoke_client_' . $client_id );

			if ( $client_id ) {
				\ACROSSAI_MCP_MANAGER\Database\OAuthTokensTable::delete_by_client( $client_id );
				\ACROSSAI_MCP_MANAGER\Database\OAuthClientsTable::delete( $client_id );
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'acrossai_mcp_manager',
						'action'  => 'edit',
						'server'  => $server_id,
						'tab'     => 'connector',
						'revoked' => '1',
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
			'acrossai_mcp_oauth_enabled',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		add_settings_section(
			'acrossai_mcp_oauth_section',
			__( 'Claude.ai Connectors (BETA)', 'acrossai-mcp-manager' ),
			array( $this, 'render_oauth_section_description' ),
			'acrossai_mcp_manager_settings'
		);

		add_settings_field(
			'acrossai_mcp_oauth_enabled',
			__( 'Enable Claude.ai Connectors', 'acrossai-mcp-manager' ),
			array( $this, 'render_oauth_enabled_field' ),
			'acrossai_mcp_manager_settings',
			'acrossai_mcp_oauth_section'
		);

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
	 * Render the description for the Claude.ai Connectors settings section.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function render_oauth_section_description() {
		$is_https  = is_ssl();
		$is_local  = strpos( home_url(), '.local' ) !== false || strpos( home_url(), 'localhost' ) !== false;
		?>
		<p class="description">
			<?php esc_html_e( 'Allow Claude.ai to connect to your MCP servers directly as a custom connector. Each server gets a unique URL you can paste into the Claude.ai "Add custom connector" dialog — no manual credentials required.', 'acrossai-mcp-manager' ); ?>
		</p>
		<?php if ( ! $is_https || $is_local ) : ?>
		<div class="notice notice-warning inline" style="margin:8px 0 0;">
			<p>
				<strong><?php esc_html_e( 'Requirements not met:', 'acrossai-mcp-manager' ); ?></strong>
				<?php if ( ! $is_https ) : ?>
					<?php esc_html_e( 'Your site must use HTTPS. Claude.ai cannot connect to HTTP sites.', 'acrossai-mcp-manager' ); ?>
				<?php endif; ?>
				<?php if ( $is_local ) : ?>
					<?php esc_html_e( 'Your site is on a local domain (.local / localhost) that Claude.ai cannot reach. Use a public HTTPS URL.', 'acrossai-mcp-manager' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the Claude.ai Connectors master enable/disable field.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function render_oauth_enabled_field() {
		$enabled = (bool) get_option( 'acrossai_mcp_oauth_enabled', false );
		?>
		<label for="acrossai_mcp_oauth_enabled">
			<input
				type="checkbox"
				id="acrossai_mcp_oauth_enabled"
				name="acrossai_mcp_oauth_enabled"
				value="1"
				<?php checked( $enabled ); ?>
			>
			<?php esc_html_e( 'Enable OAuth 2.1 connector support', 'acrossai-mcp-manager' ); ?>
		</label>
		<p class="description">
			<?php
			esc_html_e(
				'When enabled, OAuth 2.1 discovery endpoints are registered and each server can optionally be connected to Claude.ai. Disable to remove all OAuth functionality without deleting token data.',
				'acrossai-mcp-manager'
			);
			?>
		</p>
		<?php
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

		// Enqueue library access-control assets (CSS + JS for provider-rendered UI).
		$this->get_access_control_ui()->enqueue_assets();
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
				<p>
					<?php
					$updated_messages = array(
						'overview'       => __( 'MCP Server status updated successfully.', 'acrossai-mcp-manager' ),
						'access-control' => __( 'Access control updated successfully.', 'acrossai-mcp-manager' ),
						'update-server'  => __( 'MCP Server updated successfully.', 'acrossai-mcp-manager' ),
						'connector'      => __( 'Connector settings updated successfully.', 'acrossai-mcp-manager' ),
					);
					echo esc_html( $updated_messages[ $active_tab ] ?? __( 'Settings updated successfully.', 'acrossai-mcp-manager' ) );
					?>
				</p>
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
				<a href="<?php echo esc_url( $make_tab_url( 'mcp-tracker' ) ); ?>"
				   class="nav-tab <?php echo 'mcp-tracker' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'MCP Tracker', 'acrossai-mcp-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( $make_tab_url( 'connector' ) ); ?>"
				   class="nav-tab <?php echo 'connector' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Claude.ai Connector', 'acrossai-mcp-manager' ); ?>
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
				} elseif ( 'mcp-tracker' === $active_tab ) {
					$this->render_mcp_tracker_tab( $server );
				} elseif ( 'connector' === $active_tab ) {
					$this->render_connector_tab( $server );
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
	 * Shows the STDIO transport commands from the mcp-adapter package for local
	 * subprocess-mode connections.
	 *
	 * @since 1.2.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_wpcli_tab( array $server ) {
		$server_slug  = ! empty( $server['server_slug'] ) ? $server['server_slug'] : sanitize_title( $server['server_name'] );
		$site_slug    = sanitize_title( get_bloginfo( 'name' ) );
		$server_key   = $site_slug ? $site_slug . '-' . $server_slug : $server_slug;

		// STDIO transport commands (built into the mcp-adapter package).
		$cmd_list  = 'wp mcp-adapter list';
		$cmd_serve = sprintf( 'wp mcp-adapter serve --server=%s --user=admin', $server_slug );

		// STDIO-mode JSON config snippet — uses `wp` directly as the command.
		$abspath     = rtrim( ABSPATH, '/' );
		$stdio_config = array(
			'command' => 'wp',
			'args'    => array(
				'mcp-adapter',
				'serve',
				'--server=' . $server_slug,
				'--user=admin',
				'--path=' . $abspath,
			),
		);
		$stdio_config_json = wp_json_encode( $stdio_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'WP-CLI (STDIO Transport)', 'acrossai-mcp-manager' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'MCP clients can connect by launching WP-CLI as a subprocess instead of calling the HTTP endpoint. This is ideal for local WordPress installs — no credentials are transmitted over the network.', 'acrossai-mcp-manager' ); ?>
			</p>

			<h3><?php esc_html_e( 'STDIO Transport (Local / Subprocess Mode)', 'acrossai-mcp-manager' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'MCP clients can also connect by launching WP-CLI as a subprocess instead of calling the HTTP endpoint. This is ideal for local WordPress installs — no credentials are transmitted over the network.', 'acrossai-mcp-manager' ); ?>
			</p>

			<!-- List servers -->
			<div class="mcp-config-json" style="margin-top:16px;">
				<label for="wpcli_list_<?php echo esc_attr( $server['id'] ); ?>">
					<strong><?php esc_html_e( 'List all registered MCP servers', 'acrossai-mcp-manager' ); ?></strong>
				</label>
				<textarea
					id="wpcli_list_<?php echo esc_attr( $server['id'] ); ?>"
					class="widefat code mcp-cmd"
					rows="1"
					readonly><?php echo esc_textarea( $cmd_list ); ?></textarea>
				<button
					type="button"
					class="button copy-to-clipboard"
					data-field="wpcli_list_<?php echo esc_attr( $server['id'] ); ?>">
					<?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?>
				</button>
			</div>

			<!-- Serve via STDIO -->
			<div class="mcp-config-json" style="margin-top:16px;">
				<label for="wpcli_serve_<?php echo esc_attr( $server['id'] ); ?>">
					<strong><?php esc_html_e( 'Start this server via STDIO', 'acrossai-mcp-manager' ); ?></strong>
				</label>
				<p class="description" style="margin-bottom:6px;">
					<?php esc_html_e( 'Blocks until the MCP client disconnects. Replace "admin" with any WordPress user login or ID.', 'acrossai-mcp-manager' ); ?>
				</p>
				<textarea
					id="wpcli_serve_<?php echo esc_attr( $server['id'] ); ?>"
					class="widefat code mcp-cmd"
					rows="1"
					readonly><?php echo esc_textarea( $cmd_serve ); ?></textarea>
				<button
					type="button"
					class="button copy-to-clipboard"
					data-field="wpcli_serve_<?php echo esc_attr( $server['id'] ); ?>">
					<?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?>
				</button>
			</div>

			<!-- STDIO JSON config -->
			<div class="mcp-config-json" style="margin-top:16px;">
				<label for="wpcli_stdio_config_<?php echo esc_attr( $server['id'] ); ?>">
					<strong><?php esc_html_e( 'STDIO config block (paste into your MCP client)', 'acrossai-mcp-manager' ); ?></strong>
				</label>
				<p class="description" style="margin-bottom:6px;">
					<?php
					printf(
						/* translators: %s: server key */
						esc_html__( 'Add this under the key "%s" in your MCP client\'s config file. WP-CLI must be in your PATH, or replace "wp" with the full path to the binary.', 'acrossai-mcp-manager' ),
						esc_html( $server_key )
					);
					?>
				</p>
				<textarea
					id="wpcli_stdio_config_<?php echo esc_attr( $server['id'] ); ?>"
					class="widefat code"
					rows="8"
					readonly><?php echo esc_textarea( $stdio_config_json ); ?></textarea>
				<button
					type="button"
					class="button copy-to-clipboard"
					data-field="wpcli_stdio_config_<?php echo esc_attr( $server['id'] ); ?>">
					<?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?>
				</button>
			</div>

			<div class="notice notice-warning inline" style="margin-top:16px;">
				<p>
					<strong><?php esc_html_e( 'STDIO vs HTTP transport', 'acrossai-mcp-manager' ); ?></strong>
				</p>
				<ul style="list-style:disc;margin-left:18px;">
					<li><?php esc_html_e( 'STDIO — MCP client spawns wp as a subprocess. Best for local development; no network exposure.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'HTTP (npx) — MCP client connects to the REST endpoint over the network. Best for remote or shared servers.', 'acrossai-mcp-manager' ); ?></li>
				</ul>
				<p>
					<?php esc_html_e( 'The --path flag in the STDIO config is the absolute path to this WordPress installation on disk. Adjust it if the wp binary cannot find WordPress automatically.', 'acrossai-mcp-manager' ); ?>
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
	 * Render the Access Control tab — per-server access rules.
	 *
	 * The tab is available for all server types (plugin-registered and database).
	 * It shows:
	 *   - A dropdown to choose the access control type (Everyone / WordPress Role / …).
	 *   - Provider-rendered controls for the chosen type (for example role checkboxes
	 *     or live user search for the Users provider).
	 *   - A save button handled by the access-control library's AJAX flow.
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
		$ns        = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
		$route     = ! empty( $server['server_route'] ) ? $server['server_route'] : ( $server['server_slug'] ?? sanitize_title( $server['server_name'] ) );

		$ui = $this->get_access_control_ui();

		echo '<div class="mcp-tab-panel">';
		$ui->render(
			$ns,
			$route,
			array(
				'submit_label' => __( 'Save Access Control', 'acrossai-mcp-manager' ),
				'description'  => __( 'Control which users are allowed to connect to this MCP server. Administrators always have access regardless of this setting.', 'acrossai-mcp-manager' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Return a configured access-control UI instance for this plugin.
	 *
	 * The asset URL is set explicitly so the library assets resolve correctly
	 * even when WordPress is running from a non-standard or symlinked path.
	 *
	 * @since 1.6.0
	 *
	 * @return AccessControlUI
	 */
	private function get_access_control_ui(): AccessControlUI {
		$manager = \ACROSSAI_MCP_MANAGER\Core\Plugin::instance()->get_access_control_manager();
		$ui      = new AccessControlUI( $manager );
		$ui->set_assets_url( plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets', ACROSSAI_MCP_MANAGER_FILE ) );

		return $ui;
	}

	/**
	 * Render the MCP Tracker tab.
	 *
	 * Promotes the MCP Tracker plugin (wordpress.org/plugins/mcp-tracker/) and
	 * links directly to its request log filtered to this server's slug when the
	 * plugin is active.
	 *
	 * @since 1.5.0
	 *
	 * @param array $server DB row for the server being viewed.
	 *
	 * @return void
	 */
	private function render_mcp_tracker_tab( array $server ) {
		$server_slug    = ! empty( $server['server_slug'] ) ? $server['server_slug'] : sanitize_title( $server['server_name'] );
		$tracker_active = defined( 'WPVMCPT_PLUGIN_VERSION' ) || class_exists( 'WPVMCPT\Plugin' );

		// Build the tracker URL using the MCP server slug as the server parameter.
		$tracker_url = admin_url( 'admin.php?page=wpvmcpt-requests-list&server=' . rawurlencode( $server_slug ) );

		// WordPress.org plugin page URL — hardcoded, no user input.
		$wporg_url = 'https://wordpress.org/plugins/mcp-tracker/';
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'MCP Tracker', 'acrossai-mcp-manager' ); ?></h2>

			<?php if ( $tracker_active ) : ?>

				<div class="notice notice-success inline">
					<p>
						<strong><?php esc_html_e( 'MCP Tracker is active.', 'acrossai-mcp-manager' ); ?></strong>
						<?php esc_html_e( 'View all logged requests for this server below.', 'acrossai-mcp-manager' ); ?>
					</p>
				</div>

				<p style="margin-top:16px;">
					<a href="<?php echo esc_url( $tracker_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'View Request Log', 'acrossai-mcp-manager' ); ?>
					</a>
				</p>

				<p class="description" style="margin-top:12px;">
					<?php
					printf(
						/* translators: %s: server slug */
						esc_html__( 'Direct link filtered to server: %s', 'acrossai-mcp-manager' ),
						'<code>' . esc_html( $server_slug ) . '</code>'
					);
					?>
					<br>
					<code style="user-select:all;"><?php echo esc_html( $tracker_url ); ?></code>
				</p>

			<?php else : ?>

				<div class="notice notice-info inline">
					<p>
						<strong><?php esc_html_e( 'MCP Tracker is not installed.', 'acrossai-mcp-manager' ); ?></strong>
						<?php esc_html_e( 'Install the free MCP Tracker plugin to log and inspect every request made to this MCP server.', 'acrossai-mcp-manager' ); ?>
					</p>
				</div>

				<table class="form-table" style="margin-top:8px;">
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugin', 'acrossai-mcp-manager' ); ?></th>
						<td>
							<a href="<?php echo esc_url( $wporg_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'MCP Tracker — WordPress.org', 'acrossai-mcp-manager' ); ?>
							</a>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'What it does', 'acrossai-mcp-manager' ); ?></th>
						<td>
							<?php esc_html_e( 'Logs every incoming MCP request — tool calls, responses, errors, and timing — so you can audit activity, debug AI clients, and monitor server usage.', 'acrossai-mcp-manager' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Request log URL', 'acrossai-mcp-manager' ); ?></th>
						<td>
							<code style="user-select:all;"><?php echo esc_html( $tracker_url ); ?></code>
							<p class="description">
								<?php esc_html_e( 'Once installed and activated, this URL will open the request log filtered to this server.', 'acrossai-mcp-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p style="margin-top:16px;">
					<a href="<?php echo esc_url( $wporg_url ); ?>" class="button" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Get MCP Tracker', 'acrossai-mcp-manager' ); ?>
					</a>
				</p>

			<?php endif; ?>

		</div>
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

	// -------------------------------------------------------------------------
	// Connector tab
	// -------------------------------------------------------------------------

	/**
	 * Render the Claude.ai Connector tab for a server.
	 *
	 * Shows the copy-pasteable MCP URL, an enable/disable toggle for this server,
	 * and a list of active OAuth tokens with per-token revoke buttons.
	 *
	 * @since 1.6.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_connector_tab( array $server ) {
		$server_id      = (int) $server['id'];
		$oauth_enabled  = (bool) get_option( 'acrossai_mcp_oauth_enabled', false );
		$connector_on   = ! empty( $server['connector_enabled'] );
		$server_enabled = ! empty( $server['is_enabled'] );

		$ns      = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
		$route   = ! empty( $server['server_route'] ) ? $server['server_route'] : $server['server_slug'];
		$slug    = $server['server_slug'];
		$mcp_url = rest_url( $ns . '/' . $route );

		$settings_url = admin_url( 'admin.php?page=acrossai_mcp_manager_settings' );

		$save_url = add_query_arg(
			array(
				'page'   => 'acrossai_mcp_manager',
				'action' => 'save_connector_settings',
				'server' => $server_id,
			),
			admin_url( 'admin.php' )
		);

		$create_url = add_query_arg(
			array(
				'page'   => 'acrossai_mcp_manager',
				'action' => 'create_oauth_client',
				'server' => $server_id,
			),
			admin_url( 'admin.php' )
		);

		$revoke_url = add_query_arg(
			array(
				'page'   => 'acrossai_mcp_manager',
				'action' => 'revoke_oauth_client',
				'server' => $server_id,
			),
			admin_url( 'admin.php' )
		);

		// One-shot new-credential notice (set by handle_create_oauth_client).
		$new_cred = get_transient( 'acrossai_mcp_oauth_new_secret_' . get_current_user_id() );
		if ( $new_cred ) {
			delete_transient( 'acrossai_mcp_oauth_new_secret_' . get_current_user_id() );
		}
		?>
		<div class="mcp-tab-panel">

			<h2><?php esc_html_e( 'Claude.ai Connector', 'acrossai-mcp-manager' ); ?></h2>

			<?php if ( ! $oauth_enabled ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Claude.ai Connectors are disabled.', 'acrossai-mcp-manager' ); ?></strong>
						<?php
						printf(
							/* translators: %s: link to settings page */
							wp_kses_post( __( 'Enable them in <a href="%s">Settings → Claude.ai Connectors</a> first.', 'acrossai-mcp-manager' ) ),
							esc_url( $settings_url )
						);
						?>
					</p>
				</div>
			<?php else : ?>

				<?php if ( $new_cred ) : ?>
					<!-- ── One-time credential display ── -->
					<div class="notice notice-success inline acrossai-new-cred-notice" style="border-left-color:#00a32a;padding:16px 20px;">
						<h3 style="margin-top:0;color:#1d2327;">
							<?php esc_html_e( 'Credential created — copy now (shown only once)', 'acrossai-mcp-manager' ); ?>
						</h3>
						<table class="form-table" role="presentation" style="margin:0;">
							<tr>
								<th style="width:160px;padding:6px 0;"><?php esc_html_e( 'Server URL', 'acrossai-mcp-manager' ); ?></th>
								<td style="padding:6px 0;">
									<code id="acrossai-new-url" style="word-break:break-all;"><?php echo esc_html( $mcp_url ); ?></code>
									<button type="button" class="button button-small acrossai-copy" data-target="acrossai-new-url" style="margin-left:6px;"><?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?></button>
								</td>
							</tr>
							<tr>
								<th style="padding:6px 0;"><?php esc_html_e( 'Client ID', 'acrossai-mcp-manager' ); ?></th>
								<td style="padding:6px 0;">
									<code id="acrossai-new-client-id"><?php echo esc_html( $new_cred['client_id'] ); ?></code>
									<button type="button" class="button button-small acrossai-copy" data-target="acrossai-new-client-id" style="margin-left:6px;"><?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?></button>
								</td>
							</tr>
							<tr>
								<th style="padding:6px 0;"><?php esc_html_e( 'Client Secret', 'acrossai-mcp-manager' ); ?></th>
								<td style="padding:6px 0;">
									<code id="acrossai-new-client-secret"><?php echo esc_html( $new_cred['secret'] ); ?></code>
									<button type="button" class="button button-small acrossai-copy" data-target="acrossai-new-client-secret" style="margin-left:6px;"><?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?></button>
								</td>
							</tr>
						</table>
						<p style="margin:12px 0 0;color:#646970;">
							<?php esc_html_e( 'In Claude.ai → Settings → Connectors → Add custom connector: paste the Server URL, then open Advanced settings and paste the Client ID and Client Secret.', 'acrossai-mcp-manager' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<!-- ── Server URL ── -->
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Server URL', 'acrossai-mcp-manager' ); ?></th>
						<td>
							<div class="mcp-config-json" style="margin:0;">
								<input
									type="text"
									class="large-text code"
									readonly
									value="<?php echo esc_attr( $mcp_url ); ?>"
									onclick="this.select()"
									style="font-family:Consolas,'Courier New',monospace;font-size:13px;"
								>
							</div>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'Paste this into Claude.ai → Settings → Connectors → Add custom connector.', 'acrossai-mcp-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connector status', 'acrossai-mcp-manager' ); ?></th>
						<td>
							<?php if ( $connector_on ) : ?>
								<span class="acrossai-status-badge acrossai-status-active">
									<?php esc_html_e( 'Enabled', 'acrossai-mcp-manager' ); ?>
								</span>
							<?php else : ?>
								<span class="acrossai-status-badge acrossai-status-inactive">
									<?php esc_html_e( 'Disabled', 'acrossai-mcp-manager' ); ?>
								</span>
							<?php endif; ?>
							<?php if ( ! $server_enabled ) : ?>
								<p class="description" style="color:#d63638;margin-top:4px;">
									<?php esc_html_e( 'The server is inactive. Enable it on the Overview tab first.', 'acrossai-mcp-manager' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<!-- ── Enable/Disable form ── -->
				<form method="post" action="<?php echo esc_url( $save_url ); ?>">
					<?php wp_nonce_field( 'acrossai_mcp_connector_' . $server_id ); ?>
					<input type="hidden" name="connector_enabled" value="<?php echo $connector_on ? '0' : '1'; ?>">
					<p>
						<button type="submit" class="button <?php echo $connector_on ? '' : 'button-primary'; ?>">
							<?php echo $connector_on ? esc_html__( 'Disable Connector Access', 'acrossai-mcp-manager' ) : esc_html__( 'Enable Connector Access', 'acrossai-mcp-manager' ); ?>
						</button>
					</p>
				</form>

				<?php if ( $connector_on ) : ?>

					<!-- ── Connector Credentials ── -->
					<hr style="margin:24px 0;">
					<h3><?php esc_html_e( 'Connector Credentials', 'acrossai-mcp-manager' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Generate one credential per Claude.ai workspace. Anyone with a credential can connect to this server. You can revoke credentials at any time.', 'acrossai-mcp-manager' ); ?>
					</p>

					<h4><?php esc_html_e( 'How to connect Claude.ai', 'acrossai-mcp-manager' ); ?></h4>
					<ol style="max-width:540px;line-height:1.8;">
						<li><?php esc_html_e( 'Click "Generate Credential" below to create a Client ID and Client Secret pair.', 'acrossai-mcp-manager' ); ?></li>
						<li><?php esc_html_e( 'Copy the Server URL, Client ID, and Client Secret from the green notice that appears.', 'acrossai-mcp-manager' ); ?></li>
						<li><?php esc_html_e( 'In Claude.ai → Settings → Connectors → Add custom connector: paste the Server URL, then expand Advanced settings and paste the Client ID and Client Secret.', 'acrossai-mcp-manager' ); ?></li>
						<li><?php esc_html_e( 'Approve the login screen that opens on this site.', 'acrossai-mcp-manager' ); ?></li>
					</ol>

					<!-- Generate form -->
					<form method="post" action="<?php echo esc_url( $create_url ); ?>" style="margin:16px 0;">
						<?php wp_nonce_field( 'acrossai_mcp_oauth_create_client_' . $server_id ); ?>
						<table class="form-table" role="presentation" style="margin:0 0 12px;">
							<tr>
								<th scope="row" style="width:160px;">
									<label for="acrossai-client-name"><?php esc_html_e( 'Label', 'acrossai-mcp-manager' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										id="acrossai-client-name"
										name="client_name"
										class="regular-text"
										placeholder="<?php esc_attr_e( 'e.g. My Claude workspace', 'acrossai-mcp-manager' ); ?>"
										maxlength="100"
										required
									>
									<p class="description"><?php esc_html_e( 'A name to identify this credential in the list.', 'acrossai-mcp-manager' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="acrossai-redirect-uri"><?php esc_html_e( 'Redirect URI', 'acrossai-mcp-manager' ); ?></label>
								</th>
								<td>
									<input
										type="url"
										id="acrossai-redirect-uri"
										name="redirect_uri"
										class="regular-text code"
										value="https://claude.ai/api/mcp/auth_callback"
										required
									>
									<p class="description"><?php esc_html_e( 'Keep the default unless Claude.ai uses a different callback URL.', 'acrossai-mcp-manager' ); ?></p>
								</td>
							</tr>
						</table>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Generate Credential', 'acrossai-mcp-manager' ); ?>
						</button>
					</form>

					<!-- Existing credentials list -->
					<?php
					$clients         = \ACROSSAI_MCP_MANAGER\Database\OAuthClientsTable::get_all();
					$revoke_base_url = rest_url( 'acrossai-mcp-manager/v1/oauth/tokens/' );
					?>

					<?php if ( ! empty( $clients ) ) : ?>
						<table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Label', 'acrossai-mcp-manager' ); ?></th>
									<th><?php esc_html_e( 'Client ID', 'acrossai-mcp-manager' ); ?></th>
									<th><?php esc_html_e( 'Created', 'acrossai-mcp-manager' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'acrossai-mcp-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $clients as $cred ) : ?>
									<tr>
										<td><?php echo esc_html( $cred['client_name'] ); ?></td>
										<td><code><?php echo esc_html( $cred['client_id'] ); ?></code></td>
										<td><?php echo esc_html( get_date_from_gmt( $cred['created_at'], 'Y-m-d' ) ); ?></td>
										<td>
											<form method="post" action="<?php echo esc_url( $revoke_url ); ?>" style="display:inline;">
												<?php wp_nonce_field( 'acrossai_mcp_oauth_revoke_client_' . $cred['client_id'] ); ?>
												<input type="hidden" name="client_id" value="<?php echo esc_attr( $cred['client_id'] ); ?>">
												<button
													type="submit"
													class="button button-small button-link-delete"
													onclick="return confirm('<?php echo esc_js( __( 'This will revoke all active tokens for this credential. Continue?', 'acrossai-mcp-manager' ) ); ?>')"
												>
													<?php esc_html_e( 'Revoke', 'acrossai-mcp-manager' ); ?>
												</button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No credentials yet. Generate one above to connect Claude.ai.', 'acrossai-mcp-manager' ); ?></p>
					<?php endif; ?>

					<!-- ── OAuth discovery URLs ── -->
					<hr style="margin:24px 0;">
					<h3><?php esc_html_e( 'OAuth discovery URLs', 'acrossai-mcp-manager' ); ?></h3>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Protected resource', 'acrossai-mcp-manager' ); ?></th>
							<td>
								<code style="user-select:all;"><?php echo esc_html( home_url( '.well-known/oauth-protected-resource/' . $slug ) ); ?></code>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Authorization server', 'acrossai-mcp-manager' ); ?></th>
							<td>
								<code style="user-select:all;"><?php echo esc_html( home_url( '.well-known/oauth-authorization-server' ) ); ?></code>
							</td>
						</tr>
					</table>

					<!-- ── Active tokens ── -->
					<?php $tokens = \ACROSSAI_MCP_MANAGER\Database\OAuthTokensTable::get_active_by_audience( $mcp_url ); ?>

					<h3><?php esc_html_e( 'Active connections', 'acrossai-mcp-manager' ); ?></h3>

					<?php if ( empty( $tokens ) ) : ?>
						<p class="description">
							<?php esc_html_e( 'No active OAuth connections for this server yet.', 'acrossai-mcp-manager' ); ?>
						</p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'User', 'acrossai-mcp-manager' ); ?></th>
									<th><?php esc_html_e( 'Client', 'acrossai-mcp-manager' ); ?></th>
									<th><?php esc_html_e( 'Expires', 'acrossai-mcp-manager' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'acrossai-mcp-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $tokens as $tok ) : ?>
									<tr>
										<td><?php echo esc_html( $tok['user_login'] ?? '—' ); ?></td>
										<td><code><?php echo esc_html( $tok['client_id'] ); ?></code></td>
										<td><?php echo esc_html( get_date_from_gmt( $tok['expires_at'], 'Y-m-d H:i' ) ); ?></td>
										<td>
											<button
												type="button"
												class="button button-small acrossai-revoke-token"
												data-token-id="<?php echo esc_attr( (int) $tok['id'] ); ?>"
												data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
												data-revoke-base="<?php echo esc_attr( rest_url( 'acrossai-mcp-manager/v1/oauth/tokens/' ) ); ?>"
											>
												<?php esc_html_e( 'Revoke', 'acrossai-mcp-manager' ); ?>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

				<?php endif; // connector_on ?>

			<?php endif; // oauth_enabled ?>

		</div>
		<script>
		(function() {
			// Copy-to-clipboard buttons.
			document.querySelectorAll('.acrossai-copy').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var target = document.getElementById(btn.dataset.target);
					if ( ! target ) { return; }
					navigator.clipboard.writeText(target.textContent.trim()).then(function() {
						var orig = btn.textContent;
						btn.textContent = '<?php echo esc_js( __( 'Copied!', 'acrossai-mcp-manager' ) ); ?>';
						setTimeout(function() { btn.textContent = orig; }, 1500);
					});
				});
			});

			// Active-token revoke buttons (admin DELETE endpoint).
			document.querySelectorAll('.acrossai-revoke-token').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var tokenId = btn.dataset.tokenId;
					var nonce   = btn.dataset.nonce;
					var url     = btn.dataset.revokeBase + tokenId;
					var row     = btn.closest('tr');

					fetch(url, {
						method: 'DELETE',
						headers: { 'X-WP-Nonce': nonce }
					}).then(function() {
						row.style.opacity = '0.4';
						btn.textContent = '<?php echo esc_js( __( 'Revoked', 'acrossai-mcp-manager' ) ); ?>';
						btn.disabled = true;
					});
				});
			});
		})();
		</script>
		<?php
	}
}
