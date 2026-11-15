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
		if ( class_exists( 'FLBuilderLoader' ) ) {
			Strope_Integrations::instance()->flbuilder = new Strope_FLBuilder();
		}
	}
);
