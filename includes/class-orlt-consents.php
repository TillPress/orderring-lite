<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who agreed to texts, by phone number: the single consent record for Lite.
 *
 * Transactional consent (order and account texts) and marketing consent are kept
 * apart, each with its source and time. Checkout consent writes here too. A STOP on
 * the opt-out list always wins, and STOP also withdraws marketing consent.
 *
 * Mirrors OrderRing's contacts consent columns (consent / consent_detail /
 * consent_at_gmt plus marketing_consent*), so Pro can import it later.
 */
class ORLT_Consents {

	const DB_VERSION = 1;

	const KIND_TRANSACTIONAL = 'transactional';
	const KIND_MARKETING     = 'marketing';

	const MARKETING_GRANTED = 'granted';
	const MARKETING_REVOKED = 'revoked';

	/** `consent` values that count as transactional consent (twin of TOC_Consent_Policy). */
	const TRANSACTIONAL_GRANTS = array( 'order_checkbox', 'inbound', 'attested', 'widget', 'form' );

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'orlt_sms_consent_given', array( $this, 'on_consent_given' ), 10, 1 );
	}

	/**
	 * Checkout consent box ticked: record it against the billing phone.
	 *
	 * @param WC_Order $order Order.
	 */
	public function on_consent_given( $order ) {
		if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_billing_phone' ) ) ) {
			return;
		}
		$phone = (string) $order->get_billing_phone();
		if ( '' !== $phone ) {
			$this->record_consent( $phone, self::KIND_TRANSACTIONAL, 'order_checkbox', 'order:' . (int) $order->get_id() );
		}
	}

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'orlt_consents';
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(32) NOT NULL DEFAULT '',
			phone_last10 varchar(10) NOT NULL DEFAULT '',
			consent varchar(20) NOT NULL DEFAULT 'none',
			consent_detail varchar(64) NOT NULL DEFAULT '',
			consent_at_gmt datetime DEFAULT NULL,
			marketing_consent varchar(20) NOT NULL DEFAULT 'none',
			marketing_consent_source varchar(64) NOT NULL DEFAULT '',
			marketing_consent_at_gmt datetime DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY phone (phone),
			KEY phone_last10 (phone_last10)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'orlt_consents_db_version', self::DB_VERSION );
	}

	/**
	 * @return bool
	 */
	public static function schema_ready() {
		return (int) get_option( 'orlt_consents_db_version', 0 ) >= self::DB_VERSION;
	}

	/**
	 * Consent precedence for one kind (pure; twin of TOC_Consent_Policy::has_consent).
	 *
	 * @param object|array|null $row       Consent row.
	 * @param string            $kind      transactional|marketing.
	 * @param bool              $opted_out Number is on the STOP list.
	 * @return bool
	 */
	public static function decide( $row, $kind, $opted_out ) {
		if ( $opted_out ) {
			return false;
		}
		$row = is_object( $row ) ? (array) $row : ( is_array( $row ) ? $row : array() );
		if ( self::KIND_MARKETING === $kind ) {
			return isset( $row['marketing_consent'] ) && self::MARKETING_GRANTED === (string) $row['marketing_consent'];
		}
		if ( self::KIND_TRANSACTIONAL === $kind ) {
			return isset( $row['consent'] ) && in_array( (string) $row['consent'], self::TRANSACTIONAL_GRANTS, true );
		}
		return false;
	}

	/**
	 * Transactional consent only upgrades (twin of TOC_Contacts::upgrade_consent).
	 *
	 * @param string $current  Current value.
	 * @param string $incoming Incoming value.
	 * @return string
	 */
	public static function upgrade_consent( $current, $incoming ) {
		$rank = array(
			'none'           => 0,
			'inbound'        => 1,
			'widget'         => 2,
			'order_checkbox' => 3,
			'form'           => 3,
			'attested'       => 4,
		);
		$cur = isset( $rank[ $current ] ) ? $rank[ $current ] : 0;
		$in  = isset( $rank[ $incoming ] ) ? $rank[ $incoming ] : 0;
		return $in > $cur ? $incoming : ( $current ? $current : 'none' );
	}

	/**
	 * Source label (twin of TOC_Contacts::clean_source).
	 *
	 * @param mixed $source Source.
	 * @return string
	 */
	public static function clean_source( $source ) {
		return substr( (string) preg_replace( '/[^a-z0-9_\-:.]/', '', strtolower( (string) $source ) ), 0, 64 );
	}

	/**
	 * @param string $phone Any format.
	 * @return array|null
	 */
	public function get( $phone ) {
		global $wpdb;
		$norm = ORLT_Logger::instance()->normalize_phone( $phone );
		if ( '' === $norm || ! self::schema_ready() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE phone = %s LIMIT 1', self::table(), $norm ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Record that a phone number agreed to texts (checkout, a form, staff, a reply).
	 *
	 * @param string $phone  Any format.
	 * @param string $kind   transactional|marketing.
	 * @param string $source Transactional: inbound|widget|order_checkbox|form|attested (else "form").
	 * @param string $detail Where exactly, e.g. "cf7:42".
	 * @return bool
	 */
	public function record_consent( $phone, $kind, $source, $detail = '' ) {
		global $wpdb;
		if ( ! self::schema_ready() ) {
			return false;
		}
		$norm = ORLT_Logger::instance()->normalize_phone( $phone );
		if ( '' === $norm ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $this->get( $norm );
		if ( self::KIND_MARKETING === $kind ) {
			$data = array(
				'marketing_consent'        => self::MARKETING_GRANTED,
				'marketing_consent_source' => self::clean_source( '' !== (string) $detail ? $source . ':' . $detail : $source ),
				'marketing_consent_at_gmt' => $now,
			);
		} else {
			$known   = array( 'inbound', 'widget', 'order_checkbox', 'form', 'attested' );
			$source  = in_array( (string) $source, $known, true ) ? (string) $source : 'form';
			$current = $row ? (string) $row['consent'] : 'none';
			$next    = self::upgrade_consent( $current, $source );
			if ( $row && $next === $current ) {
				return true;
			}
			$data = array(
				'consent'        => $next,
				'consent_detail' => self::clean_source( '' !== (string) $detail ? $detail : $source ),
				'consent_at_gmt' => $now,
			);
		}
		$data['updated_at_gmt'] = $now;
		if ( $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $row['id'] ) );
		}
		$data['phone']          = $norm;
		$data['phone_last10']   = ORLT_Logger::last10( $norm );
		$data['created_at_gmt'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->insert( self::table(), $data );
	}

	/**
	 * Withdraw marketing consent (STOP, unsubscribe). Never creates a row.
	 *
	 * @param string $phone  Any format.
	 * @param string $source Why.
	 * @return int Rows changed.
	 */
	public function revoke_marketing( $phone, $source ) {
		global $wpdb;
		if ( ! self::schema_ready() ) {
			return 0;
		}
		$norm = ORLT_Logger::instance()->normalize_phone( $phone );
		if ( '' === $norm ) {
			return 0;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET marketing_consent = %s, marketing_consent_source = %s, marketing_consent_at_gmt = %s, updated_at_gmt = %s WHERE phone = %s',
				self::table(),
				self::MARKETING_REVOKED,
				self::clean_source( $source ),
				$now,
				$now,
				$norm
			)
		);
	}

	/**
	 * Whether this phone may get texts of this kind now. The opt-out list wins.
	 *
	 * @param string $phone Any format.
	 * @param string $kind  transactional|marketing.
	 * @return bool
	 */
	public function has_consent( $phone, $kind ) {
		$logger = ORLT_Logger::instance();
		$norm   = $logger->normalize_phone( $phone );
		if ( '' === $norm ) {
			return false;
		}
		return self::decide( $this->get( $norm ), $kind, $logger->phone_is_opted_out( $norm ) );
	}
}
