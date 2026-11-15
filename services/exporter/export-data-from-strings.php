<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the admin action of exporting strings translations.
 *
 * @since 3.6
 */
class Strope_Export_Data_From_Strings {
	/**
	 * Used to query languages and translations.
	 *
	 * @var Strope_Model
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * @since 3.6
	 *
	 * @param Strope_Model $model  Strope Translate model.
	 */
	public function __construct( Strope_Model $model ) {
		$this->model = $model;
	}

	/**
	 * Prepares and exports the selected strings translations.
	 *
	 * @since 3.6
	 *
	 * @param Strope_Export_Container $container       Export container.
	 * @param Strope_Language         $target_language The target language.
	 * @param string               $group           String translation context to export.
	 * @return WP_Error                             A `WP_Error` object. Note: an "empty" `WP_Error` object is returned on success.
	 */
	public function send_to_export( Strope_Export_Container $container, Strope_Language $target_language, string $group ): WP_Error {
		$source_language = $this->model->get_default_language();

		if ( empty( $source_language ) ) {
			return new WP_Error( 'strope_export_no_source_language', __( 'Error: Default language not defined.', 'strope-translate' ) );
		}

		$translations = new Strope_Export_Strings( $this->model );
		$sources      = Strope_Admin_Strings::get_strings();

		if ( '' !== $group ) {
			$sources = array_filter(
				$sources,
				function ( $source ) use ( $group ) {
					return $group === $source['context'];
				}
			);
		}

		if ( empty( $sources ) ) {
			return new WP_Error( 'strope_export_no_strings', __( 'Error: No strings found.', 'strope-translate' ) );
		}

		$translations->add_items( $container, $sources, $target_language );

		return new WP_Error();
	}
}
