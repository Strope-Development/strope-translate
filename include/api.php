<?php
/**
 * The Strope Translate public API.
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template tag: displays the language switcher.
 * The function does nothing if used outside the frontend.
 *
 * @api
 * @since 0.5
 *
 * @param array $args {
 *   Optional array of arguments.
 *
 *   @type int    $dropdown               The list is displayed as dropdown if set to 1, defaults to 0.
 *   @type int    $echo                   Echoes the list if set to 1, defaults to 1.
 *   @type int    $hide_if_empty          Hides languages with no posts ( or pages ) if set to 1, defaults to 1.
 *   @type int    $show_flags             Displays flags if set to 1, defaults to 0.
 *   @type int    $show_names             Shows language names if set to 1, defaults to 1.
 *   @type string $display_names_as       Whether to display the language name or its slug, valid options are 'slug' and 'name', defaults to name.
 *   @type int    $force_home             Will always link to the homepage in the translated language if set to 1, defaults to 0.
 *   @type int    $hide_if_no_translation Hides the link if there is no translation if set to 1, defaults to 0.
 *   @type int    $hide_current           Hides the current language if set to 1, defaults to 0.
 *   @type int    $post_id                Returns links to the translations of the post defined by post_id if set, defaults to not set.
 *   @type int    $raw                    Return a raw array instead of html markup if set to 1, defaults to 0.
 *   @type string $item_spacing           Whether to preserve or discard whitespace between list items, valid options are 'preserve' and 'discard', defaults to 'preserve'.
 * }
 * @return string|array Either the html markup of the switcher or the raw elements to build a custom language switcher.
 */
function strope_the_languages( $args = array() ) {
	if ( empty( Strope()->links ) ) {
		return empty( $args['raw'] ) ? '' : array();
	}

	$switcher = new Strope_Switcher();
	return $switcher->the_languages( Strope()->links, $args );
}

/**
 * Returns the current language on frontend.
 * Returns the language set in admin language filter on backend (false if set to all languages).
 *
 * @api
 * @since 0.8.1
 * @since 3.4 Accepts composite values.
 *
 * @param string $field Optional, the language field to return (@see Strope_Language), defaults to `'slug'`.
 *                      Pass `\OBJECT` constant to get the language object. A composite value can be used for language
 *                      term property values, in the form of `{language_taxonomy_name}:{property_name}` (see
 *                      {@see Strope_Language::get_tax_prop()} for the possible values). Ex: `term_language:term_taxonomy_id`.
 * @return string|int|bool|string[]|Strope_Language The requested field or object for the current language, `false` if the field isn't set or if current language doesn't exist yet.
 *
 * @phpstan-return (
 *     $field is \OBJECT ? Strope_Language : (
 *         $field is 'slug' ? non-empty-string : string|int|bool|list<non-empty-string>
 *     )
 * )|false
 */
function strope_current_language( $field = 'slug' ) {
	if ( empty( Strope()->curlang ) ) {
		return false;
	}

	if ( \OBJECT === $field ) {
		return Strope()->curlang;
	}

	return Strope()->curlang->get_prop( $field );
}

/**
 * Returns the default language.
 *
 * @api
 * @since 1.0
 * @since 3.4 Accepts composite values.
 *
 * @param string $field Optional, the language field to return (@see Strope_Language), defaults to `'slug'`.
 *                      Pass `\OBJECT` constant to get the language object. A composite value can be used for language
 *                      term property values, in the form of `{language_taxonomy_name}:{property_name}` (see
 *                      {@see Strope_Language::get_tax_prop()} for the possible values). Ex: `term_language:term_taxonomy_id`.
 * @return string|int|bool|string[]|Strope_Language The requested field or object for the default language, `false` if the field isn't set or if default language doesn't exist yet.
 *
 * @phpstan-return (
 *     $field is \OBJECT ? Strope_Language : (
 *         $field is 'slug' ? non-empty-string : string|int|bool|list<non-empty-string>
 *     )
 * )|false
 */
function strope_default_language( $field = 'slug' ) {
	$lang = Strope()->model->get_default_language();

	if ( empty( $lang ) ) {
		return false;
	}

	if ( \OBJECT === $field ) {
		return $lang;
	}

	return $lang->get_prop( $field );
}

/**
 * Among the post and its translations, returns the ID of the post which is in the language represented by $lang.
 *
 * @api
 * @since 0.5
 * @since 3.4 Returns 0 instead of false.
 * @since 3.4 $lang accepts Strope_Language or string.
 *
 * @param int                 $post_id Post ID.
 * @param Strope_Language|string $lang    Optional language (object or slug), defaults to the current language.
 * @return int|false The translation post ID if exists, otherwise the passed ID. False if the passed object has no language or if the language doesn't exist.
 *
 * @phpstan-return int<0, max>|false
 */
function strope_get_post( $post_id, $lang = '' ) {
	$lang = $lang ? $lang : strope_current_language();

	if ( empty( $lang ) ) {
		return false;
	}

	return Strope()->model->post->get( $post_id, $lang );
}

/**
 * Among the term and its translations, returns the ID of the term which is in the language represented by $lang.
 *
 * @api
 * @since 0.5
 * @since 3.4 Returns 0 instead of false.
 * @since 3.4 $lang accepts Strope_Language or string.
 *
 * @param int                 $term_id Term ID.
 * @param Strope_Language|string $lang    Optional language (object or slug), defaults to the current language.
 * @return int|false The translation term ID if exists, otherwise the passed ID. False if the passed object has no language or if the language doesn't exist.
 *
 * @phpstan-return int<0, max>|false
 */
function strope_get_term( $term_id, $lang = null ) {
	$lang = $lang ? $lang : strope_current_language();

	if ( empty( $lang ) ) {
		return false;
	}

	return Strope()->model->term->get( $term_id, $lang );
}

/**
 * Returns the home url in a language.
 *
 * @api
 * @since 0.8
 *
 * @param string $lang Optional language code, defaults to the current language.
 * @return string
 */
function strope_home_url( $lang = '' ) {
	if ( empty( $lang ) ) {
		$lang = strope_current_language();
	}

	if ( empty( $lang ) || empty( Strope()->links ) ) {
		return home_url( '/' );
	}

	return Strope()->links->get_home_url( $lang );
}

/**
 * Registers a string for translation in the "strings translation" panel.
 *
 * @api
 * @since 0.6
 *
 * @param string $name      A unique name for the string.
 * @param string $string    The string to register.
 * @param string $context   Optional, the group in which the string is registered, defaults to 'strope_translate'.
 * @param bool   $multiline Optional, true if the string table should display a multiline textarea,
 *                          false if should display a single line input, defaults to false.
 * @return void
 */
function strope_register_string( $name, $string, $context = 'Strope Translate', $multiline = false ) {
	if ( Strope() instanceof Strope_Admin_Base ) {
		Strope_Admin_Strings::register_string( $name, $string, $context, $multiline );
	}
}

/**
 * Translates a string ( previously registered with strope_register_string ).
 *
 * @api
 * @since 0.6
 *
 * @param string $string The string to translate.
 * @return string The string translated in the current language.
 */
function strope__( $string ) {
	if ( ! is_scalar( $string ) || '' === $string ) {
		return $string;
	}

	return __( $string, 'strope_string' ); // PHPCS:ignore WordPress.WP.I18n
}

/**
 * Translates a string ( previously registered with strope_register_string ) and escapes it for safe use in HTML output.
 *
 * @api
 * @since 2.1
 *
 * @param string $string The string to translate.
 * @return string The string translated in the current language.
 */
function strope_esc_html__( $string ) {
	return esc_html( strope__( $string ) );
}

/**
 * Translates a string ( previously registered with strope_register_string ) and escapes it for safe use in HTML attributes.
 *
 * @api
 * @since 2.1
 *
 * @param string $string The string to translate.
 * @return string The string translated in the current language.
 */
function strope_esc_attr__( $string ) {
	return esc_attr( strope__( $string ) );
}

/**
 * Echoes a translated string ( previously registered with strope_register_string )
 * It is an equivalent of _e() and is not escaped.
 *
 * @api
 * @since 0.6
 *
 * @param string $string The string to translate.
 * @return void
 */
function strope_e( $string ) {
	echo strope__( $string ); // phpcs:ignore
}

/**
 * Echoes a translated string ( previously registered with strope_register_string ) and escapes it for safe use in HTML output.
 *
 * @api
 * @since 2.1
 *
 * @param string $string The string to translate.
 * @return void
 */
function strope_esc_html_e( $string ) {
	echo strope_esc_html__( $string ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Echoes a translated a string ( previously registered with strope_register_string ) and escapes it for safe use in HTML attributes.
 *
 * @api
 * @since 2.1
 *
 * @param string $string The string to translate.
 * @return void
 */
function strope_esc_attr_e( $string ) {
	echo strope_esc_attr__( $string ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Translates a string ( previously registered with strope_register_string ).
 *
 * @api
 * @since 1.5.4
 *
 * @param string $string The string to translate.
 * @param string $lang   Language code.
 * @return string The string translated in the requested language.
 */
function strope_translate_string( $string, $lang ) {
	if ( Strope() instanceof Strope_Frontend && strope_current_language() === $lang ) {
		return strope__( $string );
	}

	if ( ! is_scalar( $string ) || '' === $string ) {
		return $string;
	}

	$lang = Strope()->model->get_language( $lang );

	if ( empty( $lang ) ) {
		return $string;
	}

	static $cache; // Cache object to avoid loading the same translations object several times.

	if ( empty( $cache ) ) {
		$cache = new Strope_Cache();
	}

	$mo = $cache->get( $lang->slug );

	if ( ! $mo instanceof Strope_MO ) {
		$mo = new Strope_MO();
		$mo->import_from_db( $lang );
		$cache->set( $lang->slug, $mo );
	}

	return $mo->translate( $string );
}

/**
 * Returns true if Strope Translate manages languages and translations for this post type.
 *
 * @api
 * @since 1.0.1
 *
 * @param string $post_type Post type name.
 * @return bool
 */
function strope_is_translated_post_type( $post_type ) {
	return Strope()->model->is_translated_post_type( $post_type );
}

/**
 * Returns true if Strope Translate manages languages and translations for this taxonomy.
 *
 * @api
 * @since 1.0.1
 *
 * @param string $tax Taxonomy name.
 * @return bool
 */
function strope_is_translated_taxonomy( $tax ) {
	return Strope()->model->is_translated_taxonomy( $tax );
}

/**
 * Returns the list of available languages.
 *
 * @api
 * @since 1.5
 *
 * @param array $args {
 *   Optional array of arguments.
 *
 *   @type bool   $hide_empty Hides languages with no posts if set to true ( defaults to false ).
 *   @type string $fields     Return only that field if set ( @see Strope_Language for a list of fields ), defaults to 'slug'.
 * }
 * @return string[]
 */
function strope_languages_list( $args = array() ) {
	$args = wp_parse_args( $args, array( 'fields' => 'slug' ) );
	return Strope()->model->get_languages_list( $args );
}

/**
 * Sets the post language.
 *
 * @api
 * @since 1.5
 * @since 3.4 $lang accepts Strope_Language or string.
 * @since 3.4 Returns a boolean.
 *
 * @param int                 $id   Post ID.
 * @param Strope_Language|string $lang Language (object or slug).
 * @return bool True when successfully assigned. False otherwise (or if the given language is already assigned to
 *              the post).
 */
function strope_set_post_language( $id, $lang ) {
	return Strope()->model->post->set_language( $id, $lang );
}

/**
 * Sets the term language.
 *
 * @api
 * @since 1.5
 * @since 3.4 $lang accepts Strope_Language or string.
 * @since 3.4 Returns a boolean.
 *
 * @param int                 $id   Term ID.
 * @param Strope_Language|string $lang Language (object or slug).
 * @return bool True when successfully assigned. False otherwise (or if the given language is already assigned to
 *              the term).
 */
function strope_set_term_language( $id, $lang ) {
	return Strope()->model->term->set_language( $id, $lang );
}

/**
 * Save posts translations.
 *
 * @api
 * @since 1.5
 * @since 3.4 Returns an associative array of translations.
 *
 * @param int[] $arr An associative array of translations with language code as key and post ID as value.
 * @return int[] An associative array with language codes as key and post IDs as values.
 *
 * @phpstan-return array<non-empty-string, positive-int>
 */
function strope_save_post_translations( $arr ) {
	$id = reset( $arr );
	if ( $id ) {
		return Strope()->model->post->save_translations( $id, $arr );
	}

	return array();
}

/**
 * Save terms translations
 *
 * @api
 * @since 1.5
 * @since 3.4 Returns an associative array of translations.
 *
 * @param int[] $arr An associative array of translations with language code as key and term ID as value.
 * @return int[] An associative array with language codes as key and term IDs as values.
 *
 * @phpstan-return array<non-empty-string, positive-int>
 */
function strope_save_term_translations( $arr ) {
	$id = reset( $arr );
	if ( $id ) {
		return Strope()->model->term->save_translations( $id, $arr );
	}

	return array();
}

/**
 * Returns the post language.
 *
 * @api
 * @since 1.5.4
 * @since 3.4 Accepts composite values for `$field`.
 *
 * @param int    $post_id Post ID.
 * @param string $field Optional, the language field to return (@see Strope_Language), defaults to `'slug'`.
 *                      Pass `\OBJECT` constant to get the language object. A composite value can be used for language
 *                      term property values, in the form of `{language_taxonomy_name}:{property_name}` (see
 *                      {@see Strope_Language::get_tax_prop()} for the possible values). Ex: `term_language:term_taxonomy_id`.
 * @return string|int|bool|string[]|Strope_Language The requested field or object for the post language, `false` if no language is associated to that post.
 *
 * @phpstan-return (
 *     $field is \OBJECT ? Strope_Language : (
 *         $field is 'slug' ? non-empty-string : string|int|bool|list<non-empty-string>
 *     )
 * )|false
 */
function strope_get_post_language( $post_id, $field = 'slug' ) {
	$lang = Strope()->model->post->get_language( $post_id );

	if ( empty( $lang ) || \OBJECT === $field ) {
		return $lang;
	}

	return $lang->get_prop( $field );
}

/**
 * Returns the term language.
 *
 * @api
 * @since 1.5.4
 * @since 3.4 Accepts composite values for `$field`.
 *
 * @param int    $term_id Term ID.
 * @param string $field Optional, the language field to return (@see Strope_Language), defaults to `'slug'`.
 *                      Pass `\OBJECT` constant to get the language object. A composite value can be used for language
 *                      term property values, in the form of `{language_taxonomy_name}:{property_name}` (see
 *                      {@see Strope_Language::get_tax_prop()} for the possible values). Ex: `term_language:term_taxonomy_id`.
 * @return string|int|bool|string[]|Strope_Language The requested field or object for the post language, `false` if no language is associated to that term.
 *
 * @phpstan-return (
 *     $field is \OBJECT ? Strope_Language : (
 *         $field is 'slug' ? non-empty-string : string|int|bool|list<non-empty-string>
 *     )
 * )|false
 */
function strope_get_term_language( $term_id, $field = 'slug' ) {
	$lang = Strope()->model->term->get_language( $term_id );

	if ( empty( $lang ) || \OBJECT === $field ) {
		return $lang;
	}

	return $lang->get_prop( $field );
}

/**
 * Returns an array of translations of a post.
 *
 * @api
 * @since 1.8
 *
 * @param int $post_id Post ID.
 * @return int[] An associative array of translations with language code as key and translation post ID as value.
 *
 * @phpstan-return array<non-empty-string, positive-int>
 */
function strope_get_post_translations( $post_id ) {
	return Strope()->model->post->get_translations( $post_id );
}

/**
 * Returns an array of translations of a term.
 *
 * @api
 * @since 1.8
 *
 * @param int $term_id Term ID.
 * @return int[] An associative array of translations with language code as key and translation term ID as value.
 *
 * @phpstan-return array<non-empty-string, positive-int>
 */
function strope_get_term_translations( $term_id ) {
	return Strope()->model->term->get_translations( $term_id );
}

/**
 * Counts posts in a language.
 *
 * @api
 * @since 1.5
 *
 * @param string $lang Language code.
 * @param array  $args {
 *   Optional array of arguments.
 *
 *   @type string $post_type   Post type.
 *   @type int    $m           YearMonth ( ex: 201307 ).
 *   @type int    $year        4 digit year.
 *   @type int    $monthnum    Month number (from 1 to 12).
 *   @type int    $day         Day of the month (from 1 to 31).
 *   @type int    $author      Author id.
 *   @type string $author_name Author nicename.
 *   @type string $post_format Post format.
 *   @type string $post_status Post status.
 * }
 * @return int Posts count.
 */
function strope_count_posts( $lang, $args = array() ) {
	$lang = Strope()->model->get_language( $lang );

	if ( empty( $lang ) ) {
		return 0;
	}

	return Strope()->model->count_posts( $lang, $args );
}

/**
 * Inserts a post in a given language, optionally linked to its translations.
 * Wraps `wp_insert_post()`.
 *
 * @since 4.1.0
 *
 * @param array                  $postarr  {
 *     An array of elements that make up a post to insert. See `wp_insert_post()` for accepted arguments (unslashed).
 *
 *     @type int[] $translations Optional. Translations to link, with language slugs as keys and post ids as values.
 * }
 * @param Strope_Language|string $language The post language, object or slug.
 * @return int|WP_Error The post id on success, a `WP_Error` on failure.
 */
function strope_insert_post( array $postarr, $language ) {
	$language = Strope()->model->get_language( $language );

	if ( ! $language instanceof Strope_Language ) {
		return new WP_Error( 'strope_invalid_language', __( 'Please provide a valid language.', 'strope-translate' ) );
	}

	return Strope()->model->post->insert( $postarr, $language );
}

/**
 * Inserts a term in a given language, optionally linked to its translations.
 * The same name or slug may be used by a term of the same taxonomy in another language.
 * Wraps `wp_insert_term()`.
 *
 * @since 4.1.0
 *
 * @param string                 $term     The term name.
 * @param string                 $taxonomy The taxonomy.
 * @param Strope_Language|string $language The term language, object or slug.
 * @param array                  $args     {
 *     Optional. See `wp_insert_term()` for accepted arguments.
 *
 *     @type int[] $translations Optional. Translations to link, with language slugs as keys and term ids as values.
 * }
 * @return array|WP_Error An array with the keys `term_id` and `term_taxonomy_id`, a `WP_Error` on failure.
 */
function strope_insert_term( string $term, string $taxonomy, $language, array $args = array() ) {
	$language = Strope()->model->get_language( $language );

	if ( ! $language instanceof Strope_Language ) {
		return new WP_Error( 'strope_invalid_language', __( 'Please provide a valid language.', 'strope-translate' ) );
	}

	return Strope()->model->term->insert( $term, $taxonomy, $language, $args );
}

/**
 * Updates a post, optionally changing its language and its translations.
 * Wraps `wp_update_post()`.
 *
 * @since 4.1.0
 *
 * @param array $postarr {
 *     An array of elements that make up the post to update, including its `ID`. See `wp_insert_post()` for accepted arguments (unslashed).
 *
 *     @type Strope_Language|string $lang         Optional. The new language, object or slug.
 *     @type int[]                  $translations Optional. Translations to link, with language slugs as keys and post ids as values.
 * }
 * @return int|WP_Error The post id on success, a `WP_Error` on failure.
 */
function strope_update_post( array $postarr ) {
	return Strope()->model->post->update( $postarr );
}

/**
 * Updates a term, optionally changing its language and its translations.
 * Wraps `wp_update_term()`.
 *
 * @since 4.1.0
 *
 * @param int   $term_id The term id.
 * @param array $args    {
 *     Optional. See `wp_update_term()` for accepted arguments.
 *
 *     @type Strope_Language|string $lang         Optional. The new language, object or slug.
 *     @type int[]                  $translations Optional. Translations to link, with language slugs as keys and term ids as values.
 * }
 * @return array|WP_Error An array with the keys `term_id` and `term_taxonomy_id`, a `WP_Error` on failure.
 */
function strope_update_term( int $term_id, array $args = array() ) {
	return Strope()->model->term->update( $term_id, $args );
}

/**
 * Allows to access the Strope Translate instance.
 * However, it is always preferable to use API functions
 * as internal methods may be changed without prior notice.
 *
 * @since 1.8
 *
 * @return Strope_Frontend|Strope_Admin|Strope_Settings|Strope_REST_Request
 */
function Strope() { // PHPCS:ignore WordPress.NamingConventions.ValidFunctionName
	return $GLOBALS['strope_translate'];
}
