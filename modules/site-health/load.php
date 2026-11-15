<?php
/**
 * Loads the site health.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( $strope_translate instanceof Strope_Admin && $strope_translate->model->has_languages() ) {
	$strope_translate->site_health = new Strope_Admin_Site_Health( $strope_translate );
}
