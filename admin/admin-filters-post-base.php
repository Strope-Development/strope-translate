<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Some common code for Strope_Admin_Filters_Post and Strope_Admin_Filters_Media
 *
 * @since 1.5
 */
abstract class Strope_Admin_Filters_Post_Base {
	/**
	 * @var Strope_Model
	 */
	public $model;

	/**
	 * @var Strope_Links|null
	 */
	public $links;

	/**
	 * Language selected in the admin language filter.
	 *
	 * @var Strope_Language|null
	 */
	public $filter_lang;

	/**
	 * Preferred language to assign to new contents.
	 *
	 * @var Strope_Language|null
	 */
	public $pref_lang;

	/**
	 * Constructor: setups filters and actions
	 *
	 * @since 1.2
	 *
	 * @param object $strope_translate The Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->links = &$strope_translate->links;
		$this->model = &$strope_translate->model;
		$this->pref_lang = &$strope_translate->pref_lang;
	}

	/**
	 * Save translations from the languages metabox.
	 *
	 * @since 1.5
	 *
	 * @param int   $post_id Post id of the post being saved.
	 * @param int[] $arr     An array with language codes as key and post id as value.
	 * @return int[] The array of translated post ids.
	 */
	protected function save_translations( $post_id, $arr ) {
		// Security check as 'wp_insert_post' can be called from outside WP admin.
		check_admin_referer( 'strope_language', '_strope_nonce' );

		$translations = $this->model->post->save_translations( $post_id, $this->model->post->filter_editable_translations( (int) $post_id, $arr ) );
		return $translations;
	}
}
