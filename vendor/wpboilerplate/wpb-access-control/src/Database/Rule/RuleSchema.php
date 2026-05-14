<?php
/**
 * Rule table column schema for BerlinDB.
 *
 * @package WPBoilerplate\AccessControl\Database\Rule
 * @since   3.0.0
 */

namespace WPBoilerplate\AccessControl\Database\Rule;

use BerlinDB\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the column layout for the {prefix}wpb_access_control table.
 *
 * Each entry maps to one physical column. Flags like `in`, `searchable`, and
 * `sortable` control which query parameters BerlinDB makes available on
 * Rule\Query.
 *
 * @since 3.0.0
 */
class RuleSchema extends Schema {

	/**
	 * Column definitions.
	 *
	 * @var array[]
	 */
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => 20,
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'primary'  => true,
			'sortable' => true,
		),
		array(
			'name'       => 'namespace',
			'type'       => 'varchar',
			'length'     => 100,
			'searchable' => true,
			'sortable'   => true,
			'in'         => true,
			'not_in'     => true,
		),
		array(
			'name'       => 'key',
			'type'       => 'varchar',
			'length'     => 255,
			'searchable' => true,
			'sortable'   => true,
			'in'         => true,
			'not_in'     => true,
		),
		array(
			'name'       => 'access_control_key',
			'type'       => 'varchar',
			'length'     => 100,
			'searchable' => true,
			'in'         => true,
			'not_in'     => true,
		),
		array(
			'name'       => 'access_control_value',
			'type'       => 'varchar',
			'length'     => 255,
			'searchable' => true,
			'in'         => true,
			'not_in'     => true,
		),
		array(
			'name'    => 'created_at',
			'type'    => 'datetime',
			'created' => true,
		),
		array(
			'name'     => 'updated_at',
			'type'     => 'datetime',
			'modified' => true,
		),
	);
}
