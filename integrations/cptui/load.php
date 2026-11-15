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
		if ( defined( 'CPTUI_VERSION' ) ) {
			add_action( 'strope_init', array( Strope_Integrations::instance()->cptui = new Strope_CPTUI(), 'init' ) );
		}
	},
	0
);
