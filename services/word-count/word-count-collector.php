<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects the translatable texts of a post, as the XLIFF export and the machine translation see them,
 * to count their words.
 *
 * @since 4.3.0
 */
class Strope_Word_Count_Collector extends Strope_Export_Data {
	/**
	 * Source texts, keyed by field and text (a text repeated in the same field is counted once, like in an export).
	 *
	 * @var string[]
	 */
	protected $texts = array();

	/**
	 * Adds a translation entry.
	 *
	 * @since 4.3.0
	 *
	 * @param array  $ref    Reference of the field.
	 * @param string $source Source text.
	 * @param string $target Unused.
	 * @return void
	 */
	public function add_translation_entry( array $ref, string $source, string $target = '' ) {
		if ( ! $this->are_entry_parameters_valid( $ref, $source ) ) {
			return;
		}

		$key = (string) ( $ref['field_type'] ?? '' ) . "\4" . (string) ( $ref['field_id'] ?? '' ) . "\4" . $source;

		$this->texts[ $key ] = $source;
	}

	/**
	 * Returns the collected texts.
	 *
	 * @since 4.3.0
	 *
	 * @return string[]
	 */
	public function get() {
		return array_values( $this->texts );
	}
}
