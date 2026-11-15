<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Applies the theme and plugin string overrides at runtime.
 *
 * Gettext filters are registered only for the text domains having at least one override in the
 * current language. When there are no overrides at all, nothing is hooked (the only cost is reading
 * one small autoloaded option).
 *
 * @since 4.1.0
 */
class Strope_String_Overrides {
	/**
	 * Singleton instance.
	 *
	 * @var Strope_String_Overrides|null
	 */
	protected static $instance;

	/**
	 * Index of the text domains having overrides, keyed by language slug.
	 *
	 * @var array<string, string[]>
	 */
	protected $index = array();

	/**
	 * The language slug whose overrides are currently applied, empty string if none.
	 *
	 * @var string
	 */
	protected $current = '';

	/**
	 * Overrides of the current language, keyed by text domain then by key {@see self::key()}.
	 *
	 * @var array
	 */
	protected $lookup = array();

	/**
	 * Text domains with registered filters.
	 *
	 * @var string[]
	 */
	protected $hooked = array();

	/**
	 * Plural forms expression of the current language.
	 *
	 * @var string
	 */
	protected $plural_forms = '';

	/**
	 * Plural forms parser.
	 *
	 * @var Plural_Forms|null
	 */
	protected $plural_parser;

	/**
	 * Results of the placeholders checks of the overrides containing a percent sign.
	 *
	 * @var bool[]
	 */
	protected $checked = array();

	/**
	 * Initializes the runtime: hooks only if there are overrides.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public static function init() {
		$index = get_option( Strope_String_Discovery_Store::INDEX_OPTION );

		if ( empty( $index ) || ! is_array( $index ) ) {
			return; // Nothing to do, nothing hooked.
		}

		self::$instance = new self();
		self::$instance->index = $index;

		add_action( 'strope_language_defined', array( self::$instance, 'refresh' ), 1, 0 );
		add_action( 'strope_no_language_defined', array( self::$instance, 'refresh' ), 1, 0 );
		add_action( 'init', array( self::$instance, 'refresh' ), 0, 0 );
		add_action( 'change_locale', array( self::$instance, 'refresh' ), 1 );
		add_action( 'switch_blog', array( self::$instance, 'switch_blog' ), 10, 0 );
	}

	/**
	 * Returns the instance, null when there are no overrides.
	 *
	 * @since 4.1.0
	 *
	 * @return Strope_String_Overrides|null
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * Returns the lookup key of a string.
	 *
	 * @since 4.1.0
	 *
	 * @param string $context  Context.
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @return string
	 */
	public static function key( string $context, string $singular, string $plural = '' ): string {
		$key = '' === $context ? $singular : $context . "\4" . $singular;
		return '' === $plural ? $key : $key . "\0" . $plural;
	}

	/**
	 * Reloads the index after switching blog.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function switch_blog() {
		$index       = get_option( Strope_String_Discovery_Store::INDEX_OPTION );
		$this->index = is_array( $index ) ? $index : array();
		$this->current = '#'; // Force refresh.
		$this->refresh();
	}

	/**
	 * Determines the current language and (re)registers the filters accordingly.
	 *
	 * @since 4.1.0
	 *
	 * @param string $locale Optional locale, defaults to the current one.
	 * @return void
	 */
	public function refresh( $locale = '' ) {
		$language = $this->get_language( is_string( $locale ) ? $locale : '' );
		$slug     = $language ? $language->slug : '';

		if ( $slug === $this->current ) {
			return;
		}

		$this->unhook();
		$this->current = $slug;

		if ( '' === $slug || empty( $this->index[ $slug ] ) ) {
			return;
		}

		$data = get_option( Strope_String_Discovery_Store::CACHE_OPTION_PREFIX . $slug );
		if ( empty( $data['domains'] ) || ! is_array( $data['domains'] ) ) {
			return;
		}

		$this->lookup        = $data['domains'];
		$this->plural_forms  = isset( $data['plural_forms'] ) && is_string( $data['plural_forms'] ) ? $data['plural_forms'] : '';
		$this->plural_parser = null;

		foreach ( array_keys( $this->lookup ) as $domain ) {
			$domain = (string) $domain;
			add_filter( "gettext_{$domain}", array( $this, 'gettext' ), 20, 3 );
			add_filter( "gettext_with_context_{$domain}", array( $this, 'gettext_with_context' ), 20, 4 );
			add_filter( "ngettext_{$domain}", array( $this, 'ngettext' ), 20, 5 );
			add_filter( "ngettext_with_context_{$domain}", array( $this, 'ngettext_with_context' ), 20, 6 );
			$this->hooked[] = $domain;
		}
	}

	/**
	 * Removes all the registered filters.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	protected function unhook() {
		foreach ( $this->hooked as $domain ) {
			remove_filter( "gettext_{$domain}", array( $this, 'gettext' ), 20 );
			remove_filter( "gettext_with_context_{$domain}", array( $this, 'gettext_with_context' ), 20 );
			remove_filter( "ngettext_{$domain}", array( $this, 'ngettext' ), 20 );
			remove_filter( "ngettext_with_context_{$domain}", array( $this, 'ngettext_with_context' ), 20 );
		}

		$this->hooked  = array();
		$this->lookup  = array();
		$this->checked = array();
	}

	/**
	 * Returns the language matching the locale.
	 *
	 * @since 4.1.0
	 *
	 * @param string $locale Locale, empty for the current one.
	 * @return Strope_Language|null
	 */
	protected function get_language( string $locale ) {
		$strope = isset( $GLOBALS['strope_translate'] ) ? $GLOBALS['strope_translate'] : null;

		if ( ! $strope instanceof Strope_Base ) {
			return null;
		}

		if ( '' === $locale ) {
			if ( $strope instanceof Strope_Frontend ) {
				if ( empty( $strope->curlang ) ) {
					return null; // The language is not known yet.
				}
				$locale = $strope->curlang->locale;
			} else {
				$locale = determine_locale();
			}
		}

		$language = $strope->model->get_language( $locale );
		return $language instanceof Strope_Language ? $language : null;
	}

	/**
	 * Tells whether an override can be used: its printf placeholders must match those of the source string,
	 * otherwise `sprintf()` could throw a fatal error. Protects from the overrides saved before this check existed.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed  $override Stored override.
	 * @param string $singular Source string.
	 * @param string $plural   Plural source string, empty if none.
	 * @return bool
	 */
	protected function is_valid( $override, string $singular, string $plural = '' ): bool {
		if ( ! is_string( $override ) || '' === $override ) {
			return false;
		}

		if ( false === strpos( $override . $singular . $plural, '%' ) ) {
			return true; // Fast path.
		}

		$key = $singular . "\0" . $plural . "\0" . $override;
		if ( ! isset( $this->checked[ $key ] ) ) {
			$this->checked[ $key ] = Strope_String_Placeholders::match( $override, $singular, $plural );
		}

		return $this->checked[ $key ];
	}

	/**
	 * Returns the translation for a plural string.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed  $forms       Stored translations.
	 * @param int    $number      Number used to select the plural form.
	 * @param string $translation Translation returned by WordPress.
	 * @param string $single      Singular source string.
	 * @param string $plural      Plural source string.
	 * @return string
	 */
	protected function select_plural( $forms, $number, $translation, $single, $plural ) {
		if ( ! is_array( $forms ) ) {
			return $translation;
		}

		$index = $this->get_plural_index( (int) $number );
		return isset( $forms[ $index ] ) && $this->is_valid( $forms[ $index ], (string) $single, (string) $plural ) ? $forms[ $index ] : $translation;
	}

	/**
	 * Returns the index of the plural form to use for a number.
	 *
	 * @since 4.1.0
	 *
	 * @param int $number Number.
	 * @return int
	 */
	protected function get_plural_index( int $number ): int {
		if ( '' === $this->plural_forms || 'n != 1' === $this->plural_forms ) {
			return 1 === $number ? 0 : 1;
		}

		if ( null === $this->plural_parser ) {
			if ( ! class_exists( 'Plural_Forms' ) ) {
				require_once ABSPATH . WPINC . '/pomo/plural-forms.php';
			}

			try {
				$this->plural_parser = new Plural_Forms( $this->plural_forms );
			} catch ( Exception $e ) {
				$this->plural_forms = 'n != 1';
				return 1 === $number ? 0 : 1;
			}
		}

		try {
			return (int) $this->plural_parser->get( $number );
		} catch ( Exception $e ) {
			return 1 === $number ? 0 : 1;
		}
	}

	/**
	 * Filters `gettext_{$domain}`.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Text to translate.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function gettext( $translation, $text, $domain ) {
		return isset( $this->lookup[ $domain ][ $text ] ) && $this->is_valid( $this->lookup[ $domain ][ $text ], (string) $text ) ? $this->lookup[ $domain ][ $text ] : $translation;
	}

	/**
	 * Filters `gettext_with_context_{$domain}`.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Text to translate.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function gettext_with_context( $translation, $text, $context, $domain ) {
		$key = $context . "\4" . $text;
		return isset( $this->lookup[ $domain ][ $key ] ) && $this->is_valid( $this->lookup[ $domain ][ $key ], (string) $text ) ? $this->lookup[ $domain ][ $key ] : $translation;
	}

	/**
	 * Filters `ngettext_{$domain}`.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translated text.
	 * @param string $single      Singular form.
	 * @param string $plural      Plural form.
	 * @param int    $number      Number.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function ngettext( $translation, $single, $plural, $number, $domain ) {
		$key = $single . "\0" . $plural;
		return isset( $this->lookup[ $domain ][ $key ] ) ? $this->select_plural( $this->lookup[ $domain ][ $key ], $number, $translation, $single, $plural ) : $translation;
	}

	/**
	 * Filters `ngettext_with_context_{$domain}`.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translated text.
	 * @param string $single      Singular form.
	 * @param string $plural      Plural form.
	 * @param int    $number      Number.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
		$key = $context . "\4" . $single . "\0" . $plural;
		return isset( $this->lookup[ $domain ][ $key ] ) ? $this->select_plural( $this->lookup[ $domain ][ $key ], $number, $translation, $single, $plural ) : $translation;
	}
}
