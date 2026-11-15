<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( ( $strope_translate instanceof Strope_Settings || $strope_translate instanceof Strope_Admin ) && $strope_translate->model->has_languages() ) {
	if ( $strope_translate instanceof Strope_Admin ) {
		require_once STROPE_TRANSLATE_DIR . '/modules/bulk-translate/load.php';
	}
	require_once STROPE_TRANSLATE_DIR . '/modules/sync/load.php';

	$strope_translate->import_export = new Strope_Import_Export( $strope_translate );
}
