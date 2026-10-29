<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vonage (formerly Nexmo) SMS API.
 *
 * Uses the SMS API (POST https://rest.nexmo.com/sms/json) rather than the Messages API:
 * it needs only an API key and secret (no Vonage Application, private key or JWT), it
 * accepts a per-message delivery-receipt callback, and its inbound and receipt webhooks
 * can be signed with the account signature secret (the `sig` parameter), which is what
 * lets OrderRing refuse forged STOP/START replies.
 *
 * Merchant setup: Vonage dashboard → API Settings → SMS settings must be "SMS API", and
 * signed webhooks must be enabled on the account (Vonage support enables this).
 */
class ORLT_SMS_Vonage implements ORLT_SMS_Provider {

	const ENDPOINT = 'https://rest.nexmo.com/sms/json';

	/** Signed webhooks older than this are refused (Vonage retries inbound for 24h). */
	const MAX_SIG_AGE = 2 * DAY_IN_SECONDS;

	public function id() {
		return 'vonage';
	}

	public function label() {
		return 'Vonage';
	}

	/**
	 * Plain SMS, replies (STOP/HELP/START and the inbox) and delivery receipts.
	 *
	 * @return array<string,bool>
	 */
	public function capabilities() {
		return ORLT_SMS_Providers::capability_set( array( 'sms', 'inbound', 'delivery_status' ) );
	}

	/**
	 * @return array{key:string,secret:string,sig_secret:string,sig_method:string,from:string}
	 */
	public function credentials() {
		return array(
			'key'        => ORLT_SMS_Providers::credential( 'orlt_vonage_api_key', 'ORLT_VONAGE_API_KEY' ),
			'secret'     => ORLT_SMS_Providers::credential( 'orlt_vonage_api_secret', 'ORLT_VONAGE_API_SECRET' ),
			'sig_secret' => ORLT_SMS_Providers::credential( 'orlt_vonage_signature_secret', 'ORLT_VONAGE_SIGNATURE_SECRET' ),
			'sig_method' => self::normalize_method( get_option( 'orlt_vonage_signature_method', 'md5hash' ) ),
			'from'       => ORLT_SMS_Providers::credential( 'orlt_vonage_from', 'ORLT_VONAGE_FROM' ),
		);
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string md5hash|md5|sha1|sha256|sha512 (unknown → md5hash, Vonage's default).
	 */
	public static function normalize_method( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $value, array( 'md5hash', 'md5', 'sha1', 'sha256', 'sha512' ), true ) ? $value : 'md5hash';
	}

	public function is_configured() {
		$c = $this->credentials();
		return '' !== $c['key'] && '' !== $c['secret'] && '' !== ORLT_SMS_Providers::normalize_sender( $c['from'] );
	}

	public function inbound_ready() {
		$c = $this->credentials();
		return '' !== $c['sig_secret'];
	}

	/**
	 * Request body for one SMS. Pure apart from credential reads.
	 *
	 * @param string $to         E.164 recipient.
	 * @param string $body       Text.
	 * @param string $status_url Receipt callback.
	 * @return array
	 */
	public function build_request( $to, $body, $status_url = '' ) {
		$c      = $this->credentials();
		$from   = ORLT_SMS_Providers::normalize_sender( $c['from'] );
		$params = array(
			'api_key'           => $c['key'],
			'api_secret'        => $c['secret'],
			'from'              => ORLT_Twilio::is_e164( $from ) ? ORLT_SMS_Providers::digits( $from ) : $from,
			'to'                => ORLT_SMS_Providers::digits( $to ),
			'text'              => (string) $body,
			// Non-ASCII text (accents, emoji, other scripts) is mangled as type=text.
			'type'              => preg_match( '/[^\x00-\x7F]/', (string) $body ) ? 'unicode' : 'text',
			'status-report-req' => '1',
		);
		// Vonage caps the callback at 100 characters; longer URLs fall back to the
		// account-level delivery receipt webhook set in the dashboard.
		if ( '' !== $status_url && strlen( $status_url ) <= 100 ) {
			$params['callback'] = $status_url;
		}
		return $params;
	}

	public function send( $to, $body, $status_url = '' ) {
		if ( ! $this->is_configured() ) {
			return array(
				'success'       => false,
				'failure_class' => 'deferred',
				'error'         => __( 'Vonage credentials not configured.', 'orderring-lite' ),
			);
		}
		$res = ORLT_SMS_Providers::http(
			'POST',
			self::ENDPOINT,
			array(
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $this->build_request( $to, $body, $status_url ),
			)
		);
		return self::map_response( $res );
	}

	/**
	 * Map an http() result into send_sms()'s result shape. Pure.
	 *
	 * Vonage answers HTTP 200 even for refused messages; the per-message `status`
	 * says what happened ("0" = accepted). Throttling (1), internal error (5) and too
	 * many connections (10) are worth retrying; the rest are not.
	 *
	 * @param array $res ORLT_SMS_Providers::http() result.
	 * @return array
	 */
	public static function map_response( array $res ) {
		if ( ! empty( $res['network_error'] ) ) {
			return ORLT_SMS_Providers::failure( $res, (string) ( $res['error'] ?? __( 'Could not reach Vonage.', 'orderring-lite' ) ) );
		}
		$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		$msg  = ( isset( $data['messages'][0] ) && is_array( $data['messages'][0] ) ) ? $data['messages'][0] : array();

		if ( empty( $res['success'] ) ) {
			$text = isset( $msg['error-text'] ) ? (string) $msg['error-text'] : ( isset( $data['error-text'] ) ? (string) $data['error-text'] : '' );
			/* translators: 1: HTTP status code, 2: Vonage error text */
			return ORLT_SMS_Providers::failure( $res, trim( sprintf( __( 'Vonage HTTP %1$d. %2$s', 'orderring-lite' ), (int) ( $res['code'] ?? 0 ), $text ) ) );
		}

		if ( ! $msg || ! isset( $msg['status'] ) ) {
			return ORLT_SMS_Providers::failure( $res, __( 'Unknown Vonage error', 'orderring-lite' ) );
		}

		if ( '0' === (string) $msg['status'] && ! empty( $msg['message-id'] ) ) {
			return array(
				'success' => true,
				'sid'     => (string) $msg['message-id'],
				'status'  => 'queued',
			);
		}

		$code                 = (int) $msg['status'];
		$out                  = ORLT_SMS_Providers::failure( $res, self::explain_error( $code, isset( $msg['error-text'] ) ? (string) $msg['error-text'] : '' ) );
		$out['provider_code'] = $code;
		$out['failure_class'] = in_array( $code, array( 1, 5, 10 ), true ) ? 'transient' : 'terminal';
		return $out;
	}

	/**
	 * @param int    $code Vonage message status.
	 * @param string $text Vonage error-text.
	 * @return string
	 */
	public static function explain_error( $code, $text = '' ) {
		$map  = array(
			1  => __( 'Vonage is throttling this account; the text will be retried.', 'orderring-lite' ),
			2  => __( 'Vonage rejected the request: a required parameter is missing.', 'orderring-lite' ),
			3  => __( 'Vonage rejected a parameter value (check the sender and recipient number).', 'orderring-lite' ),
			4  => __( 'Vonage API key or secret is wrong.', 'orderring-lite' ),
			5  => __( 'Vonage had an internal error; the text will be retried.', 'orderring-lite' ),
			6  => __( 'Vonage could not route this number.', 'orderring-lite' ),
			7  => __( 'This number is barred by Vonage.', 'orderring-lite' ),
			8  => __( 'Your Vonage account is suspended.', 'orderring-lite' ),
			9  => __( 'Your Vonage account is out of credit.', 'orderring-lite' ),
			10 => __( 'Too many connections to Vonage; the text will be retried.', 'orderring-lite' ),
			15 => __( 'Vonage refused the sender (From). Use a Vonage number on your account or a sender ID allowed in the destination country.', 'orderring-lite' ),
			29 => __( 'Your Vonage account is in demo mode and can only text numbers you have added to the test list.', 'orderring-lite' ),
		);
		$base = isset( $map[ $code ] ) ? $map[ $code ] : __( 'Vonage refused the text.', 'orderring-lite' );
		/* translators: %d: Vonage status code */
		$suffix = sprintf( __( '(Vonage status %d)', 'orderring-lite' ), (int) $code );
		return trim( $base . ' ' . $suffix . ( '' !== $text ? ' ' . $text : '' ) );
	}

	/**
	 * Vonage signature over a parameter set (same algorithm as Vonage's own SDKs):
	 * drop `sig`, sort by key, replace & and = in values with _, join as
	 * "&k=v&k=v", then md5(base . secret) for md5hash, else HMAC with the secret.
	 *
	 * @param array  $params Parameters (including timestamp).
	 * @param string $secret Signature secret.
	 * @param string $method md5hash|md5|sha1|sha256|sha512.
	 * @return string Lower-case hex.
	 */
	public static function signature( array $params, $secret, $method ) {
		unset( $params['sig'] );
		ksort( $params, SORT_STRING );
		$base = '';
		foreach ( $params as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			} elseif ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}
			$base .= '&' . $key . '=' . str_replace( array( '&', '=' ), '_', (string) $value );
		}
		$method = self::normalize_method( $method );
		if ( 'md5hash' === $method ) {
			return md5( $base . $secret );
		}
		return strtolower( hash_hmac( $method, $base, (string) $secret ) );
	}

	public function verify_webhook( array $params, array $headers, $url, $method ) {
		$c = $this->credentials();
		if ( '' === $c['sig_secret'] ) {
			return false;
		}
		$sig = isset( $params['sig'] ) && is_scalar( $params['sig'] ) ? strtolower( trim( (string) $params['sig'] ) ) : '';
		if ( '' === $sig ) {
			return false;
		}
		$ts = isset( $params['timestamp'] ) ? (string) $params['timestamp'] : '';
		if ( ! ctype_digit( $ts ) ) {
			return false;
		}
		$age = time() - (int) $ts;
		if ( $age > self::MAX_SIG_AGE || $age < -5 * MINUTE_IN_SECONDS ) {
			return false;
		}
		// A webhook for another Vonage account (same URL pointed at two accounts) is not ours.
		if ( isset( $params['api-key'] ) && '' !== $c['key'] && (string) $params['api-key'] !== $c['key'] ) {
			return false;
		}
		return hash_equals( self::signature( $params, $c['sig_secret'], $c['sig_method'] ), $sig );
	}

	public function parse_inbound( array $params ) {
		if ( empty( $params['msisdn'] ) || ! isset( $params['text'] ) ) {
			return null;
		}
		return array(
			'from' => ORLT_SMS_Providers::to_e164( (string) $params['msisdn'] ),
			'body' => (string) $params['text'],
			'sid'  => isset( $params['messageId'] ) ? (string) $params['messageId'] : '',
		);
	}

	public function parse_status( array $params ) {
		$sid = isset( $params['messageId'] ) ? (string) $params['messageId'] : '';
		$raw = isset( $params['status'] ) ? strtolower( (string) $params['status'] ) : '';
		if ( '' === $sid || '' === $raw ) {
			return null;
		}
		$map = array(
			'accepted'  => 'sent',
			'buffered'  => 'sent',
			'delivered' => 'delivered',
			'expired'   => 'undelivered',
			'failed'    => 'failed',
			'rejected'  => 'failed',
		);
		if ( ! isset( $map[ $raw ] ) ) {
			return null; // "unknown": leave the logged status as it is.
		}
		$err = isset( $params['err-code'] ) ? (string) $params['err-code'] : '';
		return array(
			'sid'    => $sid,
			'status' => $map[ $raw ],
			'error'  => ( '' !== $err && '0' !== $err ) ? $err : '',
		);
	}
}
