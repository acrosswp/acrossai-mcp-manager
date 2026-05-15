<?php
/**
 * PHP cross-version polyfills.
 *
 * Defines global functions introduced in PHP 8.0 / 8.1 so the plugin
 * runs correctly on PHP 7.4 through 8.5 without any code changes.
 *
 * Loaded automatically by Composer via the autoload.files mechanism —
 * never require this file manually.
 *
 * @package AcrossAI_MCP_Manager
 * @since   0.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── PHP 8.0 — string helpers ───────────────────────────────────────────────

if ( ! function_exists( 'str_contains' ) ) {
	/**
	 * Determine whether a string contains a given substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to search for.
	 *
	 * @return bool True if $needle is found inside $haystack.
	 */
	function str_contains( string $haystack, string $needle ): bool {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}
}

if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * Check if a string starts with a given prefix.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The prefix to look for.
	 *
	 * @return bool True if $haystack starts with $needle.
	 */
	function str_starts_with( string $haystack, string $needle ): bool {
		return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	/**
	 * Check if a string ends with a given suffix.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The suffix to look for.
	 *
	 * @return bool True if $haystack ends with $needle.
	 */
	function str_ends_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}
		$len = strlen( $needle );
		return $len <= strlen( $haystack ) && 0 === substr_compare( $haystack, $needle, -$len );
	}
}

// ── PHP 8.1 — array helpers ────────────────────────────────────────────────

if ( ! function_exists( 'array_is_list' ) ) {
	/**
	 * Check whether a given array is a list.
	 *
	 * An array is a list when its keys are consecutive integers starting at 0.
	 *
	 * @param array $array The array to inspect.
	 *
	 * @return bool True if the array is a list.
	 */
	function array_is_list( array $array ): bool {
		if ( array() === $array ) {
			return true;
		}
		$i = 0;
		foreach ( $array as $k => $_ ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}
}
