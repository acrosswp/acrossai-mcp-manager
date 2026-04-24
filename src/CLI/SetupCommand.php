<?php
/**
 * WP-CLI command for setting up MCP clients.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage CLI
 */

namespace ACROSSAI_MCP_MANAGER\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;

/**
 * Manage MCP client configuration from the command line.
 *
 * ## EXAMPLES
 *
 *   # Interactive setup — pick a server and generate credentials
 *   wp acrossai-mcp setup
 *
 *   # Non-interactive — specify server slug and write config files
 *   wp acrossai-mcp setup --server=default-mcp-server --write
 *
 * @since 1.2.0
 */
class SetupCommand {

	/**
	 * Supported MCP clients: where their config file lives and what JSON key to use.
	 *
	 * @var array<string,array>
	 */
	private $clients = array(
		'claude-desktop' => array(
			'label'       => 'Claude Desktop',
			'config_file' => '~/Library/Application Support/Claude/claude_desktop_config.json',
			'top_key'     => 'mcpServers',
		),
		'cursor'         => array(
			'label'       => 'Cursor',
			'config_file' => '~/.cursor/mcp.json',
			'top_key'     => 'mcpServers',
		),
		'vscode'         => array(
			'label'       => 'VS Code',
			'config_file' => '~/Library/Application Support/Code/User/settings.json',
			'top_key'     => 'mcp.servers',
		),
		'claude-code'    => array(
			'label'       => 'Claude Code',
			'config_file' => '~/.claude.json',
			'top_key'     => 'mcpServers',
		),
		'copilot'        => array(
			'label'       => 'GitHub Copilot',
			'config_file' => '~/.vscode/mcp.json',
			'top_key'     => 'servers',
		),
	);

	// -------------------------------------------------------------------------
	// Commands
	// -------------------------------------------------------------------------

	/**
	 * Generate MCP credentials and display (or write) client config.
	 *
	 * ## OPTIONS
	 *
	 * [--server=<server>]
	 * : Server slug (e.g. default-mcp-server). Prompted interactively if omitted.
	 *
	 * [--write]
	 * : Write the config directly to each client's config file on this machine.
	 *   Without this flag only the JSON is printed.
	 *
	 * [--format=<format>]
	 * : Output format for the config block. Accepts: json (default), table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp acrossai-mcp setup
	 *   wp acrossai-mcp setup --server=default-mcp-server --write
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function setup( $args, $assoc_args ) {
		// ── 1. Resolve user ───────────────────────────────────────────────────
		$user = $this->resolve_user( $assoc_args );

		// ── 2. Resolve server ─────────────────────────────────────────────────
		$server_row = $this->resolve_server( $assoc_args );

		// ── 3. Generate Application Password ─────────────────────────────────
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Generating Application Password for user: ' . \WP_CLI::colorize( '%C' . $user->user_login . '%n' ) );

		$server_slug = sanitize_title( $server_row['server_name'] );
		$site_slug   = sanitize_title( get_bloginfo( 'name' ) );
		$server_key  = $site_slug ? $site_slug . '-' . $server_slug : $server_slug;
		$app_name    = 'AcrossAI MCP Manager - ' . $server_row['server_name'];
		$mcp_url     = rest_url( 'mcp/mcp-adapter-default-server' );

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			\WP_CLI::error( 'Application Passwords require WordPress 5.6 or later.' );
		}

		$result = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => $app_name )
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		list( $raw_password ) = $result;

		\WP_CLI::success( 'Application Password created.' );

		// ── 4. Build the server entry ─────────────────────────────────────────
		$server_entry = array(
			'command' => 'npx',
			'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
			'env'     => array(
				'WP_API_URL'      => $mcp_url,
				'WP_API_USERNAME' => $user->user_login,
				'WP_API_PASSWORD' => $raw_password,
			),
		);

		// ── 5. Display / write per client ─────────────────────────────────────
		$should_write = \WP_CLI\Utils\get_flag_value( $assoc_args, 'write', false );
		$format       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'json' );

		\WP_CLI::log( '' );
		\WP_CLI::log( \WP_CLI::colorize( '%BServer key:%n ' . $server_key ) );
		\WP_CLI::log( \WP_CLI::colorize( '%BMCP URL:%n    ' . $mcp_url ) );
		\WP_CLI::log( \WP_CLI::colorize( '%BUsername:%n   ' . $user->user_login ) );
		\WP_CLI::log( \WP_CLI::colorize( '%BPassword:%n   ' . $raw_password ) );
		\WP_CLI::log( '' );

		if ( 'table' === $format ) {
			$this->display_table( $server_key, $server_entry );
		} else {
			$this->display_json( $server_key, $server_entry );
		}

		if ( $should_write ) {
			$this->write_configs( $server_key, $server_entry );
		} else {
			\WP_CLI::log( '' );
			\WP_CLI::log( \WP_CLI::colorize( '%yTip:%n Run with --write to automatically update client config files on this machine.' ) );
		}

		\WP_CLI::log( '' );
		\WP_CLI::success( 'Setup complete.' );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the WordPress user to use for the Application Password.
	 *
	 * @param array $assoc_args Named CLI arguments.
	 *
	 * @return \WP_User
	 */
	private function resolve_user( $assoc_args ) {
		// --user is a WP-CLI global flag — it sets the current user before the
		// command runs, so wp_get_current_user() already reflects it.
		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			\WP_CLI::error( 'No user context. Pass the global --user flag: wp acrossai-mcp setup --user=admin --server=default-mcp-server' );
		}

		if ( ! user_can( $user, 'manage_options' ) ) {
			\WP_CLI::warning( "User '{$user->user_login}' does not have administrator privileges." );
		}

		return $user;
	}

	/**
	 * Resolve which MCP server to use.
	 *
	 * Uses --server if provided, otherwise prompts interactively.
	 *
	 * @param array $assoc_args Named CLI arguments.
	 *
	 * @return array Server DB row.
	 */
	private function resolve_server( $assoc_args ) {
		$servers = MCPServerTable::get_all();

		if ( empty( $servers ) ) {
			\WP_CLI::error( 'No MCP servers found. Add one via WP Admin → MCP Manager.' );
		}

		$server_arg = \WP_CLI\Utils\get_flag_value( $assoc_args, 'server', null );

		if ( $server_arg ) {
			foreach ( $servers as $row ) {
				if ( sanitize_title( $row['server_name'] ) === sanitize_title( $server_arg ) ) {
					\WP_CLI::log( 'Using server: ' . \WP_CLI::colorize( '%C' . $row['server_name'] . '%n' ) );
					return $row;
				}
			}
			\WP_CLI::error( "Server '{$server_arg}' not found. Available: " . implode( ', ', array_column( $servers, 'server_name' ) ) );
		}

		// Interactive selection.
		if ( count( $servers ) === 1 ) {
			\WP_CLI::log( 'Using server: ' . \WP_CLI::colorize( '%C' . $servers[0]['server_name'] . '%n' ) );
			return $servers[0];
		}

		$choices = array();
		foreach ( $servers as $index => $row ) {
			$status          = $row['is_enabled'] ? ' (active)' : ' (inactive)';
			$choices[ $index + 1 ] = $row['server_name'] . $status;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Available servers:' );
		foreach ( $choices as $num => $label ) {
			\WP_CLI::log( "  [{$num}] {$label}" );
		}

		$selection = (int) \cli\prompt( 'Select server number', 1 );

		if ( ! isset( $servers[ $selection - 1 ] ) ) {
			\WP_CLI::error( 'Invalid selection.' );
		}

		return $servers[ $selection - 1 ];
	}

	/**
	 * Print the config block as a JSON snippet for each supported client.
	 *
	 * @param string $server_key   The key to use inside the top-level object.
	 * @param array  $server_entry The MCP server entry array.
	 *
	 * @return void
	 */
	private function display_json( $server_key, $server_entry ) {
		foreach ( $this->clients as $id => $client ) {
			\WP_CLI::log( \WP_CLI::colorize( "%B── {$client['label']} ──%n" ) );
			\WP_CLI::log( 'Config file: ' . $client['config_file'] );
			\WP_CLI::log( '' );

			$top_key = $client['top_key'];
			if ( strpos( $top_key, '.' ) !== false ) {
				// Nested key, e.g. "mcp.servers" → { "mcp": { "servers": { ... } } }
				list( $outer, $inner ) = explode( '.', $top_key, 2 );
				$block = array( $outer => array( $inner => array( $server_key => $server_entry ) ) );
			} else {
				$block = array( $top_key => array( $server_key => $server_entry ) );
			}

			\WP_CLI::log( json_encode( $block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			\WP_CLI::log( '' );
		}
	}

	/**
	 * Print a simple table summary.
	 *
	 * @param string $server_key   The key to use inside the top-level object.
	 * @param array  $server_entry The MCP server entry array.
	 *
	 * @return void
	 */
	private function display_table( $server_key, $server_entry ) {
		$rows = array(
			array( 'Field', 'Value' ),
			array( 'Server key', $server_key ),
			array( 'Command', $server_entry['command'] ),
			array( 'Args', implode( ' ', $server_entry['args'] ) ),
			array( 'WP_API_URL', $server_entry['env']['WP_API_URL'] ),
			array( 'WP_API_USERNAME', $server_entry['env']['WP_API_USERNAME'] ),
			array( 'WP_API_PASSWORD', $server_entry['env']['WP_API_PASSWORD'] ),
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'field' => $row[0],
				'value' => $row[1],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Write the server entry into each client's config file on this machine.
	 *
	 * Backs up the existing file before writing (appends .bak.<timestamp>).
	 *
	 * @param string $server_key   The key to use inside the top-level object.
	 * @param array  $server_entry The MCP server entry array.
	 *
	 * @return void
	 */
	private function write_configs( $server_key, $server_entry ) {
		$home = getenv( 'HOME' ) ?: getenv( 'USERPROFILE' ) ?: '';

		foreach ( $this->clients as $id => $client ) {
			$raw_path = str_replace( '~', $home, $client['config_file'] );
			$dir      = dirname( $raw_path );

			if ( ! is_dir( $dir ) ) {
				\WP_CLI::log( "  Skipped {$client['label']}: config directory does not exist ({$dir})" );
				continue;
			}

			// Read existing config.
			$config = array();
			if ( file_exists( $raw_path ) ) {
				// Back up first.
				$backup = $raw_path . '.bak.' . time();
				copy( $raw_path, $backup );

				$decoded = json_decode( file_get_contents( $raw_path ), true );
				if ( is_array( $decoded ) ) {
					$config = $decoded;
				}
			}

			// Merge the new entry.
			$top_key = $client['top_key'];
			if ( strpos( $top_key, '.' ) !== false ) {
				list( $outer, $inner ) = explode( '.', $top_key, 2 );
				if ( ! isset( $config[ $outer ] ) ) {
					$config[ $outer ] = array();
				}
				if ( ! isset( $config[ $outer ][ $inner ] ) ) {
					$config[ $outer ][ $inner ] = array();
				}
				$config[ $outer ][ $inner ][ $server_key ] = $server_entry;
			} else {
				if ( ! isset( $config[ $top_key ] ) ) {
					$config[ $top_key ] = array();
				}
				$config[ $top_key ][ $server_key ] = $server_entry;
			}

			file_put_contents( $raw_path, json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
			\WP_CLI::success( "  Updated {$client['label']}: {$raw_path}" );
		}
	}
}
