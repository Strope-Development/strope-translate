<?php
/**
 * Loads the content fallback module.
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
			$modules[] = 'Strope_Settings_Content_Fallback';
			return $modules;
		}
	);

	if ( $strope_translate instanceof Strope_Frontend && Strope_Content_Fallback::is_enabled( $strope_translate->options ) ) {
		$strope_translate->content_fallback = new Strope_Frontend_Content_Fallback( $strope_translate );
	}
}
