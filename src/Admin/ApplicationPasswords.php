<?php
/**
 * Application Passwords Manager class.
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
 * Manages Application Passwords for MCP clients and the REST endpoints
 * that the admin JS uses to generate passwords and fetch client configs.
 *
 * REST namespace: acrossai-mcp-manager/v1
 *
 * Endpoints:
 *   POST /generate-app-password  – create a new WP Application Password
 *   GET  /get-client-config/{client} – return the JSON config for a client
 *   GET  /list-app-passwords     – list passwords created by this plugin
 *
 * @since 1.0.0
 */
class ApplicationPasswords {

	/**
	 * Supported MCP clients and their configuration details.
	 *
	 * Each entry maps a client ID to the metadata needed to build the JSON
	 * configuration that users paste into their MCP client config file.
	 *
	 * @var array<string,array>
	 */
	private $clients = array(
		'openai' => array(
			'label'         => 'OpenAI',
			'description'   => 'OpenAI ChatGPT Desktop App',
			'icon'          => '🧠',
			'top_level_key' => 'servers',
			'config_file'   => '~/.config/chatgpt/config.json',
			'server_name'   => 'mcp-wordpress',
		),
		'claude' => array(
			'label'         => 'Claude',
			'description'   => 'Anthropic Claude Desktop App',
			'icon'          => '🤖',
			'top_level_key' => 'mcpServers',
			'config_file'   => '~/Library/Application Support/Claude/claude_desktop_config.json',
			'server_name'   => 'mcp-wordpress',
		),
		'vscode' => array(
			'label'         => 'VS Code',
			'description'   => 'Visual Studio Code',
			'icon'          => '󰨞',
			'top_level_key' => 'servers',
			'config_file'   => '.vscode/mcp.json',
			'server_name'   => 'mcp-wordpress',
		),
		'codex'  => array(
			'label'         => 'Codex',
			'description'   => 'OpenAI Codex CLI',
			'icon'          => '🐙',
			'top_level_key' => 'mcp',
			'config_file'   => '~/.codex/config.toml',
			'server_name'   => 'mcp-wordpress',
		),
		'cursor' => array(
			'label'         => 'Cursor',
			'description'   => 'Cursor AI Code Editor',
			'icon'          => '⚡',
			'top_level_key' => 'mcpServers',
			'config_file'   => '~/.cursor/mcp.json',
			'server_name'   => 'mcp-wordpress',
		),
		'custom' => array(
			'label'         => 'Custom Client',
			'description'   => 'Custom MCP Client Implementation',
			'icon'          => '⚙️',
			'top_level_key' => 'mcpServers',
			'config_file'   => './your-project/.mcp/config.json',
			'server_name'   => 'mcp-wordpress',
		),
	);

	/**
	 * Constructor — registers REST routes.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	// -------------------------------------------------------------------------
	// REST routes
	// -------------------------------------------------------------------------

	/**
	 * Register all REST API routes for this class.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$namespace       = 'acrossai-mcp-manager/v1';
		$admin_only_perm = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			$namespace,
			'/generate-app-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_app_password' ),
				'permission_callback' => $admin_only_perm,
				'args'                => array(
					'client'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'server_id' => array(
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/get-client-config/(?P<client>[a-z\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_client_config' ),
				'permission_callback' => $admin_only_perm,
				'args'                => array(
					'server_id' => array(
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/list-app-passwords',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_app_passwords' ),
				'permission_callback' => $admin_only_perm,
			)
		);
	}

	// -------------------------------------------------------------------------
	// REST callbacks
	// -------------------------------------------------------------------------

	/**
	 * Generate a WordPress Application Password for the given MCP client.
	 *
	 * The password name includes the client label and, when a valid server_id
	 * is supplied, the server name — making it easy to identify in the profile
	 * page. The raw password is returned once and never stored by this plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_app_password( \WP_REST_Request $request ) {
		$client    = $request->get_param( 'client' );
		$server_id = (int) $request->get_param( 'server_id' );

		if ( ! isset( $this->clients[ $client ] ) ) {
			return new \WP_Error(
				'invalid_client',
				__( 'Invalid client type.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new \WP_Error(
				'not_supported',
				__( 'Application Passwords are not supported on this WordPress version.', 'acrossai-mcp-manager' ),
				array( 'status' => 501 )
			);
		}

		$current_user = wp_get_current_user();
		$client_label = $this->clients[ $client ]['label'];

		// Append server name when a specific server is referenced.
		$server_suffix = '';
		if ( $server_id > 0 ) {
			$server = MCPServerTable::get_by_id( $server_id );
			if ( $server ) {
				$server_suffix = ' (' . $server['server_name'] . ')';
			}
		}

		$app_name = sprintf( 'AcrossAI MCP Manager - %s%s', $client_label, $server_suffix );

		$result = \WP_Application_Passwords::create_new_application_password(
			$current_user->ID,
			array( 'name' => $app_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		list( $password, $app_details ) = $result;

		return rest_ensure_response(
			array(
				'success'  => true,
				'password' => $password,
				'username' => $current_user->user_login,
				'client'   => $client,
				'app_id'   => isset( $app_details['uuid'] ) ? $app_details['uuid'] : '',
				'message'  => __( 'Application Password created. Store it safely — it is shown only once.', 'acrossai-mcp-manager' ),
			)
		);
	}

	/**
	 * Return the full MCP JSON configuration for a client.
	 *
	 * Accepts an optional server_id query parameter. All servers currently share
	 * the same MCP adapter endpoint; server_id is forwarded for future use when
	 * each server may expose a unique URL.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_client_config( \WP_REST_Request $request ) {
		$client    = sanitize_text_field( $request->get_param( 'client' ) );
		$server_id = (int) $request->get_param( 'server_id' );

		if ( ! isset( $this->clients[ $client ] ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Invalid client type.', 'acrossai-mcp-manager' ),
				)
			);
		}

		$current_user  = wp_get_current_user();
		$mcp_config    = $this->generate_mcp_server_config( $current_user->user_login, $server_id );
		$top_level_key = $this->clients[ $client ]['top_level_key'];
		$server_name   = $this->build_server_key( $server_id );

		$full_config = array(
			$top_level_key => array(
				$server_name => $mcp_config,
			),
		);

		return rest_ensure_response(
			array(
				'success'          => true,
				'client'           => $client,
				'mcp_config'       => $mcp_config,
				'full_config'      => $full_config,
				'username'         => $current_user->user_login,
				'top_level_key'    => $top_level_key,
				'config_file_path' => $this->clients[ $client ]['config_file'],
			)
		);
	}

	/**
	 * List existing Application Passwords created by this plugin for the current user.
	 *
	 * Filters by the "AcrossAI MCP Manager" name prefix so unrelated passwords
	 * are never exposed.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response
	 */
	public function list_app_passwords() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return rest_ensure_response(
				array(
					'success'   => true,
					'passwords' => array(),
				)
			);
		}

		$user_id   = get_current_user_id();
		$all       = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$passwords = array_values(
			array_filter(
				$all,
				function ( $pwd ) {
					return isset( $pwd['name'] ) && false !== strpos( $pwd['name'], 'AcrossAI MCP Manager' );
				}
			)
		);

		return rest_ensure_response(
			array(
				'success'   => true,
				'passwords' => $passwords,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the JSON config key for a server: {sitename}-{serverslug}.
	 *
	 * Matches the key format used by the @acrossai/mcp-manager CLI tool so that
	 * manually-pasted configs and CLI-generated configs share the same key.
	 *
	 * @since 1.0.0
	 *
	 * @param int $server_id DB ID of the server being configured.
	 *
	 * @return string Slugified key, e.g. "wordpress-default-mcp-server".
	 */
	private function build_server_key( $server_id ) {
		$site_name = sanitize_title( get_bloginfo( 'name' ) );

		if ( $server_id > 0 ) {
			$server_row = MCPServerTable::get_by_id( $server_id );
			if ( $server_row ) {
				$server_slug = sanitize_title( $server_row['server_name'] );
				if ( $site_name && $server_slug ) {
					return $site_name . '-' . $server_slug;
				}
				if ( $server_slug ) {
					return $server_slug;
				}
			}
		}

		return $site_name ?: 'mcp-wordpress';
	}

	/**
	 * Build the inner MCP server configuration block.
	 *
	 * Uses the standard @automattic/mcp-wordpress-remote package. All servers
	 * currently share the same adapter endpoint; server_id is accepted for
	 * forward-compatibility when per-server URLs are introduced.
	 *
	 * @since 1.0.0
	 *
	 * @param string $username  WordPress username for the WP_API_USERNAME env var.
	 * @param int    $server_id Optional DB server ID (reserved for future use).
	 *
	 * @return array MCP server configuration array.
	 */
	private function generate_mcp_server_config( $username, $server_id = 0 ) {
		// All servers currently use the same adapter endpoint.
		// When per-server URLs are supported, derive the URL from $server_id here.
		$mcp_api_url = rest_url( 'mcp/mcp-adapter-default-server' );

		return array(
			'command' => 'npx',
			'args'    => array(
				'-y',
				'@automattic/mcp-wordpress-remote@latest',
			),
			'env'     => array(
				'WP_API_URL'      => $mcp_api_url,
				'WP_API_USERNAME' => $username,
				'WP_API_PASSWORD' => '(paste generated password here)',
			),
		);
	}

	/**
	 * Return the full client definitions array.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array>
	 */
	public function get_clients() {
		return $this->clients;
	}
}
