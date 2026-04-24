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
 * Schema (v1.3.0)
 * ------
 *   id                     BIGINT UNSIGNED  PK auto-increment
 *   server_name            VARCHAR(255)     human-readable name
 *   server_slug            VARCHAR(255)     sanitize_title() of name; set once at creation, never changes
 *   description            VARCHAR(500)     optional description
 *   is_enabled             TINYINT(1)       1 = running, 0 = stopped
 *   registered_from        VARCHAR(50)      origin: 'plugin' | 'database' | 'theme' | 'core'
 *   server_route_namespace VARCHAR(100)     REST namespace (e.g. 'mcp')
 *   server_route           VARCHAR(255)     REST route path (e.g. 'mcp-adapter-default-server')
 *   server_version         VARCHAR(50)      MCP server version (e.g. 'v1.0.0')
 *   created_at             DATETIME         row creation timestamp
 *
 * registered_from values
 * ----------------------
 *   'plugin'   — seeded by this plugin (Default MCP Server); managed by DefaultServerFactory
 *   'database' — created via WP admin UI; booted by Controller::register_database_servers()
 *   'theme'    — reserved for theme-registered servers
 *   'core'     — reserved for WordPress core
 *
 * Bump DB_VERSION whenever the schema changes — maybe_create_table() will
 * detect the mismatch and run dbDelta automatically.
 *
 * Caching
 * -------
 * All read methods use the 'acrossai_mcp' cache group. Write methods
 * delete the affected keys so stale data is never served.
 *
 * @since 1.0.0
 */
class MCPServerTable {

	const TABLE_NAME        = 'acrossai_mcp_servers';
	const DB_VERSION        = '1.3.0';
	const DB_VERSION_OPTION = 'acrossai_mcp_manager_db_version';

	/**
	 * The server_id used by DefaultServerFactory for the built-in plugin server.
	 * Used to block UI-created servers from accidentally claiming the same slug.
	 */
	const DEFAULT_SERVER_SLUG = 'mcp-adapter-default-server';

	/**
	 * Object-cache group used for all keys in this class.
	 */
	const CACHE_GROUP = 'acrossai_mcp';

	// -------------------------------------------------------------------------
	// Helpers
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

	// -------------------------------------------------------------------------
	// Table lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Create or upgrade the table using dbDelta (idempotent).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requires exactly two spaces before PRIMARY KEY.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			server_name VARCHAR(255) NOT NULL,
			server_slug VARCHAR(255) NOT NULL DEFAULT '',
			description VARCHAR(500) NOT NULL DEFAULT '',
			is_enabled TINYINT(1) NOT NULL DEFAULT 0,
			registered_from VARCHAR(50) NOT NULL DEFAULT 'plugin',
			server_route_namespace VARCHAR(100) NOT NULL DEFAULT 'mcp',
			server_route VARCHAR(255) NOT NULL DEFAULT '',
			server_version VARCHAR(50) NOT NULL DEFAULT 'v1.0.0',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Migrate existing rows that pre-date v1.2.0.
		self::migrate_legacy_rows();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Back-fill server_slug and registered_from for rows created before v1.2.0.
	 *
	 * Safe to call multiple times — rows that already have a server_slug are skipped.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private static function migrate_legacy_rows() {
		global $wpdb;

		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, server_name, server_slug, registered_from, server_route_namespace, server_route, server_version FROM {$table_name}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$needs_update = false;
			$update_data  = array();
			$update_fmt   = array();

			// Back-fill server_slug if empty.
			if ( '' === $row['server_slug'] ) {
				$update_data['server_slug'] = sanitize_title( $row['server_name'] );
				$update_fmt[]               = '%s';
				$needs_update               = true;
			}

			// Resolve the effective slug (already set or just derived above).
			$effective_slug = '' !== $row['server_slug']
				? $row['server_slug']
				: sanitize_title( $row['server_name'] );

			// Back-fill registered_from for pre-v1.2.0 rows.
			if ( '' === $row['registered_from'] ) {
				$update_data['registered_from'] = 'plugin';
				$update_fmt[]                   = '%s';
				$needs_update                   = true;
			}

			// Back-fill server_route_namespace for pre-v1.3.0 rows.
			if ( '' === ( $row['server_route_namespace'] ?? '' ) ) {
				$update_data['server_route_namespace'] = 'mcp';
				$update_fmt[]                          = '%s';
				$needs_update                          = true;
			}

			// Back-fill server_route for pre-v1.3.0 rows.
			if ( '' === ( $row['server_route'] ?? '' ) ) {
				$update_data['server_route'] = $effective_slug;
				$update_fmt[]                = '%s';
				$needs_update                = true;
			}

			// Back-fill server_version for pre-v1.3.0 rows.
			if ( '' === ( $row['server_version'] ?? '' ) ) {
				$update_data['server_version'] = 'v1.0.0';
				$update_fmt[]                  = '%s';
				$needs_update                  = true;
			}

			if ( $needs_update ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update(
					self::get_table_name(),
					$update_data,
					array( 'id' => (int) $row['id'] ),
					$update_fmt,
					array( '%d' )
				);
			}
		}
	}

	/**
	 * Run create_table() only when the stored schema version is outdated.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}

		// Always seed — insert_default_server() is a no-op when rows exist.
		self::insert_default_server();
	}

	/**
	 * Seed the default server row when the table is empty.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function insert_default_server() {
		global $wpdb;

		// $table_name is derived from $wpdb->prefix + a constant — safe to interpolate.
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		if ( 0 === $count ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				self::get_table_name(),
				array(
					'server_name'            => 'Default MCP Server',
					'server_slug'            => self::DEFAULT_SERVER_SLUG,
					'description'            => 'WordPress MCP Adapter integration for AI clients (VS Code, Claude, GitHub Codex, ChatGPT).',
					'is_enabled'             => 0,
					'registered_from'        => 'plugin',
					'server_route_namespace' => 'mcp',
					'server_route'           => self::DEFAULT_SERVER_SLUG,
					'server_version'         => 'v1.0.0',
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);

			wp_cache_delete( 'all_servers', self::CACHE_GROUP );
			wp_cache_delete( 'has_enabled', self::CACHE_GROUP );
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
	 * @return array[] Array of associative-array rows.
	 */
	public static function get_all() {
		$cached = wp_cache_get( 'all_servers', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY id ASC", ARRAY_A );
		$results = $results ?: array();

		wp_cache_set( 'all_servers', $results, self::CACHE_GROUP );

		return $results;
	}

	/**
	 * Return all server rows where is_enabled = 1.
	 *
	 * @since 1.0.0
	 *
	 * @return array[] Array of associative-array rows.
	 */
	public static function get_enabled_servers() {
		$cached = wp_cache_get( 'enabled_servers', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( "SELECT * FROM {$table_name} WHERE is_enabled = 1 ORDER BY id ASC", ARRAY_A );
		$results = $results ?: array();

		wp_cache_set( 'enabled_servers', $results, self::CACHE_GROUP );

		return $results;
	}

	/**
	 * Return all enabled rows where registered_from = 'database'.
	 *
	 * Used by Controller to boot user-created servers via mcp_adapter_init.
	 *
	 * @since 1.2.0
	 *
	 * @return array[]
	 */
	public static function get_enabled_database_servers() {
		$cached = wp_cache_get( 'enabled_database_servers', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE is_enabled = 1 AND registered_from = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'database'
			),
			ARRAY_A
		);
		$results = $results ?: array();

		wp_cache_set( 'enabled_database_servers', $results, self::CACHE_GROUP );

		return $results;
	}

	/**
	 * Return a single server row by primary key.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Server row ID.
	 *
	 * @return array|null Row as associative array, or null if not found.
	 */
	public static function get_by_id( $id ) {
		$id        = absint( $id );
		$cache_key = 'server_' . $id;

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		wp_cache_set( $cache_key, $row, self::CACHE_GROUP );

		return $row;
	}

	/**
	 * Check if a server_slug is already taken by an existing row.
	 *
	 * Also blocks the hardcoded DefaultServerFactory slug regardless of DB state.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $slug       Slug to check.
	 * @param int|null $exclude_id Row ID to exclude from the check (for edits).
	 *
	 * @return bool True if the slug is already in use.
	 */
	public static function slug_exists( $slug, $exclude_id = null ) {
		$slug = sanitize_title( $slug );

		// The DefaultServerFactory always claims this slug at runtime.
		if ( self::DEFAULT_SERVER_SLUG === $slug ) {
			return true;
		}

		global $wpdb;

		$table_name = esc_sql( self::get_table_name() );

		if ( $exclude_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE server_slug = %s AND id != %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$slug,
					absint( $exclude_id )
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE server_slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$slug
				)
			);
		}

		return $count > 0;
	}

	/**
	 * Insert a new user-created server row (registered_from = 'database').
	 *
	 * @since 1.2.0
	 * @updated 1.3.0 Added $namespace, $route, $version params.
	 *
	 * @param string $server_name Human-readable server name (required).
	 * @param string $description Optional description.
	 * @param string $namespace   REST route namespace. Defaults to 'mcp'.
	 * @param string $route       REST route path. Defaults to sanitize_title($server_name).
	 * @param string $version     MCP server version string. Defaults to 'v1.0.0'.
	 *
	 * @return int|false New row ID on success, false on failure or slug conflict.
	 */
	public static function create_server( $server_name, $description = '', $namespace = 'mcp', $route = '', $version = 'v1.0.0' ) {
		$server_name = sanitize_text_field( $server_name );
		$description = sanitize_textarea_field( $description );
		$server_slug = sanitize_title( $server_name );
		$namespace   = sanitize_text_field( $namespace ) ?: 'mcp';
		$route       = sanitize_text_field( $route );
		$version     = sanitize_text_field( $version ) ?: 'v1.0.0';

		// Route defaults to the slug when not provided.
		if ( '' === $route ) {
			$route = $server_slug;
		}

		if ( empty( $server_name ) || empty( $server_slug ) ) {
			return false;
		}

		if ( self::slug_exists( $server_slug ) ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			self::get_table_name(),
			array(
				'server_name'            => $server_name,
				'server_slug'            => $server_slug,
				'description'            => $description,
				'is_enabled'             => 0,
				'registered_from'        => 'database',
				'server_route_namespace' => $namespace,
				'server_route'           => $route,
				'server_version'         => $version,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		$new_id = (int) $wpdb->insert_id;

		wp_cache_delete( 'all_servers', self::CACHE_GROUP );
		wp_cache_delete( 'has_enabled', self::CACHE_GROUP );
		wp_cache_delete( 'enabled_database_servers', self::CACHE_GROUP );

		return $new_id;
	}

	/**
	 * Delete a server row.
	 *
	 * Only rows with registered_from = 'database' may be deleted from the UI.
	 * Callers should verify this before calling; this method enforces it.
	 *
	 * @since 1.2.0
	 *
	 * @param int $id Server row ID.
	 *
	 * @return bool True on success, false on failure or if row is not a database server.
	 */
	public static function delete_server( $id ) {
		$id     = absint( $id );
		$server = self::get_by_id( $id );

		if ( ! $server ) {
			return false;
		}

		// Only user-created servers may be deleted.
		if ( 'database' !== $server['registered_from'] ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete(
			self::get_table_name(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wp_cache_delete( 'server_' . $id, self::CACHE_GROUP );
			wp_cache_delete( 'all_servers', self::CACHE_GROUP );
			wp_cache_delete( 'enabled_servers', self::CACHE_GROUP );
			wp_cache_delete( 'enabled_database_servers', self::CACHE_GROUP );
			wp_cache_delete( 'has_enabled', self::CACHE_GROUP );
		}

		return false !== $result;
	}

	/**
	 * Toggle the is_enabled flag for a server row (0 → 1 or 1 → 0).
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Server row ID.
	 *
	 * @return bool True on success, false if not found or update failed.
	 */
	public static function toggle_status( $id ) {
		$id     = absint( $id );
		$server = self::get_by_id( $id );

		if ( ! $server ) {
			return false;
		}

		global $wpdb;

		$new_status = $server['is_enabled'] ? 0 : 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			self::get_table_name(),
			array( 'is_enabled' => $new_status ),
			array( 'id'         => $id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wp_cache_delete( 'server_' . $id, self::CACHE_GROUP );
			wp_cache_delete( 'all_servers', self::CACHE_GROUP );
			wp_cache_delete( 'enabled_servers', self::CACHE_GROUP );
			wp_cache_delete( 'enabled_database_servers', self::CACHE_GROUP );
			wp_cache_delete( 'has_enabled', self::CACHE_GROUP );
		}

		return false !== $result;
	}

	/**
	 * Update editable fields for a server row.
	 *
	 * Only whitelisted keys (server_name, description) are persisted.
	 * server_slug is intentionally NOT updatable — changing it would break
	 * all existing client configs pointing to the old MCP URL.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $id   Server row ID.
	 * @param array $data Associative array of fields to update.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function update_server( $id, array $data ) {
		$id      = absint( $id );
		$allowed = array( 'server_name', 'description', 'server_route_namespace', 'server_route', 'server_version' );
		$fields  = array_intersect_key( $data, array_flip( $allowed ) );

		if ( empty( $fields ) ) {
			return false;
		}

		global $wpdb;

		$formats = array_fill( 0, count( $fields ), '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			self::get_table_name(),
			$fields,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		if ( false !== $result ) {
			wp_cache_delete( 'server_' . $id, self::CACHE_GROUP );
			wp_cache_delete( 'all_servers', self::CACHE_GROUP );
		}

		return false !== $result;
	}

	/**
	 * Return true if at least one server row has is_enabled = 1.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function has_any_enabled() {
		$cached = wp_cache_get( 'has_enabled', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		global $wpdb;

		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE is_enabled = 1" );

		wp_cache_set( 'has_enabled', $count, self::CACHE_GROUP );

		return $count > 0;
	}
}
