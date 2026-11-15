<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main class of the WooCommerce integration.
 * Makes products, product categories, tags and attributes translatable,
 * translates the WooCommerce pages and loads the other parts of the integration.
 *
 * @since 4.1.0
 */
class Strope_WooCommerce {
	/**
	 * Name of the option storing the integration state.
	 *
	 * @var string
	 */
	const OPTION = 'strope_woocommerce';

	/**
	 * Products synchronization.
	 *
	 * @var Strope_WooCommerce_Products|null
	 */
	public $products;

	/**
	 * Cart and Store API.
	 *
	 * @var Strope_WooCommerce_Cart|null
	 */
	public $cart;

	/**
	 * Orders and emails.
	 *
	 * @var Strope_WooCommerce_Orders|null
	 */
	public $orders;

	/**
	 * Translatable WooCommerce settings strings.
	 *
	 * @var Strope_WooCommerce_Strings|null
	 */
	public $strings;

	/**
	 * Stack of languages temporarily switched to (e.g. when sending emails).
	 *
	 * @var array
	 */
	protected $switched = array();

	/**
	 * Constructor.
	 * Called at `plugins_loaded` before Strope Translate is initialized.
	 *
	 * @since 4.1.0
	 */
	public function __construct() {
		add_filter( 'strope_get_post_types', array( $this, 'get_post_types' ), 10, 2 );
		add_filter( 'strope_get_taxonomies', array( $this, 'get_taxonomies' ), 10, 2 );

		add_action( 'strope_init', array( $this, 'init' ) );

		// New global attributes are translatable by default when products are translatable.
		add_action( 'woocommerce_attribute_added', array( $this, 'attribute_added' ), 10, 2 );
		add_action( 'woocommerce_attribute_updated', array( $this, 'attribute_updated' ), 10, 3 );
	}

	/**
	 * Setups the integration once Strope Translate is initialized.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function init() {
		if ( ! Strope()->model->has_languages() ) {
			return;
		}

		$this->maybe_set_defaults();

		$this->products = new Strope_WooCommerce_Products( $this );
		$this->cart     = new Strope_WooCommerce_Cart( $this );
		$this->orders   = new Strope_WooCommerce_Orders( $this );
		$this->strings  = new Strope_WooCommerce_Strings( $this );

		// WooCommerce pages.
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
			add_filter( "woocommerce_get_{$page}_page_id", array( $this, 'translate_page_id' ) );
		}

		if ( Strope() instanceof Strope_Frontend ) {
			add_filter( 'request', array( $this, 'shop_page_request' ), 20 );
			add_filter( 'post_type_archive_link', array( $this, 'post_type_archive_link' ), 20, 2 );
			add_filter( 'strope_translation_url', array( $this, 'translation_url' ), 10, 2 );
		}
	}

	/**
	 * Adds product variations to translated post types when products are translated,
	 * and makes sure that products appear in the settings.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $post_types  List of post type names.
	 * @param bool     $is_settings True when displaying the list in the settings.
	 * @return string[]
	 */
	public function get_post_types( $post_types, $is_settings ) {
		if ( $is_settings ) {
			$post_types['product'] = 'product';
		} elseif ( isset( $post_types['product'] ) ) {
			$post_types['product_variation'] = 'product_variation';
		}
		return $post_types;
	}

	/**
	 * Makes sure that WooCommerce taxonomies appear in the settings, even when not public.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $taxonomies  List of taxonomy names.
	 * @param bool     $is_settings True when displaying the list in the settings.
	 * @return string[]
	 */
	public function get_taxonomies( $taxonomies, $is_settings ) {
		if ( $is_settings ) {
			foreach ( array_merge( array( 'product_cat', 'product_tag', 'product_shipping_class' ), $this->get_attribute_taxonomies() ) as $taxonomy ) {
				$taxonomies[ $taxonomy ] = $taxonomy;
			}
		}
		return $taxonomies;
	}

	/**
	 * Returns the global attributes taxonomy names.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	protected function get_attribute_taxonomies() {
		return function_exists( 'wc_get_attribute_taxonomy_names' ) ? wc_get_attribute_taxonomy_names() : array();
	}

	/**
	 * Makes products, product categories, product tags and global attributes translatable
	 * the first time the integration runs. They can be deactivated later in the settings.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	protected function maybe_set_defaults() {
		$state = get_option( self::OPTION );

		if ( is_array( $state ) && ! empty( $state['defaults'] ) ) {
			return;
		}

		$strope  = Strope();
		$options = &$strope->options;

		$options['post_types'] = array_values( array_unique( array_merge( (array) $options['post_types'], array( 'product' ) ) ) );
		$options['taxonomies'] = array_values( array_unique( array_merge( (array) $options['taxonomies'], array( 'product_cat', 'product_tag' ), $this->get_attribute_taxonomies() ) ) );

		update_option( 'strope_translate', $options );
		Strope()->model->cache->clean( 'post_types' );
		Strope()->model->cache->clean( 'taxonomies' );

		$state = is_array( $state ) ? $state : array();
		$state['defaults'] = 1;
		update_option( self::OPTION, $state );
	}

	/**
	 * Makes a new global attribute translatable if products are translatable.
	 *
	 * @since 4.1.0
	 *
	 * @param int   $id   Attribute ID.
	 * @param array $data Attribute data.
	 * @return void
	 */
	public function attribute_added( $id, $data ) {
		if ( empty( $data['attribute_name'] ) || ! function_exists( 'Strope' ) || ! Strope() ) {
			return;
		}

		$strope  = Strope();
		$options = &$strope->options;

		if ( ! in_array( 'product', (array) $options['post_types'], true ) ) {
			return;
		}

		$taxonomy = wc_attribute_taxonomy_name( $data['attribute_name'] );
		if ( ! in_array( $taxonomy, (array) $options['taxonomies'], true ) ) {
			$options['taxonomies'][] = $taxonomy;
			update_option( 'strope_translate', $options );
			Strope()->model->cache->clean( 'taxonomies' );
		}
	}

	/**
	 * Keeps the translatable state of a global attribute when its slug is modified.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $id       Attribute ID.
	 * @param array  $data     Attribute data.
	 * @param string $old_slug Old attribute slug.
	 * @return void
	 */
	public function attribute_updated( $id, $data, $old_slug ) {
		if ( empty( $data['attribute_name'] ) || $old_slug === $data['attribute_name'] || ! function_exists( 'Strope' ) || ! Strope() ) {
			return;
		}

		$strope  = Strope();
		$options = &$strope->options;
		$old     = wc_attribute_taxonomy_name( $old_slug );
		$key     = array_search( $old, (array) $options['taxonomies'], true );

		if ( false !== $key ) {
			$options['taxonomies'][ $key ] = wc_attribute_taxonomy_name( $data['attribute_name'] );
			update_option( 'strope_translate', $options );
			Strope()->model->cache->clean( 'taxonomies' );
		}
	}

	/**
	 * Returns the language to use for WooCommerce data in the current context:
	 * the temporarily switched language if any, otherwise the current language on frontend and REST requests.
	 * Admin requests are not affected, so that the WooCommerce settings work with the original pages.
	 *
	 * @since 4.1.0
	 *
	 * @return Strope_Language|null
	 */
	public function get_current_language() {
		if ( ! empty( $this->switched ) ) {
			$language = end( $this->switched );
			return $language['language'];
		}

		if ( Strope() instanceof Strope_Admin_Base ) {
			return null;
		}

		return Strope()->curlang instanceof Strope_Language ? Strope()->curlang : null;
	}

	/**
	 * Temporarily switches the current language and locale, e.g. to send an email.
	 * Must be followed by a call to `restore_language()`.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language Language to switch to.
	 * @return void
	 */
	public function switch_language( Strope_Language $language ) {
		$state = array(
			'language' => $language,
			'previous' => Strope()->curlang,
			'strings'  => $this->get_strings_language(),
		);

		// Switch the locale first, as the current language may filter the current locale.
		$state['locale']  = switch_to_locale( $language->locale ); // Loads the strings translations on `change_locale`.
		Strope()->curlang = $language;

		// The locale may be unchanged while the strings translations are in another language (e.g. REST requests).
		if ( $this->get_strings_language() !== $language->slug ) {
			Strope()->load_strings_translations( $language->slug );
		}

		// Make sure the WooCommerce translations are loaded in the right language, even in admin.
		add_filter( 'plugin_locale', array( $this, 'plugin_locale' ), 20 );
		if ( function_exists( 'WC' ) && $state['locale'] ) {
			WC()->load_plugin_textdomain();
		}

		$this->switched[] = $state;
	}

	/**
	 * Restores the language and locale switched by `switch_language()`.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function restore_language() {
		$state = array_pop( $this->switched );

		if ( empty( $state ) ) {
			return;
		}

		if ( ! empty( $state['locale'] ) ) {
			restore_previous_locale();
		}

		Strope()->curlang = $state['previous'];

		if ( empty( $this->switched ) ) {
			remove_filter( 'plugin_locale', array( $this, 'plugin_locale' ), 20 );
		}

		if ( ! empty( $state['locale'] ) && function_exists( 'WC' ) ) {
			WC()->load_plugin_textdomain();
		}

		if ( $this->get_strings_language() !== $state['strings'] ) {
			if ( '' === $state['strings'] ) {
				unset( $GLOBALS['l10n']['strope_string'] );
			} else {
				Strope()->load_strings_translations( $state['strings'] );
			}
		}
	}

	/**
	 * Returns the language of the currently loaded strings translations.
	 *
	 * @since 4.1.0
	 *
	 * @return string Language slug, empty string if no strings translations are loaded.
	 */
	protected function get_strings_language() {
		if ( empty( $GLOBALS['l10n']['strope_string'] ) || ! $GLOBALS['l10n']['strope_string'] instanceof Strope_MO ) {
			return '';
		}

		$lang = $GLOBALS['l10n']['strope_string']->get_header( 'Language' );
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Forces the locale of plugins translations to the switched language.
	 *
	 * @since 4.1.0
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public function plugin_locale( $locale ) {
		$language = $this->get_current_language();
		return $language ? $language->locale : $locale;
	}

	/**
	 * Translates the ids of the WooCommerce pages (shop, cart, checkout, my account, terms).
	 *
	 * @since 4.1.0
	 *
	 * @param int|string $page_id Page id.
	 * @return int|string
	 */
	public function translate_page_id( $page_id ) {
		// Don't modify the shop page when the product post type is registered, to keep stable rewrite rules.
		if ( empty( $page_id ) || ! did_action( 'woocommerce_after_register_post_type' ) ) {
			return $page_id;
		}

		$language = $this->get_current_language();

		if ( empty( $language ) ) {
			return $page_id;
		}

		$tr_id = Strope()->model->post->get( (int) $page_id, $language );
		return $tr_id ? $tr_id : $page_id;
	}

	/**
	 * Returns the translations of the shop page.
	 *
	 * @since 4.1.0
	 *
	 * @return int[]
	 */
	protected function get_shop_page_translations() {
		$shop_page_id = (int) get_option( 'woocommerce_shop_page_id' );

		if ( $shop_page_id <= 0 ) {
			return array();
		}

		$translations = Strope()->model->post->get_translations( $shop_page_id );
		return empty( $translations ) ? array( $shop_page_id ) : array_map( 'intval', $translations );
	}

	/**
	 * Displays the products archive when a translation of the shop page is requested.
	 *
	 * @since 4.1.0
	 *
	 * @param array $query_vars The array of requested query variables.
	 * @return array
	 */
	public function shop_page_request( $query_vars ) {
		if ( is_admin() || ( empty( $query_vars['pagename'] ) && empty( $query_vars['page_id'] ) ) ) {
			return $query_vars;
		}

		// Let WooCommerce deal with a shop displayed on the front page.
		if ( ! empty( $query_vars['page_id'] ) && 'page' === get_option( 'show_on_front' ) ) {
			return $query_vars;
		}

		$translations = $this->get_shop_page_translations();

		if ( empty( $translations ) ) {
			return $query_vars;
		}

		$page_id = 0;

		if ( ! empty( $query_vars['page_id'] ) ) {
			$page_id = in_array( (int) $query_vars['page_id'], $translations, true ) ? (int) $query_vars['page_id'] : 0;
		} else {
			// Compare the requested path to the paths of the shop page translations, to avoid a query on each request.
			$pagename = trim( (string) $query_vars['pagename'], '/' );
			foreach ( $translations as $tr_id ) {
				if ( 0 === strcasecmp( rawurldecode( $pagename ), urldecode( (string) get_page_uri( $tr_id ) ) ) ) {
					$page_id = $tr_id;
					break;
				}
			}
		}

		if ( ! $page_id ) {
			return $query_vars;
		}

		// Don't break WooCommerce endpoints which could be added to the page.
		foreach ( array_keys( WC()->query->get_query_vars() ) as $var ) {
			if ( isset( $query_vars[ $var ] ) ) {
				return $query_vars;
			}
		}

		$language = Strope()->model->post->get_language( $page_id );

		// Let Strope Translate redirect a page requested in the wrong language.
		if ( ! $language || ( Strope()->curlang instanceof Strope_Language && Strope()->curlang->slug !== $language->slug ) ) {
			return $query_vars;
		}

		unset( $query_vars['pagename'], $query_vars['page_id'], $query_vars['page'] );
		$query_vars['post_type'] = 'product';

		if ( ! isset( $query_vars['lang'] ) ) {
			$query_vars['lang'] = $language->slug;
		}

		return $query_vars;
	}

	/**
	 * Uses the translated shop page as products archive link.
	 *
	 * @since 4.1.0
	 *
	 * @param string $link      Post type archive link.
	 * @param string $post_type Post type name.
	 * @return string
	 */
	public function post_type_archive_link( $link, $post_type ) {
		if ( 'product' !== $post_type ) {
			return $link;
		}

		$language = $this->get_current_language();
		$shop_id  = (int) get_option( 'woocommerce_shop_page_id' );

		if ( empty( $language ) || $shop_id <= 0 ) {
			return $link;
		}

		$tr_id = Strope()->model->post->get( $shop_id, $language );

		// The shop page in the default language is the archive itself.
		if ( ! $tr_id || $tr_id === $shop_id ) {
			return $link;
		}

		$permalink = get_permalink( $tr_id );
		return $permalink ? $permalink : $link;
	}

	/**
	 * Translates the language switcher links on the shop page.
	 *
	 * @since 4.1.0
	 *
	 * @param string|null $url  Translation url.
	 * @param string      $lang Language slug.
	 * @return string|null
	 */
	public function translation_url( $url, $lang ) {
		if ( ! function_exists( 'is_shop' ) || ! is_shop() || is_search() ) {
			return $url;
		}

		$shop_id = (int) get_option( 'woocommerce_shop_page_id' );
		$tr_id   = $shop_id > 0 ? Strope()->model->post->get( $shop_id, $lang ) : 0;

		if ( ! $tr_id || 'publish' !== get_post_status( $tr_id ) ) {
			return $url;
		}

		$permalink = get_permalink( $tr_id );

		if ( $permalink && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $shop_id ) {
			return $url; // The shop is the front page, already handled.
		}

		return $permalink ? $permalink : $url;
	}
}
