<?php
/**
 * Loads the front-end translation editor.
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

if ( ! $strope_translate->model->has_languages() ) {
	return;
}

// REST endpoints (the routes are registered only when the REST server is initialized).
new Strope_Frontend_Editor_REST( $strope_translate->model );

if ( $strope_translate instanceof Strope_Frontend ) {
	new Strope_Frontend_Editor( $strope_translate );
} elseif ( is_admin() ) {
	add_action( 'enqueue_block_editor_assets', array( 'Strope_Frontend_Editor', 'enqueue_deep_link' ) );
}
