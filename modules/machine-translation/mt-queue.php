<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs of machine translation: what a user asked for at once (posts × languages), on top of the job queue
 * of the WASM worker kit (modules/machine-translation/lib/wasm-worker).
 *
 * A run is a small row of its own (owner, languages, status); each post and language is a job of the queue
 * (see Strope_MT_Task), in the group `run-<id>`. The queue hands out the segments to the browsers of the
 * editors, checks what comes back, retries, recovers from closed tabs, and finalizes each job once.
 *
 * @since 4.2.0
 */
class Strope_MT_Queue {
	/**
	 * Post meta storing what machine translation wrote in a post, to tell whether a person edited it since.
	 */
	const META = '_strope_mt';

	/**
	 * Maximum number of posts × languages in a run.
	 */
	const MAX_JOBS = 2000;

	/**
	 * Creates a run.
	 *
	 * @since 4.2.0
	 *
	 * @param int[]    $post_ids  Source posts.
	 * @param string[] $languages Target language slugs.
	 * @param array    $args      {
	 *     @type string $post_status Status of the created translations, `draft` or `publish`.
	 * }
	 * @return array|WP_Error {
	 *     @type int   $run_id  Run id.
	 *     @type int   $jobs    Number of jobs created.
	 *     @type array $skipped Messages of the skipped posts.
	 * }
	 */
	public static function create_run( array $post_ids, array $languages, array $args = array() ) {
		$model   = Strope()->model;
		$targets = array();

		foreach ( array_unique( array_map( 'strval', $languages ) ) as $slug ) {
			$language = $model->get_language( $slug );
			if ( $language instanceof Strope_Language && self::user_can_translate_language( $language ) ) {
				$targets[ $language->slug ] = $language;
			}
		}

		if ( empty( $targets ) ) {
			return new WP_Error( 'strope_mt_no_language', __( 'Choose at least one language you are allowed to translate into.', 'strope-translate' ) );
		}

		$post_status = isset( $args['post_status'] ) && 'publish' === $args['post_status'] ? 'publish' : 'draft';
		$planned     = array();
		$skipped     = array();
		$missing     = array();

		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || ! $model->is_translated_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$source = $model->post->get_language( $post->ID );
			if ( ! $source instanceof Strope_Language ) {
				/* translators: %s: post title */
				$skipped[] = sprintf( __( '"%s" has no language.', 'strope-translate' ), get_the_title( $post ) );
				continue;
			}

			foreach ( $targets as $target ) {
				if ( $target->slug === $source->slug ) {
					continue;
				}

				$route = Strope_MT_Engine_Files::get_route( Strope_MT_Engine_Files::get_code( $source ), Strope_MT_Engine_Files::get_code( $target ) );

				if ( '' === $route ) {
					continue; // Same engine language, e.g. two variants of English.
				}

				if ( ! Strope_MT_Engine_Files::is_route_installed( $route ) ) {
					/* translators: 1: source language, 2: target language */
					$missing[ $route ] = sprintf( __( '%1$s to %2$s', 'strope-translate' ), $source->name, $target->name );
					continue;
				}

				$planned[] = array(
					'post'   => $post->ID,
					'source' => $source->slug,
					'target' => $target->slug,
					'route'  => $route,
					'code'   => Strope_MT_Engine_Files::get_code( $target ),
					'status' => self::get_allowed_status( $post, $post_status ),
					'title'  => get_the_title( $post ),
					'name'   => $target->name,
				);
			}
		}

		if ( $missing ) {
			return new WP_Error(
				'strope_mt_missing_models',
				sprintf(
					/* translators: %s: list of language pairs */
					__( 'Install the translation models for these languages first: %s.', 'strope-translate' ),
					implode( ', ', $missing )
				)
			);
		}

		if ( empty( $planned ) ) {
			return new WP_Error( 'strope_mt_nothing', $skipped ? implode( ' ', $skipped ) : __( 'There is nothing to translate.', 'strope-translate' ) );
		}

		if ( count( $planned ) > self::MAX_JOBS ) {
			return new WP_Error(
				'strope_mt_too_many',
				sprintf(
					/* translators: %d: maximum number of translations */
					__( 'Choose fewer posts: at most %d translations can be added at once.', 'strope-translate' ),
					self::MAX_JOBS
				)
			);
		}

		Strope_MT_Store::maybe_install();

		$now    = Strope_MT_Store::now();
		$run_id = Strope_MT_Store::insert(
			'runs',
			array(
				'user_id' => get_current_user_id(),
				'status'  => Strope_MT_Store::RUN_ACTIVE,
				'args'    => '',
				'created' => $now,
				'updated' => $now,
			)
		);

		if ( ! $run_id ) {
			return new WP_Error( 'strope_mt_db', __( 'The translation could not be started.', 'strope-translate' ) );
		}

		$kit   = Strope_Machine_Translation::kit();
		$group = self::group( $run_id );
		$jobs  = array();

		foreach ( $planned as $job ) {
			$title = $job['title'];
			$name  = $job['name'];
			unset( $job['title'], $job['name'] );

			$job['run'] = $run_id;

			// One open job per post and language: the key returns the job in progress instead of a new one.
			$job_id = $kit->enqueue(
				Strope_MT_Task::TYPE,
				$job,
				array(
					'key'   => 'post:' . $job['post'] . ':' . $job['target'],
					'rerun' => true,
					'group' => $group,
				)
			);

			if ( is_wp_error( $job_id ) ) {
				$skipped[] = $job_id->get_error_message();
			} elseif ( $group !== ( $kit->job( $job_id )['group'] ?? '' ) ) {
				/* translators: 1: post title, 2: language name */
				$skipped[] = sprintf( __( '"%1$s" is already being translated into %2$s.', 'strope-translate' ), $title, $name );
			} else {
				$jobs[ $job_id ] = array( $job['post'], $job['target'] );
			}
		}

		if ( empty( $jobs ) ) {
			global $wpdb;
			$wpdb->delete( Strope_MT_Store::table( 'runs' ), array( 'id' => $run_id ) );
			return new WP_Error( 'strope_mt_nothing', implode( ' ', array_slice( $skipped, 0, 5 ) ) );
		}

		// Extract the text of the first posts right away, the queue does the rest as the workers ask for work.
		$kit->queue()->plan_pending( 1.5 );

		Strope_MT_Store::update(
			'runs',
			array(
				'args' => Strope_MT_Store::encode(
					array(
						'post_status' => $post_status,
						'languages'   => array_keys( $targets ),
						'skipped'     => count( $skipped ),
						'jobs'        => (object) $jobs,
					)
				),
			),
			array( 'id' => $run_id )
		);

		return array(
			'run_id'  => $run_id,
			'jobs'    => count( $jobs ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Returns the queue group of a run.
	 *
	 * @since 4.2.0
	 *
	 * @param int $run_id Run id.
	 * @return string
	 */
	public static function group( int $run_id ): string {
		return 'run-' . $run_id;
	}

	/**
	 * Tells whether the current user can translate into a language (translator languages).
	 *
	 * @since 4.2.0
	 *
	 * @param Strope_Language $language Language.
	 * @return bool
	 */
	public static function user_can_translate_language( $language ): bool {
		if ( ! current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			return false;
		}

		return ! function_exists( 'strope_user_can_translate_language' ) || strope_user_can_translate_language( $language );
	}

	/**
	 * Returns the status a machine translation of a post can be saved with: published only when it was
	 * asked for, the current user can publish this type of content, and the source is itself public.
	 *
	 * @since 4.3.0
	 *
	 * @param WP_Post $source    Source post.
	 * @param string  $requested Requested status.
	 * @return string `publish` or `draft`.
	 */
	public static function get_allowed_status( WP_Post $source, string $requested ): string {
		if ( 'publish' !== $requested || 'publish' !== $source->post_status || '' !== (string) $source->post_password ) {
			return 'draft';
		}

		$type = get_post_type_object( $source->post_type );

		return $type && current_user_can( $type->cap->publish_posts ) ? 'publish' : 'draft';
	}

	/**
	 * Saves the translated post of a job. Called by the finalization of the job, as the user who asked for it.
	 *
	 * @since 4.2.0
	 *
	 * @param array    $input        Job input.
	 * @param array[]  $document     Entries of the post: `c` (context), `s` (source), `p` (text parts and
	 *                               segment indexes).
	 * @param string[] $translations Translated segments by index.
	 * @param string   $source_hash  Hash of the source post when its text was extracted, empty if unknown.
	 * @return int|WP_Error Translated post id.
	 */
	public static function save_translation( array $input, array $document, array $translations, string $source_hash = '' ) {
		$strope = Strope();
		$source = get_post( (int) $input['post'] );
		$target = $strope->model->get_language( (string) $input['target'] );

		if ( ! $source instanceof WP_Post || ! $target instanceof Strope_Language ) {
			return new WP_Error( 'strope_mt_gone', __( 'The post or its language no longer exists.', 'strope-translate' ) );
		}

		$tr_id = (int) $strope->model->post->get( $source->ID, $target );
		if ( $tr_id && ! self::can_overwrite( $tr_id ) ) {
			return new WP_Error( 'strope_mt_edited', __( 'The translation was edited by a person in the meantime, it is kept.', 'strope-translate' ) );
		}

		$user_can = $tr_id ? current_user_can( 'edit_post', $tr_id ) : current_user_can( 'edit_post', $source->ID );
		if ( ! $user_can ) {
			return new WP_Error( 'strope_mt_forbidden', __( 'You are not allowed to edit this translation.', 'strope-translate' ) );
		}

		$entries = new Translations();

		foreach ( $document as $entry ) {
			$text = '';
			foreach ( (array) $entry['p'] as $part ) {
				$text .= is_int( $part ) ? (string) ( $translations[ $part ] ?? '' ) : (string) $part;
			}

			$entries->add_entry(
				new Translation_Entry(
					array(
						'singular'     => (string) $entry['s'],
						'context'      => (string) $entry['c'],
						'translations' => array( $text ),
					)
				)
			);
		}

		// The import model creates the posts with `get_default_post_to_edit()`, not loaded in REST requests.
		if ( ! function_exists( 'get_default_post_to_edit' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$model = new Strope_Translation_Post_Model( $strope );
		$tr_id = $model->translate(
			array(
				'id'     => $source->ID,
				'fields' => array( 'post_status' => self::get_allowed_status( $source, (string) ( $input['status'] ?? '' ) ) ),
				'data'   => $entries,
			),
			$target
		);

		if ( ! $tr_id ) {
			return new WP_Error( 'strope_mt_save', __( 'The translation could not be saved.', 'strope-translate' ) );
		}

		$model->translate_parents( array( $source->ID ), $target );

		/** This action is documented in modules/import-export/import/import-action.php. */
		do_action( 'strope_after_post_import', $target, array( $source->ID ) );

		if ( class_exists( 'Strope_Translation_Status' ) && Strope_Translation_Status::is_enabled() ) {
			if ( '' !== $source_hash ) {
				// The translation is up to date with the text it was made from, not with a later edit of the source.
				Strope_Translation_Status::transaction(
					$tr_id,
					function () use ( $tr_id, $source, $source_hash ) {
						Strope_Translation_Status::set_source( $tr_id, $source->ID, $source_hash );
					}
				);
			}

			Strope_Translation_Status::mark_needs_review( $tr_id, 'machine' );
		}

		update_post_meta(
			$tr_id,
			self::META,
			array(
				'hash'   => self::content_hash( $tr_id ),
				'v'      => 2,
				'time'   => time(),
				'run'    => (int) ( $input['run'] ?? 0 ),
				'engine' => 'bergamot ' . Strope_MT_Engine_Files::ENGINE_RELEASE,
			)
		);

		/**
		 * Fires after a post was machine translated.
		 *
		 * @since 4.2.0
		 *
		 * @param int             $tr_id     Translated post id.
		 * @param int             $source_id Source post id.
		 * @param Strope_Language $target    Target language.
		 */
		do_action( 'strope_mt_post_translated', $tr_id, $source->ID, $target );

		return (int) $tr_id;
	}

	/**
	 * Tells whether an existing translation can be replaced: it is a machine translation nobody edited
	 * since, a duplicate of the source nobody translated, or empty.
	 *
	 * @since 4.2.0
	 *
	 * @param int $tr_id Translated post id.
	 * @return bool
	 */
	public static function can_overwrite( int $tr_id ): bool {
		$post = get_post( $tr_id );

		if ( ! $post instanceof WP_Post ) {
			return true;
		}

		if ( '' === trim( $post->post_title . $post->post_content . $post->post_excerpt ) ) {
			return true;
		}

		$meta = get_post_meta( $tr_id, self::META, true );
		// Machine translations saved before 1.0.0 have a hash of the title, content and excerpt only.
		if ( is_array( $meta ) && isset( $meta['hash'] ) && hash_equals( (string) $meta['hash'], self::content_hash( $tr_id, isset( $meta['v'] ) ? (int) $meta['v'] : 1 ) ) ) {
			return true;
		}

		if ( class_exists( 'Strope_Translation_Status' ) ) {
			$review = get_post_meta( $tr_id, Strope_Translation_Status::META_REVIEW, true );

			if ( is_array( $review ) && 'duplicate' === ( $review['method'] ?? '' ) ) {
				// Nobody saved the duplicate yet.
				if ( ! empty( $review['pending'] ) ) {
					return true;
				}

				// Saved by a person: still replaceable only if its texts are those of the source.
				$source_id = (int) Strope_Translation_Status::get_source_id( $tr_id );

				return $source_id > 0 && '' !== self::content_hash( $source_id, 1 ) && self::content_hash( $source_id, 1 ) === self::content_hash( $tr_id, 1 );
			}
		}

		return false;
	}

	/**
	 * Returns the hash of the texts of a post, to know whether a person edited a machine translation.
	 *
	 * @since 4.2.0
	 * @since 4.3.0 Version 2 also covers the translatable custom fields.
	 *
	 * @param int $post_id Post id.
	 * @param int $version 1: title, content and excerpt, as stored before 1.0.0. 2: every translatable text.
	 * @return string
	 */
	protected static function content_hash( int $post_id, int $version = 2 ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$hash = md5( $post->post_title . "\0" . $post->post_content . "\0" . $post->post_excerpt );

		if ( $version < 2 ) {
			return $hash;
		}

		$language = Strope()->model->post->get_language( $post_id );

		if ( ! $language instanceof Strope_Language ) {
			return $hash;
		}

		// Everything exported for translation, custom fields included.
		return md5( $hash . "\0" . wp_json_encode( Strope_MT_Export_Collector::collect_post( $post, $language, $language ) ) );
	}

	/**
	 * Tells whether the current user can manage a run: its author, or a languages manager.
	 *
	 * @since 4.2.0
	 *
	 * @param object $run Run row.
	 * @return bool
	 */
	public static function user_can_manage_run( $run ): bool {
		return $run && ( (int) $run->user_id === get_current_user_id() || current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) );
	}

	/**
	 * Pauses, resumes, cancels a run, or retries its failed translations.
	 *
	 * @since 4.2.0
	 *
	 * @param int    $run_id Run id.
	 * @param string $action `pause`, `resume`, `cancel` or `retry`.
	 * @return true|WP_Error
	 */
	public static function control_run( int $run_id, string $action ) {
		$run = Strope_MT_Store::get_run( $run_id );

		if ( ! $run || ! self::user_can_manage_run( $run ) ) {
			return new WP_Error( 'strope_mt_forbidden', __( 'Sorry, you are not allowed to change this translation.', 'strope-translate' ), array( 'status' => 403 ) );
		}

		$statuses = array(
			'pause'  => Strope_MT_Store::RUN_PAUSED,
			'resume' => Strope_MT_Store::RUN_ACTIVE,
			'cancel' => Strope_MT_Store::RUN_CANCELLED,
			'retry'  => Strope_MT_Store::RUN_ACTIVE,
		);

		if ( ! isset( $statuses[ $action ] ) ) {
			return new WP_Error( 'strope_mt_action', __( 'Unknown action.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		// Failed parts are translated again; the translated ones are kept by the queue.
		$changed = Strope_Machine_Translation::kit()->control_group( self::group( $run_id ), $action );

		if ( is_wp_error( $changed ) ) {
			return $changed;
		}

		if ( $changed || 'cancel' === $action ) {
			$args = $run->args;
			unset( $args['final'] );
			Strope_MT_Store::update(
				'runs',
				array(
					'status'  => $statuses[ $action ],
					'args'    => Strope_MT_Store::encode( $args ),
					'updated' => Strope_MT_Store::now(),
				),
				array( 'id' => $run_id )
			);
		}

		return true;
	}

	/**
	 * Returns the recent runs with their progress.
	 *
	 * @since 4.2.0
	 *
	 * @param int $limit Number of runs.
	 * @return array[]
	 */
	public static function get_runs( int $limit = 20 ): array {
		global $wpdb;

		Strope_MT_Store::maybe_install();

		$runs = Strope_MT_Store::table( 'runs' );

		// Languages managers see all the runs, the other users their own runs.
		$where = current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) ? '1=1' : $wpdb->prepare( 'user_id = %d', get_current_user_id() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $runs WHERE $where ORDER BY id DESC LIMIT %d", $limit ) );
		$out  = array();

		foreach ( $rows as $run ) {
			$run->args = Strope_MT_Store::decode( $run->args );
			$summary   = self::summary( $run );
			$user      = get_userdata( (int) $run->user_id );
			$jobs      = $summary['jobs'];

			$out[] = array(
				'id'         => (int) $run->id,
				'status'     => $summary['status'],
				'user'       => $user ? $user->display_name : '',
				'created'    => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $run->created . ' UTC' ) ), // Stored in GMT.
				'languages'  => array_values( array_filter( array_map( array( self::class, 'language_name' ), (array) ( $run->args['languages'] ?? array() ) ) ) ),
				'jobs'       => array(
					'total'   => $jobs['total'] + (int) ( $run->args['skipped'] ?? 0 ),
					'done'    => $jobs['completed'],
					'skipped' => (int) ( $run->args['skipped'] ?? 0 ),
					'failed'  => $jobs['failed'],
					'open'    => $jobs['active'] + $jobs['waiting'] + $jobs['paused'] + $jobs['finalizing'],
				),
				'progress'   => (float) $summary['progress'],
				'can_manage' => self::user_can_manage_run( $run ),
			);
		}

		return $out;
	}

	/**
	 * Returns the queue summary and the status of a run. A finished run keeps its summary (no more queries).
	 *
	 * @since 4.2.0
	 *
	 * @param object $run Run row, with decoded `args`.
	 * @return array Summary of the queue group, plus `status`.
	 */
	protected static function summary( $run ): array {
		if ( isset( $run->args['final'] ) && is_array( $run->args['final'] ) ) {
			return $run->args['final'] + array( 'status' => $run->status );
		}

		$summary = Strope_Machine_Translation::kit()->group( self::group( (int) $run->id ) );

		if ( Strope_MT_Store::RUN_CANCELLED === $run->status ) {
			$status = Strope_MT_Store::RUN_CANCELLED;
		} elseif ( ! $summary['open'] ) {
			$status = Strope_MT_Store::RUN_DONE;
		} elseif ( Strope_MT_Store::RUN_PAUSED === $run->status && ! $summary['jobs']['active'] ) {
			$status = Strope_MT_Store::RUN_PAUSED;
		} else {
			$status = Strope_MT_Store::RUN_ACTIVE;
		}

		// A cancelled run shows what was translated, not what was skipped.
		if ( Strope_MT_Store::RUN_CANCELLED === $status ) {
			$units               = $summary['units'];
			$total               = array_sum( $units );
			$summary['progress'] = $total ? round( $units['completed'] / $total, 4 ) : 0.0;
		}

		// Over: keep the summary in the run, so that the list does not ask the queue again.
		if ( ! $summary['open'] ) {
			$args          = $run->args;
			$args['final'] = array(
				'jobs'     => $summary['jobs'],
				'progress' => $summary['progress'],
			);
			Strope_MT_Store::update(
				'runs',
				array(
					'status'  => $status,
					'args'    => Strope_MT_Store::encode( $args ),
					'updated' => Strope_MT_Store::now(),
				),
				array( 'id' => (int) $run->id )
			);
		}

		return $summary + array( 'status' => $status );
	}

	/**
	 * Returns the translations of a run, with their status.
	 *
	 * @since 4.2.0
	 *
	 * @param object $run Run row, with decoded `args`.
	 * @return array[]
	 */
	public static function get_run_jobs( $run ): array {
		$kit    = Strope_Machine_Translation::kit();
		$labels = array(
			'waiting'    => 'queued',
			'active'     => 'translating',
			'paused'     => 'paused',
			'finalizing' => 'saving',
			'completed'  => 'done',
			'failed'     => 'failed',
			'cancelled'  => 'cancelled',
		);
		$out    = array();

		foreach ( array_slice( (array) ( $run->args['jobs'] ?? array() ), 0, 500, true ) as $job_id => $ref ) {
			$job = $kit->job( (int) $job_id );
			if ( ! $job ) {
				continue; // Pruned.
			}

			$result = (array) $job['result'];
			$out[]  = array(
				'id'       => (int) $job_id,
				'title'    => get_the_title( (int) $ref[0] ),
				'language' => self::language_name( $ref[1] ),
				'status'   => $labels[ $job['state'] ] ?? $job['state'],
				'message'  => (string) $job['error'],
				'progress' => (float) $job['progress'],
				'edit'     => ! empty( $result['post'] ) ? (string) get_edit_post_link( (int) $result['post'], 'raw' ) : '',
			);
		}

		return $out;
	}

	/**
	 * Returns the name of a language from its slug.
	 *
	 * @since 4.2.0
	 *
	 * @param string $slug Language slug.
	 * @return string
	 */
	protected static function language_name( $slug ): string {
		$language = Strope()->model->get_language( (string) $slug );
		return $language ? $language->name : (string) $slug;
	}

	/**
	 * Counts the work waiting for a worker.
	 *
	 * @since 4.2.0
	 *
	 * @return int
	 */
	public static function count_waiting(): int {
		return Strope_Machine_Translation::kit()->queue()->waiting();
	}

	/**
	 * Deletes old runs and unused memory entries. The queue prunes its own finished jobs.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;

		Strope_MT_Store::maybe_install();

		$runs   = Strope_MT_Store::table( 'runs' );
		$memory = Strope_MT_Store::table( 'memory' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $runs WHERE status IN ('done','cancelled') AND updated < %s", Strope_MT_Store::now( -30 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $memory WHERE used < %s", Strope_MT_Store::now( -365 * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}
}
