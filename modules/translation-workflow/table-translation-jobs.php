<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table of the translation jobs.
 *
 * @since 4.1.0
 */
class Strope_Table_Translation_Jobs extends WP_List_Table {
	/**
	 * @var Strope_Translation_Jobs
	 */
	protected $jobs;

	/**
	 * @var Strope_Translation_Jobs_Admin
	 */
	protected $jobs_admin;

	/**
	 * Whether the current user can manage all jobs.
	 *
	 * @var bool
	 */
	protected $is_manager;

	/**
	 * Selected status view.
	 *
	 * @var string
	 */
	protected $status_view = 'open';

	/**
	 * Selected language filter.
	 *
	 * @var string
	 */
	protected $lang_filter = '';

	/**
	 * Selected assignee filter: '' for all, 'me', 'none' or a user ID.
	 *
	 * @var string
	 */
	protected $assignee_filter = '';

	/**
	 * Words to translate, keyed by source post ID.
	 *
	 * @var int[]
	 */
	protected $words = array();

	/**
	 * Words to translate in all the jobs of the current view, null if not counted (too many jobs).
	 *
	 * @var int|null
	 */
	protected $total_words = null;

	/**
	 * Whether some posts are still being counted.
	 *
	 * @var bool
	 */
	protected $words_pending = false;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Translation_Jobs       $jobs       Jobs model.
	 * @param Strope_Translation_Jobs_Admin $jobs_admin Jobs admin.
	 */
	public function __construct( $jobs, $jobs_admin ) {
		parent::__construct(
			array(
				'plural'   => 'translation-jobs',
				'singular' => 'translation-job',
				'ajax'     => false,
				'screen'   => get_current_screen(),
			)
		);

		$this->jobs       = $jobs;
		$this->jobs_admin = $jobs_admin;
		$this->is_manager = Strope_Translation_Jobs::user_can_manage();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['job_status'] ) ) {
			$status = sanitize_key( $_GET['job_status'] );
			if ( in_array( $status, array_merge( array( 'open', 'all' ), array_keys( Strope_Translation_Jobs::get_statuses() ) ), true ) ) {
				$this->status_view = $status;
			}
		}

		if ( ! empty( $_GET['job_lang'] ) && Strope()->model->get_language( sanitize_key( $_GET['job_lang'] ) ) ) {
			$this->lang_filter = sanitize_key( $_GET['job_lang'] );
		}

		if ( ! empty( $_GET['job_assignee'] ) ) {
			$assignee = sanitize_key( $_GET['job_assignee'] );
			if ( in_array( $assignee, array( 'me', 'none' ), true ) || ( $this->is_manager && ctype_digit( $assignee ) ) ) {
				$this->assignee_filter = $assignee;
			}
		}
		// phpcs:enable
	}

	/**
	 * Returns the query arguments matching the current filters.
	 *
	 * @since 4.1.0
	 *
	 * @param string $status Status view.
	 * @return array
	 */
	protected function get_query_args( $status ) {
		$args = array();

		if ( 'open' === $status ) {
			$args['status'] = Strope_Translation_Jobs::OPEN_STATUSES;
		} elseif ( 'all' !== $status ) {
			$args['status'] = $status;
		}

		if ( $this->lang_filter ) {
			$args['lang'] = $this->lang_filter;
		}

		if ( ! $this->is_manager ) {
			$args['for_user'] = get_current_user_id();
		}

		if ( 'me' === $this->assignee_filter ) {
			$args['assignee'] = get_current_user_id();
		} elseif ( 'none' === $this->assignee_filter ) {
			$args['assignee'] = 0;
		} elseif ( '' !== $this->assignee_filter ) {
			$args['assignee'] = (int) $this->assignee_filter;
		}

		return $args;
	}

	/**
	 * Prepares the items.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$args     = $this->get_query_args( $this->status_view );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'date';
		$order   = isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'desc';
		// phpcs:enable

		$result = $this->jobs->query(
			array_merge(
				$args,
				array(
					'per_page' => $per_page,
					'paged'    => $this->get_pagenum(),
					'orderby'  => in_array( $orderby, array( 'date', 'modified', 'due' ), true ) ? $orderby : 'date',
					'order'    => 'asc' === $order ? 'ASC' : 'DESC',
				)
			)
		);

		$this->items           = $result['jobs'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->count_words( $args, $result['total'] );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * Counts the words to translate of the jobs of the page, and of all the jobs of the view when they are not
	 * too many.
	 *
	 * @since 4.3.0
	 *
	 * @param array $args  Query arguments of the view.
	 * @param int   $total Number of jobs in the view.
	 * @return void
	 */
	protected function count_words( array $args, int $total ) {
		$jobs = $this->items;

		if ( $total > count( $this->items ) && $total <= 1000 ) {
			$all  = $this->jobs->query( array_merge( $args, array( 'per_page' => -1 ) ) );
			$jobs = $all['jobs'];
		}

		// The jobs of the page first, to show their counts within the time limit.
		$counts = Strope_Word_Count::get_posts_words( array_merge( wp_list_pluck( $this->items, 'source' ), wp_list_pluck( $jobs, 'source' ) ), 2.0 );

		$this->words         = $counts['words'];
		$this->words_pending = ! empty( $counts['pending'] );

		if ( $total <= 1000 ) {
			$this->total_words = 0;
			foreach ( $jobs as $job ) {
				$this->total_words += $this->words[ $job['source'] ] ?? 0;
			}
		}
	}

	/**
	 * Returns the columns.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return array(
			'title'       => esc_html__( 'Content', 'strope-translate' ),
			'lang'        => esc_html__( 'Language', 'strope-translate' ),
			'words'       => esc_html__( 'Words', 'strope-translate' ),
			'status'      => esc_html__( 'Status', 'strope-translate' ),
			'assignee'    => esc_html__( 'Assigned to', 'strope-translate' ),
			'due'         => esc_html__( 'Due date', 'strope-translate' ),
			'created_by'  => esc_html__( 'Requested by', 'strope-translate' ),
			'modified'    => esc_html__( 'Last update', 'strope-translate' ),
			'translation' => esc_html__( 'Translation', 'strope-translate' ),
		);
	}

	/**
	 * Returns the sortable columns.
	 *
	 * @since 4.1.0
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'due'      => array( 'due', false ),
			'modified' => array( 'modified', true ),
		);
	}

	/**
	 * Returns the default primary column.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'title';
	}

	/**
	 * Returns the status views.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	protected function get_views() {
		$views    = array();
		$statuses = array_merge(
			array(
				'open' => __( 'Open', 'strope-translate' ),
			),
			Strope_Translation_Jobs::get_statuses(),
			array(
				'all' => __( 'All', 'strope-translate' ),
			)
		);

		foreach ( $statuses as $status => $label ) {
			$count = $this->jobs->query( array_merge( $this->get_query_args( $status ), array( 'per_page' => 1 ) ) )['total'];
			$url   = add_query_arg(
				array_filter(
					array(
						'page'         => 'mlang_jobs',
						'job_status'   => $status,
						'job_lang'     => $this->lang_filter,
						'job_assignee' => $this->assignee_filter,
					)
				),
				admin_url( 'admin.php' )
			);

			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$status === $this->status_view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	/**
	 * Displays the filters.
	 *
	 * @since 4.1.0
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions">';

		printf( '<label class="screen-reader-text" for="strope-job-lang">%s</label>', esc_html__( 'Filter by language', 'strope-translate' ) );
		echo '<select name="job_lang" id="strope-job-lang"><option value="">' . esc_html__( 'All languages', 'strope-translate' ) . '</option>';
		$user_languages = $this->jobs->get_translator_languages( get_current_user_id() );
		foreach ( Strope()->model->get_languages_list() as $language ) {
			if ( ! $this->is_manager && ! empty( $user_languages ) && ! in_array( $language->slug, $user_languages, true ) ) {
				continue;
			}
			printf( '<option value="%s"%s>%s</option>', esc_attr( $language->slug ), selected( $this->lang_filter, $language->slug, false ), esc_html( $language->name ) );
		}
		echo '</select>';

		printf( '<label class="screen-reader-text" for="strope-job-assignee">%s</label>', esc_html__( 'Filter by assignee', 'strope-translate' ) );
		echo '<select name="job_assignee" id="strope-job-assignee">';
		printf( '<option value="">%s</option>', esc_html__( 'All assignees', 'strope-translate' ) );
		printf( '<option value="me"%s>%s</option>', selected( $this->assignee_filter, 'me', false ), esc_html__( 'Assigned to me', 'strope-translate' ) );
		printf( '<option value="none"%s>%s</option>', selected( $this->assignee_filter, 'none', false ), esc_html__( 'Unassigned', 'strope-translate' ) );
		if ( $this->is_manager ) {
			foreach ( $this->jobs->get_translators() as $user ) {
				printf( '<option value="%d"%s>%s</option>', (int) $user->ID, selected( $this->assignee_filter, (string) $user->ID, false ), esc_html( $user->display_name ) );
			}
		}
		echo '</select>';

		submit_button( __( 'Filter', 'strope-translate' ), '', 'filter_action', false );
		echo '</div>';

		if ( null !== $this->total_words && $this->has_items() ) {
			$total = number_format_i18n( $this->total_words );
			printf(
				'<div class="alignleft actions strope-job-words">%s</div>',
				esc_html(
					$this->words_pending
						/* translators: %s: number of words */
						? sprintf( __( 'At least %s words to translate (still counting)', 'strope-translate' ), $total )
						/* translators: %s: number of words */
						: sprintf( _n( '%s word to translate', '%s words to translate', $this->total_words, 'strope-translate' ), $total )
				)
			);
		}
	}

	/**
	 * Displays the content column.
	 *
	 * @since 4.1.0
	 *
	 * @param array $item Job.
	 * @return string
	 */
	protected function column_title( $item ) {
		$title = get_the_title( $item['source'] );
		$title = '' === $title ? sprintf( '#%d', $item['source'] ) : $title;
		$link  = get_edit_post_link( $item['source'] );

		if ( ! $link && 'publish' === get_post_status( $item['source'] ) ) {
			$link = get_permalink( $item['source'] );
		}

		$out = $link ? sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( $link ), esc_html( $title ) ) : '<strong>' . esc_html( $title ) . '</strong>';

		$source_language = Strope()->model->post->get_language( $item['source'] );
		if ( $source_language ) {
			/* translators: %s is a language name */
			$out .= '<br /><span class="description">' . esc_html( sprintf( __( 'From %s', 'strope-translate' ), $source_language->name ) ) . '</span>';
		}

		if ( '' !== $item['note'] ) {
			$out .= '<p class="strope-job-note">' . nl2br( esc_html( $item['note'] ) ) . '</p>';
		}

		return $out;
	}

	/**
	 * Displays the row actions in the primary column.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $item        Job.
	 * @param string $column_name Current column name.
	 * @param string $primary     Primary column name.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$actions = array();
		foreach ( $this->jobs_admin->get_job_actions( $item ) as $op => $label ) {
			$actions[ $op ] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->jobs_admin->get_action_url( $item['id'], $op ) ),
				esc_html( $label )
			);
		}

		return $this->row_actions( $actions );
	}

	/**
	 * Displays the language column.
	 *
	 * @since 4.1.0
	 *
	 * @param array $item Job.
	 * @return string
	 */
	protected function column_lang( $item ) {
		$language = Strope()->model->get_language( $item['lang'] );
		return $language ? esc_html( $language->name ) : esc_html( $item['lang'] );
	}

	/**
	 * Displays the words column: words to translate in the source content.
	 *
	 * @since 4.3.0
	 *
	 * @param array $item Job.
	 * @return string
	 */
	protected function column_words( $item ) {
		if ( ! isset( $this->words[ $item['source'] ] ) ) {
			return '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'Not counted yet', 'strope-translate' ) . '</span>';
		}

		return esc_html( number_format_i18n( $this->words[ $item['source'] ] ) );
	}

	/**
	 * Displays the status column.
	 *
	 * @since 4.1.0
	 *
	 * @param array $item Job.
	 * @return string
	 */
	protected function column_status( $item ) {
		$statuses = Strope_Translation_Jobs::get_statuses();
		$out      = sprintf( '<span class="strope-job-status strope-job-status-%s">%s</span>', esc_attr( $item['status'] ), esc_html( $statuses[ $item['status'] ] ) );

		if ( $item['due'] && in_array( $item['status'], array( 'new', 'in_progress' ), true ) && $item['due'] < current_time( 'Y-m-d' ) ) {
			$out .= ' <span class="strope-job-overdue">' . esc_html__( 'Overdue', 'strope-translate' ) . '</span>';
		}

		return $out;
	}

	/**
	 * Displays the assignee column.
	 *
	 * @since 4.1.0
	 *
	 * @param array $item Job.
	 * @return string
	 */
	protected function column_assignee( $item ) {
		if ( ! $item['assignee'] ) {
			return esc_html__( 'Any translator', 'strope-translate' );
		}

		if ( get_current_user_id() === $item['assignee'] ) {
			return esc_html__( 'You', 'strope-translate' );
		}

		$user = get_userdata( $item['assignee'] );
		return $user ? esc_html( $user->display_name ) : '&#8212;';
	}

	/**
	 * Displays the due date column.
	 *
	 * @since 4.1.0
	 *
	 * @param array $item Job.
	 * @return string
	 */
	protected function column_due( $item ) {
		return $item['due'] ? esc_html( mysql2date( get_option( 'date_format' ), $item['due'] ) ) : '&#8212;';
	}

	/**
	 * Displays the creator column.
	 *
	 * @since 4.1.0
	 *
	 * @param array $item Job.
	 * @return string
	 */
	protected function column_created_by( $item ) {
		$user = get_userdata( $item['created_by'] );
		$out  = $user ? esc_html( $user->display_name ) : '&#8212;';
		return $out . '<br /><span class="description">' . esc_html( get_date_from_gmt( $item['created'], get_option( 'date_format' ) ) ) . '</span>';
	}

	/**
	 * Displays the last update column.
	 *
	 * @since 4.1.0
	 *
	 * @param array $item Job.
	 * @return string
	 */
	protected function column_modified( $item ) {
		return esc_html( get_date_from_gmt( $item['updated'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
	}

	/**
	 * Displays the translation column.
	 *
	 * @since 4.1.0
	 *
	 * @param array $item Job.
	 * @return string
	 */
	protected function column_translation( $item ) {
		$post = $item['translation'] ? get_post( $item['translation'] ) : null;

		if ( ! $post || 'auto-draft' === $post->post_status ) {
			return '&#8212;';
		}

		$title  = '' === $post->post_title ? sprintf( '#%d', $post->ID ) : $post->post_title;
		$status = get_post_status_object( $post->post_status );
		$link   = get_edit_post_link( $post->ID );
		$out    = $link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ) ) : esc_html( $title );

		return $out . ( $status ? '<br /><span class="description">' . esc_html( $status->label ) . '</span>' : '' );
	}

	/**
	 * Message displayed when there is no job.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No translation jobs found.', 'strope-translate' );
	}
}
