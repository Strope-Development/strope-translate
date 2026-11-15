<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class to handle terms import.
 *
 * @since 3.3
 */
class Strope_Import_Terms implements Strope_Import_Object_Interface {
	/**
	 * Handle translation of terms
	 *
	 * @var Strope_Translation_Term_Model
	 */
	private $translation_term_model;

	/**
	 * The success counter.
	 *
	 * @var int
	 */
	protected $success;

	/**
	 * The imported source term ids.
	 *
	 * @var int[]
	 */
	protected $term_ids = array();

	/**
	 * Ids of the terms skipped because the user is not allowed to translate them.
	 *
	 * @since 4.3.0
	 *
	 * @var int[]
	 */
	protected $forbidden_term_ids = array();

	/**
	 * Constructor.
	 *
	 * @since 3.3
	 *
	 * @param Strope_Translation_Term_Model $translation_term_model The Strope_Translation_Term_Model object.
	 */
	public function __construct( $translation_term_model ) {
		$this->translation_term_model = $translation_term_model;

		add_action( 'strope_after_term_import', array( $this, 'process_translated_terms' ), 10, 2 );
	}

	/**
	 * Handles the import of terms.
	 *
	 * @since 3.3
	 *
	 * @param array        $entry           The current entry to import.
	 * @param Strope_Language $target_language The targeted language for import.
	 */
	public function translate( $entry, $target_language ) {
		// The file decides which terms are translated: the user must be allowed to edit each of them.
		$tr_id = (int) Strope()->model->term->get( (int) $entry['id'], $target_language );

		if ( ! current_user_can( 'edit_term', (int) $entry['id'] ) || ( $tr_id && ! current_user_can( 'edit_term', $tr_id ) ) ) {
			$this->forbidden_term_ids[] = (int) $entry['id'];
			return;
		}

		$is_success = $this->translation_term_model->translate( $entry, $target_language );
		if ( $is_success ) {
			++$this->success;

			// Store the term ids during the import process.
			$this->term_ids[] = $entry['id'];
		}
	}

	/**
	 * Performs actions on imported terms.
	 * Translates terms parent.
	 *
	 * @since 3.3
	 *
	 * @param Strope_Language $target_language The targeted language for import.
	 * @param int[]        $term_ids        The imported term ids of the import.
	 * @return void
	 */
	public function process_translated_terms( $target_language, $term_ids ) {
		$term_ids = array_filter( array_map( 'absint', (array) $term_ids ) );
		if ( ! empty( $term_ids ) && $target_language instanceof Strope_Language ) {
			$this->translation_term_model->translate_parents( $term_ids, $target_language );
		}
	}

	/**
	 * Get update notices to display.
	 *
	 * @since 3.3
	 *
	 * @return WP_Error
	 */
	public function get_updated_notice() {
		if ( ! $this->success ) {
			return new WP_Error();
		}

		return new WP_Error(
			'strope_import_terms_success',
			sprintf(
				/* translators: %d is a number of terms translations */
				_n( '%d term translation updated.', '%d terms translations updated.', $this->success, 'strope-translate' ),
				$this->success
			),
			'success'
		);
	}

	/**
	 * Get warnings notices to display.
	 *
	 * @since 3.3
	 *
	 * @return WP_Error
	 */
	public function get_warning_notice() {
		if ( empty( $this->forbidden_term_ids ) ) {
			return new WP_Error();
		}

		return new WP_Error(
			'strope_import_terms_forbidden',
			sprintf(
				/* translators: %s is the term IDs */
				_n(
					'Warning: you are not allowed to translate the term ID: %s',
					'Warning: you are not allowed to translate the term IDs: %s',
					count( $this->forbidden_term_ids ),
					'strope-translate'
				),
				wp_sprintf_l( '%l', $this->forbidden_term_ids )
			),
			'warning'
		);
	}

	/**
	 * Returns the object type.
	 *
	 * @since 3.3
	 *
	 * @return string
	 */
	public function get_type() {
		return Strope_Import_Export::TYPE_TERM;
	}

	/**
	 * Returns the imported term ids.
	 *
	 * @since 3.3
	 *
	 * @return int[]
	 */
	public function get_imported_object_ids() {
		return $this->term_ids;
	}
}
