<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Texting compliance helpers shared (as a twin) with OrderRing Pro's TOC_Compliance.
 *
 * - Inbound opt-out words: the FCC list (STOP, QUIT, END, REVOKE, OPT OUT, CANCEL,
 *   UNSUBSCRIBE) plus STOPALL, and plain-language requests ("please stop texting me")
 *   that are flagged for staff or treated as STOP, depending on a setting.
 * - Quiet hours checked in the customer's own time zone, worked out from the order's
 *   billing (or shipping) country and state, falling back to the store's time zone.
 *
 * Everything except option reads and order lookups is pure, so tests/run.php can pin it.
 */
class ORLT_Compliance {

	/** One-word replies that always opt the sender out. "OPT OUT" normalizes to OPTOUT. */
	const STOP_WORDS = array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT' );

	const HELP_WORDS = array( 'HELP', 'INFO' );

	/** START / UNSTOP only: a bare YES is too easy to match by accident. */
	const START_WORDS = array( 'START', 'UNSTOP' );

	/**
	 * Time zones per state or province for countries that span several zones. A state that
	 * straddles a line lists every zone it touches, so quiet hours hold in all of them.
	 */
	const STATE_ZONES = array(
		'US' => array(
			'AL' => array( 'America/Chicago' ),
			'AK' => array( 'America/Anchorage', 'America/Adak' ),
			'AZ' => array( 'America/Phoenix', 'America/Denver' ),
			'AR' => array( 'America/Chicago' ),
			'CA' => array( 'America/Los_Angeles' ),
			'CO' => array( 'America/Denver' ),
			'CT' => array( 'America/New_York' ),
			'DE' => array( 'America/New_York' ),
			'DC' => array( 'America/New_York' ),
			'FL' => array( 'America/New_York', 'America/Chicago' ),
			'GA' => array( 'America/New_York' ),
			'HI' => array( 'Pacific/Honolulu' ),
			'ID' => array( 'America/Boise', 'America/Los_Angeles' ),
			'IL' => array( 'America/Chicago' ),
			'IN' => array( 'America/Indiana/Indianapolis', 'America/Chicago' ),
			'IA' => array( 'America/Chicago' ),
			'KS' => array( 'America/Chicago', 'America/Denver' ),
			'KY' => array( 'America/New_York', 'America/Chicago' ),
			'LA' => array( 'America/Chicago' ),
			'ME' => array( 'America/New_York' ),
			'MD' => array( 'America/New_York' ),
			'MA' => array( 'America/New_York' ),
			'MI' => array( 'America/Detroit', 'America/Chicago' ),
			'MN' => array( 'America/Chicago' ),
			'MS' => array( 'America/Chicago' ),
			'MO' => array( 'America/Chicago' ),
			'MT' => array( 'America/Denver' ),
			'NE' => array( 'America/Chicago', 'America/Denver' ),
			'NV' => array( 'America/Los_Angeles' ),
			'NH' => array( 'America/New_York' ),
			'NJ' => array( 'America/New_York' ),
			'NM' => array( 'America/Denver' ),
			'NY' => array( 'America/New_York' ),
			'NC' => array( 'America/New_York' ),
			'ND' => array( 'America/Chicago', 'America/Denver' ),
			'OH' => array( 'America/New_York' ),
			'OK' => array( 'America/Chicago' ),
			'OR' => array( 'America/Los_Angeles', 'America/Boise' ),
			'PA' => array( 'America/New_York' ),
			'RI' => array( 'America/New_York' ),
			'SC' => array( 'America/New_York' ),
			'SD' => array( 'America/Chicago', 'America/Denver' ),
			'TN' => array( 'America/Chicago', 'America/New_York' ),
			'TX' => array( 'America/Chicago', 'America/Denver' ),
			'UT' => array( 'America/Denver' ),
			'VT' => array( 'America/New_York' ),
			'VA' => array( 'America/New_York' ),
			'WA' => array( 'America/Los_Angeles' ),
			'WV' => array( 'America/New_York' ),
			'WI' => array( 'America/Chicago' ),
			'WY' => array( 'America/Denver' ),
			'PR' => array( 'America/Puerto_Rico' ),
			'VI' => array( 'America/St_Thomas' ),
			'GU' => array( 'Pacific/Guam' ),
			'MP' => array( 'Pacific/Saipan' ),
			'AS' => array( 'Pacific/Pago_Pago' ),
		),
		'CA' => array(
			'AB' => array( 'America/Edmonton' ),
			'BC' => array( 'America/Vancouver' ),
			'MB' => array( 'America/Winnipeg' ),
			'NB' => array( 'America/Moncton' ),
			'NL' => array( 'America/St_Johns' ),
			'NS' => array( 'America/Halifax' ),
			'NT' => array( 'America/Yellowknife' ),
			'NU' => array( 'America/Iqaluit', 'America/Rankin_Inlet', 'America/Cambridge_Bay' ),
			'ON' => array( 'America/Toronto', 'America/Winnipeg' ),
			'PE' => array( 'America/Halifax' ),
			'QC' => array( 'America/Toronto' ),
			'SK' => array( 'America/Regina' ),
			'YT' => array( 'America/Whitehorse' ),
		),
		'AU' => array(
			'ACT' => array( 'Australia/Sydney' ),
			'NSW' => array( 'Australia/Sydney', 'Australia/Broken_Hill' ),
			'NT'  => array( 'Australia/Darwin' ),
			'QLD' => array( 'Australia/Brisbane' ),
			'SA'  => array( 'Australia/Adelaide' ),
			'TAS' => array( 'Australia/Hobart' ),
			'VIC' => array( 'Australia/Melbourne' ),
			'WA'  => array( 'Australia/Perth' ),
		),
	);

	/**
	 * Uppercase A-Z only, so "Stop." and "opt out" become STOP and OPTOUT.
	 *
	 * @param string $body Inbound text.
	 * @return string
	 */
	public static function keyword( $body ) {
		$norm = preg_replace( '/\s+/', ' ', strtoupper( trim( (string) $body ) ) );
		return (string) preg_replace( '/[^A-Z]/', '', (string) $norm );
	}

	/**
	 * What an inbound text asks for.
	 *
	 * @param string $body Inbound text.
	 * @return string stop|help|start|possible_stop|none
	 */
	public static function classify_inbound( $body ) {
		$keyword = self::keyword( $body );
		if ( in_array( $keyword, self::STOP_WORDS, true ) ) {
			return 'stop';
		}
		if ( in_array( $keyword, self::HELP_WORDS, true ) ) {
			return 'help';
		}
		if ( in_array( $keyword, self::START_WORDS, true ) ) {
			return 'start';
		}
		if ( self::looks_like_opt_out( $body ) ) {
			return 'possible_stop';
		}
		return 'none';
	}

	/**
	 * Plain-language opt-out requests the FCC says must be honored ("any reasonable means"),
	 * such as "please stop texting me" or "remove me from this list". Kept narrow so order
	 * questions ("can I cancel my order?", "I'll stop by at 5") do not match.
	 *
	 * @param string $body Inbound text.
	 * @return bool
	 */
	public static function looks_like_opt_out( $body ) {
		$text = strtolower( trim( (string) $body ) );
		$text = str_replace( array( "\u{2019}", "\u{2018}", '`' ), "'", $text );
		$text = (string) preg_replace( '/\s+/', ' ', $text );
		if ( $text === '' ) {
			return false;
		}

		$patterns = array(
			'/\b(stop|quit|cease|end|cancel)\s+(?:[a-z\']+\s+){0,2}?(texting|texts?|messaging|messages?|sending|contacting|sms)\b/',
			'/\bunsubscribe\b/',
			'/\bopt\s*-?\s*(?:me\s+)?out\b/',
			'/\brevoke\b/',
			'/\b(don\'t|dont|do not|never)\s+(text|message|sms|contact)\s+(me|this number)\b/',
			'/\b(remove|take)\s+(me|my number|this number)\s+(from|off)\b/',
			'/\bno more\s+(texts?|messages?|sms)\b/',
			'/\bwrong number\b/',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		// "Stop please", "please stop now", "stop stop": only stop-words and fillers.
		$words   = preg_split( '/[^a-z]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$strong  = array( 'stop', 'unsubscribe', 'revoke' );
		$fillers = array( 'please', 'pls', 'plz', 'now', 'it', 'all', 'thanks', 'thank', 'you', 'ty', 'ok', 'okay', 'just', 'me', 'the', 'these', 'this' );
		$has     = false;
		foreach ( (array) $words as $word ) {
			if ( in_array( $word, $strong, true ) ) {
				$has = true;
				continue;
			}
			if ( ! in_array( $word, $fillers, true ) ) {
				return false;
			}
		}
		return $has;
	}

	/**
	 * What to do with a plain-language opt-out: treat it as STOP (default, the safe reading of
	 * the FCC's "any reasonable means" rule) or only flag it for staff.
	 *
	 * @return string auto|flag
	 */
	public static function plain_optout_mode() {
		return 'flag' === get_option( 'orlt_plain_optout_mode', 'auto' ) ? 'flag' : 'auto';
	}

	/**
	 * @param mixed $value Raw setting.
	 * @return string auto|flag
	 */
	public static function sanitize_plain_optout_mode( $value ) {
		return 'flag' === $value ? 'flag' : 'auto';
	}

	/**
	 * Time zones a customer in this country and state may be in. Empty when unknown, or
	 * when the country spans too many zones to guess without a state.
	 *
	 * @param string $country ISO 3166-1 alpha-2.
	 * @param string $state   WooCommerce state code.
	 * @return string[]
	 */
	public static function zones_for( $country, $state = '' ) {
		$country = strtoupper( trim( (string) $country ) );
		$state   = strtoupper( trim( (string) $state ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return array();
		}
		if ( $state !== '' && isset( self::STATE_ZONES[ $country ][ $state ] ) ) {
			return self::STATE_ZONES[ $country ][ $state ];
		}

		$ids = DateTimeZone::listIdentifiers( DateTimeZone::PER_COUNTRY, $country );
		if ( empty( $ids ) ) {
			return array();
		}
		// One zone per distinct offset (winter and summer), so Spain's Canary Islands count
		// but Germany's two identical zones do not. More than two offsets means we cannot
		// tell where the customer is.
		$jan     = new DateTimeImmutable( gmdate( 'Y' ) . '-01-15 12:00:00', new DateTimeZone( 'UTC' ) );
		$jul     = new DateTimeImmutable( gmdate( 'Y' ) . '-07-15 12:00:00', new DateTimeZone( 'UTC' ) );
		$by_off  = array();
		foreach ( $ids as $id ) {
			$tz  = new DateTimeZone( $id );
			$key = $tz->getOffset( $jan ) . '/' . $tz->getOffset( $jul );
			if ( ! isset( $by_off[ $key ] ) ) {
				$by_off[ $key ] = $id;
			}
		}
		return count( $by_off ) <= 2 ? array_values( $by_off ) : array();
	}

	/**
	 * Customer zones, or the store zone when the customer's cannot be worked out.
	 *
	 * @param string $country  Country code.
	 * @param string $state    State code.
	 * @param string $store_tz Store time zone id or offset (e.g. +02:00).
	 * @return string[]
	 */
	public static function recipient_zones( $country, $state, $store_tz ) {
		$zones = self::zones_for( $country, $state );
		return $zones ? $zones : array( (string) $store_tz );
	}

	/**
	 * Billing country and state of an order, or shipping when billing has no country.
	 *
	 * @param object|null $order WC_Order.
	 * @return array{country:string,state:string}
	 */
	public static function order_location( $order ) {
		$out = array(
			'country' => '',
			'state'   => '',
		);
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_billing_country' ) ) {
			return $out;
		}
		$out['country'] = (string) $order->get_billing_country();
		$out['state']   = (string) $order->get_billing_state();
		if ( $out['country'] === '' && method_exists( $order, 'get_shipping_country' ) ) {
			$out['country'] = (string) $order->get_shipping_country();
			$out['state']   = (string) $order->get_shipping_state();
		}
		return $out;
	}

	/**
	 * @param string $value HH:MM.
	 * @return int|null Minutes after midnight, or null when invalid.
	 */
	public static function minutes( $value ) {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( (string) $value ), $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	/**
	 * @param string $tz Zone id or offset.
	 * @return DateTimeZone
	 */
	private static function tz( $tz ) {
		try {
			return new DateTimeZone( (string) $tz );
		} catch ( Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * Whether $ts falls inside a quiet window (start, end as HH:MM) in any of the zones.
	 * Windows may wrap midnight (21:00-08:00). Equal start and end means no window.
	 *
	 * @param int        $ts      UTC epoch.
	 * @param array[]    $windows List of array( start, end ).
	 * @param string[]   $zones   Zone ids or offsets.
	 * @return bool
	 */
	public static function is_quiet_at( $ts, $windows, $zones ) {
		foreach ( (array) $windows as $window ) {
			$start = self::minutes( isset( $window[0] ) ? $window[0] : '' );
			$end   = self::minutes( isset( $window[1] ) ? $window[1] : '' );
			if ( null === $start || null === $end || $start === $end ) {
				continue;
			}
			foreach ( (array) $zones as $zone ) {
				$local = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( self::tz( $zone ) );
				$hm    = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );
				$in    = $start < $end ? ( $hm >= $start && $hm < $end ) : ( $hm >= $start || $hm < $end );
				if ( $in ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * First moment at or after $ts that is outside every window in every zone. Candidates
	 * are the window end times in each zone over the next three days.
	 *
	 * @param int      $ts      UTC epoch.
	 * @param array[]  $windows List of array( start, end ).
	 * @param string[] $zones   Zone ids or offsets.
	 * @return int
	 */
	public static function next_open_at( $ts, $windows, $zones ) {
		$ts = (int) $ts;
		if ( ! self::is_quiet_at( $ts, $windows, $zones ) ) {
			return $ts;
		}
		$candidates = array();
		foreach ( (array) $windows as $window ) {
			$end = isset( $window[1] ) ? self::minutes( $window[1] ) : null;
			if ( null === $end ) {
				continue;
			}
			foreach ( (array) $zones as $zone ) {
				$local = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( self::tz( $zone ) );
				for ( $day = 0; $day <= 3; $day++ ) {
					$at = $local->modify( '+' . $day . ' day' )->setTime( intdiv( $end, 60 ), $end % 60, 0 )->getTimestamp();
					if ( $at > $ts ) {
						$candidates[] = $at;
					}
				}
			}
		}
		sort( $candidates );
		foreach ( $candidates as $at ) {
			if ( ! self::is_quiet_at( $at, $windows, $zones ) ) {
				return $at;
			}
		}
		// Windows that overlap to cover the whole day: try again in an hour rather than never.
		return $ts + 3600;
	}
}
