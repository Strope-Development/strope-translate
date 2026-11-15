<?php
/**
 * Loads the integration with WP Sweep.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'plugins_loaded',
	function () {
		if ( defined( 'WP_SWEEP_VERSION' ) ) {
			Strope_Integrations::instance()->wp_sweep = new Strope_WP_Sweep();
			Strope_Integrations::instance()->wp_sweep->init();
		}
	},
	0
);
