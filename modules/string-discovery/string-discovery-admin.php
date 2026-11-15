<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Admin integration of the theme and plugin strings in Languages > Translations.
 *
 * @since 4.1.0
 */
class Strope_String_Discovery_Admin {
	/**
	 * Name of the notice explaining the empty fields for untranslated strings.
	 *
	 * @var string
	 */
	const NOTICE = 'strings-empty-translations';

	/**
	 * Action name of the save form.
	 *
	 * @var string
	 */
	const SAVE_ACTION = 'discovered-strings-save';

	/**
	 * Current page number of the discovered strings table.
	 *
	 * @var int
	 */
	protected static $paged = 1;

	/**
	 * Returns the current page number of the discovered strings table.
	 *
	 * @since 4.1.0
	 *
	 * @return int
	 */
	public static function get_pagenum(): int {
		return self::$paged;
	}

	/**
	 * Adds the hooks.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'load-languages_page_mlang_strings', array( $this, 'load_page' ) );
		add_filter( 'strope_can_display_notice', array( $this, 'can_display_notice' ), 10, 2 );
		add_action( 'strope_strings_tab_discovered', array( $this, 'display' ) );

	}

	/**
	 * Whether the discovered strings view is displayed.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	public static function is_discovered_view(): bool {
		return isset( $_GET['view'] ) && 'discovered' === $_GET['view']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Setups the Translations page.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function load_page() {
		Strope_Admin_Notices::add_notice(
			self::NOTICE,
			'<p>' . esc_html__( 'Untranslated strings are now displayed with an empty field, the original text being shown as a placeholder. Leave a field empty to keep a string untranslated: the original text is then displayed on your website.', 'strope-translate' ) . '</p>'
		);

		if ( ! self::is_discovered_view() ) {
			return;
		}

		/*
		 * The page number is removed from the request to not interfere with the pagination of the registered strings table,
		 * which is prepared whatever the view, see `Strope_Settings::languages_page()`.
		 */
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_REQUEST['paged'] ) ) {
			self::$paged = max( 1, (int) $_REQUEST['paged'] );
			unset( $_GET['paged'], $_POST['paged'], $_REQUEST['paged'] );
		}

		// Handle the form submission before the page is displayed.
		if ( isset( $_REQUEST['strope_action'] ) && self::SAVE_ACTION === $_REQUEST['strope_action'] ) {
			$this->save();
		}
		// phpcs:enable

		Strope_String_Discovery_Store::maybe_install();

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style( 'strope-string-discovery', plugins_url( '/css/build/string-discovery' . $suffix . '.css', STROPE_TRANSLATE_FILE ), array(), STROPE_TRANSLATE_VERSION );
		wp_enqueue_script( 'strope-string-discovery', plugins_url( '/js/build/string-discovery' . $suffix . '.js', STROPE_TRANSLATE_FILE ), array( 'wp-api-fetch', 'wp-i18n' ), STROPE_TRANSLATE_VERSION, true );
		wp_set_script_translations( 'strope-string-discovery', 'strope-translate', STROPE_TRANSLATE_DIR . '/languages' );
		wp_localize_script(
			'strope-string-discovery',
			'stropeStringDiscovery',
			array(
				'path' => '/strope/v1/string-discovery/scan',
			)
		);
	}

	/**
	 * Restricts our notice to the Translations page.
	 *
	 * @since 4.1.0
	 *
	 * @param bool   $display Whether the notice should be displayed.
	 * @param string $notice  Notice name.
	 * @return bool
	 */
	public function can_display_notice( $display, $notice ) {
		if ( self::NOTICE !== $notice ) {
			return $display;
		}

		$screen = get_current_screen();
		return $display && ! empty( $screen ) && false !== strpos( $screen->id, '_page_mlang_strings' );
	}

	/**
	 * Returns the current filters from the query string.
	 *
	 * @since 4.1.0
	 *
	 * @return array
	 */
	public static function get_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification
		$filters = array(
			'component' => isset( $_GET['component'] ) ? sanitize_text_field( wp_unslash( $_GET['component'] ) ) : '',
			'domain'    => isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '',
			'status'    => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
			'search'    => isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '',
		);
		// phpcs:enable

		if ( ! in_array( $filters['status'], array( '', 'translated', 'untranslated' ), true ) ) {
			$filters['status'] = '';
		}

		return $filters;
	}

	/**
	 * Returns the languages displayed in the table: the languages the user can translate,
	 * restricted to the admin language filter if active.
	 *
	 * @since 4.1.0
	 *
	 * @return Strope_Language[]
	 */
	public static function get_displayed_languages(): array {
		$languages = Strope_String_Discovery::get_user_languages();
		$filter    = get_user_meta( get_current_user_id(), 'strope_filter_content', true );

		if ( $filter ) {
			$filtered = array_values( wp_list_filter( $languages, array( 'slug' => $filter ) ) );
			if ( $filtered ) {
				return $filtered;
			}
		}

		return $languages;
	}

	/**
	 * Displays the view.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function display() {
		if ( ! current_user_can( 'manage_translations' ) ) {
			echo '<p>' . esc_html__( 'Sorry, you are not allowed to translate strings.', 'strope-translate' ) . '</p>';
			return;
		}

		$filters    = self::get_filters();
		$languages  = self::get_displayed_languages();
		$components = Strope_String_Scanner::get_components();
		$counts     = Strope_String_Discovery_Store::count_by_component();
		$job        = Strope_String_Scanner::get_job();
		$table      = new Strope_Table_Discovered_Strings( $languages, $filters );
		$table->prepare_items();

		include __DIR__ . '/view-discovered-strings.php';
	}

	/**
	 * Saves the translations submitted from the table.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function save() {
		check_admin_referer( self::SAVE_ACTION, '_wpnonce_strope_sd' );

		if ( ! current_user_can( 'manage_translations' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to translate strings.', 'strope-translate' ), 403 );
		}

		$languages = array();
		foreach ( Strope_String_Discovery::get_user_languages() as $language ) {
			$languages[ $language->slug ] = $language;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Strope_String_Discovery::prepare_translation().
		$posted = isset( $_POST['strope_sd'] ) && is_array( $_POST['strope_sd'] ) ? wp_unslash( $_POST['strope_sd'] ) : array();
		$orig   = isset( $_POST['strope_sd_orig'] ) && is_array( $_POST['strope_sd_orig'] ) ? wp_unslash( $_POST['strope_sd_orig'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$saved  = 0;
		$errors = array();
		$dirty  = array();

		foreach ( $posted as $string_id => $by_language ) {
			$string_id = (int) $string_id;
			if ( $string_id <= 0 || ! is_array( $by_language ) ) {
				continue;
			}

			foreach ( $by_language as $slug => $forms ) {
				$slug = (string) $slug;
				if ( ! isset( $languages[ $slug ] ) || ! is_array( $forms ) ) {
					continue;
				}

				$string = Strope_String_Discovery_Store::get_string( $string_id );
				if ( ! $string ) {
					continue;
				}

				// Sanitized and secured (see `Strope_String_Discovery::secure_translation()`).
				$forms = Strope_String_Discovery::prepare_translation( $forms, $string );
				if ( is_wp_error( $forms ) ) {
					$errors[ $forms->get_error_code() ][] = $forms->get_error_message();
					continue;
				}

				// Save only the modified translations.
				if ( isset( $orig[ $string_id ][ $slug ] ) && md5( (string) wp_json_encode( $forms ) ) === $orig[ $string_id ][ $slug ] ) {
					continue;
				}

				if ( Strope_String_Discovery_Store::set_translation( $string_id, $slug, $forms ) ) {
					++$saved;
					$dirty[ $slug ] = $languages[ $slug ];
				} else {
					$errors['strope_db_error'][] = __( 'Some translations could not be saved.', 'strope-translate' );
				}
			}
		}

		foreach ( $dirty as $language ) {
			Strope_String_Discovery_Store::rebuild_cache( $language );
		}

		foreach ( $errors as $code => $messages ) {
			$error = new WP_Error();
			foreach ( array_unique( $messages ) as $message ) {
				$error->add( $code, $message, 'error' );
			}
			strope_add_notice( $error );
		}

		strope_add_notice(
			new WP_Error(
				'strope_sd_saved',
				/* translators: %d is a number of translations */
				sprintf( _n( '%d translation updated.', '%d translations updated.', $saved, 'strope-translate' ), $saved ),
				'success'
			)
		);

		// The filters are kept as the redirection is done to the referer.
		$args = array( 'paged' => self::$paged );

		Strope_Settings::redirect( $args );
	}
}
