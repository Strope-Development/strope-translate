<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the translation status data up to date when posts are created, saved, duplicated, synchronized,
 * imported or deleted. Loaded in all contexts.
 *
 * @since 4.1.0
 */
class Strope_Translation_Status_Tracker {
	/**
	 * Translations of posts being deleted, post IDs as keys.
	 *
	 * @var int[][]
	 */
	protected $deleting = array();

	/**
	 * Posts copied by the sync-post module during the current request, which are not (yet) synchronized.
	 *
	 * @var int[]
	 */
	protected $copied = array();

	/**
	 * Posts for which a meta change is being processed (avoids recursion).
	 *
	 * @var bool[]
	 */
	protected $in_meta = array();

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 */
	public function __construct() {
		add_action( 'pre_post_update', array( $this, 'pre_post_update' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 20, 2 );
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );

		// New translation from post-new.php (classic and block editor).
		add_filter( 'use_block_editor_for_post', array( $this, 'new_post_translation' ), 1000 );

		add_action( 'strope_post_duplicated', array( $this, 'post_duplicated' ), 10, 2 );
		add_action( 'strope_post_synchronized', array( $this, 'post_synchronized' ), 10, 2 );
		add_action( 'edited_post_translations', array( $this, 'edited_translations_group' ) );
		add_action( 'strope_after_post_import', array( $this, 'after_import' ), 10, 2 );

		add_action( 'before_delete_post', array( $this, 'before_delete_post' ), 5 );
		add_action( 'deleted_post', array( $this, 'deleted_post' ) );

		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, 3 );
	}

	/**
	 * Tells whether we are doing an autosave.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	protected function doing_autosave() {
		return defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE;
	}

	/**
	 * Before a post is updated, stores a baseline for its translations which don't have one yet (legacy content),
	 * so that they become outdated if the update modifies the source content.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function pre_post_update( $post_id ) {
		$post_id = (int) $post_id;

		if ( $this->doing_autosave() || ! Strope_Translation_Status::is_tracked_post( $post_id ) ) {
			return;
		}

		$translations = Strope_Translation_Status::get_translations( $post_id );
		$hash         = null;

		foreach ( $translations as $tr_id ) {
			$tr_id = (int) $tr_id;

			if ( $tr_id === $post_id || metadata_exists( 'post', $tr_id, Strope_Translation_Status::META_SOURCE ) ) {
				continue;
			}

			if ( Strope_Translation_Status::get_source_id( $tr_id, $translations ) !== $post_id ) {
				continue;
			}

			if ( null === $hash ) {
				$hash = Strope_Translation_Status::get_hash( $post_id ); // Computed from the content before the update.
			}

			add_post_meta(
				$tr_id,
				Strope_Translation_Status::META_SOURCE,
				array(
					'id'   => $post_id,
					'hash' => $hash,
					'time' => 0,
					'user' => 0,
				),
				true
			);
		}
	}

	/**
	 * Tells whether the current request is a save made by a user in the classic editor for a given post.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_classic_editor_save( $post_id ) {
		// The nonce has already been checked by WordPress in post.php.
		// phpcs:disable WordPress.Security.NonceVerification
		return is_admin()
			&& ! wp_doing_ajax()
			&& isset( $GLOBALS['pagenow'], $_POST['action'], $_POST['post_ID'] )
			&& 'post.php' === $GLOBALS['pagenow']
			&& 'editpost' === $_POST['action']
			&& (int) $_POST['post_ID'] === (int) $post_id
			&& ! isset( $_GET['meta-box-loader'] ) // Block editor metaboxes saved after a REST save.
			&& empty( $_POST['wp-preview'] )
			&& ! $this->doing_autosave();
		// phpcs:enable
	}

	/**
	 * Updates the content hash and the status when a post is saved.
	 *
	 * @since 4.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_post( $post_id, $post ) {
		if ( $this->doing_autosave() || wp_is_post_revision( $post_id ) || ! Strope_Translation_Status::is_tracked_post( $post ) ) {
			return;
		}

		Strope_Translation_Status::update_hash( $post_id );

		if ( $this->is_classic_editor_save( $post_id ) ) {
			Strope_Translation_Status::user_saved( $post_id );
		} else {
			Strope_Translation_Status::refresh( $post_id );
		}
	}

	/**
	 * Registers the REST hooks.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function rest_api_init() {
		foreach ( Strope_Translation_Status::get_post_types() as $post_type ) {
			add_action( "rest_after_insert_{$post_type}", array( $this, 'rest_after_insert' ), 20, 3 ); // After the sync-post module.
		}
	}

	/**
	 * A post saved through the REST API (block editor) is a user save.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post         $post     Inserted or updated post object.
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating True when creating a post, false when updating.
	 * @return void
	 */
	public function rest_after_insert( $post, $request, $creating ) {
		if ( ! $post instanceof WP_Post || ! Strope_Translation_Status::is_tracked_post( $post ) ) {
			return;
		}

		Strope_Translation_Status::update_hash( $post->ID ); // Metas may have been updated after `save_post`.

		if ( $creating && $request instanceof WP_REST_Request ) {
			$from_post = $request->get_param( 'from_post' );
			$from_post = is_numeric( $from_post ) ? (int) $from_post : 0;

			if ( $from_post > 0 && $from_post !== $post->ID && current_user_can( 'read_post', $from_post ) && Strope_Translation_Status::is_tracked_post( $from_post ) ) {
				Strope_Translation_Status::set_source( $post->ID, $from_post );
			}
		}

		Strope_Translation_Status::user_saved( $post->ID );
	}

	/**
	 * Stores the source of a new translation created from post-new.php.
	 *
	 * @since 4.1.0
	 *
	 * @param bool $use_block_editor Whether the post can be edited in the block editor.
	 * @return bool Unmodified value.
	 */
	public function new_post_translation( $use_block_editor ) {
		global $post;

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if (
			! $post instanceof WP_Post ||
			! isset( $GLOBALS['pagenow'], $_GET['from_post'], $_GET['new_lang'], $_GET['_wpnonce'] ) ||
			'post-new.php' !== $GLOBALS['pagenow'] ||
			! is_numeric( $_GET['from_post'] ) ||
			! is_string( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'new-post-translation' )
		) {
			return $use_block_editor;
		}
		// phpcs:enable

		$from_post = (int) $_GET['from_post'];
		$source    = get_post_meta( $post->ID, Strope_Translation_Status::META_SOURCE, true );

		// The filter may be applied several times.
		if ( is_array( $source ) && isset( $source['id'] ) && (int) $source['id'] === $from_post ) {
			return $use_block_editor;
		}

		if ( $from_post !== $post->ID && current_user_can( 'read_post', $from_post ) && Strope_Translation_Status::is_tracked_post( $from_post ) && Strope_Translation_Status::is_tracked_post( $post, true ) ) {
			Strope_Translation_Status::set_source( $post->ID, $from_post );
		}

		return $use_block_editor;
	}

	/**
	 * Flags a translation created by duplication in the editor as needing a review.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id ID of the new translation (auto-draft).
	 * @param int $from_id ID of the source post.
	 * @return void
	 */
	public function post_duplicated( $post_id, $from_id ) {
		if ( ! Strope_Translation_Status::is_tracked_post( $post_id, true ) ) {
			return;
		}

		Strope_Translation_Status::set_source( $post_id, $from_id );
		Strope_Translation_Status::mark_needs_review( $post_id, 'duplicate', true ); // The review flag must survive the first save.
	}

	/**
	 * A post copied or synchronized by the sync-post module is up to date with its source.
	 * A copied (not synchronized) post needs a review.
	 *
	 * @since 4.1.0
	 *
	 * @param int $from_id Source post ID.
	 * @param int $tr_id   Target post ID.
	 * @return void
	 */
	public function post_synchronized( $from_id, $tr_id ) {
		$from_id = (int) $from_id;
		$tr_id   = (int) $tr_id;

		if ( ! Strope_Translation_Status::is_tracked_post( $tr_id ) || ! Strope_Translation_Status::is_tracked_post( $from_id ) ) {
			return;
		}

		Strope_Translation_Status::transaction(
			$tr_id,
			function () use ( $from_id, $tr_id ) {
				Strope_Translation_Status::update_hash( $tr_id ); // The post is updated directly in DB, without `save_post`.
				Strope_Translation_Status::set_source( $tr_id, $from_id, Strope_Translation_Status::update_hash( $from_id ) );

				if ( ! Strope_Translation_Status::is_synchronized( $tr_id ) ) {
					// May be a copy, or a synchronization group not saved yet. See `edited_translations_group()`.
					$this->copied[ $tr_id ] = $tr_id;
					update_post_meta(
						$tr_id,
						Strope_Translation_Status::META_REVIEW,
						array(
							'method'  => 'duplicate',
							'time'    => time(),
							'user'    => get_current_user_id(),
							'pending' => false,
						)
					);
				}
			}
		);
	}

	/**
	 * When a translations group is updated, refreshes the posts copied during this request:
	 * they may have become synchronized.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function edited_translations_group() {
		foreach ( $this->copied as $tr_id ) {
			if ( Strope_Translation_Status::is_synchronized( $tr_id ) ) {
				unset( $this->copied[ $tr_id ] );
				Strope_Translation_Status::refresh( $tr_id ); // Removes the review flag of synchronized posts.
			}
		}
	}

	/**
	 * Imported translations are up to date with their source.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language   Target language.
	 * @param int[]           $source_ids Source post IDs.
	 * @return void
	 */
	public function after_import( $language, $source_ids ) {
		$model = Strope()->model;

		if ( ! $language instanceof Strope_Language ) {
			return;
		}

		foreach ( array_map( 'intval', (array) $source_ids ) as $source_id ) {
			$tr_id = (int) $model->post->get( $source_id, $language );

			if ( empty( $tr_id ) || $tr_id === $source_id || ! Strope_Translation_Status::is_tracked_post( $tr_id ) ) {
				continue;
			}

			Strope_Translation_Status::transaction(
				$tr_id,
				function () use ( $tr_id, $source_id ) {
					Strope_Translation_Status::update_hash( $tr_id );
					Strope_Translation_Status::set_source( $tr_id, $source_id );
				}
			);
		}
	}

	/**
	 * Stores the translations of a post being deleted.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function before_delete_post( $post_id ) {
		if ( Strope_Translation_Status::is_tracked_post( $post_id ) ) {
			$this->deleting[ (int) $post_id ] = array_diff( Strope_Translation_Status::get_translations( $post_id ), array( (int) $post_id ) );
		}
	}

	/**
	 * Refreshes the status of the translations of a deleted post (the source may have changed).
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function deleted_post( $post_id ) {
		$post_id = (int) $post_id;

		if ( empty( $this->deleting[ $post_id ] ) ) {
			return;
		}

		$translations = $this->deleting[ $post_id ];
		unset( $this->deleting[ $post_id ] );

		Strope_Translation_Status::refresh( (int) reset( $translations ) );
	}

	/**
	 * Updates the content hash when a post meta included in the translatable fields is modified.
	 *
	 * @since 4.1.0
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public function meta_changed( $meta_id, $object_id, $meta_key ) {
		$object_id = (int) $object_id;

		if ( ! is_string( $meta_key ) || 0 === strpos( $meta_key, '_strope_' ) || 0 === strpos( $meta_key, '_edit_' ) || '_wp_old_slug' === $meta_key || isset( $this->in_meta[ $object_id ] ) ) {
			return;
		}

		$post = get_post( $object_id );

		if ( ! $post instanceof WP_Post || ! Strope_Translation_Status::is_tracked_post( $post ) || ! in_array( $meta_key, Strope_Translation_Status::get_fields( $post ), true ) ) {
			return;
		}

		$this->in_meta[ $object_id ] = true;
		Strope_Translation_Status::update_hash( $object_id );
		Strope_Translation_Status::refresh( $object_id );
		unset( $this->in_meta[ $object_id ] );
	}
}
