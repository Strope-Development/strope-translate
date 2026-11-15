<?php
/**
 * Loads the translation status tracking (missing, outdated and needs review translations).
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/functions.php';

if ( ! $strope_translate->model->has_languages() || ! Strope_Translation_Status::is_enabled() ) {
	return;
}

$strope_translate->translation_status = new Strope_Translation_Status_Tracker();
Strope_Word_Count::init();
new Strope_Translation_Status_REST();

if ( $strope_translate instanceof Strope_Admin ) {
	new Strope_Translation_Status_Admin( $strope_translate );
	new Strope_Translation_Status_Dashboard( $strope_translate );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'strope translation-status', 'Strope_Translation_Status_CLI' );
}
