<?php
/**
 * Demo data for OrderRing Lite: one Ready for Pickup order that can never be texted.
 *
 * @package OrderRingLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Demo order create/remove + report lines.
 */
class ORLT_Demo {

	const OPTION  = 'orlt_demo_ids';
	const META    = '_orlt_demo';
	const PHONE   = '+12025550142';
	const EMAIL   = 'demo.customer@example.com';
	const CREATED = 'orlt-demo';

	/**
	 * Stored demo object IDs.
	 *
	 * @return array{orders:int[]}
	 */
	public static function ids() {
		$ids = get_option( self::OPTION, array() );
		$ids = is_array( $ids ) ? $ids : array();
		return array( 'orders' => array_values( array_filter( array_map( 'absint', (array) ( $ids['orders'] ?? array() ) ) ) ) );
	}

	/**
	 * Whether demo data exists.
	 *
	 * @return bool
	 */
	public static function exists() {
		$ids = self::ids();
		foreach ( $ids['orders'] as $id ) {
			if ( function_exists( 'wc_get_order' ) && wc_get_order( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an order is a demo order (pure-ish: reads order meta).
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function is_demo_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		return $order ? (bool) $order->get_meta( self::META ) : false;
	}

	/**
	 * Create the demo order. Sends no email and no SMS.
	 *
	 * @return int|WP_Error Order ID.
	 */
	public static function create() {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'orlt_demo_no_wc', 'WooCommerce is not active.' );
		}
		if ( self::exists() ) {
			$ids = self::ids();
			return $ids['orders'][0];
		}
		self::mute_emails( true );
		try {
			$order = wc_create_order( array( 'created_via' => self::CREATED ) );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( __( 'Demo: sourdough loaf + 6 croissants', 'orderring-lite' ) );
			$fee->set_amount( 18.50 );
			$fee->set_total( 18.50 );
			$fee->set_tax_status( 'none' );
			$order->add_item( $fee );

			$ship = new WC_Order_Item_Shipping();
			$ship->set_method_title( __( 'Local pickup', 'orderring-lite' ) );
			$ship->set_method_id( 'local_pickup' );
			$ship->set_total( 0 );
			$order->add_item( $ship );

			$order->set_billing_first_name( 'Jamie' );
			$order->set_billing_last_name( 'Demo' );
			$order->set_billing_email( self::EMAIL );
			$order->set_billing_phone( self::PHONE );
			$order->update_meta_data( self::META, 1 );
			$order->update_meta_data( '_orlt_sms_consent', 'yes' );
			$order->update_meta_data( '_orlt_sms_consent_at', time() );
			$order->update_meta_data( '_orlt_sms_consent_source', 'demo' );
			// Mark as already notified so the Ready for Pickup automation never fires.
			$order->update_meta_data( '_orlt_notified_ready_for_pickup_at', time() );
			$order->calculate_totals( false );
			$order->add_order_note( __( 'OrderRing Lite demo order. Texts are blocked for this order. Remove it from OrderRing Lite > Help & demo.', 'orderring-lite' ) );
			$order->save();

			$ready = class_exists( 'ORLT_Statuses' ) ? ORLT_Statuses::bare_status( ORLT_Statuses::mapped_ready_status() ) : 'processing';
			$order->set_status( $ready );
			$order->save();

			update_option( self::OPTION, array( 'orders' => array( $order->get_id() ) ), false );
			return $order->get_id();
		} finally {
			self::mute_emails( false );
		}
	}

	/**
	 * Turn every WooCommerce email (and wp_mail) off or back on around demo writes,
	 * so demo orders never email the store owner or the fictional customer, even
	 * when an SMTP plugin replaces wp_mail().
	 *
	 * @param bool $on True to mute, false to restore.
	 */
	private static function mute_emails( $on ) {
		// Refund emails switch their id to customer_partially_refunded_order at send time.
		$ids = array( 'new_order', 'customer_completed_order', 'customer_processing_order', 'customer_on_hold_order', 'customer_refunded_order', 'customer_partially_refunded_order', 'customer_invoice', 'customer_note' );
		if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'mailer' ) ) {
			foreach ( (array) WC()->mailer()->get_emails() as $email ) {
				if ( is_object( $email ) && ! empty( $email->id ) ) {
					$ids[] = $email->id;
				}
			}
		}
		foreach ( array_unique( $ids ) as $id ) {
			if ( $on ) {
				add_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 999 );
			} else {
				remove_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 999 );
			}
		}
		if ( $on ) {
			add_filter( 'pre_wp_mail', '__return_false', 999 );
		} else {
			remove_filter( 'pre_wp_mail', '__return_false', 999 );
		}
	}

	/**
	 * Delete demo data.
	 */
	public static function remove() {
		$ids = self::ids();
		foreach ( $ids['orders'] as $id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
			if ( $order && $order->get_meta( self::META ) ) {
				$order->delete( true );
			}
		}
		delete_option( self::OPTION );
	}

	/**
	 * Links shown when demo data exists.
	 *
	 * @return array[]
	 */
	public static function links() {
		$out = array();
		$ids = self::ids();
		foreach ( $ids['orders'] as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$out[] = array(
					/* translators: %s: order number. */
					'label' => sprintf( __( 'Open demo order #%s (SMS box, consent badge, Ready for Pickup)', 'orderring-lite' ), $order->get_order_number() ),
					'url'   => $order->get_edit_order_url(),
				);
			}
		}
		$out[] = array(
			'label' => __( 'Edit the pickup message template', 'orderring-lite' ),
			'url'   => admin_url( 'admin.php?page=orderring-lite' ),
		);
		return $out;
	}

	/**
	 * Show the exact text the customer would receive.
	 */
	public static function render_extra() {
		$ids = self::ids();
		if ( empty( $ids['orders'] ) || ! class_exists( 'ORLT_Twilio' ) || ! class_exists( 'ORLT_Auto' ) ) {
			return;
		}
		$order = wc_get_order( $ids['orders'][0] );
		if ( ! $order ) {
			return;
		}
		$twilio = ORLT_Twilio::instance();
		$text   = $twilio->append_sms_footer( $twilio->merge_tags( ORLT_Auto::get_message_template(), $order ) );
		echo '<p><strong>' . esc_html__( 'Pickup text this customer would receive:', 'orderring-lite' ) . '</strong></p>';
		echo '<div class="orlt-hub-preview">' . esc_html( $text ) . '</div>';
		echo '<p class="description">' . esc_html__( 'The demo number is a fictional 555-01xx number. OrderRing Lite refuses to text any demo order, even if you press Send.', 'orderring-lite' ) . '</p>';
	}

	/**
	 * Diagnostics for the support report. Never includes credentials or numbers.
	 *
	 * @return array
	 */
	public static function report_lines() {
		$twilio = class_exists( 'ORLT_Twilio' ) ? ORLT_Twilio::instance() : null;
		return array(
			'Twilio configured'         => $twilio ? $twilio->is_configured() : false,
			'SMS provider'              => class_exists( 'ORLT_SMS_Providers' ) ? ORLT_SMS_Providers::active_id() : 'twilio',
			'SMS provider configured'   => $twilio ? $twilio->sms_is_configured() : false,
			'Provider replies verified' => class_exists( 'ORLT_SMS_Providers' ) ? ORLT_SMS_Providers::current()->inbound_ready() : false,
			'Credentials in wp-config'  => $twilio ? ( $twilio->credential_is_constant( 'sid' ) || $twilio->credential_is_constant( 'token' ) ) : false,
			'Ready status mapped to'    => class_exists( 'ORLT_Statuses' ) ? ORLT_Statuses::mapped_ready_status() : 'unknown',
			'Auto Ready SMS'            => (bool) get_option( 'orlt_auto_ready_enabled', 1 ) && (bool) get_option( 'orlt_auto_ready_sms', 1 ),
			'Local Pickup only'         => (bool) get_option( 'orlt_ready_require_local_pickup', 0 ),
			'Consent required'          => (bool) get_option( 'orlt_require_sms_consent', 1 ),
			'Checkout consent checkbox' => (bool) get_option( 'orlt_checkout_consent_enabled', 1 ),
			'Webhook base URL override' => '' !== (string) get_option( 'orlt_webhook_base_url', '' ),
			'Demo data present'         => self::exists(),
		);
	}
}
