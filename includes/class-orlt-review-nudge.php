<?php
/**
 * Dismissible wordpress.org review admin notice for OrderRing Lite.
 *
 * @package OrderRingLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dismissible wordpress.org review admin notice.
 *
 * Shown only when all of the following hold: at least 10 successful outbound
 * SMS sends in the Lite communications log, 14 days since install, the notice
 * has not been dismissed, and the current user has manage_options. Dismissal
 * is permanent. No outbound HTTP.
 */
class ORLT_Review_Nudge {

	const MIN_SENDS = 10;
	const MIN_DAYS  = 14;

	const OPTION_INSTALLED_AT = 'orlt_installed_at';
	const OPTION_DISMISSED    = 'orlt_review_nudge_dismissed';

	const REVIEW_URL     = 'https://wordpress.org/support/plugin/orderring-lite/reviews/#new-post';
	const DISMISS_ACTION = 'orlt_dismiss_review';

	/**
	 * Singleton instance.
	 *
	 * @var ORLT_Review_Nudge|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return ORLT_Review_Nudge
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire admin hooks and record install time if missing.
	 */
	private function __construct() {
		self::maybe_store_install_time();
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Store the install timestamp once if it is missing.
	 */
	public static function maybe_store_install_time() {
		if ( false === get_option( self::OPTION_INSTALLED_AT, false ) ) {
			add_option( self::OPTION_INSTALLED_AT, time() );
		}
	}

	/**
	 * Pure threshold: send count, days-since-install, and dismissal.
	 *
	 * Capability is checked by the admin renderer, not here, so this stays
	 * callable from the dependency-free test suite.
	 *
	 * @param int  $send_count   Successful outbound SMS count.
	 * @param int  $installed_at Unix timestamp of first install.
	 * @param int  $now          Current unix timestamp.
	 * @param bool $dismissed    Whether the notice was dismissed forever.
	 * @return bool
	 */
	public static function should_show( $send_count, $installed_at, $now, $dismissed ) {
		if ( $dismissed ) {
			return false;
		}
		if ( (int) $send_count < self::MIN_SENDS ) {
			return false;
		}
		$installed_at = (int) $installed_at;
		if ( $installed_at <= 0 ) {
			return false;
		}
		$elapsed = (int) $now - $installed_at;
		if ( $elapsed < self::MIN_DAYS * DAY_IN_SECONDS ) {
			return false;
		}
		return true;
	}

	/**
	 * Notice body. License-free; interpolates the send count.
	 *
	 * @param int $send_count Successful outbound SMS count.
	 * @return string
	 */
	public static function notice_copy( $send_count ) {
		return sprintf(
			/* translators: %d: number of successful order SMS sends */
			__( 'OrderRing Lite has sent %d order texts for you. A quick review helps other store owners find it.', 'orderring-lite' ),
			(int) $send_count
		);
	}

	/**
	 * Successful sends recorded by Lite: outbound SMS rows in the log.
	 * Failed Twilio API calls are never inserted, so the log is the counter.
	 *
	 * @return int
	 */
	public function successful_send_count() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return 0;
		}
		$table = $wpdb->prefix . 'orlt_communications';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE direction = %s AND type = %s',
				$table,
				'outbound',
				'sms'
			)
		);
		return absint( $count );
	}

	/**
	 * Permanently dismiss via GET + nonce.
	 */
	public function maybe_dismiss() {
		if ( ! isset( $_GET[ self::DISMISS_ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::DISMISS_ACTION );
		update_option( self::OPTION_DISMISSED, 1 );
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url();
		}
		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ACTION, '_wpnonce' ), $redirect ) );
		exit;
	}

	/**
	 * Permanently dismiss via admin-ajax + nonce.
	 */
	public function ajax_dismiss() {
		check_ajax_referer( self::DISMISS_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		update_option( self::OPTION_DISMISSED, 1 );
		wp_send_json_success();
	}

	/**
	 * Render the review notice when every gate passes.
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Only on OrderRing Lite's own screens, never dashboard-wide.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'orderring-lite' ) ) {
			return;
		}
		self::maybe_store_install_time();
		$dismissed    = (int) get_option( self::OPTION_DISMISSED, 0 );
		$installed_at = (int) get_option( self::OPTION_INSTALLED_AT, 0 );
		$send_count   = $this->successful_send_count();
		if ( ! self::should_show( $send_count, $installed_at, time(), (bool) $dismissed ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( self::DISMISS_ACTION, '1' ), self::DISMISS_ACTION );

		echo '<div class="notice notice-info orlt-review-nudge"><p>';
		echo esc_html( self::notice_copy( $send_count ) );
		echo '</p><p>';
		echo '<a class="button button-primary" href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">';
		echo esc_html__( 'Leave a review', 'orderring-lite' );
		echo '</a> ';
		echo '<a href="' . esc_url( $dismiss_url ) . '">';
		echo esc_html__( 'Dismiss', 'orderring-lite' );
		echo '</a>';
		echo '</p></div>';
	}
}
