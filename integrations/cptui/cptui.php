<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages compatibility with Custom Post Type UI.
 * Version tested: 1.5.4.
 *
 * @since 2.1
 */
class Strope_CPTUI {

	/**
	 * Initializes filters and actions.
	 *
	 * @since 2.1
	 *
	 * @return void
	 */
	public function init() {
		$keys = array(
			'*' => array(
				'label'          => 1,
				'singular_label' => 1,
				'description'    => 1,
				'labels'         => array(
					'*' => 1,
				),
			),
		);

		new Strope_Translate_Option( 'cptui_post_types', $keys, array( 'context' => 'CPT UI' ) );
		new Strope_Translate_Option( 'cptui_taxonomies', $keys, array( 'context' => 'CPT UI' ) );

		if ( Strope() instanceof Strope_Frontend && ! Strope()->options['force_lang'] ) {
			// Special case when the language is set from the content as CPT and taxonomies are registered before the language is defined.
			add_action( 'strope_language_defined', array( $this, 'strope_language_defined' ) );
		}


		// Add CPT UI post types and taxonomies to Strope Translate settings.
		add_filter( 'strope_get_post_types', array( $this, 'strope_get_types' ), 10, 2 );
		add_filter( 'strope_get_taxonomies', array( $this, 'strope_get_types' ), 10, 2 );
	}

	/**
	 * Translates custom post types and taxonomies labels when the language is set from the content.
	 *
	 * @since 2.1
	 *
	 * @param array $types       Array of registered post types or taxonomies.
	 * @param array $cptui_types Array of CPT UI post types or taxonomies.
	 */
	public function translate_registered_types( $types, $cptui_types ) {
		foreach ( $types as $name => $type ) {
			if ( in_array( $name, $cptui_types ) ) {
				$type->label       = strope__( $type->label );
				$type->description = strope__( $type->description );

				foreach ( array_keys( get_object_vars( $type->labels ) ) as $key ) {
					$type->labels->$key = strope__( $type->labels->$key );
				}
			}
		}
	}

	/**
	 * Translates custom post types and taxonomies labels when the language is set from the content.
	 *
	 * @since 2.1
	 */
	public function strope_language_defined() {
		$this->translate_registered_types( $GLOBALS['wp_post_types'], array_keys( get_option( 'cptui_post_types', array() ) ) );
		$this->translate_registered_types( $GLOBALS['wp_taxonomies'], array_keys( get_option( 'cptui_taxonomies', array() ) ) );
	}

	/**
	 * Add CPT UI post types and taxonomies to Strope Translate settings.
	 *
	 * @since 2.1
	 *
	 * @param string[] $types       List of post type or taxonomy names.
	 * @param bool     $is_settings True when displaying the list in Strope Translate settings.
	 * @return string[]
	 */
	public function strope_get_types( $types, $is_settings ) {
		if ( $is_settings ) {
			$type        = substr( current_filter(), strlen( 'strope_get_' ) ); // 'post_types' or 'taxonomies'.
			$cptui_types = get_option( "cptui_{$type}" );

			if ( is_array( $cptui_types ) ) {
				$types = array_merge( $types, array_keys( $cptui_types ) );
			}
		}
		return $types;
	}
}
