<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Machine translation of single strings on the Languages > Translations page.
 *
 * The page asks the server to prepare the strings (split them and protect their placeholders, like for
 * the posts), translates the segments with the engine in the browser, then asks the server to check and
 * restore the output. The result only fills the fields: nothing is saved until the user saves the form.
 *
 * @since 4.2.0
 */
class Strope_MT_Strings {
	/**
	 * Maximum number of strings per request.
	 */
	const MAX_ITEMS = 100;

	/**
	 * Prepares strings for the engine.
	 *
	 * @since 4.2.0
	 *
	 * @param string  $source Source language slug.
	 * @param array[] $items  Items with `key`, `target` (language slug) and either `text` (registered strings)
	 *                        or `sd_id` and `form` (theme and plugin strings).
	 * @return array[] Items with `key` and `status`:
	 *                 - `translate`: `route` and `parts` (strings and segment indexes) and `segments` to translate.
	 *                 - `done`: `text`, found in the translation memory.
	 *                 - `skip`: `reason`, one of `same`, `not_installed`, `forbidden`, `existing`, `empty`.
	 */
	public static function prepare( string $source, array $items ): array {
		$model    = Strope()->model;
		$language = $model->get_language( $source );
		$out      = array();

		if ( ! $language ) {
			return $out;
		}

		$from     = Strope_MT_Engine_Files::get_code( $language );
		$existing = self::find_existing_translations( $items, $from );

		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			$key    = (string) ( $item['key'] ?? '' );
			$target = $model->get_language( (string) ( $item['target'] ?? '' ) );

			if ( '' === $key || ! $target ) {
				continue;
			}

			$skip = function ( $reason ) use ( $key ) {
				return array(
					'key'    => $key,
					'status' => 'skip',
					'reason' => $reason,
				);
			};

			if ( ! Strope_MT_Queue::user_can_translate_language( $target ) ) {
				$out[] = $skip( 'forbidden' );
				continue;
			}

			$route = Strope_MT_Engine_Files::get_route( $from, Strope_MT_Engine_Files::get_code( $target ) );

			if ( '' === $route || $target->slug === $language->slug ) {
				$out[] = $skip( 'same' );
				continue;
			}

			if ( ! Strope_MT_Engine_Files::is_route_installed( $route ) ) {
				$out[] = $skip( 'not_installed' );
				continue;
			}

			if ( isset( $existing[ $key ] ) ) {
				$out[] = $skip( 'existing' );
				continue;
			}

			$text = self::get_source_text( $item );

			if ( '' === trim( $text ) ) {
				$out[] = $skip( 'empty' );
				continue;
			}

			if ( self::is_format( $text ) ) {
				$out[] = $skip( 'format' ); // A date or time format: translating it would break the dates.
				continue;
			}

			$fingerprint = Strope_MT_Engine_Files::get_route_fingerprint( $route );
			$parts       = array();
			$segments    = array();

			foreach ( Strope_MT_Segmenter::split( $text, Strope_Machine_Translation::get_split_args( $language, $target ) ) as $part ) {
				if ( is_string( $part ) ) {
					$parts[] = $part;
					continue;
				}
				$parts[]    = count( $segments );
				$segments[] = array(
					'mode' => (int) $part['mode'],
					'text' => $part['text'],
					'meta' => $part['meta'],
					'sig'  => self::sign( $fingerprint, (int) $part['mode'], (string) $part['text'], $part['meta'] ),
				);
			}

			if ( empty( $segments ) ) {
				$literal = implode( '', $parts );
				if ( $literal !== $text ) {
					$out[] = array(
						'key'    => $key,
						'status' => 'done',
						'text'   => $literal, // Only glossary terms: translated without the engine.
					);
					continue;
				}
				$out[] = $skip( 'empty' ); // Only placeholders, markup or kept words.
				continue;
			}

			// Everything in the translation memory: no need for the engine.
			$hashes = array();
			foreach ( $segments as $segment ) {
				$hashes[] = md5( $fingerprint . '|' . $segment['mode'] . '|' . $segment['text'] );
			}
			$memory = Strope_MT_Store::get_memory( $hashes );

			if ( count( $memory ) === count( array_unique( $hashes ) ) ) {
				foreach ( $segments as $i => $segment ) {
					$segments[ $i ]['output'] = $memory[ $hashes[ $i ] ];
				}
				$text = self::assemble( $parts, $segments, Strope_MT_Engine_Files::get_code( $target ), $fingerprint );
				if ( ! is_wp_error( $text ) ) {
					$out[] = array(
						'key'    => $key,
						'status' => 'done',
						'text'   => $text,
					);
					continue;
				}
			}

			$out[] = array(
				'key'      => $key,
				'status'   => 'translate',
				'route'    => $route,
				'parts'    => $parts,
				'segments' => $segments,
			);
		}

		return $out;
	}

	/**
	 * Checks and restores the engine output of prepared strings.
	 *
	 * @since 4.2.0
	 *
	 * @param string  $source Source language slug.
	 * @param array[] $items  Items with `key`, `target`, and `parts` and `segments` as returned by
	 *                        `self::prepare()`, each segment with the engine `output`.
	 * @return array[] Items with `key` and either `text` or `error`.
	 */
	public static function restore( string $source, array $items ): array {
		$model    = Strope()->model;
		$language = $model->get_language( $source );
		$out      = array();

		if ( ! $language ) {
			return $out;
		}

		$from = Strope_MT_Engine_Files::get_code( $language );

		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			$key    = (string) ( $item['key'] ?? '' );
			$target = $model->get_language( (string) ( $item['target'] ?? '' ) );

			if ( '' === $key || ! $target || ! Strope_MT_Queue::user_can_translate_language( $target ) ) {
				continue;
			}

			$code  = Strope_MT_Engine_Files::get_code( $target );
			$route = Strope_MT_Engine_Files::get_route( $from, $code );
			$text  = self::assemble( (array) ( $item['parts'] ?? array() ), (array) ( $item['segments'] ?? array() ), $code, Strope_MT_Engine_Files::get_route_fingerprint( $route ), true );

			$out[] = is_wp_error( $text )
				? array(
					'key'   => $key,
					'error' => $text->get_error_message(),
				)
				: array(
					'key'  => $key,
					'text' => $text,
				);
		}

		return $out;
	}

	/**
	 * Restores the segments of a string and puts the parts together.
	 *
	 * @since 4.2.0
	 *
	 * @param array   $parts       Strings and segment indexes.
	 * @param array[] $segments    Segments with `mode`, `text`, `meta` and `output`.
	 * @param string  $code        Target language code.
	 * @param string  $fingerprint Route fingerprint, for the translation memory.
	 * @param bool    $remember    Whether to save the outputs in the translation memory.
	 * @return string|WP_Error
	 */
	protected static function assemble( array $parts, array $segments, string $code, string $fingerprint, bool $remember = false ) {
		$restored = array();

		foreach ( $segments as $i => $segment ) {
			$mode   = (int) ( $segment['mode'] ?? 0 );
			$text   = (string) ( $segment['text'] ?? '' );
			$output = (string) ( $segment['output'] ?? '' );

			// Segments coming back from a browser must be the ones prepared by the server.
			if ( $remember && ! hash_equals( self::sign( $fingerprint, $mode, $text, $segment['meta'] ?? array() ), (string) ( $segment['sig'] ?? '' ) ) ) {
				return new WP_Error( 'strope_mt_signature', 'unknown segment' );
			}

			$result = Strope_MT_Segmenter::restore( $text, $output, $mode, json_decode( (string) wp_json_encode( $segment['meta'] ?? array() ), true ) ?: array(), $code );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$restored[ $i ] = $result;

			if ( $remember ) {
				Strope_MT_Store::set_memory( md5( $fingerprint . '|' . $mode . '|' . $text ), $output );
			}
		}

		$text = '';
		foreach ( $parts as $part ) {
			if ( is_int( $part ) ) {
				if ( ! isset( $restored[ $part ] ) ) {
					return new WP_Error( 'strope_mt_parts', 'missing segment' );
				}
				$text .= $restored[ $part ];
			} else {
				$text .= (string) $part;
			}
		}

		return $text;
	}

	/**
	 * Signs a prepared segment, so that only the segments prepared by the server are accepted back
	 * from the browser: the text, the mode and the protected parts can't be changed in between.
	 *
	 * @since 4.3.0
	 *
	 * @param string $fingerprint Route fingerprint.
	 * @param int    $mode        Segment mode.
	 * @param string $text        Segment text, as sent to the engine.
	 * @param mixed  $meta        Segment meta.
	 * @return string
	 */
	protected static function sign( string $fingerprint, int $mode, string $text, $meta ): string {
		// Same normalization as a segment read back from a JSON request.
		$meta = json_decode( (string) wp_json_encode( $meta ), true );

		return wp_hash( 'strope_mt_segment|' . $fingerprint . '|' . $mode . '|' . $text . '|' . wp_json_encode( $meta ? $meta : array() ) );
	}

	/**
	 * Tells whether a text is a PHP date or time format (e.g. `j F Y`, `g:i a`), never machine translated.
	 *
	 * @since 4.2.0
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_format( string $text ): bool {
		$chars = 'dDjlNSwzWFmMntLoXxYyaABgGhHisuveIOPpTZcrU';
		$char  = '(?:\\\\.|[' . $chars . '])'; // A format character, or an escaped character (`\d`).

		// Only format characters, separators and escapes, and never two letters in a row (that is a word).
		return (bool) preg_match( '~^\s*' . $char . '(?:[\s:,./-]*' . $char . ')*\s*$~', $text )
			&& ! preg_match( '/[A-Za-z]{2}/', (string) preg_replace( '/\\\\./', '', $text ) );
	}

	/**
	 * Returns the source text of an item.
	 *
	 * @since 4.2.0
	 *
	 * @param array $item Item.
	 * @return string
	 */
	protected static function get_source_text( array $item ): string {
		if ( empty( $item['sd_id'] ) ) {
			return (string) ( $item['text'] ?? '' );
		}

		$string = Strope_String_Discovery_Store::get_string( (int) $item['sd_id'] );
		if ( ! $string ) {
			return '';
		}

		// The first plural form is translated from the singular, the others from the plural.
		return ( empty( $item['form'] ) || '' === (string) $string->plural ) ? (string) $string->singular : (string) $string->plural;
	}

	/**
	 * Finds the theme and plugin strings which already have a translation in the language files of the target
	 * language: an empty field means "use the language files", which must not be replaced by a machine translation.
	 *
	 * @since 4.2.0
	 *
	 * @param array[] $items Items.
	 * @param string  $from  Source language code.
	 * @return true[] Keyed by item key.
	 */
	protected static function find_existing_translations( array $items, string $from ): array {
		$by_language = array();

		foreach ( $items as $item ) {
			if ( ! empty( $item['sd_id'] ) && ! empty( $item['target'] ) && ! empty( $item['key'] ) ) {
				$by_language[ (string) $item['target'] ][] = $item;
			}
		}

		if ( empty( $by_language ) || ! class_exists( 'Strope_String_Discovery_Store' ) ) {
			return array();
		}

		$found = array();

		foreach ( $by_language as $slug => $list ) {
			$language = Strope()->model->get_language( $slug );
			if ( ! $language || Strope_MT_Engine_Files::get_code( $language ) === $from ) {
				continue;
			}

			$switched = switch_to_locale( $language->locale );

			foreach ( $list as $item ) {
				$string = Strope_String_Discovery_Store::get_string( (int) $item['sd_id'] );
				if ( ! $string ) {
					continue;
				}

				$singular = (string) $string->singular;
				$domain   = (string) $string->domain;
				$context  = (string) $string->context;

				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralContext
				$translation = '' === $context ? translate( $singular, $domain ) : translate_with_gettext_context( $singular, $context, $domain );
				// phpcs:enable

				if ( $translation !== $singular ) {
					$found[ (string) $item['key'] ] = true;
				}
			}

			if ( $switched ) {
				restore_previous_locale();
			}
		}

		return $found;
	}
}
