<?php
/**
 * Rule table row object for BerlinDB.
 *
 * @package WPBoilerplate\AccessControl\Database\Rule
 * @since   3.0.0
 */

namespace WPBoilerplate\AccessControl\Database\Rule;

use BerlinDB\Database\Row;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a single row in the {prefix}wpb_access_control table.
 *
 * BerlinDB populates public properties from column data. Typed here for
 * static-analysis clarity.
 *
 * @since 3.0.0
 */
class RuleRow extends Row {

	/** @var int */
	public $id = 0;

	/** @var string */
	public $namespace = '';

	/** @var string */
	public $key = '';

	/** @var string */
	public $access_control_key = '';

	/** @var string */
	public $access_control_value = '';

	/** @var string */
	public $created_at = '';

	/** @var string */
	public $updated_at = '';
}
