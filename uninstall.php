<?php
/**
 * Uninstall OrderRing Lite.
 *
 * Does not touch OrderRing Pro (twilio-order-communicator) options, caps, or tables.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( defined( 'TOC_VERSION' ) || class_exists( 'Twilio_Order_Communicator', false ) ) {
	return;
}

$orlt_options = array(
	'orlt_account_sid',
	'orlt_auth_token',
	'orlt_from_number',
	'orlt_webhook_base_url',
	'orlt_db_version',
	'orlt_status_ready_for_pickup',
	'orlt_auto_ready_enabled',
	'orlt_auto_ready_sms',
	'orlt_message_ready_for_pickup',
	'orlt_ready_require_local_pickup',
	'orlt_pickup_match',
	'orlt_checkout_consent_enabled',
	'orlt_checkout_consent_required',
	'orlt_checkout_consent_label',
	'orlt_require_sms_consent',
	'orlt_sms_consent_meta',
	'orlt_stop_reply',
	'orlt_help_reply',
	'orlt_start_reply',
	'orlt_sms_footer_enabled',
	'orlt_sms_footer_text',
	'orlt_caps_seeded',
	'orlt_installed_at',
	'orlt_review_nudge_dismissed',
	'orlt_prefix_migrated',
	// Legacy 3-char-prefix options from installs that pre-date 1.0.5.
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

foreach ( $orlt_options as $orlt_key ) {
	delete_option( $orlt_key );
}

global $wpdb;
if ( isset( $wpdb ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orlt_communications' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orlt_sms_opt_outs' );
	// Legacy tables (installs that pre-date 1.0.5 and never ran the migration).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orl_communications' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orl_sms_opt_outs' );
}

foreach ( array( 'administrator', 'shop_manager', 'editor', 'author', 'contributor', 'subscriber' ) as $orlt_role_key ) {
	$orlt_role = get_role( $orlt_role_key );
	if ( ! $orlt_role ) {
		continue;
	}
	$orlt_role->remove_cap( 'orlt_manage' );
	$orlt_role->remove_cap( 'orlt_send' );
	$orlt_role->remove_cap( 'orl_manage' );
	$orlt_role->remove_cap( 'orl_send' );
}
