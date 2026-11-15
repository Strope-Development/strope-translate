<?php
/**
 * Loads the integration with Yoast SEO.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'plugins_loaded',
	function () {
		if ( defined( 'WPSEO_VERSION' ) ) {
			add_action( 'strope_init', array( Strope_Integrations::instance()->wpseo = new Strope_WPSEO(), 'init' ) );
		}
	},
	0
);
