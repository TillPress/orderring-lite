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
		$na = self::last10( $a );
		$nb = self::last10( $b );
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
		global $wpdb;
		$digits = $this->digits_only( $phone );
		if ( strlen( $digits ) < 7 ) {
			return false;
		}

		$last10 = self::last10( $digits );
		$table  = $this->opt_outs_table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE phone_digits = %s OR phone_digits = %s OR phone_last10 = %s LIMIT 1',
				$table,
				$digits,
				$last10,
				$last10
			)
		);

		return ! empty( $found );
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

		$table = $this->opt_outs_table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE phone_digits = %s OR phone_last10 = %s LIMIT 1',
				$table,
				$digits,
				self::last10( $digits )
			)
		);

		if ( $existing ) {
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
		$digits = $this->digits_only( $phone );
		if ( $digits === '' ) {
			return;
		}

		$last10 = self::last10( $digits );
		$table  = $this->opt_outs_table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE phone_digits = %s OR phone_digits = %s OR phone_last10 = %s',
				$table,
				$digits,
				$last10,
				$last10
			)
		);
	}

	public function find_order_by_phone( $phone ) {
		$digits = $this->digits_only( $phone );
		if ( strlen( $digits ) < 7 ) {
			return 0;
		}

		$tail = substr( $digits, -10 );

		$from_log = $this->find_order_id_from_communications( $tail );
		if ( $from_log ) {
			return $from_log;
		}

		$from_billing = $this->find_order_id_from_billing_phone( $tail );
		if ( $from_billing ) {
			return $from_billing;
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

		foreach ( $orders as $order ) {
			if ( ! is_callable( array( $order, 'get_billing_phone' ) ) ) {
				continue;
			}
			$billing = $this->digits_only( $order->get_billing_phone() );
			if ( $billing !== '' && substr( $billing, -10 ) === $tail ) {
				return $order->get_id();
			}
		}

		return 0;
	}

	private function find_order_id_from_communications( $tail ) {
		global $wpdb;

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

		if ( ! $rows ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$row_digits = $this->digits_only( $row->phone );
			if ( $row_digits !== '' && substr( $row_digits, -10 ) === $tail ) {
				return (int) $row->order_id;
			}
		}

		return 0;
	}

	private function find_order_id_from_billing_phone( $tail ) {
		global $wpdb;

		$like  = $this->phone_like_needle( $tail );
		$limit = $this->inbound_phone_lookup_limit();
		$ids   = array();

		$hpos = false;
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
		} else {
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

		if ( empty( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order ) {
				continue;
			}
			if ( ! is_callable( array( $order, 'get_billing_phone' ) ) ) {
				continue;
			}
			$billing = $this->digits_only( $order->get_billing_phone() );
			if ( $billing !== '' && substr( $billing, -10 ) === $tail ) {
				return (int) $order_id;
			}
		}

		return 0;
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

	public function normalize_phone( $phone ) {
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

		$cc = $this->default_country_code();

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
