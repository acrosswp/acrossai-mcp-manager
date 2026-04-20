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

use ACROSSAI_MCP_MANAGER\Core\Plugin;

/**
 * Manages Application Passwords for MCP clients.
 *
 * Uses WordPress's native Application Passwords system.
 *
 * @since 1.0.0
 */
class ApplicationPasswords {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Supported MCP clients with their configuration details.
	 *
	 * @var array
	 */
	private $clients = array(
		'vscode' => array(
			'label'         => 'VS Code',
			'description'   => 'Visual Studio Code with Copilot',
			'icon'          => '󰨞',
			'top_level_key' => 'servers',
			'config_file'   => '~/.config/Code/User/globalStorage/Copilot.copilot-chat/mcp.json',
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
		'codex'  => array(
			'label'         => 'GitHub Codex',
			'description'   => 'GitHub Copilot & Codex Integration',
			'icon'          => '🐙',
			'top_level_key' => 'servers',
			'config_file'   => '~/.gh-copilot/config.json',
			'server_name'   => 'mcp-wordpress',
		),
		'chatgpt' => array(
			'label'         => 'OpenAI ChatGPT Codex',
			'description'   => 'OpenAI ChatGPT with Code Interpreter',
			'icon'          => '🧠',
			'top_level_key' => 'servers',
			'config_file'   => '~/.config/chatgpt/config.json',
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
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'acrossai-mcp-manager/v1',
			'/generate-app-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_app_password' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'acrossai-mcp-manager/v1',
			'/get-client-config/(?P<client>[a-z\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_client_config' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Generate Application Password via REST API.
	 *
	 * Uses WordPress's native application password system.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function generate_app_password( \WP_REST_Request $request ) {
		$current_user = wp_get_current_user();
		$client       = sanitize_text_field( $request->get_param( 'client' ) );

		if ( ! isset( $this->clients[ $client ] ) ) {
			return new \WP_Error(
				'invalid_client',
				__( 'Invalid client type', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		// Check if WordPress Application Passwords class is available (WP 5.6+)
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new \WP_Error(
				'not_supported',
				__( 'Application Passwords not supported on this WordPress version', 'acrossai-mcp-manager' ),
				array( 'status' => 501 )
			);
		}

		$app_name = sprintf( 'AcrossAI MCP Manager - %s', $this->clients[ $client ]['label'] );

		// Create application password using WordPress's native class
		// This will show up in the user's profile under Application Passwords
		$result = \WP_Application_Passwords::create_new_application_password(
			$current_user->ID,
			array( 'name' => $app_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// $result is array: [ 'password', 'app_details' ]
		list( $password, $app_details ) = $result;
		$app_id                         = isset( $app_details['uuid'] ) ? $app_details['uuid'] : '';

		// Return password only once
		return rest_ensure_response(
			array(
				'success'  => true,
				'password' => $password,
				'username' => $current_user->user_login,
				'client'   => $client,
				'app_id'   => $app_id,
				'message'  => __( 'Application Password created successfully and is now visible in your profile page. Store this safely - it will only be shown once.', 'acrossai-mcp-manager' ),
			)
		);
	}

	/**
	 * Get client configuration with top-level wrapper.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response Response.
	 */
	public function get_client_config( \WP_REST_Request $request ) {
		$client = sanitize_text_field( $request->get_param( 'client' ) );

		if ( ! isset( $this->clients[ $client ] ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Invalid client type', 'acrossai-mcp-manager' ),
				)
			);
		}

		$current_user = wp_get_current_user();
		$username     = $current_user->user_login;

		$mcp_config        = $this->generate_mcp_server_config( $username );
		$wrapped_config    = $this->wrap_config_with_top_level_key( $client, $mcp_config );
		$config_file_path  = $this->clients[ $client ]['config_file'];
		$top_level_key     = $this->clients[ $client ]['top_level_key'];

		return rest_ensure_response(
			array(
				'success'           => true,
				'client'            => $client,
				'mcp_config'        => $mcp_config,
				'full_config'       => $wrapped_config,
				'username'          => $username,
				'top_level_key'     => $top_level_key,
				'config_file_path'  => $config_file_path,
			)
		);
	}

	/**
	 * Generate MCP server configuration (Format #1 - Recommended Standard).
	 *
	 * All clients use the same standard Format #1 configuration:
	 * - Uses npx command with @automattic/mcp-wordpress-remote@latest package
	 * - Uses "env" key (not "environment")
	 * - NO "type" field
	 * - Returns identical structure for: VS Code, Claude, GitHub Codex, Custom
	 *
	 * @since 1.0.0
	 *
	 * @param string $username Username.
	 *
	 * @return array MCP server configuration in Format #1.
	 */
	private function generate_mcp_server_config( $username ) {
		$mcp_api_url = rest_url( 'mcp/mcp-adapter-default-server' );

		// Format #1: Standard recommended configuration for all clients
		return array(
			'command' => 'npx',
			'args'    => array(
				'-y',
				'@automattic/mcp-wordpress-remote@latest',
			),
			'env'     => array(
				'WP_API_URL'      => $mcp_api_url,
				'WP_API_USERNAME' => $username,
				'WP_API_PASSWORD' => '(See password field above)',
			),
		);
	}

	/**
	 * Wrap MCP config with top-level key based on client type.
	 *
	 * Different providers use different top-level keys:
	 * - VS Code, GitHub Codex: "servers"
	 * - Claude, Custom: "mcpServers"
	 *
	 * @since 1.0.0
	 *
	 * @param string $client     Client type.
	 * @param array  $mcp_config MCP server configuration.
	 *
	 * @return array Full configuration with top-level key.
	 */
	private function wrap_config_with_top_level_key( $client, $mcp_config ) {
		if ( ! isset( $this->clients[ $client ] ) ) {
			return array();
		}

		$top_level_key = $this->clients[ $client ]['top_level_key'];
		$server_name   = $this->clients[ $client ]['server_name'];

		return array(
			$top_level_key => array(
				$server_name => $mcp_config,
			),
		);
	}

	/**
	 * Get list of supported clients.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of clients.
	 */
	public function get_clients() {
		return $this->clients;
	}
}
