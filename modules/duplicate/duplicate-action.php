<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class to manage duplication action.
 *
 * @since 3.6
 */
class Strope_Duplicate_Action {
	/**
	 * Duplicate user meta name.
	 *
	 * @var string
	 * @phpstan-var non-falsy-string
	 */
	const META_NAME = 'strope_duplicate_content';

	/**
	 * Reference to the plugin options.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Reference to the Strope_Sync_Content instance.
	 *
	 * @var Strope_Sync_Content
	 */
	protected $sync_content;

	/**
	 * Used to manage user meta.
	 *
	 * @var Strope_Toggle_User_Meta
	 */
	protected $user_meta;

	/**
	 * Constructor
	 *
	 * @since 3.6
	 *
	 * @param object $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->options      = &$strope_translate->options;
		$this->sync_content = &$strope_translate->sync_content;
		$this->user_meta    = new Strope_Toggle_User_Meta( static::META_NAME );

		/*
		 * After class instanciation and before terms and post metas are copied in Strope Translate.
		 */
		add_filter( 'use_block_editor_for_post', array( $this, 'new_post_translation' ), 2000 );
	}

	/**
	 * Fires the content copy
	 *
	 * @since 2.5
	 * @since 3.1 Add $is_block_editor param as the method is now hooked to the filter use_block_editor_for_post.
	 *
	 * @param bool $is_block_editor Whether the post can be edited or not.
	 * @return bool
	 */
	public function new_post_translation( $is_block_editor ) {
		global $post;
		static $done = false;

		if ( ! empty( $post ) && ! $done && 'post-new.php' === $GLOBALS['pagenow'] && isset( $_GET['from_post'], $_GET['new_lang'] ) ) {
			check_admin_referer( 'new-post-translation' );

			if ( $this->user_meta->is_active() ) {
				$done = true; // Avoid a second duplication in the block editor.

				// The capability to create the new post is checked in post-new.php, the source post must be readable too.
				$from_post = get_post( (int) $_GET['from_post'] );

				if ( empty( $from_post ) || ! strope_current_user_can_copy_post( $from_post->ID ) ) {
					return $is_block_editor;
				}

				$this->sync_content->copy_content( $from_post, $post, sanitize_key( $_GET['new_lang'] ) );

				/**
				 * Fires after the content of the source post has been duplicated into a new translation (auto-draft) in the editor.
				 *
				 * @since 4.1.0
				 *
				 * @param int    $post_id ID of the new translation.
				 * @param int    $from_id ID of the source post.
				 * @param string $lang    Language slug of the new translation.
				 */
				do_action( 'strope_post_duplicated', $post->ID, $from_post->ID, sanitize_key( $_GET['new_lang'] ) );

				// Maybe duplicates the featured image.
				if ( $this->options['media_support'] ) {
					add_filter( 'strope_translate_post_meta', array( $this->sync_content, 'duplicate_thumbnail' ), 10, 3 );
				}

				// Maybe duplicate terms.
				add_filter( 'strope_maybe_translate_term', array( $this->sync_content, 'duplicate_term' ), 10, 3 );
			}
		}

		return $is_block_editor;
	}
}
