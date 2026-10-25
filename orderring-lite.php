<?php
/**
 * Plugin Name:       OrderRing Lite
 * Plugin URI:        https://tillpress.com/lite/
 * Description:       Ready-for-pickup SMS for WooCommerce via your own Twilio account. Checkout consent and STOP/HELP/START included. Bring your own Twilio account — you pay Twilio directly, zero markup.
 * Version:           1.1.2
 * Author:            Tillpress
 * Author URI:        https://tillpress.com
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:  11.2
 * Requires Plugins:  woocommerce
 * Text Domain:       orderring-lite
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ORLT_VERSION', '1.1.2' );
define( 'ORLT_BRAND_NAME', 'OrderRing Lite' );
define( 'ORLT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORLT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ORLT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ORLT_PLUGIN_FILE', __FILE__ );

require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-caps.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-compliance.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-logger.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-twilio.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-webhooks.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-admin.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-order-meta.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-statuses.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-auto.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-checkout.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-review-nudge.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-demo.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-support.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-skin.php';
require_once ORLT_PLUGIN_DIR . 'includes/class-orlt-hub.php';

final class ORLT_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( ORLT_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( ORLT_PLUGIN_FILE, array( $this, 'deactivate' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ORLT_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ORLT_PLUGIN_FILE, true );
		}
	}

	/**
	 * Drop texts still waiting for quiet hours to end; nothing should fire while switched off.
	 */
	public function deactivate() {
		wp_unschedule_hook( ORLT_Auto::QUIET_HOOK );
	}

	public function activate() {
		if ( $this->pro_is_active() ) {
			return;
		}
		$this->migrate_legacy_orl_prefix();
		ORLT_Logger::create_table();
		ORLT_Logger::create_opt_outs_table();
		ORLT_Logger::migrate_opt_outs_from_option();
		ORLT_Logger::backfill_opt_out_last10();
		$this->seed_defaults();
		ORLT_Caps::maybe_seed();
		ORLT_Review_Nudge::maybe_store_install_time();
		update_option( 'orlt_db_version', ORLT_VERSION );
	}

	/**
	 * One-time migration from the legacy 3-char "orl" prefix to "orlt".
	 *
	 * Older installs (<= 1.0.4) stored options, custom tables and role caps under
	 * the "orl_" prefix. The 1.0.5 rename to "orlt_" (wp.org 4-char prefix rule)
	 * would otherwise orphan that data. This copies options forward (without
	 * clobbering any already-set new value), renames the two custom tables when
	 * the new ones do not yet exist, and mirrors the role caps. Idempotent and
	 * guarded so it only runs once.
	 */
	public function migrate_legacy_orl_prefix() {
		if ( get_option( 'orlt_prefix_migrated' ) ) {
			return;
		}

		$option_map = array(
			'orl_account_sid',
			'orl_auth_token',
			'orl_from_number',
			'orl_webhook_base_url',
			'orl_db_version',
			'orl_status_ready_for_pickup',
			'orl_auto_ready_enabled',
			'orl_auto_ready_sms',
			'orl_message_ready_for_pickup',
			'orl_ready_require_local_pickup',
			'orl_pickup_match',
			'orl_checkout_consent_enabled',
			'orl_checkout_consent_required',
			'orl_checkout_consent_label',
			'orl_require_sms_consent',
			'orl_sms_consent_meta',
			'orl_stop_reply',
			'orl_help_reply',
			'orl_start_reply',
			'orl_sms_footer_enabled',
			'orl_sms_footer_text',
			'orl_caps_seeded',
		);
		foreach ( $option_map as $old_key ) {
			$new_key = 'orlt_' . substr( $old_key, 4 );
			$old_val = get_option( $old_key, null );
			if ( null !== $old_val && false === get_option( $new_key, false ) ) {
				update_option( $new_key, $old_val );
			}
		}
		// Do not carry the legacy db_version marker forward; force a fresh setup pass.
		delete_option( 'orlt_db_version' );

		global $wpdb;
		if ( isset( $wpdb ) ) {
			foreach ( array( 'orl_communications', 'orl_sms_opt_outs' ) as $old_table ) {
				$old_full = $wpdb->prefix . $old_table;
				$new_full = $wpdb->prefix . 'orlt_' . substr( $old_table, 4 );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$has_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_full ) );
				$has_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_full ) );
				if ( $has_old === $old_full && $has_new !== $new_full ) {
					$wpdb->query( 'RENAME TABLE `' . esc_sql( $old_full ) . '` TO `' . esc_sql( $new_full ) . '`' );
				}
				// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			}
		}

		foreach ( array( 'administrator', 'shop_manager', 'editor', 'author', 'contributor', 'subscriber' ) as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}
			if ( isset( $role->capabilities['orl_manage'] ) ) {
				$role->add_cap( 'orlt_manage' );
			}
			if ( isset( $role->capabilities['orl_send'] ) ) {
				$role->add_cap( 'orlt_send' );
			}
		}

		update_option( 'orlt_prefix_migrated', 1 );
	}

	public function seed_defaults() {
		$defaults = array(
			'orlt_sms_consent_meta'           => '_orlt_sms_consent',
			'orlt_pickup_match'               => 'local_title',
			'orlt_ready_require_local_pickup' => 0,
			'orlt_status_ready_for_pickup'    => 'wc-ready-for-pickup',
			'orlt_auto_ready_enabled'         => 1,
			'orlt_auto_ready_sms'             => 1,
			'orlt_require_sms_consent'        => 1,
			'orlt_checkout_consent_enabled'   => 1,
			'orlt_checkout_consent_required'  => 0,
			'orlt_checkout_consent_label'     => 'I agree to receive SMS updates about my order (msg & data rates may apply). Reply STOP to opt out.',
			'orlt_sms_footer_enabled'         => 0,
			'orlt_sms_footer_text'            => 'Reply STOP to opt out. Msg & data rates may apply.',
			'orlt_message_ready_for_pickup'   => 'Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.',
			'orlt_stop_reply'                 => 'You have been unsubscribed from SMS messages. Reply START to re-subscribe. Msg&data rates may apply.',
			'orlt_start_reply'                => 'You have been re-subscribed to SMS messages. Reply STOP to opt out.',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value );
			}
		}
	}

	public function init() {
		if ( $this->pro_is_active() ) {
			add_action( 'admin_notices', array( $this, 'pro_active_notice' ) );
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		if ( get_option( 'orlt_db_version' ) !== ORLT_VERSION ) {
			$this->migrate_legacy_orl_prefix();
			ORLT_Logger::create_table();
			ORLT_Logger::create_opt_outs_table();
			ORLT_Logger::migrate_opt_outs_from_option();
			ORLT_Logger::backfill_opt_out_last10();
			$this->seed_defaults();
			ORLT_Caps::maybe_seed();
			ORLT_Review_Nudge::maybe_store_install_time();
			update_option( 'orlt_db_version', ORLT_VERSION );
		}

		ORLT_Caps::maybe_seed();
		ORLT_Logger::instance();
		ORLT_Twilio::instance();
		ORLT_Webhooks::instance();
		ORLT_Admin::instance();
		ORLT_Order_Meta::instance();
		ORLT_Statuses::instance();
		ORLT_Auto::instance();
		ORLT_Checkout::instance();
		ORLT_Review_Nudge::instance();
		if ( is_admin() ) {
			ORLT_Support::instance();
			ORLT_Skin::init();
			ORLT_Hub::instance();
		}
	}

	public function pro_is_active() {
		return class_exists( 'ORLT_Twilio', false ) && ORLT_Twilio::pro_is_licensed();
	}

	public function pro_active_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'plugins' ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'OrderRing Pro is active (licensed or on its free trial) and sending, so OrderRing Lite is idle. You can deactivate Lite — Pro already includes these SMS tools.', 'orderring-lite' );
		echo '</p></div>';
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: plugin name */
				__( '<strong>%s</strong> requires WooCommerce to be active.', 'orderring-lite' ),
				ORLT_BRAND_NAME
			)
		);
		echo '</p></div>';
	}
}

ORLT_Plugin::instance();
