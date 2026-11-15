<?php
/**
 * Public functions of the translation status tracking.
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'strope_get_translation_status' ) ) {
	/**
	 * Returns the translation status of a post in all languages, or in one language.
	 *
	 * Each entry is an array with the keys:
	 * - `lang`           (string) Language slug.
	 * - `status`         (string) 'missing', 'up_to_date' or 'outdated'.
	 * - `translation_id` (int)    ID of the post in this language, 0 if missing.
	 * - `source_id`      (int)    ID of the post this translation is based on, 0 for the original.
	 * - `is_original`    (bool)   True if the post has no source (original content of the translation group).
	 * - `synchronized`   (bool)   True if the post is synchronized by the sync-post module (always up to date).
	 * - `needs_review`   (bool)   True if the content was not written by a human (e.g. duplicated) and was not reviewed.
	 * - `review_method`  (string) How the content needing review was created, e.g. 'duplicate'.
	 * - `last_updated`   (int)    Timestamp of the last time the translation was marked up to date, 0 if unknown.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Optional language slug.
	 * @return array|null Entries with language slugs as keys, a single entry if `$lang` is passed,
	 *                    null if the post isn't tracked (or the feature is disabled).
	 */
	function strope_get_translation_status( $post_id, $lang = '' ) {
		if ( ! Strope_Translation_Status::is_enabled() ) {
			return null;
		}

		return Strope_Translation_Status::get_status( (int) $post_id, (string) $lang );
	}
}
