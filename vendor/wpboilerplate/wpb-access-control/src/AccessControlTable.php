<?php
/**
 * Access Control Table.
 *
 * Owns the {prefix}wpb_access_control database table and provides all
 * CRUD operations. Every consuming plugin calls maybe_create_table() on
 * activation and plugins_loaded so the table is always up to date.
 *
 * Table schema (v1.0.0)
 * ---------------------
 *   id              BIGINT PK AI
 *   namespace       VARCHAR(100)  — REST namespace or any product-scoped prefix
 *   key             VARCHAR(255)  — resource identifier within that namespace
 *   access_control  TEXT          — JSON config or ''
 *   created_at      DATETIME
 *   updated_at      DATETIME
 *   UNIQUE (namespace, key)
 *
 * JSON config format
 * ------------------
 *   { "type": "wp_role", "options": ["editor", "author"] }
 *   An empty string means "no restriction — allow everyone".
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
	const DB_VERSION        = '1.0.0';
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
	 * Create or upgrade the table (idempotent).
	 *
	 * Runs dbDelta unconditionally and stores the current DB_VERSION so
	 * subsequent calls to maybe_create_table() are no-ops until the version
	 * constant changes.
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
			access_control TEXT NOT NULL DEFAULT '',
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
	 * Run create_table() only when the stored schema version is outdated.
	 *
	 * Call this on plugins_loaded AND on the activation hook so both fresh
	 * installs and library version upgrades are handled correctly.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_create_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Return the stored access_control JSON for a namespace + key pair.
	 *
	 * Returns an empty string when no row exists (treated as "everyone" by
	 * AccessControlManager::user_has_access()).
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace Resource namespace (e.g. 'mcp', 'procureco/v1').
	 * @param string $key       Resource key within that namespace (e.g. 'default-server').
	 *
	 * @return string JSON config string or ''.
	 */
	public static function get( string $namespace, string $key ): string {
		$cache_key = self::cache_key( $namespace, $key );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT access_control FROM `{$table_name}` WHERE namespace = %s AND `key` = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$namespace,
				$key
			)
		);

		$value = ( null !== $value ) ? (string) $value : '';

		wp_cache_set( $cache_key, $value, self::CACHE_GROUP );

		return $value;
	}

	/**
	 * Insert or update the access_control value for a namespace + key pair.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so callers do not need to check
	 * whether a row already exists. The value is sanitized before storing —
	 * invalid JSON is replaced with an empty string (everyone).
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace      Resource namespace.
	 * @param string $key            Resource key.
	 * @param string $access_control JSON config or '' for no restriction.
	 *
	 * @return bool True on success, false on DB error.
	 */
	public static function update( string $namespace, string $key, string $access_control ): bool {
		global $wpdb;

		$access_control = self::sanitize( $access_control );
		$table_name     = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table_name}` (namespace, `key`, access_control)
				 VALUES (%s, %s, %s)
				 ON DUPLICATE KEY UPDATE access_control = VALUES(access_control), updated_at = NOW()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$namespace,
				$key,
				$access_control
			)
		);

		wp_cache_delete( self::cache_key( $namespace, $key ), self::CACHE_GROUP );

		return false !== $result;
	}

	/**
	 * Delete the access_control row for a namespace + key pair.
	 *
	 * After deletion, get() returns '' (everyone) for that pair.
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
	// Sanitization
	// -------------------------------------------------------------------------

	/**
	 * Validate and normalize an access_control JSON string before storing.
	 *
	 * Accepts:
	 *   - '' (empty string) — stored as-is; treated as "everyone".
	 *   - JSON: { "type": "wp_role", "options": ["editor"] }
	 *
	 * Any value that is not valid JSON, not an object, or missing `type` is
	 * normalised to '' so no resource is accidentally locked.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw value from user input.
	 *
	 * @return string Sanitized JSON string or ''.
	 */
	public static function sanitize( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['type'] ) ) {
			return '';
		}

		$type    = sanitize_key( $decoded['type'] );
		$options = array();

		if ( isset( $decoded['options'] ) && is_array( $decoded['options'] ) ) {
			$options = array_values( array_map( 'sanitize_key', $decoded['options'] ) );
		}

		$encoded = wp_json_encode( array( 'type' => $type, 'options' => $options ) );

		return $encoded ?: '';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

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
