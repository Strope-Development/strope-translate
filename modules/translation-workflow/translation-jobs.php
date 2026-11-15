<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Translation jobs model.
 *
 * A job is stored as a non-public `strope_job` post:
 * - `post_author`: user who created the job,
 * - `post_content`: note (plain text),
 * - `post_date` / `post_modified`: creation and last update dates,
 * - metas: source post, target language, assignee (0 = any translator of the language), status, due date
 *   and resulting translation post.
 *
 * @since 4.1.0
 */
class Strope_Translation_Jobs {
	/**
	 * Post type storing the jobs.
	 *
	 * @var string
	 */
	const POST_TYPE = 'strope_job';

	/**
	 * Statuses of jobs which are still open.
	 *
	 * @var string[]
	 */
	const OPEN_STATUSES = array( 'new', 'in_progress', 'review' );

	/**
	 * Post meta storing the job of a new translation to add to the translations group of the job source.
	 *
	 * @var string
	 */
	const GROUP_META_KEY = '_strope_translation_job';

	/**
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * Avoids linking the same translation twice when a post status changes several times in a request.
	 *
	 * @var bool
	 */
	protected $updating = false;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Model $model Strope Translate model.
	 */
	public function __construct( &$model ) {
		$this->model = &$model;

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 20, 3 );
		add_action( 'wp_insert_post', array( $this, 'link_new_translation' ), 30, 3 );
		add_action( 'wp_after_insert_post', array( $this, 'link_translation_group' ), 20, 2 );
		add_action( 'strope_update_language', array( $this, 'update_language' ), 10, 2 );
		add_action( 'delete_language', array( $this, 'delete_language' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'before_delete_post' ) );
	}

	/**
	 * Registers the jobs post type.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Translation jobs', 'strope-translate' ),
					'singular_name' => __( 'Translation job', 'strope-translate' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'query_var'           => false,
				'rewrite'             => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'supports'            => array( 'author' ),
				// Nobody can edit jobs with the posts screens: they are managed only through the translation jobs page.
				'capability_type'     => array( 'strope_job', 'strope_jobs' ),
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * Returns the job statuses with their labels.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	public static function get_statuses() {
		return array(
			'new'         => __( 'New', 'strope-translate' ),
			'in_progress' => __( 'In progress', 'strope-translate' ),
			'review'      => __( 'Ready for review', 'strope-translate' ),
			'done'        => __( 'Done', 'strope-translate' ),
			'cancelled'   => __( 'Cancelled', 'strope-translate' ),
		);
	}

	/**
	 * Returns a job.
	 *
	 * @since 4.1.0
	 *
	 * @param int|WP_Post $job Job ID or post.
	 * @return array|false {
	 *   @type int    $id          Job ID.
	 *   @type int    $source      Source post ID.
	 *   @type string $lang        Target language slug.
	 *   @type int    $assignee    Assigned user ID, 0 for any translator of the language.
	 *   @type string $status      Status.
	 *   @type string $due         Due date (Y-m-d) or empty string.
	 *   @type string $note        Note.
	 *   @type int    $created_by  User who created the job.
	 *   @type string $created     Creation date (GMT, MySQL format).
	 *   @type string $updated     Last update date (GMT, MySQL format).
	 *   @type int    $translation Translation post ID, 0 if not created yet.
	 * }
	 */
	public function get( $job ) {
		$post = get_post( $job );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$status = (string) get_post_meta( $post->ID, '_strope_job_status', true );

		return array(
			'id'          => $post->ID,
			'source'      => (int) get_post_meta( $post->ID, '_strope_job_source', true ),
			'lang'        => (string) get_post_meta( $post->ID, '_strope_job_lang', true ),
			'assignee'    => (int) get_post_meta( $post->ID, '_strope_job_assignee', true ),
			'status'      => array_key_exists( $status, self::get_statuses() ) ? $status : 'new',
			'due'         => (string) get_post_meta( $post->ID, '_strope_job_due', true ),
			'note'        => $post->post_content,
			'created_by'  => (int) $post->post_author,
			'created'     => $post->post_date_gmt,
			'updated'     => $post->post_modified_gmt,
			'translation' => (int) get_post_meta( $post->ID, '_strope_job_translation', true ),
		);
	}

	/**
	 * Sanitizes a due date.
	 *
	 * @since 4.1.0
	 *
	 * @param string $date Date, expected in the Y-m-d format.
	 * @return string The date or an empty string if invalid.
	 */
	public static function sanitize_date( $date ) {
		$date = is_string( $date ) ? trim( $date ) : '';
		$dt   = DateTime::createFromFormat( '!Y-m-d', $date );
		return $dt && $dt->format( 'Y-m-d' ) === $date ? $date : '';
	}

	/**
	 * Creates a job.
	 *
	 * @since 4.1.0
	 *
	 * @param array $args {
	 *   @type int    $source     Source post ID.
	 *   @type string $lang       Target language slug.
	 *   @type int    $assignee   Optional assigned user, 0 for any translator of the language.
	 *   @type string $due        Optional due date (Y-m-d).
	 *   @type string $note       Optional note.
	 *   @type int    $created_by Optional user creating the job, defaults to the current user.
	 * }
	 * @return int|WP_Error Job ID.
	 */
	public function create( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'source'     => 0,
				'lang'       => '',
				'assignee'   => 0,
				'due'        => '',
				'note'       => '',
				'created_by' => get_current_user_id(),
			)
		);

		$source   = get_post( (int) $args['source'] );
		$language = $this->model->get_language( sanitize_key( (string) $args['lang'] ) );

		if ( ! $source instanceof WP_Post || ! $this->model->is_translated_post_type( $source->post_type ) || in_array( $source->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return new WP_Error( 'strope_job_invalid_source', __( 'This content cannot be translated.', 'strope-translate' ) );
		}

		$source_language = $this->model->post->get_language( $source->ID );

		if ( ! $language || ! $source_language || $source_language->slug === $language->slug ) {
			return new WP_Error( 'strope_job_invalid_language', __( 'Invalid target language.', 'strope-translate' ) );
		}

		if ( $this->find_open_job( $source->ID, $language->slug ) ) {
			return new WP_Error( 'strope_job_duplicate', __( 'An open translation job already exists for this content and language.', 'strope-translate' ) );
		}

		$assignee = (int) $args['assignee'];

		if ( $assignee && ! $this->user_is_translator_for( $assignee, $language->slug ) ) {
			return new WP_Error( 'strope_job_invalid_assignee', __( 'The selected user cannot translate into this language.', 'strope-translate' ) );
		}

		$translation = (int) $this->model->post->get_translation( $source->ID, $language );

		// wp_insert_post() expects slashed data.
		$job_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'publish',
					'post_author'  => (int) $args['created_by'],
					'post_title'   => sprintf( 'Job #%1$d %2$s', $source->ID, $language->slug ),
					'post_content' => sanitize_textarea_field( (string) $args['note'] ),
					'meta_input'   => array(
						'_strope_job_source'      => $source->ID,
						'_strope_job_lang'        => $language->slug,
						'_strope_job_assignee'    => $assignee,
						'_strope_job_status'      => 'new',
						'_strope_job_due'         => self::sanitize_date( (string) $args['due'] ),
						'_strope_job_translation' => $translation,
					),
				)
			),
			true
		);

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		/**
		 * Fires after a translation job has been created.
		 *
		 * @since 4.1.0
		 *
		 * @param int   $job_id Job ID.
		 * @param array $job    Job data.
		 */
		do_action( 'strope_translation_job_created', $job_id, $this->get( $job_id ) );

		return $job_id;
	}

	/**
	 * Updates some fields of a job.
	 *
	 * @since 4.1.0
	 *
	 * @param int   $job_id Job ID.
	 * @param array $fields Fields to update: 'status', 'assignee', 'translation', 'due', 'note'.
	 * @return bool
	 */
	public function update( $job_id, $fields ) {
		$job = $this->get( $job_id );

		if ( ! $job ) {
			return false;
		}

		$old_status = $job['status'];

		if ( isset( $fields['status'] ) && array_key_exists( $fields['status'], self::get_statuses() ) ) {
			update_post_meta( $job_id, '_strope_job_status', $fields['status'] );
		}

		if ( isset( $fields['assignee'] ) ) {
			update_post_meta( $job_id, '_strope_job_assignee', (int) $fields['assignee'] );
		}

		if ( isset( $fields['translation'] ) ) {
			update_post_meta( $job_id, '_strope_job_translation', (int) $fields['translation'] );
		}

		if ( isset( $fields['due'] ) ) {
			update_post_meta( $job_id, '_strope_job_due', self::sanitize_date( (string) $fields['due'] ) );
		}

		$postarr = array( 'ID' => $job_id );
		if ( isset( $fields['note'] ) ) {
			$postarr['post_content'] = sanitize_textarea_field( (string) $fields['note'] );
		}
		wp_update_post( wp_slash( $postarr ) ); // Updates the modified date. Expects slashed data.

		if ( isset( $fields['status'] ) && $fields['status'] !== $old_status && array_key_exists( $fields['status'], self::get_statuses() ) ) {
			/**
			 * Fires after the status of a translation job changed.
			 *
			 * @since 4.1.0
			 *
			 * @param int    $job_id     Job ID.
			 * @param string $new_status New status.
			 * @param string $old_status Old status.
			 * @param array  $job        Job data.
			 */
			do_action( 'strope_translation_job_status_changed', $job_id, $fields['status'], $old_status, $this->get( $job_id ) );
		}

		return true;
	}

	/**
	 * Returns the ID of an open job for a source post and a language.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Language slug.
	 * @return int Job ID, 0 if none.
	 */
	public function find_open_job( $source_id, $lang ) {
		$result = $this->query(
			array(
				'source'   => $source_id,
				'lang'     => $lang,
				'status'   => self::OPEN_STATUSES,
				'per_page' => 1,
			)
		);
		return empty( $result['jobs'] ) ? 0 : (int) $result['jobs'][0]['id'];
	}

	/**
	 * Queries jobs.
	 *
	 * @since 4.1.0
	 *
	 * @param array $args {
	 *   @type string|string[] $status     Statuses.
	 *   @type string|string[] $lang       Target languages.
	 *   @type int|int[]       $source     Source post IDs.
	 *   @type int             $assignee   Assignee (-1 for any).
	 *   @type int             $created_by Creator.
	 *   @type int             $for_user   Restricts to the jobs a translator can work on.
	 *   @type int             $per_page   Number of jobs, -1 for all.
	 *   @type int             $paged      Page.
	 *   @type string          $orderby    'date', 'modified' or 'due'.
	 *   @type string          $order      'ASC' or 'DESC'.
	 * }
	 * @return array {
	 *   @type array[] $jobs  Jobs.
	 *   @type int     $total Total number of jobs.
	 * }
	 */
	public function query( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'status'     => array(),
				'lang'       => array(),
				'source'     => array(),
				'assignee'   => -1,
				'created_by' => 0,
				'for_user'   => 0,
				'per_page'   => 20,
				'paged'      => 1,
				'orderby'    => 'date',
				'order'      => 'DESC',
			)
		);

		$meta_query = array( 'relation' => 'AND' );

		if ( ! empty( $args['status'] ) ) {
			$meta_query[] = array(
				'key'     => '_strope_job_status',
				'value'   => array_map( 'strval', (array) $args['status'] ),
				'compare' => 'IN',
			);
		}

		if ( ! empty( $args['lang'] ) ) {
			$meta_query[] = array(
				'key'     => '_strope_job_lang',
				'value'   => array_map( 'strval', (array) $args['lang'] ),
				'compare' => 'IN',
			);
		}

		if ( ! empty( $args['source'] ) ) {
			$meta_query[] = array(
				'key'     => '_strope_job_source',
				'value'   => array_map( 'intval', (array) $args['source'] ),
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			);
		}

		if ( $args['assignee'] >= 0 ) {
			$meta_query[] = array(
				'key'   => '_strope_job_assignee',
				'value' => (int) $args['assignee'],
				'type'  => 'NUMERIC',
			);
		}

		if ( $args['for_user'] ) {
			$langs = $this->get_translator_languages( (int) $args['for_user'] );

			$unassigned = array(
				'relation' => 'AND',
				array(
					'key'   => '_strope_job_assignee',
					'value' => 0,
					'type'  => 'NUMERIC',
				),
				array(
					'key'     => '_strope_job_lang',
					'value'   => empty( $langs ) ? array( '' ) : $langs,
					'compare' => 'IN',
				),
			);

			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'   => '_strope_job_assignee',
					'value' => (int) $args['for_user'],
					'type'  => 'NUMERIC',
				),
				$unassigned,
			);
		}

		$query_args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => (int) $args['per_page'],
			'paged'                  => max( 1, (int) $args['paged'] ),
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'order'                  => 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC',
			'orderby'                => 'modified' === $args['orderby'] ? 'modified' : 'date',
			'lang'                   => '',
			'suppress_filters'       => false,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'no_found_rows'          => -1 === (int) $args['per_page'],
		);

		if ( $args['created_by'] ) {
			$query_args['author'] = (int) $args['created_by'];
		}

		if ( 'due' === $args['orderby'] ) {
			$query_args['meta_key'] = '_strope_job_due'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query_args['orderby']  = 'meta_value';
		}

		$query = new WP_Query( $query_args );

		return array(
			'jobs'  => array_values( array_filter( array_map( array( $this, 'get' ), $query->posts ) ) ),
			'total' => -1 === (int) $args['per_page'] ? count( $query->posts ) : (int) $query->found_posts,
		);
	}

	/**
	 * Returns the languages a user can take unassigned jobs for.
	 *
	 * @since 4.1.0
	 *
	 * @param int $user_id User ID.
	 * @return string[] Language slugs.
	 */
	public function get_translator_languages( $user_id ) {
		// Users with the translator role and no languages assigned get array( '' ): they can't translate in any language.
		return array_values( array_filter( Strope_Translator_Languages::get_user_languages( $user_id ) ) );
	}

	/**
	 * Tells whether a user is a translator for a language: the language is assigned to the user.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $lang    Language slug.
	 * @return bool
	 */
	public function user_is_translator_for( $user_id, $lang ) {
		return user_can( $user_id, 'edit_posts' ) && in_array( $lang, $this->get_translator_languages( $user_id ), true );
	}

	/**
	 * Returns the translators of a language.
	 *
	 * @since 4.1.0
	 *
	 * @param string $lang Language slug, empty for all languages.
	 * @return WP_User[]
	 */
	public function get_translators( $lang = '' ) {
		$users = get_users(
			array(
				'fields'     => 'all',
				'number'     => 500,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Strope_Translator_Languages::get_meta_key(),
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$users = array_merge( $users, get_users( array( 'role' => Strope_Translation_Workflow::ROLE, 'number' => 500 ) ) );

		$translators = array();
		foreach ( $users as $user ) {
			if ( isset( $translators[ $user->ID ] ) ) {
				continue;
			}

			$langs = $this->get_translator_languages( $user->ID );

			if ( ! empty( $langs ) && ( '' === $lang || in_array( $lang, $langs, true ) ) && user_can( $user, 'edit_posts' ) ) {
				$translators[ $user->ID ] = $user;
			}
		}

		return array_values( $translators );
	}

	/**
	 * Tells whether a user can manage all jobs.
	 *
	 * @since 4.1.0
	 *
	 * @param int $user_id Optional user ID, defaults to the current user.
	 * @return bool
	 */
	public static function user_can_manage( $user_id = 0 ) {
		return user_can( $user_id ? $user_id : get_current_user_id(), Strope_Translation_Workflow::MANAGE_JOBS );
	}

	/**
	 * Tells whether a user can work on a job (start it, mark it as ready for review).
	 *
	 * @since 4.1.0
	 *
	 * @param array $job     Job data.
	 * @param int   $user_id Optional user ID, defaults to the current user.
	 * @return bool
	 */
	public function user_can_work_on( $job, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id || ! Strope_Translator_Languages::user_can_translate( $job['lang'], $user_id ) ) {
			return false;
		}

		if ( self::user_can_manage( $user_id ) ) {
			return true;
		}

		if ( $job['assignee'] ) {
			return $job['assignee'] === $user_id && user_can( $user_id, 'edit_posts' );
		}

		return $this->user_is_translator_for( $user_id, $job['lang'] );
	}

	/**
	 * Tells whether a user can see a job.
	 *
	 * @since 4.1.0
	 *
	 * @param array $job     Job data.
	 * @param int   $user_id Optional user ID, defaults to the current user.
	 * @return bool
	 */
	public function user_can_view( $job, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		return self::user_can_manage( $user_id ) || $job['created_by'] === $user_id || $this->user_can_work_on( $job, $user_id );
	}

	/**
	 * Tells whether a user can cancel a job (or reopen it, or mark it as done).
	 *
	 * @since 4.1.0
	 *
	 * @param array $job     Job data.
	 * @param int   $user_id Optional user ID, defaults to the current user.
	 * @return bool
	 */
	public function user_can_cancel( $job, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		return $user_id && ( self::user_can_manage( $user_id ) || $job['created_by'] === $user_id );
	}

	/**
	 * Returns the open job matching a new translation being created with the new translation flow
	 * (post-new.php?from_post=...&new_lang=...[&strope_job=ID]), if the current user can work on it.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post The new post (auto-draft).
	 * @return array|false Job.
	 */
	protected function get_job_for_new_translation( $post ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $GLOBALS['pagenow'], $_GET['from_post'], $_GET['new_lang'], $_GET['_wpnonce'] ) || 'post-new.php' !== $GLOBALS['pagenow'] ) {
			return false;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'new-post-translation' ) ) {
			return false;
		}

		$source_id = (int) $_GET['from_post'];
		$lang      = sanitize_key( $_GET['new_lang'] );
		$job_id    = isset( $_GET['strope_job'] ) ? (int) $_GET['strope_job'] : $this->find_open_job( $source_id, $lang );
		// phpcs:enable

		$job = $job_id ? $this->get( $job_id ) : false;

		if ( ! $job || $source_id !== $job['source'] || $lang !== $job['lang'] || ! in_array( $job['status'], self::OPEN_STATUSES, true ) ) {
			return false;
		}

		if ( ! $this->model->is_translated_post_type( $post->post_type ) || $post->post_type !== get_post_type( $job['source'] ) || ! $this->user_can_work_on( $job ) ) {
			return false;
		}

		return $job;
	}

	/**
	 * Links a new translation created for a job to the job.
	 * If the translator can't edit the source post, also remembers the job to add the translation
	 * to the translations group of the source when it is saved (the translations group can't be
	 * updated by the user in this case).
	 *
	 * @since 4.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public function link_new_translation( $post_id, $post, $update ) {
		if ( $update ) {
			return;
		}

		$job = $this->get_job_for_new_translation( $post );

		if ( ! $job ) {
			return;
		}

		$this->update( $job['id'], array( 'translation' => $post_id ) );

		if ( ! current_user_can( 'edit_post', $job['source'] ) ) {
			update_post_meta( $post_id, self::GROUP_META_KEY, $job['id'] );
		}
	}

	/**
	 * Adds a translation created for a job to the translations group of the job source,
	 * when the translator can't edit the source.
	 *
	 * Only done for a job the user can work on, if the translation language is the job language
	 * and if no translation exists yet in this language.
	 *
	 * @since 4.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function link_translation_group( $post_id, $post ) {
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return;
		}

		$job_id = (int) get_post_meta( $post_id, self::GROUP_META_KEY, true );

		if ( ! $job_id ) {
			return;
		}

		delete_post_meta( $post_id, self::GROUP_META_KEY );

		$job = $this->get( $job_id );

		if ( ! $job || 'cancelled' === $job['status'] || ( $job['translation'] && $job['translation'] !== $post_id ) || ! $this->user_can_work_on( $job ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$language        = $this->model->post->get_language( $post_id );
		$source_language = $this->model->post->get_language( $job['source'] );

		if ( ! $language || ! $source_language || $language->slug !== $job['lang'] || $language->slug === $source_language->slug ) {
			return;
		}

		$own_translations = array_diff( $this->model->post->get_translations( $post_id ), array( $post_id ) );
		$translations     = $this->model->post->get_translations( $job['source'] );

		// Never replace an existing translation, and don't merge two existing groups.
		if ( ( ! empty( $own_translations ) && ! in_array( $job['source'], $own_translations, true ) ) || ! empty( $translations[ $language->slug ] ) ) {
			return;
		}

		$translations[ $source_language->slug ] = $job['source'];
		$translations[ $language->slug ]        = $post_id;

		$this->model->post->save_translations( $job['source'], $translations );
	}

	/**
	 * Updates the jobs when a translation is published or submitted for review.
	 *
	 * @since 4.1.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function transition_post_status( $new_status, $old_status, $post ) {
		if ( $this->updating || ! in_array( $new_status, array( 'publish', 'pending' ), true ) || ! $this->model->is_translated_post_type( $post->post_type ) ) {
			return;
		}

		$job_status = 'publish' === $new_status ? 'done' : 'review';

		$language = $this->model->post->get_language( $post->ID );

		if ( ! $language ) {
			return;
		}

		$sources = array_diff( array_map( 'intval', $this->model->post->get_translations( $post->ID ) ), array( $post->ID ) );

		$jobs = $this->query(
			array(
				'lang'     => $language->slug,
				'status'   => array( 'new', 'in_progress', 'review' ),
				'source'   => empty( $sources ) ? array( -1 ) : $sources,
				'per_page' => -1,
			)
		)['jobs'];

		// Jobs linked to the post before the translations group was saved.
		$linked = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => '',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_strope_job_translation',
						'value' => $post->ID,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $linked->posts as $job_id ) {
			$job = $this->get( $job_id );
			if ( $job && in_array( $job['status'], self::OPEN_STATUSES, true ) && ! in_array( $job_id, wp_list_pluck( $jobs, 'id' ), true ) ) {
				$jobs[] = $job;
			}
		}

		$this->updating = true;

		foreach ( $jobs as $job ) {
			if ( $job['translation'] && $job['translation'] !== $post->ID && get_post( $job['translation'] ) && 'auto-draft' !== get_post_status( $job['translation'] ) ) {
				continue; // Another post is the translation of this job.
			}

			if ( $old_status === $new_status && 'in_progress' !== $job['status'] ) {
				continue; // A simple update of an existing translation doesn't complete a job which was not started.
			}

			$this->update(
				$job['id'],
				array(
					'translation' => $post->ID,
					'status'      => $job_status,
				)
			);
		}

		$this->updating = false;
	}

	/**
	 * Follows the language slug changes in the jobs.
	 *
	 * @since 4.1.0
	 *
	 * @param array           $args New language data, see Strope_Admin_Model::update_language().
	 * @param Strope_Language $lang Previous language.
	 * @return void
	 */
	public function update_language( $args, $lang ) {
		if ( ! $lang instanceof Strope_Language || empty( $args['slug'] ) || ! is_string( $args['slug'] ) || $args['slug'] === $lang->slug ) {
			return;
		}

		$job_ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => '',
				'meta_key'       => '_strope_job_lang', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $lang->slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $job_ids as $job_id ) {
			update_post_meta( $job_id, '_strope_job_lang', sanitize_key( $args['slug'] ) );
		}
	}

	/**
	 * Cancels the open jobs in a deleted language.
	 *
	 * @since 4.1.0
	 *
	 * @param int     $term_id      Term ID.
	 * @param int     $tt_id        Term taxonomy ID.
	 * @param WP_Term $deleted_term Deleted language term.
	 * @return void
	 */
	public function delete_language( $term_id, $tt_id, $deleted_term ) {
		if ( ! $deleted_term instanceof WP_Term ) {
			return;
		}

		foreach ( $this->query( array( 'lang' => $deleted_term->slug, 'status' => self::OPEN_STATUSES, 'per_page' => -1 ) )['jobs'] as $job ) {
			$this->update( $job['id'], array( 'status' => 'cancelled' ) );
		}
	}

	/**
	 * Cancels the open jobs of a deleted source post and unlinks a deleted translation.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function before_delete_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! $this->model->is_translated_post_type( $post->post_type ) ) {
			return;
		}

		foreach ( $this->query( array( 'source' => $post_id, 'status' => self::OPEN_STATUSES, 'per_page' => -1 ) )['jobs'] as $job ) {
			$this->update( $job['id'], array( 'status' => 'cancelled' ) );
		}

		$linked = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => '',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_strope_job_translation',
						'value' => $post_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $linked as $job_id ) {
			update_post_meta( $job_id, '_strope_job_translation', 0 );
		}
	}
}
