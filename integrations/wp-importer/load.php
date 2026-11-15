<?php
/**
 * Loads the integration with WordPress Importer.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

Strope_Integrations::instance()->wp_importer = new Strope_WordPress_Importer();
