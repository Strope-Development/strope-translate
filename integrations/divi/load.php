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
		if ( ( 'Divi' === get_template() || defined( 'ET_BUILDER_PLUGIN_VERSION' ) ) ) {
			Strope_Integrations::instance()->divi_builder = new Strope_Divi_Builder();
		}
	}
);
