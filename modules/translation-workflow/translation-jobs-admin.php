<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Admin user interface of the translation jobs: jobs page, "Send to translation" bulk action and links,
 * job actions and dashboard widget.
 *
 * @since 4.1.0
 */
class Strope_Translation_Jobs_Admin {
	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE = 'mlang_jobs';

	/**
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * @var Strope_Translation_Jobs
	 */
	protected $jobs;

	/**
	 * Open jobs indexed by language and source post, lazily loaded for the posts list table.
	 *
	 * @var array|null
	 */
	protected $open_jobs;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Base             $strope_translate Strope Translate object.
	 * @param Strope_Translation_Jobs $jobs             Jobs model.
	 */
	public function __construct( &$strope_translate, $jobs ) {
		$this->model = &$strope_translate->model;
		$this->jobs  = $jobs;

		// Jobs page in the Languages menu.
		add_filter( 'strope_settings_tabs', array( $this, 'settings_tabs' ) );
		add_filter( 'strope_settings_tab_capability', array( $this, 'tab_capability' ), 10, 2 );
		add_action( 'strope_settings_active_tab_jobs', array( $this, 'display_page' ) );

		// Actions.
		add_action( 'admin_post_strope_translation_jobs_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_strope_translation_job', array( $this, 'handle_job_action' ) );

		// Posts list tables.
		add_action( 'current_screen', array( $this, 'init_posts_screen' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_style' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );

		// The import and export of translations require the capability to manage languages.
		add_action( 'load-languages_page_mlang_strings', array( $this, 'remove_import_export_meta_boxes' ), 20 );
	}

	/**
	 * Removes the import and export meta boxes of the strings translations page
	 * for users who can't manage languages (the actions are refused for them).
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function remove_import_export_meta_boxes() {
		if ( ! current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) ) {
			remove_meta_box( 'strope-export-strings-box', 'languages_page_mlang_strings', 'normal' );
			remove_meta_box( 'strope-import-translations-box', 'languages_page_mlang_strings', 'normal' );
		}
	}

	/**
	 * Returns the URL of the jobs page.
	 *
	 * @since 4.1.0
	 *
	 * @param array $args Optional query arguments.
	 * @return string
	 */
	public static function get_page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Adds the translation jobs tab.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $tabs Tabs.
	 * @return string[]
	 */
	public function settings_tabs( $tabs ) {
		$title = Strope_Translation_Jobs::user_can_manage() ? __( 'Translation jobs', 'strope-translate' ) : __( 'My translation jobs', 'strope-translate' );

		// Before the settings tab.
		$position = array_search( 'settings', array_keys( $tabs ), true );
		if ( false === $position ) {
			$tabs['jobs'] = $title;
			return $tabs;
		}

		return array_merge( array_slice( $tabs, 0, $position, true ), array( 'jobs' => $title ), array_slice( $tabs, $position, null, true ) );
	}

	/**
	 * Sets the capability required to access the jobs tab.
	 *
	 * @since 4.1.0
	 *
	 * @param string $capability Capability.
	 * @param string $tab        Tab.
	 * @return string
	 */
	public function tab_capability( $capability, $tab ) {
		return 'jobs' === $tab ? Strope_Translation_Workflow::VIEW_JOBS : $capability;
	}

	/**
	 * Enqueues the stylesheet.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function enqueue_style() {
		$screen = get_current_screen();

		if ( empty( $screen ) || ! ( in_array( $screen->base, array( 'edit', 'dashboard' ), true ) || false !== strpos( $screen->id, self::PAGE ) ) ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_style( 'strope_translation_workflow', plugins_url( '/css/build/translation-workflow' . $suffix . '.css', STROPE_TRANSLATE_FILE ), array(), STROPE_TRANSLATE_VERSION );
	}

	/**
	 * Returns the actions available for a job to the current user.
	 *
	 * @since 4.1.0
	 *
	 * @param array $job Job.
	 * @return string[] Labels with actions as keys.
	 */
	public function get_job_actions( $job ) {
		$actions  = array();
		$can_work = $this->jobs->user_can_work_on( $job );
		$can_mng  = $this->jobs->user_can_cancel( $job );
		$open     = in_array( $job['status'], Strope_Translation_Jobs::OPEN_STATUSES, true );

		if ( $can_work && in_array( $job['status'], array( 'new', 'in_progress' ), true ) ) {
			$translation = $this->get_existing_translation( $job );
			if ( ! $translation ) {
				$actions['start'] = __( 'Start', 'strope-translate' );
			} elseif ( current_user_can( 'edit_post', $translation ) ) {
				$actions['start'] = __( 'Open translation', 'strope-translate' );
			}
			$actions['review'] = __( 'Mark ready for review', 'strope-translate' );
		}

		if ( $open && ( $can_mng || ( $can_work && Strope_Translation_Workflow::get_options()['translators_publish'] ) ) ) {
			$actions['done'] = __( 'Mark done', 'strope-translate' );
		}

		if ( $open && $can_mng ) {
			$actions['cancel'] = __( 'Cancel', 'strope-translate' );
		}

		if ( $can_mng && in_array( $job['status'], array( 'review', 'done', 'cancelled' ), true ) ) {
			$actions['reopen'] = __( 'Reopen', 'strope-translate' );
		}

		return $actions;
	}

	/**
	 * Returns the URL of a job action.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $job_id Job ID.
	 * @param string $op     Action.
	 * @return string
	 */
	public function get_action_url( $job_id, $op ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'strope_translation_job',
					'job'    => (int) $job_id,
					'op'     => $op,
				),
				admin_url( 'admin-post.php' )
			),
			"strope_translation_job_{$op}_{$job_id}"
		);
	}

	/**
	 * Returns the existing translation of a job, if any.
	 *
	 * @since 4.1.0
	 *
	 * @param array $job Job.
	 * @return int Post ID, 0 if none.
	 */
	protected function get_existing_translation( $job ) {
		$language = $this->model->get_language( $job['lang'] );
		$tr_id    = $language ? (int) $this->model->post->get_translation( $job['source'], $language ) : 0;

		if ( ! $tr_id && $job['translation'] ) {
			$tr_id = $job['translation'];
		}

		$status = $tr_id ? get_post_status( $tr_id ) : false;

		return $status && ! in_array( $status, array( 'auto-draft', 'trash' ), true ) ? $tr_id : 0;
	}

	/**
	 * Redirects to the jobs page with a message.
	 *
	 * @since 4.1.0
	 *
	 * @param array $args Query arguments.
	 * @return never
	 */
	protected function redirect( $args ) {
		$referer = wp_get_referer();
		$url     = $referer && false !== strpos( $referer, 'page=' . self::PAGE ) && false === strpos( $referer, 'strope_jobs_view=new' ) ? $referer : self::get_page_url();
		$url     = remove_query_arg( array( 'strope_jobs_message', 'strope_jobs_created', 'strope_jobs_skipped' ), $url );
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Handles the actions on a job.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function handle_job_action() {
		$job_id = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;
		$op     = isset( $_GET['op'] ) ? sanitize_key( $_GET['op'] ) : '';

		check_admin_referer( "strope_translation_job_{$op}_{$job_id}" );

		$job = $this->jobs->get( $job_id );

		if ( ! $job || ! $this->jobs->user_can_view( $job ) || ! array_key_exists( $op, $this->get_job_actions( $job ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'strope-translate' ), 403 );
		}

		switch ( $op ) {
			case 'start':
				$this->start( $job );
				break;

			case 'review':
				$this->jobs->update( $job_id, $this->claim( $job, array( 'status' => 'review' ) ) );
				break;

			case 'done':
				$this->jobs->update( $job_id, array( 'status' => 'done' ) );
				break;

			case 'cancel':
				$this->jobs->update( $job_id, array( 'status' => 'cancelled' ) );
				break;

			case 'reopen':
				if ( $this->jobs->find_open_job( $job['source'], $job['lang'] ) ) {
					$this->redirect( array( 'strope_jobs_message' => 'duplicate' ) );
				}
				$this->jobs->update( $job_id, array( 'status' => $this->get_existing_translation( $job ) ? 'in_progress' : 'new' ) );
				break;
		}

		$this->redirect( array( 'strope_jobs_message' => 'updated' ) );
	}

	/**
	 * Assigns an unassigned job to the current user when they work on it.
	 *
	 * @since 4.1.0
	 *
	 * @param array $job    Job.
	 * @param array $fields Fields to update.
	 * @return array Fields to update.
	 */
	protected function claim( $job, $fields ) {
		if ( ! $job['assignee'] ) {
			$fields['assignee'] = get_current_user_id();
		}
		return $fields;
	}

	/**
	 * Starts a job: opens the existing translation or creates it with the new translation flow
	 * (so that the content duplication preference applies).
	 *
	 * @since 4.1.0
	 *
	 * @param array $job Job.
	 * @return never
	 */
	protected function start( $job ) {
		$source = get_post( $job['source'] );

		if ( ! $source || ! current_user_can( 'read_post', $source->ID ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to translate this content.', 'strope-translate' ), 403 );
		}

		$tr_id = $this->get_existing_translation( $job );

		if ( $tr_id ) {
			if ( ! current_user_can( 'edit_post', $tr_id ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to edit the translation.', 'strope-translate' ), 403 );
			}

			$this->jobs->update(
				$job['id'],
				$this->claim(
					$job,
					array(
						'status'      => 'in_progress',
						'translation' => $tr_id,
					)
				)
			);
			wp_safe_redirect( get_edit_post_link( $tr_id, 'raw' ) );
			exit;
		}

		$post_type = get_post_type_object( $source->post_type );

		if ( ! $post_type || ! current_user_can( $post_type->cap->create_posts ) || ! Strope_Translator_Languages::user_can_translate( $job['lang'] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create this translation.', 'strope-translate' ), 403 );
		}

		$this->jobs->update( $job['id'], $this->claim( $job, array( 'status' => 'in_progress' ) ) );

		$url = add_query_arg(
			array(
				'post_type'  => $source->post_type,
				'from_post'  => $source->ID,
				'new_lang'   => $job['lang'],
				'strope_job' => $job['id'],
				'_wpnonce'   => wp_create_nonce( 'new-post-translation' ),
			),
			admin_url( 'post-new.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handles the creation of jobs.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function handle_create() {
		check_admin_referer( 'strope_translation_jobs_create', '_strope_jobs_nonce' );

		if ( ! Strope_Translation_Jobs::user_can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create translation jobs.', 'strope-translate' ), 403 );
		}

		$post_ids  = isset( $_POST['posts'] ) ? array_unique( array_filter( array_map( 'absint', (array) $_POST['posts'] ) ) ) : array();
		$languages = isset( $_POST['languages'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['languages'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$assignee  = isset( $_POST['assignee'] ) ? absint( $_POST['assignee'] ) : 0;
		$due       = isset( $_POST['due'] ) ? Strope_Translation_Jobs::sanitize_date( sanitize_text_field( wp_unslash( $_POST['due'] ) ) ) : '';
		$note      = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$created = 0;
		$skipped = 0;

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'read_post', $post_id ) ) {
				++$skipped;
				continue;
			}

			$source_language = $this->model->post->get_language( $post_id );

			foreach ( $languages as $lang ) {
				if ( $source_language && $source_language->slug === $lang ) {
					continue; // The content is already in this language.
				}

				$result = $this->jobs->create(
					array(
						'source'   => $post_id,
						'lang'     => $lang,
						'assignee' => $assignee,
						'due'      => $due,
						'note'     => $note,
					)
				);

				if ( is_wp_error( $result ) ) {
					++$skipped;
				} else {
					++$created;
				}
			}
		}

		$url = self::get_page_url(
			array(
				'strope_jobs_message' => 'created',
				'strope_jobs_created' => $created,
				'strope_jobs_skipped' => $skipped,
			)
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Displays the admin notices of the jobs page.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	protected function display_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['strope_jobs_message'] ) ? sanitize_key( $_GET['strope_jobs_message'] ) : '';

		if ( 'created' === $message ) {
			$created = isset( $_GET['strope_jobs_created'] ) ? absint( $_GET['strope_jobs_created'] ) : 0;
			$skipped = isset( $_GET['strope_jobs_skipped'] ) ? absint( $_GET['strope_jobs_skipped'] ) : 0;

			/* translators: %d is a number of jobs */
			$text = sprintf( _n( '%d translation job created.', '%d translation jobs created.', $created, 'strope-translate' ), $created );
			if ( $skipped ) {
				/* translators: %d is a number of jobs */
				$text .= ' ' . sprintf( _n( '%d job skipped (an open job already exists, or the content, language or assignee is invalid).', '%d jobs skipped (an open job already exists, or the content, language or assignee is invalid).', $skipped, 'strope-translate' ), $skipped );
			}
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $created ? 'success' : 'warning', esc_html( $text ) );
		} elseif ( 'updated' === $message ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Translation job updated.', 'strope-translate' ) );
		} elseif ( 'duplicate' === $message ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'An open translation job already exists for this content and language.', 'strope-translate' ) );
		}
		// phpcs:enable
	}

	/**
	 * Displays the jobs page.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function display_page() {
		if ( ! current_user_can( Strope_Translation_Workflow::VIEW_JOBS ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['strope_jobs_view'] ) && 'new' === $_GET['strope_jobs_view'] && Strope_Translation_Jobs::user_can_manage() ) {
			$this->display_create_form();
			return;
		}

		$this->display_notices();

		$table = new Strope_Table_Translation_Jobs( $this->jobs, $this );
		$table->prepare_items();
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = isset( $_GET['job_status'] ) ? sanitize_key( $_GET['job_status'] ) : '';
			if ( $status ) {
				printf( '<input type="hidden" name="job_status" value="%s" />', esc_attr( $status ) );
			}
			$table->views();
			$table->display();
			?>
		</form>
		<?php
		if ( Strope_Translation_Jobs::user_can_manage() ) {
			echo '<p class="description">' . esc_html__( 'To create translation jobs, select content in the posts or pages lists and use the "Send to translation" bulk action.', 'strope-translate' ) . '</p>';
		}
	}

	/**
	 * Displays the form to create jobs.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	protected function display_create_form() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$post_ids = isset( $_GET['posts'] ) ? array_unique( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['posts'] ) ) ) ) ) ) : array();
		$checked  = isset( $_GET['languages'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['languages'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		$posts = array();
		foreach ( array_slice( $post_ids, 0, 200 ) as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && current_user_can( 'read_post', $post_id ) && $this->model->is_translated_post_type( $post->post_type ) && $this->model->post->get_language( $post_id ) ) {
				$posts[] = $post;
			}
		}

		if ( empty( $posts ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No content to translate has been selected.', 'strope-translate' ) . '</p></div>';
			return;
		}

		$languages = $this->model->get_languages_list();
		$source_languages = array();
		?>
		<h2><?php esc_html_e( 'Send to translation', 'strope-translate' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="strope-jobs-form">
			<input type="hidden" name="action" value="strope_translation_jobs_create" />
			<?php wp_nonce_field( 'strope_translation_jobs_create', '_strope_jobs_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Content', 'strope-translate' ); ?></th>
					<td>
						<ul class="strope-jobs-posts">
							<?php
							foreach ( $posts as $post ) {
								$language = $this->model->post->get_language( $post->ID );
								$source_languages[ $language->slug ] = true;
								printf(
									'<li><input type="hidden" name="posts[]" value="%d" />%s <span class="description">(%s)</span></li>',
									(int) $post->ID,
									esc_html( '' === $post->post_title ? '#' . $post->ID : $post->post_title ),
									esc_html( $language->name )
								);
							}
							?>
						</ul>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Target languages', 'strope-translate' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Target languages', 'strope-translate' ); ?></legend>
							<?php
							foreach ( $languages as $language ) {
								if ( 1 === count( $source_languages ) && isset( $source_languages[ $language->slug ] ) ) {
									continue;
								}
								printf(
									'<label><input type="checkbox" name="languages[]" value="%s"%s /> %s</label><br />',
									esc_attr( $language->slug ),
									checked( in_array( $language->slug, $checked, true ), true, false ),
									esc_html( $language->name )
								);
							}
							?>
							<p class="description"><?php esc_html_e( 'Content already in a selected language is skipped. Jobs are not duplicated when an open job already exists.', 'strope-translate' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="strope-job-assignee-field"><?php esc_html_e( 'Assign to', 'strope-translate' ); ?></label></th>
					<td>
						<select name="assignee" id="strope-job-assignee-field">
							<option value="0"><?php esc_html_e( 'Any translator of the language', 'strope-translate' ); ?></option>
							<?php
							foreach ( $this->jobs->get_translators() as $user ) {
								$names = array();
								foreach ( $this->jobs->get_translator_languages( $user->ID ) as $slug ) {
									$language = $this->model->get_language( $slug );
									if ( $language ) {
										$names[] = $language->name;
									}
								}
								printf( '<option value="%d">%s (%s)</option>', (int) $user->ID, esc_html( $user->display_name ), esc_html( implode( ', ', $names ) ) );
							}
							?>
						</select>
						<p class="description"><?php esc_html_e( 'Translators are users with the Translator role or with translation languages set in their profile.', 'strope-translate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="strope-job-due"><?php esc_html_e( 'Due date', 'strope-translate' ); ?></label></th>
					<td><input type="date" name="due" id="strope-job-due" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="strope-job-note"><?php esc_html_e( 'Note', 'strope-translate' ); ?></label></th>
					<td><textarea name="note" id="strope-job-note" rows="4" class="large-text"></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Send to translation', 'strope-translate' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Adds the bulk action and the column links to the posts list tables of translated post types.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Screen $screen Current screen.
	 * @return void
	 */
	public function init_posts_screen( $screen ) {
		if ( 'edit' !== $screen->base || empty( $screen->post_type ) || 'attachment' === $screen->post_type || ! $this->model->is_translated_post_type( $screen->post_type ) ) {
			return;
		}

		if ( ! Strope_Translation_Jobs::user_can_manage() ) {
			return;
		}

		add_filter( "bulk_actions-{$screen->id}", array( $this, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-{$screen->id}", array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( "manage_{$screen->post_type}_posts_custom_column", array( $this, 'post_column' ), 20, 2 );
	}

	/**
	 * Adds the "Send to translation" bulk action.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $actions Bulk actions.
	 * @return string[]
	 */
	public function bulk_actions( $actions ) {
		$actions['strope_send_to_translation'] = __( 'Send to translation', 'strope-translate' );
		return $actions;
	}

	/**
	 * Redirects the "Send to translation" bulk action to the jobs creation form.
	 * The bulk action nonce has already been checked by WordPress.
	 *
	 * @since 4.1.0
	 *
	 * @param string $sendback Redirect URL.
	 * @param string $action   Bulk action.
	 * @param int[]  $post_ids Selected posts.
	 * @return string
	 */
	public function handle_bulk_action( $sendback, $action, $post_ids ) {
		if ( 'strope_send_to_translation' !== $action || ! Strope_Translation_Jobs::user_can_manage() ) {
			return $sendback;
		}

		return self::get_page_url(
			array(
				'strope_jobs_view' => 'new',
				'posts'            => implode( ',', array_map( 'intval', $post_ids ) ),
			)
		);
	}

	/**
	 * Tells whether a translation is outdated, using the translation status tracker when available.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $tr_id Translation post ID.
	 * @param string $lang  Language slug.
	 * @return bool
	 */
	protected function is_outdated( $tr_id, $lang ) {
		if ( ! function_exists( 'strope_get_translation_status' ) ) {
			return false;
		}

		$status = strope_get_translation_status( $tr_id, $lang );

		if ( is_array( $status ) ) {
			$status = isset( $status['status'] ) ? $status['status'] : ( isset( $status[ $lang ] ) ? $status[ $lang ] : '' );
			if ( is_array( $status ) ) {
				$status = isset( $status['status'] ) ? $status['status'] : '';
			}
		}

		return 'outdated' === $status;
	}

	/**
	 * Adds a "Send to translation" link (or the job status) in the language columns of the posts list tables.
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

		$language      = $this->model->get_language( substr( $column, 9 ) );
		$post_language = $this->model->post->get_language( $post_id );

		if ( ! $language || ! $post_language || $language->slug === $post_language->slug ) {
			return;
		}

		if ( null === $this->open_jobs ) {
			$this->open_jobs = array();
			foreach ( $this->jobs->query( array( 'status' => Strope_Translation_Jobs::OPEN_STATUSES, 'per_page' => -1 ) )['jobs'] as $job ) {
				$this->open_jobs[ $job['lang'] ][ $job['source'] ] = $job;
			}
		}

		$translations = $this->model->post->get_translations( $post_id );

		foreach ( $translations as $tr_id ) {
			if ( isset( $this->open_jobs[ $language->slug ][ $tr_id ] ) ) {
				$job      = $this->open_jobs[ $language->slug ][ $tr_id ];
				$statuses = Strope_Translation_Jobs::get_statuses();
				printf(
					'<a class="strope-job-indicator strope-job-indicator-%1$s" href="%2$s" title="%3$s"><span class="screen-reader-text">%3$s</span></a>',
					esc_attr( $job['status'] ),
					esc_url( self::get_page_url( array( 'job_lang' => $language->slug ) ) ),
					/* translators: %s is a job status */
					esc_attr( sprintf( __( 'Translation job: %s', 'strope-translate' ), $statuses[ $job['status'] ] ) )
				);
				return;
			}
		}

		$tr_id = isset( $translations[ $language->slug ] ) ? (int) $translations[ $language->slug ] : 0;

		if ( $tr_id && ! $this->is_outdated( $tr_id, $language->slug ) ) {
			return;
		}

		/* translators: %s is a language name */
		$title = sprintf( __( 'Send to translation into %s', 'strope-translate' ), $language->name );

		printf(
			'<a class="strope-send-to-translation" href="%1$s" title="%2$s"><span class="screen-reader-text">%2$s</span></a>',
			esc_url(
				self::get_page_url(
					array(
						'strope_jobs_view' => 'new',
						'posts'            => $post_id,
						'languages'        => array( $language->slug ),
					)
				)
			),
			esc_attr( $title )
		);
	}

	/**
	 * Adds the translation jobs dashboard widget.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function add_dashboard_widget() {
		if ( current_user_can( Strope_Translation_Workflow::VIEW_JOBS ) ) {
			wp_add_dashboard_widget( 'strope_translation_jobs', __( 'Translation jobs', 'strope-translate' ), array( $this, 'dashboard_widget' ) );
		}
	}

	/**
	 * Displays the translation jobs dashboard widget.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function dashboard_widget() {
		$statuses = Strope_Translation_Jobs::get_statuses();

		if ( Strope_Translation_Jobs::user_can_manage() ) {
			echo '<ul class="strope-jobs-counts">';
			foreach ( Strope_Translation_Jobs::OPEN_STATUSES as $status ) {
				$count = $this->jobs->query( array( 'status' => $status, 'per_page' => 1 ) )['total'];
				printf(
					'<li><a href="%s">%s</a></li>',
					esc_url( self::get_page_url( array( 'job_status' => $status ) ) ),
					/* translators: 1: number of jobs, 2: job status */
					esc_html( sprintf( __( '%1$d %2$s', 'strope-translate' ), $count, $statuses[ $status ] ) )
				);
			}
			echo '</ul>';
		}

		$result = $this->jobs->query(
			array(
				'status'   => array( 'new', 'in_progress' ),
				'for_user' => get_current_user_id(),
				'per_page' => 5,
				'orderby'  => 'due',
				'order'    => 'ASC',
			)
		);

		echo '<h3>' . esc_html__( 'Your open jobs', 'strope-translate' ) . '</h3>';

		if ( empty( $result['jobs'] ) ) {
			echo '<p>' . esc_html__( 'You have no open translation jobs.', 'strope-translate' ) . '</p>';
		} else {
			echo '<ul class="strope-jobs-list">';
			foreach ( $result['jobs'] as $job ) {
				$language = $this->model->get_language( $job['lang'] );
				$title    = get_the_title( $job['source'] );
				$actions  = $this->get_job_actions( $job );
				printf(
					'<li>%s &rarr; %s <span class="description">(%s%s)</span>%s</li>',
					esc_html( '' === $title ? '#' . $job['source'] : $title ),
					esc_html( $language ? $language->name : $job['lang'] ),
					esc_html( $statuses[ $job['status'] ] ),
					/* translators: %s is a date */
					$job['due'] ? esc_html( ', ' . sprintf( __( 'due %s', 'strope-translate' ), mysql2date( get_option( 'date_format' ), $job['due'] ) ) ) : '',
					isset( $actions['start'] ) ? sprintf( ' <a href="%s">%s</a>', esc_url( $this->get_action_url( $job['id'], 'start' ) ), esc_html( $actions['start'] ) ) : ''
				);
			}
			echo '</ul>';
		}

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( self::get_page_url() ),
			/* translators: %d is a number of jobs */
			esc_html( sprintf( _n( 'See all (%d open job)', 'See all (%d open jobs)', $result['total'], 'strope-translate' ), $result['total'] ) )
		);
	}
}
