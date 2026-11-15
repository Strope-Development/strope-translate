<?php
/**
 * Removes the machine translation data when uninstalling with all data removal.
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// The job queue: its tables, staged files, options, transients and scheduled tasks.
require_once __DIR__ . '/lib/wasm-worker/php/autoload.php';
( new Strope_Translate\MT\Worker\Kit( array( 'id' => 'strope-mt' ) ) )->uninstall();

// Runs and translation memory (jobs and segments: version 1 of the queue).
foreach ( array( 'runs', 'jobs', 'segments', 'memory' ) as $strope_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'strope_mt_' . $strope_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

delete_option( 'strope_mt_db_version' );
delete_option( 'strope_mt_index' );
delete_option( 'strope_mt_installed' );
delete_option( 'strope_mt_glossary' );
delete_metadata( 'post', 0, '_strope_mt', '', true );
delete_metadata( 'user', 0, 'strope_mt_background', '', true );
wp_clear_scheduled_hook( 'strope_mt_cleanup' );

// Downloaded engine and model files.
$strope_uploads = wp_get_upload_dir();
$strope_dir     = untrailingslashit( $strope_uploads['basedir'] ) . '/strope-translate/models';

foreach ( (array) glob( $strope_dir . '/*' ) as $strope_file ) {
	@unlink( (string) $strope_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
}

@rmdir( $strope_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
