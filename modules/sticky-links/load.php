<?php
/**
 * Loads the sticky links: the internal links of the content are kept pointing to the current url
 * of the linked content, in the right language, by correcting the stored content.
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
		$modules[] = 'Strope_Settings_Sticky_Links';
		return $modules;
	}
);

if ( empty( $strope_translate->options['sticky_links'] ) ) {
	return;
}

$strope_translate->sticky_links = ( new Strope_Sticky_Links( $strope_translate->model, $strope_translate->links_model ) )->init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'strope links', new Strope_Sticky_Links_CLI( $strope_translate->sticky_links ) );
}
