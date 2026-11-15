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
		if ( function_exists( 'custom_post_widget_plugin_init' ) ) {
			add_action( 'strope_init', array( Strope_Integrations::instance()->content_blocks = new Strope_Content_Blocks(), 'init' ) );
		}
	},
	0
);
