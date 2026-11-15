<?php
/**
 * Loads the compatibility layer for themes and plugins written for Polylang.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_filter(
	'strope_settings_modules',
	function ( $modules ) {
		$modules[] = 'Strope_Settings_Legacy_API';
		return $modules;
	}
);

if ( ! empty( $strope_translate->options['legacy_api'] ) ) {
	Strope_Legacy_API::init( $strope_translate );
}
