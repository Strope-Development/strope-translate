<?php
/**
 * Loads the shared slugs module.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( $strope_translate->model->has_languages() ) {
	add_filter(
		'strope_settings_modules',
		function ( $modules ) {
			$modules[] = 'Strope_Settings_Share_Slug';
			return $modules;
		}
	);

	if ( get_option( 'permalink_structure' ) && $strope_translate->options['force_lang'] ) {
		$strope_translate->share_post_slug = new Strope_Share_Post_Slug( $strope_translate );
		$strope_translate->share_term_slug = new Strope_Share_Term_Slug( $strope_translate );
	}
}
