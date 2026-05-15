<?php
/**
 * PHP compatibility utility class.
 *
 * Provides typed static helpers that work correctly across the plugin's
 * supported PHP range (7.4 – 8.5). Pair with polyfills.php (auto-loaded
 * via Composer) which patches missing global functions on PHP < 8.0/8.1.
 *
 * Usage:
 *   Compat::str_contains( $haystack, $needle );
 *   Compat::supports( '8.0' );   // true when running PHP ≥ 8.0
 *
 * @package AcrossAI_MCP_Manager\Core
 * @since   0.0.4
 */

namespace ACROSSAI_MCP_MANAGER\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHP cross-version compatibility helpers.
 *
 * All methods are static and have no external dependencies. They delegate
 * to the polyfilled global functions so callers do not need to guard on
 * PHP version themselves.
 *
 * Supported PHP range: 7.4 – 8.5.
 */
class Compat {

	/** Minimum supported PHP version (inclusive). */
	const PHP_MIN = '7.4';

	/** Maximum tested PHP version (inclusive). */
	const PHP_MAX = '8.5';

	// ── String helpers ──────────────────────────────────────────────────────

	/**
	 * Return true if $haystack contains $needle.
	 *
	 * Delegates to the native str_contains() on PHP ≥ 8.0; uses the
	 * polyfilled global function on PHP 7.4.
	 *
	 * @since 0.0.4
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to look for.
	 *
	 * @return bool
	 */
	public static function str_contains( string $haystack, string $needle ): bool {
		return str_contains( $haystack, $needle );
	}

	/**
	 * Return true if $haystack starts with $needle.
	 *
	 * @since 0.0.4
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The prefix to look for.
	 *
	 * @return bool
	 */
	public static function str_starts_with( string $haystack, string $needle ): bool {
		return str_starts_with( $haystack, $needle );
	}

	/**
	 * Return true if $haystack ends with $needle.
	 *
	 * @since 0.0.4
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The suffix to look for.
	 *
	 * @return bool
	 */
	public static function str_ends_with( string $haystack, string $needle ): bool {
		return str_ends_with( $haystack, $needle );
	}

	// ── Array helpers ───────────────────────────────────────────────────────

	/**
	 * Return true if $array is a list (consecutive integer keys from 0).
	 *
	 * Delegates to the native array_is_list() on PHP ≥ 8.1; uses the
	 * polyfilled global function on PHP 7.4 / 8.0.
	 *
	 * @since 0.0.4
	 *
	 * @param array $array The array to inspect.
	 *
	 * @return bool
	 */
	public static function array_is_list( array $array ): bool {
		return array_is_list( $array );
	}

	/**
	 * Return the first key of an array without affecting the internal pointer.
	 *
	 * Wraps the native array_key_first() (PHP ≥ 7.3) with an explicit
	 * fallback for clarity and IDE type inference.
	 *
	 * @since 0.0.4
	 *
	 * @param array $array The input array.
	 *
	 * @return int|string|null The first key, or null if the array is empty.
	 */
	public static function array_key_first( array $array ) {
		return array_key_first( $array );
	}

	/**
	 * Return the last key of an array without affecting the internal pointer.
	 *
	 * @since 0.0.4
	 *
	 * @param array $array The input array.
	 *
	 * @return int|string|null The last key, or null if the array is empty.
	 */
	public static function array_key_last( array $array ) {
		return array_key_last( $array );
	}

	// ── Version checks ──────────────────────────────────────────────────────

	/**
	 * Return true when the current PHP runtime is at or above $version.
	 *
	 * @since 0.0.4
	 *
	 * @param string $version Dotted version string, e.g. '8.0', '8.1.0'.
	 *
	 * @return bool
	 */
	public static function supports( string $version ): bool {
		return version_compare( PHP_VERSION, $version, '>=' );
	}

	/**
	 * Return true when the current PHP version falls within [min, max].
	 *
	 * Useful for conditional logic that varies across the supported range.
	 *
	 * @since 0.0.4
	 *
	 * @param string $min Minimum version (inclusive), e.g. '7.4'.
	 * @param string $max Maximum version (inclusive), e.g. '8.5'.
	 *
	 * @return bool
	 */
	public static function in_range( string $min, string $max ): bool {
		return version_compare( PHP_VERSION, $min, '>=' )
			&& version_compare( PHP_VERSION, $max, '<=' );
	}
}
