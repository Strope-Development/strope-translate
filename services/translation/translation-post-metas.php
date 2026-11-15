<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Strope_Translation_Post_Metas
 *
 * @since 3.3
 *
 * Translate post metas from a set of translation entries.
 */
class Strope_Translation_Post_Metas extends Strope_Translation_Metas {

	/**
	 * The Strope_Translation_Post_Metas constructor that allows to define the meta type.
	 *
	 * @since 3.3
	 *
	 * @param Strope_Sync_Post_Metas $sync_metas Object to manage copied post metas during import.
	 */
	public function __construct( Strope_Sync_Post_Metas $sync_metas ) {
		parent::__construct( $sync_metas );

		$this->meta_type = 'post';
		$this->context   = Strope_Import_Export::POST_META;
	}
}
