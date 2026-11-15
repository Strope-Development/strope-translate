<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class for the legacy API module.
 *
 * @since 4.0.0
 */
class Strope_Settings_Legacy_API extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 90;

	/**
	 * Constructor.
	 *
	 * @since 4.0.0
	 *
	 * @param Strope_Settings $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		parent::__construct(
			$strope_translate,
			array(
				'module'        => 'legacy_api',
				'title'         => __( 'Legacy API compatibility', 'strope-translate' ),
				'description'   => __( 'Lets themes and plugins written for Polylang keep working: provides the pll_*() functions, PLL(), the class names and the hooks they use. Activate it if you switched from Polylang, or if a theme or plugin needs it.', 'strope-translate' ),
				'active_option' => 'legacy_api',
			)
		);
	}
}
