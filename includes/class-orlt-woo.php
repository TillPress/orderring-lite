<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce is optional. Core (sending, the log, opt-outs, STOP/HELP/START, the
 * webhooks) runs on any WordPress site. The order features (ORLT_Woo::MODULE_CLASSES)
 * only start when WooCommerce is active.
 *
 * Core code reaches orders only through this class, so it never calls a WooCommerce
 * function that might not exist.
 */
final class ORLT_Woo {

	/**
	 * Classes that make up the WooCommerce module, in the order they start.
	 */
	const MODULE_CLASSES = array(
		'ORLT_Order_Meta',
		'ORLT_Statuses',
		'ORLT_Auto',
		'ORLT_Checkout',
	);

	/**
	 * Whether WooCommerce is loaded on this request.
	 *
	 * @return bool
	 */
	public static function active() {
		$active = class_exists( 'WooCommerce', false ) && function_exists( 'wc_get_order' );
		/**
		 * Filter whether OrderRing Lite treats WooCommerce as active (the order features load).
		 *
		 * @param bool $active Detected state.
		 */
		return (bool) apply_filters( 'orlt_woocommerce_active', $active );
	}

	/**
	 * The WooCommerce module's merge tags. Registered even before WooCommerce loads;
	 * without an order every value is ''.
	 */
	public static function register_merge_tags() {
		ORLT_Merge_Tags::register(
			'order',
			array( __CLASS__, 'merge_tag_values' ),
			array(
				'{order_number}'        => __( 'Order number', 'orderring-lite' ),
				'{order_id}'            => __( 'Order ID', 'orderring-lite' ),
				'{customer_first_name}' => __( 'Billing first name', 'orderring-lite' ),
				'{customer_last_name}'  => __( 'Billing last name', 'orderring-lite' ),
				'{customer_full_name}'  => __( 'Billing full name', 'orderring-lite' ),
				'{phone}'               => __( 'Billing phone', 'orderring-lite' ),
				'{order_total}'         => __( 'Order total', 'orderring-lite' ),
				'{billing_email}'       => __( 'Billing email', 'orderring-lite' ),
				'{tracking}'            => __( 'Tracking number', 'orderring-lite' ),
				'{tracking_url}'        => __( 'Tracking link', 'orderring-lite' ),
			)
		);
	}

	/**
	 * Order tag values for a send context (needs context['order'] to be a WC_Order).
	 *
	 * @param array $context Send context.
	 * @return array<string,string>
	 */
	public static function merge_tag_values( array $context ) {
		$order = isset( $context['order'] ) ? $context['order'] : null;
		if ( ! ( $order instanceof WC_Order ) ) {
			return array();
		}
		$tracking = ORLT_Twilio::instance()->tracking_for( $order );
		$values   = array(
			'{order_number}'        => (string) $order->get_order_number(),
			'{order_id}'            => (string) $order->get_id(),
			'{customer_first_name}' => (string) $order->get_billing_first_name(),
			'{customer_last_name}'  => (string) $order->get_billing_last_name(),
			'{customer_full_name}'  => trim( $order->get_formatted_billing_full_name() ),
			'{phone}'               => (string) $order->get_billing_phone(),
			'{order_total}'         => str_replace( "\xC2\xA0", ' ', html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			'{billing_email}'       => (string) $order->get_billing_email(),
			'{tracking}'            => $tracking['number'],
			'{tracking_url}'        => $tracking['url'],
			// An order send is also a send to that customer.
			'{contact_first_name}'  => (string) $order->get_billing_first_name(),
			'{contact_last_name}'   => (string) $order->get_billing_last_name(),
			'{contact_phone}'       => (string) $order->get_billing_phone(),
		);
		return $values;
	}

	/**
	 * wc_get_order() when WooCommerce is present, otherwise false.
	 *
	 * @param mixed $order_id Order ID or object.
	 * @return WC_Order|WC_Order_Refund|false
	 */
	public static function order( $order_id ) {
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		return wc_get_order( $order_id );
	}
}
