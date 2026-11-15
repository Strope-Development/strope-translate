<?php
/**
 * Loads the integration with No Category Base.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

Strope_Integrations::instance()->no_category_base = new Strope_No_Category_Base();
Strope_Integrations::instance()->no_category_base->init();
