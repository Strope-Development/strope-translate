<?php
/**
 * Loads the integration with WordPress MU Domain Mapping.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action( 'init', array( Strope_Integrations::instance()->twenty_seventeen = new Strope_Twenty_Seventeen(), 'init' ) );
