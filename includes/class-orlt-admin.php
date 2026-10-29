<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ORLT_Admin {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_orlt_test_connection', array( $this, 'ajax_test' ) );
		add_action( 'wp_ajax_orlt_test_sms', array( $this, 'ajax_test_sms' ) );
		add_action( 'admin_post_orlt_copy_from_pro', array( $this, 'handle_copy_from_pro' ) );
		add_action( 'admin_post_orlt_restore_copy_backup', array( $this, 'handle_restore_copy_backup' ) );
		add_action( 'updated_option', array( $this, 'maybe_recover_deferred' ), 10, 3 );
		add_filter( 'plugin_action_links_' . ORLT_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'OrderRing Lite', 'orderring-lite' ),
			__( 'OrderRing Lite', 'orderring-lite' ),
			ORLT_Caps::manage(),
			'orderring-lite',
			array( $this, 'render_settings' ),
			'dashicons-smartphone',
			56
		);
	}

	public function action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=orderring-lite' ) ) . '">' . esc_html__( 'Settings', 'orderring-lite' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public function assets( $hook ) {
		$on_settings = ( is_string( $hook ) && false !== strpos( $hook, 'orderring-lite' ) );
		$on_order    = ( $hook === 'post.php' || $hook === 'woocommerce_page_wc-orders' );
		if ( ! $on_settings && ! $on_order ) {
			return;
		}
		wp_enqueue_style( 'orlt-admin', ORLT_PLUGIN_URL . 'assets/admin.css', array(), ORLT_VERSION );
		wp_enqueue_script( 'orlt-admin', ORLT_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), ORLT_VERSION, true );
		wp_localize_script(
			'orlt-admin',
			'orltAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'orlt_nonce' ),
				'i18n'  => array(
					'testing' => __( 'Testing…', 'orderring-lite' ),
					'sending' => __( 'Sending…', 'orderring-lite' ),
					'failed'  => __( 'Request failed.', 'orderring-lite' ),
					'force'   => __( 'Customer has not consented to SMS. Send anyway?', 'orderring-lite' ),
				),
			)
		);
	}

	public function register_settings() {
		// The Settings form posts to options.php, which requires manage_options by
		// default. Grant save access to whoever can open the page (ORLT_Caps::manage(),
		// e.g. shop_manager), matching OrderRing. The Auth Token and webhook base URL
		// stay administrator-only in their sanitize callbacks.
		add_filter( 'option_page_capability_orlt_settings', array( 'ORLT_Caps', 'manage' ) );

		register_setting(
			'orlt_settings',
			'orlt_account_sid',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting(
			'orlt_settings',
			'orlt_auth_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_auth_token' ),
			)
		);
		register_setting(
			'orlt_settings',
			'orlt_from_number',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_from_number' ),
			)
		);
		// SMS provider (Twilio default) and Vonage / Plivo credentials.
		register_setting(
			'orlt_settings',
			ORLT_SMS_Providers::OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_sms_provider' ),
				'default'           => ORLT_SMS_Providers::DEFAULT,
			)
		);
		register_setting(
			'orlt_settings',
			'orlt_vonage_signature_method',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_vonage_signature_method' ),
				'default'           => 'md5hash',
			)
		);
		$provider_fields = array(
			array( ORLT_SMS_Providers::plain_fields(), false ),
			array( ORLT_SMS_Providers::secret_fields(), true ),
		);
		foreach ( $provider_fields as list( $fields, $secret ) ) {
			foreach ( $fields as $option => $constant ) {
				register_setting(
					'orlt_settings',
					$option,
					array(
						'type'              => 'string',
						'sanitize_callback' => function ( $value ) use ( $option, $constant, $secret ) {
							return $this->sanitize_provider_field( $option, $constant, $value, $secret );
						},
						'default'           => '',
					)
				);
			}
		}

		$plain = array(
			'orlt_webhook_base_url'         => array( $this, 'sanitize_webhook_base' ),
			'orlt_status_ready_for_pickup'  => array( $this, 'sanitize_order_status' ),
			'orlt_pickup_match'             => 'sanitize_text_field',
			'orlt_message_ready_for_pickup' => 'sanitize_textarea_field',
			'orlt_checkout_consent_label'   => 'sanitize_text_field',
			'orlt_sms_consent_meta'         => 'sanitize_text_field',
			'orlt_stop_reply'               => 'sanitize_textarea_field',
			'orlt_help_reply'               => 'sanitize_textarea_field',
			'orlt_start_reply'              => 'sanitize_textarea_field',
			'orlt_sms_footer_text'          => 'sanitize_text_field',
			'orlt_quiet_hours_start'        => array( $this, 'sanitize_time' ),
			'orlt_quiet_hours_end'          => array( $this, 'sanitize_time' ),
			'orlt_plain_optout_mode'        => array( 'ORLT_Compliance', 'sanitize_plain_optout_mode' ),
		);
		foreach ( $plain as $key => $cb ) {
			register_setting(
				'orlt_settings',
				$key,
				array(
					'type'              => 'string',
					'sanitize_callback' => $cb,
				)
			);
		}
		foreach ( array( 'orlt_auto_ready_enabled', 'orlt_auto_ready_sms', 'orlt_ready_require_local_pickup', 'orlt_require_sms_consent', 'orlt_checkout_consent_enabled', 'orlt_checkout_consent_required', 'orlt_sms_footer_enabled', 'orlt_quiet_hours_enabled' ) as $box ) {
			register_setting(
				'orlt_settings',
				$box,
				array(
					'type'              => 'integer',
					'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				)
			);
		}
	}

	/**
	 * @param mixed $value HH:MM from a time input.
	 * @return string
	 */
	public function sanitize_time( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return null === ORLT_Compliance::minutes( $value ) ? '' : $value;
	}

	public function sanitize_checkbox( $value ) {
		return $value ? 1 : 0;
	}

	public function sanitize_from_number( $value ) {
		$value = preg_replace( '/\s+/', '', (string) $value );
		if ( $value !== '' && ! ORLT_Twilio::is_e164( $value ) ) {
			add_settings_error( 'orlt_from_number', 'orlt_from', __( 'From Number was not saved: enter your Twilio number with + and the country code, for example +15095551234.', 'orderring-lite' ) );
			return (string) get_option( 'orlt_from_number', '' );
		}
		return $value;
	}

	public function sanitize_auth_token( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return (string) get_option( 'orlt_auth_token', '' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error( 'orlt_auth_token', 'orlt_token_cap', __( 'Only administrators can change the Twilio Auth Token.', 'orderring-lite' ) );
			return (string) get_option( 'orlt_auth_token', '' );
		}
		return sanitize_text_field( $value );
	}

	/**
	 * SMS provider choice. Switching where texts go (and whose webhooks are trusted) is
	 * administrator-only, like the Twilio Auth Token.
	 *
	 * @param mixed $value Raw value.
	 * @return string twilio|vonage|plivo
	 */
	public function sanitize_sms_provider( $value ) {
		$prev = ORLT_SMS_Providers::active_id();
		$new  = ORLT_SMS_Providers::normalize_id( $value );
		if ( $new !== $prev && ! current_user_can( 'manage_options' ) ) {
			add_settings_error( ORLT_SMS_Providers::OPTION, 'orlt_sms_provider_denied', __( 'Only administrators can change the SMS provider. Your previous choice was kept.', 'orderring-lite' ) );
			return $prev;
		}
		return $new;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_vonage_signature_method( $value ) {
		$prev = ORLT_SMS_Vonage::normalize_method( get_option( 'orlt_vonage_signature_method', 'md5hash' ) );
		$new  = ORLT_SMS_Vonage::normalize_method( $value );
		if ( $new !== $prev && ! current_user_can( 'manage_options' ) ) {
			return $prev;
		}
		return $new;
	}

	/**
	 * Vonage / Plivo credential fields. A wp-config constant locks the field; only
	 * administrators can change a value; a blank secret keeps the saved one (secrets are
	 * never printed back into the form); senders must be E.164 or an alphanumeric ID.
	 *
	 * @param string $option   Option name.
	 * @param string $constant wp-config constant that overrides it.
	 * @param mixed  $value    Raw value.
	 * @param bool   $secret   Whether this is a secret.
	 * @return string
	 */
	public function sanitize_provider_field( $option, $constant, $value, $secret ) {
		$prev  = (string) get_option( $option, '' );
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( ORLT_SMS_Providers::is_constant( $constant ) ) {
			return $prev;
		}
		if ( $secret && '' === $value ) {
			return $prev;
		}
		if ( $value === $prev ) {
			return $prev;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error( $option, $option . '_denied', __( 'Only administrators can change SMS provider credentials. Your previous value was kept.', 'orderring-lite' ) );
			return $prev;
		}
		if ( in_array( $option, array( 'orlt_vonage_from', 'orlt_plivo_from' ), true ) && '' !== $value ) {
			$sender = ORLT_SMS_Providers::normalize_sender( $value );
			if ( '' === $sender ) {
				add_settings_error( $option, $option . '_invalid', __( 'Sender must be an E.164 number (e.g. +15055551234) or an alphanumeric sender ID of up to 11 letters and digits. Your previous value was kept.', 'orderring-lite' ) );
				return $prev;
			}
			return $sender;
		}
		return sanitize_text_field( $value );
	}

	public function sanitize_order_status( $value ) {
		return ORLT_Statuses::normalize_wc_status( $value, ORLT_Statuses::READY_FOR_PICKUP );
	}

	public function sanitize_webhook_base( $value ) {
		$value = esc_url_raw( trim( (string) $value ) );
		$prev  = (string) get_option( 'orlt_webhook_base_url', '' );
		// Shop managers can save this form (see register_settings()); the webhook
		// origin used for Twilio signature checks stays administrator-only.
		if ( $value !== $prev && ! current_user_can( 'manage_options' ) ) {
			add_settings_error( 'orlt_webhook_base_url', 'orlt_webhook_cap', __( 'Only administrators can change the public site URL override.', 'orderring-lite' ) );
			return $prev;
		}
		return $value;
	}

	public function ajax_test() {
		check_ajax_referer( 'orlt_nonce', 'nonce' );
		if ( ! current_user_can( ORLT_Caps::manage() ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'orderring-lite' ) ), 403 );
		}
		$result = ORLT_Twilio::instance()->test_credentials();
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * Send one test text (no order) to a number the admin types in. Works with or
	 * without WooCommerce; the opt-out list still applies.
	 */
	public function ajax_test_sms() {
		check_ajax_referer( 'orlt_nonce', 'nonce' );
		if ( ! current_user_can( ORLT_Caps::manage() ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'orderring-lite' ) ), 403 );
		}
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$phone = ORLT_Logger::instance()->normalize_phone( $phone );
		if ( '' === $phone || ! ORLT_Twilio::is_e164( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a mobile number in E.164 format, for example +15055551234.', 'orderring-lite' ) ) );
		}
		$twilio   = ORLT_Twilio::instance();
		$provider = $twilio->sms_provider();
		if ( ! $provider->is_configured() ) {
			wp_send_json_error(
				array(
					'message' => 'twilio' === $provider->id()
						? __( 'Save your Twilio Account SID, Auth Token and From Number first.', 'orderring-lite' )
						/* translators: %s: SMS provider name */
						: sprintf( __( 'Save your %s credentials and sender first.', 'orderring-lite' ), $provider->label() ),
				)
			);
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: SMS provider name */
		$body = sprintf( __( '%1$s: test text from OrderRing Lite. Your %2$s connection works.', 'orderring-lite' ), $site, $provider->label() );
		$res  = $twilio->send_sms( $phone, $body, 0, true );
		if ( empty( $res['success'] ) ) {
			wp_send_json_error( array( 'message' => ! empty( $res['error'] ) ? (string) $res['error'] : __( 'The test text was not sent.', 'orderring-lite' ) ) );
		}
		/* translators: %s: phone number */
		wp_send_json_success( array( 'message' => sprintf( __( 'Test text sent to %s.', 'orderring-lite' ), $phone ) ) );
	}

	public function render_settings() {
		if ( ! current_user_can( ORLT_Caps::manage() ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderring-lite' ) );
		}
		$twilio  = ORLT_Twilio::instance();
		$sms_url = ORLT_Webhooks::rest_url( 'sms' );
		?>
		<div class="wrap orlt-wrap">
			<h1><?php echo esc_html__( 'OrderRing Lite', 'orderring-lite' ); ?></h1>
			<?php settings_errors(); // Top-level menu pages don't show the saved/error notices on their own. ?>
			<p class="description"><?php echo esc_html__( 'Ready-for-pickup SMS via your own Twilio, Vonage or Plivo account. You pay the provider directly, zero markup.', 'orderring-lite' ); ?></p>

			<div class="orlt-upgrade">
				<p><strong><?php echo esc_html__( 'Need voice calls, WhatsApp, two-way chat, or bulk reminders?', 'orderring-lite' ); ?></strong></p>
				<p><?php echo esc_html__( 'Those features are part of OrderRing Pro, sold separately on tillpress.com:', 'orderring-lite' ); ?></p>
				<ul>
					<li><?php echo esc_html__( 'Voice calls to customers directly from the order screen', 'orderring-lite' ); ?></li>
					<li><?php echo esc_html__( 'WhatsApp notifications using your Twilio WhatsApp sender', 'orderring-lite' ); ?></li>
					<li><?php echo esc_html__( 'Two-way customer SMS & WhatsApp chat history in WooCommerce', 'orderring-lite' ); ?></li>
					<li><?php echo esc_html__( 'Automated Shipped notifications and pickup reminders', 'orderring-lite' ); ?></li>
					<li><?php echo esc_html__( 'Bulk and scheduled pickup reminders', 'orderring-lite' ); ?></li>
					<li><?php echo esc_html__( 'Customizable message templates and delivery analytics', 'orderring-lite' ); ?></li>
				</ul>
				<p>
					<a class="button" href="<?php echo esc_url( 'https://tillpress.com/orderring/' ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html__( 'View OrderRing Pro', 'orderring-lite' ); ?> &rarr;
					</a>
				</p>
				<p class="description"><?php echo esc_html__( 'OrderRing Lite remains permanently free and fully functional. No license key, no lock, and no phone-home (only a support ticket you choose to send reaches Tillpress).', 'orderring-lite' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'orlt_settings' ); ?>

				<?php $this->render_sms_provider_settings(); ?>

				<div class="orlt-provider-fields" data-provider="twilio">
					<h2><?php echo esc_html__( 'Twilio credentials', 'orderring-lite' ); ?></h2>
					<p><?php echo esc_html__( 'US A2P 10DLC: register your brand and campaign in the Twilio Console before texting US mobiles. This plugin does not file 10DLC for you.', 'orderring-lite' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="orlt_account_sid"><?php echo esc_html__( 'Account SID', 'orderring-lite' ); ?></label></th>
							<td>
								<?php if ( $twilio->credential_is_constant( 'sid' ) ) : ?>
									<code><?php echo esc_html__( 'Set in wp-config.php (ORLT_ACCOUNT_SID)', 'orderring-lite' ); ?></code>
								<?php else : ?>
									<input type="text" class="regular-text" id="orlt_account_sid" name="orlt_account_sid" value="<?php echo esc_attr( get_option( 'orlt_account_sid', '' ) ); ?>" autocomplete="off" />
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="orlt_auth_token"><?php echo esc_html__( 'Auth Token', 'orderring-lite' ); ?></label></th>
							<td>
								<?php if ( $twilio->credential_is_constant( 'token' ) ) : ?>
									<code><?php echo esc_html__( 'Set in wp-config.php (ORLT_AUTH_TOKEN)', 'orderring-lite' ); ?></code>
								<?php else : ?>
									<input type="password" class="regular-text" id="orlt_auth_token" name="orlt_auth_token" value="" placeholder="<?php echo esc_attr( get_option( 'orlt_auth_token', '' ) ? '••••••••' : '' ); ?>" autocomplete="new-password" />
									<p class="description"><?php echo esc_html__( 'Leave blank to keep the saved token.', 'orderring-lite' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="orlt_from_number"><?php echo esc_html__( 'From Number', 'orderring-lite' ); ?></label></th>
							<td>
								<?php if ( $twilio->credential_is_constant( 'from' ) ) : ?>
									<code><?php echo esc_html__( 'Set in wp-config.php (ORLT_FROM_NUMBER)', 'orderring-lite' ); ?></code>
								<?php else : ?>
									<input type="text" class="regular-text" id="orlt_from_number" name="orlt_from_number" value="<?php echo esc_attr( get_option( 'orlt_from_number', '' ) ); ?>" placeholder="+15055551234" />
								<?php endif; ?>
							</td>
						</tr>
					</table>
					<p>
						<button type="button" class="button" id="orlt-test-connection"><?php echo esc_html__( 'Test connection', 'orderring-lite' ); ?></button>
						<span id="orlt-test-msg"></span>
					</p>
				</div>

				<?php $this->render_other_provider_fields(); ?>

				<p>
					<label for="orlt-test-sms-phone"><?php echo esc_html__( 'Send a test text to', 'orderring-lite' ); ?></label>
					<input type="tel" id="orlt-test-sms-phone" class="regular-text" placeholder="+15055551234" autocomplete="off" />
					<button type="button" class="button" id="orlt-test-sms"><?php echo esc_html__( 'Send test SMS', 'orderring-lite' ); ?></button>
					<span id="orlt-test-sms-msg"></span>
				</p>

				<?php
				// Pickup texts and checkout consent need WooCommerce. Without it the fields stay
				// in the form (hidden) so saving never wipes them.
				$orlt_woo = ORLT_Woo::active();
				if ( ! $orlt_woo ) :
					?>
					<div class="notice notice-info inline"><p><?php echo esc_html__( 'WooCommerce is not active, so the pickup text and checkout consent settings are hidden. Texting, opt-outs and STOP/HELP/START work without it.', 'orderring-lite' ); ?></p></div>
				<?php endif; ?>
				<div class="orlt-woo-only"<?php echo $orlt_woo ? '' : ' hidden'; ?>>
				<h2><?php echo esc_html__( 'Ready for Pickup', 'orderring-lite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Mapped status', 'orderring-lite' ); ?></th>
						<td>
							<select name="orlt_status_ready_for_pickup" id="orlt_status_ready_for_pickup">
								<?php foreach ( ORLT_Statuses::all_order_statuses() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( ORLT_Statuses::mapped_ready_status(), $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Auto SMS', 'orderring-lite' ); ?></th>
						<td>
							<label><input type="checkbox" name="orlt_auto_ready_enabled" value="1" <?php checked( (int) get_option( 'orlt_auto_ready_enabled', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Send when an order enters this status', 'orderring-lite' ); ?></label><br />
							<label><input type="checkbox" name="orlt_auto_ready_sms" value="1" <?php checked( (int) get_option( 'orlt_auto_ready_sms', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Enable SMS (this is the Lite channel)', 'orderring-lite' ); ?></label><br />
							<label><input type="checkbox" name="orlt_ready_require_local_pickup" value="1" <?php checked( (int) get_option( 'orlt_ready_require_local_pickup', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Only if the shipping method looks like Local Pickup', 'orderring-lite' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="orlt_message_ready_for_pickup"><?php echo esc_html__( 'Message', 'orderring-lite' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="4" id="orlt_message_ready_for_pickup" name="orlt_message_ready_for_pickup"><?php echo esc_textarea( (string) get_option( 'orlt_message_ready_for_pickup', '' ) ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Tags: {customer_first_name} {customer_last_name} {customer_full_name} {order_number} {order_total} {store_name} {phone} {tracking_url}', 'orderring-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Quiet hours', 'orderring-lite' ); ?></th>
						<td>
							<label><input type="checkbox" name="orlt_quiet_hours_enabled" value="1" <?php checked( (int) get_option( 'orlt_quiet_hours_enabled', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Hold texts during quiet hours', 'orderring-lite' ); ?></label>
							<p>
								<label><?php echo esc_html__( 'From', 'orderring-lite' ); ?> <input type="time" name="orlt_quiet_hours_start" value="<?php echo esc_attr( ORLT_Auto::time_option( 'orlt_quiet_hours_start', '21:00' ) ); ?>" /></label>
								<label><?php echo esc_html__( 'until', 'orderring-lite' ); ?> <input type="time" name="orlt_quiet_hours_end" value="<?php echo esc_attr( ORLT_Auto::time_option( 'orlt_quiet_hours_end', '08:00' ) ); ?>" /></label>
							</p>
							<p class="description"><?php echo esc_html__( "Uses the customer's time zone, worked out from the billing country and state, and the store's time zone when it cannot tell. A held text goes out when quiet hours end.", 'orderring-lite' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'SMS consent', 'orderring-lite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Checkout', 'orderring-lite' ); ?></th>
						<td>
							<label><input type="checkbox" name="orlt_checkout_consent_enabled" value="1" <?php checked( (int) get_option( 'orlt_checkout_consent_enabled', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Show a consent checkbox at checkout', 'orderring-lite' ); ?></label><br />
							<label><input type="checkbox" name="orlt_checkout_consent_required" value="1" <?php checked( (int) get_option( 'orlt_checkout_consent_required', 0 ), 1 ); ?> /> <?php echo esc_html__( 'Require the checkbox to place the order', 'orderring-lite' ); ?></label><br />
							<label><input type="checkbox" name="orlt_require_sms_consent" value="1" <?php checked( (int) get_option( 'orlt_require_sms_consent', 1 ), 1 ); ?> /> <?php echo esc_html__( 'Do not send SMS unless the customer consented (or you force-send from the order screen)', 'orderring-lite' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="orlt_checkout_consent_label"><?php echo esc_html__( 'Checkbox label', 'orderring-lite' ); ?></label></th>
						<td><input type="text" class="large-text" id="orlt_checkout_consent_label" name="orlt_checkout_consent_label" value="<?php echo esc_attr( (string) get_option( 'orlt_checkout_consent_label', '' ) ); ?>" /></td>
					</tr>
				</table>
				</div>

				<h2><?php echo esc_html__( 'STOP / HELP / START', 'orderring-lite' ); ?></h2>
				<p class="orlt-provider-fields" data-provider="twilio"><?php echo esc_html__( 'Paste this Incoming SMS webhook into Twilio:', 'orderring-lite' ); ?>
					<code><?php echo esc_html( $sms_url ); ?></code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="orlt_stop_reply"><?php echo esc_html__( 'STOP reply', 'orderring-lite' ); ?></label></th>
						<td><textarea class="large-text" rows="2" id="orlt_stop_reply" name="orlt_stop_reply"><?php echo esc_textarea( (string) get_option( 'orlt_stop_reply', '' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Plain-language opt-outs', 'orderring-lite' ); ?></th>
						<td>
							<label><input type="radio" name="orlt_plain_optout_mode" value="auto" <?php checked( ORLT_Compliance::plain_optout_mode(), 'auto' ); ?> /> <?php echo esc_html__( 'Treat them as STOP (recommended)', 'orderring-lite' ); ?></label><br />
							<label><input type="radio" name="orlt_plain_optout_mode" value="flag" <?php checked( ORLT_Compliance::plain_optout_mode(), 'flag' ); ?> /> <?php echo esc_html__( 'Only add an order note', 'orderring-lite' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Replies such as "please stop texting me" or "wrong number". STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT, REVOKE and OPT OUT always opt the customer out, as US rules require.', 'orderring-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="orlt_start_reply"><?php echo esc_html__( 'START reply', 'orderring-lite' ); ?></label></th>
						<td><textarea class="large-text" rows="2" id="orlt_start_reply" name="orlt_start_reply"><?php echo esc_textarea( (string) get_option( 'orlt_start_reply', '' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="orlt_webhook_base_url"><?php echo esc_html__( 'Public site URL override', 'orderring-lite' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="orlt_webhook_base_url" name="orlt_webhook_base_url" value="<?php echo esc_attr( (string) get_option( 'orlt_webhook_base_url', '' ) ); ?>" placeholder="https://example.com" />
							<p class="description"><?php echo esc_html__( 'Only if WordPress is behind a reverse proxy and webhook URLs are wrong.', 'orderring-lite' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0;">
				<?php wp_nonce_field( 'orlt_copy_from_pro' ); ?>
				<input type="hidden" name="action" value="orlt_copy_from_pro" />
				<?php submit_button( __( 'Copy settings from OrderRing Pro', 'orderring-lite' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php echo esc_html__( 'Copies Twilio credentials, status mapping, templates, and STOP list from OrderRing Pro into Lite. Your current Lite settings are backed up and can be restored.', 'orderring-lite' ); ?></p>
			</form>
			<?php if ( get_option( 'orlt_pro_copy_backup' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 1em;">
				<?php wp_nonce_field( 'orlt_restore_copy_backup' ); ?>
				<input type="hidden" name="action" value="orlt_restore_copy_backup" />
				<?php submit_button( __( 'Restore Lite settings backup', 'orderring-lite' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php endif; ?>
			<p class="orlt-legal"><?php echo esc_html__( 'Twilio and all related logos are trademarks of Twilio Inc. or its affiliates. OrderRing is not affiliated with, endorsed, or sponsored by Twilio Inc.', 'orderring-lite' ); ?></p>
		</div>
		<?php
	}

	/**
	 * SMS provider picker, what works through each provider, and setup warnings.
	 */
	private function render_sms_provider_settings() {
		$active  = ORLT_SMS_Providers::active_id();
		$choices = ORLT_SMS_Providers::choices();
		?>
		<h2 id="orlt-sms-provider"><?php echo esc_html__( 'SMS provider', 'orderring-lite' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Choose who sends your texts. Twilio, Vonage and Plivo all send texts and handle STOP / HELP / START replies. You bring your own account and pay the provider directly.', 'orderring-lite' ); ?></p>
		<?php foreach ( ORLT_SMS_Providers::admin_warnings() as $warning ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( $warning ); ?></p></div>
		<?php endforeach; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="orlt_sms_provider"><?php echo esc_html__( 'Send SMS with', 'orderring-lite' ); ?></label></th>
				<td>
					<select id="orlt_sms_provider" name="<?php echo esc_attr( ORLT_SMS_Providers::OPTION ); ?>">
						<?php foreach ( $choices as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php echo esc_html__( 'Only administrators can change this. Your opt-out list and consent records stay the same when you switch.', 'orderring-lite' ); ?></p>
					<?php foreach ( array_keys( $choices ) as $key ) : ?>
						<p class="description orlt-provider-fields" data-provider="<?php echo esc_attr( $key ); ?>">
							<?php
							/* translators: 1: SMS provider name, 2: comma-separated features */
							echo esc_html( sprintf( __( 'Works through %1$s in OrderRing Lite: %2$s.', 'orderring-lite' ), $choices[ $key ], implode( ', ', ORLT_SMS_Providers::capability_labels( $key ) ) ) );
							?>
						</p>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Vonage and Plivo credentials and the webhook URLs to paste into their dashboards.
	 * All fields stay in the form (hidden when another provider is selected), so saving
	 * never wipes them.
	 */
	private function render_other_provider_fields() {
		?>
		<div class="orlt-provider-fields" data-provider="vonage">
			<h2><?php echo esc_html__( 'Vonage', 'orderring-lite' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$this->provider_text_row( 'orlt_vonage_api_key', 'ORLT_VONAGE_API_KEY', __( 'API key', 'orderring-lite' ), '' );
				$this->provider_secret_row( 'orlt_vonage_api_secret', 'ORLT_VONAGE_API_SECRET', __( 'API secret', 'orderring-lite' ), '' );
				$this->provider_text_row( 'orlt_vonage_from', 'ORLT_VONAGE_FROM', __( 'From number or sender ID', 'orderring-lite' ), __( 'A Vonage number on your account in E.164 format (e.g. +15055551234), or an alphanumeric sender ID where the destination country allows one. Customers can only reply to a number.', 'orderring-lite' ) );
				$this->provider_secret_row( 'orlt_vonage_signature_secret', 'ORLT_VONAGE_SIGNATURE_SECRET', __( 'Signature secret', 'orderring-lite' ), __( 'Vonage dashboard → API Settings → Signature secret. Required for incoming replies: unsigned or wrongly signed webhooks are refused, so STOP replies are only processed once this is set. Ask Vonage support to turn on signed webhooks for your account.', 'orderring-lite' ) );
				?>
				<tr>
					<th scope="row"><label for="orlt_vonage_signature_method"><?php echo esc_html__( 'Signature method', 'orderring-lite' ); ?></label></th>
					<td>
						<?php $method = ORLT_SMS_Vonage::normalize_method( get_option( 'orlt_vonage_signature_method', 'md5hash' ) ); ?>
						<select id="orlt_vonage_signature_method" name="orlt_vonage_signature_method">
							<?php foreach ( array( 'md5hash' => 'MD5 hash', 'md5' => 'MD5 HMAC', 'sha1' => 'SHA-1 HMAC', 'sha256' => 'SHA-256 HMAC', 'sha512' => 'SHA-512 HMAC' ) as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $method, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php echo esc_html__( 'Must match the method chosen in your Vonage API Settings. SHA-256 HMAC is recommended.', 'orderring-lite' ); ?></p>
					</td>
				</tr>
			</table>
			<?php $this->provider_webhook_help( 'vonage' ); ?>
		</div>

		<div class="orlt-provider-fields" data-provider="plivo">
			<h2><?php echo esc_html__( 'Plivo', 'orderring-lite' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$this->provider_text_row( 'orlt_plivo_auth_id', 'ORLT_PLIVO_AUTH_ID', __( 'Auth ID', 'orderring-lite' ), '' );
				$this->provider_secret_row( 'orlt_plivo_auth_token', 'ORLT_PLIVO_AUTH_TOKEN', __( 'Auth Token', 'orderring-lite' ), __( 'Also used to verify Plivo’s webhook signatures, so incoming replies are refused without it.', 'orderring-lite' ) );
				$this->provider_text_row( 'orlt_plivo_from', 'ORLT_PLIVO_FROM', __( 'From number or sender ID', 'orderring-lite' ), __( 'A Plivo number on your account in E.164 format (e.g. +15055551234), or an alphanumeric sender ID where allowed.', 'orderring-lite' ) );
				?>
			</table>
			<?php $this->provider_webhook_help( 'plivo' ); ?>
		</div>
		<?php
	}

	/**
	 * @param string $option   Option.
	 * @param string $constant Constant.
	 * @param string $label    Label.
	 * @param string $help     Help text.
	 */
	private function provider_text_row( $option, $constant, $label, $help ) {
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( ORLT_SMS_Providers::is_constant( $constant ) ) : ?>
					<input type="text" value="<?php echo esc_attr( '•••••••• (' . $constant . ')' ); ?>" class="regular-text" disabled />
				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( (string) get_option( $option, '' ) ); ?>" class="regular-text" autocomplete="off" />
				<?php endif; ?>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Password row that never prints the saved secret.
	 *
	 * @param string $option   Option.
	 * @param string $constant Constant.
	 * @param string $label    Label.
	 * @param string $help     Help text.
	 */
	private function provider_secret_row( $option, $constant, $label, $help ) {
		$has = '' !== (string) get_option( $option, '' );
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( ORLT_SMS_Providers::is_constant( $constant ) ) : ?>
					<?php /* translators: %s: wp-config constant name */ ?>
					<input type="password" value="" class="regular-text" disabled placeholder="<?php echo esc_attr( sprintf( __( 'Set via %s', 'orderring-lite' ), $constant ) ); ?>" />
				<?php else : ?>
					<input type="password" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has ? esc_attr__( '•••••••• (leave blank to keep)', 'orderring-lite' ) : ''; ?>" />
				<?php endif; ?>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Where to paste the inbound and delivery-receipt webhook URLs.
	 *
	 * @param string $id vonage|plivo.
	 */
	private function provider_webhook_help( $id ) {
		$urls = ORLT_SMS_Providers::webhook_urls( $id );
		if ( ! $urls ) {
			return;
		}
		if ( 'vonage' === $id ) {
			$where = __( 'In the Vonage dashboard: API Settings → SMS settings → choose “SMS API”, then set the Inbound SMS webhook and the Delivery receipts webhook (method POST or POST-JSON). Also link your number to the inbound URL under Numbers.', 'orderring-lite' );
		} else {
			$where = __( 'In the Plivo console: Messaging → Applications → create an application with this Message URL (method POST) and attach it to your number. Delivery status uses the second URL, which OrderRing Lite adds to each text automatically.', 'orderring-lite' );
		}
		?>
		<p class="description"><?php echo esc_html( $where ); ?></p>
		<ul>
			<?php if ( ORLT_SMS_Providers::supports( 'inbound', $id ) ) : ?>
				<li><?php echo esc_html__( 'Incoming SMS:', 'orderring-lite' ); ?> <code><?php echo esc_html( $urls['inbound'] ); ?></code></li>
			<?php endif; ?>
			<?php if ( ORLT_SMS_Providers::supports( 'delivery_status', $id ) ) : ?>
				<li><?php echo esc_html__( 'Delivery status:', 'orderring-lite' ); ?> <code><?php echo esc_html( $urls['status'] ); ?></code></li>
			<?php endif; ?>
		</ul>
		<?php
	}

	public static function copy_option_map() {
		return array(
			'toc_account_sid'                => 'orlt_account_sid',
			'toc_auth_token'                 => 'orlt_auth_token',
			'toc_from_number'                => 'orlt_from_number',
			'toc_status_ready_for_pickup'    => 'orlt_status_ready_for_pickup',
			'toc_message_ready_for_pickup'   => 'orlt_message_ready_for_pickup',
			'toc_default_pickup_message'     => 'orlt_message_ready_for_pickup',
			'toc_auto_ready_enabled'         => 'orlt_auto_ready_enabled',
			'toc_auto_ready_sms'             => 'orlt_auto_ready_sms',
			'toc_ready_require_local_pickup' => 'orlt_ready_require_local_pickup',
			'toc_checkout_consent_enabled'   => 'orlt_checkout_consent_enabled',
			'toc_checkout_consent_required'  => 'orlt_checkout_consent_required',
			'toc_checkout_consent_label'     => 'orlt_checkout_consent_label',
			'toc_require_sms_consent'        => 'orlt_require_sms_consent',
			'toc_stop_reply'                 => 'orlt_stop_reply',
			'toc_start_reply'                => 'orlt_start_reply',
			'toc_help_reply'                 => 'orlt_help_reply',
			'toc_webhook_base_url'           => 'orlt_webhook_base_url',
			'toc_pickup_match'               => 'orlt_pickup_match',
			'toc_quiet_hours_start'          => 'orlt_quiet_hours_start',
			'toc_quiet_hours_end'            => 'orlt_quiet_hours_end',
			'toc_plain_optout_mode'          => 'orlt_plain_optout_mode',
			'toc_sms_provider'               => 'orlt_sms_provider',
			'toc_vonage_api_key'             => 'orlt_vonage_api_key',
			'toc_vonage_api_secret'          => 'orlt_vonage_api_secret',
			'toc_vonage_signature_secret'    => 'orlt_vonage_signature_secret',
			'toc_vonage_signature_method'    => 'orlt_vonage_signature_method',
			'toc_vonage_from'                => 'orlt_vonage_from',
			'toc_plivo_auth_id'              => 'orlt_plivo_auth_id',
			'toc_plivo_auth_token'           => 'orlt_plivo_auth_token',
			'toc_plivo_from'                 => 'orlt_plivo_from',
		);
	}

	public static function copy_from_pro() {
		$map     = self::copy_option_map();
		$backup  = array();
		$written = array();
		foreach ( $map as $src => $dst ) {
			if ( ! array_key_exists( $dst, $backup ) ) {
				$backup[ $dst ] = get_option( $dst, null );
			}
			$val = get_option( $src, null );
			if ( null === $val || false === $val || $val === '' ) {
				continue;
			}
			if ( isset( $written[ $dst ] ) ) {
				continue;
			}
			update_option( $dst, $val, false );
			$written[ $dst ] = true;
		}
		update_option( 'orlt_pro_copy_backup', $backup, false );
		self::import_pro_opt_outs();
		return true;
	}

	public static function import_pro_opt_outs() {
		global $wpdb;
		if ( class_exists( 'ORLT_Logger' ) && class_exists( 'TOC_Logger' ) ) {
			$pro = TOC_Logger::instance();
			if ( method_exists( $pro, 'list_opt_out_phones' ) ) {
				$lite = ORLT_Logger::instance();
				foreach ( (array) $pro->list_opt_out_phones() as $phone ) {
					$lite->add_opt_out_phone( (string) $phone );
				}
				return;
			}
		}
		$list = get_option( 'toc_sms_opt_outs', array() );
		if ( is_array( $list ) && class_exists( 'ORLT_Logger' ) ) {
			$logger = ORLT_Logger::instance();
			foreach ( $list as $entry ) {
				$logger->add_opt_out_phone( is_array( $entry ) ? (string) ( $entry['phone'] ?? '' ) : (string) $entry );
			}
		}
	}

	public static function restore_copy_backup() {
		$backup = get_option( 'orlt_pro_copy_backup', null );
		if ( ! is_array( $backup ) ) {
			return false;
		}
		foreach ( $backup as $key => $val ) {
			if ( null === $val ) {
				delete_option( $key );
			} else {
				update_option( $key, $val, false );
			}
		}
		delete_option( 'orlt_pro_copy_backup' );
		return true;
	}

	public function handle_copy_from_pro() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'orderring-lite' ), 403 );
		}
		check_admin_referer( 'orlt_copy_from_pro' );
		self::copy_from_pro();
		if ( class_exists( 'ORLT_Auto' ) && ORLT_Woo::active() ) {
			ORLT_Auto::instance()->recover_deferred_notifications();
		}
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=orderring-lite&orlt_copied=1' ) );
			exit;
		}
	}

	public function handle_restore_copy_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'orderring-lite' ), 403 );
		}
		check_admin_referer( 'orlt_restore_copy_backup' );
		self::restore_copy_backup();
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=orderring-lite&orlt_restored=1' ) );
			exit;
		}
	}

	public function maybe_recover_deferred( $option, $old, $new ) {
		unset( $old, $new );
		$watch = array_merge( array( 'orlt_account_sid', 'orlt_auth_token', 'orlt_from_number' ), ORLT_SMS_Providers::option_names() );
		if ( in_array( (string) $option, $watch, true ) && class_exists( 'ORLT_Auto' ) && ORLT_Woo::active() ) {
			ORLT_Auto::instance()->recover_deferred_notifications();
		}
	}

}
