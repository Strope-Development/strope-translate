<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class handling multiple or single terms export.
 *
 * @since 3.3
 */
class Strope_Export_Terms extends Strope_Export_Translated_Objects {
	/**
	 * Term Metas
	 *
	 * @var Strope_Export_Term_Metas
	 */
	private $term_metas;

	/**
	 * Constructor.
	 *
	 * @since 3.3
	 * @since 3.6 Accepts now an instance of `Strope_Translated_Term`.
	 *
	 * @param Strope_Translated_Term $translated_object Term translation object.
	 */
	public function __construct( Strope_Translated_Term $translated_object ) {
		parent::__construct( $translated_object );

		$this->term_metas = new Strope_Export_Term_Metas();
	}

	/**
	 * Adds one term to export.
	 *
	 * @since 3.6
	 *
	 * @param Strope_Export_Data $export Export object.
	 * @param WP_Term         $item   Term to export.
	 *
	 * @return void
	 */
	public function add_item( Strope_Export_Data $export, $item ) {
		$tr_id   = $this->translated_object->get( $item->term_id, $export->get_target_language() );
		$tr_item = get_term( $tr_id );

		if ( '' !== $item->name ) {
			$export->add_translation_entry(
				array(
					'object_type' => Strope_Import_Export::TYPE_TERM,
					'field_type'  => Strope_Import_Export::TERM_NAME,
					'object_id'   => $item->term_id,
				),
				$item->name,
				$tr_item instanceof WP_Term ? $tr_item->name : ''
			);
		}

		if ( '' !== $item->description ) {
			$export->add_translation_entry(
				array(
					'object_type' => Strope_Import_Export::TYPE_TERM,
					'field_type'  => Strope_Import_Export::TERM_DESCRIPTION,
					'object_id'   => $item->term_id,
				),
				$item->description,
				$tr_item instanceof WP_Term ? $tr_item->description : ''
			);
		}

		$this->term_metas->export( $export, $item->term_id, $tr_item instanceof WP_Term ? $tr_item->term_id : 0 );
	}

	/**
	 * Caches terms to avoid too many SQL queries during export.
	 *
	 * @since 3.6
	 *
	 * @param int[] $ids Term IDs.
	 * @return void
	 *
	 * @phpstan-param non-empty-array<positive-int> $ids
	 */
	protected function add_to_cache( array $ids ) {
		_prime_term_caches( $ids );
	}

	/**
	 * Returns ID corresponding to the given term.
	 *
	 * @since 3.6
	 *
	 * @param WP_Term $item Term to get ID from.
	 * @return int Term ID.
	 */
	protected function get_item_id( $item ): int {
		return $item->term_id;
	}
}
