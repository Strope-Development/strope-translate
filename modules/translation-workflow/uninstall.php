<?php
/**
 * Removes the translation workflow data when uninstalling with all data removal.
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Translation jobs and their metas.
$strope_job_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'strope_job' ) );

foreach ( $strope_job_ids as $strope_job_id ) {
	wp_delete_post( (int) $strope_job_id, true );
}

foreach ( array( '_strope_translation_job', '_strope_translation_of', '_strope_job_translation' ) as $strope_meta_key ) {
	delete_metadata( 'post', 0, $strope_meta_key, '', true );
}

// Translation languages, stored per site (the uninstall runs for each site).
delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'strope_translator_languages', '', true );

wp_clear_scheduled_hook( 'strope_translation_jobs_digest' );

remove_role( 'strope_translator' );
