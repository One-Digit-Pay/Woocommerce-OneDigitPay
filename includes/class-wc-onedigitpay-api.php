<?php
/**
 * OneDigitPay API client for WooCommerce.
 *
 * Handles POST create checkout session and GET session status using wp_remote_*.
 *
 * @package OneDigitPay_WooCommerce
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OneDigitPay_API
 */
class WC_OneDigitPay_API {

	/**
	 * API base URL (e.g. https://prod-api-business.onedigitpay.com/api/v1).
	 *
	 * @var string
	 */
	private $api_base;

	/**
	 * Merchant token for Bearer auth.
	 *
	 * @var string
	 */
	private $merchant_token;

	/**
	 * Constructor.
	 *
	 * @param string $api_base       Base URL for the OneDigitPay API.
	 * @param string $merchant_token Merchant token from OneDigitPay dashboard.
	 */
	public function __construct( $api_base, $merchant_token ) {
		$this->api_base       = rtrim( $api_base, '/' );
		$this->merchant_token = $merchant_token;
	}

	/**
	 * Create a checkout session.
	 *
	 * @param array $payload Keys: amount (float, Naira major units e.g. 459.95), order_id, redirect_url, currency, metaData (firstName, lastName, email).
	 * @return array{success: bool, session_id?: string, checkout_url?: string, order_id?: string}|WP_Error On success returns session data; on failure WP_Error or array with success false.
	 */
	public function create_checkout_session( $payload ) {
		$url  = $this->api_base . '/authenticate/checkout-session';
		$body = wp_json_encode( $payload );
		$args = array(
			'headers'  => array(
				'Authorization' => 'Bearer ' . $this->merchant_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => $body,
			'timeout' => 30,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$data     = json_decode( $body_raw, true );

		if ( $code !== 201 ) {
			$message = isset( $data['msg'] ) ? $data['msg'] : __( 'Failed to create checkout session.', 'onedigitpay-woocommerce' );
			if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$message .= ' ' . implode( ' ', $data['errors'] );
			}
			return new WP_Error( 'onedigitpay_api_error', $message, array( 'status_code' => $code, 'response' => $data ) );
		}

		if ( empty( $data['success'] ) || empty( $data['data']['session']['checkout_url'] ) ) {
			return new WP_Error( 'onedigitpay_api_error', __( 'Invalid response from payment provider.', 'onedigitpay-woocommerce' ), array( 'response' => $data ) );
		}

	$session = $data['data']['session'];

		if ( empty( $session['session_id'] ) ) {
			return new WP_Error( 'onedigitpay_api_error', __( 'Payment provider returned an empty session ID.', 'onedigitpay-woocommerce' ), array( 'response' => $data ) );
		}

		return array(
			'success'      => true,
			'session_id'   => $session['session_id'],
			'checkout_url' => $session['checkout_url'],
			'order_id'     => isset( $session['order_id'] ) ? $session['order_id'] : '',
		);
	}

	/**
	 * Get session status (no auth required).
	 *
	 * @param string $session_id Session ID from create_checkout_session.
	 * @return array{success: bool, status?: string, session_data?: array}|WP_Error On success returns status and session_data; on failure WP_Error.
	 */
	public function get_session_status( $session_id ) {
		$url  = $this->api_base . '/authenticate/checkout-session/' . rawurlencode( $session_id );
		$args = array(
			'method'  => 'GET',
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'timeout' => 15,
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$data     = json_decode( $body_raw, true );

		// Edge case: OneDigitPay returns HTTP 400 with "Session status is completed" for already-completed sessions.
		if ( $code === 400 && isset( $data['msg'] ) && stripos( $data['msg'], 'Session status is completed' ) !== false ) {
			return array(
				'success'      => true,
				'status'       => 'COMPLETED',
				'session_data' => isset( $data['data']['session'] ) ? $data['data']['session'] : array(),
			);
		}

		if ( $code !== 200 ) {
			$message = isset( $data['msg'] ) ? $data['msg'] : __( 'Failed to check payment status.', 'onedigitpay-woocommerce' );
			return new WP_Error( 'onedigitpay_api_error', $message, array( 'status_code' => $code, 'response' => $data ) );
		}

		if ( empty( $data['success'] ) || empty( $data['data']['session'] ) ) {
			return new WP_Error( 'onedigitpay_api_error', __( 'Invalid response from payment provider.', 'onedigitpay-woocommerce' ), array( 'response' => $data ) );
		}

		$session = $data['data']['session'];
		$status  = isset( $session['status'] ) ? strtoupper( $session['status'] ) : '';

		return array(
			'success'      => true,
			'status'       => $status,
			'session_data' => $session,
		);
	}
}
