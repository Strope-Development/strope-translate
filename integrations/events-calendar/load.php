<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'plugins_loaded',
	function () {
		if ( defined( 'TRIBE_EVENTS_FILE' ) ) {
			add_action( 'strope_init', array( Strope_Integrations::instance()->tec = new Strope_TEC(), 'init' ) );
		}
	},
	0
);
