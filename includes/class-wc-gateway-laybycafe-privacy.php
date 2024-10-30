<?php
if ( ! class_exists( 'WC_Abstract_Privacy' ) ) {
	return;
}

class WC_Gateway_LaybyCafe_Privacy extends WC_Abstract_Privacy {
	/**
	 * Constructor
	 *
	 */
	public function __construct() {
		parent::__construct( __( 'LaybyCafe', 'woocommerce-gateway-laybycafe' ) );

		$this->add_exporter( 'woocommerce-gateway-laybycafe-order-data', __( 'WooCommerce LaybyCafe Order Data', 'woocommerce-gateway-laybycafe' ), array( $this, 'order_data_exporter' ) );

		if ( function_exists( 'wcs_get_subscriptions' ) ) {
			$this->add_exporter( 'woocommerce-gateway-laybycafe-subscriptions-data', __( 'WooCommerce LaybyCafe Subscriptions Data', 'woocommerce-gateway-laybycafe' ), array( $this, 'subscriptions_data_exporter' ) );
		}

		$this->add_eraser( 'woocommerce-gateway-laybycafe-order-data', __( 'WooCommerce LaybyCafe Data', 'woocommerce-gateway-laybycafe' ), array( $this, 'order_data_eraser' ) );
	}

	/**
	 * Returns a list of orders that are using one of LaybyCafe's payment methods.
	 *
	 * @param string  $email_address
	 * @param int     $page
	 *
	 * @return array WP_Post
	 */
	protected function get_laybycafe_orders( $email_address, $page ) {
		$user = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.

		$order_query    = array(
			'payment_method' => 'laybycafe',
			'limit'          => 10,
			'page'           => $page,
		);

		if ( $user instanceof WP_User ) {
			$order_query['customer_id'] = (int) $user->ID;
		} else {
			$order_query['billing_email'] = $email_address;
		}

		return wc_get_orders( $order_query );
	}

	/**
	 * Gets the message of the privacy to display.
	 *
	 */
	public function get_privacy_message() {
		return wpautop( sprintf( __( 'By using this extension, you may be storing personal data or sharing data with an external service. <a href="%s" target="_blank">Learn more about how this works, including what you may want to include in your privacy policy.</a>', 'woocommerce-gateway-laybycafe' ), 'https://docs.woocommerce.com/document/privacy-payments/#woocommerce-gateway-laybycafe' ) );
	}

	/**
	 * Handle exporting data for Orders.
	 *
	 * @param string $email_address E-mail address to export.
	 * @param int    $page          Pagination of data.
	 *
	 * @return array
	 */
	public function order_data_exporter( $email_address, $page = 1 ) {
		$done           = false;
		$data_to_export = array();

		$orders = $this->get_laybycafe_orders( $email_address, (int) $page );

		$done = true;

		if ( 0 < count( $orders ) ) {
			foreach ( $orders as $order ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_orders',
					'group_label' => __( 'Orders', 'woocommerce-gateway-laybycafe' ),
					'item_id'     => 'order-' . $order->get_id(),
					'data'        => array(
						array(
							'name'  => __( 'LaybyCafe token', 'woocommerce-gateway-laybycafe' ),
							'value' => get_post_meta( $order->get_id(), '_laybycafe_pre_order_token', true ),
						),
					),
				);
			}

			$done = 10 > count( $orders );
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Handle exporting data for Subscriptions.
	 *
	 * @param string $email_address E-mail address to export.
	 * @param int    $page          Pagination of data.
	 *
	 * @return array
	 */
	public function subscriptions_data_exporter( $email_address, $page = 1 ) {
		$done           = false;
		$page           = (int) $page;
		$data_to_export = array();

		$meta_query = array(
			'relation'    => 'AND',
			array(
				'key'     => '_payment_method',
				'value'   => 'laybycafe',
				'compare' => '=',
			),
			array(
				'key'     => '_billing_email',
				'value'   => $email_address,
				'compare' => '=',
			),
		);

		$subscription_query    = array(
			'posts_per_page'  => 10,
			'page'            => $page,
			'meta_query'      => $meta_query,
		);

		$subscriptions = wcs_get_subscriptions( $subscription_query );

		$done = true;

		if ( 0 < count( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_subscriptions',
					'group_label' => __( 'Subscriptions', 'woocommerce-gateway-laybycafe' ),
					'item_id'     => 'subscription-' . $subscription->get_id(),
					'data'        => array(
						array(
							'name'  => __( 'LaybyCafe subscription token', 'woocommerce-gateway-laybycafe' ),
							'value' => get_post_meta( $subscription->get_id(), '_laybycafe_subscription_token', true ),
						),
					),
				);
			}

			$done = 10 > count( $subscriptions );
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Finds and erases order data by email address.
	 *
	 * @since 3.4.0
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of personal data in name value pairs
	 */
	public function order_data_eraser( $email_address, $page ) {
		$orders = $this->get_laybycafe_orders( $email_address, (int) $page );

		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		foreach ( (array) $orders as $order ) {
			$order = wc_get_order( $order->get_id() );

			list( $removed, $retained, $msgs ) = $this->maybe_handle_order( $order );
			$items_removed  |= $removed;
			$items_retained |= $retained;
			$messages        = array_merge( $messages, $msgs );

			list( $removed, $retained, $msgs ) = $this->maybe_handle_subscription( $order );
			$items_removed  |= $removed;
			$items_retained |= $retained;
			$messages        = array_merge( $messages, $msgs );
		}

		// Tell core if we have more orders to work on still
		$done = count( $orders ) < 10;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Handle eraser of data tied to Subscriptions
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	protected function maybe_handle_subscription( $order ) {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return array( false, false, array() );
		}

		if ( ! wcs_order_contains_subscription( $order ) ) {
			return array( false, false, array() );
		}

		$subscription    = current( wcs_get_subscriptions_for_order( $order->get_id() ) );
		$subscription_id = $subscription->get_id();

		$laybycafe_source_id = get_post_meta( $subscription_id, '_laybycafe_subscription_token', true );

		if ( empty( $laybycafe_source_id ) ) {
			return array( false, false, array() );
		}

		if ( $subscription->has_status( apply_filters( 'wc_laybycafe_privacy_eraser_subs_statuses', array( 'on-hold', 'active' ) ) ) ) {
			return array( false, true, array( sprintf( __( 'Order ID %d contains an active Subscription' ), $order->get_id() ) ) );
		}

		$renewal_orders = WC_Subscriptions_Renewal_Order::get_renewal_orders( $order->get_id() );

		foreach ( $renewal_orders as $renewal_order_id ) {
			delete_post_meta( $renewal_order_id, '_laybycafe_subscription_token' );
		}

		delete_post_meta( $subscription_id, '_laybycafe_subscription_token' );

		return array( true, false, array( __( 'LaybyCafe Subscriptions Data Erased.', 'woocommerce-gateway-laybycafe' ) ) );
	}

	/**
	 * Handle eraser of data tied to Orders
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	protected function maybe_handle_order( $order ) {
		$order_id      = $order->get_id();
		$laybycafe_token = get_post_meta( $order_id, '_laybycafe_pre_order_token', true );

		if ( empty( $laybycafe_token ) ) {
			return array( false, false, array() );
		}

		delete_post_meta( $order_id, '_laybycafe_pre_order_token' );

		return array( true, false, array( __( 'LaybyCafe Order Data Erased.', 'woocommerce-gateway-laybycafe' ) ) );
	}
}

new WC_Gateway_LaybyCafe_Privacy();
