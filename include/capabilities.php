<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta capabilities to manage languages and translations.
 *
 * - `manage_languages`: languages and settings pages.
 * - `manage_translations`: strings translations and translation tools.
 * - `assign_languages`: assign a language to a post through the `language` taxonomy terms (for example with the
 *   `tax_input` argument of `wp_insert_post()`), as `assign_terms` capability of this taxonomy.
 *
 * By default, they are granted to users who can `manage_options`. A role (or user) can also be given
 * one of these capabilities directly, for example a translator role allowed to translate strings.
 *
 * @since 4.1.0
 */
class Strope_Capabilities {
	/**
	 * Capability to manage the languages and the settings.
	 *
	 * @var string
	 */
	const MANAGE_LANGUAGES = 'manage_languages';

	/**
	 * Capability to manage the translations (strings translations, translation tools).
	 *
	 * @var string
	 */
	const MANAGE_TRANSLATIONS = 'manage_translations';

	/**
	 * Capability to assign the language taxonomy terms to posts, mapped to `edit_posts`.
	 *
	 * @since 4.1.0
	 *
	 * @var string
	 */
	const ASSIGN_LANGUAGES = 'assign_languages';

	/**
	 * Adds the hooks.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Maps the plugin capabilities to the primitive capabilities required to use them.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Additional arguments.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( self::ASSIGN_LANGUAGES === $cap ) {
			// As `assign_categories` in WordPress.
			$caps = array( 'edit_posts' );
		} elseif ( self::MANAGE_LANGUAGES === $cap || self::MANAGE_TRANSLATIONS === $cap ) {
			// A user explicitly granted the capability keeps it, otherwise it requires `manage_options`.
			$user = get_userdata( $user_id );
			$caps = $user instanceof WP_User && ! empty( $user->allcaps[ $cap ] ) ? array( $cap ) : array( 'manage_options' );
		} else {
			return $caps;
		}

		/**
		 * Filters the primitive capabilities required to manage languages or translations, or to assign languages.
		 *
		 * @since 4.1.0
		 *
		 * @param string[] $caps    Primitive capabilities required.
		 * @param string   $cap     `manage_languages`, `manage_translations` or `assign_languages`.
		 * @param int      $user_id User ID.
		 * @param array    $args    Additional arguments.
		 */
		return (array) apply_filters( 'strope_map_meta_cap', $caps, $cap, $user_id, $args );
	}
}
