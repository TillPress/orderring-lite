<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ready-for-pickup auto SMS only. No voice, no shipped. Optional quiet hours in the
 * customer's time zone hold the text until morning.
 */
class ORLT_Auto {

	const KIND_READY = 'ready_for_pickup';

	/** Cron hook that sends a text held back by quiet hours. */
	const QUIET_HOOK = 'orlt_send_after_quiet_hours';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 25, 4 );
		add_action( self::QUIET_HOOK, array( $this, 'run_after_quiet_hours' ), 10, 1 );
	}

	/**
	 * Quiet window as a list for ORLT_Compliance (empty when switched off).
	 *
	 * @return array[]
	 */
	public static function quiet_windows() {
		if ( ! (int) get_option( 'orlt_quiet_hours_enabled', 0 ) ) {
			return array();
		}
		return array(
			array(
				self::time_option( 'orlt_quiet_hours_start', '21:00' ),
				self::time_option( 'orlt_quiet_hours_end', '08:00' ),
			),
		);
	}

	/**
	 * @param string $option   Option name.
	 * @param string $fallback HH:MM.
	 * @return string HH:MM
	 */
	public static function time_option( $option, $fallback ) {
		$value = get_option( $option, $fallback );
		return null === ORLT_Compliance::minutes( $value ) ? $fallback : trim( (string) $value );
	}

	/**
	 * When the order's text may go out: now, or the end of quiet hours in the customer's
	 * time zone (the store's when the billing address does not say).
	 *
	 * @param object   $order WC_Order.
	 * @param int|null $now   UTC epoch.
	 * @return int
	 */
	public static function send_at( $order, $now = null ) {
		$now     = $now ? (int) $now : time();
		$windows = self::quiet_windows();
		if ( ! $windows ) {
			return $now;
		}
		$loc   = ORLT_Compliance::order_location( $order );
		$zones = ORLT_Compliance::recipient_zones( $loc['country'], $loc['state'], wp_timezone()->getName() );
		return ORLT_Compliance::next_open_at( $now, $windows, $zones );
	}

	/**
	 * Cron: send a text that quiet hours held back.
	 *
	 * @param int $order_id Order ID.
	 */
	public function run_after_quiet_hours( $order_id ) {
		$order_id = absint( $order_id );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			$order->delete_meta_data( '_orlt_quiet_until' );
			$order->save();
			$this->process_order( $order_id );
		}
	}

	public static function is_local_pickup( $order ) {
		if ( ! $order ) {
			return false;
		}

		$mode = get_option( 'orlt_pickup_match', 'local_title' );
		if ( ! in_array( $mode, array( 'method_id', 'local_title', 'any_pickup' ), true ) ) {
			$mode = 'local_title';
		}

		foreach ( $order->get_shipping_methods() as $shipping ) {
			$title     = (string) $shipping->get_method_title();
			$method_id = (string) $shipping->get_method_id();
			$is_method = ( $method_id === 'local_pickup' || strpos( $method_id, 'local_pickup' ) !== false );

			if ( $mode === 'method_id' ) {
				if ( $is_method ) {
					return true;
				}
				continue;
			}

			if ( $mode === 'local_title' ) {
				if ( $is_method || stripos( $title, 'local pickup' ) !== false ) {
					return true;
				}
				continue;
			}

			if ( $is_method || stripos( $title, 'local pickup' ) !== false || stripos( $title, 'pickup' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	public static function get_message_template() {
		$default = 'Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.';
		$val     = get_option( 'orlt_message_ready_for_pickup', $default );
		return ( is_string( $val ) && $val !== '' ) ? $val : $default;
	}

	public function on_status_changed( $order_id, $from, $to, $order ) {
		unset( $from, $order );
		$order_id = absint( $order_id );
		$to       = (string) $to;
		$ready    = ORLT_Statuses::bare_status( ORLT_Statuses::mapped_ready_status() );
		if ( $to === $ready ) {
			$this->process_order( $order_id );
		}
	}

	private function process_order( $order_id ) {
		if ( ! (int) get_option( 'orlt_auto_ready_enabled', 1 ) || ! (int) get_option( 'orlt_auto_ready_sms', 1 ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$expected = ORLT_Statuses::bare_status( ORLT_Statuses::mapped_ready_status() );
		if ( $order->get_status() !== $expected ) {
			return;
		}

		if ( $order->get_meta( '_orlt_notified_ready_for_pickup_at' ) ) {
			return;
		}

		if ( (int) get_option( 'orlt_ready_require_local_pickup', 0 ) === 1 && ! self::is_local_pickup( $order ) ) {
			$order->add_order_note( __( 'OrderRing Lite: Ready-for-pickup SMS skipped (shipping is not Local Pickup).', 'orderring-lite' ) );
			$order->update_meta_data( '_orlt_notified_ready_for_pickup_at', time() );
			$order->save();
			return;
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			$order->add_order_note( __( 'OrderRing Lite: Ready-for-pickup SMS skipped (no phone number).', 'orderring-lite' ) );
			$order->update_meta_data( '_orlt_notified_ready_for_pickup_at', time() );
			$order->save();
			return;
		}

		// Only hold a text that would go out; otherwise send_sms() below records why it did not.
		$check    = ORLT_Twilio::instance();
		$sendable = ! $check->phone_is_opted_out( $phone )
			&& ( ! (int) get_option( 'orlt_require_sms_consent', 1 ) || $check->consent_for_order( $order ) );
		$when     = $sendable ? self::send_at( $order ) : time();
		if ( $when > time() ) {
			if ( (int) $order->get_meta( '_orlt_quiet_until' ) !== $when ) {
				wp_clear_scheduled_hook( self::QUIET_HOOK, array( $order_id ) );
				wp_schedule_single_event( $when, self::QUIET_HOOK, array( $order_id ) );
				$order->update_meta_data( '_orlt_quiet_until', $when );
				$order->add_order_note(
					sprintf(
						/* translators: %s: date and time the text will be sent, store time */
						__( 'OrderRing Lite: Ready-for-pickup SMS held for quiet hours in the customer\'s time zone. It will be sent %s (store time).', 'orderring-lite' ),
						wp_date( 'M j, Y g:i a', $when )
					)
				);
				$order->save();
			}
			return;
		}

		$twilio  = ORLT_Twilio::instance();
		// send_sms() applies merge tags for $order_id (once).
		$message = self::get_message_template();
		$result  = $twilio->send_sms( $phone, $message, $order_id, false );

		if ( ! empty( $result['success'] ) ) {
			$order->add_order_note( __( 'OrderRing Lite: Ready-for-pickup SMS sent.', 'orderring-lite' ) );
			$order->update_meta_data( '_orlt_notified_ready_for_pickup_at', time() );
			$order->delete_meta_data( '_orlt_deferred_ready' );
		} else {
			$deferred = ! empty( $result['failure_class'] ) && 'deferred' === $result['failure_class'];
			$order->add_order_note(
				sprintf(
					/* translators: %s: error */
					__( 'OrderRing Lite: Ready-for-pickup SMS failed: %s', 'orderring-lite' ),
					$result['error'] ?? 'unknown'
				)
			);
			if ( $deferred ) {
				$order->update_meta_data( '_orlt_deferred_ready', time() );
			} else {
				$order->update_meta_data( '_orlt_notified_ready_for_pickup_at', time() );
			}
		}

		$order->save();
	}

	public function recover_deferred_notifications() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$after  = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
		$status = class_exists( 'ORLT_Statuses' ) ? ORLT_Statuses::bare_status( ORLT_Statuses::mapped_ready_status() ) : 'ready-for-pickup';
		$orders = wc_get_orders(
			array(
				'limit'        => 40,
				'status'       => $status,
				'type'         => 'shop_order',
				'date_created' => '>' . $after,
				'return'       => 'objects',
			)
		);
		foreach ( (array) $orders as $order ) {
			if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_id' ) ) ) {
				continue;
			}
			if ( $order->get_meta( '_orlt_notified_ready_for_pickup_at' ) ) {
				continue;
			}
			if ( ! $order->get_meta( '_orlt_deferred_ready' ) ) {
				continue;
			}
			$this->process_order( (int) $order->get_id() );
		}
	}
}
