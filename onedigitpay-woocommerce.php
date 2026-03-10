<?php
/**
 * Plugin Name: WooCommerce OneDigitPay
 * Plugin URI: https://business.onedigitpay.com
 * Description: Extends WooCommerce with OneDigitPay gateway. Customers are redirected to the OneDigitPay checkout page to complete payment.
 * Version: 0.2.1
 * Author: OneDigitPay
 * Author URI: https://onedigitpay.com
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: onedigitpay-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 *
 * @package OneDigitPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', 'onedigitpay_woocommerce_init', 0 );

/**
 * Initialise the gateway after WooCommerce loads.
 */
function onedigitpay_woocommerce_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	load_plugin_textdomain(
		'onedigitpay-woocommerce',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);

	require_once dirname( __FILE__ ) . '/includes/class-wc-onedigitpay-api.php';
	require_once dirname( __FILE__ ) . '/includes/class-wc-gateway-onedigitpay.php';
	require_once dirname( __FILE__ ) . '/includes/class-wc-onedigitpay-cron.php';

	add_filter( 'woocommerce_payment_gateways', 'onedigitpay_woocommerce_add_gateway' );

	// WC-API callback: customer returns from OneDigitPay after payment.
	add_action( 'woocommerce_api_wc_gateway_onedigitpay', 'onedigitpay_woocommerce_handle_return' );

	// WC-API callback: render the payment-pending page.
	add_action( 'woocommerce_api_odp_payment_pending', 'onedigitpay_woocommerce_payment_pending' );

	// AJAX: client-side polling for payment status (logged-in and guest).
	add_action( 'wp_ajax_odp_check_status', array( 'WC_Gateway_OneDigitPay', 'ajax_check_payment_status' ) );
	add_action( 'wp_ajax_nopriv_odp_check_status', array( 'WC_Gateway_OneDigitPay', 'ajax_check_payment_status' ) );

	// Background cron: poll pending orders every 5 minutes.
	WC_OneDigitPay_Cron::init();
}

/**
 * Add the gateway to WooCommerce.
 *
 * @param array $methods Existing payment methods.
 * @return array
 */
function onedigitpay_woocommerce_add_gateway( $methods ) {
	$methods[] = 'WC_Gateway_OneDigitPay';
	return $methods;
}

/**
 * Handle return from OneDigitPay (WC-API callback).
 */
function onedigitpay_woocommerce_handle_return() {
	$gateway = new WC_Gateway_OneDigitPay();
	$gateway->handle_return();
}

/**
 * Render the payment-pending page (WC-API callback).
 */
function onedigitpay_woocommerce_payment_pending() {
	$gateway = new WC_Gateway_OneDigitPay();
	$gateway->render_payment_pending_page();
}

/*
 * Activation / deactivation hooks for cron scheduling.
 */
register_activation_hook( __FILE__, array( 'WC_OneDigitPay_Cron', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'WC_OneDigitPay_Cron', 'unschedule' ) );
