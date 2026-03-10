<?php
/**
 * Background cron job for OneDigitPay payment status polling.
 *
 * Periodically checks all on-hold orders that have a OneDigitPay session ID
 * and updates them based on the current payment status from the API.
 * This handles the case where a customer closes their browser before
 * the client-side polling can complete.
 *
 * @package OneDigitPay_WooCommerce
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OneDigitPay_Cron
 */
class WC_OneDigitPay_Cron {

	/**
	 * WP-Cron hook name.
	 */
	const CRON_HOOK = 'odp_check_pending_payments';

	/**
	 * Recurrence interval in seconds (5 minutes).
	 */
	const INTERVAL = 300;

	/**
	 * Maximum age of orders to check (24 hours in seconds).
	 */
	const MAX_ORDER_AGE = 86400;

	/**
	 * Initialise cron hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'check_pending_orders' ) );

		// Ensure the event is scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'odp_every_five_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Register a custom cron schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules['odp_every_five_minutes'] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every 5 minutes (OneDigitPay)', 'onedigitpay-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Query on-hold OneDigitPay orders and check their payment status.
	 */
	public static function check_pending_orders() {
		$gateway = new WC_Gateway_OneDigitPay();
		$api_base = $gateway->get_option( 'api_base' );
		$token    = $gateway->get_option( 'merchant_token' );

		if ( empty( $api_base ) || empty( $token ) ) {
			return;
		}

		$api = new WC_OneDigitPay_API( $api_base, $token );

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::MAX_ORDER_AGE );

		$orders = wc_get_orders( array(
			'status'         => 'on-hold',
			'payment_method' => 'onedigitpay',
			'date_created'   => '>' . $cutoff,
			'limit'          => 50,
			'meta_key'       => WC_Gateway_OneDigitPay::META_SESSION_ID,
			'meta_compare'   => 'EXISTS',
		) );

		if ( empty( $orders ) ) {
			return;
		}

		$logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;

		foreach ( $orders as $order ) {
			$session_id = $order->get_meta( WC_Gateway_OneDigitPay::META_SESSION_ID );
			if ( empty( $session_id ) ) {
				continue;
			}

			$result = $api->get_session_status( $session_id );

			if ( is_wp_error( $result ) ) {
				if ( $logger ) {
					$logger->debug(
						'OneDigitPay cron: order ' . $order->get_id() . ' status check failed: ' . $result->get_error_message(),
						array( 'source' => 'onedigitpay' )
					);
				}
				continue;
			}

			$status = isset( $result['status'] ) ? $result['status'] : '';

			if ( ! empty( $result['success'] ) && $status === 'COMPLETED' ) {
				$order->payment_complete();
				$order->add_order_note( __( 'Payment completed via OneDigitPay (verified by background check).', 'onedigitpay-woocommerce' ) );
				if ( $logger ) {
					$logger->info( 'OneDigitPay cron: order ' . $order->get_id() . ' marked as paid.', array( 'source' => 'onedigitpay' ) );
				}
			} elseif ( in_array( $status, array( 'FAILED', 'EXPIRED', 'CANCELLED' ), true ) ) {
				$order->update_status( 'failed', __( 'OneDigitPay payment did not complete (verified by background check).', 'onedigitpay-woocommerce' ) );
				if ( $logger ) {
					$logger->info( 'OneDigitPay cron: order ' . $order->get_id() . ' marked as failed (' . $status . ').', array( 'source' => 'onedigitpay' ) );
				}
			}
		}
	}

	/**
	 * Schedule the cron event (call on plugin activation).
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'odp_every_five_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the cron event (call on plugin deactivation).
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
