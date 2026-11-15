<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Strope_Translation_Term_Metas
 *
 * @since 3.3
 *
 * Translate term metas from a set of translation entries.
 */
class Strope_Translation_Term_Metas extends Strope_Translation_Metas {

	/**
	 * The Strope_Translation_Term_Metas constructor that allows to define the meta type.
	 *
	 * @since 3.3
	 *
	 * @param Strope_Sync_Term_Metas $sync_metas Object to manage copied term metas during import.
	 */
	public function __construct( Strope_Sync_Term_Metas $sync_metas ) {
		parent::__construct( $sync_metas );

		$this->meta_type = 'term';
		$this->context   = Strope_Import_Export::TERM_META;
	}
}
