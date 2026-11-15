<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class for media language and translation management
 *
 * @since 1.8
 */
class Strope_Settings_Media extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 30;

	/**
	 * Constructor
	 *
	 * @since 1.8
	 *
	 * @param object $strope_translate strope_translate object
	 */
	public function __construct( &$strope_translate ) {
		parent::__construct(
			$strope_translate,
			array(
				'module'        => 'media',
				'title'         => __( 'Media', 'strope-translate' ),
				'description'   => __( 'Activate languages and translations for media only if you need to translate the text attached to the media: the title, the alternative text, the caption, the description... Note that the file is not duplicated.', 'strope-translate' ),
				'active_option' => 'media_support',
			)
		);
	}
}
