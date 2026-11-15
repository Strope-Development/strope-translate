<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects the strings displayed on a page in front-end edit mode and saves their translations.
 *
 * - Registered strings (`strope_register_string()`), translated with `strope__()` through the `strope_string` text domain,
 *   are stored in the language strings translations (`Strope_MO`).
 * - Theme and plugin strings found by the string discovery module (when available) are saved as overrides by this module.
 *
 * @since 4.1.0
 */
class Strope_Frontend_Editor_Strings {
	const TYPE_REGISTERED = 'registered';
	const TYPE_DISCOVERED = 'discovered';

	/**
	 * Registered strings used on the page.
	 *
	 * @var true[] Source strings as keys.
	 */
	private $registered = array();

	/**
	 * Starts collecting the strings used on the page.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function start() {
		add_filter( 'gettext_strope_string', array( $this, 'collect' ), PHP_INT_MAX, 2 );
		self::call_discovery( 'start_collecting' );
	}

	/**
	 * Records a registered string translated while rendering the page.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Source text.
	 * @return string Unmodified translation.
	 */
	public function collect( $translation, $text ) {
		if ( is_string( $text ) && '' !== $text && count( $this->registered ) < 1000 ) {
			$this->registered[ $text ] = true;
		}
		return $translation;
	}

	/**
	 * Returns the strings used on the page with their translation in a language.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language Language.
	 * @return array[]
	 */
	public function get_strings( Strope_Language $language ): array {
		$strings = array();

		if ( ! empty( $this->registered ) ) {
			$mo = new Strope_MO();
			$mo->import_from_db( $language );

			foreach ( array_keys( $this->registered ) as $text ) {
				$text  = (string) $text;
				$entry = new Translation_Entry( array( 'singular' => $text ) );
				$entry = $mo->translate_entry( $entry );

				$strings[] = array(
					'type'        => self::TYPE_REGISTERED,
					'domain'      => '',
					'context'     => '',
					'singular'    => $text,
					'translation' => $entry && ! empty( $entry->translations[0] ) ? (string) $entry->translations[0] : '',
					'id'          => self::sign( self::TYPE_REGISTERED, '', '', $text ),
				);
			}
		}

		$seen       = array();
		$discovered = self::call_discovery( 'get_collected' );
		if ( is_array( $discovered ) ) {
			foreach ( $discovered as $item ) {
				$item = (array) $item;
				if ( empty( $item['singular'] ) || ! is_string( $item['singular'] ) ) {
					continue;
				}

				$domain  = isset( $item['domain'] ) && is_string( $item['domain'] ) ? $item['domain'] : 'default';
				$context = isset( $item['context'] ) && is_string( $item['context'] ) ? $item['context'] : '';
				if ( 'strope_string' === $domain ) {
					continue; // Already handled as a registered string.
				}

				$key = $domain . "\4" . $context . "\4" . $item['singular'];
				if ( isset( $seen[ $key ] ) || count( $seen ) >= 1000 ) {
					continue;
				}
				$seen[ $key ] = true;

				$plural      = isset( $item['plural'] ) && is_string( $item['plural'] ) ? $item['plural'] : '';
				$translation = isset( $item['translation'] ) && is_scalar( $item['translation'] ) ? (string) $item['translation'] : '';

				$strings[] = array(
					'type'        => self::TYPE_DISCOVERED,
					'domain'      => $domain,
					'context'     => $context,
					'singular'    => $item['singular'],
					'plural'      => $plural,
					'translation' => self::decode_discovered_translation( $translation, $item['singular'] . "\n" . $plural ),
					// WordPress core strings can be overridden only by the users who manage the languages.
					'editable'    => 'default' !== $domain || current_user_can( 'manage_languages' ),
					'id'          => self::sign( self::TYPE_DISCOVERED, $domain, $context, $item['singular'] ),
				);
			}
		}

		return $strings;
	}

	/**
	 * Returns a signature proving that a string was displayed on a page rendered for the current user.
	 *
	 * @since 4.1.0
	 *
	 * @param string $type     String type.
	 * @param string $domain   Text domain.
	 * @param string $context  Context.
	 * @param string $singular Source string.
	 * @return string
	 */
	public static function sign( string $type, string $domain, string $context, string $singular ): string {
		return wp_hash( implode( "\4", array( 'strope_fe_string', get_current_user_id(), $type, $domain, $context, $singular ) ), 'nonce' );
	}

	/**
	 * Tells whether the string discovery module can save overrides.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	public static function can_save_discovered(): bool {
		return null !== self::get_discovery_callable( 'save_override' );
	}

	/**
	 * Saves the translation of a registered string.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language    Language.
	 * @param string          $text        Source string.
	 * @param string          $translation Translation.
	 * @return string|WP_Error The saved translation, an error if its placeholders don't match the source string.
	 */
	public static function save_registered( Strope_Language $language, string $text, string $translation ) {
		$translation = str_replace( array( "\r\n", "\r" ), "\n", trim( $translation ) );

		if ( wp_strip_all_tags( $text ) === $text ) {
			// Plain text source string: plain text translation.
			$translation = false === strpos( $text, "\n" ) ? sanitize_text_field( $translation ) : sanitize_textarea_field( $translation );
		} elseif ( ! current_user_can( 'unfiltered_html' ) ) {
			$translation = wp_kses_post( $translation );
		}

		/** This filter is documented in settings/table-string.php */
		$translation = (string) apply_filters( 'strope_sanitize_string_translation', $translation, '', '' );

		// A translation with other placeholders than the source string would break `sprintf()`.
		$valid = Strope_String_Placeholders::validate( $translation, $text );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$mo = new Strope_MO();
		$mo->import_from_db( $language );
		$mo->add_entry( $mo->make_entry( $text, $translation ) );
		$mo->export_to_db( $language );

		/** This action is documented in settings/table-string.php */
		do_action( 'strope_save_strings_translations' );

		return $translation;
	}

	/**
	 * Saves the translation of a string found by the string discovery module.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $domain      Text domain.
	 * @param string          $context     Context.
	 * @param string          $singular    Source string.
	 * @param Strope_Language $language    Language.
	 * @param string          $translation Translation.
	 * @return true|WP_Error
	 */
	public static function save_discovered( string $domain, string $context, string $singular, Strope_Language $language, string $translation ) {
		if ( ! self::can_save_discovered() ) {
			return new WP_Error( 'strope_fe_discovery_unavailable', __( 'Theme and plugin strings cannot be translated on this site.', 'strope-translate' ), array( 'status' => 501 ) );
		}

		$result = self::call_discovery( 'save_override', array( $domain, $context, $singular, $language->slug, $translation ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			return new WP_Error( 'strope_fe_string_not_saved', __( 'The translation could not be saved.', 'strope-translate' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Decodes the special characters encoded by the string discovery module when it saves
	 * a translation for a user without the `unfiltered_html` capability, so that the
	 * translation is displayed and edited as plain text (the module encodes it again on save).
	 *
	 * `<` and `>` are decoded only for plain text source strings, quotes and backticks only
	 * when they are absent from the source string, mirroring the encoding.
	 *
	 * Nothing is decoded for users with the `unfiltered_html` capability, as the module saves their
	 * translations unchanged: they edit the stored value exactly, otherwise an encoded `&lt;script&gt;`
	 * would be saved back as a real tag.
	 *
	 * @since 4.1.0
	 *
	 * @param string $translation Stored translation.
	 * @param string $source      Source string (singular and plural).
	 * @return string
	 */
	public static function decode_discovered_translation( string $translation, string $source ): string {
		if ( false === strpos( $translation, '&' ) || current_user_can( 'unfiltered_html' ) ) {
			return $translation;
		}

		$map = array();

		if ( false === strpos( $source, '<' ) && false === strpos( $source, '>' ) ) {
			$map += array(
				'&lt;' => '<',
				'&gt;' => '>',
			);
		}

		$chars = array(
			'"' => array( '&quot;', '&#034;', '&#34;', '&#x22;' ),
			"'" => array( '&#039;', '&#39;', '&#x27;', '&apos;' ),
			'`' => array( '&#096;', '&#96;', '&#x60;' ),
		);
		foreach ( $chars as $char => $entities ) {
			if ( false === strpos( $source, $char ) ) {
				$map += array_fill_keys( $entities, $char );
			}
		}

		return strtr( $translation, $map );
	}

	/**
	 * Returns the stored translation of a discovered string, decoded for display.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $domain   Text domain.
	 * @param string          $context  Context.
	 * @param string          $singular Source string.
	 * @param Strope_Language $language Language.
	 * @param string          $fallback Value returned if the stored translation can't be read.
	 * @return string
	 */
	public static function get_discovered_translation( string $domain, string $context, string $singular, Strope_Language $language, string $fallback ): string {
		$store = 'Strope_String_Discovery_Store';
		if ( ! class_exists( $store ) || ! method_exists( $store, 'find_string' ) || ! method_exists( $store, 'get_translations' ) ) {
			return $fallback;
		}

		$string = call_user_func( array( $store, 'find_string' ), $domain, $context, $singular, '', false );
		if ( ! is_object( $string ) || empty( $string->id ) ) {
			return $fallback;
		}

		$translations = (array) call_user_func( array( $store, 'get_translations' ), array( (int) $string->id ), array( $language->slug ) );
		$forms        = $translations[ (int) $string->id ][ $language->slug ] ?? array();
		$plural       = isset( $string->plural ) ? (string) $string->plural : '';

		return self::decode_discovered_translation( isset( $forms[0] ) ? (string) $forms[0] : '', $singular . "\n" . $plural );
	}

	/**
	 * Returns a callable for a method of the string discovery module, if available.
	 *
	 * @since 4.1.0
	 *
	 * @param string $method Method name.
	 * @return callable|null
	 */
	private static function get_discovery_callable( string $method ) {
		$class = 'Strope_String_Discovery';

		if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
			return null;
		}

		try {
			$reflection = new ReflectionMethod( $class, $method );
		} catch ( ReflectionException $e ) {
			return null;
		}

		if ( ! $reflection->isPublic() ) {
			return null;
		}

		if ( $reflection->isStatic() ) {
			return array( $class, $method );
		}

		$instance = null;
		foreach ( array( 'instance', 'get_instance' ) as $getter ) {
			if ( method_exists( $class, $getter ) && is_callable( array( $class, $getter ) ) ) {
				$instance = call_user_func( array( $class, $getter ) );
				break;
			}
		}

		if ( ! $instance instanceof $class && function_exists( 'Strope' ) && isset( Strope()->string_discovery ) ) {
			$instance = Strope()->string_discovery;
		}

		/**
		 * Filters the string discovery instance used by the front-end translation editor.
		 *
		 * @since 4.1.0
		 *
		 * @param object|null $instance String discovery instance.
		 */
		$instance = apply_filters( 'strope_frontend_editor_string_discovery', $instance );

		return $instance instanceof $class ? array( $instance, $method ) : null;
	}

	/**
	 * Calls a method of the string discovery module, if available.
	 *
	 * @since 4.1.0
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed Null if not available.
	 */
	private static function call_discovery( string $method, array $args = array() ) {
		$callable = self::get_discovery_callable( $method );
		return null === $callable ? null : call_user_func_array( $callable, $args );
	}
}
