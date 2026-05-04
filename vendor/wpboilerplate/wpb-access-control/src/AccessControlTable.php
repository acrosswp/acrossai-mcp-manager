<?php
/**
 * Access Control Table.
 *
 * Owns the {prefix}wpb_access_control database table and provides all
 * CRUD operations. Every consuming plugin calls maybe_create_table() on
 * activation and plugins_loaded so the table is always up to date.
 *
 * Table schema (v2.0.0)
 * ---------------------
 *   id                    BIGINT PK AI
 *   namespace             VARCHAR(100)  — REST namespace or any product-scoped prefix
 *   key                   VARCHAR(255)  — resource identifier within that namespace
 *   access_control_key    VARCHAR(100)  — rule type slug: '', 'everyone', 'wp_role', 'wp_user', …
 *   access_control_value  TEXT          — JSON-encoded options array, or ''
 *   created_at            DATETIME
 *   updated_at            DATETIME
 *   UNIQUE (namespace, key)
 *
 * Rule storage convention
 * -----------------------
 *   access_control_key = ''         , access_control_value = ''   → no user access added by admin
 *   access_control_key = 'everyone' , access_control_value = ''   → everyone (no restriction)
 *   access_control_key = 'wp_role'  , access_control_value = '["editor","author"]'
 *   access_control_key = 'wp_user'  , access_control_value = '["1","42"]'
 *
 * Caching
 * -------
 * Read results are cached in the 'wpb_access_control' object-cache group.
 * All write methods flush the affected key. Never write directly via $wpdb
 * — always go through update() / delete() so the cache stays consistent.
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the {prefix}wpb_access_control table.
 *
 * @since 1.0.0
 */
class AccessControlTable {

	const TABLE_NAME        = 'wpb_access_control';
	const DB_VERSION        = '2.0.0';
	const DB_VERSION_OPTION = 'wpb_access_control_db_version';
	const CACHE_GROUP       = 'wpb_access_control';
	const NAMESPACE_LENGTH  = 100;
	const KEY_LENGTH        = 255;

	// -------------------------------------------------------------------------
	// Table lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Return the full table name with the WordPress DB prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the table (idempotent via dbDelta).
	 *
	 * Stores DB_VERSION so subsequent maybe_create_table() calls are no-ops
	 * until the version constant changes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Two spaces before PRIMARY KEY required by dbDelta.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			namespace VARCHAR(" . self::NAMESPACE_LENGTH . ") NOT NULL DEFAULT '',
			`key` VARCHAR(" . self::KEY_LENGTH . ") NOT NULL DEFAULT '',
			access_control_key VARCHAR(100) NOT NULL DEFAULT '',
			access_control_value TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY namespace_key (namespace(" . self::NAMESPACE_LENGTH . "),`key`(191))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Drop and recreate the table when the stored schema version is outdated.
	 *
	 * Dropping ensures the old `access_control` column (v1.x) is removed, since
	 * dbDelta never drops columns. Existing rows are not migrated — callers must
	 * treat all resources as unconfigured after an upgrade.
	 *
	 * Call this on plugins_loaded AND on the activation hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_create_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			global $wpdb;
			$table_name = self::get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
			self::create_table();
		}
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Return the stored access rule for a namespace + key pair.
	 *
	 * Returns ['key' => '', 'value' => []] when no row exists, which the
	 * AccessControlManager treats as "no user access added by admin".
	 *
	 * @since 2.0.0
	 *
	 * @param string $namespace Resource namespace (e.g. 'mcp', 'procureco/v1').
	 * @param string $key       Resource key within that namespace.
	 *
	 * @return array{key: string, value: string[]}
	 */
	public static function get( string $namespace, string $key ): array {
		$cache_key = self::cache_key( $namespace, $key );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT access_control_key, access_control_value FROM `{$table_name}` WHERE namespace = %s AND `key` = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$namespace,
				$key
			)
		);

		if ( null === $row ) {
			$result = array( 'key' => '', 'value' => array() );
		} else {
			$decoded = json_decode( (string) $row->access_control_value, true );
			$result  = array(
				'key'   => (string) $row->access_control_key,
				'value' => is_array( $decoded ) ? array_values( $decoded ) : array(),
			);
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP );

		return $result;
	}

	/**
	 * Insert or update the access rule for a namespace + key pair.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so callers do not need to check
	 * whether a row already exists. Both values are sanitized before storing.
	 *
	 * @since 2.0.0
	 *
	 * @param string   $namespace   Resource namespace.
	 * @param string   $key         Resource key.
	 * @param string   $ac_key      Rule type slug ('', 'everyone', 'wp_role', 'wp_user', …).
	 * @param string[] $ac_options  Option values (role slugs, user ID strings, etc.).
	 *
	 * @return bool True on success, false on DB error.
	 */
	public static function update( string $namespace, string $key, string $ac_key, array $ac_options ): bool {
		global $wpdb;

		$normalized   = self::normalize_input( $ac_key, $ac_options );
		$table_name   = self::get_table_name();
		$stored_value = empty( $normalized['value'] )
			? ''
			: ( wp_json_encode( $normalized['value'] ) ?: '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table_name}` (namespace, `key`, access_control_key, access_control_value)
				 VALUES (%s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE
				     access_control_key   = VALUES(access_control_key),
				     access_control_value = VALUES(access_control_value),
				     updated_at           = NOW()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$namespace,
				$key,
				$normalized['key'],
				$stored_value
			)
		);

		wp_cache_delete( self::cache_key( $namespace, $key ), self::CACHE_GROUP );

		return false !== $result;
	}

	/**
	 * Delete the access rule row for a namespace + key pair.
	 *
	 * After deletion, get() returns ['key' => '', 'value' => []] for that pair.
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key.
	 *
	 * @return bool True on success, false on DB error.
	 */
	public static function delete( string $namespace, string $key ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete(
			self::get_table_name(),
			array(
				'namespace' => $namespace,
				'key'       => $key,
			),
			array( '%s', '%s' )
		);

		wp_cache_delete( self::cache_key( $namespace, $key ), self::CACHE_GROUP );

		return false !== $result;
	}

	/**
	 * Delete all rows belonging to a given namespace.
	 *
	 * Call this from your plugin's uninstall hook to clean up your plugin's
	 * rows without affecting rows owned by other plugins.
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace Resource namespace to purge.
	 *
	 * @return bool True on success, false on DB error.
	 */
	public static function delete_all_for_namespace( string $namespace ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete(
			self::get_table_name(),
			array( 'namespace' => $namespace ),
			array( '%s' )
		);

		// Flush the entire group — we don't track individual keys per namespace.
		wp_cache_flush_group( self::CACHE_GROUP );

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize and normalize the rule type and options before storing.
	 *
	 * @since 2.0.0
	 *
	 * @param string   $key     Rule type slug from user input.
	 * @param string[] $options Option values from user input.
	 *
	 * @return array{key: string, value: string[]}
	 */
	private static function normalize_input( string $key, array $options ): array {
		$key     = sanitize_key( $key );
		$options = array_values( array_map( 'sanitize_key', $options ) );

		// These states never carry options.
		if ( '' === $key || 'everyone' === $key ) {
			$options = array();
		}

		return array( 'key' => $key, 'value' => $options );
	}

	/**
	 * Build the object-cache key for a namespace + key pair.
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key.
	 *
	 * @return string Cache key.
	 */
	private static function cache_key( string $namespace, string $key ): string {
		return $namespace . ':' . $key;
	}
}
