<?php
/**
 * MCP Server database table manager.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Database
 */

namespace ACROSSAI_MCP_MANAGER\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the {prefix}acrossai_mcp_servers custom table.
 *
 * Table columns:
 *   id          BIGINT UNSIGNED  — auto-increment primary key
 *   server_name VARCHAR(255)     — human-readable server name
 *   is_enabled  TINYINT(1)       — 1 = running, 0 = stopped
 *   created_at  DATETIME         — row creation timestamp
 *
 * @since 1.0.0
 */
class MCPServerTable {

	/**
	 * Base table name (without DB prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'acrossai_mcp_servers';

	/**
	 * Current schema version.
	 *
	 * Bump this string whenever the table structure changes so
	 * maybe_create_table() triggers a dbDelta upgrade automatically.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * WordPress option key that stores the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'acrossai_mcp_manager_db_version';

	// -------------------------------------------------------------------------
	// Table lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Return the full table name including the WordPress DB prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or upgrade the table using dbDelta.
	 *
	 * Safe to call on every activation — dbDelta is idempotent.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Note: dbDelta requires two spaces before PRIMARY KEY.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			server_name VARCHAR(255) NOT NULL,
			is_enabled TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Create the table only when the stored schema version is outdated.
	 *
	 * Called on every plugins_loaded so upgrades are applied automatically.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}

		// Always seed the default row if the table is empty — covers the case
		// where the table was created but the activation hook never fired
		// (e.g. the plugin was already active when this code was deployed).
		self::insert_default_server();
	}

	/**
	 * Seed the table with the default server row when the table is empty.
	 *
	 * Called once during plugin activation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function insert_default_server() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		if ( 0 === $count ) {
			$wpdb->insert(
				$table_name,
				array(
					'server_name' => 'Default MCP Server',
					'is_enabled'  => 0,
				),
				array( '%s', '%d' )
			);
		}
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Return all server rows ordered by id ASC.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of associative arrays, one per row.
	 */
	public static function get_all() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY id ASC", ARRAY_A );

		return $results ?: array();
	}

	/**
	 * Return a single server row by primary key.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Server row ID.
	 *
	 * @return array|null Associative array on success, null if not found.
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		$table_name = self::get_table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", absint( $id ) ),
			ARRAY_A
		);
	}

	/**
	 * Toggle the is_enabled flag for a given server row.
	 *
	 * 0 → 1 (enable / run)  |  1 → 0 (disable / stop)
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Server row ID.
	 *
	 * @return bool True on success, false if the row was not found or the update failed.
	 */
	public static function toggle_status( $id ) {
		global $wpdb;

		$server = self::get_by_id( $id );
		if ( ! $server ) {
			return false;
		}

		$new_status = $server['is_enabled'] ? 0 : 1;

		$result = $wpdb->update(
			self::get_table_name(),
			array( 'is_enabled' => $new_status ),
			array( 'id'         => absint( $id ) ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Return true if at least one server row has is_enabled = 1.
	 *
	 * Used by the MCP Controller to decide whether to start the adapter.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function has_any_enabled() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE is_enabled = 1" );

		return $count > 0;
	}
}
