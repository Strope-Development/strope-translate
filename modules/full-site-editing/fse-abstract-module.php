<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Abstract class for FSE modules.
 *
 * @since 3.2
 */
abstract class Strope_FSE_Abstract_Module {

	/**
	 * Instance of `Strope_Model`.
	 *
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * Plugin's options.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Constructor.
	 *
	 * @since 3.2
	 *
	 * @param  Strope_Base $strope_translate Instance of the main Strope Translate object, passed by reference.
	 * @return void
	 */
	public function __construct( Strope_Base &$strope_translate ) {
		$this->model   = &$strope_translate->model;
		$this->options = &$strope_translate->options;
	}

	/**
	 * Returns the list of the slugs of enabled languages.
	 *
	 * @since 1.0
	 *
	 * @return string[]
	 */
	protected function get_languages_slugs() {
		return $this->model->get_languages_list( array( 'fields' => 'slug' ) );
	}
}
