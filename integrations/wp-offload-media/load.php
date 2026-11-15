<?php
/**
 * Loads the integration with WP Offload Media Lite.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'plugins_loaded',
	function () {
		if ( function_exists( 'as3cf_init' ) && class_exists( 'Strope_AS3CF' ) ) {
			add_action( 'strope_init', array( Strope_Integrations::instance()->as3cf = new Strope_AS3CF(), 'init' ) );
		}
	},
	0
);
