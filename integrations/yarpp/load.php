<?php
/**
 * Loads the integration with Yet Another Related Posts Plugin.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'plugins_loaded',
	function () {
		if ( defined( 'YARPP_VERSION' ) ) {
			add_action( 'init', array( Strope_Integrations::instance()->yarpp = new Strope_Yarpp(), 'init' ) );
		}
	},
	0
);
