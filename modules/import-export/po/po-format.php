<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Strope_PO_Format
 *
 * @since 3.1
 *
 * Manages the support for Po format for Strope Translate Import / Export feature.
 */
class Strope_PO_Format extends Strope_File_Format {
	/**
	 * @var string
	 */
	public $extension = 'po';

	/**
	 * @var string[]
	 */
	public $mime_type = array( 'po' => 'text/x-po' );

	/**
	 * Po format is always supported.
	 *
	 * @since 3.1
	 *
	 * @return true
	 */
	public function is_supported() {
		return true;
	}

	/**
	 * Returns the associated import class.
	 *
	 * @since 3.1
	 *
	 * @return Strope_PO_Import
	 */
	public function get_import() {
		return new Strope_PO_Import();
	}

	/**
	 * Returns the associated export class.
	 *
	 * @since 3.6
	 *
	 * @param string $version Optional file format version. Not used for PO.
	 * @return string
	 *
	 * @phpstan-return class-string<Strope_PO_Export>
	 */
	public function get_export_class( $version = '' ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return Strope_PO_Export::class;
	}
}
