<?php
/**
 * CLI auth audit log table manager.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Database
 */

namespace ACROSSAI_MCP_MANAGER\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores browser-assisted CLI auth outcomes per MCP server.
 */
class CliAuthLogTable {

	const TABLE_NAME        = 'acrossai_mcp_cli_auth_logs';
	const DB_VERSION        = '0.0.1';
	const DB_VERSION_OPTION = 'acrossai_mcp_cli_auth_log_db_version';

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
	 * Create or upgrade the CLI auth log table.
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
			status VARCHAR(20) NOT NULL DEFAULT '',
			failure_code VARCHAR(100) NOT NULL DEFAULT '',
			auth_code_hash CHAR(64) NOT NULL DEFAULT '',
			app_password_uuid VARCHAR(64) NOT NULL DEFAULT '',
			approved_at DATETIME NULL DEFAULT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY auth_code_hash (auth_code_hash),
			KEY server_created (server_id, created_at),
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
	 * Record that a user approved a CLI auth request.
	 *
	 * @param string $auth_code            Raw auth code.
	 * @param array  $server_row           Optional MCP server row.
	 * @param int    $user_id              Approving WordPress user ID.
	 * @param string $fallback_server_slug Optional fallback server slug.
	 *
	 * @return bool
	 */
	public static function record_approved( $auth_code, array $server_row = array(), $user_id = 0, $fallback_server_slug = '' ) {
		return self::save_event(
			$auth_code,
			array(
				'server_id'    => isset( $server_row['id'] ) ? (int) $server_row['id'] : 0,
				'server_slug'  => ! empty( $server_row['server_slug'] ) ? $server_row['server_slug'] : sanitize_title( $fallback_server_slug ),
				'user_id'      => (int) $user_id,
				'status'       => 'approved',
				'failure_code' => '',
				'approved_at'  => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Record a successful CLI auth exchange.
	 *
	 * @param string $auth_code         Raw auth code.
	 * @param string $app_password_uuid Application Password UUID.
	 * @param array  $context           Optional log context.
	 *
	 * @return bool
	 */
	public static function record_success( $auth_code, $app_password_uuid = '', array $context = array() ) {
		return self::save_event(
			$auth_code,
			array(
				'server_id'         => isset( $context['server_id'] ) ? (int) $context['server_id'] : 0,
				'server_slug'       => isset( $context['server_slug'] ) ? sanitize_title( $context['server_slug'] ) : '',
				'user_id'           => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
				'status'            => 'success',
				'failure_code'      => '',
				'app_password_uuid' => sanitize_text_field( $app_password_uuid ),
				'completed_at'      => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Record a failed CLI auth exchange.
	 *
	 * @param string $auth_code    Raw auth code.
	 * @param string $failure_code Machine-readable failure code.
	 * @param array  $context      Optional log context.
	 *
	 * @return bool
	 */
	public static function record_failed( $auth_code, $failure_code, array $context = array() ) {
		return self::save_event(
			$auth_code,
			array(
				'server_id'    => isset( $context['server_id'] ) ? (int) $context['server_id'] : 0,
				'server_slug'  => isset( $context['server_slug'] ) ? sanitize_title( $context['server_slug'] ) : '',
				'user_id'      => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
				'status'       => 'failed',
				'failure_code' => sanitize_key( $failure_code ),
				'completed_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Return the total number of log rows for one server.
	 *
	 * @param int $server_id Server row ID.
	 *
	 * @return int
	 */
	public static function count_by_server( $server_id ) {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE server_id = %d',
				$table,
				absint( $server_id )
			)
		);
	}

	/**
	 * Return paginated log rows for one server.
	 *
	 * @param int $server_id Server row ID.
	 * @param int $per_page  Rows per page.
	 * @param int $page      Current page number.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_logs_by_server( $server_id, $per_page = 20, $page = 1 ) {
		global $wpdb;

		$per_page = max( 1, absint( $per_page ) );
		$page     = max( 1, absint( $page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE server_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$table,
				absint( $server_id ),
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Persist an auth event, updating an existing auth-code row when possible.
	 *
	 * @param string $auth_code Raw auth code.
	 * @param array  $data      Sanitized event data.
	 *
	 * @return bool
	 */
	private static function save_event( $auth_code, array $data ) {
		global $wpdb;

		$auth_code_hash = self::hash_auth_code( $auth_code );
		$existing       = self::get_by_auth_code_hash( $auth_code_hash );

		if ( ! $auth_code_hash ) {
			return false;
		}

		$clean_data = array(
			'server_id'         => isset( $data['server_id'] ) ? (int) $data['server_id'] : 0,
			'server_slug'       => isset( $data['server_slug'] ) ? sanitize_title( $data['server_slug'] ) : '',
			'user_id'           => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'status'            => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : '',
			'failure_code'      => isset( $data['failure_code'] ) ? sanitize_key( $data['failure_code'] ) : '',
			'app_password_uuid' => isset( $data['app_password_uuid'] ) ? sanitize_text_field( $data['app_password_uuid'] ) : '',
			'approved_at'       => $data['approved_at'] ?? null,
			'completed_at'      => $data['completed_at'] ?? null,
		);

		if ( $existing ) {
			$update_data = array();
			foreach ( $clean_data as $key => $value ) {
				if ( null === $value ) {
					continue;
				}
				$update_data[ $key ] = $value;
			}

			if ( empty( $update_data ) ) {
				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update(
				self::get_table_name(),
				$update_data,
				array( 'id' => (int) $existing['id'] ),
				self::get_formats( $update_data ),
				array( '%d' )
			);
		}

		$insert_data = array(
			'auth_code_hash' => $auth_code_hash,
			'created_at'     => current_time( 'mysql', true ),
		);

		foreach ( $clean_data as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			$insert_data[ $key ] = $value;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->insert(
			self::get_table_name(),
			$insert_data,
			self::get_formats( $insert_data )
		);
	}

	/**
	 * Return one log row by auth code hash.
	 *
	 * @param string $auth_code_hash SHA-256 auth code hash.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function get_by_auth_code_hash( $auth_code_hash ) {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE auth_code_hash = %s LIMIT 1',
				$table,
				$auth_code_hash
			),
			ARRAY_A
		);
	}

	/**
	 * Hash an auth code so raw credentials are never stored.
	 *
	 * @param string $auth_code Raw auth code.
	 *
	 * @return string
	 */
	private static function hash_auth_code( $auth_code ) {
		$auth_code = sanitize_text_field( (string) $auth_code );
		return '' === $auth_code ? '' : hash( 'sha256', $auth_code );
	}

	/**
	 * Infer wpdb formats for an insert or update payload.
	 *
	 * @param array $data DB payload.
	 *
	 * @return array<int, string>
	 */
	private static function get_formats( array $data ) {
		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			if ( in_array( $key, array( 'server_id', 'user_id' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}
}
