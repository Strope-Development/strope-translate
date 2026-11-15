<?php
/**
 * Public functions of the translation workflow module.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Returns the languages a user is allowed to translate into.
 * An empty array means that the user is not restricted (all languages).
 *
 * @since 4.1.0
 *
 * @param int $user_id Optional user ID, defaults to the current user.
 * @return string[] Language slugs.
 */
function strope_get_user_translation_languages( $user_id = 0 ) {
	return Strope_Translator_Languages::get_user_languages( $user_id );
}

/**
 * Tells whether a user is allowed to translate content into a language.
 * Always true for users without language restriction.
 *
 * @since 4.1.0
 *
 * @param Strope_Language|string $language Language object or slug.
 * @param int                    $user_id  Optional user ID, defaults to the current user.
 * @return bool
 */
function strope_user_can_translate_language( $language, $user_id = 0 ) {
	return Strope_Translator_Languages::user_can_translate( $language, $user_id );
}
