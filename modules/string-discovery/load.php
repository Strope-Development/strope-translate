<?php
/**
 * Loads the theme and plugin strings discovery: scanner, overrides storage and runtime, admin UI.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

// Runtime overrides: hooks nothing when there are no overrides.
Strope_String_Overrides::init();

add_action( 'rest_api_init', array( 'Strope_String_Discovery_REST', 'register_routes' ) );

add_action( 'switch_blog', array( 'Strope_String_Discovery_Store', 'reset' ) );

// Cleans the translations when a language is deleted (the language slug is the term slug of the `language` taxonomy).
add_action(
	'delete_language',
	function ( $term, $tt_id, $deleted_term ) {
		if ( $deleted_term instanceof WP_Term ) {
			Strope_String_Discovery_Store::delete_language( $deleted_term->slug );
		}
	},
	10,
	3
);

// Follows the language slug and locale changes.
add_action(
	'strope_update_language',
	function ( $args, $lang ) {
		if ( ! $lang instanceof Strope_Language || empty( $args['slug'] ) ) {
			return;
		}

		$index = get_option( Strope_String_Discovery_Store::INDEX_OPTION );
		if ( empty( $index[ $lang->slug ] ) ) {
			return;
		}

		$new_slug = (string) $args['slug'];

		if ( $new_slug !== $lang->slug ) {
			global $wpdb;
			$wpdb->update( Strope_String_Discovery_Store::translations_table(), array( 'language' => $new_slug ), array( 'language' => $lang->slug ) );
			Strope_String_Discovery_Store::delete_language( $lang->slug );
		}

		$language = Strope()->model->get_language( $new_slug );
		if ( $language ) {
			Strope_String_Discovery_Store::rebuild_cache( $language );
		}
	},
	10,
	2
);

if ( $strope_translate instanceof Strope_Settings ) {
	new Strope_String_Discovery_Admin();
}

if ( is_admin() ) {
	add_action( 'admin_init', array( 'Strope_String_Discovery_Store', 'maybe_install' ) );
}
