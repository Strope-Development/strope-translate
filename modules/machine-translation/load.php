<?php
/**
 * Loads the machine translation: posts are translated by a translation engine running in the browsers of the
 * editors, and saved as reviewable drafts. Off by default (Languages > Settings > Machine translation).
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

if ( ! $strope_translate->model->has_languages() ) {
	return;
}

add_filter(
	'strope_settings_modules',
	function ( $modules ) {
		$modules[] = 'Strope_Settings_Machine_Translation';
		return $modules;
	}
);

if ( empty( $strope_translate->options['machine_translation'] ) ) {
	// Not active: only the settings card is loaded, and the scheduled tasks are removed.
	if ( wp_next_scheduled( Strope_Machine_Translation::CLEANUP_HOOK ) ) {
		wp_clear_scheduled_hook( Strope_Machine_Translation::CLEANUP_HOOK );
		Strope_Machine_Translation::kit()->deactivate();
	}
	return;
}

Strope_Machine_Translation::init( $strope_translate );
