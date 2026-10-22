<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ORLT_Order_Meta {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'meta_box' ) );
		add_action( 'wp_ajax_orlt_send_sms', array( $this, 'ajax_sms' ) );
	}

	public function meta_box() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'orlt_sms',
				__( 'OrderRing Lite SMS', 'orderring-lite' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	private function order_from_screen() {
		if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = wc_get_order( absint( wp_unslash( $_GET['id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $order ) {
				return $order;
			}
		}
		$post_id = isset( $GLOBALS['post'] ) ? (int) $GLOBALS['post']->ID : 0;
		return $post_id ? wc_get_order( $post_id ) : null;
	}

	public function render() {
		$order = $this->order_from_screen();
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Save the order first.', 'orderring-lite' ) . '</p>';
			return;
		}
		if ( ! current_user_can( ORLT_Caps::send() ) ) {
			echo '<p>' . esc_html__( 'You cannot send SMS.', 'orderring-lite' ) . '</p>';
			return;
		}

		$phone = (string) $order->get_billing_phone();
		$state = ORLT_Twilio::instance()->get_sms_consent_state( $order->get_id() );
		$opted = $phone && ORLT_Twilio::instance()->phone_is_opted_out( $phone );
		$tpl   = ORLT_Auto::get_message_template();
		?>
		<div class="orlt-order-box" data-order="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<p><strong><?php echo esc_html( $phone ? $phone : __( 'No billing phone', 'orderring-lite' ) ); ?></strong></p>
			<p>
				<?php if ( $opted ) : ?>
					<span class="orlt-badge orlt-badge--no"><?php echo esc_html__( 'STOP', 'orderring-lite' ); ?></span>
				<?php elseif ( $state === 'yes' ) : ?>
					<span class="orlt-badge orlt-badge--yes"><?php echo esc_html__( 'Consented', 'orderring-lite' ); ?></span>
				<?php elseif ( $state === 'no' ) : ?>
					<span class="orlt-badge orlt-badge--no"><?php echo esc_html__( 'No consent', 'orderring-lite' ); ?></span>
				<?php else : ?>
					<span class="orlt-badge"><?php echo esc_html__( 'Consent unknown', 'orderring-lite' ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<button type="button" class="button orlt-use-tpl"><?php echo esc_html__( 'Use Ready for Pickup template', 'orderring-lite' ); ?></button>
			</p>
			<p>
				<textarea class="widefat orlt-sms-body" rows="4" data-tpl="<?php echo esc_attr( $tpl ); ?>"><?php echo esc_textarea( $tpl ); ?></textarea>
			</p>
			<p>
				<button type="button" class="button button-primary orlt-send-sms"><?php echo esc_html__( 'Send SMS', 'orderring-lite' ); ?></button>
				<span class="orlt-sms-msg"></span>
			</p>
		</div>
		<?php
	}

	public function ajax_sms() {
		check_ajax_referer( 'orlt_nonce', 'nonce' );
		if ( ! current_user_can( ORLT_Caps::send() ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'orderring-lite' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$body     = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		$force    = ! empty( $_POST['force'] );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || $body === '' ) {
			wp_send_json_error( array( 'message' => __( 'Missing order or message.', 'orderring-lite' ) ) );
		}

		$phone = (string) $order->get_billing_phone();
		if ( $phone === '' ) {
			wp_send_json_error( array( 'message' => __( 'Order has no billing phone.', 'orderring-lite' ) ) );
		}

		$result = ORLT_Twilio::instance()->send_sms( $phone, $body, $order_id, $force );
		if ( empty( $result['success'] ) ) {
			// Only missing consent can be overridden; a STOP opt-out cannot (send_sms enforces it).
			$code = ( empty( $result['opted_out'] ) && isset( $result['error'] ) && false !== strpos( $result['error'], 'consented' ) )
				? 'needs_force'
				: '';
			wp_send_json_error(
				array(
					'message' => $result['error'] ?? __( 'Send failed.', 'orderring-lite' ),
					'code'    => $code,
				)
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: message body */
				__( 'SMS sent: "%s"', 'orderring-lite' ),
				isset( $result['body'] ) ? (string) $result['body'] : $body
			) . ( $force ? ' ' . __( '(forced)', 'orderring-lite' ) : '' )
		);

		wp_send_json_success( array( 'message' => __( 'SMS sent.', 'orderring-lite' ) ) );
	}
}
