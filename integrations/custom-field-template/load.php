<?php
/**
 * Loads the integration with Custom Field Template.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'custom_field_template' ) ) {
			Strope_Integrations::instance()->cft = new Strope_Cft();
			Strope_Integrations::instance()->cft->init();
		}
	},
	0
);
