<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class to handle strings translations import.
 *
 * @since 3.3
 */
class Strope_Import_Strings implements Strope_Import_Object_Interface {

	/**
	 * The success counter.
	 *
	 * @var int
	 */
	protected $success;

	/**
	 * The imported source string.
	 *
	 * @var string[]
	 */
	protected $imported_strings = array();

	/**
	 * Messages about the translations not imported because their placeholders don't match the source strings.
	 *
	 * @since 4.1.0
	 *
	 * @var string[]
	 */
	protected $invalid_placeholders = array();

	/**
	 * Handles the import of strings translations.
	 *
	 * @since 3.3
	 *
	 * @param array        $entry {
	 *     An array containing the translations data.
	 *     @type string       $type Either 'post', 'term' or 'string_translations'.
	 *     @type int          $id   Id of the object in the database (if applicable).
	 *     @type Translations $data Objects holding all the retrieved Translation_Entry objects.
	 * }
	 * @param Strope_Language $target_language The targeted language for import.
	 */
	public function translate( $entry, $target_language ) {
		$strope_mo = new Strope_MO();
		$strope_mo->import_from_db( $target_language );
		$registered_strings = Strope_Admin_Strings::get_strings();

		$translations = $entry['data'];

		// Clone the $strope_mo element to avoid modifying the original one since we will then update it.
		$strope_mo_clone = clone $strope_mo;

		// Remove the context for the translation entries to generate the same key between the translation strings
		// and the database strings.
		$translations->entries = $this->remove_context_from_translations( $translations->entries );

		foreach ( $translations->entries as $entry ) {
			if ( empty( $entry->translations ) ) {
				$entry->translations = array( '' );
			}

			/** This filter is documented in /strope-translate/settings/table-string.php */
			$sanitized_translation = apply_filters( 'strope_sanitize_string_translation', $entry->translations[0], $entry->extracted_comments, $entry->context );
			$sanitized_translation = wp_kses_post( $sanitized_translation );

			// Since 4.1.0, a translation whose printf placeholders don't match the source string is not imported.
			if ( '' !== (string) $sanitized_translation ) {
				$valid = Strope_String_Placeholders::validate( (string) $sanitized_translation, (string) $entry->singular );
				if ( true !== $valid ) {
					if ( is_wp_error( $valid ) ) {
						$this->invalid_placeholders[] = $valid->get_error_message();
					}
					continue;
				}
			}

			// Set a unique key for each entry to compare the original and translated strings.
			$key = $entry->key();

			if ( empty( $key ) ) {
				continue;
			}
			if ( isset( $strope_mo_clone->entries[ $key ]->translations[0] ) || isset( $registered_strings[ md5( $key ) ] ) ) {
				// Checks that the string did not exist or has been edited before updating.
				if ( ! isset( $strope_mo_clone->entries[ $key ]->translations[0] ) || $strope_mo_clone->entries[ $key ]->translations[0] !== $sanitized_translation ) {
					$strope_mo->add_entry( $strope_mo->make_entry( $entry->singular, $sanitized_translation ) );
					++$this->success;

					// Store the source strings as ids during the import process.
					$this->imported_strings[] = $entry->singular;
				}
			}
		}

		if ( $this->success ) {
			$strope_mo->export_to_db( $target_language );
		}
	}

	/**
	 * Removes the context for the translation entries.
	 *
	 * @since 3.2
	 * @since 3.3 Moved from Strope_Import_Action to Strope_Import_Strings.
	 *
	 * @param  Translation_Entry[] $translations An array with all the entries.
	 * @return Translation_Entry[]               An array with the same entries with an empty context.
	 */
	private function remove_context_from_translations( $translations ) {
		foreach ( $translations as $translation_entry ) {
			$translation_entry->context = '';
		}
		return $translations;
	}

	/**
	 * Gets update notices to display.
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
			'strope_import_strings_success',
			sprintf(
				/* translators: %d is a number of strings translations */
				_n( '%d string translation updated.', '%d string translations updated.', $this->success, 'strope-translate' ),
				$this->success
			),
			'success'
		);
	}

	/**
	 * Gets warnings notices to display.
	 *
	 * @since 3.3
	 * @since 4.1.0 Lists the translations not imported because of mismatched placeholders.
	 *
	 * @return WP_Error
	 */
	public function get_warning_notice() {
		$error = new WP_Error();

		foreach ( array_unique( $this->invalid_placeholders ) as $message ) {
			$error->add( 'strope_import_strings_placeholders', esc_html( $message ), 'warning' );
		}

		return $error;
	}

	/**
	 * Returns the object type.
	 *
	 * @since 3.3
	 *
	 * @return string
	 */
	public function get_type() {
		return Strope_Import_Export::STRINGS_TRANSLATIONS;
	}

	/**
	 * Returns the imported source strings as ids.
	 *
	 * @since 3.3
	 *
	 * @return string[]
	 */
	public function get_imported_object_ids() {
		return $this->imported_strings;
	}
}
