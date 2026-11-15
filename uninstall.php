<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { // If uninstall not called from WordPress exit
	exit;
}

/**
 * Manages Strope Translate uninstallation
 * The goal is to remove ALL Strope Translate related data in db
 *
 * @since 0.5
 */
class Strope_Uninstall {

	/**
	 * Constructor: manages uninstall for multisite
	 *
	 * @since 0.5
	 */
	public function __construct() {
		global $wpdb;

		// Don't do anything except if the constant STROPE_REMOVE_ALL_DATA is explicitly defined and true.
		if ( ! defined( 'STROPE_REMOVE_ALL_DATA' ) || ! STROPE_REMOVE_ALL_DATA ) {
			return;
		}

		// Check if it is a multisite uninstall - if so, run the uninstall function for each blog id
		if ( is_multisite() ) {
			foreach ( $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" ) as $blog_id ) {
				switch_to_blog( $blog_id );
				$this->uninstall();
			}
			restore_current_blog();
		}
		else {
			$this->uninstall();
		}
	}

	/**
	 * Removes ALL plugin data
	 * only when the relevant option is active
	 *
	 * @since 0.5
	 */
	public function uninstall() {
		global $wpdb;

		do_action( 'strope_uninstall' );

		// Need to register the taxonomies
		$strope_taxonomies = array( 'language', 'term_language', 'post_translations', 'term_translations' );
		foreach ( $strope_taxonomies as $taxonomy ) {
			register_taxonomy( $taxonomy, null, array( 'label' => false, 'public' => false, 'query_var' => false, 'rewrite' => false ) );
		}

		$languages = get_terms( array( 'taxonomy' => 'language', 'hide_empty' => false ) );

		// Delete users options
		delete_metadata( 'user', 0, 'strope_filter_content', '', true );
		delete_metadata( 'user', 0, 'strope_dismissed_notices', '', true ); // Legacy meta.
		foreach ( $languages as $lang ) {
			delete_metadata( 'user', 0, "description_{$lang->slug}", '', true );
		}

		// Delete menu language switchers
		$ids = get_posts(
			array(
				'post_type'   => 'nav_menu_item',
				'numberposts' => -1,
				'nopaging'    => true,
				'fields'      => 'ids',
				'meta_key'    => '_strope_menu_item',
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}

		/*
		 * Backward compatibility with Strope Translate < 3.4.
		 * Delete the legacy strings translations.
		 */
		register_post_type( 'strope_translate_mo', array( 'rewrite' => false, 'query_var' => false ) );
		$ids = get_posts(
			array(
				'post_type'   => 'strope_translate_mo',
				'post_status' => 'any',
				'numberposts' => -1,
				'nopaging'    => true,
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}

		// Delete all what is related to languages and translations
		$term_ids = array();
		$tt_ids   = array();

		foreach ( get_terms( array( 'taxonomy' => $strope_taxonomies, 'hide_empty' => false ) ) as $term ) {
			$term_ids[] = (int) $term->term_id;
			$tt_ids[] = (int) $term->term_taxonomy_id;
		}

		if ( ! empty( $term_ids ) ) {
			$term_ids = array_unique( $term_ids );
			$wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id IN ( " . implode( ',', $term_ids ) . ' )' ); // PHPCS:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_id IN ( " . implode( ',', $term_ids ) . ' )' ); // PHPCS:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ( " . implode( ',', $term_ids ) . " ) AND meta_key='_strope_strings_translations'" ); // PHPCS:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( ! empty( $tt_ids ) ) {
			$tt_ids = array_unique( $tt_ids );
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ( " . implode( ',', $tt_ids ) . ' )' ); // PHPCS:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Delete options
		delete_option( 'strope_translate' );
		delete_option( 'strope_translate_imported' );
		delete_option( 'strope_translate_import_progress' );
		delete_option( 'strope_translate_import_lock' );
		delete_option( 'widget_strope_translate' ); // Automatically created by WP
		delete_option( 'strope_translate_wpml_strings' ); // Strings registered with icl_register_string
		delete_option( 'strope_dismissed_notices' );

		// Delete transients
		delete_transient( 'strope_languages_list' );
		delete_transient( 'strope_activation_redirect' );
		delete_transient( 'strope_domain_check_token' );
	}
}

add_action(
	'strope_uninstall',
	function () {
		// Executes each module's uninstall script, if it exists.
		foreach ( array_merge( (array) glob( __DIR__ . '/modules/*/uninstall.php', GLOB_NOSORT ), (array) glob( __DIR__ . '/integrations/*/uninstall.php', GLOB_NOSORT ) ) as $uninstall_script ) {
			require $uninstall_script; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		}
	}
);

new Strope_Uninstall();
