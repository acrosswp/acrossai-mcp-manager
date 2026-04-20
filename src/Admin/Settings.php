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

use ACROSSAI_MCP_MANAGER\Core\Plugin;

/**
 * Handles admin settings page and settings registration.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private $plugin;

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
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin        = $plugin;
		$this->app_passwords = new ApplicationPasswords( $plugin );

		add_action( 'admin_init', array( $this, 'handle_actions' ), 5 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 10 );
		add_action( 'admin_init', array( $this, 'register_settings' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Handle page actions (toggle status) before any output.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_actions() {
		// Only run on our page.
		if ( ! isset( $_GET['page'] ) || 'acrossai_mcp_manager' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'toggle_status' !== $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'acrossai-mcp-manager' ) );
		}

		$server = isset( $_GET['server'] ) ? sanitize_key( $_GET['server'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'acrossai_mcp_toggle_' . $server );

		if ( 'default' === $server ) {
			$current = (bool) get_option( 'acrossai_mcp_manager_enabled', false );
			update_option( 'acrossai_mcp_manager_enabled', ! $current );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=acrossai_mcp_manager&updated=1' ) );
		exit;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = get_current_screen();
		if ( 'toplevel_page_acrossai_mcp_manager' !== $screen->id ) {
			return;
		}

		wp_enqueue_style( 'acrossai-mcp-manager-admin', ACROSSAI_MCP_MANAGER_URL . 'assets/admin.css', array(), ACROSSAI_MCP_MANAGER_VERSION );

		wp_enqueue_script( 'acrossai-mcp-manager-admin', ACROSSAI_MCP_MANAGER_URL . 'assets/admin.js', array( 'wp-api' ), ACROSSAI_MCP_MANAGER_VERSION, true );

		wp_localize_script(
			'acrossai-mcp-manager-admin',
			'acrossaiMcpManagerData',
			array(
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'rest_url'     => rest_url( 'acrossai-mcp-manager/v1/' ),
				'current_user' => wp_get_current_user(),
			)
		);
	}

	/**
	 * Register admin menu.
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
	}

	/**
	 * Register settings and fields.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'acrossai_mcp_manager_settings',
			'acrossai_mcp_manager_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => function( $value ) {
					return rest_sanitize_boolean( $value );
				},
				'show_in_rest'      => true,
				'default'           => false,
			)
		);

		add_settings_section(
			'acrossai_mcp_manager_section',
			__( 'MCP Adapter Settings', 'acrossai-mcp-manager' ),
			function() {
				echo '<p>' . esc_html__( 'Configure MCP Adapter integration with WordPress.', 'acrossai-mcp-manager' ) . '</p>';
			},
			'acrossai_mcp_manager_settings'
		);

		add_settings_field(
			'acrossai_mcp_manager_enabled',
			__( 'Enable MCP Adapter', 'acrossai-mcp-manager' ),
			array( $this, 'render_enabled_field' ),
			'acrossai_mcp_manager_settings',
			'acrossai_mcp_manager_section'
		);

		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_adapter_notice' ) );
		}
	}

	/**
	 * Route between list view and edit view.
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

	/**
	 * Render the MCP server list page (WP_List_Table).
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

			<?php settings_errors(); ?>

			<form method="get">
				<input type="hidden" name="page" value="acrossai_mcp_manager">
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the edit page for a single MCP server (tabbed UI).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_edit_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$clients    = $this->app_passwords->get_clients();
		$back_url   = admin_url( 'admin.php?page=acrossai_mcp_manager' );
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1>
				<a href="<?php echo esc_url( $back_url ); ?>" class="acrossai-back-link">
					&#8592; <?php esc_html_e( 'MCP Servers', 'acrossai-mcp-manager' ); ?>
				</a>
				<?php echo esc_html( get_admin_page_title() ); ?>
			</h1>

			<?php settings_errors(); ?>

			<!-- Tab Navigation -->
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'acrossai_mcp_manager', 'action' => 'edit', 'tab' => 'overview' ), admin_url( 'admin.php' ) ) ); ?>"
				   class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Overview', 'acrossai-mcp-manager' ); ?>
				</a>
				<?php foreach ( $clients as $client_id => $client_data ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'acrossai_mcp_manager', 'action' => 'edit', 'tab' => $client_id ), admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab <?php echo $client_id === $active_tab ? 'nav-tab-active' : ''; ?>">
						<span class="mcp-client-icon"><?php echo esc_html( $client_data['icon'] ); ?></span>
						<?php echo esc_html( $client_data['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<!-- Tab Content -->
			<div class="tab-content">
				<?php
				if ( 'overview' === $active_tab ) {
					$this->render_overview_tab();
				} elseif ( isset( $clients[ $active_tab ] ) ) {
					$this->render_client_tab( $active_tab, $clients[ $active_tab ] );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render overview tab.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_overview_tab() {
		?>
		<div class="mcp-tab-panel">
			<h2><?php esc_html_e( 'MCP Manager Overview', 'acrossai-mcp-manager' ); ?></h2>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'acrossai_mcp_manager_settings' );
				do_settings_sections( 'acrossai_mcp_manager_settings' );
				submit_button();
				?>

				<div class="notice notice-info inline">
					<p>
						<strong><?php esc_html_e( 'Application Passwords', 'acrossai-mcp-manager' ); ?></strong><br>
						<?php esc_html_e( 'Passwords generated here are managed through WordPress Application Passwords. You can view, revoke, and manage all your application passwords on your ', 'acrossai-mcp-manager' ); ?>
						<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php esc_html_e( 'profile page', 'acrossai-mcp-manager' ); ?></a>
						<?php esc_html_e( ' under Account Management.', 'acrossai-mcp-manager' ); ?>
					</p>
				</div>
			</form>

			<div class="mcp-info-box">
				<h3><?php esc_html_e( 'Supported MCP Clients', 'acrossai-mcp-manager' ); ?></h3>
				<p><?php esc_html_e( 'Click on any tab above to configure MCP for your desired client.', 'acrossai-mcp-manager' ); ?></p>
				<ul class="mcp-clients-list">
					<?php foreach ( $this->app_passwords->get_clients() as $client_id => $client_data ) : ?>
						<li>
							<strong><?php echo esc_html( $client_data['label'] ); ?></strong>
							<p><?php echo esc_html( $client_data['description'] ); ?></p>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render client-specific tab.
	 *
	 * @since 1.0.0
	 *
	 * @param string $client_id   Client ID.
	 * @param array  $client_data Client data.
	 *
	 * @return void
	 */
	private function render_client_tab( $client_id, $client_data ) {
		?>
		<div class="mcp-tab-panel">
			<h2>
				<span class="mcp-client-icon"><?php echo esc_html( $client_data['icon'] ); ?></span>
				<?php echo esc_html( $client_data['label'] ); ?>
			</h2>
			<p class="description"><?php echo esc_html( $client_data['description'] ); ?></p>

			<!-- Generate Password Button -->
			<div class="password-actions">
				<button type="button" class="button button-primary generate-app-password" data-client="<?php echo esc_attr( $client_id ); ?>">
					<?php esc_html_e( 'Generate New Application Password', 'acrossai-mcp-manager' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Click to generate a new application password. Store it securely - it will only be shown once. The password will also appear in your profile page under Account Management → Application Passwords where you can manage and revoke it.', 'acrossai-mcp-manager' ); ?>
				</p>
			</div>

			<!-- Full Configuration JSON -->
			<div class="mcp-config-json">
				<h3><?php esc_html_e( 'Full Configuration (MCP Format)', 'acrossai-mcp-manager' ); ?></h3>

				<!-- Config File Location -->
				<div style="background: #f5f5f5; padding: 10px; margin-bottom: 15px; border-radius: 3px;">
					<p><strong><?php esc_html_e( '📁 Configuration File:', 'acrossai-mcp-manager' ); ?></strong></p>
					<code id="config_path_<?php echo esc_attr( $client_id ); ?>" style="word-break: break-all; display: block; padding: 5px 0;">Loading...</code>
				</div>

				<!-- Top-Level Key -->
				<div style="background: #f5f5f5; padding: 10px; margin-bottom: 15px; border-radius: 3px;">
					<p><strong><?php esc_html_e( '🔑 Top-Level Key:', 'acrossai-mcp-manager' ); ?></strong></p>
					<code id="top_level_key_<?php echo esc_attr( $client_id ); ?>" style="color: #d74e1d; font-weight: bold;">Loading...</code>
				</div>

				<!-- Configuration JSON -->
				<p style="margin-bottom: 10px;"><strong><?php esc_html_e( '📋 Full Configuration (Ready to Copy & Paste):', 'acrossai-mcp-manager' ); ?></strong></p>
				<textarea id="config_json_<?php echo esc_attr( $client_id ); ?>" class="widefat code" rows="15" readonly style="font-family: monospace; background: #f8f8f8;"></textarea>
				<button type="button" class="button copy-to-clipboard" data-field="config_json_<?php echo esc_attr( $client_id ); ?>" style="margin-top: 10px;">
					<?php esc_html_e( 'Copy Configuration', 'acrossai-mcp-manager' ); ?>
				</button>

				<!-- Instructions Note -->
				<div style="background: #e7f3ff; padding: 10px; margin-top: 15px; border-left: 4px solid #0073aa; border-radius: 3px;">
					<p><strong><?php esc_html_e( '⚠️ Important:', 'acrossai-mcp-manager' ); ?></strong></p>
					<ol style="margin: 10px 0; margin-left: 20px;">
						<li><?php esc_html_e( 'Generate password above and wait for it to appear in the configuration', 'acrossai-mcp-manager' ); ?></li>
						<li><?php esc_html_e( 'Copy the entire configuration JSON', 'acrossai-mcp-manager' ); ?></li>
						<li><?php esc_html_e( 'Open the file location shown above', 'acrossai-mcp-manager' ); ?></li>
						<li><?php esc_html_e( 'Paste the configuration under the top-level key', 'acrossai-mcp-manager' ); ?></li>
					</ol>
				</div>
			</div>

			<!-- Usage Instructions -->
			<div class="mcp-instructions">
				<h3><?php esc_html_e( 'Setup Instructions', 'acrossai-mcp-manager' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Click "Generate New Application Password" to create credentials', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'Copy the application password and store it safely', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'Copy the full configuration above', 'acrossai-mcp-manager' ); ?></li>
					<li><?php /* translators: %s: MCP client label. */ echo esc_html( sprintf( __( 'Paste the configuration in your %s MCP settings', 'acrossai-mcp-manager' ), $client_data['label'] ) ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the enabled/disabled checkbox field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_enabled_field() {
		$enabled = $this->plugin->get_option( 'acrossai_mcp_manager_enabled', false );
		?>
		<input
			type="checkbox"
			id="acrossai_mcp_manager_enabled"
			name="acrossai_mcp_manager_enabled"
			value="1"
			<?php checked( $enabled, 1 ); ?>
		/>
		<label for="acrossai_mcp_manager_enabled">
			<?php esc_html_e( 'Enable MCP Adapter integration', 'acrossai-mcp-manager' ); ?>
		</label>
		<?php
	}

	/**
	 * Render missing adapter admin notice.
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
					__( 'MCP Adapter is not installed. Please install the <code>wordpress/mcp-adapter</code> package to enable MCP integration.', 'acrossai-mcp-manager' )
				);
				?>
			</p>
		</div>
		<?php
	}
}
