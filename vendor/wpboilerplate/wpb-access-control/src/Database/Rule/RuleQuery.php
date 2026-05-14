<?php
/**
 * Rule query class — CRUD entry point for the access-control table.
 *
 * Wraps BerlinDB's low-level Query API with the higher-level "rule" semantics
 * that the rest of the library needs: one logical rule per (namespace, key)
 * pair, stored as one or more flat rows in the underlying table.
 *
 * Usage
 * -----
 *   $q = new \WPBoilerplate\AccessControl\Database\Rule\Query();
 *
 *   $rule = $q->get_rule( 'procureco/v1', 'endpoints/list' );
 *   // → ['key' => 'wp_role', 'value' => ['editor', 'author']]
 *
 *   $q->set_rule( 'procureco/v1', 'endpoints/list', 'wp_role', ['editor'] );
 *   $q->clear_rule( 'procureco/v1', 'endpoints/list' );
 *   $q->purge_namespace( 'procureco/v1' );
 *
 * @package WPBoilerplate\AccessControl\Database\Rule
 * @since   3.0.0
 */

namespace WPBoilerplate\AccessControl\Database\Rule;

use BerlinDB\Database\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access-control rule query.
 *
 * @since 3.0.0
 */
class RuleQuery extends Query {

	// -------------------------------------------------------------------------
	// BerlinDB Query configuration
	// -------------------------------------------------------------------------

	/** @var string Table name without $wpdb->prefix. */
	protected $table_name = 'wpb_access_control';

	/** @var string Short alias used in SQL JOINs. */
	protected $table_alias = 'wpac';

	/** @var string Fully-qualified Schema class. */
	protected $table_schema = RuleSchema::class;

	/** @var string Singular item label (used internally by BerlinDB). */
	protected $item_name = 'rule';

	/** @var string Plural item label. */
	protected $item_name_plural = 'rules';

	/** @var string Row class to hydrate results into. */
	protected $item_shape = RuleRow::class;

	/**
	 * Object-cache group.
	 * Must not contain colons or spaces (BerlinDB restriction).
	 *
	 * @var string
	 */
	protected $cache_group = 'wpb_access_control';

	/**
	 * Static guard: ensures RuleTable is instantiated exactly once per request.
	 * BerlinDB's Table registers $wpdb->wpb_access_control, which Query reads
	 * via get_table_name() — so Table must exist before any Query is used.
	 *
	 * @var bool
	 */
	private static $table_setup = false;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * @since 3.0.0
	 */
	public function __construct() {
		if ( ! self::$table_setup ) {
			self::$table_setup = true;
			new RuleTable();
		}
		parent::__construct();
	}

	// -------------------------------------------------------------------------
	// High-level rule API
	// -------------------------------------------------------------------------

	/**
	 * Return the stored access rule for a (namespace, key) pair.
	 *
	 * Aggregates all rows for the pair into the canonical shape
	 * `['key' => string, 'value' => string[]]` so callers do not need to know
	 * about the flat-row storage detail.
	 *
	 * Returns `['key' => '', 'value' => []]` when no rows exist for the pair,
	 * which AccessControlManager treats as "no restriction configured".
	 *
	 * @since 3.0.0
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key within that namespace.
	 *
	 * @return array{key: string, value: string[]}
	 */
	public function get_rule( string $namespace, string $key ): array {
		/** @var RuleRow[] $rows */
		$rows = $this->query(
			array(
				'namespace' => $namespace,
				'key'       => $key,
				'orderby'   => 'id',
				'order'     => 'ASC',
				'number'    => 0,
			)
		);

		if ( empty( $rows ) ) {
			return array( 'key' => '', 'value' => array() );
		}

		$ac_key = (string) $rows[0]->access_control_key;
		$values = array();

		foreach ( $rows as $row ) {
			if ( '' !== $row->access_control_value ) {
				$values[] = (string) $row->access_control_value;
			}
		}

		return array( 'key' => $ac_key, 'value' => $values );
	}

	/**
	 * Insert or replace the access rule for a (namespace, key) pair.
	 *
	 * Atomically replaces all existing rows for the pair with the new rule:
	 *   - `''`        → purge only (no rows = "no restriction configured").
	 *   - `'everyone'`→ one sentinel row with an empty value.
	 *   - anything else → one row per option in $ac_options.
	 *
	 * Both $ac_key and each element of $ac_options are sanitized with
	 * sanitize_key() before storage.
	 *
	 * @since 3.0.0
	 *
	 * @param string   $namespace  Resource namespace.
	 * @param string   $key        Resource key.
	 * @param string   $ac_key     Rule type slug ('', 'everyone', 'wp_role', 'wp_user', …).
	 * @param string[] $ac_options Option values (role slugs, user ID strings, etc.).
	 *
	 * @return bool True on success, false if any write fails.
	 */
	public function set_rule(
		string $namespace,
		string $key,
		string $ac_key,
		array  $ac_options
	): bool {
		$normalized = self::normalize_input( $ac_key, $ac_options );
		$ac_key     = $normalized['key'];
		$ac_options = $normalized['value'];

		$this->purge_resource( $namespace, $key );

		if ( '' === $ac_key ) {
			return true;
		}

		if ( 'everyone' === $ac_key ) {
			return (bool) $this->add_item(
				array(
					'namespace'            => $namespace,
					'key'                  => $key,
					'access_control_key'   => $ac_key,
					'access_control_value' => '',
				)
			);
		}

		$ok = true;
		foreach ( $ac_options as $option ) {
			$inserted = $this->add_item(
				array(
					'namespace'            => $namespace,
					'key'                  => $key,
					'access_control_key'   => $ac_key,
					'access_control_value' => $option,
				)
			);
			if ( false === $inserted ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Delete all rows for a (namespace, key) pair.
	 *
	 * After this call, get_rule() returns `['key' => '', 'value' => []]`.
	 *
	 * @since 3.0.0
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key.
	 *
	 * @return bool True on success (includes the case where no rows existed).
	 */
	public function clear_rule( string $namespace, string $key ): bool {
		$this->purge_resource( $namespace, $key );
		return true;
	}

	/**
	 * Delete all rows belonging to a given namespace.
	 *
	 * Call from your plugin's uninstall hook to remove only your rows without
	 * affecting rules stored by other plugins.
	 *
	 * @since 3.0.0
	 *
	 * @param string $namespace Resource namespace to purge.
	 *
	 * @return int Number of rows deleted.
	 */
	public function purge_namespace( string $namespace ): int {
		$ids = $this->query(
			array(
				'namespace' => $namespace,
				'number'    => 0,
				'fields'    => 'ids',
			)
		);

		$count = 0;
		foreach ( (array) $ids as $id ) {
			if ( $this->delete_item( (int) $id ) ) {
				$count++;
			}
		}

		return $count;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Delete every row for a given (namespace, key) pair.
	 *
	 * @since 3.0.0
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key.
	 *
	 * @return void
	 */
	private function purge_resource( string $namespace, string $key ): void {
		$ids = $this->query(
			array(
				'namespace' => $namespace,
				'key'       => $key,
				'number'    => 0,
				'fields'    => 'ids',
			)
		);

		foreach ( (array) $ids as $id ) {
			$this->delete_item( (int) $id );
		}
	}

	/**
	 * Sanitize and normalize the rule type and options before storing.
	 *
	 * @since 3.0.0
	 *
	 * @param string   $key     Rule type slug from user input.
	 * @param string[] $options Option values from user input.
	 *
	 * @return array{key: string, value: string[]}
	 */
	private static function normalize_input( string $key, array $options ): array {
		$key     = sanitize_key( $key );
		$options = array_values( array_map( 'sanitize_key', $options ) );

		if ( '' === $key || 'everyone' === $key ) {
			$options = array();
		}

		return array( 'key' => $key, 'value' => $options );
	}
}
