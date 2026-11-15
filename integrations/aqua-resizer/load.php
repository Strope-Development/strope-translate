<?php
/**
 * Loads the integration with Aqua Resizer.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

Strope_Integrations::instance()->aq_resizer = new Strope_Aqua_Resizer();
Strope_Integrations::instance()->aq_resizer->init();
