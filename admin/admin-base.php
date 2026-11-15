<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Setup features available on all admin pages.
 *
 * @since 1.8
 */
abstract class Strope_Admin_Base extends Strope_Base {
	/**
	 * Current language (used to filter the content).
	 *
	 * @var Strope_Language|null
	 */
	public $curlang;

	/**
	 * Language selected in the admin language filter.
	 *
	 * @var Strope_Language|null
	 */
	public $filter_lang;

	/**
	 * Preferred language to assign to new contents.
	 *
	 * @var Strope_Language|null
	 */
	public $pref_lang;

	/**
	 * @var Strope_Filters_Links|null
	 */
	public $filters_links;

	/**
	 * @var Strope_Admin_Links|null
	 */
	public $links;

	/**
	 * @var Strope_Admin_Notices|null
	 */
	public $notices;

	/**
	 * @var Strope_Admin_Static_Pages|null
	 */
	public $static_pages;

	/**
	 * @var Strope_Admin_Default_Term|null
	 */
	public $default_term;

	/**
	 * Setups actions needed on all admin pages.
	 *
	 * @since 1.8
	 *
	 * @param Strope_Links_Model $links_model Reference to the links model.
	 */
	public function __construct( &$links_model ) {
		parent::__construct( $links_model );

		// Adds the link to the languages panel in the WordPress admin menu
		add_action( 'admin_menu', array( $this, 'add_menus' ) );

		add_action( 'admin_menu', array( $this, 'remove_customize_submenu' ) );

		// Setup js scripts and css styles
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_print_footer_scripts', array( $this, 'admin_print_footer_scripts' ), 0 ); // High priority in case an ajax request is sent by an immediately invoked function

		add_action( 'customize_controls_enqueue_scripts', array( $this, 'customize_controls_enqueue_scripts' ) );

		// Early instantiated to be able to correctly initialize language properties.
		$this->static_pages = new Strope_Admin_Static_Pages( $this );
		$this->model->set_languages_ready();
	}

	/**
	 * Setups filters and action needed on all admin pages and on plugins page
	 * Loads the settings pages or the filters base on the request
	 *
	 * @since 1.2
	 */
	public function init() {
		parent::init();

		$this->notices = new Strope_Admin_Notices( $this );

		$this->default_term = new Strope_Admin_Default_Term( $this );
		$this->default_term->add_hooks();

		if ( ! $this->model->has_languages() ) {
			return;
		}

		$this->links = new Strope_Admin_Links( $this ); // FIXME needed here ?
		$this->filters_links = new Strope_Filters_Links( $this ); // FIXME needed here ?

		// Filter admin language for users
		// We must not call user info before WordPress defines user roles in wp-settings.php
		add_action( 'setup_theme', array( $this, 'init_user' ) );
		add_filter( 'request', array( $this, 'request' ) );

		// Adds the languages in admin bar
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 ); // 100 determines the position
	}

	/**
	 * Adds the link to the languages panel in the WordPress admin menu
	 *
	 * @since 0.1
	 *
	 * @return void
	 */
	public function add_menus() {
		global $admin_page_hooks;

		// Prepare the list of tabs
		$tabs = array( 'lang' => __( 'Languages', 'strope-translate' ) );

		// Only if at least one language has been created
		if ( $this->model->has_languages() ) {
			$tabs['strings'] = __( 'Translations', 'strope-translate' );
		}

		$tabs['settings'] = __( 'Settings', 'strope-translate' );

		/**
		 * Filter the list of tabs in Strope Translate settings
		 *
		 * @since 1.5.1
		 *
		 * @param array $tabs list of tab names
		 */
		$tabs = apply_filters( 'strope_settings_tabs', $tabs );

		$parent = '';

		foreach ( $tabs as $tab => $title ) {
			$page = 'lang' === $tab ? 'mlang' : "mlang_$tab";
			if ( empty( $parent ) ) {
				$parent = $page;
				add_menu_page( $title, __( 'Languages', 'strope-translate' ), self::get_tab_capability( $tab ), $page, '__return_null', 'dashicons-translation' );
				$admin_page_hooks[ $page ] = 'languages'; // Hack to avoid the localization of the hook name. See: https://core.trac.wordpress.org/ticket/18857
			}

			add_submenu_page( $parent, $title, $title, self::get_tab_capability( $tab ), $page, array( $this, 'languages_page' ) );
		}
	}

	/**
	 * Returns the capability required to access a tab of the languages admin pages.
	 *
	 * @since 4.1.0
	 *
	 * @param string $tab Tab name, e.g. 'lang', 'strings', 'settings'.
	 * @return string
	 */
	public static function get_tab_capability( $tab ) {
		$capability = 'strings' === $tab ? Strope_Capabilities::MANAGE_TRANSLATIONS : Strope_Capabilities::MANAGE_LANGUAGES;

		/**
		 * Filters the capability required to access a tab of the languages admin pages.
		 *
		 * @since 4.1.0
		 *
		 * @param string $capability Capability, `manage_languages` or `manage_translations` by default.
		 * @param string $tab        Tab name.
		 */
		return (string) apply_filters( 'strope_settings_tab_capability', $capability, $tab );
	}

	/**
	 * Setup js scripts & css styles ( only on the relevant pages )
	 *
	 * @since 0.6
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		$screen = get_current_screen();
		if ( empty( $screen ) ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		/*
		 * For each script:
		 * 0 => the pages on which to load the script
		 * 1 => the scripts it needs to work
		 * 2 => true if loaded even if languages have not been defined yet, false otherwise
		 * 3 => true if loaded in footer
		 */
		$scripts = array(
			'user'    => array( array( 'profile', 'user-edit' ), array( 'jquery' ), false, false ),
			'widgets' => array( array( 'widgets' ), array( 'jquery' ), false, false ),
		);

		$block_screens = array( 'widgets', 'site-editor' );

		if ( ! empty( $screen->post_type ) && $this->model->is_translated_post_type( $screen->post_type ) ) {
			$scripts['post'] = array( array( 'edit', 'upload' ), array( 'jquery', 'wp-ajax-response' ), false, true );

			// Classic editor.
			if ( ! method_exists( $screen, 'is_block_editor' ) || ! $screen->is_block_editor() ) {
				$scripts['classic-editor'] = array( array( 'post', 'media', 'async-upload' ), array( 'jquery', 'wp-ajax-response', 'post', 'jquery-ui-dialog', 'wp-i18n' ), false, true );
			}

			// Block editor with legacy metabox in WP 5.0+.
			$block_screens[] = 'post';
		}

		if ( $this->is_block_editor( $screen ) ) {
			$scripts['block-editor'] = array( $block_screens, array( 'jquery', 'wp-ajax-response', 'wp-api-fetch', 'jquery-ui-dialog', 'wp-i18n' ), false, true );
		}

		if ( ! empty( $screen->taxonomy ) && $this->model->is_translated_taxonomy( $screen->taxonomy ) ) {
			$scripts['term'] = array( array( 'edit-tags', 'term' ), array( 'jquery', 'wp-ajax-response', 'jquery-ui-autocomplete' ), false, true );
		}

		foreach ( $scripts as $script => $v ) {
			if ( in_array( $screen->base, $v[0] ) && ( $v[2] || $this->model->has_languages() ) ) {
				wp_enqueue_script( 'strope_' . $script, plugins_url( '/js/build/' . $script . $suffix . '.js', STROPE_TRANSLATE_FILE ), $v[1], STROPE_TRANSLATE_VERSION, $v[3] );
				if ( 'classic-editor' === $script || 'block-editor' === $script ) {
					wp_set_script_translations( 'strope_' . $script, 'strope-translate', STROPE_TRANSLATE_DIR . '/languages' );
				}
			}
		}

		wp_register_style( 'strope_translate_admin', plugins_url( '/css/build/admin' . $suffix . '.css', STROPE_TRANSLATE_FILE ), array( 'wp-jquery-ui-dialog' ), STROPE_TRANSLATE_VERSION );
		wp_enqueue_style( 'strope_translate_dialog', plugins_url( '/css/build/dialog' . $suffix . '.css', STROPE_TRANSLATE_FILE ), array( 'strope_translate_admin' ), STROPE_TRANSLATE_VERSION );

		$this->add_inline_scripts();
	}

	/**
	 * Tells whether or not the given screen is block editor kind.
	 * e.g. widget, site or post editor.
	 *
	 * @since 3.3
	 *
	 * @param WP_Screen $screen Screen object.
	 * @return bool True if the screen is a block editor, false otherwise.
	 */
	protected function is_block_editor( $screen ) {
		return method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() && ! strope_use_block_editor_plugin();
	}

	/**
	 * Enqueue scripts to the WP Customizer.
	 *
	 * @since 2.4.0
	 *
	 * @return void
	 */
	public function customize_controls_enqueue_scripts() {
		if ( $this->model->has_languages() ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_enqueue_script( 'strope_widgets', plugins_url( '/js/build/widgets' . $suffix . '.js', STROPE_TRANSLATE_FILE ), array( 'jquery' ), STROPE_TRANSLATE_VERSION, true );
			$this->add_inline_scripts();
		}
	}

	/**
	 * Adds inline scripts to set the default language in JS
	 * and localizes scripts.
	 *
	 * @since 3.3
	 *
	 * @return void
	 */
	private function add_inline_scripts() {
		if ( wp_script_is( 'strope_block-editor', 'enqueued' ) ) {
			$default_lang_script = sprintf( 'const stropeDefaultLanguage = %s;', wp_json_encode( $this->options['default_lang'] ) );
			wp_add_inline_script(
				'strope_block-editor',
				$default_lang_script,
				'before'
			);
		}
		if ( wp_script_is( 'strope_widgets', 'enqueued' ) ) {
			wp_localize_script(
				'strope_widgets',
				'strope_widgets',
				array(
					'flags' => wp_list_pluck( $this->model->get_languages_list(), 'flag', 'slug' ),
				)
			);
		}
	}

	/**
	 * Sets strope_ajax_backend on all backend ajax request
	 * The final goal is to detect if an ajax request is made on admin or frontend
	 *
	 * Takes care to various situations:
	 * when the ajax request has no options.data thanks to ScreenfeedFr
	 * see: https://wordpress.org/support/topic/ajaxprefilter-may-not-work-as-expected
	 * when options.data is a json string
	 * when options.data is an empty string (GET request with the method 'load')
	 * see: https://wordpress.org/support/topic/invalid-url-during-wordpress-new-dashboard-widget-operation
	 *
	 * @since 1.4
	 *
	 * @return void
	 */
	public function admin_print_footer_scripts() {
		global $post, $tag;

		$params = array( 'strope_ajax_backend' => 1 );
		if ( $post instanceof WP_Post && $this->model->is_translated_post_type( $post->post_type ) ) {
			$params['strope_post_id'] = $post->ID;
		}

		if ( $tag instanceof WP_Term && $this->model->is_translated_taxonomy( $tag->taxonomy ) ) {
			$params['strope_term_id'] = $tag->term_id;
		}

		/**
		 * Filters the list of parameters to add to the admin ajax request.
		 *
		 * @since 3.4.5
		 *
		 * @param array $params List of parameters to add to the admin ajax request.
		 */
		$params = apply_filters( 'strope_admin_ajax_params', $params );

		$str = http_build_query( $params );
		$arr = wp_json_encode( $params );
		?>
		<script>
			if (typeof jQuery != 'undefined') {
				jQuery(
					function( $ ){
						$.ajaxPrefilter( function ( options, originalOptions, jqXHR ) {
							if ( -1 != options.url.indexOf( ajaxurl ) || -1 != ajaxurl.indexOf( options.url ) ) {

								function addStropeTranslateParametersAsString() {
									if ( 'undefined' === typeof options.data || '' === options.data.trim() ) {
										// Only Strope Translate data need to be send. So it could be as a simple query string.
										options.data = '<?php echo $str; // phpcs:ignore WordPress.Security.EscapeOutput ?>';
									} else {
										/*
										 * In some cases data could be a JSON string like in third party plugins.
										 * So we need not to break their process by adding strope_translate parameters as valid JSON data.
										 */
										try {
											options.data = JSON.stringify( Object.assign( JSON.parse( options.data ), <?php echo $arr; // phpcs:ignore WordPress.Security.EscapeOutput ?> ) );
										} catch( exception ) {
											// Add Strope Translate data to the existing query string.
											options.data = options.data + '&<?php echo $str; // phpcs:ignore WordPress.Security.EscapeOutput ?>';
										}
									}
								}

								/*
								 * options.processData set to true is the default jQuery process where the data is converted in a query string by using jQuery.param().
								 * This step is done before applying filters. Thus here the options.data is already a string in this case.
								 * @See https://github.com/jquery/jquery/blob/3.5.1/src/ajax.js#L563-L569 jQuery ajax function.
								 * It is the most case WordPress send ajax request this way however third party plugins or themes could be send JSON string.
								 * Use JSON format is recommended in jQuery.param() documentation to be able to send complex data structures.
								 * @See https://api.jquery.com/jquery.param/ jQuery param function.
								 */
								if ( options.processData ) {
									addStropeTranslateParametersAsString();
								} else {
									/*
									 * If options.processData is set to false data could be undefined or pass as a string.
									 * So data as to be processed as if options.processData is set to true.
									 */
									if ( 'undefined' === typeof options.data || 'string' === typeof options.data ) {
										addStropeTranslateParametersAsString();
									} else {
										// Otherwise options.data is probably an object.
										options.data = Object.assign( options.data || {} , <?php echo $arr; // phpcs:ignore WordPress.Security.EscapeOutput ?> );
									}
								}
							}
						});
					}
				);
			}
		</script>
		<?php
	}

	/**
	 * Sets the admin current language, used to filter the content
	 *
	 * @since 2.0
	 *
	 * @return void
	 */
	public function set_current_language() {
		$this->curlang = $this->filter_lang;

		// Edit Post
		if ( isset( $_REQUEST['strope_post_id'] ) && $lang = $this->model->post->get_language( (int) $_REQUEST['strope_post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->curlang = $lang;
		} elseif ( 'post.php' === $GLOBALS['pagenow'] && isset( $_GET['post'] ) && $this->model->is_translated_post_type( get_post_type( (int) $_GET['post'] ) ) && $lang = $this->model->post->get_language( (int) $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->curlang = $lang;
		} elseif ( 'post-new.php' === $GLOBALS['pagenow'] && ( empty( $_GET['post_type'] ) || $this->model->is_translated_post_type( sanitize_key( $_GET['post_type'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->curlang = empty( $_GET['new_lang'] ) ? $this->pref_lang : $this->model->get_language( sanitize_key( $_GET['new_lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		// Edit Term
		elseif ( isset( $_REQUEST['strope_term_id'] ) && $lang = $this->model->term->get_language( (int) $_REQUEST['strope_term_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->curlang = $lang;
		} elseif ( in_array( $GLOBALS['pagenow'], array( 'edit-tags.php', 'term.php' ) ) && isset( $_GET['taxonomy'] ) && $this->model->is_translated_taxonomy( sanitize_key( $_GET['taxonomy'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( isset( $_GET['tag_ID'] ) && $lang = $this->model->term->get_language( (int) $_GET['tag_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$this->curlang = $lang;
			} elseif ( ! empty( $_GET['new_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$this->curlang = $this->model->get_language( sanitize_key( $_GET['new_lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			} elseif ( empty( $this->curlang ) ) {
				$this->curlang = $this->pref_lang;
			}
		}

		// Ajax
		if ( wp_doing_ajax() && ! empty( $_REQUEST['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->curlang = $this->model->get_language( sanitize_key( $_REQUEST['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		/**
		 * Filters the current language used by Strope Translate in the admin context.
		 *
		 * @since 3.2
		 *
		 * @param Strope_Language|false|null $curlang  Instance of the current language.
		 * @param Strope_Admin_Base          $strope_translate Instance of the main Strope Translate's object.
		 */
		$this->curlang = apply_filters( 'strope_admin_current_language', $this->curlang, $this );

		// Inform that the admin language has been set.
		if ( $this->curlang instanceof Strope_Language ) {
			/** This action is documented in frontend/choose-lang.php */
			do_action( 'strope_language_defined', $this->curlang->slug, $this->curlang );
		} else {
			/** This action is documented in include/class-strope-translate.php */
			do_action( 'strope_no_language_defined' ); // To load overridden textdomains.
		}
	}

	/**
	 * Defines the backend language and the admin language filter based on user preferences
	 *
	 * @since 1.2.3
	 *
	 * @return void
	 */
	public function init_user() {
		// Language for admin language filter: may be empty
		// $_GET['lang'] is numeric when editing a language, not when selecting a new language in the filter
		// We intentionally don't use a nonce to update the language filter
		if ( ! wp_doing_ajax() && ! empty( $_GET['lang'] ) && ! is_numeric( sanitize_key( $_GET['lang'] ) ) && current_user_can( 'edit_user', $user_id = get_current_user_id() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			update_user_meta( $user_id, 'strope_filter_content', ( $lang = $this->model->get_language( sanitize_key( $_GET['lang'] ) ) ) ? $lang->slug : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		$this->filter_lang = $this->model->get_language( get_user_meta( get_current_user_id(), 'strope_filter_content', true ) );

		// Set preferred language for use when saving posts and terms: must not be empty
		$this->pref_lang = empty( $this->filter_lang ) ? $this->model->get_default_language() : $this->filter_lang;

		/**
		 * Filters the preferred language on admin side.
		 * The preferred language is used for example to determine the language of a new post.
		 *
		 * @since 1.2.3
		 *
		 * @param Strope_Language $pref_lang Preferred language.
		 */
		$this->pref_lang = apply_filters( 'strope_admin_preferred_language', $this->pref_lang );

		$this->set_current_language();
	}

	/**
	 * Avoids parsing a tax query when all languages are requested
	 * Fixes https://wordpress.org/support/topic/notice-undefined-offset-0-in-wp-includesqueryphp-on-line-3877 introduced in WP 4.1
	 *
	 * @see https://core.trac.wordpress.org/ticket/31246 the suggestion of @boonebgorges.
	 *
	 * @since 1.6.5
	 *
	 * @param array $qvars The array of requested query variables.
	 * @return array
	 */
	public function request( $qvars ) {
		if ( isset( $qvars['lang'] ) && 'all' === $qvars['lang'] ) {
			unset( $qvars['lang'] );
		}

		return $qvars;
	}

	/**
	 * Adds the languages list in admin bar for the admin languages filter.
	 *
	 * @since 0.9
	 *
	 * @param WP_Admin_Bar $wp_admin_bar WP_Admin_Bar global object.
	 * @return void
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		$all_item = (object) array(
			'slug' => 'all',
			'name' => __( 'Show all languages', 'strope-translate' ),
			'flag' => '<span class="ab-icon"></span>',
		);

		$selected = empty( $this->filter_lang ) ? $all_item : $this->filter_lang;

		$title = sprintf(
			'<span class="ab-label"%1$s><span class="screen-reader-text">%2$s</span>%3$s</span>',
			$selected instanceof Strope_Language ? sprintf( ' lang="%s"', esc_attr( $selected->get_locale( 'display' ) ) ) : '',
			__( 'Filters content by language', 'strope-translate' ),
			esc_html( $selected->name )
		);

		/**
		 * Filters the admin languages filter submenu items
		 *
		 * @since 2.6
		 *
		 * @param array $items The admin languages filter submenu items.
		 */
		$items = apply_filters( 'strope_admin_languages_filter', array_merge( array( $all_item ), $this->model->get_languages_list() ) );

		$menu = array(
			'id'    => 'languages',
			'title' => $selected->flag . $title,
			'href'  => esc_url( add_query_arg( 'lang', $selected->slug, remove_query_arg( 'paged' ) ) ),
			'meta'  => array(
				'title' => __( 'Filters content by language', 'strope-translate' ),
			),
		);

		if ( 'all' !== $selected->slug ) {
			$menu['meta']['class'] = 'strope-filtered-languages';
		}

		if ( ! empty( $items ) ) {
			$wp_admin_bar->add_menu( $menu );
		}

		foreach ( $items as $lang ) {
			if ( $selected->slug === $lang->slug ) {
				continue;
			}

			$wp_admin_bar->add_menu(
				array(
					'parent' => 'languages',
					'id'     => $lang->slug,
					'title'  => $lang->flag . esc_html( $lang->name ),
					'href'   => esc_url( add_query_arg( 'lang', $lang->slug, remove_query_arg( 'paged' ) ) ),
					'meta'   => 'all' === $lang->slug ? array() : array( 'lang' => esc_attr( $lang->get_locale( 'display' ) ) ),
				)
			);
		}
	}

	/**
	 * Remove the customize submenu when using a block theme.
	 *
	 * WordPress removes the Customizer menu if a block theme is activated and no other plugins interact with it.
	 * As Strope Translate interacts with the Customizer, we have to delete this menu ourselves in the case of a block theme,
	 * unless another plugin than Strope Translate interacts with the Customizer.
	 *
	 * @since 3.2
	 *
	 * @return void
	 */
	public function remove_customize_submenu() {
		if ( ! $this->should_customize_menu_be_removed() ) {
			return;
		}

		global $submenu;

		if ( ! empty( $submenu['themes.php'] ) ) {
			foreach ( $submenu['themes.php'] as $submenu_item ) {
				if ( 'customize' === $submenu_item[1] ) {
					remove_submenu_page( 'themes.php', $submenu_item[2] );
				}
			}
		}
	}
}
