<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class that handle the import action.
 *
 * @since 2.7
 *
 * Class Strope_Import_Action
 */
class Strope_Import_Action {

	/**
	 * Used to set import action name in forms.
	 *
	 * @var string
	 */
	const ACTION_NAME = 'strope_import';

	/**
	 * Used to create nonce for this action.
	 *
	 * @var string
	 */
	const NONCE_NAME = '_strope_import_nonce';

	/**
	 * Used to query languages and translations.
	 *
	 * @var Strope_Model
	 */
	private $model;

	/**
	 * The various import types.
	 *
	 * @var Strope_Import_Object_Interface[]
	 */
	private $imports;

	/**
	 * Stores the properties of the uploaded file.
	 *
	 * @var array {
	 *   @type string $file  Filename of the newly-uploaded file.
	 *   @type string $url   URL of the newly-uploaded file.
	 *   @type string $type  Mime type of the newly-uploaded file.
	 *   @type string $error Optional. Message for errors having occurred during file upload.
	 * }
	 */
	private $upload = array();

	/**
	 * Used to get the format of uploaded files.
	 *
	 * @var Strope_File_Format_Factory
	 */
	private $file_format_factory;

	/**
	 * Strope_Import_Action constructor.
	 *
	 * @since 2.7
	 * @since 3.3 Added the 2nd parameter `$imports` to handle the various import types.
	 * @since 3.6 Added the 3rd parameter `$file_format_factory`.
	 *
	 * @param Strope_Model                     $model               Instance of Strope_Model.
	 * @param Strope_Import_Object_Interface[] $imports             The import types.
	 * @param Strope_File_Format_Factory       $file_format_factory File factory.
	 */
	public function __construct( Strope_Model $model, array $imports, Strope_File_Format_Factory $file_format_factory ) {
		$this->model               = $model;
		$this->imports             = $imports;
		$this->file_format_factory = $file_format_factory;
	}

	/**
	 * Deletes the file when no longer needed.
	 *
	 * @since 3.6
	 */
	public function __destruct() {
		if ( isset( $this->upload['file'] ) ) {
			wp_delete_file( $this->upload['file'] );
		}
	}

	/**
	 * Launches the import action.
	 * Make sure to verify the current user's capabilities first.
	 *
	 * @since 3.6
	 *
	 * @return void
	 */
	public function import_action() {
		$error = $this->upload();

		if ( ! is_wp_error( $error ) ) {
			$error = $this->process( $error );
		}

		strope_add_notice( $error );

		Strope_Settings::redirect();
	}

	/**
	 * Adds translation file formats to the list of allowed mime types.
	 *
	 * @since 3.6
	 *
	 * @param array $mimes List of allowed mime types.
	 * @return array Modified list of allowed mime types.
	 */
	public function allowed_mimes( $mimes ) {
		foreach ( $this->file_format_factory->get_supported_formats() as $format ) {
			$mimes = array_merge( $mimes, $format->mime_type );
		}
		return $mimes;
	}

	/**
	 * Processes the imported objects retrieved from an import file.
	 *
	 * @since 3.6
	 *
	 * @param Strope_Import_File_Interface $import File importer.
	 * @return WP_Error Error object.
	 */
	private function process( Strope_Import_File_Interface $import ): WP_Error {
		$is_targeted_site_valid = $this->is_valid_site( $import );
		if ( $is_targeted_site_valid->has_errors() ) {
			return $is_targeted_site_valid;
		}

		$is_generator_valid = $this->is_valid_generator( $import );
		if ( $is_generator_valid->has_errors() ) {
			return $is_generator_valid;
		}

		$target_language = $this->get_target_language( $import );
		if ( is_wp_error( $target_language ) ) {
			return $target_language;
		}

		$error             = new WP_Error();
		$same_language_ids = array();

		$entry = $import->get_next_entry();
		while ( ! empty( $entry ) ) {
			if ( isset( $this->imports[ $entry['type'] ] ) ) {
				if ( $this->is_in_target_language( $entry, $target_language ) ) {
					// Importing would overwrite the source content with its own "translation".
					$same_language_ids[] = (int) $entry['id'];
				} else {
					$this->imports[ $entry['type'] ]->translate( $entry, $target_language );
				}
			}
			$entry = $import->get_next_entry();
		}

		if ( ! empty( $same_language_ids ) ) {
			$error->add(
				'strope_import_same_language',
				sprintf(
					/* translators: %1$s is a language name, %2$s is a list of IDs. */
					__( 'Error: The target language of the imported file (%1$s) is the language of the source content, which would be overwritten. These contents have not been imported: %2$s.', 'strope-translate' ),
					$target_language->name,
					wp_sprintf_l( '%l', array_unique( $same_language_ids ) )
				)
			);
		}

		foreach ( $this->imports as $import ) {
			$import_type = $import->get_type();
			$imported_objects_ids = $import->get_imported_object_ids();

			/**
			 * Fires after objects have been imported.
			 *
			 * @since 3.3
			 *
			 * @param Strope_Language $target_language      The targeted language for import.
			 * @param array        $imported_objects_ids The imported object ids of the import.
			 */
			do_action( "strope_after_{$import_type}_import", $target_language, $imported_objects_ids );

			$error->merge_from( $import->get_warning_notice() );
			$error->merge_from( $import->get_updated_notice() );
		}

		return $error;
	}

	/**
	 * Tells whether the source object of an imported post or term entry is already in the target language.
	 *
	 * @since 4.1.0
	 *
	 * @param array           $entry           The entry to import.
	 * @param Strope_Language $target_language The target language of the import.
	 * @return bool
	 */
	private function is_in_target_language( array $entry, Strope_Language $target_language ): bool {
		if ( empty( $entry['id'] ) ) {
			return false;
		}

		switch ( $entry['type'] ) {
			case Strope_Import_Export::TYPE_POST:
				$language = $this->model->post->get_language( (int) $entry['id'] );
				break;
			case Strope_Import_Export::TYPE_TERM:
				$language = $this->model->term->get_language( (int) $entry['id'] );
				break;
			default:
				return false;
		}

		return $language instanceof Strope_Language && $language->slug === $target_language->slug;
	}

	/**
	 * Checks if the current site corresponding on the targeted one in the import file.
	 *
	 * @since 3.3
	 * @since 3.6 Visibility is now private.
	 *
	 * @param Strope_Import_File_Interface $import Import file.
	 * @return WP_Error Error object, empty if valid.
	 */
	private function is_valid_site( Strope_Import_File_Interface $import ): WP_Error {
		if ( $import->get_site_reference() !== get_site_url() ) {
			return new WP_Error(
				'strope_import_invalid_site',
				/* translators: placeholders are URLs */
				sprintf( __( 'Error: The site targeted in the imported file does not match the current site. Found: %1$s. Expected: %2$s.', 'strope-translate' ), $import->get_site_reference(), get_site_url() )
			);
		}

		return new WP_Error();
	}

	/**
	 * Checks if the import file has been generated by Strope Translate.
	 *
	 * @since 3.3
	 * @since 3.6 Visibility is now private.
	 *
	 * @param Strope_Import_File_Interface $import Import file.
	 * @return WP_Error Error object, empty if valid.
	 */
	private function is_valid_generator( Strope_Import_File_Interface $import ): WP_Error {
		if ( $import->get_generator_name() !== Strope_Import_Export::APP_NAME ) {
			return new WP_Error(
				'strope_import_invalid_generator',
				sprintf(
					/* translators: %s is the plugin's name. */
					__( 'Error: The imported file has not been generated by %s.', 'strope-translate' ),
					STROPE_TRANSLATE
				)
			);
		}

		return new WP_Error();
	}

	/**
	 * Checks if the targeted language is valid for the import.
	 *
	 * @since 3.3
	 * @since 3.6 Visibility is now private.
	 *
	 * @param Strope_Import_File_Interface $import Import file.
	 * @return Strope_Language|WP_Error
	 */
	private function get_target_language( Strope_Import_File_Interface $import ) {

		$locale = $import->get_target_language();

		if ( false === $locale ) {
			return new WP_Error(
				'strope_import_no_target_language',
				__( 'Error: No target languages have been provided in the imported file.', 'strope-translate' )
			);
		}
		$language = $this->model->get_language( $locale );
		if ( ! $language ) {
			return new WP_Error(
				'strope_import_invalid_target_language',
				__( "Error: You are trying to import a file in a language which doesn't exist on your site.", 'strope-translate' )
			);
		}

		return $language;
	}

	/**
	 * Handles uploading a file and returns associated importer.
	 *
	 * @since 3.6
	 *
	 * @return Strope_Import_File_Interface|WP_Error An error object on failure, `Strope_Import_File_Interface` object on success.
	 */
	private function upload() {
		check_admin_referer( self::ACTION_NAME, self::NONCE_NAME );

		if ( empty( $_FILES['importFileToUpload']['name'] ) ) {
			// No uploaded file.
			return new WP_Error( 'strope_import_no_upload', __( 'Error: Please select a file to upload.', 'strope-translate' ) );
		}

		add_filter( 'upload_mimes', array( $this, 'allowed_mimes' ) ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.upload_mimes

		$overrides = array(
			'test_form' => false,
		);

		$this->upload = wp_handle_upload( $_FILES['importFileToUpload'], $overrides );

		remove_filter( 'upload_mimes', array( $this, 'allowed_mimes' ) );

		if ( isset( $this->upload['error'] ) ) {
			return new WP_Error( 'strope_import_upload_failed', $this->upload['error'] );
		}

		if ( ! isset( $this->upload['type'], $this->upload['file'] ) ) {
			return new WP_Error( 'strope_import_upload_failed', __( 'Upload failed.', 'strope-translate' ) );
		}

		$file_format = $this->file_format_factory->from_mime_type( $this->upload['type'] );

		if ( is_wp_error( $file_format ) ) {
			return $file_format;
		}

		$import_file = $file_format->get_import();
		$result      = $import_file->import_from_file( $this->upload['file'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $import_file;
	}
}
