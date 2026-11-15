<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface to use for modules.
 *
 * @since 3.2
 */
interface Strope_Module_Interface {

	/**
	 * Returns the module's name.
	 *
	 * @since 3.2
	 *
	 * @return string
	 */
	public static function get_name();

	/**
	 * Module init.
	 *
	 * @since 3.2
	 *
	 * @return self
	 */
	public function init();
}
