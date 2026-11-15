<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

use Strope\Translate\Modules\Import_Export\Services\Context;

/**
 * Collects the translatable fields of an object with the exporters of the XLIFF/PO export, so that machine
 * translation covers exactly what a translation agency would receive: title, content (per block), excerpt,
 * and the custom fields declared as translatable (wpml-config.xml, ACF...).
 *
 * @since 4.2.0
 */
class Strope_MT_Export_Collector extends Strope_Export_Data {
	/**
	 * Collected entries.
	 *
	 * @var array[] Each with keys `context` and `source`.
	 */
	protected $entries = array();

	/**
	 * Adds a source string, see `Strope_Export_Data::add_translation_entry()`.
	 *
	 * @since 4.2.0
	 *
	 * @param array  $ref    Reference of the field.
	 * @param string $source Source string.
	 * @param string $target Existing translation (ignored).
	 * @return void
	 */
	public function add_translation_entry( array $ref, string $source, string $target = '' ) {
		if ( ! $this->are_entry_parameters_valid( $ref, $source ) ) {
			return;
		}

		// Same context as an entry read back from an XLIFF file, see `Strope_Xliff_Import_Parser_Base`.
		$context = array( Context::FIELD => (string) $ref['field_type'] );

		if ( ! empty( $ref['field_id'] ) && Strope_Import_Export::POST_CONTENT !== $ref['field_type'] ) {
			$context[ Context::ID ] = (string) $ref['field_id'];
		}

		if ( ! empty( $ref['encoding'] ) ) {
			$context[ Context::ENCODING ] = (string) $ref['encoding'];
		}

		$context = Context::to_string( $context );
		$key     = $context . "\4" . $source;

		$this->entries[ $key ] = array(
			'context' => $context,
			'source'  => $source,
		);
	}

	/**
	 * Returns the collected entries.
	 *
	 * @since 4.2.0
	 *
	 * @return array[]
	 */
	public function get() {
		return array_values( $this->entries );
	}

	/**
	 * Collects the entries of a post.
	 *
	 * @since 4.2.0
	 *
	 * @param WP_Post         $post   Source post.
	 * @param Strope_Language $source Source language.
	 * @param Strope_Language $target Target language.
	 * @return array[] Entries with keys `context` and `source`.
	 */
	public static function collect_post( WP_Post $post, $source, $target ): array {
		$collector = new self( $source, $target );
		$exporter  = new Strope_Export_Posts( Strope()->model->post );

		$exporter->add_item( $collector, $post );

		return $collector->get();
	}
}
