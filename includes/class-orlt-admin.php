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
		foreach ( array( 'orlt_auto_ready_enabled', 'orlt_auto_ready_sms', 'orlt_ready_require_local_pickup', 'orlt_require_sms_consent', 'orlt_checkout_consent_enabled', 'orlt_checkout_consent_required', 'orlt_sms_footer_enabled' ) as $box ) {
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

	public function sanitize_checkbox( $value ) {
		return $value ? 1 : 0;
	}

	public function sanitize_from_number( $value ) {
		$value = preg_replace( '/\s+/', '', (string) $value );
		if ( $value !== '' && ! ORLT_Twilio::is_e164( $value ) ) {
			add_settings_error( 'orlt_from_number', 'orlt_from', __( 'From Number must be E.164 (e.g. +15055551234).', 'orderring-lite' ) );
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

	public function sanitize_order_status( $value ) {
		return ORLT_Statuses::normalize_wc_status( $value, ORLT_Statuses::READY_FOR_PICKUP );
	}

	public function sanitize_webhook_base( $value ) {
		$value = esc_url_raw( trim( (string) $value ) );
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

	public function render_settings() {
		if ( ! current_user_can( ORLT_Caps::manage() ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderring-lite' ) );
		}
		$twilio  = ORLT_Twilio::instance();
		$sms_url = ORLT_Webhooks::rest_url( 'sms' );
		?>
		<div class="wrap orlt-wrap">
			<h1><?php echo esc_html__( 'OrderRing Lite', 'orderring-lite' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Ready-for-pickup SMS via your own Twilio account. You pay Twilio directly, zero markup.', 'orderring-lite' ); ?></p>

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
							<p class="description"><?php echo esc_html__( 'Tags: {customer_first_name} {order_number} {store_name} {phone}', 'orderring-lite' ); ?></p>
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

				<h2><?php echo esc_html__( 'STOP / HELP / START', 'orderring-lite' ); ?></h2>
				<p><?php echo esc_html__( 'Paste this Incoming SMS webhook into Twilio:', 'orderring-lite' ); ?>
					<code><?php echo esc_html( $sms_url ); ?></code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="orlt_stop_reply"><?php echo esc_html__( 'STOP reply', 'orderring-lite' ); ?></label></th>
						<td><textarea class="large-text" rows="2" id="orlt_stop_reply" name="orlt_stop_reply"><?php echo esc_textarea( (string) get_option( 'orlt_stop_reply', '' ) ); ?></textarea></td>
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
		if ( class_exists( 'ORLT_Auto' ) ) {
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
		$watch = array( 'orlt_account_sid', 'orlt_auth_token', 'orlt_from_number' );
		if ( in_array( (string) $option, $watch, true ) && class_exists( 'ORLT_Auto' ) ) {
			ORLT_Auto::instance()->recover_deferred_notifications();
		}
	}

}
