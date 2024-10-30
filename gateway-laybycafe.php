<?php
/**
 * Plugin Name: Layby Cafe Gateway
 * Plugin URI: https://woocommerce.com/products/layby-cafe-gateway/
 * Description: Layby your orders using the South African Layby Cafe payment provider.
 * Author: Layby Cafe
 * Author URI: http://laybycafe.com/
 * Version: 1.0
 * Requires at least: 4.4
 * Tested up to: 5.6
 * WC tested up to: 4.7
 * WC requires at least: 2.6
 *
 */
defined( 'ABSPATH' ) || exit;

/**
 * Initialize the gateway.
 * @since 1.0
 */
function woocommerce_laybycafe_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	define( 'WC_GATEWAY_LAYBYCAFE_VERSION', '1.0' ); // WRCS: DEFINED_VERSION.

	require_once( plugin_basename( 'includes/class-wc-gateway-laybycafe.php' ) );
	require_once( plugin_basename( 'includes/class-wc-gateway-laybycafe-privacy.php' ) );
	load_plugin_textdomain( 'woocommerce-gateway-laybycafe', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) );
	add_filter( 'woocommerce_payment_gateways', 'woocommerce_laybycafe_add_gateway' );
}
add_action( 'plugins_loaded', 'woocommerce_laybycafe_init', 0 );

function woocommerce_laybycafe_plugin_links( $links ) {
	$settings_url = add_query_arg(
		array(
			'page' => 'wc-settings',
			'tab' => 'checkout',
			'section' => 'wc_gateway_laybycafe',
		),
		admin_url( 'admin.php' )
	);

	$plugin_links = array(
		'<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'woocommerce-gateway-laybycafe' ) . '</a>',
		'<a href="https://www.woocommerce.com/my-account/tickets/">' . __( 'Support', 'woocommerce-gateway-laybycafe' ) . '</a>',
		'<a href="https://docs.woocommerce.com/document/layby-cafe-gateway/">' . __( 'Docs', 'woocommerce-gateway-laybycafe' ) . '</a>',
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woocommerce_laybycafe_plugin_links' );


/**
 * Add the gateway to WooCommerce
 * @since 1.0.0
 */
function woocommerce_laybycafe_add_gateway( $methods ) {
	$methods[] = 'WC_Gateway_LaybyCafe';
	return $methods;
}
