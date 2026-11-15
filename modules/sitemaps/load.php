<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( $strope_translate->model->has_languages() ) {
	if ( $strope_translate->links_model instanceof Strope_Links_Abstract_Domain ) {
		$strope_translate->sitemaps = new Strope_Sitemaps_Domain( $strope_translate );
	} else {
		$strope_translate->sitemaps = new Strope_Sitemaps( $strope_translate );
	}
	$strope_translate->sitemaps->init();
}
