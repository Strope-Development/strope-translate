<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

$strope_translate->locale_fallback = new Strope_Locale_Fallback();
add_action( 'strope_init', array( $strope_translate->locale_fallback, 'init' ) );
