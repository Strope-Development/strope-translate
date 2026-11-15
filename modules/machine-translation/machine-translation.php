<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Machine translation of the content with a translation engine running in the browsers of the editors
 * (Bergamot, the engine of Firefox Translations). The server does no translation work and nothing is sent
 * to a translation service: the translated posts are saved as drafts flagged as needing a review.
 *
 * @since 4.2.0
 */
class Strope_Machine_Translation {
	/**
	 * Key of the settings in the plugin options.
	 */
	const OPTIONS_KEY = 'machine_translation_settings';

	/**
	 * User meta: whether the browser of the user translates in the background on all admin pages.
	 */
	const USER_BACKGROUND = 'strope_mt_background';

	/**
	 * Cron hook of the cleanup.
	 */
	const CLEANUP_HOOK = 'strope_mt_cleanup';

	/**
	 * Option of the glossary.
	 */
	const GLOSSARY_OPTION = 'strope_mt_glossary';

	/**
	 * Maximum number of glossary entries.
	 */
	const GLOSSARY_MAX = 2000;

	/**
	 * Returns the job queue of the machine translation (WASM worker kit, in lib/wasm-worker).
	 *
	 * Its REST routes are under `strope-mt-worker/v1`, its WP-CLI commands under `wp strope-mt-worker`.
	 *
	 * @since 4.2.0
	 *
	 * @return Strope_Translate\MT\Worker\Kit
	 */
	public static function kit() {
		static $kit = null;

		if ( null === $kit ) {
			$kit = new Strope_Translate\MT\Worker\Kit(
				array(
					'id'                => 'strope-mt',
					'capability'        => Strope_Capabilities::MANAGE_TRANSLATIONS,
					'manage_capability' => Strope_Capabilities::MANAGE_LANGUAGES,
				)
			);
			$kit->register( new Strope_MT_Task() );
		}

		return $kit;
	}

	/**
	 * Returns the settings.
	 *
	 * @since 4.2.0
	 *
	 * @return array {
	 *     @type string $post_status Status of the new translations, `draft` or `publish`.
	 *     @type int    $background  Whether the editors can translate in the background on all admin pages.
	 *     @type string $keep        Words never translated, one per line.
	 * }
	 */
	public static function get_options(): array {
		$options = function_exists( 'Strope' ) && Strope() && isset( Strope()->options[ self::OPTIONS_KEY ] ) ? (array) Strope()->options[ self::OPTIONS_KEY ] : array();

		$options = array_merge(
			array(
				'post_status' => 'draft',
				'background'  => 1,
				'keep'        => '',
			),
			$options
		);

		$options['post_status'] = 'publish' === $options['post_status'] ? 'publish' : 'draft';
		$options['background']  = empty( $options['background'] ) ? 0 : 1;
		$options['keep']        = (string) $options['keep'];

		return $options;
	}

	/**
	 * Returns the words and phrases never translated: the settings, plus the site name and the language names.
	 *
	 * @since 4.2.0
	 *
	 * @return string[]
	 */
	public static function get_keep_words(): array {
		$options = self::get_options();
		$words   = preg_split( '/\r\n|\r|\n/', $options['keep'] );

		/**
		 * Filters the words and phrases which are never machine translated, e.g. brand and product names.
		 *
		 * @since 4.2.0
		 *
		 * @param string[] $words Words, from the settings.
		 */
		$words = (array) apply_filters( 'strope_mt_keep_words', (array) $words );

		return array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $words ) ), 'strlen' ) ) );
	}

	/**
	 * Returns the glossary entries: each entry is one term, in the languages it has a translation for.
	 *
	 * @since 4.3.0
	 *
	 * @return array[] Entries: terms keyed by language slug.
	 */
	public static function get_glossary_entries(): array {
		$entries = get_option( self::GLOSSARY_OPTION, array() );

		return is_array( $entries ) ? array_values( array_filter( $entries, 'is_array' ) ) : array();
	}

	/**
	 * Saves the glossary entries. Terms are cleaned, entries with less than two terms are dropped.
	 *
	 * @since 4.3.0
	 *
	 * @param array[] $rows Entries: terms keyed by language slug.
	 * @return int Number of entries saved.
	 */
	public static function save_glossary_entries( array $rows ): int {
		$slugs   = Strope()->model->get_languages_list( array( 'fields' => 'slug' ) );
		$entries = array();
		$seen    = array();

		foreach ( $rows as $row ) {
			$entry = array();
			foreach ( $slugs as $slug ) {
				$term = isset( $row[ $slug ] ) ? trim( sanitize_text_field( (string) $row[ $slug ] ) ) : '';
				if ( '' !== $term ) {
					$entry[ $slug ] = $term;
				}
			}

			$key = mb_strtolower( (string) wp_json_encode( $entry ) );
			if ( count( $entry ) < 2 || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$entries[]    = $entry;
		}

		$entries = array_slice( $entries, 0, self::GLOSSARY_MAX );
		update_option( self::GLOSSARY_OPTION, $entries, false );

		return count( $entries );
	}

	/**
	 * Returns the glossary of a language pair: the translations of the terms, keyed by the source term.
	 *
	 * @since 4.3.0
	 *
	 * @param Strope_Language|string $source Source language or slug.
	 * @param Strope_Language|string $target Target language or slug.
	 * @return string[]
	 */
	public static function get_glossary( $source, $target ): array {
		$source   = $source instanceof Strope_Language ? $source->slug : (string) $source;
		$target   = $target instanceof Strope_Language ? $target->slug : (string) $target;
		$glossary = array();

		foreach ( self::get_glossary_entries() as $entry ) {
			if ( ! empty( $entry[ $source ] ) && ! empty( $entry[ $target ] ) ) {
				$glossary[ (string) $entry[ $source ] ] = (string) $entry[ $target ];
			}
		}

		/**
		 * Filters the glossary of a language pair: terms always translated the same way.
		 *
		 * @since 4.3.0
		 *
		 * @param string[] $glossary Translations keyed by source term.
		 * @param string   $source   Source language slug.
		 * @param string   $target   Target language slug.
		 */
		return (array) apply_filters( 'strope_mt_glossary', $glossary, $source, $target );
	}

	/**
	 * Returns the arguments of the segmenter for a language pair: the kept words and the glossary.
	 *
	 * @since 4.3.0
	 *
	 * @param Strope_Language|string $source Source language or slug.
	 * @param Strope_Language|string $target Target language or slug.
	 * @return array
	 */
	public static function get_split_args( $source, $target ): array {
		return array(
			'keep'     => self::get_keep_words(),
			'glossary' => self::get_glossary( $source, $target ),
		);
	}

	/**
	 * Returns what the scripts need to translate in the browser: the REST API of the plugin, the workers of
	 * the job queue, and the status strings.
	 *
	 * @since 4.2.0
	 *
	 * @return array
	 */
	public static function get_client_data(): array {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		return array(
			'api'      => esc_url_raw( rest_url( Strope_MT_REST::REST_NAMESPACE . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'nonceUrl' => admin_url( 'admin-ajax.php?action=rest-nonce' ),
			'maxItems' => Strope_MT_Strings::MAX_ITEMS,
			// The workers of the job queue (the worker bundle loads the engine glue with importScripts()).
			'kit'      => self::kit()->client_config() + array(
				'workerUrl'     => add_query_arg( 'ver', STROPE_TRANSLATE_VERSION, plugins_url( '/js/build/mt-worker' . $suffix . '.js', STROPE_TRANSLATE_FILE ) ),
				'workerType'    => 'classic',
				'workerOptions' => array(
					'manifestUrl' => esc_url_raw( rest_url( Strope_MT_REST::REST_NAMESPACE . '/mt/manifest' ) ),
					'glueUrl'     => plugins_url( '/modules/machine-translation/vendor/bergamot-translator/bergamot-translator.js', STROPE_TRANSLATE_FILE ),
				),
			),
			'i18n'     => array(
				'idle'        => __( 'Waiting for work', 'strope-translate' ),
				'stopped'     => __( 'Stopped', 'strope-translate' ),
				'starting'    => __( 'Starting the translation engine…', 'strope-translate' ),
				/* translators: %s: language pair, e.g. nl-en. */
				'loading'     => __( 'Loading the %s language model…', 'strope-translate' ),
				/* translators: %s: download progress, e.g. 40%. */
				'downloading' => __( 'Downloading the language model: %s', 'strope-translate' ),
				/* translators: %s: number of words per second. */
				'translating' => __( 'Translating (%s words per second)', 'strope-translate' ),
				'otherTab'    => __( 'Translating in another tab of this browser', 'strope-translate' ),
				/* translators: %s: error message. */
				'error'       => __( 'Error: %s', 'strope-translate' ),
				'unsupported' => __( 'This browser cannot run the translation engine.', 'strope-translate' ),
				/* translators: %s: translation speed, e.g. 120 w/s (words per second). */
				'adminBar'    => __( 'Translating %s', 'strope-translate' ),
			),
		);
	}

	/**
	 * Adds the machine translation to the front-end translation editor, when the user can translate and the
	 * engine and at least one language model are installed.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public static function enqueue_frontend_editor() {
		if ( ! current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			return;
		}

		$installed = Strope_MT_Engine_Files::get_installed();
		if ( empty( $installed['wasm'] ) || empty( $installed['models'] ) ) {
			return;
		}

		$model     = Strope()->model;
		$default   = $model->get_default_language();
		$languages = array();
		$allowed   = array();
		$english   = '';

		foreach ( $model->get_languages_list() as $language ) {
			$code                         = Strope_MT_Engine_Files::get_code( $language );
			$languages[ $language->slug ] = array(
				'name' => $language->name,
				'code' => $code,
			);
			if ( Strope_MT_Queue::user_can_translate_language( $language ) ) {
				$allowed[] = $language->slug;
			}
			if ( 'en' === $code && '' === $english ) {
				$english = $language->slug;
			}
		}

		$data = self::get_client_data();

		$data['languages']     = $languages;
		$data['directions']    = array_keys( $installed['models'] );
		$data['allowed']       = $allowed;
		$data['defaultSource'] = $default ? $default->slug : '';
		$data['englishSource'] = '' !== $english ? $english : ( $default ? $default->slug : '' ); // Theme and plugin strings.
		$data['i18n']         += array(
			/* translators: %s: language name */
			'translateFrom'   => __( 'Machine translate from %s', 'strope-translate' ),
			'working'         => __( 'Translating…', 'strope-translate' ),
			'filled'          => __( 'Machine translation: check it, then save.', 'strope-translate' ),
			'noResult'        => __( 'This text could not be machine translated.', 'strope-translate' ),
			'translatePage'   => __( 'Machine translate this page', 'strope-translate' ),
			'queued'          => __( 'Added to the translation queue…', 'strope-translate' ),
			/* translators: %d: percentage */
			'progress'        => __( 'Translating the page: %d%%', 'strope-translate' ),
			'pageDone'        => __( 'Translated: a draft is waiting for your review.', 'strope-translate' ),
			/* translators: %s: error message */
			'pageFailed'      => __( 'The page could not be translated: %s', 'strope-translate' ),
			'editDraft'       => __( 'Edit the draft', 'strope-translate' ),
			'translateString' => __( 'Machine translate', 'strope-translate' ),
			'translateEmpty'  => __( 'Machine translate the empty strings', 'strope-translate' ),
			/* translators: %d: number of strings */
			'stringsFilled'   => __( '%d strings filled: check them, then save each one.', 'strope-translate' ),
			'unchanged'       => __( 'The machine translation is the same as the original text (already in that language?).', 'strope-translate' ),
		);

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script( 'strope-mt-frontend', plugins_url( '/js/build/mt-frontend' . $suffix . '.js', STROPE_TRANSLATE_FILE ), array( 'strope-frontend-editor' ), STROPE_TRANSLATE_VERSION, true );
		wp_add_inline_script( 'strope-mt-frontend', 'var stropeMtFrontendData = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Tells whether the browser of the current user translates in the background.
	 *
	 * @since 4.2.0
	 *
	 * @return bool
	 */
	public static function user_wants_background(): bool {
		if ( ! self::get_options()['background'] || ! current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			return false;
		}

		$value = get_user_meta( get_current_user_id(), self::USER_BACKGROUND, true );

		return '' === $value ? true : (bool) $value; // On by default.
	}

	/**
	 * Initializes the module.
	 *
	 * @since 4.2.0
	 *
	 * @param Strope_Base $strope_translate Strope Translate object.
	 * @return void
	 */
	public static function init( $strope_translate ) {
		self::kit()->boot();

		add_action( 'rest_api_init', array( 'Strope_MT_REST', 'register_routes' ) );
		add_action( 'strope_frontend_editor_enqueue_scripts', array( self::class, 'enqueue_frontend_editor' ) );

		add_action( self::CLEANUP_HOOK, array( 'Strope_MT_Queue', 'cleanup' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( 'Strope_MT_Store', 'maybe_install' ) );
		}

		if ( $strope_translate instanceof Strope_Admin_Base ) {
			new Strope_MT_Admin( $strope_translate );
		}
	}
}
