<?php
/**
 * Loads the module for synchronization such as metas, taxonomies, content and navigation.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly
}

if ( $strope_translate->model->has_languages() ) {
	if ( $strope_translate instanceof Strope_Admin_Base ) {
		$strope_translate->sync = new Strope_Admin_Sync( $strope_translate );
	} else {
		$strope_translate->sync = new Strope_Sync( $strope_translate );
	}

	$strope_translate->sync_content = new Strope_Sync_Content( $strope_translate );
	$strope_translate->navigation   = new Strope_Sync_Navigation( $strope_translate );

	add_filter(
		'strope_settings_modules',
		function ( $modules ) {
			$modules[] = 'Strope_Settings_Sync';
			return $modules;
		}
	);
}
