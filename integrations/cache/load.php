<?php
/**
 * Loads the integration with cache plugins.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'plugins_loaded',
	function () {
		if ( strope_is_cache_active() ) {
			add_action( 'strope_init', array( Strope_Integrations::instance()->cache_compat = new Strope_Cache_Compat(), 'init' ) );
		}
	},
	0
);
