<?php
/**
 * Removes the sticky links data when uninstalling with all data removal.
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( '_strope_links', '_strope_link_target', '_strope_broken_links', '_strope_links_pending' ) as $strope_meta_key ) {
	delete_metadata( 'post', 0, $strope_meta_key, '', true );
}

delete_option( 'strope_sticky_links_scan' );
delete_option( 'strope_sticky_links_lock' );
wp_clear_scheduled_hook( 'strope_sticky_links_process' );
