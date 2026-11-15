<?php
/**
 * Removes the translation status data when uninstalling with all data removal.
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( '_strope_content_hash', '_strope_source', '_strope_needs_review', '_strope_outdated', '_strope_word_count' ) as $strope_meta_key ) {
	delete_metadata( 'post', 0, $strope_meta_key, '', true );
}

// Word counts.
delete_option( 'strope_word_count_queue' );
wp_clear_scheduled_hook( 'strope_word_count_backfill' );
