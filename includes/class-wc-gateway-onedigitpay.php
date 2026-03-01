<?php
/**
 * WooCommerce OneDigitPay gateway.
 *
 * Redirects customer to OneDigitPay hosted checkout; on return, verifies session status and completes order.
 *
 * @package OneDigitPay_WooCommerce
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Gateway_OneDigitPay
 */
class WC_Gateway_OneDigitPay extends WC_Payment_Gateway {

	/**
	 * Order meta key for OneDigitPay session ID.
	 */
	const META_SESSION_ID = '_onedigitpay_session_id';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'onedigitpay';
		$this->method_title       = __( 'OneDigitPay', 'onedigitpay-woocommerce' );
		$this->method_description = __( 'Accept payments via OneDigitPay. Customers are redirected to the OneDigitPay checkout page.', 'onedigitpay-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialise gateway form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'onedigitpay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable OneDigitPay', 'onedigitpay-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'onedigitpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', 'onedigitpay-woocommerce' ),
				'default'     => __( 'OneDigitPay', 'onedigitpay-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'onedigitpay-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Optional description shown at checkout.', 'onedigitpay-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'merchant_token' => array(
				'title'       => __( 'Merchant Token', 'onedigitpay-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your merchant token from OneDigitPay dashboard (Settings → Tokens).', 'onedigitpay-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'api_base'       => array(
				'title'       => __( 'API base URL', 'onedigitpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'OneDigitPay API base URL. Leave default for production.', 'onedigitpay-woocommerce' ),
				'default'     => 'https://prod-api-business.onedigitpay.com/api/v1',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Get the return URL where the customer lands after payment (used to verify status).
	 *
	 * @param WC_Order $order Order instance.
	 * @return string
	 */
	private function get_return_url_for_order( $order ) {
		return add_query_arg(
			array(
				'wc-api'    => 'WC_Gateway_OneDigitPay',
				'order_id'  => $order->get_id(),
				'order_key' => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Invalid order.', 'onedigitpay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$token   = $this->get_option( 'merchant_token' );
		$api_base = $this->get_option( 'api_base' );
		if ( empty( $token ) || empty( $api_base ) ) {
			wc_add_notice( __( 'OneDigitPay is not configured. Please contact the store.', 'onedigitpay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$currency = $order->get_currency();
		if ( strtoupper( $currency ) !== 'NGN' ) {
			wc_add_notice( __( 'OneDigitPay only supports NGN. Please switch store currency or choose another payment method.', 'onedigitpay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Amount in Naira (major units) so checkout displays correctly (e.g. 459.95 not 45,995).
		$amount = round( (float) $order->get_total(), 2 );

		$payload = array(
			'amount'        => $amount,
			'order_id'      => 'wc-' . $order->get_id() . '-' . $order->get_order_key(),
			'redirect_url'  => $this->get_return_url_for_order( $order ),
			'currency'      => 'NGN',
			'metaData'      => array(
				'firstName' => $order->get_billing_first_name(),
				'lastName'  => $order->get_billing_last_name(),
				'email'     => $order->get_billing_email(),
			),
		);

		$api   = new WC_OneDigitPay_API( $api_base, $token );
		$result = $api->create_checkout_session( $payload );

		if ( is_wp_error( $result ) ) {
			$message   = $result->get_error_message();
			$err_data  = $result->get_error_data();
			if ( $err_data && isset( $err_data['status_code'] ) && (int) $err_data['status_code'] === 401 ) {
				$message = __( 'OneDigitPay authentication failed. Please check your Merchant Token in settings.', 'onedigitpay-woocommerce' );
			}
			wc_add_notice( $message, 'error' );
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->debug( 'OneDigitPay create session error: ' . $result->get_error_message(), array( 'source' => 'onedigitpay' ) );
			}
			return array( 'result' => 'failure' );
		}

		if ( empty( $result['success'] ) || empty( $result['checkout_url'] ) ) {
			wc_add_notice( __( 'Payment could not be started. Please try again or use another method.', 'onedigitpay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( self::META_SESSION_ID, $result['session_id'] );
		$order->set_status( 'on-hold', __( 'Awaiting OneDigitPay payment.', 'onedigitpay-woocommerce' ) );
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $result['checkout_url'],
		);
	}

	/**
	 * Handle return from OneDigitPay: verify session status and complete or redirect.
	 */
	public function handle_return() {
		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

		if ( ! $order_id || ! $order_key ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_order_key() !== $order_key ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Idempotency: already paid, skip API call and go to thank-you.
		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$session_id = $order->get_meta( self::META_SESSION_ID );
		if ( empty( $session_id ) ) {
			$order->add_order_note( __( 'OneDigitPay return: no session ID found.', 'onedigitpay-woocommerce' ) );
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->debug( 'OneDigitPay return: order ' . $order_id . ' has no session_id.', array( 'source' => 'onedigitpay' ) );
			}
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$api_base = $this->get_option( 'api_base' );
		$api      = new WC_OneDigitPay_API( $api_base, $this->get_option( 'merchant_token' ) );
		$result   = $api->get_session_status( $session_id );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( __( 'OneDigitPay status check failed: ', 'onedigitpay-woocommerce' ) . $result->get_error_message() );
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->debug( 'OneDigitPay status check error: ' . $result->get_error_message(), array( 'source' => 'onedigitpay' ) );
			}
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$status = isset( $result['status'] ) ? $result['status'] : '';

		if ( ! empty( $result['success'] ) && $status === 'COMPLETED' ) {
			$order->payment_complete();
			$order->add_order_note( __( 'Payment completed via OneDigitPay.', 'onedigitpay-woocommerce' ) );
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: payment status from API (e.g. PENDING, FAILED) */
					__( 'Customer returned from OneDigitPay. Payment status: %s.', 'onedigitpay-woocommerce' ),
					$status ? $status : __( 'unknown', 'onedigitpay-woocommerce' )
				)
			);
			// Mark as failed if status indicates terminal failure (e.g. FAILED, EXPIRED).
			if ( in_array( $status, array( 'FAILED', 'EXPIRED', 'CANCELLED' ), true ) ) {
				$order->update_status( 'failed', __( 'OneDigitPay payment did not complete.', 'onedigitpay-woocommerce' ) );
			}
		}

		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}
}
