<?php
/**
 * Loads the integration with Jetpack.
 * Works for Twenty Fourteen featured content too.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

Strope_Integrations::instance()->jetpack = new Strope_Jetpack(); // Must be loaded before the plugin is active.
add_action( 'strope_init', array( Strope_Integrations::instance()->featured_content = new Strope_Featured_Content(), 'init' ) );
