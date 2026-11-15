<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface Strope_Import_Export_File_Format
 *
 * @since 3.1
 *
 * Represents a supported format for the import / export feature.
 */
abstract class Strope_File_Format {
	/**
	 * @var string
	 */
	public $extension;

	/**
	 * @var string[]
	 */
	public $mime_type;

	/**
	 * Whether the file format is supported by the current environment or not.
	 *
	 * @since 3.1
	 *
	 * @return true|WP_Error
	 */
	abstract public function is_supported();

	/**
	 * Returns the associated import class.
	 *
	 * @since 3.1
	 *
	 * @return Strope_Import_File_Interface
	 */
	abstract public function get_import();

	/**
	 * Returns the associated export class.
	 *
	 * @since 3.6
	 *
	 * @param string $version Optional file format version.
	 * @return string
	 *
	 * @phpstan-return class-string<Strope_Export_Data>
	 */
	abstract public function get_export_class( $version = '' ): string;
}
