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
		/**
		 * This must be checked only after the theme is loaded (not earlier than 'after_setup_theme') because some
		 * themes include ACF.
		 */
		if ( ! defined( 'ACF_VERSION' ) || version_compare( ACF_VERSION, '5.7.11', '<' ) ) {
			// Run only for ACF >= 5.7.11.
			return;
		}

		if ( ! did_action( 'strope_init' ) || ! Strope()->model->has_languages() ) {
			// Run only if Strope Translate (and its API) is loaded, and if there is at least one language.
			return;
		}

		add_action( 'init', array( Strope_Integrations::instance()->acf = new Strope_ACF(), 'init' ) );
	}
);
