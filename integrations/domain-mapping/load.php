<?php
/**
 * Loads the integration with WordPress MU Domain Mapping.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

Strope_Integrations::instance()->dm = new Strope_Domain_Mapping();
