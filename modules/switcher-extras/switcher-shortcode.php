<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * The `[strope_language_switcher]` shortcode.
 *
 * Usage example: `[strope_language_switcher dropdown="1" show_flags="1" hide_current="1"]`.
 *
 * @since 4.1.0
 */
class Strope_Switcher_Shortcode {
	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const TAG = 'strope_language_switcher';

	/**
	 * Boolean attributes and their default values.
	 *
	 * @var int[]
	 */
	const BOOLEAN_ATTRIBUTES = array(
		'dropdown'               => 0,
		'show_flags'             => 0,
		'show_names'             => 1,
		'hide_current'           => 0,
		'hide_if_no_translation' => 0,
		'force_home'             => 0,
	);

	/**
	 * Adds hooks.
	 *
	 * @since 4.1.0
	 *
	 * @return self
	 */
	public function init() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		return $this;
	}

	/**
	 * Sanitizes the shortcode attributes.
	 *
	 * @since 4.1.0
	 *
	 * @param array|string $atts Raw shortcode attributes.
	 * @return array Arguments for `strope_the_languages()`.
	 */
	public function sanitize_attributes( $atts ) {
		$defaults = array_merge( self::BOOLEAN_ATTRIBUTES, array( 'display_names_as' => 'name' ) );
		$atts     = shortcode_atts( $defaults, (array) $atts, self::TAG );
		$args     = array();

		foreach ( array_keys( self::BOOLEAN_ATTRIBUTES ) as $key ) {
			$value        = is_string( $atts[ $key ] ) ? strtolower( trim( $atts[ $key ] ) ) : $atts[ $key ];
			$args[ $key ] = in_array( $value, array( 0, '0', 'false', 'no', 'off', '' ), true ) || empty( $value ) ? 0 : 1;
		}

		$args['display_names_as'] = 'slug' === $atts['display_names_as'] ? 'slug' : 'name';

		// At least the names or the flags must be displayed.
		if ( ! $args['show_names'] && ! $args['show_flags'] ) {
			$args['show_names'] = 1;
		}

		return $args;
	}

	/**
	 * Renders the shortcode.
	 *
	 * @since 4.1.0
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML markup.
	 */
	public function render( $atts ) {
		static $instance = 0;
		++$instance;

		$args = $this->sanitize_attributes( $atts );

		// A unique id is required for the dropdown `<select>`.
		$args['dropdown'] = $args['dropdown'] ? 'shortcode_' . $instance : 0;
		$args['echo']     = 0;
		$args['raw']      = 0;

		$output = strope_the_languages( $args );

		if ( empty( $output ) || ! is_string( $output ) ) {
			return '';
		}

		$aria_label = __( 'Choose a language', 'strope-translate' );

		if ( $args['dropdown'] ) {
			$html = sprintf(
				'<div class="strope-language-switcher strope-language-switcher--dropdown"><label class="screen-reader-text" for="%1$s">%2$s</label>%3$s</div>',
				esc_attr( 'lang_choice_' . $args['dropdown'] ),
				esc_html( $aria_label ),
				$output
			);
		} else {
			$html = sprintf(
				'<nav class="strope-language-switcher" aria-label="%1$s"><ul>%2$s</ul></nav>',
				esc_attr( $aria_label ),
				$output
			);
		}

		/**
		 * Filters the HTML markup of the `[strope_language_switcher]` shortcode.
		 *
		 * @since 4.1.0
		 *
		 * @param string $html HTML markup.
		 * @param array  $args Arguments passed to `strope_the_languages()`.
		 */
		return (string) apply_filters( 'strope_language_switcher_shortcode_html', $html, $args );
	}
}
