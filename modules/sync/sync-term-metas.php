<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class to manage the copy and synchronization of term metas.
 *
 * @since 2.3
 */
class Strope_Sync_Term_Metas extends Strope_Sync_Metas {

	/**
	 * Constructor.
	 *
	 * @since 2.3
	 *
	 * @param object $strope_translate The Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->meta_type = 'term';

		parent::__construct( $strope_translate );
	}
}
