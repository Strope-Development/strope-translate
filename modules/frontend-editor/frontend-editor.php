<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end "Edit translations" mode.
 *
 * Adds an admin bar entry reloading the page in edit mode. In edit mode, the editable
 * content is marked and a side panel allows to edit the translations, which are saved
 * into the translated posts or the strings translations through the REST API.
 * Nothing is loaded for visitors or when the edit mode is off.
 *
 * @since 4.1.0
 */
class Strope_Frontend_Editor {
	const QUERY_ARG    = 'strope_edit';
	const NONCE_ARG    = '_strope_nonce';
	const NONCE_ACTION = 'strope_frontend_edit';
	const FOCUS_ARG    = 'strope_fe_focus';

	/**
	 * Strope Translate's frontend object.
	 *
	 * @var Strope_Frontend
	 */
	private $strope;

	/**
	 * Whether the edit mode is on.
	 *
	 * @var bool
	 */
	private $editing = false;

	/**
	 * Markers of the editable content.
	 *
	 * @var Strope_Frontend_Editor_Markers|null
	 */
	private $markers;

	/**
	 * Strings collector.
	 *
	 * @var Strope_Frontend_Editor_Strings|null
	 */
	private $strings;

	/**
	 * Custom fields recorder.
	 *
	 * @var Strope_Frontend_Editor_ACF|null
	 */
	private $acf;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Frontend $strope Strope Translate's frontend object.
	 */
	public function __construct( $strope ) {
		$this->strope = $strope;

		add_action( 'init', array( $this, 'maybe_start_editing' ), 1 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 90 );
	}

	/**
	 * Tells whether the current user may use the front-end editor at all.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	private static function user_can_use(): bool {
		return current_user_can( 'edit_posts' ) || current_user_can( 'manage_translations' );
	}

	/**
	 * Turns the edit mode on when requested with a valid nonce.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function maybe_start_editing() {
		if ( empty( $_GET[ self::QUERY_ARG ] ) || empty( $_GET[ self::NONCE_ARG ] ) || is_admin() || ! is_user_logged_in() ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET[ self::NONCE_ARG ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! self::user_can_use() ) {
			return;
		}

		$this->editing = true;

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		add_action( 'send_headers', 'nocache_headers' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_footer', array( $this, 'print_strings' ), 1000 );

		$this->markers = new Strope_Frontend_Editor_Markers( $this->strope->model );

		if ( current_user_can( 'manage_translations' ) ) {
			$this->strings = new Strope_Frontend_Editor_Strings();
			$this->strings->start();
		}

		// ACF is loaded after the plugins, check it when the page is rendered.
		add_action(
			'wp',
			function () {
				if ( Strope_Frontend_Editor_ACF::is_active() ) {
					$this->acf = new Strope_Frontend_Editor_ACF( $this->strope->model );
					$this->acf->start();
				}
			}
		);
	}

	/**
	 * Returns the URL of the current page without the edit mode arguments.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	private function get_exit_url(): string {
		return remove_query_arg( array( self::QUERY_ARG, self::NONCE_ARG, self::FOCUS_ARG ), strope_get_requested_url() );
	}

	/**
	 * Returns the language of the edited content.
	 *
	 * @since 4.1.0
	 *
	 * @return Strope_Language|null
	 */
	private function get_edited_language() {
		if ( is_singular() ) {
			$language = $this->strope->model->post->get_language( (int) get_queried_object_id() );
			if ( $language instanceof Strope_Language ) {
				return $language;
			}
		}
		return $this->strope->curlang instanceof Strope_Language ? $this->strope->curlang : null;
	}

	/**
	 * Returns the ID of the queried post if it can be edited by the current user.
	 *
	 * @since 4.1.0
	 *
	 * @return int
	 */
	private function get_editable_queried_post(): int {
		if ( ! is_singular() ) {
			return 0;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! $this->strope->model->is_translated_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return 0;
		}
		return $post->ID;
	}

	/**
	 * Adds the admin bar entries.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 * @return void
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}

		$language = $this->get_edited_language();
		$post_id  = $this->get_editable_queried_post();

		if ( $this->editing ) {
			$wp_admin_bar->add_node(
				array(
					'id'    => 'strope-frontend-editor',
					'title' => '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span><span class="ab-label">' . esc_html(
						$language
						/* translators: %s is a language name. */
						? sprintf( __( 'Editing translations: %s', 'strope-translate' ), $language->name )
						: __( 'Editing translations', 'strope-translate' )
					) . '</span>',
					'href'  => $this->get_exit_url(),
					'meta'  => array( 'class' => 'strope-fe-ab-editing' ),
				)
			);

			$children = array(
				'strope-fe-exit' => array( __( 'Stop editing', 'strope-translate' ), $this->get_exit_url() ),
			);

			if ( $post_id ) {
				$children['strope-fe-title'] = array( __( 'Edit title', 'strope-translate' ), '#strope-fe-title' );
				if ( post_type_supports( get_post_type( $post_id ), 'excerpt' ) ) {
					$children['strope-fe-excerpt'] = array( __( 'Edit excerpt', 'strope-translate' ), '#strope-fe-excerpt' );
				}
			}

			if ( $this->strings ) {
				$children['strope-fe-strings'] = array( __( 'Strings on this page', 'strope-translate' ), '#strope-fe-strings' );
			}

			foreach ( $children as $id => $child ) {
				$wp_admin_bar->add_node(
					array(
						'parent' => 'strope-frontend-editor',
						'id'     => $id,
						'title'  => esc_html( $child[0] ),
						'href'   => $child[1],
					)
				);
			}
			return;
		}

		if ( ! $post_id && ! current_user_can( 'manage_translations' ) && ( is_singular() || ! current_user_can( 'edit_posts' ) ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'strope-frontend-editor',
				'title' => '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span><span class="ab-label">' . esc_html(
					$language
					/* translators: %s is a language name. */
					? sprintf( __( 'Edit translations (%s)', 'strope-translate' ), $language->name )
					: __( 'Edit translations', 'strope-translate' )
				) . '</span>',
				'href'  => add_query_arg(
					array(
						self::QUERY_ARG => 1,
						self::NONCE_ARG => wp_create_nonce( self::NONCE_ACTION ),
					),
					strope_get_requested_url()
				),
			)
		);
	}

	/**
	 * Adds a class to the body in edit mode.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		$classes[] = 'strope-fe-editing';
		return $classes;
	}

	/**
	 * Enqueues the editor script and stylesheet.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		$suffix   = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$language = $this->get_edited_language();
		$post_id  = $this->get_editable_queried_post();

		wp_enqueue_style( 'strope-frontend-editor', plugins_url( '/css/build/frontend-editor' . $suffix . '.css', STROPE_TRANSLATE_FILE ), array( 'dashicons' ), STROPE_TRANSLATE_VERSION );
		wp_enqueue_script( 'strope-frontend-editor', plugins_url( '/js/build/frontend-editor' . $suffix . '.js', STROPE_TRANSLATE_FILE ), array(), STROPE_TRANSLATE_VERSION, true );

		$data = array(
			'root'        => esc_url_raw( rest_url( Strope_Frontend_Editor_REST::NAMESPACE . '/' . Strope_Frontend_Editor_REST::BASE . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'exitUrl'     => $this->get_exit_url(),
			'language'    => $language ? array(
				'slug' => $language->slug,
				'name' => $language->name,
				'code' => $language->w3c,
				'rtl'  => (bool) $language->is_rtl,
			) : null,
			'post'        => $post_id,
			'canStrings'  => null !== $this->strings,
			'canOverride' => null !== $this->strings && Strope_Frontend_Editor_Strings::can_save_discovered(),
			'i18n'        => array(
				'panelTitle'      => __( 'Edit translations', 'strope-translate' ),
				'close'           => __( 'Close', 'strope-translate' ),
				'content'         => __( 'Content', 'strope-translate' ),
				'strings'         => __( 'Strings on this page', 'strope-translate' ),
				'language'        => __( 'Language', 'strope-translate' ),
				'block'           => __( 'Block', 'strope-translate' ),
				'field'           => __( 'Field', 'strope-translate' ),
				'source'          => __( 'Source', 'strope-translate' ),
				'translation'     => __( 'Translation', 'strope-translate' ),
				'save'            => __( 'Save', 'strope-translate' ),
				'cancel'          => __( 'Cancel', 'strope-translate' ),
				'openEditor'      => __( 'Open in editor', 'strope-translate' ),
				'bold'            => __( 'Bold', 'strope-translate' ),
				'italic'          => __( 'Italic', 'strope-translate' ),
				'code'            => __( 'Inline code', 'strope-translate' ),
				'link'            => __( 'Link', 'strope-translate' ),
				'unlink'          => __( 'Remove link', 'strope-translate' ),
				'linkPrompt'      => __( 'Link URL:', 'strope-translate' ),
				'loading'         => __( 'Loading…', 'strope-translate' ),
				'saving'          => __( 'Saving…', 'strope-translate' ),
				'saved'           => __( 'Saved.', 'strope-translate' ),
				'error'           => __( 'An error occurred.', 'strope-translate' ),
				'intro'           => __( 'Click a highlighted text to translate it. Alt+click to follow a link.', 'strope-translate' ),
				'nothingEditable' => __( 'No text of this page can be edited here. The title and the strings can be edited from the "Editing translations" menu in the toolbar, the rest in the editor.', 'strope-translate' ),
				'customField'     => __( 'Custom field', 'strope-translate' ),
				'allLanguages'    => __( 'All languages', 'strope-translate' ),
				/* translators: %s is a language name. */
				'thisPage'        => __( '%s (this page)', 'strope-translate' ),
				'statusMissing'   => __( 'not translated', 'strope-translate' ),
				'statusMismatch'  => __( 'different layout', 'strope-translate' ),
				'statusForbidden' => __( 'not allowed', 'strope-translate' ),
				'editLanguage'    => __( 'Edit', 'strope-translate' ),
				'openPage'        => __( 'Open this page', 'strope-translate' ),
				'addTranslation'  => __( 'Add translation', 'strope-translate' ),
				/* translators: 1: language name of the edited text, 2: language name of the page. */
				'otherLanguage'   => __( 'You are editing the %1$s text. This page shows the %2$s text.', 'strope-translate' ),
				'editedLanguage'  => __( 'Edited language', 'strope-translate' ),
				'noStrings'       => __( 'No translatable strings were found on this page.', 'strope-translate' ),
				'filter'          => __( 'Filter strings', 'strope-translate' ),
				'registered'      => __( 'Registered string', 'strope-translate' ),
				'reloadToSee'     => __( 'Saved. Reload the page to see the change everywhere.', 'strope-translate' ),
				'notOverridable'  => __( 'This string cannot be translated here.', 'strope-translate' ),
				'unsavedConfirm'  => __( 'Discard your unsaved changes?', 'strope-translate' ),
				'shortcuts'       => __( 'Ctrl+Enter to save, Escape to cancel.', 'strope-translate' ),
			),
		);

		wp_add_inline_script( 'strope-frontend-editor', 'var stropeFrontendEditor = ' . wp_json_encode( $data ) . ';', 'before' );

		/**
		 * Fires after the scripts of the front-end translation editor are enqueued, e.g. to add tools to its
		 * panel (the machine translation does).
		 *
		 * @since 4.2.0
		 *
		 * @param Strope_Language|null $language Edited language.
		 * @param int                  $post_id  Edited post, 0 for none.
		 */
		do_action( 'strope_frontend_editor_enqueue_scripts', $language, $post_id );
	}

	/**
	 * Prints the strings used on the page, once they are known.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function print_strings() {
		$language = $this->strope->curlang;
		$strings  = $this->strings && $language instanceof Strope_Language ? $this->strings->get_strings( $language ) : array();

		printf(
			'<script type="application/json" id="strope-fe-strings-data">%s</script>' . "\n",
			wp_json_encode(
				array(
					'language' => $language instanceof Strope_Language ? $language->slug : '',
					'strings'  => $strings,
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			)
		);
	}

	/**
	 * Enqueues the script selecting a block in the block editor when opened from the front-end editor.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public static function enqueue_deep_link() {
		if ( empty( $_GET['strope_fe_block'] ) || ! preg_match( '/^\d{1,5}(?:\.\d{1,5}){0,30}$/', sanitize_text_field( wp_unslash( $_GET['strope_fe_block'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script( 'strope-frontend-editor-deeplink', plugins_url( '/js/build/frontend-editor-deeplink' . $suffix . '.js', STROPE_TRANSLATE_FILE ), array( 'wp-data', 'wp-block-editor' ), STROPE_TRANSLATE_VERSION, true );
	}
}
