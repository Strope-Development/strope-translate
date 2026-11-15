<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class to advertize the Share slugs module.
 *
 * @since 1.9
 * @since 3.1 Renamed from Strope_Settings_Share_Slug.
 */
class Strope_Settings_Preview_Share_Slug extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 70;

	/**
	 * Constructor.
	 *
	 * @since 1.9
	 *
	 * @param Strope_Settings $strope_translate Strope Translate object.
	 * @param array        $args     Optional. Addition arguments.
	 *
	 * @phpstan-param array{
	 *   module?: non-falsy-string,
	 *   title?: string,
	 *   description?: string,
	 *   active_option?: non-falsy-string
	 * } $args
	 */
	public function __construct( &$strope_translate, array $args = array() ) {
		$default = array(
			'module'        => 'share-slugs',
			'title'         => __( 'Share slugs', 'strope-translate' ),
			'description'   => $this->get_description(),
			'active_option' => 'preview',
		);

		parent::__construct( $strope_translate, array_merge( $default, $args ) );
	}

	/**
	 * Returns the module description.
	 *
	 * @since 3.1
	 *
	 * @return string
	 */
	protected function get_description() {
		return __( 'Allows to share the same URL slug across languages for posts and terms.', 'strope-translate' );
	}
}
