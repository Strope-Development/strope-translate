<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit; // @phpstan-ignore-line

if ( ! $strope_translate->model->has_languages() ) {
	return;
}

add_action(
	'strope_init',
	function ( $strope_translate ) {
		$strope_fse_sub_modules = array(
			Strope_FSE_Default_Language_Change::class,
			Strope_FSE_Language::class,
			Strope_FSE_Language_Slug_Change::class,
			Strope_FSE_Filter_Block_Types::class,
			Strope_FSE_Post_Types::class,
			Strope_FSE_Query_Filters::class,
			Strope_FSE_REST_Duplicate_Template::class,
			Strope_FSE_REST_Enforce_Default_Template::class,
			Strope_FSE_Post_Deletion::class,
			Strope_FSE_Template_Model::class,
			Strope_FSE_Template_Slug_Sync::class,
		);

		foreach ( $strope_fse_sub_modules as $strope_fse_class ) {
			$strope_translate->{$strope_fse_class::get_name()} = ( new $strope_fse_class( $strope_translate ) )->init();
		}

		if ( $strope_translate->model instanceof Strope_Admin_Model ) {
			$strope_translate->{Strope_FSE_Recreate_Language::get_name()} = ( new Strope_FSE_Recreate_Language( $strope_translate ) )->init();
		}

		// Strope_FSE_REST_Template is required only in a REST context.
		add_action(
			'rest_api_init',
			function () use ( $strope_translate ) {
				$strope_translate->rest_api->template = ( new Strope_FSE_REST_Template( $strope_translate->rest_api, Strope_FSE_Tools::get_template_post_types() ) )->init();
			},
			20 // Load the FSE modules after the Strope_REST_API.
		);

		unset( $strope_fse_sub_modules, $strope_fse_class );
	},
	20 // Load the FSE modules after the Strope_REST_API.
);
