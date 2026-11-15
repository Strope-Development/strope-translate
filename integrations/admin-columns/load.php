<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'after_setup_theme',
	function () {
		if ( did_action( 'strope_init' ) && ( defined( 'AC_FILE' ) || defined( 'ACP_FILE' ) ) ) {
			add_action( 'admin_init', array( Strope_Integrations::instance()->cpac = new Strope_CPAC(), 'init' ) );
		}
	}
);
