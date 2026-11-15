<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin of the machine translation: the Languages > Machine translation page, the bulk action of the posts
 * lists, and the background worker loaded on the admin pages.
 *
 * @since 4.2.0
 */
class Strope_MT_Admin {
	/**
	 * Admin page slug.
	 */
	const PAGE = 'mlang_mt';

	/**
	 * Bulk action name.
	 */
	const BULK_ACTION = 'strope_machine_translate';

	/**
	 * Used to query languages and translations.
	 *
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * Constructor.
	 *
	 * @since 4.2.0
	 *
	 * @param Strope_Admin_Base $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->model = &$strope_translate->model;

		add_filter( 'strope_settings_tabs', array( $this, 'settings_tabs' ) );
		add_filter( 'strope_settings_tab_capability', array( $this, 'tab_capability' ), 10, 2 );
		add_action( 'strope_settings_active_tab_mt', array( $this, 'display_page' ) );

		add_action( 'admin_post_strope_mt_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_strope_mt_glossary', array( $this, 'handle_glossary' ) );
		add_action( 'current_screen', array( $this, 'init_posts_screen' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 120 );
		add_action( 'load-languages_page_mlang_strings', array( $this, 'load_strings_page' ) );
	}

	/**
	 * Adds the machine translation buttons to the Languages > Translations page.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public function load_strings_page() {
		if ( ! current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			return;
		}

		$installed = array_keys( Strope_MT_Engine_Files::get_installed()['models'] );
		if ( empty( $installed ) || empty( Strope_MT_Engine_Files::get_installed()['wasm'] ) ) {
			return; // Nothing installed: no buttons.
		}

		add_action(
			'admin_enqueue_scripts',
			function () use ( $installed ) {
				$suffix     = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
				$discovered = class_exists( 'Strope_String_Discovery_Admin' ) && Strope_String_Discovery_Admin::is_discovered_view();
				$default    = $this->model->get_default_language();
				$languages  = array();
				$english    = '';

				foreach ( $this->model->get_languages_list() as $language ) {
					$code                         = Strope_MT_Engine_Files::get_code( $language );
					$languages[ $language->slug ] = array(
						'name' => $language->name,
						'code' => $code,
					);
					if ( 'en' === $code && '' === $english ) {
						$english = $language->slug;
					}
				}

				// Theme and plugin strings are usually written in English, registered strings in the default language.
				$source = $discovered && '' !== $english ? $english : ( $default ? $default->slug : '' );

				$data = array_merge(
					$this->get_script_data( false ),
					array(
						'view'          => $discovered ? 'discovered' : 'registered',
						'languages'     => $languages,
						'directions'    => $installed,
						'defaultSource' => $source,
						'maxItems'      => Strope_MT_Strings::MAX_ITEMS,
					)
				);

				$data['i18n'] += array(
					'from'         => __( 'Machine translation, from:', 'strope-translate' ),
					'translateAll' => __( 'Translate empty fields', 'strope-translate' ),
					/* translators: %s: language name */
					'translateOne' => __( 'Machine translate into %s', 'strope-translate' ),
					'working'      => __( 'Translating…', 'strope-translate' ),
					/* translators: %d: number of fields */
					'filled'       => __( '%d fields filled: check them, then save.', 'strope-translate' ),
					/* translators: %d: number of strings */
					'existing'     => __( '%d skipped, already translated by the language files of the theme or plugin.', 'strope-translate' ),
					/* translators: %d: number of strings */
					'failed'       => __( '%d could not be translated.', 'strope-translate' ),
					/* translators: %d: number of strings */
					'unchanged'    => __( '%d left empty: their machine translation is the same as the original text (already in that language?).', 'strope-translate' ),
					'filledTitle'  => __( 'Machine translation: check it before saving.', 'strope-translate' ),
				);

				wp_enqueue_style( 'strope-mt', plugins_url( '/css/build/machine-translation' . $suffix . '.css', STROPE_TRANSLATE_FILE ), array( 'dashicons' ), STROPE_TRANSLATE_VERSION );
				wp_enqueue_script( 'strope-mt-strings', plugins_url( '/js/build/mt-strings' . $suffix . '.js', STROPE_TRANSLATE_FILE ), array(), STROPE_TRANSLATE_VERSION, true );
				wp_add_inline_script( 'strope-mt-strings', 'window.stropeMtStrings = ' . wp_json_encode( $data ) . ';', 'before' );
			}
		);
	}

	/**
	 * Returns the URL of the page.
	 *
	 * @since 4.2.0
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function get_page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Adds the tab, before the settings.
	 *
	 * @since 4.2.0
	 *
	 * @param string[] $tabs Tabs.
	 * @return string[]
	 */
	public function settings_tabs( $tabs ) {
		$title    = __( 'Machine translation', 'strope-translate' );
		$position = array_search( 'settings', array_keys( $tabs ), true );

		if ( false === $position ) {
			$tabs['mt'] = $title;
			return $tabs;
		}

		return array_merge( array_slice( $tabs, 0, $position, true ), array( 'mt' => $title ), array_slice( $tabs, $position, null, true ) );
	}

	/**
	 * Capability of the tab.
	 *
	 * @since 4.2.0
	 *
	 * @param string $capability Capability.
	 * @param string $tab        Tab.
	 * @return string
	 */
	public function tab_capability( $capability, $tab ) {
		return 'mt' === $tab ? Strope_Capabilities::MANAGE_TRANSLATIONS : $capability;
	}

	/**
	 * Adds the bulk action to the lists of translated post types.
	 *
	 * @since 4.2.0
	 *
	 * @param WP_Screen $screen Current screen.
	 * @return void
	 */
	public function init_posts_screen( $screen ) {
		if ( 'edit' !== $screen->base || empty( $screen->post_type ) || 'attachment' === $screen->post_type || ! $this->model->is_translated_post_type( $screen->post_type ) || ! current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			return;
		}

		add_filter(
			"bulk_actions-{$screen->id}",
			function ( $actions ) {
				$actions[ self::BULK_ACTION ] = __( 'Machine translate', 'strope-translate' );
				return $actions;
			}
		);

		add_filter(
			"handle_bulk_actions-{$screen->id}",
			function ( $sendback, $action, $post_ids ) {
				if ( self::BULK_ACTION !== $action ) {
					return $sendback;
				}
				return self::get_page_url( array( 'posts' => implode( ',', array_map( 'intval', (array) $post_ids ) ) ) );
			},
			10,
			3
		);
	}

	/**
	 * Enqueues the page script, or the background worker on the other admin pages.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( ! current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			return;
		}

		$screen  = get_current_screen();
		$is_page = $screen && 'languages_page_' . self::PAGE === $screen->id;
		$suffix  = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		if ( $is_page ) {
			wp_enqueue_style( 'strope-mt', plugins_url( '/css/build/machine-translation' . $suffix . '.css', STROPE_TRANSLATE_FILE ), array(), STROPE_TRANSLATE_VERSION );
			wp_enqueue_script( 'strope-mt-admin', plugins_url( '/js/build/mt-admin' . $suffix . '.js', STROPE_TRANSLATE_FILE ), array(), STROPE_TRANSLATE_VERSION, true );
			wp_add_inline_script( 'strope-mt-admin', 'window.stropeMt = ' . wp_json_encode( $this->get_script_data( true ) ) . ';', 'before' );
			return;
		}

		if ( Strope_Machine_Translation::user_wants_background() ) {
			wp_enqueue_script( 'strope-mt-background', plugins_url( '/js/build/mt-background' . $suffix . '.js', STROPE_TRANSLATE_FILE ), array(), STROPE_TRANSLATE_VERSION, true );
			wp_add_inline_script( 'strope-mt-background', 'window.stropeMt = ' . wp_json_encode( $this->get_script_data( false ) ) . ';', 'before' );
		}
	}

	/**
	 * Returns the data of the scripts.
	 *
	 * @since 4.2.0
	 *
	 * @param bool $page Whether the data is for the admin page (more data).
	 * @return array
	 */
	protected function get_script_data( bool $page ): array {
		$data               = Strope_Machine_Translation::get_client_data();
		$data['background'] = Strope_Machine_Translation::user_wants_background();

		if ( ! $page ) {
			return $data;
		}

		$data['backgroundAllowed'] = (bool) Strope_Machine_Translation::get_options()['background'];
		$data['canManageModels']   = current_user_can( Strope_Capabilities::MANAGE_LANGUAGES );
		$data['i18n']             += array(
			'start'        => __( 'Start translating in this tab', 'strope-translate' ),
			'stop'         => __( 'Stop', 'strope-translate' ),
			'install'      => __( 'Install', 'strope-translate' ),
			'update'       => __( 'Update', 'strope-translate' ),
			'remove'       => __( 'Remove', 'strope-translate' ),
			'installed'    => __( 'Installed', 'strope-translate' ),
			'notAvailable' => __( 'Not available', 'strope-translate' ),
			'confirmRemove'=> __( 'Remove the models of this language pair?', 'strope-translate' ),
			'pause'        => __( 'Pause', 'strope-translate' ),
			'resume'       => __( 'Resume', 'strope-translate' ),
			'cancel'       => __( 'Cancel', 'strope-translate' ),
			'retry'        => __( 'Retry failed', 'strope-translate' ),
			'details'      => __( 'Details', 'strope-translate' ),
			'edit'         => __( 'Edit translation', 'strope-translate' ),
			'noRuns'       => __( 'Nothing translated yet. Select posts or pages in their lists and use the "Machine translate" bulk action, or use the form above.', 'strope-translate' ),
			/* translators: 1: number of translated posts, 2: total number of posts. */
			'posts'        => __( '%1$d of %2$d posts', 'strope-translate' ),
			'statuses'     => array(
				'active'      => __( 'In progress', 'strope-translate' ),
				'paused'      => __( 'Paused', 'strope-translate' ),
				'cancelled'   => __( 'Cancelled', 'strope-translate' ),
				'done'        => __( 'Done', 'strope-translate' ),
				'queued'      => __( 'Waiting', 'strope-translate' ),
				'preparing'   => __( 'Waiting', 'strope-translate' ),
				'translating' => __( 'Translating', 'strope-translate' ),
				'saving'      => __( 'Saving', 'strope-translate' ),
				'skipped'     => __( 'Skipped', 'strope-translate' ),
				'failed'      => __( 'Failed', 'strope-translate' ),
			),
			/* translators: %d: number of skipped translations. */
			'skippedCount' => __( '%d skipped', 'strope-translate' ),
			/* translators: %d: number of failed translations. */
			'failedCount'  => __( '%d failed', 'strope-translate' ),
		);

		return $data;
	}

	/**
	 * Adds the progress indicator of the background worker to the admin bar (shown by the script when working).
	 *
	 * @since 4.2.0
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 * @return void
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		if ( ! is_admin() || ! current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'strope-mt',
				'title' => '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span><span class="ab-label"></span>',
				'href'  => self::get_page_url(),
				'meta'  => array( 'class' => 'hidden' ),
			)
		);
	}

	/**
	 * Creates a run from the form of the page.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public function handle_create() {
		check_admin_referer( 'strope_mt_create' );

		if ( ! current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to translate content.', 'strope-translate' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$languages = isset( $_POST['languages'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['languages'] ) ) : array();
		$status    = isset( $_POST['post_status'] ) && 'publish' === $_POST['post_status'] ? 'publish' : 'draft';

		if ( isset( $_POST['posts'] ) ) {
			$post_ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['posts'] ) ) ) ) );
		} else {
			$types    = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
			$source   = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
			$post_ids = $this->get_untranslated_posts( $types, $source, $languages );
		}
		// phpcs:enable

		$result = Strope_MT_Queue::create_run( $post_ids, $languages, array( 'post_status' => $status ) );

		if ( is_wp_error( $result ) ) {
			set_transient( 'strope_mt_notice_' . get_current_user_id(), array( 'error', $result->get_error_message() ), 60 );
		} else {
			$message = sprintf(
				/* translators: %d: number of translations */
				_n( '%d translation was added to the queue.', '%d translations were added to the queue.', $result['jobs'], 'strope-translate' ),
				$result['jobs']
			);
			if ( $result['skipped'] ) {
				$message .= ' ' . implode( ' ', array_slice( $result['skipped'], 0, 5 ) );
			}
			set_transient( 'strope_mt_notice_' . get_current_user_id(), array( 'success', $message ), 60 );
		}

		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * Saves the glossary from the form of the page.
	 *
	 * @since 4.3.0
	 *
	 * @return void
	 */
	public function handle_glossary() {
		check_admin_referer( 'strope_mt_glossary' );

		if ( ! current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the glossary.', 'strope-translate' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked above, sanitized when saved.
		$rows  = isset( $_POST['glossary'] ) && is_array( $_POST['glossary'] ) ? (array) wp_unslash( $_POST['glossary'] ) : array();
		$count = Strope_Machine_Translation::save_glossary_entries( array_filter( $rows, 'is_array' ) );

		set_transient(
			'strope_mt_notice_' . get_current_user_id(),
			array(
				'success',
				/* translators: %d: number of glossary terms */
				sprintf( _n( 'Glossary saved: %d term.', 'Glossary saved: %d terms.', $count, 'strope-translate' ), $count ),
			),
			60
		);

		wp_safe_redirect( self::get_page_url() . '#strope-mt-glossary' );
		exit;
	}

	/**
	 * Returns the posts in a language which have no translation in at least one of the target languages.
	 *
	 * @since 4.2.0
	 *
	 * @param string[] $types     Post types.
	 * @param string   $source    Source language slug.
	 * @param string[] $languages Target language slugs.
	 * @return int[]
	 */
	protected function get_untranslated_posts( array $types, string $source, array $languages ): array {
		$language = $this->model->get_language( $source );
		$types    = array_values( array_filter( $types, array( $this->model, 'is_translated_post_type' ) ) );

		if ( ! $language || empty( $types ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'lang'             => $language->slug,
				'fields'           => 'ids',
				'posts_per_page'   => 5000,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		return array_values(
			array_filter(
				array_map( 'intval', (array) $ids ),
				function ( $id ) use ( $languages ) {
					foreach ( $languages as $slug ) {
						$tr_id = (int) $this->model->post->get( $id, $slug );
						if ( ! $tr_id || ( Strope_MT_Queue::can_overwrite( $tr_id ) && ! get_post_meta( $tr_id, Strope_MT_Queue::META, true ) ) ) {
							return true; // Missing, or an untouched duplicate.
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * Displays the page.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public function display_page() {
		if ( ! current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			return;
		}

		Strope_MT_Store::maybe_install();

		$notice = get_transient( 'strope_mt_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'strope_mt_notice_' . get_current_user_id() );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_ids   = isset( $_GET['posts'] ) ? array_slice( array_unique( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['posts'] ) ) ) ) ) ), 0, 5000 ) : array();
		$languages  = array_filter( $this->model->get_languages_list(), array( 'Strope_MT_Queue', 'user_can_translate_language' ) );
		$options    = Strope_Machine_Translation::get_options();
		$directions = current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) ? Strope_MT_Engine_Files::get_site_directions() : array();
		$glossary   = current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) ? Strope_Machine_Translation::get_glossary_entries() : null;
		$index      = current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) ? Strope_MT_Engine_Files::get_index() : array();
		$post_types = array();

		foreach ( $this->model->get_translated_post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( $object && $object->show_ui && 'attachment' !== $type && current_user_can( $object->cap->edit_posts ) ) {
				$post_types[ $type ] = $object->labels->name;
			}
		}

		include __DIR__ . '/view-machine-translation.php';
	}
}
