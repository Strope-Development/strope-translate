<?php
/**
 * Loads the integration with Duplicate Post.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'plugins_loaded',
	function () {
		if ( defined( 'DUPLICATE_POST_CURRENT_VERSION' ) ) {
			Strope_Integrations::instance()->duplicate_post = new Strope_Duplicate_Post();
			Strope_Integrations::instance()->duplicate_post->init();
		}
	},
	0
);
