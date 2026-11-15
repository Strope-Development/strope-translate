<?php
/**
 * Loads the per-block language visibility in content shared by all languages.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'strope_init',
	function ( $strope_translate ) {
		if ( $strope_translate->model->has_languages() ) {
			$strope_translate->block_visibility = ( new Strope_Block_Visibility( $strope_translate ) )->init();
		}
	}
);
