<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ready-for-pickup auto SMS only. No voice, no shipped, no quiet-hours deferral.
 */
class ORLT_Auto {

	const KIND_READY = 'ready_for_pickup';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 25, 4 );
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

		$twilio  = ORLT_Twilio::instance();
		$message = $twilio->merge_tags( self::get_message_template(), $order );
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
