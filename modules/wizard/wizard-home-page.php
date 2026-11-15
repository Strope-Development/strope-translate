<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main class for Strope Translate wizard.
 *
 * @since 2.7
 */
class Strope_Wizard_Home_Page {

	/**
	 * @var Strope_Model
	 */
	public $model;

	/**
	 * @var Strope_Sync_Post_Model
	 */
	protected $sync_model;

	/**
	 * Constructor
	 *
	 * @since 2.7
	 *
	 * @param object $strope_translate Reference to Strope Translate options array.
	 */
	public function __construct( &$strope_translate ) {
		$this->model      = &$strope_translate->model;
		$this->sync_model = &$strope_translate->sync_post_model;

		// See strope_wizard_create_home_page_translations filter in Strope_Wizard class.
		add_filter( 'strope_wizard_create_home_page_translations', array( $this, 'replace_create_home_page_translations' ) );
	}

	/**
	 * Replace function to apply to process the home page transations creation.
	 *
	 * @since 2.7
	 *
	 * @return callable
	 */
	public function replace_create_home_page_translations() {
		return array( $this, 'create_home_page_translations' );
	}

	/**
	 * Create home page translations for each language defined with duplicating content.
	 *
	 * @since 2.7
	 *
	 * @param string $default_language       slug of the default language; null if no default language is defined.
	 * @param int    $home_page              post_id of the home page if it's defined, false otherwise.
	 * @param string $home_page_title        home page title if it's defined, 'Homepage' otherwise.
	 * @param string $home_page_language     slug of the home page if it's defined, false otherwise.
	 * @param array  $untranslated_languages array of languages which needs to have a home page translated.
	 * @return void
	 */
	public function create_home_page_translations( $default_language, $home_page, $home_page_title, $home_page_language, $untranslated_languages ) {
		global $wpdb;

		$home_page = (int) $home_page;

		if ( $home_page <= 0 || 'page' !== get_post_type( $home_page ) ) {
			return;
		}

		foreach ( $untranslated_languages as $language ) {
			$language_properties = $this->model->get_language( $language );

			// Only into a language without home page yet, for a page the user can edit.
			if ( empty( $language_properties ) || $this->model->post->get( $home_page, $language_properties ) || ! $this->sync_model->current_user_can_synchronize( $home_page, $language ) ) {
				continue;
			}

			$translated_post_id = (int) $this->sync_model->copy_post( $home_page, $language, false );

			if ( $translated_post_id <= 0 ) {
				continue;
			}

			$wpdb->update(
				$wpdb->posts,
				array( 'post_title' => $home_page_title . ' - ' . $language_properties->name ),
				array( 'ID' => $translated_post_id )
			); // Don't use wp_update_post to ensure not redo save post process.
		}
	}
}
