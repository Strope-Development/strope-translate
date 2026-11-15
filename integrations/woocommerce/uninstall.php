<?php
/**
 * Removes the WooCommerce integration data when uninstalling with all data removal.
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'strope_woocommerce' );

// Customers preferred language.
delete_metadata( 'user', 0, 'strope_language', '', true );

// Orders language, stored as post metas or in the WooCommerce orders meta table (HPOS).
delete_metadata( 'post', 0, '_strope_language', '', true );

$strope_orders_meta = $wpdb->prefix . 'wc_orders_meta';

if ( $strope_orders_meta === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $strope_orders_meta ) ) ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$strope_orders_meta} WHERE meta_key = %s", '_strope_language' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
