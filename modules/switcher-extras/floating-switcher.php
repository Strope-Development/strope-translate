<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Displays a floating language switcher in the footer of the front end.
 *
 * @since 4.1.0
 */
class Strope_Floating_Switcher {
	/**
	 * Default settings.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'position'               => 'bottom-right',
		'display'                => 'both',
		'dropdown'               => 1,
		'hide_current'           => 0,
		'hide_if_no_translation' => 0,
	);

	/**
	 * @var Strope_Frontend
	 */
	protected $strope_translate;

	/**
	 * Language switcher elements to display, computed when enqueuing the assets.
	 *
	 * @var array[]|null
	 */
	protected $elements;

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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
		return $this;
	}

	/**
	 * Returns the list of available positions.
	 *
	 * @since 4.1.0
	 *
	 * @return string[] Labels keyed by position.
	 */
	public static function get_positions() {
		return array(
			'bottom-right' => __( 'Bottom right', 'strope-translate' ),
			'bottom-left'  => __( 'Bottom left', 'strope-translate' ),
			'top-right'    => __( 'Top right', 'strope-translate' ),
			'top-left'     => __( 'Top left', 'strope-translate' ),
		);
	}

	/**
	 * Returns the list of available display modes.
	 *
	 * @since 4.1.0
	 *
	 * @return string[] Labels keyed by display mode.
	 */
	public static function get_display_modes() {
		return array(
			'both'  => __( 'Flags and language names', 'strope-translate' ),
			'names' => __( 'Language names', 'strope-translate' ),
			'flags' => __( 'Flags', 'strope-translate' ),
		);
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
		$settings = isset( $options['floating_switcher_settings'] ) && is_array( $options['floating_switcher_settings'] ) ? $options['floating_switcher_settings'] : array();
		return self::sanitize_settings( $settings );
	}

	/**
	 * Sanitizes the settings.
	 *
	 * @since 4.1.0
	 *
	 * @param array $settings Unsanitized settings.
	 * @return array
	 */
	public static function sanitize_settings( $settings ) {
		$settings  = wp_parse_args( (array) $settings, self::DEFAULTS );
		$sanitized = array(
			'position' => is_string( $settings['position'] ) && array_key_exists( $settings['position'], self::get_positions() ) ? $settings['position'] : self::DEFAULTS['position'],
			'display'  => is_string( $settings['display'] ) && array_key_exists( $settings['display'], self::get_display_modes() ) ? $settings['display'] : self::DEFAULTS['display'],
		);

		foreach ( array( 'dropdown', 'hide_current', 'hide_if_no_translation' ) as $key ) {
			$sanitized[ $key ] = empty( $settings[ $key ] ) ? 0 : 1;
		}

		return $sanitized;
	}

	/**
	 * Computes the language switcher elements and enqueues the assets if the switcher will be displayed.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		$this->elements = $this->get_elements();

		if ( empty( $this->elements ) ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'strope-floating-language-switcher',
			plugins_url( '/css/build/floating-language-switcher' . $suffix . '.css', STROPE_TRANSLATE_FILE ),
			array(),
			STROPE_TRANSLATE_VERSION
		);

		if ( self::get_settings( $this->strope_translate->options )['dropdown'] ) {
			wp_enqueue_script(
				'strope-floating-language-switcher',
				plugins_url( '/js/build/floating-language-switcher' . $suffix . '.js', STROPE_TRANSLATE_FILE ),
				array(),
				STROPE_TRANSLATE_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	/**
	 * Returns the language switcher elements to display.
	 *
	 * @since 4.1.0
	 *
	 * @return array[] Switcher elements, empty if there is not more than one language to display.
	 */
	protected function get_elements() {
		if ( empty( $this->strope_translate->curlang ) || empty( $this->strope_translate->links ) ) {
			return array();
		}

		$settings = self::get_settings( $this->strope_translate->options );

		$elements = strope_the_languages(
			array(
				'raw'                    => 1,
				'echo'                   => 0,
				'show_flags'             => 'names' === $settings['display'] ? 0 : 1,
				'show_names'             => 1, // Flags with an empty alt text, the name is always output (visually hidden for flags only).
				'hide_current'           => 0, // Handled below, as the current language is used by the dropdown button.
				'hide_if_no_translation' => $settings['hide_if_no_translation'],
			)
		);

		if ( ! is_array( $elements ) || count( $elements ) < 2 ) {
			return array();
		}

		return $elements;
	}

	/**
	 * Returns the HTML content of a link or button: flag and/or name.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $element Switcher element.
	 * @param string $display Display mode.
	 * @return string
	 */
	protected function get_item_label( $element, $display ) {
		$name = '<span class="strope-floating-switcher__name">' . esc_html( $element['name'] ) . '</span>';

		if ( 'names' === $display || empty( $element['flag'] ) ) {
			return $name;
		}

		// The flag is an `<img>` tag built by Strope Translate, with an empty alternative text.
		$flag = '<span class="strope-floating-switcher__flag">' . $element['flag'] . '</span>';

		if ( 'flags' === $display ) {
			// The language name is still available to assistive technologies.
			return $flag . '<span class="strope-floating-switcher__name strope-floating-switcher__sr">' . esc_html( $element['name'] ) . '</span>';
		}

		return $flag . $name;
	}

	/**
	 * Renders the floating language switcher.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function render() {
		if ( empty( $this->elements ) ) {
			return;
		}

		$settings = self::get_settings( $this->strope_translate->options );
		$list_id  = 'strope-floating-switcher-list';
		$current  = null;
		$items    = '';

		foreach ( $this->elements as $element ) {
			if ( $element['current_lang'] ) {
				$current = $element;
				if ( $settings['hide_current'] ) {
					continue;
				}
			}

			$items .= sprintf(
				'<li class="%1$s"><a href="%2$s" hreflang="%3$s" lang="%3$s"%4$s>%5$s</a></li>',
				esc_attr( implode( ' ', array_merge( array( 'strope-floating-switcher__item' ), (array) $element['classes'] ) ) ),
				esc_url( $element['url'] ),
				esc_attr( $element['locale'] ),
				$element['current_lang'] ? ' aria-current="true"' : '',
				$this->get_item_label( $element, $settings['display'] )
			);
		}

		if ( '' === $items ) {
			return;
		}

		$classes = array(
			'strope-floating-switcher',
			'strope-floating-switcher--' . $settings['position'],
			'strope-floating-switcher--' . ( $settings['dropdown'] ? 'dropdown' : 'list' ),
			'strope-floating-switcher--' . $settings['display'],
		);

		$html = sprintf(
			'<nav class="%1$s" aria-label="%2$s" data-strope-floating-switcher>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr__( 'Choose a language', 'strope-translate' )
		);

		if ( $settings['dropdown'] ) {
			if ( empty( $current ) ) {
				$current = reset( $this->elements );
			}

			// The button is hidden until the script runs. Without JavaScript, the list stays visible.
			$html .= sprintf(
				'<button type="button" class="strope-floating-switcher__toggle" aria-expanded="false" aria-controls="%1$s" lang="%2$s" hidden>%3$s<span class="strope-floating-switcher__icon" aria-hidden="true"></span></button>',
				esc_attr( $list_id ),
				esc_attr( $current['locale'] ),
				$this->get_item_label( $current, $settings['display'] )
			);
		}

		$html .= sprintf( '<ul id="%1$s" class="strope-floating-switcher__list">%2$s</ul></nav>', esc_attr( $list_id ), $items );

		/**
		 * Filters the HTML markup of the floating language switcher.
		 *
		 * @since 4.1.0
		 *
		 * @param string  $html     HTML markup.
		 * @param array[] $elements Language switcher elements, see `strope_the_languages()` with the `raw` argument.
		 * @param array   $settings Floating language switcher settings.
		 */
		echo apply_filters( 'strope_floating_switcher_html', $html, $this->elements, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
