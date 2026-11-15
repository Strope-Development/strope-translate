<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Makes the texts entered in the WooCommerce settings translatable in the strings translations.
 *
 * @since 4.1.0
 */
class Strope_WooCommerce_Strings {
	/**
	 * Context of the registered strings.
	 *
	 * @var string
	 */
	const CONTEXT = 'WooCommerce';

	/**
	 * Context of the registered emails strings.
	 *
	 * @var string
	 */
	const EMAILS_CONTEXT = 'WooCommerce Emails';

	/**
	 * The WooCommerce integration.
	 *
	 * @var Strope_WooCommerce
	 */
	protected $woocommerce;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_WooCommerce $woocommerce The WooCommerce integration.
	 */
	public function __construct( Strope_WooCommerce $woocommerce ) {
		$this->woocommerce = $woocommerce;

		$this->translate_options();

		// Emails settings.
		add_filter( 'woocommerce_email_get_option', array( $this, 'translate_email_option' ), 10, 4 );

		// Payment gateways titles and descriptions.
		add_filter( 'woocommerce_gateway_title', array( $this, 'translate_gateway_string' ), 10, 2 );
		add_filter( 'woocommerce_gateway_description', array( $this, 'translate_gateway_string' ), 10, 2 );

		// Global attributes labels.
		add_filter( 'woocommerce_attribute_label', array( $this, 'translate_attribute_label' ), 10, 2 );

		if ( Strope() instanceof Strope_Settings ) {
			add_action( 'admin_init', array( $this, 'register_strings' ) );
		}
	}

	/**
	 * Registers and translates the texts of WooCommerce options.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	protected function translate_options() {
		/**
		 * Filters the WooCommerce options translated in the strings translations.
		 *
		 * @since 4.1.0
		 *
		 * @param array $options Option names as keys, and keys to translate as values (see `Strope_Translate_Option`).
		 */
		$options = (array) apply_filters(
			'strope_wc_translated_options',
			array(
				'woocommerce_demo_store_notice'                    => array(),
				'woocommerce_email_footer_text'                    => array(),
				'woocommerce_email_from_name'                      => array(),
				'woocommerce_checkout_privacy_policy_text'         => array(),
				'woocommerce_registration_privacy_policy_text'     => array(),
				'woocommerce_checkout_terms_and_conditions_checkbox_text' => array(),
				'woocommerce_price_display_suffix'                 => array(),
				'woocommerce_bacs_settings'                        => array(
					'title'        => 1,
					'description'  => 1,
					'instructions' => 1,
				),
				'woocommerce_cheque_settings'                      => array(
					'title'        => 1,
					'description'  => 1,
					'instructions' => 1,
				),
				'woocommerce_cod_settings'                         => array(
					'title'        => 1,
					'description'  => 1,
					'instructions' => 1,
				),
			)
		);

		foreach ( $options as $name => $keys ) {
			new Strope_Translate_Option( $name, (array) $keys, array( 'context' => self::CONTEXT ) );
		}
	}

	/**
	 * Tells whether an email option is translatable.
	 *
	 * @since 4.1.0
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	protected function is_translatable_email_key( $key ) {
		return (bool) preg_match( '/^(subject|heading|additional_content|preheader)(_[a-z_]+)?$/', (string) $key );
	}

	/**
	 * Translates the texts of the emails settings when the email is rendered.
	 * The language is switched before the email is rendered.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed    $value       Option value.
	 * @param WC_Email $email       Email object.
	 * @param mixed    $raw_value   Option value.
	 * @param string   $key         Option key.
	 * @return mixed
	 */
	public function translate_email_option( $value, $email, $raw_value, $key ) {
		if ( ! is_string( $value ) || '' === $value || ! $email instanceof WC_Email || ! $this->is_translatable_email_key( $key ) ) {
			return $value;
		}

		// Translate only the texts entered in the settings, the default texts are already translated.
		if ( empty( $email->settings[ $key ] ) || $email->settings[ $key ] !== $value ) {
			return $value;
		}

		return strope__( $value );
	}

	/**
	 * Translates the title and description of the payment gateways.
	 *
	 * @since 4.1.0
	 *
	 * @param string $string Title or description.
	 * @param string $id     Gateway id.
	 * @return string
	 */
	public function translate_gateway_string( $string, $id = '' ) {
		return is_string( $string ) && '' !== $string ? strope__( $string ) : $string;
	}

	/**
	 * Translates the labels of the global attributes.
	 *
	 * @since 4.1.0
	 *
	 * @param string $label Attribute label.
	 * @param string $name  Attribute name.
	 * @return string
	 */
	public function translate_attribute_label( $label, $name = '' ) {
		return is_string( $label ) && '' !== $label && taxonomy_is_product_attribute( (string) $name ) ? strope__( $label ) : $label;
	}

	/**
	 * Registers the strings of the emails and of the payment gateways settings.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function register_strings() {
		global $wpdb;

		// Global attributes labels.
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( ! empty( $attribute->attribute_label ) ) {
				strope_register_string( 'attribute_' . $attribute->attribute_name, $attribute->attribute_label, self::CONTEXT );
			}
		}

		// Emails and payment gateways.
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'woocommerce_' ) . '%' . $wpdb->esc_like( '_settings' )
			)
		);

		$gateways = array( 'woocommerce_bacs_settings', 'woocommerce_cheque_settings', 'woocommerce_cod_settings' ); // Already registered.

		foreach ( $names as $name ) {
			if ( in_array( $name, $gateways, true ) ) {
				continue;
			}

			$settings = get_option( $name );

			if ( ! is_array( $settings ) ) {
				continue;
			}

			$id = substr( $name, strlen( 'woocommerce_' ), -strlen( '_settings' ) );

			foreach ( $settings as $key => $value ) {
				if ( ! is_string( $value ) || '' === trim( $value ) ) {
					continue;
				}

				if ( $this->is_translatable_email_key( $key ) && ! isset( $settings['title'] ) ) {
					// Emails settings.
					strope_register_string( "{$id}_{$key}", $value, self::EMAILS_CONTEXT, 'additional_content' === $key );
				} elseif ( in_array( $key, array( 'title', 'description' ), true ) && isset( $settings['enabled'] ) ) {
					// Other payment gateways.
					strope_register_string( "{$id}_{$key}", $value, self::CONTEXT, 'description' === $key );
				}
			}
		}
	}
}
