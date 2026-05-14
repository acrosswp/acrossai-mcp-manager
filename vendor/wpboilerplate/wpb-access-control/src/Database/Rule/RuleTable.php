<?php
/**
 * Rule database table definition for BerlinDB.
 *
 * Defines the {prefix}wpb_access_control schema. RuleQuery instantiates this
 * automatically on first use — consuming plugins do not need to manage it.
 *
 * @package WPBoilerplate\AccessControl\Database\Rule
 * @since   3.0.0
 */

namespace WPBoilerplate\AccessControl\Database\Rule;

use BerlinDB\Database\Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the {prefix}wpb_access_control table schema and BerlinDB upgrades.
 *
 * One flat row per option value — no JSON storage. See AGENTS.md for the
 * full schema and rule storage convention.
 *
 * @since 3.0.0
 */
class RuleTable extends Table {

	// -------------------------------------------------------------------------
	// Length constants — referenced by Rule\Query and AccessControlUI validation.
	// -------------------------------------------------------------------------

	const NAMESPACE_LENGTH  = 100;
	const KEY_LENGTH        = 255;
	const AC_KEY_LENGTH     = 100;
	const AC_VALUE_LENGTH   = 255;

	// -------------------------------------------------------------------------
	// BerlinDB Table properties
	// -------------------------------------------------------------------------

	/** @var string Table name without the global $wpdb->prefix. */
	protected $name = 'wpb_access_control';

	/**
	 * Schema version as a monotonically-increasing integer.
	 * BerlinDB compares this to the stored option to decide which upgrades to run.
	 *
	 * @var int
	 */
	protected $version = 202605120001;

	/**
	 * WordPress option key used to store the installed schema version.
	 *
	 * @var string
	 */
	protected $db_version_key = 'wpb_access_control_db_version';

	/**
	 * Version-to-method map for BerlinDB's upgrade runner.
	 * Each method must return true on success.
	 *
	 * @var array<int,string>
	 */
	protected $upgrades = array(
		202605120001 => 'upgrade_202605120001',
	);

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Define the table columns (called by BerlinDB during install/upgrade).
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function set_schema(): void {
		$ns  = self::NAMESPACE_LENGTH;
		$key = self::KEY_LENGTH;
		$ack = self::AC_KEY_LENGTH;
		$acv = self::AC_VALUE_LENGTH;

		$this->schema = "
			`id` bigint(20) unsigned NOT NULL auto_increment,
			`namespace` varchar({$ns}) NOT NULL DEFAULT '',
			`key` varchar({$key}) NOT NULL DEFAULT '',
			`access_control_key` varchar({$ack}) NOT NULL DEFAULT '',
			`access_control_value` varchar({$acv}) NOT NULL DEFAULT '',
			`created_at` datetime DEFAULT NULL,
			`updated_at` datetime DEFAULT NULL,
			PRIMARY KEY  (`id`),
			UNIQUE KEY `ns_key_value` (`namespace`,`key`(191),`access_control_value`),
			KEY `ns_key` (`namespace`,`key`(191))
		";
	}

	// -------------------------------------------------------------------------
	// Upgrade methods
	// -------------------------------------------------------------------------

	/**
	 * First-time migration to the flat-row schema.
	 *
	 * The old TEXT + JSON column cannot transition to normalized VARCHARs via
	 * dbDelta alone, so the table is dropped and recreated. Existing rows are
	 * intentionally discarded — resources default to "no restriction" until
	 * an admin reconfigures them.
	 *
	 * @since 3.0.0
	 *
	 * @return bool True on success.
	 */
	protected function upgrade_202605120001(): bool {
		$this->drop();
		return $this->create();
	}
}
