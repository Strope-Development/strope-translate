<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Copy the title, content and excerpt from the source when creating a new post translation
 * in the classic editor.
 *
 * @since 1.9
 */
class Strope_Duplicate extends Strope_Metabox_User_Button {
	/**
	 * Used to manage user meta.
	 *
	 * @var Strope_Toggle_User_Meta
	 */
	protected $user_meta;

	/**
	 * Constructor
	 *
	 * @since 1.9
	 */
	public function __construct() {
		$this->user_meta = new Strope_Toggle_User_Meta( Strope_Duplicate_Action::META_NAME );

		$args = array(
			'position'   => 'before_post_translations',
			'activate'   => __( 'Activate content duplication', 'strope-translate' ),
			'deactivate' => __( 'Deactivate content duplication', 'strope-translate' ),
			'class'      => 'dashicons-before dashicons-admin-page',
		);

		parent::__construct( 'strope-duplicate', $args );
	}
}
