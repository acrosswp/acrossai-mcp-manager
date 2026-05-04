<?php
/**
 * PHPUnit bootstrap for the wpb-access-control test suite.
 *
 * Loads Composer's autoloader (which pulls in Brain Monkey + the package
 * source) and defines the ABSPATH constant so the package source files —
 * which all guard with `if ( ! defined( 'ABSPATH' ) ) exit;` — can be
 * loaded safely outside of WordPress.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
