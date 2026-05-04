<?php
/**
 * Direct connector audit log table manager.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Database
 */

namespace ACROSSAI_MCP_MANAGER\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores direct Claude connector audit events.
 */
class ConnectorAuditLogTable {

	const TABLE_NAME        = 'acrossai_mcp_connector_audit_logs';
	const DB_VERSION        = '0.0.1';
	const DB_VERSION_OPTION = 'acrossai_mcp_connector_audit_log_db_version';

	/**
	 * Return the full table name including the WordPress DB prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or upgrade the connector audit log table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			server_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			server_slug VARCHAR(255) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			client_id VARCHAR(255) NOT NULL DEFAULT '',
			event_type VARCHAR(60) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			resource_url TEXT NULL,
			scope VARCHAR(255) NOT NULL DEFAULT '',
			request_method VARCHAR(20) NOT NULL DEFAULT '',
			request_route VARCHAR(255) NOT NULL DEFAULT '',
			response_code SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			failure_code VARCHAR(100) NOT NULL DEFAULT '',
			authorization_code_hash CHAR(64) NOT NULL DEFAULT '',
			access_token_hash CHAR(64) NOT NULL DEFAULT '',
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			details LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY server_created (server_id, created_at),
			KEY server_event_created (server_id, event_type, created_at),
			KEY server_status_created (server_id, status, created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Create the table only when the stored version is outdated.
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * Record an audit event.
	 *
	 * @param array $data Event payload.
	 *
	 * @return bool
	 */
	public static function record_event( array $data ) {
		global $wpdb;

		$details = null;
		if ( isset( $data['details'] ) ) {
			if ( is_array( $data['details'] ) ) {
				$details = wp_json_encode( $data['details'], JSON_UNESCAPED_SLASHES );
			} elseif ( is_string( $data['details'] ) ) {
				$details = $data['details'];
			}
		}

		$insert_data = array(
			'server_id'               => isset( $data['server_id'] ) ? (int) $data['server_id'] : 0,
			'server_slug'             => isset( $data['server_slug'] ) ? sanitize_title( $data['server_slug'] ) : '',
			'user_id'                 => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'client_id'               => isset( $data['client_id'] ) ? sanitize_text_field( $data['client_id'] ) : '',
			'event_type'              => isset( $data['event_type'] ) ? sanitize_key( $data['event_type'] ) : '',
			'status'                  => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : '',
			'resource_url'            => isset( $data['resource_url'] ) ? esc_url_raw( $data['resource_url'] ) : '',
			'scope'                   => isset( $data['scope'] ) ? sanitize_text_field( $data['scope'] ) : '',
			'request_method'          => isset( $data['request_method'] ) ? strtoupper( sanitize_text_field( $data['request_method'] ) ) : '',
			'request_route'           => isset( $data['request_route'] ) ? sanitize_text_field( $data['request_route'] ) : '',
			'response_code'           => isset( $data['response_code'] ) ? (int) $data['response_code'] : 0,
			'failure_code'            => isset( $data['failure_code'] ) ? sanitize_key( $data['failure_code'] ) : '',
			'authorization_code_hash' => isset( $data['authorization_code_hash'] ) ? sanitize_text_field( $data['authorization_code_hash'] ) : '',
			'access_token_hash'       => isset( $data['access_token_hash'] ) ? sanitize_text_field( $data['access_token_hash'] ) : '',
			'ip_address'              => isset( $data['ip_address'] ) ? sanitize_text_field( $data['ip_address'] ) : '',
			'user_agent'              => isset( $data['user_agent'] ) ? sanitize_text_field( $data['user_agent'] ) : '',
			'details'                 => $details,
			'created_at'              => current_time( 'mysql', true ),
		);

		return false !== $wpdb->insert(
			self::get_table_name(),
			$insert_data,
			array(
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
	}

	/**
	 * Return the total number of log rows for one server.
	 *
	 * @param int  $server_id      Server row ID.
	 * @param bool $include_global Whether to include global rows (server_id=0).
	 *
	 * @return int
	 */
	public static function count_by_server( $server_id, $include_global = false ) {
		global $wpdb;

		$table = self::get_table_name();

		if ( $include_global ) {
			$query = $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE server_id = %d OR server_id = 0',
				$table,
				absint( $server_id )
			);
			return (int) $wpdb->get_var( $query );
		}

		$query = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE server_id = %d',
			$table,
			absint( $server_id )
		);
		return (int) $wpdb->get_var( $query );
	}

	/**
	 * Return paginated log rows for one server.
	 *
	 * @param int  $server_id      Server row ID.
	 * @param int  $per_page       Rows per page.
	 * @param int  $page           Current page number.
	 * @param bool $include_global Whether to include global rows (server_id=0).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_logs_by_server( $server_id, $per_page = 20, $page = 1, $include_global = false ) {
		global $wpdb;

		$per_page = max( 1, absint( $per_page ) );
		$page     = max( 1, absint( $page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$table = self::get_table_name();

		if ( $include_global ) {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE server_id = %d OR server_id = 0 ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$table,
				absint( $server_id ),
				$per_page,
				$offset
			);
			$results = $wpdb->get_results( $query, ARRAY_A );
		} else {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE server_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$table,
				absint( $server_id ),
				$per_page,
				$offset
			);
			$results = $wpdb->get_results( $query, ARRAY_A );
		}

		return $results ?: array();
	}
}
