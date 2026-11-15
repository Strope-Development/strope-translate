<?php
/**
 * Loads the translated slugs module.
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
			$modules[] = 'Strope_Settings_Translate_Slugs';
			return $modules;
		}
	);

	if ( get_option( 'permalink_structure' ) ) {
		$slugs_model = new Strope_Translate_Slugs_Model( $strope_translate );

		if ( $strope_translate instanceof Strope_Frontend ) {
			$strope_translate->translate_slugs = new Strope_Frontend_Translate_Slugs( $slugs_model, $strope_translate->curlang );
		} else {
			$curlang = isset( $strope_translate->curlang ) ? $strope_translate->curlang : null;
			$strope_translate->translate_slugs = new Strope_Translate_Slugs( $slugs_model, $curlang );
		}
	}
}
