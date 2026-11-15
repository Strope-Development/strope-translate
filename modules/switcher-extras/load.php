<?php
/**
 * Loads the language switcher extras: floating language switcher, language switcher shortcode
 * and "also available in" notice.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( ! $strope_translate->model->has_languages() ) {
	return;
}

add_filter(
	'strope_settings_modules',
	function ( $modules ) {
		$modules[] = 'Strope_Settings_Floating_Switcher';
		$modules[] = 'Strope_Settings_Also_Available';
		return $modules;
	}
);

$strope_translate->switcher_shortcode = ( new Strope_Switcher_Shortcode() )->init();

if ( $strope_translate instanceof Strope_Frontend ) {
	if ( ! empty( $strope_translate->options['floating_switcher'] ) ) {
		$strope_translate->floating_switcher = ( new Strope_Floating_Switcher( $strope_translate ) )->init();
	}

	if ( ! empty( $strope_translate->options['also_available'] ) ) {
		$strope_translate->also_available_notice = ( new Strope_Also_Available_Notice( $strope_translate ) )->init();
	}
}

if ( $strope_translate instanceof Strope_Admin_Base && ! empty( $strope_translate->options['also_available'] ) ) {
	// Allows to translate the custom text of the notice in the strings translations table.
	add_action(
		'admin_init',
		function () {
			$settings = Strope_Also_Available_Notice::get_settings( Strope()->options );
			if ( '' !== $settings['text'] ) {
				strope_register_string( __( '"Also available in" notice', 'strope-translate' ), $settings['text'], 'Strope Translate', true );
			}
		}
	);
}
