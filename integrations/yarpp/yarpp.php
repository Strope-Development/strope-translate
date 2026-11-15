<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the compatibility with Yet Another Related Posts Plugin.
 *
 * @since 2.8
 */
class Strope_Yarpp {
	/**
	 * Just makes YARPP aware of the language taxonomy ( after Strope Translate registered it ).
	 *
	 * @since 1.0
	 */
	public function init() {
		$GLOBALS['wp_taxonomies']['language']->yarpp_support = 1;
	}
}
