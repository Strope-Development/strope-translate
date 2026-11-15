<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( $strope_translate->model->has_languages() ) {
	if ( $strope_translate instanceof Strope_Admin ) {
		new Strope_Admin_Loader( $strope_translate, 'duplicate' );
		$strope_translate->duplicate_action = new Strope_Duplicate_Action( $strope_translate );
	} elseif ( $strope_translate instanceof Strope_REST_Request ) {
		$strope_translate->duplicate = new Strope_Duplicate_REST( $strope_translate );
	}
}
