<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filterable capability helpers + role grants.
 *
 * Custom caps:
 *   orlt_manage — Order Communicator admin pages (Dashboard, Settings, Bulk, …)
 *   orlt_send   — Send SMS / place calls from the order screen
 *
 * Defaults are granted to administrator + shop_manager once (never wiped on upgrade).
 * Advanced sites can still override the required string via filters:
 *   add_filter( 'orlt_manage_settings', fn() => 'edit_shop_orders' );
 *   add_filter( 'orlt_send_sms', fn() => 'edit_shop_orders' );
 */
class ORLT_Caps {

	const CAP_MANAGE = 'orlt_manage';
	const CAP_SEND   = 'orlt_send';

	/** Option flag: default role grants have been applied once. */
	const SEEDED_OPTION = 'orlt_caps_seeded';

	/**
	 * Capability for Settings / Tools / Setup / Dashboard / Bulk / CSV / License admin pages.
	 *
	 * @return string
	 */
	public static function manage() {
		/**
		 * Filter the capability required to manage Order Communicator settings.
		 *
		 * @param string $cap Default orlt_manage.
		 */
		$cap = apply_filters( 'orlt_manage_settings', self::CAP_MANAGE );
		return is_string( $cap ) && $cap !== '' ? $cap : self::CAP_MANAGE;
	}

	/**
	 * Capability for sending SMS / placing calls (order meta box + related AJAX).
	 *
	 * @return string
	 */
	public static function send() {
		/**
		 * Filter the capability required to send SMS or place calls.
		 *
		 * @param string $cap Default orlt_send.
		 */
		$cap = apply_filters( 'orlt_send_sms', self::CAP_SEND );
		return is_string( $cap ) && $cap !== '' ? $cap : self::CAP_SEND;
	}

	/**
	 * Ensure default caps exist on administrator + shop_manager (once).
	 * Safe to call from activate and init; never removes custom grants.
	 */
	public static function maybe_seed() {
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			// Still ensure administrator always has manage (safety net after bad saves).
			self::ensure_administrator_manage();
			return;
		}

		$defaults = array( 'administrator', 'shop_manager' );
		foreach ( $defaults as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}
			$role->add_cap( self::CAP_MANAGE );
			$role->add_cap( self::CAP_SEND );
		}

		update_option( self::SEEDED_OPTION, 1, false );
		self::ensure_administrator_manage();
	}

	/**
	 * Always keep orlt_manage on the administrator role.
	 */
	public static function ensure_administrator_manage() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP_MANAGE ) ) {
			$role->add_cap( self::CAP_MANAGE );
		}
		// Administrators can also send by default if somehow stripped during seed race.
		// Intentionally not force-re-adding CAP_SEND here: if a store removed Send from
		// admin via the roles matrix, respect that. Only manage is force-restored above.
	}

	/**
	 * Roles that currently hold orlt_manage.
	 *
	 * @return string[] Role keys.
	 */
	public static function roles_with_manage() {
		$found = array();
		foreach ( self::editable_roles() as $key => $role ) {
			if ( ! empty( $role['capabilities'][ self::CAP_MANAGE ] ) ) {
				$found[] = $key;
			}
		}
		return $found;
	}

	/**
	 * @return array<string,array> Role key => role data from get_editable_roles().
	 */
	public static function editable_roles() {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$roles = get_editable_roles();
		return is_array( $roles ) ? $roles : array();
	}

	/**
	 * Whether a role is eligible to hold orlt_manage / orlt_send.
	 *
	 * Guards against escalating a low-privilege role (e.g. subscriber) into plugin
	 * management — reading Twilio secrets, exporting PII, granting further caps. A role
	 * may receive the caps only if it is the administrator or already holds a WooCommerce
	 * shop baseline (manage_woocommerce or edit_shop_orders). Pure so the rule is
	 * unit-testable without a WP runtime.
	 *
	 * @param string $role_key        Role slug.
	 * @param bool   $has_manage_woo  Whether the role holds manage_woocommerce.
	 * @param bool   $has_edit_orders Whether the role holds edit_shop_orders.
	 * @return bool
	 */
	public static function role_meets_baseline( $role_key, $has_manage_woo, $has_edit_orders ) {
		if ( 'administrator' === $role_key ) {
			return true;
		}
		return (bool) $has_manage_woo || (bool) $has_edit_orders;
	}
}
