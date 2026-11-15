<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( $strope_translate instanceof Strope_Admin && $strope_translate->model->has_languages() ) {
	$strope_translate->bulk_translate = new Strope_Bulk_Translate( $strope_translate->model );
	add_action( 'current_screen', array( $strope_translate->bulk_translate, 'init' ) );
}
