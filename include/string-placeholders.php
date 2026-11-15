<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compares the printf placeholders (`%s`, `%d`, `%1$s`...) of a translation with those of its source string,
 * to prevent translations which would break `sprintf()` (a missing argument is a fatal error since PHP 8).
 *
 * A source string is considered as a format string only if it contains a placeholder written without
 * the space flag (for example `%s`, `%1$s`, `%.2f` or `%%`), so that plain text such as "100% sure"
 * can be translated freely. Plain text translations must not introduce such placeholders.
 * Source strings with a percent sign followed by a space (as in "50% off %s") are not checked
 * as `sprintf()` would read `% o` as a placeholder.
 *
 * @since 4.1.0
 */
class Strope_String_Placeholders {
	/**
	 * Regular expression matching an unambiguous placeholder, used to detect format strings.
	 *
	 * @var string
	 */
	const SIMPLE_REGEX = '/%(?:%|(?:\d+\$)?(?:[-+0]|\'.)*\d*(?:\.\d+)?[bcdeEfFgGhHosuxX])/s';

	/**
	 * Tells whether a translation (or one plural form of it) has the same placeholders as its source string.
	 *
	 * For a string without plural, the translation must use exactly the same arguments with the same types.
	 * For a plural string, a form may omit some arguments (for example "One comment" for "%d comment")
	 * but must not use arguments absent from the source strings.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translation.
	 * @param string $singular    Source string.
	 * @param string $plural      Optional plural source string.
	 * @return bool
	 */
	public static function match( string $translation, string $singular, string $plural = '' ): bool {
		if ( false === strpos( $translation, '%' ) && false === strpos( $singular . $plural, '%' ) ) {
			return true; // Fast path, no placeholders at all.
		}

		$sources = '' === $plural ? array( $singular ) : array( $singular, $plural );

		if ( ! self::is_format( implode( "\n", $sources ) ) ) {
			// Plain text source: the translation must not introduce placeholders (a literal `%%` is harmless).
			return ! preg_match_all( self::SIMPLE_REGEX, $translation, $matches ) || array( '%%' ) === array_unique( $matches[0] );
		}

		if ( preg_match( '/%(?:\d+\$)?(?:[-+0]|\'.)* /s', str_replace( '%%', '', implode( "\n", $sources ) ) ) ) {
			return true; // A percent sign followed by a space, as in "50% off": the source string is likely not used with `sprintf()`.
		}

		$expected = array();
		foreach ( $sources as $source ) {
			$args = self::get_arguments( $source );
			if ( false === $args ) {
				return true; // The source string is not a valid format string: it can't be used with `sprintf()`.
			}
			foreach ( $args as $num => $types ) {
				$expected[ $num ] = array_merge( $expected[ $num ] ?? array(), $types );
			}
		}

		$args = self::get_arguments( $translation );
		if ( false === $args ) {
			return false;
		}

		if ( '' === $plural ) {
			return self::normalize( $args ) === self::normalize( $expected );
		}

		$expected = self::normalize( $expected );
		foreach ( self::normalize( $args ) as $num => $types ) {
			if ( ! isset( $expected[ $num ] ) || array_diff( $types, $expected[ $num ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validates the forms of a translation and returns an error when the placeholders don't match the source string.
	 * Empty forms are ignored.
	 *
	 * @since 4.1.0
	 *
	 * @param string|string[] $translation Translation, or array of plural forms.
	 * @param string          $singular    Source string.
	 * @param string          $plural      Optional plural source string.
	 * @return true|WP_Error
	 */
	public static function validate( $translation, string $singular, string $plural = '' ) {
		foreach ( (array) $translation as $form ) {
			if ( ! is_scalar( $form ) || '' === (string) $form || self::match( (string) $form, $singular, $plural ) ) {
				continue;
			}

			$placeholders = self::get_placeholders( '' === $plural ? $singular : $singular . "\n" . $plural );

			return new WP_Error(
				'strope_invalid_placeholders',
				sprintf(
					/* translators: 1: source string, 2: list of placeholders such as %s or %1$d */
					__( 'The translation of "%1$s" was not saved: its placeholders must match those of the original text (%2$s).', 'strope-translate' ),
					wp_html_excerpt( $singular, 60, '...' ),
					empty( $placeholders ) ? __( 'none', 'strope-translate' ) : implode( ' ', $placeholders )
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Tells whether a source string is a format string.
	 *
	 * @since 4.1.0
	 *
	 * @param string $text Source string.
	 * @return bool
	 */
	public static function is_format( string $text ): bool {
		return false !== strpos( $text, '%' ) && (bool) preg_match( self::SIMPLE_REGEX, $text );
	}

	/**
	 * Returns the distinct placeholders of a format string, as written, `%%` excluded.
	 *
	 * @since 4.1.0
	 *
	 * @param string $text Source string.
	 * @return string[]
	 */
	public static function get_placeholders( string $text ): array {
		if ( ! self::is_format( $text ) || ! preg_match_all( self::SIMPLE_REGEX, $text, $matches ) ) {
			return array();
		}

		return array_values( array_unique( array_diff( $matches[0], array( '%%' ) ) ) );
	}

	/**
	 * Parses a string as `sprintf()` does and returns the arguments it uses with their conversion types.
	 *
	 * @since 4.1.0
	 *
	 * @param string $text Format string.
	 * @return array|false Conversion types keyed by argument number (starting at 1), false if `sprintf()` would throw an error.
	 */
	public static function get_arguments( string $text ) {
		$args   = array();
		$next   = 1;
		$offset = 0;

		while ( false !== ( $pos = strpos( $text, '%', $offset ) ) ) {
			// Argument number, flags (including a custom padding character), width, precision, 'l' modifier and conversion.
			if ( ! preg_match( '/\G(?:(\d+)\$)?(?:[-+ 0]|\'[\s\S])*(\*)?\d*(?:\.(\*)?\d*)?l?([\s\S]?)/', $text, $match, 0, $pos + 1 ) ) {
				return false;
			}

			$offset = $pos + 1 + strlen( $match[0] );
			$type   = $match[4];

			if ( '%' === $match[0] ) {
				continue; // A literal percent sign.
			}

			if ( '' === $type || false === strpos( 'bcdeEfFgGhHosuxX', $type ) || ! empty( $match[2] ) || ! empty( $match[3] ) ) {
				return false; // Missing or unknown conversion, or variable width or precision (not supported).
			}

			if ( '' !== $match[1] ) {
				$num = (int) $match[1];
				if ( $num <= 0 ) {
					return false;
				}
			} else {
				$num = $next++;
			}

			$args[ $num ][] = strtolower( $type );
		}

		return $args;
	}

	/**
	 * Normalizes the arguments returned by `self::get_arguments()` for comparisons.
	 *
	 * @since 4.1.0
	 *
	 * @param array $args Conversion types keyed by argument number.
	 * @return array
	 */
	protected static function normalize( array $args ): array {
		foreach ( $args as $num => $types ) {
			$types = array_values( array_unique( $types ) );
			sort( $types );
			$args[ $num ] = $types;
		}

		ksort( $args );
		return $args;
	}
}
