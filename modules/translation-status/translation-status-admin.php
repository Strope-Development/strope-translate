<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translation status in the admin: badges in the language columns of the posts list tables, list filter,
 * classic editor metabox, block editor panel assets.
 *
 * @since 4.1.0
 */
class Strope_Translation_Status_Admin {
	const QUERY_VAR = 'strope_translation_status';
	const ACTION    = 'strope_translation_status';

	/**
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * Status of the rows already displayed, post IDs as keys.
	 *
	 * @var array[]
	 */
	protected $rows = array();

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Admin_Base $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->model = &$strope_translate->model;

		add_action( 'admin_init', array( $this, 'add_column_hooks' ) );
		add_action( 'restrict_manage_posts', array( $this, 'restrict_manage_posts' ), 20, 2 );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_filter( 'the_posts', array( $this, 'prime_list_caches' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 20, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Returns the asset suffix.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	protected static function suffix() {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
	}

	/**
	 * Adds the hooks displaying the status in the language columns (after the plugin's own output).
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function add_column_hooks() {
		foreach ( Strope_Translation_Status::get_post_types() as $post_type ) {
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'post_column' ), 20, 2 );
		}
	}

	/**
	 * Tells whether a query is the main query of a posts list table of a tracked post type.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	protected function is_list_query( $query ) {
		if ( ! $query instanceof WP_Query || ! $query->is_main_query() || ! is_admin() || ! isset( $GLOBALS['pagenow'] ) || 'edit.php' !== $GLOBALS['pagenow'] ) {
			return false;
		}

		$post_type = $query->get( 'post_type' );
		$post_type = empty( $post_type ) ? 'post' : $post_type;

		return is_string( $post_type ) && Strope_Translation_Status::is_tracked_post_type( $post_type );
	}

	/**
	 * Primes the caches of the listed posts and their translations to avoid N+1 queries.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post[] $posts Posts.
	 * @param WP_Query  $query Query.
	 * @return WP_Post[]
	 */
	public function prime_list_caches( $posts, $query ) {
		if ( ! empty( $posts ) && is_array( $posts ) && $this->is_list_query( $query ) ) {
			Strope_Translation_Status::prime_caches( wp_list_pluck( $posts, 'ID' ) );
		}
		return $posts;
	}

	/**
	 * Returns the badges HTML of a status entry.
	 *
	 * @since 4.1.0
	 *
	 * @param array $entry Status entry.
	 * @return string
	 */
	public static function get_badges_html( array $entry ) {
		$out = '';

		if ( Strope_Translation_Status::STATUS_OUTDATED === $entry['status'] ) {
			$text = __( 'Outdated: the source changed since this translation was last updated', 'strope-translate' );
			$out .= sprintf(
				'<span class="strope-ts-badge strope-ts-badge-outdated" title="%1$s"><span class="screen-reader-text">%2$s</span></span>',
				esc_attr( $text ),
				esc_html( $text )
			);
		}

		if ( ! empty( $entry['needs_review'] ) ) {
			$text = __( 'Needs review: this content was not written by a human', 'strope-translate' );
			$out .= sprintf(
				'<span class="strope-ts-badge strope-ts-badge-review" title="%1$s"><span class="screen-reader-text">%2$s</span></span>',
				esc_attr( $text ),
				esc_html( $text )
			);
		}

		return $out;
	}

	/**
	 * Displays the status badges in the language columns of the posts list table.
	 *
	 * @since 4.1.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function post_column( $column, $post_id ) {
		if ( 0 !== strpos( $column, 'language_' ) ) {
			return;
		}

		$post_id = (int) $post_id;

		if ( ! isset( $this->rows[ $post_id ] ) || wp_doing_ajax() ) {
			$this->rows[ $post_id ] = (array) Strope_Translation_Status::get_status( $post_id );
		}

		$slug = substr( $column, 9 );

		if ( empty( $this->rows[ $post_id ][ $slug ]['translation_id'] ) ) {
			return;
		}

		echo self::get_badges_html( $this->rows[ $post_id ][ $slug ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Returns the filter options.
	 *
	 * @since 4.1.0
	 *
	 * @return string[] Labels with values as keys.
	 */
	protected function get_filter_options() {
		$options = array();

		foreach ( $this->model->get_languages_list() as $language ) {
			/* translators: %s is a language name */
			$options[ 'missing-' . $language->slug ] = sprintf( __( 'Missing in %s', 'strope-translate' ), $language->name );
		}

		$options['outdated']     = __( 'Outdated', 'strope-translate' );
		$options['needs_review'] = __( 'Needs review', 'strope-translate' );

		return $options;
	}

	/**
	 * Returns the requested filter value.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	protected function get_requested_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		if ( '' === $value ) {
			return '';
		}

		// Don't use the translated labels here: this may run before `init`.
		$valid = array( 'outdated', 'needs_review' );

		foreach ( $this->model->get_languages_list( array( 'fields' => 'slug' ) ) as $slug ) {
			$valid[] = 'missing-' . $slug;
		}

		return in_array( $value, $valid, true ) ? $value : '';
	}

	/**
	 * Displays the "Translation status" filter in the posts list tables.
	 *
	 * @since 4.1.0
	 *
	 * @param string $post_type Post type.
	 * @param string $which     The location of the extra table nav markup.
	 * @return void
	 */
	public function restrict_manage_posts( $post_type, $which = 'top' ) {
		if ( 'top' !== $which || ! Strope_Translation_Status::is_tracked_post_type( $post_type ) ) {
			return;
		}

		$selected = $this->get_requested_filter();

		printf(
			'<label class="screen-reader-text" for="strope-filter-translation-status">%s</label><select name="%s" id="strope-filter-translation-status"><option value="">%s</option>',
			esc_html__( 'Filter by translation status', 'strope-translate' ),
			esc_attr( self::QUERY_VAR ),
			esc_html__( 'All translation statuses', 'strope-translate' )
		);

		foreach ( $this->get_filter_options() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}

		echo '</select>';
	}

	/**
	 * Filters the posts list table query.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public function pre_get_posts( $query ) {
		if ( ! $this->is_list_query( $query ) ) {
			return;
		}

		$filter = $this->get_requested_filter();

		if ( '' === $filter ) {
			return;
		}

		if ( 0 === strpos( $filter, 'missing-' ) ) {
			$language = $this->model->get_language( substr( $filter, 8 ) );

			if ( $language ) {
				$query->set( 'strope_ts_missing', $language->slug );
				add_filter( 'posts_where', array( $this, 'posts_where_missing' ), 10, 2 );
			}
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		$meta_query = is_array( $meta_query ) ? $meta_query : array();

		$meta_query[] = array(
			'key'     => 'outdated' === $filter ? Strope_Translation_Status::META_OUTDATED : Strope_Translation_Status::META_REVIEW,
			'compare' => 'EXISTS',
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Adds the WHERE clause selecting the posts which are not translated in a language.
	 *
	 * @since 4.1.0
	 *
	 * @param string   $where WHERE clause.
	 * @param WP_Query $query Query.
	 * @return string
	 */
	public function posts_where_missing( $where, $query ) {
		global $wpdb;

		$slug = $query->get( 'strope_ts_missing' );

		if ( empty( $slug ) || ! is_string( $slug ) ) {
			return $where;
		}

		$language = $this->model->get_language( $slug );

		if ( $language ) {
			$where .= ' AND ' . Strope_Translation_Status::get_missing_sql( $language, "{$wpdb->posts}.ID" );
		}

		return $where;
	}

	/**
	 * Adds the translation status metabox in the classic editor, below the Languages metabox.
	 *
	 * @since 4.1.0
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 * @return void
	 */
	public function add_meta_boxes( $post_type, $post = null ) {
		if ( ! Strope_Translation_Status::is_tracked_post_type( $post_type ) ) {
			return;
		}

		add_meta_box(
			'strope-translation-status',
			__( 'Translation status', 'strope-translate' ),
			array( $this, 'metabox' ),
			$post_type,
			'side',
			'high',
			array( '__back_compat_meta_box' => true ) // The block editor has its own panel.
		);
	}

	/**
	 * Returns the URL of an action on a post.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Action.
	 * @return string
	 */
	public static function get_action_url( $post_id, $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => (int) $post_id,
					'do'     => $action,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . (int) $post_id
		);
	}

	/**
	 * Displays the translation status metabox.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function metabox( $post ) {
		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'The translation status will be available once the post is saved.', 'strope-translate' ) . '</p>';
			return;
		}

		$status = Strope_Translation_Status_REST::prepare_status( $post->ID );

		if ( empty( $status ) ) {
			return;
		}

		echo '<ul class="strope-ts-list">';

		foreach ( $status as $entry ) {
			$language = $this->model->get_language( $entry['lang'] );
			$flag     = $language && $language->flag ? $language->flag : '<abbr>' . esc_html( $entry['lang'] ) . '</abbr>';
			$label    = Strope_Translation_Status::get_status_label( $entry['status'] );

			if ( $entry['is_current'] ) {
				if ( $entry['synchronized'] ) {
					$label = __( 'This post (synchronized)', 'strope-translate' );
				} elseif ( $entry['is_original'] ) {
					$label = __( 'This post (original)', 'strope-translate' );
				} else {
					$label = sprintf(
						/* translators: %s is a status, e.g. Outdated */
						__( 'This post: %s', 'strope-translate' ),
						$label
					);
				}
			}

			$actions = array();

			if ( $entry['can_edit'] ) {
				if ( ! $entry['is_current'] && $entry['edit_link'] ) {
					$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $entry['edit_link'] ), esc_html__( 'Edit', 'strope-translate' ) );
				}
				if ( Strope_Translation_Status::STATUS_OUTDATED === $entry['status'] ) {
					$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( self::get_action_url( $entry['translation_id'], 'mark_up_to_date' ) ), esc_html__( 'Mark as up to date', 'strope-translate' ) );
				}
				if ( $entry['needs_review'] ) {
					$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( self::get_action_url( $entry['translation_id'], 'mark_reviewed' ) ), esc_html__( 'Mark as reviewed', 'strope-translate' ) );
				}
			} elseif ( $entry['add_link'] ) {
				$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $entry['add_link'] ), esc_html__( 'Add', 'strope-translate' ) );
			}

			printf(
				'<li class="strope-ts-item strope-ts-%1$s"><span class="strope-ts-flag">%2$s</span> <span class="screen-reader-text">%3$s:</span> <span class="strope-ts-label">%4$s</span>%5$s%6$s</li>',
				esc_attr( str_replace( '_', '-', $entry['status'] ) ),
				$flag, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $entry['language'] ),
				esc_html( $label ),
				self::get_badges_html( $entry ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				empty( $actions ) ? '' : ' <span class="strope-ts-actions">' . implode( ' | ', $actions ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		echo '</ul>';
	}

	/**
	 * Handles the actions of the metabox links.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function handle_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		check_admin_referer( self::ACTION . '_' . $post_id );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this item.', 'strope-translate' ), 403 );
		}

		$action = isset( $_GET['do'] ) && is_string( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';

		switch ( $action ) {
			case 'mark_up_to_date':
				$result = Strope_Translation_Status::mark_up_to_date( $post_id );
				break;
			case 'mark_reviewed':
				$result = Strope_Translation_Status::mark_reviewed( $post_id );
				break;
			case 'mark_outdated':
				$result = Strope_Translation_Status::mark_needs_update( $post_id );
				break;
			default:
				$result = new WP_Error( 'strope_translation_status_invalid_action', __( 'Invalid action.', 'strope-translate' ) );
		}

		$redirect = wp_get_referer();
		$redirect = $redirect ? $redirect : (string) get_edit_post_link( $post_id, 'raw' );

		wp_safe_redirect( add_query_arg( 'strope_ts_message', is_wp_error( $result ) ? 'error' : 'updated', $redirect ) );
		exit;
	}

	/**
	 * Displays the result of an action.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function admin_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['strope_ts_message'] ) || ! is_string( $_GET['strope_ts_message'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'updated' === $_GET['strope_ts_message'] ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Translation status updated.', 'strope-translate' ) );
		} else {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'The translation status could not be updated.', 'strope-translate' ) );
		}
	}

	/**
	 * Removes our message query arg from the URL after display.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $args Query args.
	 * @return string[]
	 */
	public function removable_query_args( $args ) {
		$args[] = 'strope_ts_message';
		return $args;
	}

	/**
	 * Enqueues the stylesheet on the screens displaying a translation status.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		$screen = get_current_screen();

		if ( ! $screen || ! ( 'dashboard' === $screen->base || ( in_array( $screen->base, array( 'edit', 'post' ), true ) && Strope_Translation_Status::is_tracked_post_type( $screen->post_type ) ) ) ) {
			return;
		}

		wp_enqueue_style(
			'strope-translation-status',
			plugins_url( '/css/build/translation-status' . self::suffix() . '.css', STROPE_TRANSLATE_FILE ),
			array(),
			STROPE_TRANSLATE_VERSION
		);
	}

	/**
	 * Enqueues the block editor panel.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->base || ! Strope_Translation_Status::is_tracked_post_type( $screen->post_type ) ) {
			return;
		}

		wp_enqueue_script(
			'strope-translation-status-panel',
			plugins_url( '/js/build/translation-status-panel' . self::suffix() . '.js', STROPE_TRANSLATE_FILE ),
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-core-data' ),
			STROPE_TRANSLATE_VERSION,
			true
		);

		wp_set_script_translations( 'strope-translation-status-panel', 'strope-translate', STROPE_TRANSLATE_DIR . '/languages' );
	}
}
