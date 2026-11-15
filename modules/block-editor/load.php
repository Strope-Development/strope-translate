<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

add_action(
	'strope_init',
	function ( $strope_translate ) {
		if ( ! $strope_translate->model->has_languages() ) {
			return;
		}

		if ( strope_use_block_editor_plugin() ) {
			if ( $strope_translate instanceof Strope_Admin ) {
				$strope_translate->block_editor_plugin = new Strope_Block_Editor_Plugin( $strope_translate );
			}

			if ( $strope_translate instanceof Strope_Frontend ) {
				$strope_translate->filters_widgets_blocks = new Strope_Frontend_Filters_Widgets_Blocks( $strope_translate );
			}

			$strope_translate->widget_editor = new Strope_Widget_Editor_Language_Attribute();
		}

		// The language switcher blocks don't depend on the block editor plugin (languages panel).
		$strope_translate->switcher_block   = ( new Strope_Language_Switcher_Block( $strope_translate ) )->init();
		$strope_translate->navigation_block = ( new Strope_Navigation_Language_Switcher_Block( $strope_translate ) )->init();
	}
);
