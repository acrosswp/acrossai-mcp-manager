<?php
/**
 * Removes the retired custom OAuth connector schema and options.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Database
 */

namespace ACROSSAI_MCP_MANAGER\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time cleanup for the retired custom Claude connector implementation.
 */
class LegacyConnectorCleanup {

	const DB_VERSION        = '0.0.1';
	const DB_VERSION_OPTION = 'acrossai_mcp_legacy_connector_cleanup_version';

	/**
	 * Run the cleanup only once per plugin version of this migration.
	 *
	 * @return void
	 */
	public static function maybe_cleanup() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::cleanup();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Drop unused OAuth tables/options/transients left by the retired connector.
	 *
	 * @return void
	 */
	private static function cleanup() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'acrossai_mcp_oauth_clients',
			$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
			$wpdb->prefix . 'acrossai_mcp_oauth_codes',
		);

		foreach ( $tables as $table_name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		}

		delete_option( 'acrossai_mcp_oauth_enabled' );
		delete_option( 'acrossai_mcp_oauth_clients_db_version' );
		delete_option( 'acrossai_mcp_oauth_tokens_db_version' );
		delete_option( 'acrossai_mcp_oauth_codes_db_version' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acrossai_mcp_oauth_new_secret_%' OR option_name LIKE '_transient_timeout_acrossai_mcp_oauth_new_secret_%'"
		);

		flush_rewrite_rules();
	}
}
