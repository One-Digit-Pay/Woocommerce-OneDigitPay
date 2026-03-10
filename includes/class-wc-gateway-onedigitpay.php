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
	 * Order meta key for OneDigitPay checkout URL.
	 */
	const META_CHECKOUT_URL = '_onedigitpay_checkout_url';

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
			'payment_mode'   => array(
				'title'       => __( 'Payment Mode', 'onedigitpay-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Redirect: payment-pending page then new tab. Direct: same page, opens OneDigitPay in the same window. Inline: popup on checkout.', 'onedigitpay-woocommerce' ),
				'default'     => 'redirect',
				'desc_tip'    => true,
				'options'     => array(
					'redirect' => __( 'Redirect (new tab)', 'onedigitpay-woocommerce' ),
					'direct'   => __( 'Direct (same window)', 'onedigitpay-woocommerce' ),
					'inline'   => __( 'Inline (popup)', 'onedigitpay-woocommerce' ),
				),
			),
			'sdk_url'        => array(
				'title'       => __( 'Checkout SDK URL', 'onedigitpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'URL to the OneDigitPay checkout.js SDK. Only used in Inline mode.', 'onedigitpay-woocommerce' ),
				'default'     => 'https://cdn.onedigitpay.com/checkout.v1.js',
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

	if ( empty( $result['success'] ) || empty( $result['checkout_url'] ) || empty( $result['session_id'] ) ) {
			wc_add_notice( __( 'Payment could not be started. Please try again or use another method.', 'onedigitpay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( self::META_SESSION_ID, $result['session_id'] );
		$order->update_meta_data( self::META_CHECKOUT_URL, $result['checkout_url'] );
		$order->set_status( 'on-hold', __( 'Awaiting OneDigitPay payment.', 'onedigitpay-woocommerce' ) );
		$order->save();

		WC()->cart->empty_cart();

		$payment_mode = $this->get_option( 'payment_mode', 'redirect' );

	if ( 'inline' === $payment_mode ) {
			// Build the redirect-mode pending URL as a fallback if the SDK fails to load.
			$pending_fallback_url = add_query_arg(
				array(
					'wc-api'    => 'odp_payment_pending',
					'order_id'  => $order->get_id(),
					'order_key' => $order->get_order_key(),
				),
				home_url( '/' )
			);

			// Inline mode: return session ID so checkout JS can open the popup.
			return array(
				'result'               => 'success',
				'redirect'             => false,
				'odp_inline'           => true,
				'odp_session_id'       => $result['session_id'],
				'odp_api_base'         => $api_base,
				'odp_thank_you_url'    => $this->get_return_url( $order ),
				'odp_pending_url'      => $pending_fallback_url,
				'odp_order_id'         => $order->get_id(),
				'odp_order_key'        => $order->get_order_key(),
			);
		}

		// Redirect mode: send to store-hosted payment-pending page.
		$pending_url = add_query_arg(
			array(
				'wc-api'    => 'odp_payment_pending',
				'order_id'  => $order->get_id(),
				'order_key' => $order->get_order_key(),
			),
			home_url( '/' )
		);

		return array(
			'result'   => 'success',
			'redirect' => $pending_url,
		);
	}

	/**
	 * Render the payment-pending page (wc-api=odp_payment_pending).
	 *
	 * Shows a "Pay Now" button that opens OneDigitPay checkout in a new tab,
	 * while polling the AJAX endpoint for payment status.
	 */
	public function render_payment_pending_page() {
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

		// Already paid — go straight to thank-you.
		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$checkout_url  = $order->get_meta( self::META_CHECKOUT_URL );
		$thank_you    = $this->get_return_url( $order );
		$ajax_url     = admin_url( 'admin-ajax.php' );
		$amount       = $order->get_formatted_order_total();
		$order_number = $order->get_order_number();
		$payment_mode = $this->get_option( 'payment_mode', 'redirect' );
		$is_direct    = ( 'direct' === $payment_mode );

		// Render a self-contained HTML page using the active theme's header/footer.
		get_header();
		?>
		<style>
			.odp-pending-wrap {
				max-width: 520px;
				margin: 60px auto;
				text-align: center;
				font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
			}
			.odp-pending-wrap h2 { margin-bottom: 8px; }
			.odp-pending-wrap .odp-order-info { color: #555; margin-bottom: 24px; }
			.odp-pending-wrap .odp-pay-btn {
				display: inline-block;
				background: #a00;
				color: #fff;
				padding: 14px 36px;
				border: none;
				border-radius: 6px;
				font-size: 16px;
				cursor: pointer;
				text-decoration: none;
				transition: background 0.2s;
			}
			.odp-pending-wrap .odp-pay-btn:hover { background: #800; }
			.odp-pending-wrap .odp-status {
				margin-top: 20px;
				padding: 12px;
				border-radius: 6px;
				font-size: 14px;
			}
			.odp-status-polling { background: #eef6ff; color: #1a6dbf; }
			.odp-status-completed { background: #eaffea; color: #1a7a1a; }
			.odp-status-failed { background: #ffeeee; color: #a00; }
			.odp-spinner {
				display: inline-block;
				width: 48px; height: 48px;
				border: 4px solid #ddd;
				border-top-color: #a00;
				border-radius: 50%;
				animation: odp-spin 0.8s linear infinite;
				margin-bottom: 16px;
			}
			@keyframes odp-spin { to { transform: rotate(360deg); } }
		</style>

		<div class="odp-pending-wrap">
			<div class="odp-spinner" id="odp-spinner"></div>
			<h2 id="odp-heading"><?php esc_html_e( 'Order Placed Successfully', 'onedigitpay-woocommerce' ); ?></h2>
			<p class="odp-order-info">
				<?php
				printf(
					/* translators: 1: order number, 2: formatted total */
					esc_html__( 'Order #%1$s — Total: %2$s', 'onedigitpay-woocommerce' ),
					esc_html( $order_number ),
					wp_kses_post( $amount )
				);
				?>
			</p>
			<p><?php esc_html_e( 'Click the button below to complete your payment.', 'onedigitpay-woocommerce' ); ?></p>

			<?php if ( ! empty( $checkout_url ) ) : ?>
				<button class="odp-pay-btn" id="odp-pay-btn"><?php esc_html_e( 'Pay Now', 'onedigitpay-woocommerce' ); ?></button>
			<?php else : ?>
				<p style="color:#a00;"><?php esc_html_e( 'Payment link is no longer available. Please contact the store.', 'onedigitpay-woocommerce' ); ?></p>
			<?php endif; ?>

			<div class="odp-status" id="odp-status" style="display:none;"></div>
		</div>

		<script>
		(function() {
			var checkoutUrl = <?php echo wp_json_encode( $checkout_url ); ?>;
			var ajaxUrl     = <?php echo wp_json_encode( $ajax_url ); ?>;
			var orderId     = <?php echo (int) $order_id; ?>;
			var orderKey    = <?php echo wp_json_encode( $order_key ); ?>;
			var thankYou    = <?php echo wp_json_encode( $thank_you ); ?>;
			var isDirect    = <?php echo $is_direct ? 'true' : 'false'; ?>;
			var pollTimer   = null;
			var stopTimer   = null;

			var btn     = document.getElementById('odp-pay-btn');
			var status  = document.getElementById('odp-status');
			var heading = document.getElementById('odp-heading');
			var spinner = document.getElementById('odp-spinner');

			if (btn) {
				btn.addEventListener('click', function() {
					if (isDirect) {
						window.location.href = checkoutUrl;
					} else {
						window.open(checkoutUrl, '_blank');
					}
					startPolling();
				});
			}

			function startPolling() {
				if (pollTimer) return;
				status.style.display = 'block';
				status.className = 'odp-status odp-status-polling';
				status.textContent = '<?php echo esc_js( __( 'Checking payment status…', 'onedigitpay-woocommerce' ) ); ?>';

				pollTimer = setInterval(checkStatus, 7000);
				checkStatus(); // first check immediately

				// Stop polling after 10 minutes.
				stopTimer = setTimeout(function() {
					clearInterval(pollTimer);
					pollTimer = null;
					status.textContent = '<?php echo esc_js( __( 'Status check timed out. Please refresh the page or check your orders.', 'onedigitpay-woocommerce' ) ); ?>';
				}, 600000);
			}

			function checkStatus() {
				var body = new FormData();
				body.append('action', 'odp_check_status');
				body.append('order_id', orderId);
				body.append('order_key', orderKey);

				fetch(ajaxUrl, { method: 'POST', body: body })
					.then(function(r) { return r.json(); })
					.then(function(data) {
						if (!data || !data.success) return;

						if (data.status === 'completed') {
							clearInterval(pollTimer);
							clearTimeout(stopTimer);
							spinner.style.borderTopColor = '#1a7a1a';
							heading.textContent = '<?php echo esc_js( __( 'Payment Successful!', 'onedigitpay-woocommerce' ) ); ?>';
							status.className = 'odp-status odp-status-completed';
							status.textContent = '<?php echo esc_js( __( 'Redirecting to your order…', 'onedigitpay-woocommerce' ) ); ?>';
							if (btn) btn.style.display = 'none';
							setTimeout(function() { window.location.href = thankYou; }, 2000);
						} else if (data.status === 'failed') {
							clearInterval(pollTimer);
							clearTimeout(stopTimer);
							spinner.style.display = 'none';
							heading.textContent = '<?php echo esc_js( __( 'Payment Failed', 'onedigitpay-woocommerce' ) ); ?>';
							status.className = 'odp-status odp-status-failed';
							status.textContent = '<?php echo esc_js( __( 'Your payment was not successful. You can try again.', 'onedigitpay-woocommerce' ) ); ?>';
							if (btn) btn.textContent = '<?php echo esc_js( __( 'Try Again', 'onedigitpay-woocommerce' ) ); ?>';
						}
					})
					.catch(function() {});
			}
		})();
		</script>
		<?php
		get_footer();
		exit;
	}

	/**
	 * AJAX handler: check payment status for a given order.
	 *
	 * Accepts order_id + order_key for auth (supports guest checkout).
	 * Returns JSON: { success: bool, status: 'pending'|'completed'|'failed' }
	 */
	public static function ajax_check_payment_status() {
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

		if ( ! $order_id || ! $order_key ) {
			wp_send_json( array( 'success' => false, 'status' => 'error' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_order_key() !== $order_key ) {
			wp_send_json( array( 'success' => false, 'status' => 'error' ) );
		}

		// Already completed.
		if ( $order->is_paid() ) {
			wp_send_json( array( 'success' => true, 'status' => 'completed' ) );
		}

		// Already failed.
		if ( $order->has_status( 'failed' ) ) {
			wp_send_json( array( 'success' => true, 'status' => 'failed' ) );
		}

		$session_id = $order->get_meta( self::META_SESSION_ID );
		if ( empty( $session_id ) ) {
			wp_send_json( array( 'success' => false, 'status' => 'error' ) );
		}

		$gateway = new self();
		$api     = new WC_OneDigitPay_API( $gateway->get_option( 'api_base' ), $gateway->get_option( 'merchant_token' ) );
		$result  = $api->get_session_status( $session_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json( array( 'success' => true, 'status' => 'pending' ) );
		}

		$api_status = isset( $result['status'] ) ? $result['status'] : '';

		if ( ! empty( $result['success'] ) && $api_status === 'COMPLETED' ) {
			$order->payment_complete();
			$order->add_order_note( __( 'Payment completed via OneDigitPay.', 'onedigitpay-woocommerce' ) );
			wp_send_json( array( 'success' => true, 'status' => 'completed' ) );
		}

		if ( in_array( $api_status, array( 'FAILED', 'EXPIRED', 'CANCELLED' ), true ) ) {
			$order->update_status( 'failed', __( 'OneDigitPay payment did not complete.', 'onedigitpay-woocommerce' ) );
			wp_send_json( array( 'success' => true, 'status' => 'failed' ) );
		}

		wp_send_json( array( 'success' => true, 'status' => 'pending' ) );
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
