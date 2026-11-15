<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( $strope_translate->model->has_languages() ) {
	$strope_translate->sync_post_model = new Strope_Sync_Post_Model( $strope_translate );

	if ( $strope_translate instanceof Strope_Admin ) {
		require_once STROPE_TRANSLATE_DIR . '/modules/bulk-translate/load.php';
	}

	if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		$strope_translate->sync_post = new Strope_Sync_Post( $strope_translate );
	} elseif ( $strope_translate instanceof Strope_Admin ) {
		new Strope_Admin_Loader( $strope_translate, 'sync_post' );
	} elseif ( $strope_translate instanceof Strope_REST_Request ) {
		$strope_translate->sync_post = new Strope_Sync_Post_REST( $strope_translate );
	} else {
		$strope_translate->sync_post = new Strope_Sync_Post( $strope_translate );
	}
}
