<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the language of orders and customers, and sends the emails in the right language.
 *
 * @since 4.1.0
 */
class Strope_WooCommerce_Orders {
	/**
	 * Order meta storing the order language.
	 *
	 * @var string
	 */
	const ORDER_META = '_strope_language';

	/**
	 * User meta storing the customer preferred language.
	 *
	 * @var string
	 */
	const USER_META = 'strope_language';

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
	 * Emails being triggered, with object hashes as keys and whether the language was switched as values.
	 *
	 * @var bool[]
	 */
	protected $emails = array();

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

		// Order and customer language.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'set_order_language' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'set_order_language' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'set_customer_language' ), 5 );
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'order_received_url' ), 10, 2 );

		// Emails.
		add_filter( 'woocommerce_email_classes', array( $this, 'email_classes' ), 999 );
		add_filter( 'woocommerce_allow_switching_email_locale', array( $this, 'before_email' ), 10, 2 );
		add_filter( 'woocommerce_allow_restoring_email_locale', array( $this, 'after_email' ), 10, 2 );
		add_filter( 'woocommerce_order_item_name', array( $this, 'order_item_name' ), 10, 2 );

		// Admin.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_language_field' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_order_language' ), 50 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'display_column' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'display_column' ), 10, 2 );
	}

	/**
	 * Stores the current language in a new order, and as preferred language of the customer.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function set_order_language( $order ) {
		$language = $this->woocommerce->get_current_language();

		if ( empty( $language ) || ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( self::ORDER_META, $language->slug );

		if ( $order->get_customer_id() ) {
			update_user_meta( $order->get_customer_id(), self::USER_META, $language->slug );
		}
	}

	/**
	 * Stores the current language as preferred language of a new customer.
	 *
	 * @since 4.1.0
	 *
	 * @param int $customer_id Customer (user) id.
	 * @return void
	 */
	public function set_customer_language( $customer_id ) {
		$language = $this->woocommerce->get_current_language();

		if ( $language && $customer_id ) {
			update_user_meta( (int) $customer_id, self::USER_META, $language->slug );
		}
	}

	/**
	 * Returns the language of an order.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Abstract_Order|int $order Order object or id.
	 * @return Strope_Language|null
	 */
	public function get_order_language( $order ) {
		if ( ! $order instanceof WC_Abstract_Order ) {
			$order = wc_get_order( $order );
		}

		if ( $order instanceof WC_Order_Refund ) {
			$order = wc_get_order( $order->get_parent_id() );
		}

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$slug = $order->get_meta( self::ORDER_META );

		if ( empty( $slug ) && $order->get_customer_id() ) {
			$slug = get_user_meta( $order->get_customer_id(), self::USER_META, true );
		}

		$language = is_string( $slug ) && '' !== $slug ? $this->model->get_language( $slug ) : false;
		return $language ? $language : null;
	}

	/**
	 * Returns the preferred language of a user.
	 *
	 * @since 4.1.0
	 *
	 * @param int $user_id User id.
	 * @return Strope_Language|null
	 */
	public function get_user_language( $user_id ) {
		$slug     = $user_id ? get_user_meta( (int) $user_id, self::USER_META, true ) : '';
		$language = is_string( $slug ) && '' !== $slug ? $this->model->get_language( $slug ) : false;
		return $language ? $language : null;
	}

	/**
	 * Uses the checkout page in the order language for the order received url
	 * (useful when it is generated in another context, e.g. a payment gateway callback).
	 *
	 * @since 4.1.0
	 *
	 * @param string   $url   Order received url.
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function order_received_url( $url, $order ) {
		$language    = $order instanceof WC_Order ? $this->get_order_language( $order ) : null;
		$checkout_id = (int) get_option( 'woocommerce_checkout_page_id' );

		if ( empty( $language ) || $checkout_id <= 0 ) {
			return $url;
		}

		$tr_id = $this->model->post->get( $checkout_id, $language );

		if ( ! $tr_id || (int) wc_get_page_id( 'checkout' ) === $tr_id ) {
			return $url;
		}

		$permalink = get_permalink( $tr_id );

		if ( ! $permalink ) {
			return $url;
		}

		$tr_url = add_query_arg( 'key', $order->get_order_key(), wc_get_endpoint_url( 'order-received', (string) $order->get_id(), $permalink ) );

		return 0 === strpos( $url, 'https:' ) ? set_url_scheme( $tr_url, 'https' ) : $tr_url;
	}

	/*
	 * Emails.
	 */

	/**
	 * Adds a filter on the recipient of each email, which is called once the email object (order, user) is known.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Email[] $emails Emails.
	 * @return WC_Email[]
	 */
	public function email_classes( $emails ) {
		foreach ( (array) $emails as $email ) {
			if ( $email instanceof WC_Email && ! empty( $email->id ) ) {
				add_filter( 'woocommerce_email_recipient_' . $email->id, array( $this, 'switch_email_language' ), 999, 3 );
			}
		}
		return $emails;
	}

	/**
	 * Notices the start of an email trigger and prevents WooCommerce from switching the locale itself.
	 *
	 * @since 4.1.0
	 *
	 * @param bool     $allow Whether to switch the locale.
	 * @param WC_Email $email Email object.
	 * @return bool
	 */
	public function before_email( $allow, $email ) {
		if ( ! $email instanceof WC_Email ) {
			return $allow;
		}

		$this->emails[ spl_object_hash( $email ) ] = false;
		return false;
	}

	/**
	 * Restores the language at the end of an email trigger.
	 *
	 * @since 4.1.0
	 *
	 * @param bool     $allow Whether to restore the locale.
	 * @param WC_Email $email Email object.
	 * @return bool
	 */
	public function after_email( $allow, $email ) {
		if ( ! $email instanceof WC_Email ) {
			return $allow;
		}

		$hash = spl_object_hash( $email );

		if ( ! array_key_exists( $hash, $this->emails ) ) {
			return $allow;
		}

		if ( $this->emails[ $hash ] ) {
			$this->woocommerce->restore_language();

			if ( isset( $email->placeholders['{site_title}'] ) ) {
				$email->placeholders['{site_title}'] = $email->get_blogname();
			}
		}

		unset( $this->emails[ $hash ] );
		return false;
	}

	/**
	 * Switches the language when an email is about to be sent.
	 * Customer emails are sent in the order or customer language, admin emails in the default language.
	 *
	 * @since 4.1.0
	 *
	 * @param string   $recipient Recipient(s).
	 * @param mixed    $object    The object the email is about (order, user...).
	 * @param WC_Email $email     Email object.
	 * @return string Unmodified recipient.
	 */
	public function switch_email_language( $recipient, $object, $email = null ) {
		if ( ! $email instanceof WC_Email || empty( $recipient ) ) {
			return $recipient;
		}

		$hash = spl_object_hash( $email );

		// Switch only during an email trigger and only once.
		if ( ! array_key_exists( $hash, $this->emails ) || $this->emails[ $hash ] ) {
			return $recipient;
		}

		$language = null;

		if ( $email->is_customer_email() ) {
			if ( $object instanceof WC_Abstract_Order ) {
				$language = $this->get_order_language( $object );
			} elseif ( $object instanceof WP_User ) {
				$language = $this->get_user_language( $object->ID );
			} elseif ( $object instanceof WC_Customer ) {
				$language = $this->get_user_language( $object->get_id() );
			}

			if ( empty( $language ) ) {
				$language = $this->woocommerce->get_current_language();
			}
		}

		if ( empty( $language ) ) {
			$language = $this->model->get_default_language();
		}

		/**
		 * Filters the language in which a WooCommerce email is sent.
		 *
		 * @since 4.1.0
		 *
		 * @param Strope_Language|false|null $language Language.
		 * @param WC_Email                   $email    Email object.
		 * @param mixed                      $object   The object the email is about (order, user...).
		 */
		$language = apply_filters( 'strope_wc_email_language', $language, $email, $object );

		if ( ! $language instanceof Strope_Language ) {
			return $recipient;
		}

		$this->woocommerce->switch_language( $language );
		$this->emails[ $hash ] = true;

		// Some placeholders are evaluated before the language is known.
		if ( isset( $email->placeholders['{site_title}'] ) ) {
			$email->placeholders['{site_title}'] = $email->get_blogname();
		}

		if ( isset( $email->placeholders['{order_date}'] ) && $object instanceof WC_Order && $object->get_date_created() ) {
			$email->placeholders['{order_date}'] = wc_format_datetime( $object->get_date_created() );
		}

		return $recipient;
	}

	/**
	 * Displays the order items names in the language of the email being sent.
	 *
	 * @since 4.1.0
	 *
	 * @param string        $name Item name.
	 * @param WC_Order_Item $item Order item.
	 * @return string
	 */
	public function order_item_name( $name, $item ) {
		if ( ! in_array( true, $this->emails, true ) || ! $item instanceof WC_Order_Item_Product ) {
			return $name;
		}

		$language   = $this->woocommerce->get_current_language();
		$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();

		if ( empty( $language ) || empty( $product_id ) ) {
			return $name;
		}

		$tr_id = $this->model->post->get( $product_id, $language );

		if ( ! $tr_id || $tr_id === $product_id ) {
			return $name;
		}

		$tr_product = wc_get_product( $tr_id );
		return $tr_product ? $tr_product->get_name() : $name;
	}

	/*
	 * Admin.
	 */

	/**
	 * Displays the order language in the order edit screen.
	 *
	 * @since 4.1.0
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function order_language_field( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$current = $order->get_meta( self::ORDER_META );
		?>
		<p class="form-field form-field-wide strope-order-language">
			<label for="strope_order_language"><?php esc_html_e( 'Language:', 'strope-translate' ); ?></label>
			<select id="strope_order_language" name="strope_order_language">
				<option value=""><?php esc_html_e( 'Not defined', 'strope-translate' ); ?></option>
				<?php foreach ( $this->model->get_languages_list() as $language ) { ?>
					<option value="<?php echo esc_attr( $language->slug ); ?>" <?php selected( $current, $language->slug ); ?>><?php echo esc_html( $language->name ); ?></option>
				<?php } ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Saves the order language from the order edit screen.
	 *
	 * @since 4.1.0
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function save_order_language( $order_id ) {
		if ( ! isset( $_POST['strope_order_language'], $_POST['woocommerce_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$language = $this->model->get_language( sanitize_key( wp_unslash( $_POST['strope_order_language'] ) ) );

		if ( $language ) {
			$order->update_meta_data( self::ORDER_META, $language->slug );
		} else {
			$order->delete_meta_data( self::ORDER_META );
		}

		$order->save();
	}

	/**
	 * Adds a language column in the orders list.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $columns List of columns.
	 * @return string[]
	 */
	public function add_column( $columns ) {
		$columns['strope_language'] = esc_html__( 'Language', 'strope-translate' );
		return $columns;
	}

	/**
	 * Displays the order language in the orders list.
	 *
	 * @since 4.1.0
	 *
	 * @param string       $column Column name.
	 * @param int|WC_Order $order  Order id (posts storage) or object (HPOS).
	 * @return void
	 */
	public function display_column( $column, $order ) {
		if ( 'strope_language' !== $column ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		$slug  = $order instanceof WC_Order ? $order->get_meta( self::ORDER_META ) : '';

		$language = is_string( $slug ) && '' !== $slug ? $this->model->get_language( $slug ) : false;
		echo $language ? esc_html( $language->name ) : '&mdash;';
	}
}
