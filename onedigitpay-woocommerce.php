<?php
/**
 * Plugin Name: WooCommerce OneDigitPay
 * Plugin URI: https://business.onedigitpay.com
 * Description: Extends WooCommerce with OneDigitPay gateway. Customers are redirected to the OneDigitPay checkout page to complete payment.
 * Version: 0.3.4
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

	// Enqueue inline checkout SDK on the checkout page when inline mode is active.
	add_action( 'wp_enqueue_scripts', 'onedigitpay_woocommerce_enqueue_inline_sdk' );
}

/**
 * Enqueue the OneDigitPay checkout SDK and integration script when inline mode is active.
 */
function onedigitpay_woocommerce_enqueue_inline_sdk() {
	if ( ! is_checkout() ) {
		return;
	}

	$gateway = new WC_Gateway_OneDigitPay();
	if ( 'inline' !== $gateway->get_option( 'payment_mode', 'redirect' ) ) {
		return;
	}

	$default_sdk_url = 'https://cdn.onedigitpay.com/checkout.v1.js';
	$sdk_url         = $gateway->get_option( 'sdk_url', $default_sdk_url );

	// Validate SDK URL: must be a valid https:// URL.
	$sdk_url = esc_url( $sdk_url, array( 'https' ) );
	if ( empty( $sdk_url ) ) {
		$sdk_url = $default_sdk_url;
	}

	$ajax_url = admin_url( 'admin-ajax.php' );

	// Load the OneDigitPay SDK (depends on jQuery for the integration script).
	wp_enqueue_script( 'onedigitpay-sdk', $sdk_url, array( 'jquery' ), null, true );

	// Inline JS that intercepts the WooCommerce checkout response and opens the popup.
	$inline_js = "
	(function() {
		if (typeof jQuery === 'undefined') return;

		var openedBySession = {};
		var openingInProgress = false;

		function parseCheckoutResponse(xhr) {
			if (!xhr) return null;
			if (xhr.responseJSON) return xhr.responseJSON;
			if (!xhr.responseText) return null;
			try {
				return JSON.parse(xhr.responseText);
			} catch (e) {
				return null;
			}
		}

		function openInlineCheckout(data) {
			if (!data || !data.odp_session_id) return false;
			if (openingInProgress || openedBySession[data.odp_session_id]) return true;

			if (typeof OneDigitPay !== 'undefined' && typeof OneDigitPay.open === 'function') {
				openingInProgress = true;
				openedBySession[data.odp_session_id] = true;

				OneDigitPay.open({
					sessionId: data.odp_session_id,
					apiBase: data.odp_api_base,
					onSuccess: function() {
						// Finalize order first (WC-API return handler verifies and marks paid),
						// then gateway redirects customer to the standard thank-you page.
						if (data.odp_finalize_url) {
							window.location.href = data.odp_finalize_url;
							return;
						}
						if (data.odp_thank_you_url) {
							window.location.href = data.odp_thank_you_url;
						}
					},
					onClose: function() {
						openingInProgress = false;
					},
					onError: function(err) {
						openingInProgress = false;
						console.error('OneDigitPay error:', err);
					}
				});
				return true;
			}

			return false;
		}

		jQuery(document).ajaxComplete(function(event, xhr, settings) {
			if (!settings.url || settings.url.indexOf('wc-ajax=checkout') === -1) return;

			var data = parseCheckoutResponse(xhr);

			if (!data || data.result !== 'success' || !data.odp_inline) return;

			// Open the OneDigitPay popup, or fall back to redirect if SDK failed to load.
			if (!openInlineCheckout(data)) {
				// SDK not available (CDN blocked, CSP, ad-blocker, etc.) — fall back to redirect mode.
				if (data.odp_pending_url) {
					window.location.href = data.odp_pending_url;
				}
			}
		});
	})();
	";

	wp_add_inline_script( 'onedigitpay-sdk', $inline_js );
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
require_once dirname( __FILE__ ) . '/includes/class-wc-onedigitpay-cron.php';

/**
 * Activation callback.
 */
function onedigitpay_woocommerce_activate() {
	if ( ! class_exists( 'WC_OneDigitPay_Cron' ) ) {
		require_once dirname( __FILE__ ) . '/includes/class-wc-onedigitpay-cron.php';
	}
	if ( class_exists( 'WC_OneDigitPay_Cron' ) ) {
		WC_OneDigitPay_Cron::schedule();
	}
}

/**
 * Deactivation callback.
 */
function onedigitpay_woocommerce_deactivate() {
	if ( ! class_exists( 'WC_OneDigitPay_Cron' ) ) {
		require_once dirname( __FILE__ ) . '/includes/class-wc-onedigitpay-cron.php';
	}
	if ( class_exists( 'WC_OneDigitPay_Cron' ) ) {
		WC_OneDigitPay_Cron::unschedule();
	}
}

register_activation_hook( __FILE__, 'onedigitpay_woocommerce_activate' );
register_deactivation_hook( __FILE__, 'onedigitpay_woocommerce_deactivate' );
