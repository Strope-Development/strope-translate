<?php
/**
 * Loads the setup wizard.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly
}

if ( $strope_translate instanceof Strope_Admin_Base ) {
	$strope_translate->wizard = new Strope_Wizard( $strope_translate );

	if ( Strope_Translate::is_wizard() ) {
		add_action(
			'admin_init',
			function () use ( $strope_translate ) {
				$strope_translate->wizard_home_page = new Strope_Wizard_Home_Page( $strope_translate );
			},
			30
		);
	}
}
