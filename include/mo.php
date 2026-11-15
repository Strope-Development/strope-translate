<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages strings translations storage
 *
 * @since 1.2
 * @since 2.1 Stores the strings in a post meta instead of post content to avoid unserialize issues (See #63)
 * @since 3.4 Stores the strings into language taxonomy term meta instead of a post meta.
 */
class Strope_MO extends MO {

	/**
	 * Writes the strings into a term meta.
	 *
	 * @since 1.2
	 *
	 * @param Strope_Language $lang The language in which we want to export strings.
	 * @return void
	 */
	public function export_to_db( $lang ) {
		/*
		 * It would be convenient to store the whole object, but it would take a huge space in DB.
		 * So let's keep only the strings in an array.
		 * The strings are slashed to avoid breaking slashed strings in update_term_meta.
		 * @see https://codex.wordpress.org/Function_Reference/update_post_meta#Character_Escaping.
		 */
		$strings = array();
		foreach ( $this->entries as $entry ) {
			// An empty translation means that the string is not translated: don't store it.
			if ( '' !== $entry->singular && '' !== $this->get_translation( $entry->singular ) ) {
				$strings[] = wp_slash( array( $entry->singular, $this->get_translation( $entry->singular ) ) );
			}
		}

		update_term_meta( $lang->term_id, '_strope_strings_translations', $strings );
	}

	/**
	 * Reads a Strope_MO object from the term meta.
	 *
	 * @since 1.2
	 * @since 3.4 Reads a Strope_MO from the term meta.
	 *
	 * @param Strope_Language $lang The language in which we want to get strings.
	 * @return void
	 */
	public function import_from_db( $lang ) {
		$this->set_header( 'Language', $lang->slug );

		$strings = get_term_meta( $lang->term_id, '_strope_strings_translations', true );
		if ( empty( $strings ) || ! is_array( $strings ) ) {
			return;
		}

		foreach ( $strings as $msg ) {
			if ( ! is_array( $msg ) || ! isset( $msg[0], $msg[1] ) || '' === $msg[0] || '' === $msg[1] ) {
				continue; // An empty translation means that the string is not translated.
			}

			$entry = $this->make_entry( $msg[0], $msg[1] );

			if ( '' !== $entry->singular ) {
				$this->add_entry( $entry );
			}
		}
	}

	/**
	 * Deletes a string
	 *
	 * @since 2.9
	 *
	 * @param string $string The source string to remove from the translations.
	 * @return void
	 */
	public function delete_entry( $string ) {
		unset( $this->entries[ $string ] );
	}

	/**
	 * Translates a string, falling back to the source string when the translation is empty
	 * or when its printf placeholders don't match the source string (it could break `sprintf()`).
	 *
	 * @since 4.1.0
	 *
	 * @param string      $singular The source string.
	 * @param string|null $context  Optional context.
	 * @return string
	 */
	public function translate( $singular, $context = null ) {
		$translation = parent::translate( $singular, $context );
		return '' === $translation || ! Strope_String_Placeholders::match( (string) $translation, (string) $singular ) ? $singular : $translation;
	}

	/**
	 * Returns the translation of a string, or an empty string if it is not translated.
	 * Contrary to `self::translate()`, this method doesn't fall back to the source string.
	 *
	 * @since 4.1.0
	 *
	 * @param string $source The source string.
	 * @return string
	 */
	public function get_translation( $source ) {
		$entry = new Translation_Entry( array( 'singular' => $source ) );
		$entry = $this->translate_entry( $entry );

		if ( ! $entry instanceof Translation_Entry || empty( $entry->translations ) ) {
			return '';
		}

		return (string) $entry->translations[0];
	}
}
