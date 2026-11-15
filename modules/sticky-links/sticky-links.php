<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sticky links: keeps the internal links of the content pointing to the current url of the linked post or term,
 * in the language of the content.
 *
 * The links are corrected in the stored content (never when displaying it):
 * - when a post is saved (or gets its language),
 * - in the background, when a linked post or term changes (url, status, translations, deletion),
 *   with a revision saved for each modified post.
 *
 * @since 4.1.0
 */
class Strope_Sticky_Links {
	const META_INDEX   = '_strope_links';
	const META_TARGET  = '_strope_link_target';
	const META_BROKEN  = '_strope_broken_links';
	const META_PENDING = '_strope_links_pending';
	const OPTION_SCAN  = 'strope_sticky_links_scan';
	const OPTION_LOCK  = 'strope_sticky_links_lock';
	const CRON_HOOK    = 'strope_sticky_links_process';
	const BATCH_SIZE   = 20;

	/**
	 * Post statuses of the posts whose links are not corrected.
	 *
	 * @var string[]
	 */
	const SKIPPED_STATUSES = array( 'auto-draft', 'trash', 'inherit' );

	/**
	 * Strope Translate options affecting the urls.
	 *
	 * @var string[]
	 */
	const URL_OPTIONS = array( 'force_lang', 'hide_default', 'rewrite', 'domains', 'redirect_lang' );

	/**
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * @var Strope_Sticky_Links_Content
	 */
	protected $content;

	/**
	 * Results of the fixes made before saving a post, by post ID.
	 *
	 * @var array[]
	 */
	protected $prepared = array();

	/**
	 * Language and content of the posts already processed during the request, by post ID.
	 *
	 * @var string[]
	 */
	protected $processed = array();

	/**
	 * Posts modified during the request, whose linking posts must be updated.
	 *
	 * @var true[]
	 */
	protected $touched_posts = array();

	/**
	 * Terms modified during the request, whose linking posts must be updated.
	 *
	 * @var true[]
	 */
	protected $touched_terms = array();

	/**
	 * Whether all the indexed posts must be updated.
	 *
	 * @var bool
	 */
	protected $touched_all = false;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Model       $model       Strope Translate's model.
	 * @param Strope_Links_Model $links_model Strope Translate's links model.
	 */
	public function __construct( Strope_Model $model, Strope_Links_Model $links_model ) {
		$this->model   = $model;
		$this->content = new Strope_Sticky_Links_Content( $model, $links_model );
	}

	/**
	 * Adds hooks.
	 *
	 * @since 4.1.0
	 *
	 * @return self
	 */
	public function init(): self {
		// Corrects the links of the saved posts.
		add_filter( 'wp_insert_post_data', array( $this, 'insert_post_data' ), 99, 4 );
		add_action( 'wp_after_insert_post', array( $this, 'after_insert_post' ), 8 ); // Before the revision of new posts.
		add_action( 'set_object_terms', array( $this, 'set_object_terms' ), 10, 6 );
		add_action( 'strope_post_synchronized', array( $this, 'post_synchronized' ), 20, 2 );

		// Detects the changes of the linked objects.
		add_action( 'post_updated', array( $this, 'post_updated' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'touch_post' ) );
		add_action( 'edited_term', array( $this, 'edited_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'touch_term' ) );

		foreach ( array( 'permalink_structure', 'category_base', 'tag_base', 'show_on_front', 'page_on_front' ) as $option ) {
			add_action( "update_option_{$option}", array( $this, 'touch_all' ) );
		}
		add_action( 'update_option_strope_translate', array( $this, 'options_updated' ), 10, 2 );

		add_action( 'shutdown', array( $this, 'flush' ) );
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );

		return $this;
	}

	/*
	 * Saved posts.
	 */

	/**
	 * Corrects the links of a post before it's updated, when its language is known.
	 *
	 * @since 4.1.0
	 *
	 * @param array $data                Slashed, sanitized and processed post data.
	 * @param array $postarr             Sanitized (and slashed) post data.
	 * @param array $unsanitized_postarr Unsanitized post data.
	 * @param bool  $update              Whether this is an existing post being updated.
	 * @return array
	 */
	public function insert_post_data( $data, $postarr, $unsanitized_postarr = array(), $update = false ) {
		if ( ! $update || empty( $postarr['ID'] ) || ! is_array( $data ) || ! $this->is_processable( $data['post_type'], $data['post_status'] ) ) {
			return $data;
		}

		$post_id  = (int) $postarr['ID'];
		$language = $this->model->post->get_language( $post_id );

		if ( ! $language ) {
			return $data;
		}

		$result = $this->content->fix( wp_unslash( (string) $data['post_content'] ), $language, $this->get_index( $post_id ), $this->get_source_language( $post_id, $language ) );

		$this->prepared[ $post_id ] = array(
			'language' => $language->slug,
			'result'   => $result,
		);

		$data['post_content'] = wp_slash( $result['content'] );

		return $data;
	}

	/**
	 * Saves the links index of a saved post, or corrects its links if it wasn't done before saving
	 * (new post or language modified).
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function after_insert_post( $post_id ) {
		$post_id = (int) $post_id;

		if ( isset( $this->prepared[ $post_id ] ) ) {
			$prepared = $this->prepared[ $post_id ];
			unset( $this->prepared[ $post_id ] );

			$post     = get_post( $post_id );
			$language = $this->model->post->get_language( $post_id );

			if ( $post && $language && $language->slug === $prepared['language'] && $post->post_content === $prepared['result']['content'] ) {
				$this->save_index( $post_id, $prepared['result'] );
				$this->processed[ $post_id ] = $this->get_state( $post );
				return;
			}
		}

		$this->process_post( $post_id );
	}

	/**
	 * Corrects the links of a post when its language is modified, and detects the changes of translations.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      An array of object term IDs or slugs.
	 * @param array  $tt_ids     An array of term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether to append new terms to the old terms.
	 * @param array  $old_tt_ids Old array of term taxonomy IDs.
	 * @return void
	 */
	public function set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ) {
		$tt_ids     = array_map( 'intval', (array) $tt_ids );
		$old_tt_ids = array_map( 'intval', (array) $old_tt_ids );
		sort( $tt_ids );
		sort( $old_tt_ids );

		if ( $tt_ids === $old_tt_ids ) {
			return;
		}

		switch ( $taxonomy ) {
			case 'language':
				$this->process_post( (int) $object_id );
				$this->touch_post( (int) $object_id );
				break;
			case 'post_translations':
				$this->touch_post( (int) $object_id );
				break;
			case 'term_language':
			case 'term_translations':
				$this->touch_term( (int) $object_id );
				break;
		}
	}

	/**
	 * Corrects the links of a post synchronized with another one, as its content is copied directly in database.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id ID of the source post.
	 * @param int $tr_id   ID of the synchronized post.
	 * @return void
	 */
	public function post_synchronized( $post_id, $tr_id ) {
		unset( $this->processed[ (int) $tr_id ] );
		$this->process_post( (int) $tr_id );
	}

	/*
	 * Linked objects changes.
	 */

	/**
	 * Detects the modification of a post url.
	 *
	 * @since 4.1.0
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post object following the update.
	 * @param WP_Post $post_before Post object before the update.
	 * @return void
	 */
	public function post_updated( $post_id, $post_after, $post_before ) {
		if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post || 'revision' === $post_after->post_type ) {
			return;
		}

		$url_changed = $post_after->post_name !== $post_before->post_name || (int) $post_after->post_parent !== (int) $post_before->post_parent;

		if ( $url_changed || $post_after->post_status !== $post_before->post_status || $post_after->post_type !== $post_before->post_type ) {
			$this->touch_post( (int) $post_id );
		}

		// The urls of the children of a hierarchical post include its slug.
		if ( $url_changed && is_post_type_hierarchical( $post_after->post_type ) ) {
			foreach ( $this->get_post_descendants( (int) $post_id ) as $child_id ) {
				$this->touch_post( $child_id );
			}
		}
	}

	/**
	 * Detects the change of status of a post (e.g. publication of a scheduled post).
	 *
	 * @since 4.1.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function transition_post_status( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status && $post instanceof WP_Post && 'revision' !== $post->post_type ) {
			$this->touch_post( (int) $post->ID );
		}
	}

	/**
	 * Detects the modification of a term url.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function edited_term( $term_id, $tt_id, $taxonomy ) {
		$this->touch_term( (int) $term_id );

		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			$children = get_term_children( (int) $term_id, $taxonomy );
			foreach ( is_array( $children ) ? $children : array() as $child_id ) {
				$this->touch_term( (int) $child_id );
			}
		}
	}

	/**
	 * Updates all the indexed posts when the urls settings are modified.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $value     The new option value.
	 * @return void
	 */
	public function options_updated( $old_value, $value ) {
		foreach ( self::URL_OPTIONS as $key ) {
			$old = is_array( $old_value ) && isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
			$new = is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : null;

			if ( $old != $new ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
				$this->touch_all();
				return;
			}
		}
	}

	/**
	 * Marks a post as modified: the posts linking to it (or to its translations) will be updated.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function touch_post( $post_id ) {
		$this->touched_posts[ (int) $post_id ] = true;
	}

	/**
	 * Marks a term as modified: the posts linking to it (or to its translations) will be updated.
	 *
	 * @since 4.1.0
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function touch_term( $term_id ) {
		$this->touched_terms[ (int) $term_id ] = true;
	}

	/**
	 * Marks all the indexed posts to be updated.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function touch_all() {
		$this->touched_all = true;
	}

	/**
	 * Queues the posts linking to the objects modified during the request.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function flush() {
		global $wpdb;

		$queued = false;

		if ( $this->touched_all ) {
			$this->touched_all = false;
			$queued            = (bool) $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value )
					SELECT DISTINCT i.post_id, %s, '1' FROM {$wpdb->postmeta} AS i
					LEFT JOIN {$wpdb->postmeta} AS p ON p.post_id = i.post_id AND p.meta_key = %s
					WHERE i.meta_key = %s AND p.meta_id IS NULL",
					self::META_PENDING,
					self::META_PENDING,
					self::META_INDEX
				)
			); // The pending flag is only read from the database.
		}

		$targets = array();

		foreach ( array_keys( $this->touched_posts ) as $post_id ) {
			$targets[] = 'post:' . $post_id;
			foreach ( $this->model->post->get_translations( $post_id ) as $tr_id ) {
				$targets[] = 'post:' . $tr_id;
			}
		}

		foreach ( array_keys( $this->touched_terms ) as $term_id ) {
			$targets[] = 'term:' . $term_id;
			foreach ( $this->model->term->get_translations( $term_id ) as $tr_id ) {
				$targets[] = 'term:' . $tr_id;
			}
		}

		$this->touched_posts = array();
		$this->touched_terms = array();

		if ( ! empty( $targets ) ) {
			$queued = $this->queue( $this->get_linking_posts( array_unique( $targets ) ) ) || $queued;
		}

		if ( $queued ) {
			$this->schedule();
		}
	}

	/*
	 * Queue.
	 */

	/**
	 * Returns the posts linking to some objects.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $targets Objects, formatted as 'post:ID' or 'term:ID'.
	 * @return int[] Post IDs.
	 */
	public function get_linking_posts( array $targets ): array {
		global $wpdb;

		$post_ids = array();

		foreach ( array_chunk( $targets, 500 ) as $chunk ) {
			$post_ids = array_merge(
				$post_ids,
				$wpdb->get_col(
					$wpdb->prepare(
						sprintf(
							"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %%s AND meta_value IN (%s)",
							implode( ',', array_fill( 0, count( $chunk ), '%s' ) )
						),
						array_merge( array( self::META_TARGET ), $chunk )
					)
				)
			);
		}

		return array_values( array_unique( array_map( 'intval', $post_ids ) ) );
	}

	/**
	 * Queues posts whose links must be updated.
	 *
	 * @since 4.1.0
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return bool True if at least one post was queued.
	 */
	public function queue( array $post_ids ): bool {
		$queued = false;

		foreach ( $post_ids as $post_id ) {
			$queued = (bool) add_post_meta( (int) $post_id, self::META_PENDING, '1', true ) || $queued;
		}

		return $queued;
	}

	/**
	 * Schedules the processing of the queue.
	 *
	 * @since 4.1.0
	 *
	 * @param int $delay Delay in seconds.
	 * @return void
	 */
	public function schedule( int $delay = 0 ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	/**
	 * Starts updating the links of all the existing posts, in the background.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function start_scan() {
		update_option( self::OPTION_SCAN, 0, false );
		$this->schedule();
	}

	/**
	 * Returns the number of posts waiting to be updated.
	 *
	 * @since 4.1.0
	 *
	 * @return int
	 */
	public static function count_pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_PENDING ) );
	}

	/**
	 * Tells whether the update of all the existing posts is in progress.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	public static function is_scanning(): bool {
		return false !== get_option( self::OPTION_SCAN );
	}

	/**
	 * Processes a batch of the queue (cron callback).
	 *
	 * @since 4.1.0
	 *
	 * @param int $batch_size Optional. Maximum number of posts to process. Default self::BATCH_SIZE.
	 * @return int Number of processed posts.
	 */
	public function process_queue( $batch_size = self::BATCH_SIZE ) {
		global $wpdb;

		if ( ! $this->lock() ) {
			$this->schedule( MINUTE_IN_SECONDS );
			return 0;
		}

		$batch_size = max( 1, (int) $batch_size );
		$count      = 0;

		try {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT %d",
					self::META_PENDING,
					$batch_size
				)
			);

			foreach ( $post_ids as $post_id ) {
				$this->process_post( (int) $post_id, true );
				delete_post_meta( (int) $post_id, self::META_PENDING );
				++$count;
			}

			if ( $count < $batch_size && self::is_scanning() ) {
				$count += $this->scan( $batch_size - $count );
			}
		} finally {
			$this->unlock();
		}

		if ( self::count_pending() > 0 || self::is_scanning() ) {
			$this->schedule( 5 );
		}

		return $count;
	}

	/**
	 * Processes the next posts of the update of all the existing posts.
	 *
	 * @since 4.1.0
	 *
	 * @param int $batch_size Maximum number of posts to process.
	 * @return int Number of processed posts.
	 */
	protected function scan( int $batch_size ): int {
		$cursor   = (int) get_option( self::OPTION_SCAN, 0 );
		$post_ids = $this->get_posts_after( $cursor, $batch_size );

		foreach ( $post_ids as $post_id ) {
			$this->process_post( $post_id, true );
			$cursor = $post_id;
		}

		if ( count( $post_ids ) < $batch_size ) {
			delete_option( self::OPTION_SCAN );
		} else {
			update_option( self::OPTION_SCAN, $cursor, false );
		}

		return count( $post_ids );
	}

	/**
	 * Returns the IDs of the posts whose links can be corrected, after a given ID.
	 *
	 * @since 4.1.0
	 *
	 * @param int      $after      Post ID.
	 * @param int      $limit      Maximum number of IDs.
	 * @param string[] $post_types Optional. Post types, defaults to all translated post types.
	 * @return int[]
	 */
	public function get_posts_after( int $after, int $limit, array $post_types = array() ): array {
		global $wpdb;

		$post_types = empty( $post_types ) ? array_values( $this->model->get_translated_post_types() ) : $post_types;
		$post_types = array_diff( $post_types, array( 'attachment' ) );

		if ( empty( $post_types ) ) {
			return array();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				sprintf(
					"SELECT ID FROM {$wpdb->posts} WHERE ID > %%d AND post_type IN (%s) AND post_status NOT IN (%s) ORDER BY ID ASC LIMIT %%d",
					implode( ',', array_fill( 0, count( $post_types ), '%s' ) ),
					implode( ',', array_fill( 0, count( self::SKIPPED_STATUSES ), '%s' ) )
				),
				array_merge( array( $after ), $post_types, self::SKIPPED_STATUSES, array( $limit ) )
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Takes the processing lock.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	protected function lock(): bool {
		global $wpdb;

		// Atomic queries, as `add_option()` overwrites an existing value.
		$locked = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				self::OPTION_LOCK,
				time()
			)
		);

		if ( 1 !== $locked ) {
			// Take over a stale lock.
			$locked = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d",
					time(),
					self::OPTION_LOCK,
					time() - 10 * MINUTE_IN_SECONDS
				)
			);
		}

		wp_cache_delete( 'notoptions', 'options' );

		return 1 === $locked;
	}

	/**
	 * Releases the processing lock.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	protected function unlock() {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION_LOCK ) );
		wp_cache_delete( self::OPTION_LOCK, 'options' );
	}

	/**
	 * Returns the language a post was translated from, whose links are switched to the language of the post.
	 * It's the language of its translation source if known, the default language otherwise.
	 *
	 * @since 4.1.0
	 *
	 * @param int             $post_id  Post ID.
	 * @param Strope_Language $language Language of the post.
	 * @return Strope_Language|null Null if the post is in its source language.
	 */
	protected function get_source_language( int $post_id, Strope_Language $language ): ?Strope_Language {
		$meta   = get_post_meta( $post_id, '_strope_source', true ); // See Strope_Translation_Status::set_source().
		$source = is_array( $meta ) && ! empty( $meta['id'] ) ? (int) $meta['id'] : 0;

		if ( $source && $source !== $post_id && in_array( $source, array_map( 'intval', $this->model->post->get_translations( $post_id ) ), true ) ) {
			$source_language = $this->model->post->get_language( $source );
		} else {
			$source_language = $this->model->get_default_language();
		}

		return $source_language && $source_language->slug !== $language->slug ? $source_language : null;
	}

	/*
	 * Processing.
	 */

	/**
	 * Corrects the links of a post, directly in database.
	 *
	 * @since 4.1.0
	 *
	 * @param int  $post_id        Post ID.
	 * @param bool $save_revisions Optional. Whether to save revisions before and after the modification. Default false.
	 * @return int|null Number of corrected links, null if the post can't be processed.
	 */
	public function process_post( int $post_id, bool $save_revisions = false ): ?int {
		global $wpdb;

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->is_processable( $post->post_type, $post->post_status ) ) {
			return null;
		}

		$language = $this->model->post->get_language( $post_id );

		if ( ! $language ) {
			return null;
		}

		// During a save, don't process the same content twice. The background processing is always done, as the linked objects changed.
		if ( ! $save_revisions && isset( $this->processed[ $post_id ] ) && $this->processed[ $post_id ] === $this->get_state( $post ) ) {
			return 0;
		}

		$result = $this->content->fix( $post->post_content, $language, $this->get_index( $post_id ), $this->get_source_language( $post_id, $language ) );

		if ( $result['content'] !== $post->post_content ) {
			if ( $save_revisions ) {
				wp_save_post_revision( $post_id ); // Keeps the current content, if not already saved.
			}

			// Only if the content wasn't modified meanwhile (e.g. saved by an editor, whose save corrects the links itself).
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = %s WHERE ID = %d AND post_content = BINARY %s",
					$result['content'],
					$post_id,
					$post->post_content
				)
			);
			clean_post_cache( $post_id );

			if ( 1 !== $updated ) {
				return 0;
			}

			if ( $save_revisions ) {
				wp_save_post_revision( $post_id );
			}

			/**
			 * Fires after the links of a post have been corrected directly in database.
			 *
			 * @since 4.1.0
			 *
			 * @param int $post_id Post ID.
			 * @param int $changed Number of corrected links.
			 */
			do_action( 'strope_sticky_links_updated', $post_id, $result['changed'] );
		}

		$this->save_index( $post_id, $result );
		$this->processed[ $post_id ] = $this->get_state( get_post( $post_id ) );

		return $result['changed'];
	}

	/**
	 * Returns the links of a post (dry run).
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Result of the fix (see Strope_Sticky_Links_Content::fix()), null if the post can't be processed.
	 */
	public function preview_post( int $post_id ): ?array {
		$post     = get_post( $post_id );
		$language = $post ? $this->model->post->get_language( $post_id ) : false;

		if ( ! $post instanceof WP_Post || ! $language || ! $this->is_processable( $post->post_type, $post->post_status ) ) {
			return null;
		}

		return $this->content->fix( $post->post_content, $language, $this->get_index( $post_id ), $this->get_source_language( $post_id, $language ) );
	}

	/**
	 * Tells whether the links of a post can be corrected.
	 *
	 * @since 4.1.0
	 *
	 * @param string $post_type   Post type.
	 * @param string $post_status Post status.
	 * @return bool
	 */
	protected function is_processable( $post_type, $post_status ): bool {
		return 'revision' !== $post_type
			&& 'attachment' !== $post_type
			&& ! in_array( $post_status, self::SKIPPED_STATUSES, true )
			&& $this->model->is_translated_post_type( $post_type );
	}

	/**
	 * Returns a fingerprint of the language and content of a post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post|null $post Post object.
	 * @return string
	 */
	protected function get_state( $post ): string {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$language = $this->model->post->get_language( $post->ID );
		return ( $language ? $language->slug : '' ) . ':' . md5( $post->post_content );
	}

	/**
	 * Returns the links index of a post.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_index( int $post_id ): array {
		$index = get_post_meta( $post_id, self::META_INDEX, true );
		$valid = array();

		foreach ( is_array( $index ) ? $index : array() as $key => $entry ) {
			if ( is_string( $key ) && is_array( $entry ) && isset( $entry['type'], $entry['id'], $entry['source'] ) && in_array( $entry['type'], array( 'post', 'term' ), true ) ) {
				$valid[ $key ] = array(
					'type'   => $entry['type'],
					'id'     => absint( $entry['id'] ),
					'source' => absint( $entry['source'] ),
				);
			}
		}

		return $valid;
	}

	/**
	 * Saves the links index, the linked objects and the broken links of a post.
	 *
	 * @since 4.1.0
	 *
	 * @param int   $post_id Post ID.
	 * @param array $result  Result of the fix (see Strope_Sticky_Links_Content::fix()).
	 * @return void
	 */
	protected function save_index( int $post_id, array $result ) {
		if ( empty( $result['index'] ) ) {
			delete_post_meta( $post_id, self::META_INDEX );
		} elseif ( $this->get_index( $post_id ) !== $result['index'] ) {
			update_post_meta( $post_id, self::META_INDEX, $result['index'] );
		}

		$targets = array();
		foreach ( $result['index'] as $entry ) {
			$targets[] = $entry['type'] . ':' . $entry['id'];
			$targets[] = $entry['type'] . ':' . $entry['source'];
		}
		$targets = array_values( array_unique( $targets ) );
		sort( $targets );

		$existing = array_map( 'strval', get_post_meta( $post_id, self::META_TARGET ) );
		sort( $existing );

		if ( $existing !== $targets ) {
			delete_post_meta( $post_id, self::META_TARGET );
			foreach ( $targets as $target ) {
				add_post_meta( $post_id, self::META_TARGET, $target );
			}
		}

		if ( empty( $result['broken'] ) ) {
			delete_post_meta( $post_id, self::META_BROKEN );
		} else {
			update_post_meta( $post_id, self::META_BROKEN, $result['broken'] );
		}
	}

	/**
	 * Returns the IDs of the descendants of a post (all statuses and languages).
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	protected function get_post_descendants( int $post_id ): array {
		global $wpdb;

		$descendants = array();
		$parents     = array( $post_id );

		// Limit the depth to avoid infinite loops with corrupted data.
		for ( $depth = 0; $depth < 20 && ! empty( $parents ); $depth++ ) {
			$children = array_map(
				'intval',
				$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_parent IN (" . implode( ',', array_fill( 0, count( $parents ), '%d' ) ) . ") AND post_type <> 'revision'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %d placeholder per id.
						array_map( 'intval', $parents )
					)
				)
			);
			$parents     = array_diff( $children, $descendants, array( $post_id ) );
			$descendants = array_merge( $descendants, $parents );
		}

		return $descendants;
	}

	/**
	 * Returns the posts with broken links.
	 *
	 * @since 4.1.0
	 *
	 * @param int $limit Maximum number of posts.
	 * @return array[] Post ID and broken urls.
	 */
	public static function get_broken_links( int $limit = 50 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} AS m
				INNER JOIN {$wpdb->posts} AS p ON p.ID = m.post_id
				WHERE m.meta_key = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				ORDER BY m.post_id DESC LIMIT %d",
				self::META_BROKEN,
				$limit
			)
		);

		$posts = array();

		foreach ( $rows as $row ) {
			$urls = strope_maybe_unserialize( $row->meta_value );
			if ( is_array( $urls ) && ! empty( $urls ) ) {
				$posts[] = array(
					'post_id' => (int) $row->post_id,
					'urls'    => array_values( array_filter( $urls, 'is_string' ) ),
				);
			}
		}

		return $posts;
	}
}
