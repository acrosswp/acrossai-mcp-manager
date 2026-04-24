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
 *   /<slug>/?action=cli_auth&code=…        → approval page (requires manage_options)
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
						array_map( 'sanitize_text_field', $_GET ), // phpcs:ignore WordPress.Security.NonceVerification
						self::get_base_url()
					)
				)
			);
			exit;
		}

		// Only administrators may use the CLI auth flow.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ),
				esc_html__( 'Forbidden', 'acrossai-mcp-manager' ),
				array( 'response' => 403 )
			);
		}

		$npm_enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );

		if ( 'cli_auth_approve' === $action ) {
			if ( ! $npm_enabled ) {
				wp_die(
					esc_html__( 'CLI authentication is disabled. Enable npm Login in MCP Manager Settings first.', 'acrossai-mcp-manager' ),
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
			esc_html__( 'MCP Manager — CLI Authorization', 'acrossai-mcp-manager' )
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
			<strong><?php esc_html_e( 'A CLI tool is requesting access to your MCP server.', 'acrossai-mcp-manager' ); ?></strong>
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

		<p><?php esc_html_e( 'Approving will allow the CLI tool to generate an Application Password for this site and server.', 'acrossai-mcp-manager' ); ?></p>

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
			<strong><?php esc_html_e( 'Authorization approved!', 'acrossai-mcp-manager' ); ?></strong>
		</div>

		<p>
			<?php
			printf(
				/* translators: %s: server slug */
				esc_html__( 'The CLI tool has been granted access to the "%s" server. You can now return to your terminal.', 'acrossai-mcp-manager' ),
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
	 * Render the blocked view shown when npm Login is disabled.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_disabled_view() {
		$settings_url = admin_url( 'admin.php?page=acrossai_mcp_manager_settings' );
		?>
		<div class="acrossai-notice acrossai-notice-error">
			<strong><?php esc_html_e( 'CLI authentication is disabled.', 'acrossai-mcp-manager' ); ?></strong>
		</div>

		<p>
			<?php
			printf(
				/* translators: %s: link to settings page */
				wp_kses_post( __( 'To use CLI-based login, please <a href="%s">enable npm Login in Settings</a> first.', 'acrossai-mcp-manager' ) ),
				esc_url( $settings_url )
			);
			?>
		</p>

		<p class="acrossai-description">
			<?php
			esc_html_e(
				'Enabling npm Login allows the AcrossAI MCP Manager CLI tool to authenticate with this site via the npx command. It automatically generates a WordPress Application Password so terminal users can connect to MCP servers without manually editing JSON config files.',
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
<style>
*, *::before, *::after { box-sizing: border-box; }
body {
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, sans-serif;
	font-size: 14px;
	line-height: 1.6;
	color: #1d2327;
	background: #f0f0f1;
	margin: 0;
	padding: 40px 20px;
}
.acrossai-auth-wrap {
	max-width: 480px;
	margin: 0 auto;
	background: #fff;
	border-radius: 4px;
	box-shadow: 0 1px 3px rgba(0,0,0,.13);
	padding: 32px;
}
.acrossai-auth-logo {
	text-align: center;
	margin-bottom: 24px;
}
.acrossai-auth-logo h1 {
	font-size: 18px;
	font-weight: 600;
	color: #1d2327;
	margin: 0;
}
.acrossai-auth-logo p {
	font-size: 12px;
	color: #646970;
	margin: 4px 0 0;
}
.acrossai-notice {
	border-left: 4px solid #72aee6;
	background: #f0f6fc;
	padding: 10px 14px;
	margin-bottom: 20px;
	border-radius: 0 3px 3px 0;
}
.acrossai-notice-warning {
	border-color: #dba617;
	background: #fcf9e8;
}
.acrossai-notice-error {
	border-color: #d63638;
	background: #fcf0f1;
}
.acrossai-notice-success {
	border-color: #00a32a;
	background: #edfaef;
}
.acrossai-table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 16px;
}
.acrossai-table th,
.acrossai-table td {
	text-align: left;
	padding: 8px 10px;
	border-bottom: 1px solid #f0f0f1;
	vertical-align: top;
}
.acrossai-table th {
	width: 40%;
	color: #646970;
	font-weight: 500;
}
.acrossai-table code {
	background: #f6f7f7;
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 13px;
	word-break: break-all;
}
.acrossai-actions {
	display: flex;
	gap: 10px;
	margin-top: 24px;
}
.acrossai-btn {
	display: inline-block;
	padding: 8px 18px;
	font-size: 13px;
	font-weight: 500;
	border-radius: 3px;
	border: 1px solid #c3c4c7;
	background: #f6f7f7;
	color: #1d2327;
	text-decoration: none;
	cursor: pointer;
	line-height: 1.4;
}
.acrossai-btn:hover { background: #f0f0f1; }
.acrossai-btn-primary {
	background: #2271b1;
	border-color: #2271b1;
	color: #fff;
}
.acrossai-btn-primary:hover {
	background: #135e96;
	border-color: #135e96;
	color: #fff;
}
.acrossai-description {
	color: #646970;
	font-size: 13px;
}
</style>
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
</body>
</html>
		<?php
	}
}
