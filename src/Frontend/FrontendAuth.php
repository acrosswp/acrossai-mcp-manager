<?php
/**
 * Frontend CLI Auth page.
 *
 * Registers a virtual frontend page at /<slug>/ that handles the
 * browser-side step of the CLI authentication flow, keeping it
 * completely out of the WP admin area.
 *
 * URL routing
 * -----------
 *   /<slug>/                               → redirect to wp-login.php if not logged in
 *   /<slug>/?action=cli_auth&code=…        → approval page (any logged-in user)
 *   /<slug>/?action=cli_auth_approve&…     → process approval + redirect
 *   /<slug>/?action=cli_auth_approved&…    → confirmation page
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Frontend
 */

namespace ACROSSAI_MCP_MANAGER\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the public-facing CLI auth flow.
 *
 * @since 1.0.0
 */
class FrontendAuth {

	/**
	 * URL slug for the virtual frontend page.
	 */
	const PAGE_SLUG = 'acrossai-mcp-manager';

	/**
	 * WP query var used to detect requests to this page.
	 */
	const QUERY_VAR = 'acrossai_mcp_auth';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rule' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ) );
	}

	// -------------------------------------------------------------------------
	// Rewrite / routing
	// -------------------------------------------------------------------------

	/**
	 * Register the rewrite rule for the virtual page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_rewrite_rule() {
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Flush rewrite rules once if our rule is not yet in the stored rules.
	 *
	 * Runs at init priority 20 (after register_rewrite_rule at priority 10).
	 * After the first flush the rule is stored in the DB so this becomes a
	 * no-op on every subsequent request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		$rules = get_option( 'rewrite_rules' );
		if ( empty( $rules ) || ! isset( $rules[ '^' . self::PAGE_SLUG . '/?$' ] ) ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Expose the custom query var to WP_Query.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $vars Existing public query vars.
	 *
	 * @return string[]
	 */
	public function add_query_var( array $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Return the base URL for the frontend auth page.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_base_url() {
		return trailingslashit( home_url( self::PAGE_SLUG ) );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue frontend styles for the CLI auth page.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		wp_enqueue_style(
			'acrossai-mcp-manager-frontend-auth',
			ACROSSAI_MCP_MANAGER_URL . 'assets/frontend-auth.css',
			array(),
			ACROSSAI_MCP_MANAGER_VERSION
		);
	}

	// -------------------------------------------------------------------------
	// Request handler
	// -------------------------------------------------------------------------

	/**
	 * Intercept requests to the virtual page and dispatch them.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_request() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Prevent any caching layer from storing this page.
		nocache_headers();

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'cli_auth'; // phpcs:ignore WordPress.Security.NonceVerification

		// Require the user to be logged in — redirect to wp-login.php if not.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect(
				wp_login_url(
					add_query_arg( // phpcs:ignore WordPress.Security.NonceVerification
						array_map( 'sanitize_text_field', wp_unslash( $_GET ) ), // phpcs:ignore WordPress.Security.NonceVerification
						self::get_base_url()
					)
				)
			);
			exit;
		}

		// Any logged-in user may go through the CLI auth flow.
		// Each user approves access for their own account — the resulting
		// Application Password belongs to the currently logged-in user.
		// No elevated capability is required.
		$npm_enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );

		if ( 'cli_auth_approve' === $action ) {
			if ( ! $npm_enabled ) {
				wp_die(
					esc_html__( 'CLI connections are disabled. Enable CLI Connections in MCP Manager Settings first.', 'acrossai-mcp-manager' ),
					esc_html__( 'Feature Disabled', 'acrossai-mcp-manager' ),
					array( 'response' => 403 )
				);
			}
			$this->handle_approve();
			return;
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		$this->render_page( $npm_enabled, $action );
		exit;
	}

	// -------------------------------------------------------------------------
	// Approval handler (POST-style GET with nonce)
	// -------------------------------------------------------------------------

	/**
	 * Process the CLI approval and redirect to the confirmation page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function handle_approve() {
		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'acrossai_cli_approve_' . $code );

		\ACROSSAI_MCP_MANAGER\REST\CliController::approve_auth_code( $code, get_current_user_id() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'action' => 'cli_auth_approved',
					'server' => rawurlencode( $server ),
				),
				self::get_base_url()
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// Page renderer (routes to the correct view)
	// -------------------------------------------------------------------------

	/**
	 * Dispatch to the correct view based on action and feature state.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $npm_enabled Whether the npm login feature is enabled.
	 * @param string $action      Current action slug.
	 *
	 * @return void
	 */
	private function render_page( $npm_enabled, $action ) {
		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$this->render_html_open(
			esc_html__( 'MCP Manager — Authorize CLI Connection', 'acrossai-mcp-manager' )
		);

		if ( 'cli_auth_approved' === $action ) {
			$this->render_approved_view( $server );
		} elseif ( ! $npm_enabled ) {
			$this->render_disabled_view();
		} else {
			$this->render_auth_view( $code, $server );
		}

		$this->render_html_close();
	}

	// -------------------------------------------------------------------------
	// Views
	// -------------------------------------------------------------------------

	/**
	 * Render the approval/consent view.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code   Auth code from the CLI.
	 * @param string $server Server slug from the CLI.
	 *
	 * @return void
	 */
	private function render_auth_view( $code, $server ) {
		if ( empty( $code ) ) {
			?>
			<div class="acrossai-notice acrossai-notice-error">
				<strong><?php esc_html_e( 'Invalid or missing auth code.', 'acrossai-mcp-manager' ); ?></strong>
			</div>
			<?php
			return;
		}

		$nonce       = wp_create_nonce( 'acrossai_cli_approve_' . $code );
		$approve_url = add_query_arg(
			array(
				'action'   => 'cli_auth_approve',
				'code'     => $code,
				'server'   => rawurlencode( $server ),
				'_wpnonce' => $nonce,
			),
			self::get_base_url()
		);
		?>
		<div class="acrossai-notice acrossai-notice-warning">
			<strong><?php esc_html_e( 'A CLI tool wants to connect to your MCP server.', 'acrossai-mcp-manager' ); ?></strong>
		</div>

		<table class="acrossai-table">
			<tr>
				<th><?php esc_html_e( 'Server', 'acrossai-mcp-manager' ); ?></th>
				<td><code><?php echo esc_html( $server ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Logged in as', 'acrossai-mcp-manager' ); ?></th>
				<td><strong><?php echo esc_html( wp_get_current_user()->user_login ); ?></strong></td>
			</tr>
		</table>

		<p><?php esc_html_e( 'Approving access will let the CLI tool generate a WordPress Application Password for this site and server.', 'acrossai-mcp-manager' ); ?></p>

		<div class="acrossai-actions">
			<a href="<?php echo esc_url( $approve_url ); ?>" class="acrossai-btn acrossai-btn-primary">
				<?php esc_html_e( 'Approve', 'acrossai-mcp-manager' ); ?>
			</a>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="acrossai-btn">
				<?php esc_html_e( 'Cancel', 'acrossai-mcp-manager' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Render the confirmation view after a successful approval.
	 *
	 * @since 1.0.0
	 *
	 * @param string $server Server slug that was approved.
	 *
	 * @return void
	 */
	private function render_approved_view( $server ) {
		?>
		<div class="acrossai-notice acrossai-notice-success">
			<strong><?php esc_html_e( 'Connection authorized!', 'acrossai-mcp-manager' ); ?></strong>
		</div>

		<p>
			<?php
			printf(
				/* translators: %s: server slug */
				esc_html__( 'The CLI tool can now connect to the "%s" server. You can return to your terminal.', 'acrossai-mcp-manager' ),
				esc_html( $server )
			);
			?>
		</p>

		<div class="acrossai-actions">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="acrossai-btn">
				<?php esc_html_e( '← Back to site', 'acrossai-mcp-manager' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Render the blocked view shown when CLI connections are disabled.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_disabled_view() {
		$settings_url = admin_url( 'admin.php?page=acrossai_mcp_manager_settings' );
		?>
		<div class="acrossai-notice acrossai-notice-error">
			<strong><?php esc_html_e( 'CLI connections are disabled.', 'acrossai-mcp-manager' ); ?></strong>
		</div>

		<p>
			<?php
			printf(
				/* translators: %s: link to settings page */
				wp_kses_post( __( 'To use the CLI connection flow, please <a href="%s">enable CLI Connections in Settings</a> first.', 'acrossai-mcp-manager' ) ),
				esc_url( $settings_url )
			);
			?>
		</p>

		<p class="acrossai-description">
			<?php
			esc_html_e(
				'Enabling CLI Connections allows the AcrossAI MCP Manager CLI tool to connect to this site via the npx command. Users sign in through WordPress and approve access in the browser, then the CLI receives a WordPress Application Password automatically so terminal users can connect to MCP servers without manually editing JSON config files.',
				'acrossai-mcp-manager'
			);
			?>
		</p>

		<div class="acrossai-actions">
			<a href="<?php echo esc_url( $settings_url ); ?>" class="acrossai-btn acrossai-btn-primary">
				<?php esc_html_e( 'Go to Settings', 'acrossai-mcp-manager' ); ?>
			</a>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="acrossai-btn">
				<?php esc_html_e( '← Back to site', 'acrossai-mcp-manager' ); ?>
			</a>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// HTML shell
	// -------------------------------------------------------------------------

	/**
	 * Output the opening HTML shell for the standalone auth page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title Page <title> text.
	 *
	 * @return void
	 */
	private function render_html_open( $title ) {
		$blog_name = get_bloginfo( 'name' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' — ' . $blog_name ); ?></title>
<?php wp_head(); ?>
</head>
<body>
<div class="acrossai-auth-wrap">
	<div class="acrossai-auth-logo">
		<h1><?php esc_html_e( 'MCP Manager', 'acrossai-mcp-manager' ); ?></h1>
		<p><?php echo esc_html( $blog_name ); ?></p>
	</div>
		<?php
	}

	/**
	 * Output the closing HTML shell.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_html_close() {
		?>
</div>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	}
}
