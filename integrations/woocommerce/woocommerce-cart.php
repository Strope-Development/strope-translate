<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the cart across languages: one cart for all languages,
 * displaying the products in the current language.
 *
 * @since 4.1.0
 */
class Strope_WooCommerce_Cart {
	/**
	 * The WooCommerce integration.
	 *
	 * @var Strope_WooCommerce
	 */
	protected $woocommerce;

	/**
	 * Strope Translate model.
	 *
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_WooCommerce $woocommerce The WooCommerce integration.
	 */
	public function __construct( Strope_WooCommerce $woocommerce ) {
		$this->woocommerce = $woocommerce;
		$this->model       = Strope()->model;

		if ( Strope() instanceof Strope_Admin_Base ) {
			return;
		}

		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'translate_cart' ), 5 );
		add_action( 'strope_language_defined', array( $this, 'language_defined' ), 20 );
		add_filter( 'woocommerce_add_to_cart_product_id', array( $this, 'translate_product_id' ) );

		// Store API (cart and checkout blocks).
		add_filter( 'rest_pre_dispatch', array( $this, 'store_api_language' ), 5, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_api_fetch_language' ), 20 );
	}

	/**
	 * Translates the products in the cart when it is loaded from the session.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public function translate_cart( $cart ) {
		$language = $this->woocommerce->get_current_language();

		if ( empty( $language ) || ! $cart instanceof WC_Cart || ! $this->model->is_translated_post_type( 'product' ) ) {
			return;
		}

		$contents = $cart->get_cart_contents();
		$changed  = false;
		$new      = array();

		foreach ( $contents as $key => $item ) {
			$tr_item = $this->translate_cart_item( $cart, $item, $language );

			if ( null === $tr_item ) {
				$new[ $key ] = $item;
				continue;
			}

			$changed = true;
			$new_key = $tr_item['key'];

			if ( isset( $new[ $new_key ] ) ) {
				$new[ $new_key ]['quantity'] += $tr_item['quantity'];
			} else {
				$new[ $new_key ] = $tr_item;
			}
		}

		if ( $changed ) {
			$cart->set_cart_contents( $new );

			// Forces WooCommerce to save the new cart contents in the session and to calculate the totals again.
			if ( isset( WC()->session ) ) {
				WC()->session->set( 'cart_totals', null );
			}
		}
	}

	/**
	 * Translates the cart if it was loaded before the language was defined.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function language_defined() {
		if ( ! did_action( 'woocommerce_cart_loaded_from_session' ) || ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart ) {
			return;
		}

		$hash = WC()->cart->get_cart_hash();
		$this->translate_cart( WC()->cart );

		if ( WC()->cart->get_cart_hash() !== $hash ) {
			WC()->cart->calculate_totals(); // Also saves the cart in the session.
		}
	}

	/**
	 * Translates a cart item.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Cart         $cart     Cart object.
	 * @param array           $item     Cart item.
	 * @param Strope_Language $language Target language.
	 * @return array|null Translated cart item, null if not modified.
	 */
	protected function translate_cart_item( WC_Cart $cart, array $item, Strope_Language $language ) {
		if ( empty( $item['product_id'] ) ) {
			return null;
		}

		$product_id   = (int) $item['product_id'];
		$variation_id = empty( $item['variation_id'] ) ? 0 : (int) $item['variation_id'];

		$tr_product_id   = $this->model->post->get( $product_id, $language );
		$tr_variation_id = $variation_id ? $this->model->post->get( $variation_id, $language ) : 0;

		if ( ! $tr_product_id || $tr_product_id === $product_id || ( $variation_id && ! $tr_variation_id ) ) {
			return null;
		}

		$tr_product = wc_get_product( $tr_variation_id ? $tr_variation_id : $tr_product_id );

		if ( ! $tr_product || ! $tr_product->exists() || 'trash' === $tr_product->get_status() || ! $tr_product->is_purchasable() ) {
			return null;
		}

		$variation = isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array();

		if ( $tr_variation_id ) {
			$variation = $this->translate_variation( $variation, $product_id, $tr_product_id, $tr_product, $language );
		}

		$standard_keys  = array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' );
		$cart_item_data = array_diff_key( $item, array_flip( $standard_keys ) );

		$item['product_id']   = $tr_product_id;
		$item['variation_id'] = $tr_variation_id;
		$item['variation']    = $variation;
		$item['data']         = $tr_product;
		$item['data_hash']    = wc_get_cart_item_data_hash( $tr_product );
		$item['key']          = $cart->generate_cart_id( $tr_product_id, $tr_variation_id, $variation, $cart_item_data );

		/**
		 * Filters a cart item translated in a new language.
		 *
		 * @since 4.1.0
		 *
		 * @param array           $item     Translated cart item.
		 * @param Strope_Language $language Language.
		 */
		return apply_filters( 'strope_wc_translated_cart_item', $item, $language );
	}

	/**
	 * Translates the selected attributes of a variation in the cart.
	 *
	 * @since 4.1.0
	 *
	 * @param array                $variation     Selected attributes, with 'attribute_' prefixed keys.
	 * @param int                  $product_id    Source parent product id.
	 * @param int                  $tr_product_id Translated parent product id.
	 * @param WC_Product_Variation $tr_variation  Translated variation.
	 * @param Strope_Language      $language      Target language.
	 * @return array
	 */
	protected function translate_variation( array $variation, $product_id, $tr_product_id, $tr_variation, Strope_Language $language ) {
		$product    = wc_get_product( $product_id );
		$tr_product = wc_get_product( $tr_product_id );

		if ( ! $product || ! $tr_product || empty( $this->woocommerce->products ) ) {
			return $variation;
		}

		$attributes = array();
		foreach ( $variation as $key => $value ) {
			$attributes[ preg_replace( '/^attribute_/', '', (string) $key ) ] = $value;
		}

		$map        = $this->woocommerce->products->get_custom_attributes_map( $product, $tr_product );
		$attributes = $this->woocommerce->products->translate_variation_attributes( $attributes, $language, $map, $this->model->post->get_language( $product_id ) );

		// Values defined by the translated variation itself have priority.
		foreach ( $tr_variation->get_attributes( 'edit' ) as $key => $value ) {
			if ( '' !== (string) $value ) {
				$attributes[ $key ] = $value;
			}
		}

		$translated = array();
		foreach ( $attributes as $key => $value ) {
			$translated[ 'attribute_' . $key ] = $value;
		}

		return $translated;
	}

	/**
	 * Translates a product added to the cart in the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	public function translate_product_id( $product_id ) {
		$language = $this->woocommerce->get_current_language();

		if ( empty( $language ) || empty( $product_id ) || 'product' !== get_post_type( (int) $product_id ) ) {
			return $product_id;
		}

		// Variable products are translated when the cart is loaded, as the variation is also needed.
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			return $product_id;
		}

		$tr_id = $this->model->post->get( (int) $product_id, $language );
		return $tr_id ? $tr_id : $product_id;
	}

	/**
	 * Sets the language of Store API requests (used by the cart and checkout blocks)
	 * when it is not sent, from the language cookie.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed Unmodified $result.
	 */
	public function store_api_language( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || 0 !== strpos( $request->get_route(), '/wc/store/' ) || $request->get_param( 'lang' ) ) {
			return $result;
		}

		$lang = Strope_Cookie::get();

		if ( is_string( $lang ) && '' !== $lang && $this->model->get_language( sanitize_key( $lang ) ) ) {
			$request->set_param( 'lang', sanitize_key( $lang ) );
		}

		return $result;
	}

	/**
	 * Adds the current language to the Store API requests made with `wp.apiFetch` on frontend.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function enqueue_api_fetch_language() {
		$language = $this->woocommerce->get_current_language();

		if ( empty( $language ) || ! wp_script_is( 'wp-api-fetch', 'registered' ) ) {
			return;
		}

		$script = sprintf(
			'( function() {
				if ( ! window.wp || ! wp.apiFetch || ! wp.apiFetch.use ) { return; }
				var lang = %s;
				wp.apiFetch.use( function( options, next ) {
					var prop = options.path ? "path" : ( options.url ? "url" : "" );
					if ( prop && -1 !== options[ prop ].indexOf( "wc/store" ) && ! /[?&]lang=/.test( options[ prop ] ) ) {
						options = Object.assign( {}, options );
						options[ prop ] += ( -1 === options[ prop ].indexOf( "?" ) ? "?" : "&" ) + "lang=" + encodeURIComponent( lang );
					}
					return next( options );
				} );
			} )();',
			wp_json_encode( $language->slug )
		);

		wp_add_inline_script( 'wp-api-fetch', $script, 'after' );
	}
}
