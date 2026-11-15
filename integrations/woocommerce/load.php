<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/*
 * Declares the compatibility of the plugin with WooCommerce features
 * (High-Performance Order Storage and cart & checkout blocks).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', STROPE_TRANSLATE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', STROPE_TRANSLATE_FILE, true );
		}
	}
);

/*
 * Loads the integration only when WooCommerce is active.
 * Hooked before Strope Translate is initialized (priority 1) so that the default
 * translated post types and taxonomies are known before they are cached.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! defined( 'WC_PLUGIN_FILE' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		if ( ! defined( 'STROPE_WOOCOMMERCE' ) || STROPE_WOOCOMMERCE ) {
			Strope_Integrations::instance()->woocommerce = new Strope_WooCommerce();
		}
	},
	0
);
