<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( STROPE_COOKIE && $strope_translate instanceof Strope_Frontend && $strope_translate->model->has_languages() ) {
	$class = array( 2 => 'Strope_Xdata_Subdomain', 3 => 'Strope_Xdata_Domain' );
	if ( isset( $class[ $strope_translate->options['force_lang'] ] ) ) {
		$strope_translate->xdata = new $class[ $strope_translate->options['force_lang'] ]( $strope_translate );
	}
}
