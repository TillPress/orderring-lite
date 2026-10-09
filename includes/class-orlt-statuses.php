<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ready for Pickup status only.
 */
class ORLT_Statuses {

	const READY_FOR_PICKUP = 'wc-ready-for-pickup';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_post_statuses' ), 5 );
		add_filter( 'wc_order_statuses', array( $this, 'add_to_order_statuses' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( $this, 'paid_statuses' ) );
	}

	public function paid_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}
		$statuses[] = 'ready-for-pickup';
		return array_values( array_unique( $statuses ) );
	}

	public function register_post_statuses() {
		if ( get_post_status_object( self::READY_FOR_PICKUP ) ) {
			return;
		}
		register_post_status(
			self::READY_FOR_PICKUP,
			array(
				'label'                     => _x( 'Ready for Pickup', 'Order status', 'orderring-lite' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop(
					'Ready for Pickup <span class="count">(%s)</span>',
					'Ready for Pickup <span class="count">(%s)</span>',
					'orderring-lite'
				),
			)
		);
	}

	public function add_to_order_statuses( $order_statuses ) {
		$new = array();
		foreach ( $order_statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'wc-processing' ) {
				$new[ self::READY_FOR_PICKUP ] = _x( 'Ready for Pickup', 'Order status', 'orderring-lite' );
			}
		}
		if ( ! isset( $new[ self::READY_FOR_PICKUP ] ) ) {
			$new[ self::READY_FOR_PICKUP ] = _x( 'Ready for Pickup', 'Order status', 'orderring-lite' );
		}
		return $new;
	}

	public function bulk_actions( $actions ) {
		$actions['mark_ready-for-pickup'] = __( 'Change status to Ready for Pickup', 'orderring-lite' );
		return $actions;
	}

	public static function mapped_ready_status() {
		$status = (string) get_option( 'orlt_status_ready_for_pickup', self::READY_FOR_PICKUP );
		return self::normalize_wc_status( $status, self::READY_FOR_PICKUP );
	}

	public static function bare_status( $wc_status ) {
		$wc_status = (string) $wc_status;
		if ( strpos( $wc_status, 'wc-' ) === 0 ) {
			return substr( $wc_status, 3 );
		}
		return $wc_status;
	}

	public static function normalize_wc_status( $status, $fallback ) {
		$status = sanitize_key( $status );
		if ( $status === '' ) {
			return $fallback;
		}
		if ( strpos( $status, 'wc-' ) !== 0 ) {
			$status = 'wc-' . $status;
		}
		$registered = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		if ( ! empty( $registered ) && ! isset( $registered[ $status ] ) ) {
			return $fallback;
		}
		return $status;
	}

	public static function all_order_statuses() {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			return wc_get_order_statuses();
		}
		return array(
			self::READY_FOR_PICKUP => _x( 'Ready for Pickup', 'Order status', 'orderring-lite' ),
		);
	}
}
