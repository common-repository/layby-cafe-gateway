<?php
/**
 * Layby Cafe Payment Gateway
 *
 * Provides a Layby Cafe Payment Gateway.
 *
 * @class  woocommerce_laybycafe
 * @package WooCommerce
 * @category Payment Gateways
 * @author WooCommerce
 */

add_action( 'init','register_on_layby_order_status');
add_filter( 'wc_order_statuses','add_on_layby_order_to_order_statuses');
add_action( 'wp_print_scripts', 'on_layby_add_order_status_icon' );

function on_layby_add_order_status_icon() {

	if( ! is_admin() ) {
		return;
	}

	?> <style>
        /* Add custom status order icons */
        .column-order_status mark.on-layby,
        .column-order_status mark.building {

        }
        .widefat .column-order_status mark.on-layby::after {
            content: "\e011";
            color: #ffba00;
            font-family: WooCommerce;
            speak: none;
            font-weight: 400;
            font-variant: normal;
            text-transform: none;
            line-height: 1;
            -webkit-font-smoothing: antialiased;
            margin: 0;
            text-indent: 0;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            text-align: center;
        }

        /* Repeat for each different icon; tie to the correct status */

    </style> <?php
}

function register_on_layby_order_status() {
	register_post_status( 'wc-on-layby', array(
		'label'                     => 'On Layby',
		'public'                    => true,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		'label_count'               => _n_noop( 'On Layby <span class="count">(%s)</span>', 'On Layby <span class="count">(%s)</span>' )
	) );
}
// Add to list of WC Order statuses
function add_on_layby_order_to_order_statuses( $order_statuses ) {

	$new_order_statuses = array();

	// add new order status after processing
	foreach ( $order_statuses as $key => $status ) {

		$new_order_statuses[ $key ] = $status;

		if ( 'wc-pending' === $key ) {
			$new_order_statuses['wc-on-layby'] = _x( 'On Layby','Order status', 'woocommerce');
		}
	}

	return $new_order_statuses;
}

class WC_Gateway_LaybyCafe extends WC_Payment_Gateway {

	/**
	 * Version
	 *
	 * @var string
	 */
	public $version;

	/**
	 * @access protected
	 * @var array $data_to_send
	 */
	protected $data_to_send = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->version = WC_GATEWAY_LAYBYCAFE_VERSION;
		$this->id = 'laybycafe';
		$this->method_title       = __( 'Layby Cafe', 'woocommerce-gateway-laybycafe' );
		/* translators: 1: a href link 2: closing href */
		$this->order_button_text 	= 'Put It On Layby';
		$this->method_description = sprintf( __( 'Layby Cafe works by sending the user to %1$sLayby Cafe%2$s to enter their payment information.', 'woocommerce-gateway-laybycafe' ), '<a href="http://laybycafe.com/">', '</a>' );
		$this->icon               = WP_PLUGIN_URL . '/' . plugin_basename( dirname( dirname( __FILE__ ) ) ) . '/assets/images/icon.png';
		$this->debug_email        = get_option( 'admin_email' );
		$this->available_countries  = array( 'ZA' );
		$this->available_currencies = (array)apply_filters('woocommerce_gateway_laybycafe_available_currencies', array( 'ZAR' ) );

		// Supported functionality
		$this->supports = array(
			'products',
			'pre-orders',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change', // Subs 1.x support
			//'subscription_payment_method_change_customer', // see issue #39
		);

		$this->init_form_fields();
		$this->init_settings();

		if ( ! is_admin() ) {
			$this->setup_constants();
		}

		// Setup default merchant data.
		$this->token      = $this->get_option( 'token' );
		$this->url          = 'https://laybycafe.com/app/payment?order=';
		$this->title            = $this->get_option( 'title' );
		$this->response_url	    = add_query_arg( 'wc-api', 'WC_Gateway_LaybyCafe', home_url( '/' ) );
		$this->send_debug_email = 'yes' === $this->get_option( 'send_debug_email' );
		$this->description      = $this->get_option( 'description' );
		$this->enabled          = $this->is_valid_for_use() ? 'yes': 'no'; // Check if the base currency supports this gateway.
		$this->enable_logging   = 'yes' === $this->get_option( 'enable_logging' );

		// Setup the test data, if in test mode.
		if ( 'yes' === $this->get_option( 'testmode' ) ) {
			$this->token     ='Z7qu4KgcNGJvGQTwak5XATpQVHHuavQTmvY7Nn8hTQGBMHjdSw';
			$this->url          = 'https://localhost/consumerApp/payment?order=';
			$this->add_testmode_admin_settings_notice();
		} else {
			$this->send_debug_email = false;
		}

		add_action( 'woocommerce_api_wc_gateway_laybycafe', array( $this, 'check_itn_response' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_laybycafe', array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
		add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'process_pre_order_payments' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @since 1.0.0
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-gateway-laybycafe' ),
				'label'       => __( 'Enable Layby Cafe', 'woocommerce-gateway-laybycafe' ),
				'type'        => 'checkbox',
				'description' => __( 'This controls whether or not this gateway is enabled within WooCommerce.', 'woocommerce-gateway-laybycafe' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'title' => array(
				'title'       => __( 'Title', 'woocommerce-gateway-laybycafe' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-laybycafe' ),
				'default'     => __( 'Layby Cafe', 'woocommerce-gateway-laybycafe' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce-gateway-laybycafe' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-laybycafe' ),
				'default'     => 'Create and pay laybys securely using Layby Cafe.',
				'desc_tip'    => true,
			),
			'testmode' => array(
				'title'       => __( 'Layby Cafe Sandbox', 'woocommerce-gateway-laybycafe' ),
				'type'        => 'checkbox',
				'description' => __( 'Place the payment gateway in development mode.', 'woocommerce-gateway-laybycafe' ),
				'default'     => 'yes',
			),
			'token' => array(
				'title'       => __( 'Merchant Token', 'woocommerce-gateway-laybycafe' ),
				'type'        => 'text',
				'description' => __( 'This is the Merchant Token, received from Layby Cafe.', 'woocommerce-gateway-laybycafe' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'send_debug_email' => array(
				'title'   => __( 'Send Debug Emails', 'woocommerce-gateway-laybycafe' ),
				'type'    => 'checkbox',
				'label'   => __( 'Send debug e-mails for transactions through the Layby Cafe gateway (sends on successful transaction as well).', 'woocommerce-gateway-laybycafe' ),
				'default' => 'yes',
			),
			'debug_email' => array(
				'title'       => __( 'Who Receives Debug E-mails?', 'woocommerce-gateway-laybycafe' ),
				'type'        => 'text',
				'description' => __( 'The e-mail address to which debugging error e-mails are sent when in test mode.', 'woocommerce-gateway-laybycafe' ),
				'default'     => get_option( 'admin_email' ),
			),
			'enable_logging' => array(
				'title'   => __( 'Enable Logging', 'woocommerce-gateway-laybycafe' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable transaction logging for gateway.', 'woocommerce-gateway-laybycafe' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * add_testmode_admin_settings_notice()
	 * Add a notice to the merchant_key and token fields when in test mode.
	 *
	 * @since 1.0.0
	 */
	public function add_testmode_admin_settings_notice() {
		$this->form_fields['token']['description']  .= ' <strong>' . __( 'Sandbox Merchant Token currently in use', 'woocommerce-gateway-laybycafe' ) . ' ( ' . esc_html( $this->token ) . ' ).</strong>';
	}
	/**
	 * is_valid_for_use()
	 *
	 * Check if this gateway is enabled and available in the base currency being traded with.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_valid_for_use() {
		$is_available          = false;
		$is_available_currency = in_array( get_woocommerce_currency(), $this->available_currencies );

		if ( $is_available_currency && $this->token) {
			$is_available = true;
		}

		return $is_available;
	}
    /**
     *  Show possible admin notices
     *
     */
    public function admin_notices() {
        if ( 'yes' !== $this->get_option( 'enabled' )
            || ! empty( $this->token) ) {
            return;
        }

        echo '<div class="error layby Cafe-passphrase-message"><p>'
            . __( 'Layby Cafe requires a Merchant Token to work.', 'woocommerce-gateway-laybycafe' )
            . '</p></div>';
    }

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		if ( in_array( get_woocommerce_currency(), $this->available_currencies ) ) {
			parent::admin_options();
		} else {
		?>
			<h3><?php _e( 'Layb yCafe', 'woocommerce-gateway-laybycafe' ); ?></h3>
			<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce-gateway-laybycafe' ); ?></strong> <?php /* translators: 1: a href link 2: closing href */ echo sprintf( __( 'Choose South African Rands as your store currency in %1$sGeneral Settings%2$s to enable the Layby Cafe Gateway.', 'woocommerce-gateway-laybycafe' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">', '</a>' ); ?></p></div>
			<?php
		}
	}

	/**
	 * Generate the LaybyCafe button link.
	 *
	 * @since 1.0
	 */
	public function generate_laybycafe_form( $order_id ) {
		$order         = wc_get_order( $order_id );
		// Construct variables for post
        global $woocommerce;
        $items = $woocommerce->cart->get_cart();

        $itemsData =[];
        foreach($items as $item => $values) {

            $_product =  wc_get_product( $values['data']->get_id() );
            //product image
            $getProductDetail = wc_get_product( $values['product_id'] );
            $getProductDetail->get_image_id(); // accepts 2 arguments ( size, attr )

            $price = get_post_meta($values['product_id'] , '_price', true);
            /*Regular Price and Sale Price*/
            $Regular = get_post_meta($values['product_id'] , '_regular_price', true);
            $Sale= get_post_meta($values['product_id'] , '_sale_price', true);
            $itemsData[] = [
                'product_quantity'=>$values['quantity'],
                'order_item_name'=>$_product->get_title(),
                'order_item_sku'=>@$_product->get_sku(),
                'product_item_regular_price'=>@$Regular,
                'product_item_sale_price'=>@$Sale,
                'product_item_price'=>@$price,
            ];
        }

        $postData = [
                    'token'=>$this->token,
                    'consumer_info'=>[
                        'email'=>self::get_order_prop( $order, 'billing_email' ),
                        'first_name'=>self::get_order_prop( $order, 'billing_first_name' ),
                        'last_name'=>self::get_order_prop( $order, 'billing_last_name' ),
                        'phone'=>self::get_order_prop( $order, 'billing_phone' ),
                ],
            'layby_info'=>[
                    'order_id'=>self::get_order_prop( $order, 'order_key' ),
                    'product_ref'=>ltrim( $order->get_order_number(), _x( '#', 'hash before order number', 'woocommerce-gateway-laybycafe' ) ),
                    'price'=>$order->get_total(),
                    'description'=>sprintf( __( 'New order from %s', 'woocommerce-gateway-laybycafe' ), get_bloginfo( 'name' ) ),
                    'items'=>base64_encode(json_encode($itemsData)),
                    'return_url'  => $this->response_url,
                    'cancel_url'       => $order->get_cancel_order_url(),
            ]
        ];


        $uri = $this->url.''.base64_encode(json_encode($postData)).'';

		$this->data_to_send = array(
			// Merchant details
			'token'      => $this->token,
			'return_url'       => $this->get_return_url( $order ),
			'cancel_url'       => $order->get_cancel_order_url(),
			'notify_url'       => $this->response_url,

			// Billing details
			'name_first'       => self::get_order_prop( $order, 'billing_first_name' ),
			'name_last'        => self::get_order_prop( $order, 'billing_last_name' ),
			'email_address'    => self::get_order_prop( $order, 'billing_email' ),

			// Item details
			'm_payment_id'     => ltrim( $order->get_order_number(), _x( '#', 'hash before order number', 'woocommerce-gateway-laybycafe' ) ),
			'amount'           => $order->get_total(),
			'item_name'        => get_bloginfo( 'name' ) . ' - ' . $order->get_order_number(),
			/* translators: 1: blog info name */
			'item_description' => sprintf( __( 'New order from %s', 'woocommerce-gateway-laybycafe' ), get_bloginfo( 'name' ) ),
            "items" => base64_encode(json_encode($itemsData)),
			// Custom strings
			'custom_str1'      => self::get_order_prop( $order, 'order_key' ),
			'custom_str2'      => 'WooCommerce/' . WC_VERSION . '; ' . get_site_url(),
			'custom_str3'      => self::get_order_prop( $order, 'id' ),
			'source'           => 'WooCommerce-Free-Plugin',
		);


		$laybycafe_args_array = array();
		foreach ( $this->data_to_send as $key => $value ) {
			$laybycafe_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		}
		return '<form action="' . esc_url( $uri) . '" method="post" id="laybycafe_payment_form">
				' . implode( '', $laybycafe_args_array ) . '
				<input type="submit" class="button-alt" id="submit_laybycafe_payment_form" value="' . __( 'Pay via LaybyCafe', 'woocommerce-gateway-laybycafe' ) . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Cancel order &amp; restore cart', 'woocommerce-gateway-laybycafe' ) . '</a>
				<script type="text/javascript">
					jQuery(function(){
						jQuery("body").block(
							{
								message: "' . __( 'Thank you for your order. We are now redirecting you to LaybyCafe to make payment.', 'woocommerce-gateway-laybycafe' ) . '",
								overlayCSS:
								{
									background: "#fff",
									opacity: 0.6
								},
								css: {
									padding:        20,
									textAlign:      "center",
									color:          "#555",
									border:         "3px solid #aaa",
									backgroundColor:"#fff",
									cursor:         "wait"
								}
							});
						jQuery( "#submit_laybycafe_payment_form" ).click();
					});
				</script>
			</form>';
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @since 1.0
	 */
	public function process_payment( $order_id ) {

		if ( $this->order_contains_pre_order( $order_id )
			&& $this->order_requires_payment_tokenization( $order_id )
			&& ! $this->cart_contains_pre_order_fee() ) {
				throw new Exception( 'LaybyCafe does not support transactions without any ulbront costs or fees. Please select another gateway' );
		}

		$order = wc_get_order( $order_id );
		return array(
			'result' 	 => 'success',
			'redirect'	 => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Reciept page.
	 *
	 * Display text and a button to direct the user to LaybyCafe.
	 *
	 * @since 1.0
	 */
	public function receipt_page( $order ) {
		echo '<p>' . __( 'Thank you for your order, please click the button below to pay with LaybyCafe.', 'woocommerce-gateway-laybycafe' ) . '</p>';
		echo $this->generate_laybycafe_form( $order );
	}

	/**
	 * Check LaybyCafe ITN response.
	 *
	 * @since 1.0
	 */
	public function check_itn_response() {

		$this->handle_itn_request( stripslashes_deep( $_POST ) );

		// Notify LaybyCafe that information has been received
		header( 'HTTP/1.0 200 OK' );
		flush();
	}

	/**
	 * Check LaybyCafe ITN validity.
	 *
	 * @param array $data
	 * @since 1.0
	 */
	public function handle_itn_request( $data ) {

		$this->log( PHP_EOL
			. '----------'
			. PHP_EOL . 'LaybyCafe ITN call received'
			. PHP_EOL . '----------'
		);
		$this->log( 'Get posted data' );
		$this->log( 'LaybyCafe Data: ' . print_r( $data, true ) );

		$laybycafe_error  = false;
		$laybycafe_done   = false;
		$debug_email    = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
		$session_id     = $data['order_id'];
		$vendor_name    = get_bloginfo( 'name', 'display' );
		$vendor_url     = home_url( '/' );
		$order_id       = absint( $data['product_ref'] );
		$order_key      = wc_clean( $session_id );
		$order          = wc_get_order( $order_id );
		$original_order = $order;

		if ( false === $data ) {
			$laybycafe_error  = true;
			$laybycafe_error_message = LAYBYC_ERR_BAD_ACCESS;
		}


		// Check data against internal order
		if ( ! $laybycafe_error && ! $laybycafe_done ) {
			$this->log( 'Check data against internal order' );

			// Check order amount
			if ( ! $this->amounts_equal( $data['price'], self::get_order_prop( $order, 'order_total' ) )
				 && ! $this->order_contains_pre_order( $order_id )
				 && ! $this->order_contains_subscription( $order_id ) ) {
				$laybycafe_error  = true;
				$laybycafe_error_message = LAYBYC_ERR_AMOUNT_MISMATCH;
			} elseif ( strcasecmp( $data['order_id'], self::get_order_prop( $order, 'order_key' ) ) != 0 ) {
				// Check session ID
				$laybycafe_error  = true;
				$laybycafe_error_message = LAYBYC_ERR_SESSIONID_MISMATCH;
			}
		}


		// Get internal order and verify it hasn't already been processed
		if ( ! $laybycafe_error && ! $laybycafe_done ) {
			$this->log_order_details( $order );

			// Check if order has already been processed
			if ( 'completed' === self::get_order_prop( $order, 'status' ) ) {
				$this->log( 'Order has already been processed' );
				$laybycafe_done = true;
			}
		}

		// If an error occurred

		$status = strtolower( $data['successful'] );

		if ( $laybycafe_error ) {
			$this->log( 'Error occurred: ' . $laybycafe_error_message );

			if ( $this->send_debug_email ) {
				$this->log( 'Sending email notification' );

				 // Send an email
				$subject = 'LaybyCafe ITN error: ' . $laybycafe_error_message;
				$body =
					"Hi,\n\n" .
					"An invalid LaybyCafe transaction on your website requires attention\n" .
					"------------------------------------------------------------\n" .
					'Site: ' . esc_html( $vendor_name ) . ' (' . esc_url( $vendor_url ) . ")\n" .
					'Remote IP Address: ' . $_SERVER['REMOTE_ADDR'] . "\n" .
					'Remote host name: ' . gethostbyaddr( $_SERVER['REMOTE_ADDR'] ) . "\n" .
					'Purchase ID: ' . self::get_order_prop( $order, 'id' ) . "\n" .
					'User ID: ' . self::get_order_prop( $order, 'user_id' ) . "\n";
				if ( isset( $data['transaction_id'] ) ) {
					$body .= 'LaybyCafe Transaction ID: ' . esc_html( $data['transaction_id'] ) . "\n";
				}
				if ( isset( $data['successful'] ) ) {
					$body .= 'LaybyCafe Payment Status: ' . esc_html( $data['successful'] ) . "\n";
				}

				$body .= "\nError: " . $laybycafe_error_message . "\n";

				switch ( $laybycafe_error_message ) {
					case LAYBYC_ERR_AMOUNT_MISMATCH:
						$body .=
							'Value received : ' . esc_html( $data['amount_gross'] ) . "\n"
							. 'Value should be: ' . self::get_order_prop( $order, 'order_total' );
						break;

					case LAYBYC_ERR_ORDER_ID_MISMATCH:
						$body .=
							'Value received : ' . esc_html( $data['custom_str3'] ) . "\n"
							. 'Value should be: ' . self::get_order_prop( $order, 'id' );
						break;

					case LAYBYC_ERR_SESSIONID_MISMATCH:
						$body .=
							'Value received : ' . esc_html( $data['order_id'] ) . "\n"
							. 'Value should be: ' . self::get_order_prop( $order, 'id' );
						break;

					// For all other errors there is no need to add additional information
					default:
						break;
				}

				wp_mail( $debug_email, $subject, $body );
			} // End if().
		}
		elseif ( ! $laybycafe_done ) {

			$this->log( 'Check status and update order '.$status.'' );

			if ( '1' === $status ) {
				$this->handle_itn_payment_complete( $data, $order, $subscriptions );
			} elseif ( '0' === $status ) {
				$this->handle_itn_payment_failed( $data, $order );
			} elseif ( '2' === $status ) {
				$this->handle_itn_payment_pending( $data, $order );
			} elseif ( '3' === $status ) {
				$this->handle_itn_payment_cancelled( $data, $order, $subscriptions );
			}
		} // End if().

		$this->log( PHP_EOL
			. '----------'
			. PHP_EOL . 'End ITN call'
			. PHP_EOL . '----------'
		);

	}

	/**
	 * Handle logging the order details.
	 *
	 * @since 1.0
	 */
	public function log_order_details( $order ) {
		if ( version_compare( WC_VERSION,'3.0.0', '<' ) ) {
			$customer_id = get_post_meta( $order->get_id(), '_customer_user', true );
		} else {
			$customer_id = $order->get_user_id();
		}

		$details = "Order Details:"
		. PHP_EOL . 'customer id:' . $customer_id
		. PHP_EOL . 'order id:   ' . $order->get_id()
		. PHP_EOL . 'parent id:  ' . $order->get_parent_id()
		. PHP_EOL . 'status:     ' . $order->get_status()
		. PHP_EOL . 'total:      ' . $order->get_total()
		. PHP_EOL . 'currency:   ' . $order->get_currency()
		. PHP_EOL . 'key:        ' . $order->get_order_key()
		. "";

		$this->log( $details );
	}

	/**
	 * This function mainly responds to ITN cancel requests initiated on LaybyCafe, but also acts
	 * just in case they are not cancelled.
	 * @version 1.4.3 Subscriptions flag
	 *
	 * @param array $data should be from the Gatewy ITN callback.
	 * @param WC_Order $order
	 */
	public function handle_itn_payment_cancelled( $data, $order, $subscriptions ) {

		remove_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
		foreach ( $subscriptions as $subscription ) {
			if ( 'cancelled' !== $subscription->get_status() ) {
				$subscription->update_status( 'cancelled', __( 'Merchant cancelled subscription on LaybyCafe.' , 'woocommerce-gateway-laybycafe' ) );
				$this->_delete_subscription_token( $subscription );
			}
		}
		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
	}

	/**
	 * This function handles payment complete request by LaybyCafe.
	 * @version 1.4.3 Subscriptions flag
	 *
	 * @param array $data should be from the Gatewy ITN callback.
	 * @param WC_Order $order
	 */
	public function handle_itn_payment_complete( $data, $order, $subscriptions ) {
		$this->log( '- Complete' );
		$order->add_order_note( __( 'ITN payment completed', 'woocommerce-gateway-laybycafe' ) );
		$order_id = self::get_order_prop( $order, 'id' );

		$order->update_status( 'wc-on-layby', sprintf( __( 'Payment received via SETL-IT.', 'woocommerce-gateway-laybycafe' ), strtolower( sanitize_text_field( $order->get_status() ) ) ) );
		$debug_email   = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
		$vendor_name    = get_bloginfo( 'name', 'display' );
		$vendor_url     = home_url( '/' );

		if ( $this->send_debug_email ) {
			$subject = 'LaybyCafe ITN on your site';
			$body =
				"Hi,\n\n"
				. "A LaybyCafe transaction has been completed on your website\n"
				. "------------------------------------------------------------\n"
				. 'Site: ' . esc_html( $vendor_name ) . ' (' . esc_url( $vendor_url ) . ")\n"
				. 'Purchase ID: ' . esc_html( $data['transaction_id'] ) . "\n"
				. 'LaybyCafe Transaction ID: ' . esc_html( $data['transaction_id'] ) . "\n"
				. 'LaybyCafe Payment Status: ' . esc_html( $data['successful'] ) . "\n"
				. 'Order Status Code: ' . self::get_order_prop( $order, 'status' );
			wp_mail( $debug_email, $subject, $body );
		}

		wp_redirect( $this->get_return_url( $order ) );
		exit;
	}

	/**
	 * @param $data
	 * @param $order
	 */
	public function handle_itn_payment_failed( $data, $order ) {
		$this->log( '- Failed' );
		/* translators: 1: payment status */
		$order->update_status( 'failed', sprintf( __( 'Payment %s via ITN.', 'woocommerce-gateway-laybycafe' ), strtolower( sanitize_text_field( $data['payment_status'] ) ) ) );
		$debug_email   = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
		$vendor_name    = get_bloginfo( 'name', 'display' );
		$vendor_url     = home_url( '/' );

		if ( $this->send_debug_email ) {
			$subject = 'LaybyCafe ITN Transaction on your site';
			$body =
				"Hi,\n\n" .
				"A failed LaybyCafe transaction on your website requires attention\n" .
				"------------------------------------------------------------\n" .
				'Site: ' . esc_html( $vendor_name ) . ' (' . esc_url( $vendor_url ) . ")\n" .
				'Purchase ID: ' . self::get_order_prop( $order, 'id' ) . "\n" .
				'User ID: ' . self::get_order_prop( $order, 'user_id' ) . "\n" .
				'LaybyCafe Transaction ID: ' . esc_html( $data['lb_payment_id'] ) . "\n" .
				'LaybyCafe Payment Status: ' . esc_html( $data['payment_status'] );
			wp_mail( $debug_email, $subject, $body );
		}
	}

	/**
	 * @since 1.0 introduced
	 * @param $data
	 * @param $order
	 */
	public function handle_itn_payment_pending( $data, $order ) {
		$this->log( '- Pending' );
		// Need to wait for "Completed" before processing
		/* translators: 1: payment status */
		$order->update_status( 'on-hold', sprintf( __( 'Payment %s via ITN.', 'woocommerce-gateway-laybycafe' ), strtolower( sanitize_text_field( $data['payment_status'] ) ) ) );
	}

	/**
	 * @param string $order_id
	 * @return double
	 */
	public function get_pre_order_fee( $order_id ) {
		foreach ( wc_get_order( $order_id )->get_fees() as $fee ) {
			if ( is_array( $fee ) && 'Pre-Order Fee' == $fee['name'] ) {
				return doubleval( $fee['line_total'] ) + doubleval( $fee['line_tax'] );
			}
		}
	}
	/**
	 * @param string $order_id
	 * @return bool
	 */
	public function order_contains_pre_order( $order_id ) {
		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			return WC_Pre_Orders_Order::order_contains_pre_order( $order_id );
		}
		return false;
	}

	/**
	 * @param string $order_id
	 *
	 * @return bool
	 */
	public function order_requires_payment_tokenization( $order_id ) {
		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			return WC_Pre_Orders_Order::order_requires_payment_tokenization( $order_id );
		}
		return false;
	}

	/**
	 * @return bool
	 */
	public function cart_contains_pre_order_fee() {
		if ( class_exists( 'WC_Pre_Orders_Cart' ) ) {
			return WC_Pre_Orders_Cart::cart_contains_pre_order_fee();
		}
		return false;
	}
	/**
	 * Store the LaybyCafe subscription token
	 *
	 * @param string $token
	 * @param WC_Subscription $subscription
	 */
	protected function _set_subscription_token( $token, $subscription ) {
		update_post_meta( self::get_order_prop( $subscription, 'id' ), '_laybycafe_subscription_token', $token );
	}

	/**
	 * Retrieve the LaybyCafe subscription token for a given order id.
	 *
	 * @param WC_Subscription $subscription
	 * @return mixed
	 */
	protected function _get_subscription_token( $subscription ) {
		return get_post_meta( self::get_order_prop( $subscription, 'id' ), '_laybycafe_subscription_token', true );
	}

	/**
	 * Retrieve the LaybyCafe subscription token for a given order id.
	 *
	 * @param WC_Subscription $subscription
	 * @return mixed
	 */
	protected function _delete_subscription_token( $subscription ) {
		return delete_post_meta( self::get_order_prop( $subscription, 'id' ), '_laybycafe_subscription_token' );
	}

	/**
	 * Store the LaybyCafe renewal flag
	 * @since 1.4.3
	 *
	 * @param string $token
	 * @param WC_Subscription $subscription
	 */
	protected function _set_renewal_flag( $subscription ) {
		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			update_post_meta( self::get_order_prop( $subscription, 'id' ), '_laybycafe_renewal_flag', 'true' );
		} else {
			$subscription->update_meta_data( '_laybycafe_renewal_flag', 'true' );
			$subscription->save_meta_data();
		}
	}

	/**
	 * Retrieve the LaybyCafe renewal flag for a given order id.
	 * @since 1.4.3
	 *
	 * @param WC_Subscription $subscription
	 * @return bool
	 */
	protected function _has_renewal_flag( $subscription ) {
		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			return 'true' === get_post_meta( self::get_order_prop( $subscription, 'id' ), '_laybycafe_renewal_flag', true );
		} else {
			return 'true' === $subscription->get_meta( '_laybycafe_renewal_flag', true );
		}
	}

	/**
	 * Retrieve the LaybyCafe renewal flag for a given order id.
	 * @since 1.4.3
	 *
	 * @param WC_Subscription $subscription
	 * @return mixed
	 */
	protected function _delete_renewal_flag( $subscription ) {
		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			return delete_post_meta( self::get_order_prop( $subscription, 'id' ), '_laybycafe_renewal_flag' );
		} else {
			$subscription->delete_meta_data( '_laybycafe_renewal_flag' );
			$subscription->save_meta_data();
		}
	}

	/**
	 * Store the LaybyCafe pre_order_token token
	 *
	 * @param string   $token
	 * @param WC_Order $order
	 */
	protected function _set_pre_order_token( $token, $order ) {
		update_post_meta( self::get_order_prop( $order, 'id' ), '_laybycafe_pre_order_token', $token );
	}

	/**
	 * Retrieve the LaybyCafe pre-order token for a given order id.
	 *
	 * @param WC_Order $order
	 * @return mixed
	 */
	protected function _get_pre_order_token( $order ) {
		return get_post_meta( self::get_order_prop( $order, 'id' ), '_laybycafe_pre_order_token', true );
	}

	/**
	 * Wrapper function for wcs_order_contains_subscription
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public function order_contains_subscription( $order ) {
		if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return false;
		}
		return wcs_order_contains_subscription( $order );
	}

	/**
	 * @param $amount_to_charge
	 * @param WC_Order $renewal_order
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {

		$subscription = wcs_get_subscription( get_post_meta( self::get_order_prop( $renewal_order, 'id' ), '_subscription_renewal', true ) );
		$this->log( 'Attempting to renew subscription from renewal order ' . self::get_order_prop( $renewal_order, 'id' ) );

		if ( empty( $subscription ) ) {
			$this->log( 'Subscription from renewal order was not found.' );
			return;
		}

		$response = $this->submit_subscription_payment( $subscription, $amount_to_charge );

		if ( is_wp_error( $response ) ) {
			/* translators: 1: error code 2: error message */
			$renewal_order->update_status( 'failed', sprintf( __( 'LaybyCafe Subscription renewal transaction failed (%1$s:%2$s)', 'woocommerce-gateway-laybycafe' ), $response->get_error_code() ,$response->get_error_message() ) );
		}
		// Payment will be completion will be capture only when the ITN callback is sent to $this->handle_itn_request().
		$renewal_order->add_order_note( __( 'LaybyCafe Subscription renewal transaction submitted.', 'woocommerce-gateway-laybycafe' ) );

	}

	/**
	 * @param WC_Subscription $subscription
	 * @param $amount_to_charge
	 * @return mixed WP_Error on failure, bool true on success
	 */
	public function submit_subscription_payment( $subscription, $amount_to_charge ) {
		$token = $this->_get_subscription_token( $subscription );
		$item_name = $this->get_subscription_name( $subscription );

		foreach ( $subscription->get_related_orders( 'all', 'renewal' ) as $order ) {
			$statuses_to_charge = array( 'on-hold', 'failed', 'pending' );
			if ( in_array( $order->get_status(), $statuses_to_charge ) ) {
				$latest_order_to_renew = $order;
				break;
			}
		}
		$item_description = json_encode( array( 'renewal_order_id' => self::get_order_prop( $latest_order_to_renew, 'id' ) ) );

		return $this->submit_ad_hoc_payment( $token, $amount_to_charge, $item_name, $item_description );
	}

	/**
	 * Get a name for the subscription item. For multiple
	 * item only Subscription $date will be returned.
	 *
	 * For subscriptions with no items Site/Blog name will be returned.
	 *
	 * @param WC_Subscription $subscription
	 * @return string
	 */
	public function get_subscription_name( $subscription ) {

		if ( $subscription->get_item_count() > 1 ) {
			return $subscription->get_date_to_display( 'start' );
		} else {
			$items = $subscription->get_items();

			if ( empty( $items ) ) {
				return get_bloginfo( 'name' );
			}

			$item = array_shift( $items );
			return $item['name'];
		}
	}

	/**
	 * Setup api data for the the adhoc payment.
	 *
	 * @since 1.0 introduced.
	 * @param string $token
	 * @param double $amount_to_charge
	 * @param string $item_name
	 * @param string $item_description
	 *
	 * @return bool|WP_Error
	 */
	public function submit_ad_hoc_payment( $token, $amount_to_charge, $item_name, $item_description ) {
		$args = array(
			'body' => array(
				'amount'           => $amount_to_charge * 100, // convert to cents
				'item_name'        => $item_name,
				'item_description' => $item_description,
			),
		);
		return $this->api_request( 'adhoc', $token, $args );
	}

	/**
	 * Send off API request.
	 *
	 * @since 1.0 introduced.
	 *
	 * @param $command
	 * @param $token
	 * @param $api_args
	 * @param string $method GET | PUT | POST | DELETE.
	 *
	 * @return bool|WP_Error
	 */
	public function api_request( $command, $token, $api_args, $method = 'POST' ) {
		if ( empty( $token ) ) {
			$this->log( "Error posting API request: No token supplied", true );
			return new WP_Error( '404', __( 'Can not submit LaybyCafe request with an empty token', 'woocommerce-gateway-laybycafe' ), $results );
		}

		$api_endpoint  = "https://api.laybycafe.co.za/subscriptions/$token/$command";
		$api_endpoint .= 'yes' === $this->get_option( 'testmode' ) ? '?testing=true' : '';

		$timestamp = current_time( rtrim( DateTime::ATOM, 'P' ) ) . '+02:00';
		$api_args['timeout'] = 45;
		$api_args['headers'] = array(
			'merchant-id' => $this->token,
			'timestamp'   => $timestamp,
			'version'     => 'v1',
		);

		// generate signature
		$all_api_variables                = array_merge( $api_args['headers'], (array) $api_args['body'] );
		$api_args['headers']['signature'] = md5( $this->_generate_parameter_string( $all_api_variables ) );
		$api_args['method']               = strtoupper( $method );

		$results = wp_remote_request( $api_endpoint, $api_args );

		// Check LaybyCafe server response
		if ( 200 !== $results['response']['code'] ) {
			$this->log( "Error posting API request:\n" . print_r( $results['response'], true ) );
			return new WP_Error( $results['response']['code'], json_decode( $results['body'] )->data->response, $results );
		}

		// Check adhoc bank charge response
		$results_data = json_decode( $results['body'], true )['data'];
		if ( $command == 'adhoc' && 'true' !== $results_data['response'] ) {
			$this->log( "Error posting API request:\n" . print_r( $results_data , true ) );

			$code         = is_array( $results_data['response'] ) ? $results_data['response']['code'] : $results_data['response'];
			$message      = is_array( $results_data['response'] ) ? $results_data['response']['reason'] : $results_data['message'];
			// Use trim here to display it properly e.g. on an order note, since LaybyCafe can include CRLF in a message.
			return new WP_Error( $code, trim( $message ), $results );
		}

		$maybe_json = json_decode( $results['body'], true );

		if ( ! is_null( $maybe_json ) && isset( $maybe_json['status'] ) && 'failed' === $maybe_json['status'] ) {
			$this->log( "Error posting API request:\n" . print_r( $results['body'], true ) );

			// Use trim here to display it properly e.g. on an order note, since LaybyCafe can include CRLF in a message.
			return new WP_Error( $maybe_json['code'], trim( $maybe_json['data']['message'] ), $results['body'] );
		}

		return true;
	}

	/**
	 * Responds to Subscriptions extension cancellation event.
	 *
	 * @since 1.0 introduced.
	 * @param WC_Subscription $subscription
	 */
	public function cancel_subscription_listener( $subscription ) {
		$token = $this->_get_subscription_token( $subscription );
		if ( empty( $token ) ) {
			return;
		}
		$this->api_request( 'cancel', $token, array(), 'PUT' );
	}

	/**
	 * @since 1.0
	 * @param string $token
	 *
	 * @return bool|WP_Error
	 */
	public function cancel_pre_order_subscription( $token ) {
		return $this->api_request( 'cancel', $token, array(), 'PUT' );
	}

	/**
	 * @since 1.0 introduced.
	 * @param      $api_data
	 * @param bool $sort_data_before_merge? default true.
	 * @param bool $skip_empty_values Should key value pairs be ignored when generating signature?  Default true.
	 *
	 * @return string
	 */
	protected function _generate_parameter_string( $api_data, $sort_data_before_merge = true, $skip_empty_values = true ) {

		// if sorting is required the passphrase should be added in before sort.
		if ( ! empty( $this->pass_phrase ) && $sort_data_before_merge ) {
			$api_data['passphrase'] = $this->pass_phrase;
		}

		if ( $sort_data_before_merge ) {
			ksort( $api_data );
		}

		// concatenate the array key value pairs.
		$parameter_string = '';
		foreach ( $api_data as $key => $val ) {

			if ( $skip_empty_values && empty( $val ) ) {
				continue;
			}

			if ( 'signature' !== $key ) {
				$val = urlencode( $val );
				$parameter_string .= "$key=$val&";
			}
		}
		// when not sorting passphrase should be added to the end before md5
		if ( $sort_data_before_merge ) {
			$parameter_string = rtrim( $parameter_string, '&' );
		} elseif ( ! empty( $this->pass_phrase ) ) {
			$parameter_string .= 'passphrase=' . urlencode( $this->pass_phrase );
		} else {
			$parameter_string = rtrim( $parameter_string, '&' );
		}

		return $parameter_string;
	}

	/**
	 * @since 1.0 introduced.
	 * @param WC_Order $order
	 */
	public function process_pre_order_payments( $order ) {

		// The total amount to charge is the the order's total.
		$total = $order->get_total() - $this->get_pre_order_fee( self::get_order_prop( $order, 'id' ) );
		$token = $this->_get_pre_order_token( $order );

		if ( ! $token ) {
			return;
		}
		// get the payment token and attempt to charge the transaction
		$item_name = 'pre-order';
		$results = $this->submit_ad_hoc_payment( $token, $total, $item_name );

		if ( is_wp_error( $results ) ) {
			/* translators: 1: error code 2: error message */
			$order->update_status( 'failed', sprintf( __( 'Layby Cafe Pre-Order payment transaction failed (%1$s:%2$s)', 'woocommerce-gateway-laybycafe' ), $results->get_error_code() ,$results->get_error_message() ) );
			return;
		}

		// Payment completion will be handled by ITN callback
	}

	/**
	 * Setup constants.
	 *
	 * Setup common values and messages used by the LaybyCafe gateway.
	 *
	 * @since 1.0
	 */
	public function setup_constants() {
		// Create user agent string.
		define( 'LAYBYC_SOFTWARE_NAME', 'WooCommerce' );
		define( 'LAYBYC_SOFTWARE_VER', WC_VERSION );
		define( 'LAYBYC_MODULE_NAME', 'WooCommerce-LaybyCafe-Free' );
		define( 'LAYBYC_MODULE_VER', $this->version );

		// Features
		// - PHP
		$lb_features = 'PHP ' . phpversion() . ';';

		// - cURL
		if ( in_array( 'curl', get_loaded_extensions() ) ) {
			define( 'LAYBYC_CURL', '' );
			$lb_version = curl_version();
			$lb_features .= ' curl ' . $lb_version['version'] . ';';
		} else {
			$lb_features .= ' nocurl;';
		}

		// Create user agrent
		define( 'LAYBYC_USER_AGENT', LAYBYC_SOFTWARE_NAME . '/' . LAYBYC_SOFTWARE_VER . ' (' . trim( $lb_features ) . ') ' . LAYBYC_MODULE_NAME . '/' . LAYBYC_MODULE_VER );

		// General Defines
		define( 'LAYBYC_TIMEOUT', 15 );
		define( 'LAYBYC_EPSILON', 0.01 );

		// Messages
		// Error
		define( 'LAYBYC_ERR_AMOUNT_MISMATCH', __( 'Amount mismatch', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_BAD_ACCESS', __( 'Bad access of page', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_BAD_SOURCE_IP', __( 'Bad source IP address', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_CONNECT_FAILED', __( 'Failed to connect to LaybyCafe', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_INVALID_SIGNATURE', __( 'Security signature mismatch', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_MERCHANT_ID_MISMATCH', __( 'Merchant Token mismatch', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_NO_SESSION', __( 'No saved session found for ITN transaction', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_ORDER_ID_MISSING_URL', __( 'Order ID not present in URL', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_ORDER_ID_MISMATCH', __( 'Order ID mismatch', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_ORDER_INVALID', __( 'This order ID is invalid', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_ORDER_NUMBER_MISMATCH', __( 'Order Number mismatch', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_ORDER_PROCESSED', __( 'This order has already been processed', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_PDT_FAIL', __( 'PDT query failed', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_PDT_TOKEN_MISSING', __( 'PDT token not present in URL', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_SESSIONID_MISMATCH', __( 'Session ID mismatch', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_ERR_UNKNOWN', __( 'Unkown error occurred', 'woocommerce-gateway-laybycafe' ) );

		// General
		define( 'LAYBYC_MSG_OK', __( 'Payment was successful', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_MSG_FAILED', __( 'Payment has failed', 'woocommerce-gateway-laybycafe' ) );
		define( 'LAYBYC_MSG_PENDING', __( 'The payment is pending. Please note, you will receive another Instant Transaction Notification when the payment status changes to "Completed", or "Failed"', 'woocommerce-gateway-laybycafe' ) );

		do_action( 'woocommerce_gateway_laybycafe_setup_constants' );
	}

	/**
	 * Log system processes.
	 * @since 1.0
	 */
	public function log( $message ) {
		if ( 'yes' === $this->get_option( 'testmode' ) || $this->enable_logging ) {
			if ( empty( $this->logger ) ) {
				$this->logger = new WC_Logger();
			}
			$this->logger->add( 'laybycafe', $message );
		}
	}

	/**
	 * validate_signature()
	 *
	 * Validate the signature against the returned data.
	 *
	 * @param array $data
	 * @param string $signature
	 * @since 1.0
	 * @return string
	 */
	public function validate_signature( $data, $signature ) {
	    $result = $data['signature'] === $signature;
	    $this->log( 'Signature = ' . ( $result ? 'valid' : 'invalid' ) );
	    return $result;
	}

	/**
	 * Validate the IP address to make sure it's coming from LaybyCafe.
	 *
	 * @param array $source_ip
	 * @since 1.0
	 * @return bool
	 */
	public function is_valid_ip( $source_ip ) {
		// Variable initialization
		$valid_hosts = array(
			'www.laybycafe.com',
			'apiv2.laybycafe.com',
			'w1w.laybycafe.com',
			'w2w.laybycafe.com',
		);

		$valid_ips = array();

		foreach ( $valid_hosts as $lb_hostname ) {
			$ips = gethostbynamel( $lb_hostname );

			if ( false !== $ips ) {
				$valid_ips = array_merge( $valid_ips, $ips );
			}
		}

		// Remove duplicates
		$valid_ips = array_unique( $valid_ips );

		// Adds support for X_Forwarded_For
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$source_ip = (string) rest_is_ip_address( trim( current( preg_split( '/[,:]/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) ) ) ?: $source_ip;
		}

		$this->log( "Valid IPs:\n" . print_r( $valid_ips, true ) );
		$is_valid_ip = in_array( $source_ip, $valid_ips );
		return apply_filters( 'woocommerce_gateway_laybycafe_is_valid_ip', $is_valid_ip, $source_ip );
	}

	/**
	 * validate_response_data()
	 *
	 * @param array $post_data
	 * @param string $proxy Address of proxy to use or NULL if no proxy.
	 * @since 1.0
	 * @return bool
	 */
	public function validate_response_data( $post_data, $proxy = null ) {
		$this->log( 'Host = ' . $this->validate_url );
		$this->log( 'Params = ' . print_r( $post_data, true ) );

		if ( ! is_array( $post_data ) ) {
			return false;
		}

		$response = wp_remote_post( $this->validate_url, array(
			'body'       => $post_data,
			'timeout'    => 70,
			'user-agent' => LAYBYC_USER_AGENT,
		));

		if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
			$this->log( "Response error:\n" . print_r( $response, true ) );
			return false;
		}

		parse_str( $response['body'], $parsed_response );

		$response = $parsed_response;

		$this->log( "Response:\n" . print_r( $response, true ) );

		// Interpret Response
		if ( is_array( $response ) && in_array( 'VALID', array_keys( $response ) ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * amounts_equal()
	 *
	 * Checks to see whether the given amounts are equal using a proper floating
	 * point comparison with an Epsilon which ensures that insignificant decimal
	 * places are ignored in the comparison.
	 *
	 * eg. 100.00 is equal to 100.0001
	 *
	 * @author Jonathan Smit
	 * @param $amount1 Float 1st amount for comparison
	 * @param $amount2 Float 2nd amount for comparison
	 * @since 1.0
	 * @return bool
	 */
	public function amounts_equal( $amount1, $amount2 ) {
		return ! ( abs( floatval( $amount1 ) - floatval( $amount2 ) ) > LAYBYC_EPSILON );
	}

	/**
	 * Get order property with compatibility check on order getter introduced
	 * in WC 3.0.
	 *
	 * @since 1.0
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $prop  Property name.
	 *
	 * @return mixed Property value
	 */
	public static function get_order_prop( $order, $prop ) {
		switch ( $prop ) {
			case 'order_total':
				$getter = array( $order, 'get_total' );
				break;
			default:
				$getter = array( $order, 'get_' . $prop );
				break;
		}

		return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $prop };
	}

}
