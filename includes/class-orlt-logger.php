<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Communications log + STOP list. Custom tables require $wpdb (no WP API for them).
 */
class ORLT_Logger {

	private static $instance = null;
	private $table;
	private $opt_outs_table;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table          = $wpdb->prefix . 'orlt_communications';
		$this->opt_outs_table = $wpdb->prefix . 'orlt_sms_opt_outs';
	}

	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . 'orlt_communications';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned DEFAULT 0,
			phone varchar(30) NOT NULL DEFAULT '',
			direction varchar(10) NOT NULL DEFAULT 'outbound',
			type varchar(10) NOT NULL DEFAULT 'sms',
			body text,
			twilio_sid varchar(64) DEFAULT '',
			status varchar(40) DEFAULT '',
			resolved tinyint(1) NOT NULL DEFAULT 0,
			resolved_at datetime DEFAULT NULL,
			resolved_by bigint(20) unsigned DEFAULT 0,
			admin_user_id bigint(20) unsigned DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY order_id (order_id),
			KEY phone (phone),
			KEY twilio_sid (twilio_sid),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function create_opt_outs_table() {
		global $wpdb;
		$table   = $wpdb->prefix . 'orlt_sms_opt_outs';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(30) NOT NULL DEFAULT '',
			phone_digits varchar(20) NOT NULL DEFAULT '',
			phone_last10 varchar(10) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY phone_digits (phone_digits),
			KEY phone (phone),
			KEY phone_last10 (phone_last10)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function last10( $phone ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		return strlen( $digits ) >= 10 ? substr( $digits, -10 ) : $digits;
	}

	public static function phones_match( $a, $b ) {
		$na = self::instance()->normalize_phone( $a );
		$nb = self::instance()->normalize_phone( $b );
		if ( '' === $na || '' === $nb ) {
			return false;
		}
		return $na === $nb;
	}

	public static function backfill_opt_out_last10() {
		global $wpdb;
		// SUBSTR(col, -10) returns the last 10 characters and is portable across
		// MySQL/MariaDB and SQLite (RIGHT() is MySQL-only and errors on the SQLite
		// drop-in that WordPress Playground uses). One maintenance pass on upgrade.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET phone_last10 = SUBSTR(phone_digits, -10) WHERE phone_last10 = '' AND phone_digits <> ''",
				$wpdb->prefix . 'orlt_sms_opt_outs'
			)
		);
	}

	public static function migrate_opt_outs_from_option() {
		$list = get_option( 'orlt_sms_opt_outs', null );
		if ( null === $list ) {
			return;
		}
		if ( is_array( $list ) ) {
			$logger = self::instance();
			foreach ( $list as $entry ) {
				$logger->add_opt_out_phone( (string) $entry );
			}
		}
		delete_option( 'orlt_sms_opt_outs' );
	}

	public function log( $args ) {
		global $wpdb;

		$defaults = array(
			'order_id'      => 0,
			'phone'         => '',
			'direction'     => 'outbound',
			'type'          => 'sms',
			'body'          => '',
			'twilio_sid'    => '',
			'status'        => '',
			'resolved'      => 0,
			'admin_user_id' => get_current_user_id(),
		);
		$data     = wp_parse_args( $args, $defaults );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->table,
			array(
				'order_id'      => absint( $data['order_id'] ),
				'phone'         => sanitize_text_field( $data['phone'] ),
				'direction'     => in_array( $data['direction'], array( 'outbound', 'inbound' ), true ) ? $data['direction'] : 'outbound',
				'type'          => 'sms',
				'body'          => wp_kses_post( $data['body'] ),
				'twilio_sid'    => sanitize_text_field( $data['twilio_sid'] ),
				'status'        => sanitize_text_field( $data['status'] ),
				'resolved'      => absint( $data['resolved'] ),
				'admin_user_id' => absint( $data['admin_user_id'] ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		return $wpdb->insert_id;
	}

	public function update_status_by_sid( $sid, $status ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table,
			array( 'status' => sanitize_text_field( $status ) ),
			array( 'twilio_sid' => sanitize_text_field( $sid ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	public function get_order_id_by_sid( $sid ) {
		global $wpdb;
		$sid = sanitize_text_field( (string) $sid );
		if ( $sid === '' ) {
			return 0;
		}

		$table = $this->table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$order_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT order_id FROM %i WHERE twilio_sid = %s LIMIT 1',
				$table,
				$sid
			)
		);

		return absint( $order_id );
	}

	public function phone_is_opted_out( $phone ) {
		$norm = $this->normalize_phone( $phone );
		if ( $norm === '' ) {
			return false;
		}
		foreach ( $this->opt_out_tables() as $table ) {
			if ( $this->opt_out_identity_id( $table, $norm ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Opt-out tables to consult. Includes Pro's table when Pro has been installed
	 * so STOP is honoured across both plugins.
	 *
	 * @return string[]
	 */
	public function opt_out_tables() {
		global $wpdb;
		$tables = array( $this->opt_outs_table );
		if ( get_option( 'toc_db_version' ) ) {
			$pro = $wpdb->prefix . 'toc_sms_opt_outs';
			if ( $pro !== $this->opt_outs_table ) {
				$tables[] = $pro;
			}
		}
		return array_values( array_unique( $tables ) );
	}

	public function add_opt_out_phone( $phone ) {
		global $wpdb;
		$norm = $this->normalize_phone( $phone );
		if ( $norm === '' ) {
			return false;
		}

		$digits = $this->digits_only( $norm );
		if ( $digits === '' ) {
			return false;
		}

		if ( $this->opt_out_identity_id( $this->opt_outs_table, $norm ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->insert(
			$this->opt_outs_table,
			array(
				'phone'        => $norm,
				'phone_digits' => $digits,
				'phone_last10' => self::last10( $digits ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	public function remove_opt_out_phone( $phone ) {
		global $wpdb;
		$norm = $this->normalize_phone( $phone );
		if ( $norm === '' ) {
			return;
		}
		foreach ( $this->opt_out_tables() as $table ) {
			$ids = $this->opt_out_identity_ids( $table, $norm );
			if ( ! $ids ) {
				continue;
			}
			$ids   = array_map( 'absint', $ids );
			$table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table );
			foreach ( $ids as $id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $table, $id ) );
			}
		}
	}

	/**
	 * @param string $table Table name.
	 * @param string $norm  Canonical E.164.
	 * @return int[]
	 */
	private function opt_out_identity_ids( $table, $norm ) {
		global $wpdb;
		$digits = $this->digits_only( $norm );
		if ( $digits === '' ) {
			return array();
		}
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, phone, phone_digits FROM %i WHERE phone = %s OR phone_digits = %s OR phone_last10 = %s',
				$table,
				$norm,
				$digits,
				self::last10( $digits )
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$ids = array();
		foreach ( $rows as $row ) {
			$stored_digits = isset( $row->phone_digits ) ? (string) $row->phone_digits : '';
			$stored_phone  = isset( $row->phone ) ? (string) $row->phone : '';
			if ( $stored_digits === $digits || $stored_phone === $norm ) {
				$ids[] = (int) $row->id;
				continue;
			}
			if ( $stored_phone !== '' && $this->normalize_phone( $stored_phone ) === $norm ) {
				$ids[] = (int) $row->id;
			}
		}
		return $ids;
	}

	/**
	 * @param string $table Table name.
	 * @param string $norm  Canonical E.164.
	 * @return int
	 */
	private function opt_out_identity_id( $table, $norm ) {
		$ids = $this->opt_out_identity_ids( $table, $norm );
		return $ids ? $ids[0] : 0;
	}

	public function find_order_by_phone( $phone ) {
		$from_e164 = $this->normalize_phone( $phone );
		if ( $from_e164 === '' ) {
			return 0;
		}
		$digits = $this->digits_only( $from_e164 );
		if ( strlen( $digits ) < 7 ) {
			return 0;
		}
		$tail = substr( $digits, -10 );

		$candidate_ids = array();
		foreach ( $this->find_communication_candidates( $tail ) as $row ) {
			$row_phone = isset( $row->phone ) ? (string) $row->phone : '';
			$oid       = isset( $row->order_id ) ? (int) $row->order_id : 0;
			if ( $oid > 0 && $this->normalize_phone( $row_phone ) === $from_e164 ) {
				$candidate_ids[ $oid ] = true;
			}
		}
		foreach ( $this->find_billing_candidate_ids( $tail ) as $oid ) {
			$candidate_ids[ (int) $oid ] = true;
		}

		$statuses = array( 'processing', 'completed', 'on-hold', 'pending', 'ready-for-pickup' );
		if ( class_exists( 'ORLT_Statuses' ) ) {
			$statuses[] = ORLT_Statuses::bare_status( ORLT_Statuses::mapped_ready_status() );
		}
		$statuses = array_values( array_unique( $statuses ) );

		$scan_limit = (int) apply_filters( 'orlt_inbound_phone_scan_limit', 80 );
		$scan_limit = max( 10, min( 150, $scan_limit ) );

		$orders = wc_get_orders(
			array(
				'limit'   => $scan_limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => $statuses,
				'type'    => 'shop_order',
				'return'  => 'objects',
			)
		);

		foreach ( (array) $orders as $order ) {
			if ( ! is_callable( array( $order, 'get_billing_phone' ) ) ) {
				continue;
			}
			$candidate_ids[ (int) $order->get_id() ] = true;
		}

		$exact = array();
		foreach ( array_keys( $candidate_ids ) as $oid ) {
			$oid   = (int) $oid;
			$order = wc_get_order( $oid );
			if ( $this->order_billing_matches_from( $order, $from_e164 ) ) {
				$exact[ $oid ] = $order;
			}
		}

		if ( count( $exact ) === 1 ) {
			return (int) key( $exact );
		}
		return 0;
	}

	public function order_billing_matches_from( $order, $from_e164 ) {
		if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_billing_phone' ) ) ) {
			return false;
		}
		$from_e164 = (string) $from_e164;
		if ( $from_e164 === '' ) {
			return false;
		}
		$country = is_callable( array( $order, 'get_billing_country' ) ) ? (string) $order->get_billing_country() : '';
		$norm    = $this->normalize_phone( $order->get_billing_phone(), $country );
		return $norm !== '' && $norm === $from_e164;
	}

	private function find_communication_candidates( $tail ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}
		$like  = $this->phone_like_needle( $tail );
		$limit = $this->inbound_phone_lookup_limit();
		$table = $this->table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT order_id, phone FROM %i WHERE order_id > 0 AND phone LIKE %s ORDER BY created_at DESC LIMIT %d',
				$table,
				$like,
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	private function find_billing_candidate_ids( $tail ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}
		$like  = $this->phone_like_needle( $tail );
		$limit = $this->inbound_phone_lookup_limit();
		$ids   = array();
		$hpos  = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
		) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		if ( $hpos ) {
			$addresses = $wpdb->prefix . 'wc_order_addresses';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT order_id FROM %i WHERE address_type = 'billing' AND phone LIKE %s ORDER BY order_id DESC LIMIT %d",
					$addresses,
					$like,
					$limit
				)
			);
		} elseif ( isset( $wpdb->postmeta ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM %i WHERE meta_key = '_billing_phone' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT %d",
					$wpdb->postmeta,
					$like,
					$limit
				)
			);
		}
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	private function phone_like_needle( $tail ) {
		$tail   = (string) $tail;
		$needle = strlen( $tail ) >= 10 ? $tail : substr( $tail, -4 );
		if ( $needle === '' ) {
			$needle = $tail;
		}
		global $wpdb;
		return '%' . $wpdb->esc_like( $needle );
	}

	private function inbound_phone_lookup_limit() {
		$limit = (int) apply_filters( 'orlt_inbound_phone_lookup_limit', 40 );
		return max( 5, min( 100, $limit ) );
	}

	public function normalize_phone( $phone, $country_iso = '' ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( $phone === '' || $phone === '+' ) {
			return '';
		}

		if ( strpos( $phone, '+' ) === 0 ) {
			return $phone;
		}

		if ( strpos( $phone, '00' ) === 0 ) {
			$rest = ltrim( substr( $phone, 2 ), '0' );
			return '' === $rest ? '' : '+' . $rest;
		}

		$cc = $this->calling_code_for_iso( $country_iso );

		if ( strpos( $phone, '0' ) === 0 ) {
			$national = ltrim( $phone, '0' );
			return '' === $national ? '' : '+' . $cc . $national;
		}

		if ( strpos( $phone, $cc ) === 0 && strlen( $phone ) >= strlen( $cc ) + 7 ) {
			return '+' . $phone;
		}

		return '+' . $cc . $phone;
	}

	public function default_country_code() {
		$store = (string) get_option( 'woocommerce_default_country', '' );
		$iso   = strtoupper( substr( $store, 0, 2 ) );
		$map   = $this->calling_code_map();
		$cc    = isset( $map[ $iso ] ) ? $map[ $iso ] : '1';

		$cc = apply_filters( 'orlt_default_country_code', $cc );
		$cc = preg_replace( '/\D/', '', (string) $cc );
		return '' === $cc ? '1' : $cc;
	}

	public function calling_code_for_iso( $iso ) {
		$iso = strtoupper( substr( (string) $iso, 0, 2 ) );
		$map = $this->calling_code_map();
		if ( $iso !== '' && isset( $map[ $iso ] ) ) {
			return $map[ $iso ];
		}
		return $this->default_country_code();
	}

	public function calling_code_map() {
		return array(
			'US' => '1',
			'CA' => '1',
			'PR' => '1',
			'DO' => '1',
			'GB' => '44',
			'IE' => '353',
			'FR' => '33',
			'DE' => '49',
			'ES' => '34',
			'IT' => '39',
			'NL' => '31',
			'BE' => '32',
			'LU' => '352',
			'PT' => '351',
			'AT' => '43',
			'CH' => '41',
			'DK' => '45',
			'SE' => '46',
			'NO' => '47',
			'FI' => '358',
			'IS' => '354',
			'PL' => '48',
			'CZ' => '420',
			'SK' => '421',
			'HU' => '36',
			'RO' => '40',
			'BG' => '359',
			'GR' => '30',
			'HR' => '385',
			'SI' => '386',
			'RS' => '381',
			'EE' => '372',
			'LV' => '371',
			'LT' => '370',
			'UA' => '380',
			'RU' => '7',
			'TR' => '90',
			'IL' => '972',
			'SA' => '966',
			'AE' => '971',
			'QA' => '974',
			'KW' => '965',
			'BH' => '973',
			'OM' => '968',
			'JO' => '962',
			'LB' => '961',
			'EG' => '20',
			'MA' => '212',
			'DZ' => '213',
			'TN' => '216',
			'ZA' => '27',
			'NG' => '234',
			'KE' => '254',
			'GH' => '233',
			'AU' => '61',
			'NZ' => '64',
			'JP' => '81',
			'KR' => '82',
			'CN' => '86',
			'HK' => '852',
			'TW' => '886',
			'SG' => '65',
			'MY' => '60',
			'TH' => '66',
			'ID' => '62',
			'PH' => '63',
			'VN' => '84',
			'IN' => '91',
			'PK' => '92',
			'BD' => '880',
			'LK' => '94',
			'MX' => '52',
			'BR' => '55',
			'AR' => '54',
			'CL' => '56',
			'CO' => '57',
			'PE' => '51',
			'VE' => '58',
			'EC' => '593',
			'UY' => '598',
			'PY' => '595',
			'BO' => '591',
			'CR' => '506',
			'PA' => '507',
			'GT' => '502',
		);
	}

	public function digits_only( $phone ) {
		return preg_replace( '/\D/', '', (string) $phone );
	}
}
