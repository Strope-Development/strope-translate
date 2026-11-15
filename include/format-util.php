<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class to match values against a given format.
 *
 * @since 3.6
 */
class Strope_Format_Util {
	/**
	 * Cache for parsed formats.
	 * Useful when using `filter_list()` for example.
	 *
	 * @var string[][] Formats as array keys, literal segments separated by wildcards as array values.
	 */
	private $segments = array();

	/**
	 * Filters the given list to return only the values whose the key or value matches the given format.
	 *
	 * @since 3.6
	 *
	 * @param array|Traversable $list   An list with keys or values to match against `$format`.
	 * @param string            $format A format, where `*` means "any characters" (`.*`), unless escaped.
	 * @param string            $mode   Optional. Tell if we should filter the keys or values from `$list`.
	 *                                  Possible values are `'use_keys'` and `'use_values'`. Default is `'use_keys'`.
	 * @return array
	 *
	 * @template TArrayValue
	 * @phpstan-param ($mode is 'use_keys' ? array<string, TArrayValue>|Traversable<string, TArrayValue> : array<string>|Traversable<string>) $list
	 * @phpstan-param 'use_keys'|'values' $mode
	 * @phpstan-return ($mode is 'use_keys' ? array<string, TArrayValue> : array<string>)
	 */
	public function filter_list( $list, string $format, string $mode = 'use_keys' ): array {
		$filter = function ( $key ) use ( $format ) {
			return $this->matches( (string) $key, $format );
		};

		if ( ! is_array( $list ) ) {
			$list = iterator_to_array( $list );
		}

		if ( 'use_values' === $mode ) {
			return array_filter( $list, $filter );
		}

		return array_filter( $list, $filter, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Tells if the given string matches the given format.
	 *
	 * @since 3.6
	 *
	 * @param string $key    A string to test.
	 * @param string $format A format, where `*` means "any characters" (`.*`), unless escaped.
	 * @return bool
	 */
	public function matches( string $key, string $format ): bool {
		if ( strpos( $format, '*' ) === false ) {
			return $key === $format;
		}

		if ( '*' === $format ) {
			return true;
		}

		if ( ! isset( $this->segments[ $format ] ) ) {
			// Split on `*` characters not preceded by `\`, then unescape `\*`.
			$this->segments[ $format ] = str_replace( '\*', '*', (array) preg_split( '/(?<!\\\\)\*/', $format ) );
		}

		$segments = $this->segments[ $format ];

		if ( 1 === count( $segments ) ) {
			// Only escaped wildcards.
			return $key === $segments[0];
		}

		/*
		 * Linear time wildcard matching, without regular expressions to avoid catastrophic backtracking (ReDoS).
		 * The key must start with the first segment, end with the last one, and contain the other ones in order.
		 */
		$first = (string) array_shift( $segments );
		$last  = (string) array_pop( $segments );
		$start = strlen( $first );
		$end   = strlen( $key ) - strlen( $last );

		if ( $end < $start || 0 !== strncmp( $key, $first, $start ) || substr( $key, $end ) !== $last ) {
			return false;
		}

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$found = strpos( $key, $segment, $start );

			if ( false === $found || $found + strlen( $segment ) > $end ) {
				return false;
			}

			$start = $found + strlen( $segment );
		}

		return true;
	}
}
