<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages links related functions
 *
 * @since 1.2
 */
class Strope_Links {
	/**
	 * Stores the plugin options.
	 *
	 * @var array
	 */
	public $options;

	/**
	 * @var Strope_Model
	 */
	public $model;

	/**
	 * Instance of a child class of Strope_Links_Model.
	 *
	 * @var Strope_Links_Model
	 */
	public $links_model;

	/**
	 * Current language (used to filter the content).
	 *
	 * @var Strope_Language|null
	 */
	public $curlang;

	/**
	 * Constructor
	 *
	 * @since 1.2
	 *
	 * @param object $strope_translate The Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->links_model = &$strope_translate->links_model;
		$this->model = &$strope_translate->model;
		$this->options = &$strope_translate->options;
	}

	/**
	 * Returns the home url in the requested language.
	 *
	 * @since 1.3
	 *
	 * @param Strope_Language|string $language  The language.
	 * @param bool                $is_search Optional, whether we need the home url for a search form, defaults to false.
	 * @return string
	 */
	public function get_home_url( $language, $is_search = false ) {
		if ( ! $language instanceof Strope_Language ) {
			$language = $this->model->get_language( $language );
		}

		if ( empty( $language ) ) {
			return home_url( '/' );
		}

		return $is_search ? $language->get_search_url() : $language->get_home_url();
	}
}
