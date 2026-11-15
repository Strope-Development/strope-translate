<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'strope_init',
	function ( $strope_translate ) {
		$strope_translate->rest_api = new Strope_REST_API( $strope_translate );
	}
);
