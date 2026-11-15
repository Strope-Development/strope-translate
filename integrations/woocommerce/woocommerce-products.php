<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Copies and synchronizes the products data (prices, stock, SKU, variations...) across translations.
 *
 * @since 4.1.0
 */
class Strope_WooCommerce_Products {
	/**
	 * Default list of the product properties synchronized across translations.
	 * Translatable texts (title, descriptions, purchase note...) are not synchronized.
	 *
	 * @var string[]
	 */
	const PRODUCT_PROPS = array(
		'sku',
		'regular_price',
		'sale_price',
		'date_on_sale_from',
		'date_on_sale_to',
		'manage_stock',
		'stock_quantity',
		'stock_status',
		'backorders',
		'low_stock_amount',
		'weight',
		'length',
		'width',
		'height',
		'tax_status',
		'tax_class',
		'shipping_class_id',
		'virtual',
		'downloadable',
		'downloads',
		'download_limit',
		'download_expiry',
		'sold_individually',
		'upsell_ids',
		'cross_sell_ids',
		'children',
		'image_id',
		'gallery_image_ids',
		'menu_order',
		'featured',
		'catalog_visibility',
		'attributes',
		'default_attributes',
	);

	/**
	 * Default list of the variation properties synchronized across translations.
	 *
	 * @var string[]
	 */
	const VARIATION_PROPS = array(
		'sku',
		'status',
		'regular_price',
		'sale_price',
		'date_on_sale_from',
		'date_on_sale_to',
		'manage_stock',
		'stock_quantity',
		'stock_status',
		'backorders',
		'low_stock_amount',
		'weight',
		'length',
		'width',
		'height',
		'tax_class',
		'shipping_class_id',
		'virtual',
		'downloadable',
		'downloads',
		'download_limit',
		'download_expiry',
		'image_id',
		'menu_order',
		'attributes',
	);

	/**
	 * Product metas copied when a translation is created.
	 *
	 * @var string[]
	 */
	const COPIED_METAS = array(
		'_sku',
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		'_price',
		'_tax_status',
		'_tax_class',
		'_manage_stock',
		'_stock',
		'_stock_status',
		'_backorders',
		'_low_stock_amount',
		'_sold_individually',
		'_weight',
		'_length',
		'_width',
		'_height',
		'_upsell_ids',
		'_crosssell_ids',
		'_children',
		'_product_image_gallery',
		'_virtual',
		'_downloadable',
		'_downloadable_files',
		'_download_limit',
		'_download_expiry',
		'_product_attributes',
		'_default_attributes',
		'_purchase_note',
		'_product_url',
		'_button_text',
		'_variation_description',
		'_product_version',
	);

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
	 * Counter preventing loops while synchronizing.
	 *
	 * @var int
	 */
	protected $syncing = 0;

	/**
	 * Counter of stock updates in progress.
	 *
	 * @var int
	 */
	protected $stock_updating = 0;

	/**
	 * Stock changes made directly in the database, with product ids as keys.
	 *
	 * @var array
	 */
	protected $stock_changes = array();

	/**
	 * Ids of the products being deleted.
	 *
	 * @var bool[]
	 */
	protected $deleting = array();

	/**
	 * Languages of the products being saved.
	 *
	 * @var array
	 */
	protected $saving = array();

	/**
	 * Language of the product whose attributes lookup data are being generated.
	 *
	 * @var Strope_Language|null|false
	 */
	protected $lookup_language;

	/**
	 * Whether the attributes lookup data are being regenerated.
	 *
	 * @var bool
	 */
	protected $lookup_regeneration = false;

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

		// Copy when creating a translation.
		add_filter( 'strope_copy_post_metas', array( $this, 'copy_post_metas' ), 10, 3 );
		add_filter( 'strope_translate_post_meta', array( $this, 'translate_post_meta' ), 10, 4 );
		add_filter( 'strope_copy_taxonomies', array( $this, 'copy_taxonomies' ), 10, 3 );
		add_filter( 'use_block_editor_for_post', array( $this, 'new_product_translation' ), 5010 ); // After the copy of metas and terms.
		add_action( 'strope_created_sync_post', array( $this, 'created_sync_post' ), 10, 3 );

		// Synchronization.
		add_action( 'woocommerce_new_product', array( $this, 'product_saved' ), 20 );
		add_action( 'woocommerce_update_product', array( $this, 'product_saved' ), 20 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'variation_saved' ), 20 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'variation_saved' ), 20 );
		add_action( 'before_delete_post', array( $this, 'before_delete_post' ), 5 );
		add_action( 'update_post_meta', array( $this, 'before_attributes_update' ), 10, 4 );

		// Stock.
		add_action( 'woocommerce_product_before_set_stock', array( $this, 'before_set_stock' ) );
		add_action( 'woocommerce_variation_before_set_stock', array( $this, 'before_set_stock' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'set_stock' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'set_stock' ) );
		add_filter( 'woocommerce_update_product_stock_query', array( $this, 'record_stock_change' ), 10, 4 );

		// SKU.
		add_filter( 'wc_product_pre_has_unique_sku', array( $this, 'has_unique_sku' ), 10, 3 );
		add_filter( 'woocommerce_get_product_id_by_sku', array( $this, 'get_product_id_by_sku' ) );

		// Terms queried while saving a product (e.g. to generate a variation title) must be in the product language.
		add_action( 'woocommerce_before_product_object_save', array( $this, 'before_object_save' ) );
		add_action( 'woocommerce_before_product_variation_object_save', array( $this, 'before_object_save' ) );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'after_object_save' ) );
		add_action( 'woocommerce_after_product_variation_object_save', array( $this, 'after_object_save' ) );
		add_action( 'woocommerce_run_product_attribute_lookup_update_callback', array( $this, 'before_lookup_update' ), 1 );
		add_action( 'woocommerce_run_product_attribute_lookup_update_callback', array( $this, 'after_lookup_update' ), 100 );
		add_action( 'woocommerce_run_product_attribute_lookup_regeneration_callback', array( $this, 'before_lookup_regeneration' ), 1 );
		add_action( 'woocommerce_run_product_attribute_lookup_regeneration_callback', array( $this, 'after_lookup_update' ), 100 );
		add_action( 'woocommerce_product_read', array( $this, 'product_read' ) );
		add_filter( 'get_terms_args', array( $this, 'get_terms_args' ), 5, 2 );
		add_filter( 'get_terms_args', array( $this, 'force_terms_language' ), 20 );

		// Coupons restrictions apply to all the translations of the products and categories.
		add_filter( 'woocommerce_coupon_get_product_ids', array( $this, 'coupon_product_ids' ) );
		add_filter( 'woocommerce_coupon_get_excluded_product_ids', array( $this, 'coupon_product_ids' ) );
		add_filter( 'woocommerce_coupon_get_product_categories', array( $this, 'coupon_categories' ) );
		add_filter( 'woocommerce_coupon_get_excluded_product_categories', array( $this, 'coupon_categories' ) );

		// Variations queries and hierarchy.
		add_action( 'parse_query', array( $this, 'parse_query' ), 1 );
		add_filter( 'wp_insert_post_parent', array( $this, 'keep_variation_parent' ), 20, 4 );
	}

	/**
	 * Tells whether products are translated.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	protected function are_products_translated() {
		return $this->model->is_translated_post_type( 'product' );
	}

	/**
	 * Tells whether the current user is allowed to synchronize a product (or variation) with its translations.
	 * Always true for requests without a logged-in user and for unrestricted users.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Product or variation id.
	 * @return bool
	 */
	protected function current_user_can_synchronize( $post_id ) {
		return $this->model->post->current_user_can_synchronize( (int) $post_id );
	}

	/**
	 * Returns the translations of a post, except the post itself.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post id.
	 * @return int[] Translations ids with language slugs as keys.
	 */
	protected function get_other_translations( $post_id ) {
		$translations = $this->model->post->get_translations( $post_id );
		$translations = array_map( 'intval', $translations );
		return array_filter(
			$translations,
			function ( $tr_id ) use ( $post_id ) {
				return $tr_id !== (int) $post_id;
			}
		);
	}

	/*
	 * Copy when creating a translation.
	 */

	/**
	 * Adds the WooCommerce metas to copy when a product translation is created.
	 * The synchronization is done separately through the WooCommerce API.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $keys List of meta keys.
	 * @param bool     $sync True for a synchronization, false for a copy.
	 * @param int      $from Id of the source post.
	 * @return string[]
	 */
	public function copy_post_metas( $keys, $sync, $from ) {
		if ( $sync || ! in_array( get_post_type( $from ), array( 'product', 'product_variation' ), true ) ) {
			return $keys;
		}

		/**
		 * Filters the product metas copied when a product translation is created.
		 *
		 * @since 4.1.0
		 *
		 * @param string[] $metas List of meta keys.
		 * @param int      $from  Id of the source product.
		 */
		$metas = (array) apply_filters( 'strope_wc_copied_product_metas', self::COPIED_METAS, $from );

		return array_merge( $keys, $metas );
	}

	/**
	 * Translates the ids stored in product metas when they are copied.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed  $value Meta value.
	 * @param string $key   Meta key.
	 * @param string $lang  Target language slug.
	 * @param int    $from  Id of the source post.
	 * @return mixed
	 */
	public function translate_post_meta( $value, $key, $lang, $from = 0 ) {
		$language = $this->model->get_language( $lang );

		if ( empty( $language ) ) {
			return $value;
		}

		switch ( $key ) {
			case '_upsell_ids':
			case '_crosssell_ids':
				return is_array( $value ) ? $this->translate_post_ids( $value, $language, false ) : $value;

			case '_children':
				return is_array( $value ) ? $this->translate_post_ids( $value, $language, true ) : $value;

			case '_product_image_gallery':
				if ( ! is_string( $value ) || '' === $value ) {
					return $value;
				}
				return implode( ',', array_map( array( $this, 'translate_media_id' ), wp_parse_id_list( $value ), array_fill( 0, count( wp_parse_id_list( $value ) ), $language ) ) );

			case '_default_attributes':
				if ( ! is_array( $value ) ) {
					return $value;
				}
				$from_language = $from ? $this->model->post->get_language( (int) $from ) : false;
				foreach ( $value as $taxonomy => $slug ) {
					if ( is_string( $slug ) && '' !== $slug && taxonomy_exists( $taxonomy ) ) {
						$value[ $taxonomy ] = $this->translate_term_slug( $slug, $taxonomy, $language, $from_language ? $from_language : null );
					}
				}
				return $value;
		}

		return $value;
	}

	/**
	 * Adds the product type and visibility to the taxonomies copied when a product translation is created.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $taxonomies List of taxonomy names.
	 * @param bool     $sync       True for a synchronization, false for a copy.
	 * @param int      $from       Id of the source post.
	 * @return string[]
	 */
	public function copy_taxonomies( $taxonomies, $sync, $from ) {
		if ( ! $sync && 'product' === get_post_type( $from ) ) {
			$taxonomies = array_merge( $taxonomies, array( 'product_type', 'product_visibility', 'product_shipping_class' ) );
		}
		return $taxonomies;
	}

	/**
	 * Synchronizes the product data (and creates the variations) when a product translation
	 * is created from the admin ("Add new translation").
	 *
	 * @since 4.1.0
	 *
	 * @param bool $is_block_editor Whether the post can be edited with the block editor.
	 * @return bool Unmodified value.
	 */
	public function new_product_translation( $is_block_editor ) {
		global $post;
		static $done = array();

		if ( empty( $post ) || 'product' !== $post->post_type || ! isset( $GLOBALS['pagenow'], $_GET['from_post'], $_GET['new_lang'] ) || 'post-new.php' !== $GLOBALS['pagenow'] ) {
			return $is_block_editor;
		}

		check_admin_referer( 'new-post-translation' );

		$from_id  = (int) $_GET['from_post'];
		$language = $this->model->get_language( sanitize_key( $_GET['new_lang'] ) );

		// Only the new translation is modified: reading the source is enough.
		if ( ! empty( $done[ $post->ID ] ) || empty( $language ) || ! strope_current_user_can_copy_post( $from_id ) || ! current_user_can( 'edit_post', $post->ID ) || ! $this->are_products_translated() ) {
			return $is_block_editor;
		}

		$done[ $post->ID ] = true;

		$product = wc_get_product( $from_id );

		if ( $product && ! $product->is_type( 'variation' ) ) {
			++$this->syncing;
			try {
				$this->sync_product_to( $product, $post->ID, $language );
			} finally {
				--$this->syncing;
			}
		}

		return $is_block_editor;
	}

	/**
	 * Synchronizes the product data when a translation is created by the posts synchronization.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $post_id Id of the source post.
	 * @param int    $tr_id   Id of the new translation.
	 * @param string $lang    Language slug of the new translation.
	 * @return void
	 */
	public function created_sync_post( $post_id, $tr_id, $lang ) {
		$language = $this->model->get_language( $lang );

		if ( empty( $language ) || 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( $product ) {
			++$this->syncing;
			try {
				$this->sync_product_to( $product, (int) $tr_id, $language );
			} finally {
				--$this->syncing;
			}
		}
	}

	/*
	 * Synchronization.
	 */

	/**
	 * Synchronizes a product when it is saved.
	 *
	 * @since 4.1.0
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function product_saved( $product_id ) {
		if ( $this->syncing > 0 || $this->stock_updating > 0 ) {
			return;
		}

		// Don't modify translations the current user is not allowed to edit.
		if ( ! $this->current_user_can_synchronize( (int) $product_id ) ) {
			return;
		}

		$this->sync_product( (int) $product_id );
	}

	/**
	 * Synchronizes the data of a product to all its translations.
	 *
	 * @since 4.1.0
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function sync_product( $product_id ) {
		if ( ! $this->are_products_translated() ) {
			return;
		}

		$product  = wc_get_product( $product_id );
		$language = $this->model->post->get_language( $product_id );

		if ( ! $product || $product->is_type( 'variation' ) || empty( $language ) || 'auto-draft' === $product->get_status( 'edit' ) ) {
			return;
		}

		$translations = $this->get_other_translations( $product_id );

		if ( empty( $translations ) ) {
			return;
		}

		++$this->syncing;

		try {
			// A new translation of a variable product may not have variations yet: get them from another translation.
			if ( $product->is_type( 'variable' ) && ! $this->get_variation_ids( $product_id ) ) {
				foreach ( $translations as $tr_id ) {
					$tr_product = wc_get_product( $tr_id );
					if ( $tr_product && $tr_product->is_type( 'variable' ) && $this->get_variation_ids( $tr_id ) ) {
						$this->sync_variations( $tr_product, $product, $language );
						$product = wc_get_product( $product_id );
						break;
					}
				}
			}

			foreach ( $translations as $lang => $tr_id ) {
				$tr_language = $this->model->get_language( $lang );
				if ( $product && $tr_language ) {
					$this->sync_product_to( $product, $tr_id, $tr_language );
				}
			}
		} finally {
			--$this->syncing;
		}
	}

	/**
	 * Copies the synchronized data of a product to a translation.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Product      $product  Source product.
	 * @param int             $tr_id    Translated product id.
	 * @param Strope_Language $language Language of the translated product.
	 * @return void
	 */
	public function sync_product_to( WC_Product $product, $tr_id, Strope_Language $language ) {
		$tr_product = wc_get_product( $tr_id );

		if ( ! $tr_product || $tr_product->is_type( 'variation' ) ) {
			return;
		}

		// Product type.
		if ( $tr_product->get_type() !== $product->get_type() ) {
			$classname = WC_Product_Factory::get_product_classname( $tr_id, $product->get_type() );
			if ( $classname && class_exists( $classname ) ) {
				$tr_product = new $classname( $tr_id );
			}
		}

		/**
		 * Filters the product properties synchronized across translations.
		 *
		 * @since 4.1.0
		 *
		 * @param string[]        $props    Product properties (as used by WooCommerce getters and setters).
		 * @param WC_Product      $product  Source product.
		 * @param Strope_Language $language Target language.
		 */
		$keys = (array) apply_filters( 'strope_wc_product_props_to_sync', self::PRODUCT_PROPS, $product, $language );

		$props = array();
		foreach ( $keys as $key ) {
			if ( 'default_attributes' === $key || ( 'children' === $key && ! $product->is_type( 'grouped' ) ) || $this->skip_stock_quantity( $key, $tr_product ) ) {
				continue;
			}

			if ( 'attributes' === $key ) {
				$props['attributes'] = $this->translate_product_attributes( $product, $tr_product, $language );
				continue;
			}

			if ( is_callable( array( $product, "get_{$key}" ) ) && is_callable( array( $tr_product, "set_{$key}" ) ) ) {
				$props[ $key ] = $this->translate_prop( $key, $product->{"get_{$key}"}( 'edit' ), $language );
			}
		}

		$tr_product->set_props( $props );

		if ( in_array( 'default_attributes', $keys, true ) ) {
			$map      = $this->get_custom_attributes_map( $product, $tr_product );
			$defaults = $this->translate_variation_attributes( $product->get_default_attributes( 'edit' ), $language, $map, $this->model->post->get_language( $product->get_id() ) );
			$tr_product->set_props( array( 'default_attributes' => $defaults ) );
		}

		$tr_product->save();

		if ( $product->is_type( 'variable' ) && $tr_product->is_type( 'variable' ) ) {
			$this->sync_variations( $product, $tr_product, $language );
		}

		/**
		 * Fires after a product has been synchronized to a translation.
		 *
		 * @since 4.1.0
		 *
		 * @param WC_Product      $tr_product Translated product.
		 * @param WC_Product      $product    Source product.
		 * @param Strope_Language $language   Language of the translated product.
		 */
		do_action( 'strope_wc_product_synchronized', $tr_product, $product, $language );
	}

	/**
	 * Tells whether the stock quantity must not be copied to a translation when synchronizing products.
	 * Once a translation manages its stock, stock changes are propagated as they happen (see `set_stock()`),
	 * so that a product save does not override a concurrent stock change (e.g. an order in another language).
	 *
	 * @since 4.1.0
	 *
	 * @param string     $key        Property name.
	 * @param WC_Product $tr_product Translated product or variation.
	 * @return bool
	 */
	protected function skip_stock_quantity( $key, WC_Product $tr_product ) {
		return 'stock_quantity' === $key && $tr_product->get_id() && $tr_product->get_manage_stock( 'edit' );
	}

	/**
	 * Returns the ids of the variations of a variable product, whatever the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param int $product_id Parent product id.
	 * @return int[]
	 */
	protected function get_variation_ids( $product_id ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_parent'      => $product_id,
					'post_type'        => 'product_variation',
					'post_status'      => array( 'publish', 'private' ),
					'orderby'          => array(
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
					),
					'fields'           => 'ids',
					'numberposts'      => -1,
					'lang'             => '',
					'suppress_filters' => false, // Fires `posts_selection` to clean the terms language set by the query.
				)
			)
		);
	}

	/**
	 * Creates or synchronizes the variations of a translated variable product.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Product      $product    Source variable product.
	 * @param WC_Product      $tr_product Translated variable product.
	 * @param Strope_Language $language   Language of the translated product.
	 * @return void
	 */
	protected function sync_variations( WC_Product $product, WC_Product $tr_product, Strope_Language $language ) {
		$from_language = $this->model->post->get_language( $product->get_id() );

		if ( empty( $from_language ) ) {
			return;
		}

		$map        = $this->get_custom_attributes_map( $product, $tr_product );
		$variations = $this->get_variation_ids( $product->get_id() );
		$existing   = $this->get_variation_ids( $tr_product->get_id() );
		$kept       = array();

		// When two existing products are linked as translations, reuse the variations of the translated product.
		if ( ! empty( $variations ) && ! empty( $existing ) ) {
			$this->link_existing_variations( $variations, $existing, $language, $map, $from_language );
		}

		foreach ( $variations as $variation_id ) {
			$tr_variation_id = $this->sync_variation_to( $variation_id, $tr_product, $language, $map, $from_language );
			if ( $tr_variation_id ) {
				$kept[] = $tr_variation_id;
			}
		}

		/*
		 * The variations which have no counterpart in the source product are disabled rather than deleted,
		 * as they may still be referenced by orders, carts or stock reports. They can be matched again later.
		 */
		if ( ! empty( $variations ) ) {
			foreach ( array_diff( $existing, $kept ) as $orphan_id ) {
				$orphan = wc_get_product( $orphan_id );
				if ( $orphan instanceof WC_Product_Variation && 'private' !== $orphan->get_status( 'edit' ) ) {
					$orphan->set_status( 'private' );
					$orphan->save();
				}
			}
		}

		WC_Product_Variable::sync( $tr_product->get_id() );
		wc_delete_product_transients( $tr_product->get_id() );
	}

	/**
	 * Links the existing variations of a translated product to the source variations they correspond to,
	 * so that they are synchronized instead of being replaced by new variations.
	 * Variations are matched by their (translated) attributes, then by SKU.
	 *
	 * @since 4.1.0
	 *
	 * @param int[]           $variations    Ids of the variations of the source product.
	 * @param int[]           $existing      Ids of the variations of the translated product.
	 * @param Strope_Language $language      Language of the translated product.
	 * @param array           $map           Map of the custom attributes, see `get_custom_attributes_map()`.
	 * @param Strope_Language $from_language Language of the source product.
	 * @return void
	 */
	protected function link_existing_variations( array $variations, array $existing, Strope_Language $language, array $map, Strope_Language $from_language ) {
		// Variations of the translated product not yet linked to a variation in the source language.
		$candidates = array();
		foreach ( $existing as $tr_variation_id ) {
			if ( ! $this->model->post->get_translation( $tr_variation_id, $from_language ) ) {
				$tr_variation = wc_get_product( $tr_variation_id );
				if ( $tr_variation instanceof WC_Product_Variation ) {
					$candidates[ $tr_variation_id ] = $tr_variation;
				}
			}
		}

		if ( empty( $candidates ) ) {
			return;
		}

		// Source variations without a translation in the translated product.
		$sources = array();
		foreach ( $variations as $variation_id ) {
			$tr_variation_id = (int) $this->model->post->get_translation( $variation_id, $language );
			if ( $tr_variation_id && in_array( $tr_variation_id, $existing, true ) ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product_Variation ) {
				$sources[ $variation_id ] = array(
					'sku'        => (string) $variation->get_sku( 'edit' ),
					'attributes' => $this->translate_variation_attributes( $variation->get_attributes( 'edit' ), $language, $map, $from_language ),
				);
			}
		}

		foreach ( array( 'attributes', 'sku' ) as $criterion ) {
			foreach ( $sources as $variation_id => $source ) {
				foreach ( $candidates as $tr_variation_id => $tr_variation ) {
					if ( 'attributes' === $criterion ) {
						$match = $this->variation_attributes_match( $source['attributes'], $tr_variation->get_attributes( 'edit' ) );
					} else {
						$match = '' !== $source['sku'] && $source['sku'] === (string) $tr_variation->get_sku( 'edit' );
					}

					if ( $match && $this->link_variation( $variation_id, $tr_variation_id, $language, $from_language ) ) {
						unset( $sources[ $variation_id ], $candidates[ $tr_variation_id ] );
						break;
					}
				}
			}
		}
	}

	/**
	 * Tells whether two sets of variation attributes are identical.
	 *
	 * @since 4.1.0
	 *
	 * @param array $expected Expected attributes, with attribute keys as keys.
	 * @param array $actual   Actual attributes, with attribute keys as keys.
	 * @return bool
	 */
	protected function variation_attributes_match( array $expected, array $actual ) {
		$normalize = function ( array $attributes ) {
			$normalized = array();
			foreach ( $attributes as $key => $value ) {
				$normalized[ sanitize_title( (string) $key ) ] = sanitize_title( (string) $value );
			}
			ksort( $normalized );
			return $normalized;
		};

		return ! empty( $expected ) && $normalize( $expected ) === $normalize( $actual );
	}

	/**
	 * Links an existing variation of a translated product as the translation of a source variation.
	 *
	 * @since 4.1.0
	 *
	 * @param int             $variation_id    Source variation id.
	 * @param int             $tr_variation_id Id of the variation of the translated product.
	 * @param Strope_Language $language        Language of the translated product.
	 * @param Strope_Language $from_language   Language of the source variation.
	 * @return bool True if the variations have been linked.
	 */
	protected function link_variation( $variation_id, $tr_variation_id, Strope_Language $language, Strope_Language $from_language ) {
		$translations    = $this->model->post->get_translations( $variation_id );
		$tr_translations = $this->get_other_translations( $tr_variation_id );

		// Don't break a translation group in another language.
		foreach ( $tr_translations as $lang => $id ) {
			if ( isset( $translations[ $lang ] ) && (int) $translations[ $lang ] !== $id ) {
				return false;
			}
		}

		$this->model->post->set_language( $tr_variation_id, $language );
		$translations = array_merge(
			$tr_translations,
			$translations,
			array(
				$from_language->slug => (int) $variation_id,
				$language->slug      => (int) $tr_variation_id,
			)
		);
		$this->model->post->save_translations( $variation_id, $translations );

		return true;
	}

	/**
	 * Creates or synchronizes the translation of a variation.
	 *
	 * @since 4.1.0
	 *
	 * @param int             $variation_id  Source variation id.
	 * @param WC_Product      $tr_product    Translated parent product.
	 * @param Strope_Language $language      Target language.
	 * @param array           $map           Map of the custom attributes, see `get_custom_attributes_map()`.
	 * @param Strope_Language $from_language Language of the source variation.
	 * @return int Translated variation id, 0 on failure.
	 */
	protected function sync_variation_to( $variation_id, WC_Product $tr_product, Strope_Language $language, array $map, Strope_Language $from_language ) {
		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return 0;
		}

		$tr_variation_id = (int) $this->model->post->get_translation( $variation_id, $language );

		// Don't use a translation which belongs to another product.
		if ( $tr_variation_id && wp_get_post_parent_id( $tr_variation_id ) !== $tr_product->get_id() ) {
			$tr_variation_id = 0;
		}

		$tr_variation = $tr_variation_id ? wc_get_product( $tr_variation_id ) : null;

		if ( ! $tr_variation instanceof WC_Product_Variation ) {
			$tr_variation = new WC_Product_Variation();
			$tr_variation->set_parent_id( $tr_product->get_id() );
			$tr_variation->set_description( $variation->get_description( 'edit' ) ); // Translatable, copied only once.
			$tr_variation->set_status( $variation->get_status( 'edit' ) );
			$tr_variation->save();

			if ( ! $tr_variation->get_id() ) {
				return 0;
			}

			$this->model->post->set_language( $tr_variation->get_id(), $language );
			$translations = array_merge(
				$this->model->post->get_translations( $variation_id ),
				array(
					$from_language->slug => (int) $variation_id,
					$language->slug      => $tr_variation->get_id(),
				)
			);
			$this->model->post->save_translations( $variation_id, $translations );
		}

		/**
		 * Filters the variation properties synchronized across translations.
		 *
		 * @since 4.1.0
		 *
		 * @param string[]             $props     Variation properties (as used by WooCommerce getters and setters).
		 * @param WC_Product_Variation $variation Source variation.
		 * @param Strope_Language      $language  Target language.
		 */
		$keys = (array) apply_filters( 'strope_wc_variation_props_to_sync', self::VARIATION_PROPS, $variation, $language );

		$props = array();
		foreach ( $keys as $key ) {
			if ( $this->skip_stock_quantity( $key, $tr_variation ) ) {
				continue;
			}

			if ( 'attributes' === $key ) {
				$props['attributes'] = $this->translate_variation_attributes( $variation->get_attributes( 'edit' ), $language, $map, $from_language );
				continue;
			}

			if ( is_callable( array( $variation, "get_{$key}" ) ) && is_callable( array( $tr_variation, "set_{$key}" ) ) ) {
				$props[ $key ] = $this->translate_prop( $key, $variation->{"get_{$key}"}( 'edit' ), $language );
			}
		}

		$tr_variation->set_props( $props );
		$tr_variation->save();

		return $tr_variation->get_id();
	}

	/**
	 * Synchronizes a variation when it is saved.
	 *
	 * @since 4.1.0
	 *
	 * @param int $variation_id Variation id.
	 * @return void
	 */
	public function variation_saved( $variation_id ) {
		if ( $this->syncing > 0 || $this->stock_updating > 0 || ! $this->are_products_translated() ) {
			return;
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return;
		}

		$parent_id       = $variation->get_parent_id();
		$parent_language = $parent_id ? $this->model->post->get_language( $parent_id ) : false;

		if ( empty( $parent_language ) ) {
			return;
		}

		// A variation is always in the language of its parent.
		$language = $this->model->post->get_language( $variation_id );
		if ( empty( $language ) || $language->slug !== $parent_language->slug ) {
			$this->model->post->set_language( $variation_id, $parent_language );
		}

		// Don't modify translations the current user is not allowed to edit.
		if ( ! $this->current_user_can_synchronize( $parent_id ) ) {
			return;
		}

		$translations = $this->get_other_translations( $parent_id );
		$parent       = wc_get_product( $parent_id );

		if ( empty( $translations ) || ! $parent ) {
			return;
		}

		++$this->syncing;

		try {
			foreach ( $translations as $lang => $tr_parent_id ) {
				$tr_parent   = wc_get_product( $tr_parent_id );
				$tr_language = $this->model->get_language( $lang );

				if ( ! $tr_parent || ! $tr_parent->is_type( 'variable' ) || ! $tr_language ) {
					continue;
				}

				$map = $this->get_custom_attributes_map( $parent, $tr_parent );

				// Reuse a matching variation of the translated product, rather than creating a new one.
				$this->link_existing_variations( array( (int) $variation_id ), $this->get_variation_ids( $tr_parent_id ), $tr_language, $map, $parent_language );
				$this->sync_variation_to( (int) $variation_id, $tr_parent, $tr_language, $map, $parent_language );
				WC_Product_Variable::sync( $tr_parent_id );
				wc_delete_product_transients( $tr_parent_id );
			}
		} finally {
			--$this->syncing;
		}
	}

	/**
	 * Deletes the translations of a variation when it is deleted.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Id of the post being deleted.
	 * @return void
	 */
	public function before_delete_post( $post_id ) {
		$post_type = get_post_type( $post_id );

		if ( 'product' === $post_type ) {
			$this->deleting[ (int) $post_id ] = true; // Its variations will be deleted, but not their translations.
			return;
		}

		if ( 'product_variation' !== $post_type || $this->syncing > 0 || ! $this->are_products_translated() ) {
			return;
		}

		$parent_id = wp_get_post_parent_id( $post_id );

		if ( ! $parent_id || ! empty( $this->deleting[ $parent_id ] ) || 'trash' === get_post_status( $parent_id ) ) {
			return;
		}

		// Don't delete translations the current user is not allowed to edit.
		if ( ! $this->current_user_can_synchronize( (int) $post_id ) ) {
			return;
		}

		$translations = $this->get_other_translations( $post_id );

		if ( empty( $translations ) ) {
			return;
		}

		++$this->syncing;

		try {
			foreach ( $translations as $tr_id ) {
				$tr_variation = wc_get_product( $tr_id );
				if ( $tr_variation instanceof WC_Product_Variation ) {
					$tr_parent_id = $tr_variation->get_parent_id();
					$tr_variation->delete( true );
					if ( $tr_parent_id ) {
						WC_Product_Variable::sync( $tr_parent_id );
						wc_delete_product_transients( $tr_parent_id );
					}
				}
			}
		} finally {
			--$this->syncing;
		}
	}

	/**
	 * Updates the variations when custom attributes are renamed or their values modified
	 * (typically when they are translated), so that variations keep their attributes values.
	 * Custom attributes and their values are matched by position.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $meta_id    Meta id.
	 * @param int    $object_id  Post id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 * @return void
	 */
	public function before_attributes_update( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( '_product_attributes' !== $meta_key || ! is_array( $meta_value ) || 'product' !== get_post_type( $object_id ) ) {
			return;
		}

		$old_value = get_post_meta( $object_id, '_product_attributes', true );

		if ( ! is_array( $old_value ) ) {
			return;
		}

		$old_custom = $this->get_raw_custom_attributes( $old_value );
		$new_custom = $this->get_raw_custom_attributes( $meta_value );
		$changes    = array();

		foreach ( $old_custom as $i => $old ) {
			if ( ! isset( $new_custom[ $i ] ) ) {
				break;
			}

			$new = $new_custom[ $i ];
			if ( $old['key'] !== $new['key'] || $old['values'] !== $new['values'] ) {
				$changes[] = array(
					'old' => $old,
					'new' => $new,
				);
			}
		}

		if ( empty( $changes ) ) {
			return;
		}

		foreach ( $this->get_variation_ids( (int) $object_id ) as $variation_id ) {
			foreach ( $changes as $change ) {
				$old_meta = 'attribute_' . $change['old']['key'];

				if ( ! metadata_exists( 'post', $variation_id, $old_meta ) ) {
					continue;
				}

				$value     = (string) get_post_meta( $variation_id, $old_meta, true );
				$new_value = $value;

				foreach ( $change['old']['values'] as $n => $option ) {
					if ( '' !== $value && ( 0 === strcasecmp( $option, $value ) || sanitize_title( $option ) === sanitize_title( $value ) ) ) {
						$new_value = isset( $change['new']['values'][ $n ] ) ? $change['new']['values'][ $n ] : $value;
						break;
					}
				}

				if ( $change['old']['key'] !== $change['new']['key'] ) {
					delete_post_meta( $variation_id, $old_meta );
				}
				update_post_meta( $variation_id, 'attribute_' . $change['new']['key'], wp_slash( $new_value ) );
			}

			clean_post_cache( $variation_id );
		}

		wc_delete_product_transients( (int) $object_id );
	}

	/**
	 * Returns the custom attributes stored in the `_product_attributes` meta, in their order.
	 *
	 * @since 4.1.0
	 *
	 * @param array $attributes Raw attributes.
	 * @return array List of arrays with 'key' and 'values'.
	 */
	protected function get_raw_custom_attributes( array $attributes ) {
		$custom = array();

		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) || ! isset( $attribute['name'] ) ) {
				continue;
			}

			$custom[] = array(
				'key'    => sanitize_title( (string) $attribute['name'] ),
				'values' => isset( $attribute['value'] ) ? array_values( wc_get_text_attributes( (string) $attribute['value'] ) ) : array(),
			);
		}

		return $custom;
	}

	/*
	 * Stock.
	 */

	/**
	 * Notices that a stock update is in progress.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function before_set_stock() {
		++$this->stock_updating;
	}

	/**
	 * Records the stock changes made directly in the database by `wc_update_product_stock()`
	 * (orders, refunds, stock edits...), to propagate the same change to the translations.
	 *
	 * @since 4.1.0
	 *
	 * @param string    $sql        Stock update query.
	 * @param int       $product_id Id of the product whose stock is updated.
	 * @param int|float $new_stock  New stock quantity (computed by WooCommerce).
	 * @param string    $operation  'set', 'increase' or 'decrease'.
	 * @return string Unmodified query.
	 */
	public function record_stock_change( $sql, $product_id, $new_stock, $operation ) {
		global $wpdb;

		if ( $this->syncing > 0 ) {
			return $sql; // Our own propagation.
		}

		$change = array(
			'operation' => in_array( $operation, array( 'increase', 'decrease' ), true ) ? $operation : 'set',
			'amount'    => (float) $new_stock,
		);

		if ( 'set' !== $change['operation'] ) {
			// The amount of a relative change is in the query: `meta_value = meta_value -2.000000`.
			if ( is_string( $sql ) && preg_match( '/meta_value\s*=\s*meta_value\s*([+-]\s*[0-9.]+)/', $sql, $matches ) ) {
				$change['amount'] = abs( (float) str_replace( ' ', '', $matches[1] ) );
			} else {
				$current          = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'", $product_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$change['amount'] = abs( (float) $new_stock - (float) $current );
			}
		}

		$this->stock_changes[ (int) $product_id ] = $change;

		return $sql;
	}

	/**
	 * Propagates a stock quantity change to the translations.
	 * Relative changes (orders, refunds) are applied as relative changes to each translation, so that concurrent
	 * changes in several languages are not lost. Stock quantities set by a user are copied to the translations.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Product $product Product (or variation) whose stock has been modified.
	 * @return void
	 */
	public function set_stock( $product ) {
		if ( $this->stock_updating > 0 ) {
			--$this->stock_updating;
		}

		if ( $this->syncing > 0 || ! $product instanceof WC_Product || ! $this->are_products_translated() ) {
			return;
		}

		$product_id = $product->get_id();
		$change     = isset( $this->stock_changes[ $product_id ] ) ? $this->stock_changes[ $product_id ] : null;
		unset( $this->stock_changes[ $product_id ] );

		if ( null === $change || 'set' === $change['operation'] ) {
			// Stock quantity set by a user: only when the user is allowed to edit the translations.
			if ( ! $this->current_user_can_synchronize( $product_id ) ) {
				return;
			}

			$quantity = $product->get_stock_quantity();

			if ( null === $quantity ) {
				return;
			}

			$operation = 'set';
			$amount    = $quantity;
		} else {
			/*
			 * Relative change of the shared stock, typically due to an order: always propagated,
			 * except when made from the admin by a user who is not allowed to edit the translations.
			 */
			if ( Strope() instanceof Strope_Admin_Base && ! $this->current_user_can_synchronize( $product_id ) ) {
				return;
			}

			$operation = $change['operation'];
			$amount    = $change['amount'];

			if ( empty( $amount ) ) {
				return;
			}
		}

		$translations = $this->get_other_translations( $product_id );

		if ( empty( $translations ) ) {
			return;
		}

		++$this->syncing;

		try {
			foreach ( $translations as $tr_id ) {
				$tr_product = wc_get_product( $tr_id );

				if ( ! $tr_product || ! $tr_product->managing_stock() || $tr_product->get_stock_managed_by_id() !== $tr_product->get_id() ) {
					continue;
				}

				if ( 'set' === $operation && wc_stock_amount( $tr_product->get_stock_quantity() ) === wc_stock_amount( $amount ) ) {
					continue;
				}

				wc_update_product_stock( $tr_product, $amount, $operation );
			}
		} finally {
			--$this->syncing;
		}
	}

	/*
	 * SKU.
	 */

	/**
	 * Allows the translations of a product to share the same SKU.
	 *
	 * @since 4.1.0
	 *
	 * @param bool|null $has_unique_sku Null to let WooCommerce check the SKU.
	 * @param int       $product_id     Product id.
	 * @param string    $sku            SKU.
	 * @return bool|null
	 */
	public function has_unique_sku( $has_unique_sku, $product_id, $sku ) {
		global $wpdb;

		if ( null !== $has_unique_sku || empty( $product_id ) || '' === (string) $sku || ! $this->are_products_translated() ) {
			return $has_unique_sku;
		}

		$group = array_map( 'intval', $this->model->post->get_translations( (int) $product_id ) );

		if ( count( $group ) < 2 ) {
			return $has_unique_sku;
		}

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT posts.ID FROM {$wpdb->posts} AS posts
				INNER JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
				WHERE posts.post_type IN ( 'product', 'product_variation' )
				AND posts.post_status != 'trash'
				AND meta.meta_key = '_sku' AND meta.meta_value = %s
				AND posts.ID != %d",
				$sku,
				$product_id
			)
		);

		if ( empty( $ids ) ) {
			return $has_unique_sku; // Let WooCommerce do its own checks.
		}

		return array_diff( array_map( 'intval', $ids ), $group ) ? $has_unique_sku : true;
	}

	/**
	 * Returns the product in the current language when searching a product by SKU.
	 *
	 * @since 4.1.0
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	public function get_product_id_by_sku( $product_id ) {
		$language = $this->woocommerce->get_current_language();

		if ( empty( $product_id ) || empty( $language ) ) {
			return $product_id;
		}

		$tr_id = $this->model->post->get( (int) $product_id, $language );
		return $tr_id ? $tr_id : $product_id;
	}

	/*
	 * Coupons.
	 */

	/**
	 * Tells whether the current request is a request to the WooCommerce REST API (except the Store API),
	 * in which case the coupons data must be returned unmodified.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	protected function is_wc_rest_api_request() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		return false !== strpos( $uri, '/wc/' ) && false === strpos( $uri, '/wc/store' );
	}

	/**
	 * Adds the translations of the products selected in a coupon.
	 *
	 * @since 4.1.0
	 *
	 * @param int[] $ids Product ids.
	 * @return int[]
	 */
	public function coupon_product_ids( $ids ) {
		if ( empty( $ids ) || ! is_array( $ids ) || ! $this->are_products_translated() || $this->is_wc_rest_api_request() ) {
			return $ids;
		}

		$all = array();
		foreach ( $ids as $id ) {
			$all[] = (int) $id;
			$all   = array_merge( $all, array_map( 'intval', $this->model->post->get_translations( (int) $id ) ) );
		}
		return array_values( array_unique( $all ) );
	}

	/**
	 * Adds the translations of the product categories selected in a coupon.
	 *
	 * @since 4.1.0
	 *
	 * @param int[] $ids Term ids.
	 * @return int[]
	 */
	public function coupon_categories( $ids ) {
		if ( empty( $ids ) || ! is_array( $ids ) || ! $this->model->is_translated_taxonomy( 'product_cat' ) || $this->is_wc_rest_api_request() ) {
			return $ids;
		}

		$all = array();
		foreach ( $ids as $id ) {
			$all[] = (int) $id;
			$all   = array_merge( $all, array_map( 'intval', $this->model->term->get_translations( (int) $id ) ) );
		}
		return array_values( array_unique( $all ) );
	}

	/*
	 * Queries.
	 */

	/**
	 * Stores the language of a product being saved.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Product $product Product being saved.
	 * @return void
	 */
	public function before_object_save( $product ) {
		$language = null;

		if ( $product instanceof WC_Product ) {
			$id       = $product->get_id() ? $product->get_id() : $product->get_parent_id();
			$language = $id ? $this->model->post->get_language( $id ) : null;
		}

		$this->saving[] = $language ? $language : null;
	}

	/**
	 * Removes the language of a saved product.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function after_object_save() {
		array_pop( $this->saving );
	}

	/**
	 * Stores the language of a product before its attributes lookup data are updated.
	 *
	 * @since 4.1.0
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function before_lookup_update( $product_id ) {
		$this->lookup_language = $this->model->post->get_language( (int) $product_id );
	}

	/**
	 * Notices the start of the attributes lookup data regeneration.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function before_lookup_regeneration() {
		$this->lookup_regeneration = true;
	}

	/**
	 * Cleans the language after the attributes lookup data are updated.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function after_lookup_update() {
		$this->lookup_language     = null;
		$this->lookup_regeneration = false;
	}

	/**
	 * Stores the language of a product read during the attributes lookup data regeneration.
	 *
	 * @since 4.1.0
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function product_read( $product_id ) {
		if ( $this->lookup_regeneration ) {
			$this->lookup_language = $this->model->post->get_language( (int) $product_id );
		}
	}

	/**
	 * Queries the terms in the language of the product being saved (or whose attributes lookup data are generated).
	 *
	 * @since 4.1.0
	 *
	 * @param array    $args       Terms query arguments.
	 * @param string[] $taxonomies Queried taxonomies.
	 * @return array
	 */
	public function get_terms_args( $args, $taxonomies ) {
		if ( ( empty( $this->saving ) && empty( $this->lookup_language ) ) || isset( $args['lang'] ) || ! empty( $args['object_ids'] ) ) {
			return $args;
		}

		$language = empty( $this->saving ) ? $this->lookup_language : end( $this->saving );

		if ( $language instanceof Strope_Language && $this->model->is_translated_taxonomy( $taxonomies ) ) {
			$args['lang']           = $language->slug;
			$args['strope_wc_lang'] = $language->slug; // To restore the language if it is modified by another filter.
		}

		return $args;
	}

	/**
	 * Makes sure that the language set in `get_terms_args()` is not overridden.
	 *
	 * @since 4.1.0
	 *
	 * @param array $args Terms query arguments.
	 * @return array
	 */
	public function force_terms_language( $args ) {
		if ( isset( $args['strope_wc_lang'] ) ) {
			$args['lang'] = $args['strope_wc_lang'];
		}
		return $args;
	}

	/**
	 * Prevents filtering by language (and auto translating) the queries of the variations of a product.
	 * Variations are always in the language of their parent.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	public function parse_query( $query ) {
		$qv = &$query->query_vars;

		if ( isset( $qv['lang'] ) || empty( $qv['post_type'] ) || array( 'product_variation' ) !== array_values( (array) $qv['post_type'] ) ) {
			return;
		}

		if ( ! empty( $qv['post_parent'] ) || ! empty( $qv['post_parent__in'] ) || ! empty( $qv['post__in'] ) || ! empty( $qv['p'] ) ) {
			$qv['lang'] = '';
		}
	}

	/**
	 * Makes sure that a variation is never moved to another product.
	 *
	 * @since 4.1.0
	 *
	 * @param int   $post_parent Post parent id.
	 * @param int   $post_id     Post id.
	 * @param array $new_postarr Array of parsed post data.
	 * @param array $postarr     Array of sanitized, but otherwise unmodified post data.
	 * @return int
	 */
	public function keep_variation_parent( $post_parent, $post_id, $new_postarr, $postarr ) {
		if ( isset( $postarr['post_type'], $postarr['post_parent'] ) && 'product_variation' === $postarr['post_type'] ) {
			return (int) $postarr['post_parent'];
		}
		return $post_parent;
	}

	/*
	 * Helpers.
	 */

	/**
	 * Translates a product property.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $key      Property name.
	 * @param mixed           $value    Property value.
	 * @param Strope_Language $language Target language.
	 * @return mixed
	 */
	protected function translate_prop( $key, $value, Strope_Language $language ) {
		switch ( $key ) {
			case 'shipping_class_id':
				return $value ? $this->translate_term_id( (int) $value, 'product_shipping_class', $language, false ) : $value;

			case 'upsell_ids':
			case 'cross_sell_ids':
				return $this->translate_post_ids( (array) $value, $language, false );

			case 'children':
				return $this->translate_post_ids( (array) $value, $language, true );

			case 'image_id':
				return $value ? $this->translate_media_id( (int) $value, $language ) : $value;

			case 'gallery_image_ids':
				$ids = array();
				foreach ( (array) $value as $id ) {
					$ids[] = $this->translate_media_id( (int) $id, $language );
				}
				return $ids;
		}

		return $value;
	}

	/**
	 * Translates a list of post ids.
	 *
	 * @since 4.1.0
	 *
	 * @param int[]           $ids          Post ids.
	 * @param Strope_Language $language     Target language.
	 * @param bool            $keep_missing Whether to keep the posts without translation.
	 * @return int[]
	 */
	protected function translate_post_ids( array $ids, Strope_Language $language, $keep_missing ) {
		$tr_ids = array();
		foreach ( $ids as $id ) {
			$tr_id = $this->model->post->get( (int) $id, $language );
			if ( $tr_id ) {
				$tr_ids[] = $tr_id;
			} elseif ( $keep_missing || ! $this->model->post->get_language( (int) $id ) ) {
				$tr_ids[] = (int) $id;
			}
		}
		return array_values( array_unique( $tr_ids ) );
	}

	/**
	 * Translates a media id, creating the media translation if needed when media are translated.
	 *
	 * @since 4.1.0
	 *
	 * @param int             $id       Attachment id.
	 * @param Strope_Language $language Target language.
	 * @return int
	 */
	public function translate_media_id( $id, Strope_Language $language ) {
		if ( empty( $id ) || empty( Strope()->options['media_support'] ) || ! $this->model->post->get_language( $id ) ) {
			return (int) $id;
		}

		$tr_id = $this->model->post->get( $id, $language );

		if ( ! $tr_id && ! empty( Strope()->posts ) ) {
			$tr_id = Strope()->posts->create_media_translation( $id, $language );
		}

		return $tr_id ? (int) $tr_id : (int) $id;
	}

	/**
	 * Translates a term id.
	 *
	 * @since 4.1.0
	 *
	 * @param int             $term_id  Term id.
	 * @param string          $taxonomy Taxonomy name.
	 * @param Strope_Language $language Target language.
	 * @param bool            $create   Whether to create the term translation if it doesn't exist.
	 * @return int Translated term id, the term id itself if it is not translatable, 0 if it has no translation.
	 */
	public function translate_term_id( $term_id, $taxonomy, Strope_Language $language, $create = true ) {
		if ( ! $this->model->is_translated_taxonomy( $taxonomy ) ) {
			return $term_id;
		}

		$from_language = $this->model->term->get_language( $term_id );

		if ( empty( $from_language ) ) {
			return $term_id; // Keep terms without language.
		}

		$tr_id = $this->model->term->get( $term_id, $language );

		if ( $tr_id ) {
			return $tr_id;
		}

		/**
		 * Filters whether to create a missing translation of a global attribute term when synchronizing products.
		 *
		 * @since 4.1.0
		 *
		 * @param bool            $create   Whether to create the term translation.
		 * @param int             $term_id  Source term id.
		 * @param string          $taxonomy Taxonomy name.
		 * @param Strope_Language $language Target language.
		 */
		if ( ! $create || ! apply_filters( 'strope_wc_create_term_translation', 0 === strpos( $taxonomy, 'pa_' ), $term_id, $taxonomy, $language ) ) {
			return 'product_shipping_class' === $taxonomy ? $term_id : 0;
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term instanceof WP_Term ) {
			return 0;
		}

		$result = $this->model->term->insert(
			$term->name,
			$taxonomy,
			$language,
			array(
				'translations' => array_merge( $this->model->term->get_translations( $term_id ), array( $from_language->slug => $term_id ) ),
			)
		);

		return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
	}

	/**
	 * Translates a term slug.
	 *
	 * @since 4.1.0
	 *
	 * @param string               $slug          Term slug.
	 * @param string               $taxonomy      Taxonomy name.
	 * @param Strope_Language      $language      Target language.
	 * @param Strope_Language|null $from_language Language of the term, if known.
	 * @return string
	 */
	public function translate_term_slug( $slug, $taxonomy, Strope_Language $language, $from_language = null ) {
		if ( ! $this->model->is_translated_taxonomy( $taxonomy ) ) {
			return $slug;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => $slug,
				'hide_empty' => false,
				'lang'       => $from_language instanceof Strope_Language ? $from_language->slug : '',
			)
		);

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return $slug;
		}

		$term = reset( $terms );

		if ( ! $term instanceof WP_Term ) {
			return $slug;
		}

		$tr_id   = $this->translate_term_id( $term->term_id, $taxonomy, $language );
		$tr_term = $tr_id ? get_term( $tr_id, $taxonomy ) : null;

		return $tr_term instanceof WP_Term ? $tr_term->slug : $slug;
	}

	/**
	 * Translates the attributes of a product.
	 * Global attributes terms are translated, custom attributes keep their translated names and values.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Product      $product    Source product.
	 * @param WC_Product      $tr_product Translated product.
	 * @param Strope_Language $language   Target language.
	 * @return WC_Product_Attribute[]
	 */
	protected function translate_product_attributes( WC_Product $product, WC_Product $tr_product, Strope_Language $language ) {
		$tr_custom = array();
		foreach ( $tr_product->get_attributes( 'edit' ) as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				$tr_custom[] = $attribute;
			}
		}

		$attributes = array();
		$i          = 0;

		foreach ( $product->get_attributes( 'edit' ) as $key => $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$tr_attribute = clone $attribute;
				$options      = array();
				foreach ( $attribute->get_options() as $term_id ) {
					$tr_term_id = $this->translate_term_id( (int) $term_id, $attribute->get_name(), $language );
					if ( $tr_term_id ) {
						$options[] = $tr_term_id;
					}
				}
				$tr_attribute->set_options( $options );
				$attributes[ $key ] = $tr_attribute;
				continue;
			}

			// Custom attributes are translatable: keep the translated name and values, matched by position.
			if ( isset( $tr_custom[ $i ] ) ) {
				$tr_attribute = clone $tr_custom[ $i ];
				$tr_attribute->set_position( $attribute->get_position() );
				$tr_attribute->set_visible( $attribute->get_visible() );
				$tr_attribute->set_variation( $attribute->get_variation() );
			} else {
				$tr_attribute = clone $attribute;
			}
			++$i;

			$attributes[ sanitize_title( $tr_attribute->get_name() ) ] = $tr_attribute;
		}

		return $attributes;
	}

	/**
	 * Returns the correspondence between the custom attributes of a product and of its translation (matched by position).
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Product $product    Source product.
	 * @param WC_Product $tr_product Translated product.
	 * @return array Array with source attribute keys as keys and arrays with 'key' (translated attribute key)
	 *               and 'values' (source values as keys and translated values as values) as values.
	 */
	public function get_custom_attributes_map( WC_Product $product, WC_Product $tr_product ) {
		$tr_custom = array();
		foreach ( $tr_product->get_attributes( 'edit' ) as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				$tr_custom[] = $attribute;
			}
		}

		$map = array();
		$i   = 0;

		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || $attribute->is_taxonomy() ) {
				continue;
			}

			$tr_attribute = isset( $tr_custom[ $i ] ) ? $tr_custom[ $i ] : $attribute;
			$tr_options   = array_values( $tr_attribute->get_options() );
			$values       = array();

			foreach ( array_values( $attribute->get_options() ) as $n => $option ) {
				$values[ $option ] = isset( $tr_options[ $n ] ) ? $tr_options[ $n ] : $option;
			}

			$map[ sanitize_title( $attribute->get_name() ) ] = array(
				'key'    => sanitize_title( $tr_attribute->get_name() ),
				'values' => $values,
			);
			++$i;
		}

		return $map;
	}

	/**
	 * Translates the attributes of a variation (or default attributes), as an array of
	 * attribute keys (without the 'attribute_' prefix) and values.
	 *
	 * @since 4.1.0
	 *
	 * @param array                      $attributes    Attributes.
	 * @param Strope_Language            $language      Target language.
	 * @param array                      $map           Map of the custom attributes, see `get_custom_attributes_map()`.
	 * @param Strope_Language|false|null $from_language Language of the source.
	 * @return array
	 */
	public function translate_variation_attributes( array $attributes, Strope_Language $language, array $map, $from_language = null ) {
		$translated = array();

		foreach ( $attributes as $key => $value ) {
			$key   = (string) $key;
			$value = (string) $value;

			if ( taxonomy_exists( $key ) ) {
				$translated[ $key ] = '' === $value ? '' : $this->translate_term_slug( $value, $key, $language, $from_language instanceof Strope_Language ? $from_language : null );
				continue;
			}

			if ( ! isset( $map[ $key ] ) ) {
				$translated[ $key ] = $value;
				continue;
			}

			$tr_value = $value;
			foreach ( $map[ $key ]['values'] as $option => $tr_option ) {
				if ( 0 === strcasecmp( (string) $option, $value ) || sanitize_title( (string) $option ) === sanitize_title( $value ) ) {
					$tr_value = (string) $tr_option;
					break;
				}
			}

			$translated[ $map[ $key ]['key'] ] = $tr_value;
		}

		return $translated;
	}
}
