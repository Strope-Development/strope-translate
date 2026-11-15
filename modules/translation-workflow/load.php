<?php
/**
 * Loads the translation workflow module: translator role, per-user translation languages and translation jobs.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

require_once __DIR__ . '/functions.php';

add_filter(
	'strope_settings_modules',
	function ( $modules ) {
		$modules[] = 'Strope_Settings_Translation_Workflow';
		return $modules;
	}
);

if ( $strope_translate->model->has_languages() ) {
	$strope_translate->translation_workflow = new Strope_Translation_Workflow( $strope_translate );
	Strope_Word_Count::init(); // Word counts of the jobs.

	// Restrict the theme and plugin strings translations to the languages assigned to the user.
	add_filter(
		'strope_string_discovery_user_languages',
		function ( $languages, $user_id ) {
			return array_filter(
				(array) $languages,
				function ( $language ) use ( $user_id ) {
					return $language instanceof Strope_Language && strope_user_can_translate_language( $language, (int) $user_id );
				}
			);
		},
		10,
		2
	);
}
