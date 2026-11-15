<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Displays a notice listing the other languages in which the current post is available.
 *
 * @since 4.1.0
 */
class Strope_Also_Available_Notice {
	/**
	 * Default settings.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'post_types' => array( 'post', 'page' ),
		'position'   => 'before',
		'format'     => 'names',
		'text'       => '',
	);

	/**
	 * @var Strope_Frontend
	 */
	protected $strope_translate;

	/**
	 * Ids of the posts for which the notice has already been displayed.
	 *
	 * @var int[]
	 */
	protected $done = array();

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Frontend $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->strope_translate = &$strope_translate;
	}

	/**
	 * Adds hooks.
	 *
	 * @since 4.1.0
	 *
	 * @return self
	 */
	public function init() {
		// After `do_blocks()`, `wpautop()` and `do_shortcode()`.
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
		return $this;
	}

	/**
	 * Returns the default text of the notice.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	public static function get_default_text() {
		/* translators: %s is a list of languages. */
		return __( 'This content is also available in: %s', 'strope-translate' );
	}

	/**
	 * Returns the post types which can display the notice.
	 *
	 * @since 4.1.0
	 *
	 * @return string[] Post type labels keyed by post type names.
	 */
	public static function get_available_post_types() {
		$post_types = array();

		foreach ( Strope()->model->get_translated_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object && is_post_type_viewable( $object ) ) {
				$post_types[ $post_type ] = $object->labels->name;
			}
		}

		return $post_types;
	}

	/**
	 * Returns the validated settings.
	 *
	 * @since 4.1.0
	 *
	 * @param array $options Strope Translate options.
	 * @return array
	 */
	public static function get_settings( $options ) {
		$settings = isset( $options['also_available_settings'] ) && is_array( $options['also_available_settings'] ) ? $options['also_available_settings'] : array();
		return self::sanitize_settings( $settings );
	}

	/**
	 * Sanitizes the settings.
	 * The post types aren't checked against the registered post types, as it may be too early.
	 *
	 * @since 4.1.0
	 *
	 * @param array $settings Unsanitized settings.
	 * @return array
	 */
	public static function sanitize_settings( $settings ) {
		$settings = wp_parse_args( (array) $settings, self::DEFAULTS );

		return array(
			'post_types' => array_values( array_filter( array_map( 'sanitize_key', (array) $settings['post_types'] ) ) ),
			'position'   => 'after' === $settings['position'] ? 'after' : 'before',
			'format'     => 'flags' === $settings['format'] ? 'flags' : 'names',
			'text'       => is_string( $settings['text'] ) ? sanitize_text_field( $settings['text'] ) : '',
		);
	}

	/**
	 * Tells if the notice should be added to the content being filtered.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post|null $post     Current post.
	 * @param array        $settings Notice settings.
	 * @return bool
	 */
	protected function is_eligible( $post, $settings ) {
		if ( ! $post instanceof WP_Post || empty( $this->strope_translate->curlang ) ) {
			return false;
		}

		// Only for the main singular post, displayed in the loop, and not when building an excerpt or a feed.
		if ( ! is_singular() || ! is_main_query() || ! in_the_loop() || is_feed() || doing_filter( 'get_the_excerpt' ) ) {
			return false;
		}

		if ( get_queried_object_id() !== $post->ID || in_array( $post->ID, $this->done, true ) ) {
			return false;
		}

		return in_array( $post->post_type, $settings['post_types'], true ) && $this->strope_translate->model->is_translated_post_type( $post->post_type );
	}

	/**
	 * Returns the other languages in which a post is published.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post Post.
	 * @return array[] List of arrays with the language object and the url of the translation.
	 */
	public function get_translations( WP_Post $post ) {
		$translations = array();
		$current      = $this->strope_translate->curlang;
		$post_lang    = $this->strope_translate->model->post->get_language( $post->ID );

		// Inactive languages are already removed for visitors, see `Strope_Active_Languages`.
		foreach ( $this->strope_translate->model->get_languages_list() as $language ) {
			if ( empty( $language->active ) || $language->slug === $current->slug || ( $post_lang && $language->slug === $post_lang->slug ) ) {
				continue;
			}

			$tr_id = $this->strope_translate->model->post->get( $post->ID, $language );

			if ( ! $tr_id || 'publish' !== get_post_status( $tr_id ) ) {
				continue;
			}

			$url = get_permalink( $tr_id );

			if ( empty( $url ) ) {
				continue;
			}

			$translations[] = array(
				'language' => $language,
				'post_id'  => $tr_id,
				'url'      => $url,
			);
		}

		/**
		 * Filters the translations listed in the "also available in" notice.
		 *
		 * @since 4.1.0
		 *
		 * @param array[] $translations List of arrays with keys 'language' (Strope_Language), 'post_id' and 'url'.
		 * @param WP_Post $post         The current post.
		 */
		return (array) apply_filters( 'strope_also_available_translations', $translations, $post );
	}

	/**
	 * Returns the notice markup for a post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $settings Notice settings.
	 * @return string Empty string if the post isn't available in other languages.
	 */
	public function get_notice( WP_Post $post, $settings ) {
		$translations = $this->get_translations( $post );

		if ( empty( $translations ) ) {
			return '';
		}

		$links = array();
		foreach ( $translations as $translation ) {
			if ( empty( $translation['language'] ) || ! $translation['language'] instanceof Strope_Language || empty( $translation['url'] ) ) {
				continue;
			}

			$language = $translation['language'];
			$label    = esc_html( $language->name );

			if ( 'flags' === $settings['format'] ) {
				$flag = $language->get_display_flag( 'no-alt' );
				if ( $flag ) {
					$label = '<span class="strope-also-available__flag">' . $flag . '</span> ' . $label;
				}
			}

			$links[] = sprintf(
				'<a href="%1$s" hreflang="%2$s" lang="%2$s">%3$s</a>',
				esc_url( $translation['url'] ),
				esc_attr( $language->get_locale( 'display' ) ),
				$label
			);
		}

		if ( empty( $links ) ) {
			return '';
		}

		$text = '' === $settings['text'] ? self::get_default_text() : strope__( $settings['text'] );
		$list = wp_sprintf( '%l', $links );

		if ( false === strpos( $text, '%s' ) ) {
			$message = esc_html( $text ) . ' ' . $list;
		} else {
			$parts   = explode( '%s', $text, 2 );
			$message = esc_html( $parts[0] ) . $list . esc_html( str_replace( '%s', '', $parts[1] ) );
		}

		$html = sprintf(
			'<div class="strope-also-available strope-also-available--%1$s"><p>%2$s</p></div>',
			esc_attr( $settings['position'] ),
			$message
		);

		/**
		 * Filters the markup of the "also available in" notice.
		 *
		 * @since 4.1.0
		 *
		 * @param string   $html         HTML markup.
		 * @param string[] $links        HTML links to the translations.
		 * @param WP_Post  $post         The current post.
		 * @param array    $settings     The notice settings.
		 * @param array[]  $translations The translations, see the 'strope_also_available_translations' filter.
		 */
		return (string) apply_filters( 'strope_also_available_notice_html', $html, $links, $post, $settings, $translations );
	}

	/**
	 * Adds the notice to the content of the main singular post.
	 *
	 * @since 4.1.0
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		$settings = self::get_settings( $this->strope_translate->options );
		$post     = get_post();

		if ( ! $this->is_eligible( $post, $settings ) ) {
			return $content;
		}

		$this->done[] = $post->ID;

		$notice = $this->get_notice( $post, $settings );

		if ( '' === $notice ) {
			return $content;
		}

		return 'after' === $settings['position'] ? $content . $notice : $notice . $content;
	}
}
