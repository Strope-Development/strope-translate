<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( $strope_translate->model->has_languages() ) {
	add_filter(
		'strope_settings_modules',
		function ( $modules ) {
			$k = array_search( 'Strope_Settings_Media', $modules );
			if ( $k ) {
				$modules[ $k ] = 'Strope_Settings_Advanced_Media';
			}
			return $modules;
		},
		0
	);

	add_action(
		'strope_init',
		function ( $strope_translate ) {
			if ( $strope_translate->options['media_support'] ) {
				if ( $strope_translate instanceof Strope_Admin ) {
					require_once STROPE_TRANSLATE_DIR . '/modules/bulk-translate/load.php';
				}

				if ( $strope_translate instanceof Strope_Admin || $strope_translate instanceof Strope_REST_Request ) {
					$strope_translate->advanced_media = new Strope_Admin_Advanced_Media( $strope_translate );
				}
			}
		}
	);
}
