<?php
/**
 * @package Strope Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface for object translation model.
 */
interface Strope_Translation_Object_Model_Interface {
	/**
	 * Translates an object into a given language.
	 *
	 * @since 3.6
	 *
	 * @param  array        $entry           Properties array of an entry.
	 * @param  Strope_Language $target_language A language to translate into.
	 * @return int The translated entity ID, 0 on failure.
	 */
	public function translate( array $entry, Strope_Language $target_language ): int;

	/**
	 * Translates parent objects if any.
	 *
	 * @since 3.6
	 *
	 * @param int[]        $ids             Array of source entity ids.
	 * @param Strope_Language $target_language The target language.
	 * @return void
	 */
	public function translate_parents( array $ids, Strope_Language $target_language );
}
