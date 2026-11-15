<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Public API of the theme and plugin strings discovery.
 *
 * Allows to translate (or override, including in the default language) the gettext strings
 * of the active themes and plugins without editing .po files.
 *
 * @since 4.1.0
 */
class Strope_String_Discovery {
	/**
	 * Strings collected during the current request, keyed by hash.
	 *
	 * @var array
	 */
	protected static $collected = array();

	/**
	 * Whether the collection is active.
	 *
	 * @var bool
	 */
	protected static $collecting = false;

	/**
	 * Starts recording every gettext string going through a text domain known to the scanner
	 * during the current request. Should be called early in the request.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public static function start_collecting(): void {
		if ( self::$collecting ) {
			return;
		}

		self::$collecting = true;

		foreach ( Strope_String_Discovery_Store::get_domains() as $domain ) {
			add_filter( "gettext_{$domain}", array( self::class, 'collect_gettext' ), PHP_INT_MAX, 3 );
			add_filter( "gettext_with_context_{$domain}", array( self::class, 'collect_gettext_with_context' ), PHP_INT_MAX, 4 );
			add_filter( "ngettext_{$domain}", array( self::class, 'collect_ngettext' ), PHP_INT_MAX, 5 );
			add_filter( "ngettext_with_context_{$domain}", array( self::class, 'collect_ngettext_with_context' ), PHP_INT_MAX, 6 );
		}
	}

	/**
	 * Returns the strings collected since `self::start_collecting()` was called.
	 * Strings of the 'default' text domain (WordPress core) are returned only if they were found by the scanner.
	 *
	 * @since 4.1.0
	 *
	 * @return array[] List of arrays with keys `domain`, `context`, `singular`, `plural`, `translation` and `string_id` (int, 0 if unknown).
	 */
	public static function get_collected(): array {
		if ( empty( self::$collected ) ) {
			return array();
		}

		$ids = Strope_String_Discovery_Store::get_ids_by_hash( array_keys( self::$collected ) );
		$out = array();

		foreach ( self::$collected as $hash => $string ) {
			$string['string_id'] = isset( $ids[ $hash ] ) ? $ids[ $hash ] : 0;

			if ( 'default' === $string['domain'] && empty( $string['string_id'] ) ) {
				continue;
			}

			$out[] = $string;
		}

		return $out;
	}

	/**
	 * Saves the translation (override) of a string in a language.
	 * The string is created if it was not found by the scanner.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $domain      Text domain.
	 * @param string          $context     Context, empty string if none.
	 * @param string          $singular    Source string (singular form).
	 * @param string          $language    Language slug (or locale).
	 * @param string|string[] $translation Translation, or array of translations for plural forms. Empty to delete the override.
	 * @return true|WP_Error
	 */
	public static function save_override( string $domain, string $context, string $singular, string $language, $translation ) {
		if ( ! current_user_can( 'manage_translations' ) ) {
			return new WP_Error( 'strope_forbidden', __( 'Sorry, you are not allowed to translate strings.', 'strope-translate' ), array( 'status' => 403 ) );
		}

		$lang = self::get_language( $language );
		if ( ! $lang ) {
			return new WP_Error( 'strope_invalid_language', __( 'Invalid language.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $lang->slug, wp_list_pluck( self::get_user_languages(), 'slug' ), true ) ) {
			return new WP_Error( 'strope_forbidden_language', __( 'Sorry, you are not allowed to translate strings in this language.', 'strope-translate' ), array( 'status' => 403 ) );
		}

		if ( '' === $domain || '' === $singular ) {
			return new WP_Error( 'strope_invalid_string', __( 'Invalid string.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		$string = Strope_String_Discovery_Store::find_string( $domain, $context, $singular );
		if ( empty( $string ) ) {
			return new WP_Error( 'strope_db_error', __( 'The string could not be saved.', 'strope-translate' ), array( 'status' => 500 ) );
		}

		return self::save_translation( (int) $string->id, $lang, $translation );
	}

	/**
	 * Saves the translation of a string, sanitizes and secures it, and rebuilds the cache of the language.
	 * Checks the permission to override WordPress core strings but not the `manage_translations` capability
	 * nor the languages allowed for the user.
	 *
	 * @since 4.1.0
	 *
	 * @param int             $string_id   String id.
	 * @param Strope_Language $language    Language.
	 * @param string|string[] $translation Translation, or array of translations for plural forms.
	 * @param bool            $rebuild     Whether to rebuild the cache.
	 * @return true|WP_Error
	 */
	public static function save_translation( int $string_id, $language, $translation, bool $rebuild = true ) {
		$string = Strope_String_Discovery_Store::get_string( $string_id );
		if ( empty( $string ) ) {
			return new WP_Error( 'strope_invalid_string', __( 'Invalid string.', 'strope-translate' ), array( 'status' => 404 ) );
		}

		$forms = self::prepare_translation( $translation, $string );
		if ( is_wp_error( $forms ) ) {
			return $forms;
		}

		if ( ! Strope_String_Discovery_Store::set_translation( $string_id, $language->slug, $forms ) ) {
			return new WP_Error( 'strope_db_error', __( 'The string could not be saved.', 'strope-translate' ), array( 'status' => 500 ) );
		}

		if ( $rebuild ) {
			Strope_String_Discovery_Store::rebuild_cache( $language );
		}

		return true;
	}

	/**
	 * Prepares a translation before it is saved: sanitizes it, checks the permission to override
	 * WordPress core strings, checks the printf placeholders and secures it for users without
	 * the `unfiltered_html` capability.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed  $translation Translation (string or array of plural forms).
	 * @param object $string      String row, see `Strope_String_Discovery_Store::get_string()`.
	 * @return string[]|WP_Error
	 */
	public static function prepare_translation( $translation, $string ) {
		$forms = self::sanitize_translation( $translation );

		/*
		 * WordPress core strings are displayed everywhere, including the admin and login screens,
		 * so overriding them is reserved to the users who manage the languages.
		 */
		if ( 'default' === $string->domain && ! current_user_can( 'manage_languages' ) ) {
			return new WP_Error( 'strope_forbidden_domain', __( 'Sorry, you are not allowed to change the WordPress core strings.', 'strope-translate' ), array( 'status' => 403 ) );
		}

		// A translation with other placeholders than the source string would break `sprintf()`.
		$valid = Strope_String_Placeholders::validate( $forms, (string) $string->singular, (string) $string->plural );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return self::secure_translation( $forms, $string->singular . "\n" . $string->plural );
	}

	/**
	 * Sanitizes a translation: casts it to an array of plural forms and removes the trailing empty forms.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $translation Translation (string or array of plural forms).
	 * @return string[]
	 */
	public static function sanitize_translation( $translation ): array {
		$forms = is_array( $translation ) ? array_values( $translation ) : array( $translation );
		$forms = array_slice( $forms, 0, 6 ); // Max number of plural forms in gettext.

		foreach ( $forms as $i => $form ) {
			$form        = is_scalar( $form ) ? wp_check_invalid_utf8( (string) $form ) : '';
			$forms[ $i ] = '' === trim( $form ) ? '' : $form;
		}

		// Remove trailing empty forms.
		while ( $forms && '' === end( $forms ) ) {
			array_pop( $forms );
		}

		return $forms;
	}

	/**
	 * Secures a translation for users without the `unfiltered_html` capability.
	 *
	 * Themes and plugins may print translations unescaped, in HTML attributes or inline scripts,
	 * so a translation must not introduce characters which are significant in these contexts
	 * and which are not in the source string:
	 * - HTML tags are allowed only if the same tags (and attributes) are in the source string,
	 *   and only if they are allowed in post content.
	 * - Otherwise `<` and `>` are encoded, as well as `"`, `'` and backticks absent from the source string.
	 *   The HTML entities are displayed as the original characters by browsers and are not double encoded by `esc_html()` and `esc_attr()`.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $forms  Translations.
	 * @param string   $source Source string (singular and plural).
	 * @return string[]
	 */
	public static function secure_translation( array $forms, string $source ): array {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $forms;
		}

		$allowed_html = self::get_source_tags( $source );

		foreach ( $forms as $i => $form ) {
			if ( '' === $form ) {
				continue;
			}

			if ( $allowed_html ) {
				$form  = wp_kses( $form, $allowed_html );
				$parts = preg_split( '/(<[^<>]*>)/', $form, -1, PREG_SPLIT_DELIM_CAPTURE );
			} else {
				$parts = array( $form );
			}

			foreach ( (array) $parts as $k => $part ) {
				if ( $allowed_html && 1 === $k % 2 ) {
					continue; // A tag sanitized by kses.
				}
				$parts[ $k ] = self::encode_special_chars( (string) $part, $source );
			}

			$forms[ $i ] = implode( '', (array) $parts );
		}

		return $forms;
	}

	/**
	 * Returns the tags and attributes of a source string which are allowed in post content, in the `wp_kses()` format.
	 *
	 * @since 4.1.0
	 *
	 * @param string $source Source string.
	 * @return array
	 */
	protected static function get_source_tags( string $source ): array {
		$allowed = array();

		if ( false === strpos( $source, '<' ) || ! preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)([^<>]*)>/', $source, $matches, PREG_SET_ORDER ) ) {
			return $allowed;
		}

		$post_html = wp_kses_allowed_html( 'post' );

		foreach ( $matches as $match ) {
			$tag = strtolower( $match[1] );
			if ( ! isset( $post_html[ $tag ] ) ) {
				continue;
			}

			if ( ! isset( $allowed[ $tag ] ) ) {
				$allowed[ $tag ] = array();
			}

			foreach ( array_keys( wp_kses_hair( $match[2], wp_allowed_protocols() ) ) as $attr ) {
				$attr = strtolower( $attr );
				if ( is_array( $post_html[ $tag ] ) && isset( $post_html[ $tag ][ $attr ] ) ) {
					$allowed[ $tag ][ $attr ] = true;
				}
			}
		}

		return $allowed;
	}

	/**
	 * Encodes `<`, `>` and the quotes and backticks which are not in the source string.
	 *
	 * @since 4.1.0
	 *
	 * @param string $text   Text (outside tags).
	 * @param string $source Source string.
	 * @return string
	 */
	protected static function encode_special_chars( string $text, string $source ): string {
		$map = array(
			'<' => '&lt;',
			'>' => '&gt;',
		);

		foreach ( array( '"' => '&quot;', "'" => '&#039;', '`' => '&#096;' ) as $char => $entity ) {
			if ( false === strpos( $source, $char ) ) {
				$map[ $char ] = $entity;
			}
		}

		return strtr( $text, $map );
	}

	/**
	 * Returns a language from its slug or locale.
	 *
	 * @since 4.1.0
	 *
	 * @param string $language Language slug or locale.
	 * @return Strope_Language|null
	 */
	public static function get_language( string $language ) {
		$strope = isset( $GLOBALS['strope_translate'] ) ? $GLOBALS['strope_translate'] : null;
		if ( ! $strope instanceof Strope_Base || '' === $language ) {
			return null;
		}

		$lang = $strope->model->get_language( $language );
		return $lang instanceof Strope_Language ? $lang : null;
	}

	/**
	 * Returns the languages in which the current user can translate the strings.
	 *
	 * @since 4.1.0
	 *
	 * @return Strope_Language[]
	 */
	public static function get_user_languages(): array {
		$strope = isset( $GLOBALS['strope_translate'] ) ? $GLOBALS['strope_translate'] : null;
		if ( ! $strope instanceof Strope_Base || ! current_user_can( 'manage_translations' ) ) {
			return array();
		}

		/**
		 * Filters the languages in which a user can translate the theme and plugin strings.
		 *
		 * @since 4.1.0
		 *
		 * @param Strope_Language[] $languages The languages, all by default for users who can `manage_translations`.
		 * @param int               $user_id   The user id.
		 */
		$languages = apply_filters( 'strope_string_discovery_user_languages', $strope->model->get_languages_list(), get_current_user_id() );

		return array_values( array_filter( (array) $languages, function ( $language ) {
			return $language instanceof Strope_Language;
		} ) );
	}

	/**
	 * Returns the plural forms expression of a language, for example `n > 1` for French.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language Language.
	 * @return string
	 */
	public static function get_plural_forms( $language ): string {
		$header = self::get_plural_forms_header( $language );
		$expr   = 'n != 1';

		if ( preg_match( '/plural\s*=\s*([^;]+)/', $header, $matches ) ) {
			$expr = trim( $matches[1] );
		}

		/**
		 * Filters the plural forms expression of a language.
		 *
		 * @since 4.1.0
		 *
		 * @param string          $expr     Plural forms expression, for example `n != 1`.
		 * @param Strope_Language $language The language.
		 */
		return (string) apply_filters( 'strope_string_discovery_plural_forms', $expr, $language );
	}

	/**
	 * Returns the number of plural forms of a language.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language Language.
	 * @return int
	 */
	public static function get_nplurals( $language ): int {
		$header = self::get_plural_forms_header( $language );

		if ( preg_match( '/nplurals\s*=\s*(\d+)/', $header, $matches ) ) {
			return max( 1, min( 6, (int) $matches[1] ) );
		}

		$expr = self::get_plural_forms( $language );
		if ( 'n != 1' === $expr || 'n > 1' === $expr || '0' === $expr ) {
			return '0' === $expr ? 1 : 2;
		}

		// Count the distinct numbers returned by the expression.
		if ( ! class_exists( 'Plural_Forms' ) ) {
			require_once ABSPATH . WPINC . '/pomo/plural-forms.php';
		}

		try {
			$parser = new Plural_Forms( $expr );
			$max    = 0;
			for ( $n = 0; $n <= 200; $n++ ) {
				$max = max( $max, (int) $parser->get( $n ) );
			}
			return min( 6, $max + 1 );
		} catch ( Exception $e ) {
			return 2;
		}
	}

	/**
	 * Returns the Plural-Forms header of a language: from the installed WordPress translation if any,
	 * otherwise from a built-in table.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language Language.
	 * @return string
	 */
	protected static function get_plural_forms_header( $language ): string {
		static $cache = array();

		$locale = (string) $language->locale;
		if ( isset( $cache[ $locale ] ) ) {
			return $cache[ $locale ];
		}

		$header = '';

		// WordPress 6.5+ PHP translation files.
		$file = WP_LANG_DIR . '/' . $locale . '.l10n.php';
		if ( is_readable( $file ) ) {
			$data = include $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			if ( is_array( $data ) && ! empty( $data['plural-forms'] ) && is_string( $data['plural-forms'] ) ) {
				$header = $data['plural-forms'];
			}
		}

		if ( '' === $header ) {
			$file = WP_LANG_DIR . '/' . $locale . '.mo';
			if ( is_readable( $file ) ) {
				$mo = new MO();
				if ( $mo->import_from_file( $file ) ) {
					$header = (string) $mo->get_header( 'Plural-Forms' );
				}
			}
		}

		if ( '' === $header ) {
			$header = self::get_builtin_plural_forms( $locale );
		}

		$cache[ $locale ] = $header;
		return $header;
	}

	/**
	 * Built-in plural forms for common languages.
	 *
	 * @since 4.1.0
	 *
	 * @param string $locale Locale.
	 * @return string Plural-Forms header.
	 */
	protected static function get_builtin_plural_forms( string $locale ): string {
		$slavic = 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);';
		$rules  = array(
			'nplurals=1; plural=0;'   => array( 'ja', 'ko', 'zh', 'vi', 'th', 'id', 'ms', 'lo', 'km', 'my' ),
			'nplurals=2; plural=n > 1;' => array( 'fr', 'pt_BR', 'tr', 'fa', 'oc', 'br', 'tg', 'uz' ),
			$slavic                   => array( 'ru', 'uk', 'be', 'sr', 'hr', 'bs' ),
			'nplurals=3; plural=(n == 1) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);' => array( 'pl' ),
			'nplurals=3; plural=(n == 1) ? 0 : ((n >= 2 && n <= 4) ? 1 : 2);' => array( 'cs', 'sk' ),
			'nplurals=3; plural=(n % 10 == 1 && (n % 100 < 11 || n % 100 > 19)) ? 0 : ((n % 10 >= 2 && n % 10 <= 9 && (n % 100 < 11 || n % 100 > 19)) ? 1 : 2);' => array( 'lt' ),
			'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : (n != 0 ? 1 : 2);' => array( 'lv' ),
			'nplurals=3; plural=(n == 1) ? 0 : ((n == 0 || (n % 100 > 0 && n % 100 < 20)) ? 1 : 2);' => array( 'ro' ),
			'nplurals=4; plural=(n % 100 == 1) ? 0 : ((n % 100 == 2) ? 1 : ((n % 100 == 3 || n % 100 == 4) ? 2 : 3));' => array( 'sl' ),
			'nplurals=5; plural=(n == 1) ? 0 : ((n == 2) ? 1 : ((n > 2 && n < 7) ? 2 : ((n > 6 && n < 11) ? 3 : 4)));' => array( 'ga' ),
			'nplurals=6; plural=(n == 0) ? 0 : ((n == 1) ? 1 : ((n == 2) ? 2 : ((n % 100 >= 3 && n % 100 <= 10) ? 3 : ((n % 100 >= 11 && n % 100 <= 99) ? 4 : 5))));' => array( 'ar' ),
		);

		$lang = strtok( $locale, '_' );
		foreach ( $rules as $header => $locales ) {
			if ( in_array( $locale, $locales, true ) ) {
				return $header;
			}
		}
		foreach ( $rules as $header => $locales ) {
			if ( in_array( $lang, $locales, true ) && ! ( 'pt' === $lang && 'pt_BR' !== $locale ) ) {
				return $header;
			}
		}

		return 'nplurals=2; plural=n != 1;';
	}

	/**
	 * Records a string.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translation.
	 * @param string $domain      Text domain.
	 * @param string $context     Context.
	 * @param string $singular    Singular.
	 * @param string $plural      Plural.
	 * @return void
	 */
	protected static function collect( $translation, $domain, $context, $singular, $plural ) {
		$hash = Strope_String_Discovery_Store::hash( (string) $domain, (string) $context, (string) $singular, (string) $plural );

		if ( ! isset( self::$collected[ $hash ] ) ) {
			self::$collected[ $hash ] = array(
				'domain'      => (string) $domain,
				'context'     => (string) $context,
				'singular'    => (string) $singular,
				'plural'      => (string) $plural,
				'translation' => (string) $translation,
			);
		}
	}

	/**
	 * Collects strings from `gettext_{$domain}`.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Text to translate.
	 * @param string $domain      Text domain.
	 * @return string Unmodified translation.
	 */
	public static function collect_gettext( $translation, $text, $domain ) {
		self::collect( $translation, $domain, '', $text, '' );
		return $translation;
	}

	/**
	 * Collects strings from `gettext_with_context_{$domain}`.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Text to translate.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string Unmodified translation.
	 */
	public static function collect_gettext_with_context( $translation, $text, $context, $domain ) {
		self::collect( $translation, $domain, $context, $text, '' );
		return $translation;
	}

	/**
	 * Collects strings from `ngettext_{$domain}`.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translated text.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Number.
	 * @param string $domain      Text domain.
	 * @return string Unmodified translation.
	 */
	public static function collect_ngettext( $translation, $single, $plural, $number, $domain ) {
		self::collect( $translation, $domain, '', $single, $plural );
		return $translation;
	}

	/**
	 * Collects strings from `ngettext_with_context_{$domain}`.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translated text.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Number.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string Unmodified translation.
	 */
	public static function collect_ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
		self::collect( $translation, $domain, $context, $single, $plural );
		return $translation;
	}
}
