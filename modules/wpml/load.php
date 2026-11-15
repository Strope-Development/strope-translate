<?php
/**
 * Loads the WPML compatibility mode.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( $strope_translate->model->has_languages() ) {
	if ( ! defined( 'STROPE_WPML_COMPAT' ) || STROPE_WPML_COMPAT ) {
		Strope_WPML_Compat::instance(); // WPML API.
		Strope_WPML_Config::instance(); // wpml-config.xml.
	}
}
