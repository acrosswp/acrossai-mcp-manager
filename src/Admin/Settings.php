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

		if ( 'toggle_status' !== $action ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'acrossai-mcp-manager' ) );
		}

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
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'MCP Server status updated successfully.', 'acrossai-mcp-manager' ); ?></p>
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

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$clients    = $this->app_passwords->get_clients();
		$back_url   = admin_url( 'admin.php?page=acrossai_mcp_manager' );
		$updated    = isset( $_GET['updated'] ) && '1' === $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification

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

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $make_tab_url( 'overview' ) ); ?>"
				   class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Overview', 'acrossai-mcp-manager' ); ?>
				</a>
				<?php foreach ( $clients as $client_id => $client_data ) : ?>
					<?php if ( 'custom' === $client_id ) : ?>
						<a href="<?php echo esc_url( $make_tab_url( 'npm' ) ); ?>"
						   class="nav-tab <?php echo 'npm' === $active_tab ? 'nav-tab-active' : ''; ?>">
							<?php esc_html_e( 'npm', 'acrossai-mcp-manager' ); ?>
						</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( $make_tab_url( $client_id ) ); ?>"
					   class="nav-tab <?php echo $client_id === $active_tab ? 'nav-tab-active' : ''; ?>">
						<span class="mcp-client-icon"><?php echo esc_html( $client_data['icon'] ); ?></span>
						<?php echo esc_html( $client_data['label'] ); ?>
					</a>
				<?php endforeach; ?>
				<a href="<?php echo esc_url( $make_tab_url( 'wp-cli' ) ); ?>"
				   class="nav-tab <?php echo 'wp-cli' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'WP-CLI', 'acrossai-mcp-manager' ); ?>
				</a>
			</nav>

			<div class="tab-content">
				<?php
				if ( 'overview' === $active_tab ) {
					$this->render_overview_tab( $server );
				} elseif ( 'npm' === $active_tab ) {
					$this->render_npm_tab( $server );
				} elseif ( 'wp-cli' === $active_tab ) {
					$this->render_wpcli_tab( $server );
				} elseif ( isset( $clients[ $active_tab ] ) ) {
					$this->render_client_tab( $active_tab, $clients[ $active_tab ], $server_id );
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
		$server_id  = (int) $server['id'];
		$enabled    = (bool) $server['is_enabled'];
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
		?>
		<div class="mcp-tab-panel">

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
					<td><code><?php echo esc_html( rest_url( 'mcp/mcp-adapter-default-server' ) ); ?></code></td>
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
						<span><?php echo esc_html( $client_data['icon'] ); ?></span>
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
				$server_slug = sanitize_title( $server['server_name'] );
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
		$server_slug  = sanitize_title( $server['server_name'] );
		$site_slug    = sanitize_title( get_bloginfo( 'name' ) );
		$server_key   = $site_slug ? $site_slug . '-' . $server_slug : $server_slug;
		$mcp_url      = rest_url( 'mcp/mcp-adapter-default-server' );

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
