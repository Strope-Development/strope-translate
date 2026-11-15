<?php
/**
 * Removes the theme and plugin strings data when uninstalling with all data removal.
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}strope_string_translations" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}strope_strings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

foreach ( array( 'strope_string_discovery_db_version', 'strope_string_overrides', 'strope_string_discovery_components', 'strope_string_discovery_job', 'strope_string_discovery_lock' ) as $strope_option ) {
	delete_option( $strope_option );
}

// Compiled overrides, one option per language.
$strope_options = $wpdb->get_col(
	$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'strope_string_overrides_' ) . '%' )
);

foreach ( $strope_options as $strope_option ) {
	delete_option( $strope_option );
}
