<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plivo Message API (POST https://api.plivo.com/v1/Account/{auth_id}/Message/).
 *
 * Webhooks are verified with the Auth Token: X-Plivo-Signature-V3 when Plivo sends it
 * (HMAC-SHA256 over URL + sorted params + "." + nonce), otherwise X-Plivo-Signature-V2
 * (HMAC-SHA256 over URL without query + nonce), which Plivo's messaging docs describe.
 * V2 does not cover the body, so each V2 nonce is bound to the first body it arrived
 * with and a reused nonce carrying a different body is refused.
 */
class ORLT_SMS_Plivo implements ORLT_SMS_Provider {

	const API_BASE = 'https://api.plivo.com/v1/Account/';

	public function id() {
		return 'plivo';
	}

	public function label() {
		return 'Plivo';
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
	 * @return array{id:string,token:string,from:string}
	 */
	public function credentials() {
		return array(
			'id'    => ORLT_SMS_Providers::credential( 'orlt_plivo_auth_id', 'ORLT_PLIVO_AUTH_ID' ),
			'token' => ORLT_SMS_Providers::credential( 'orlt_plivo_auth_token', 'ORLT_PLIVO_AUTH_TOKEN' ),
			'from'  => ORLT_SMS_Providers::credential( 'orlt_plivo_from', 'ORLT_PLIVO_FROM' ),
		);
	}

	public function is_configured() {
		$c = $this->credentials();
		return '' !== $c['id'] && '' !== $c['token'] && '' !== ORLT_SMS_Providers::normalize_sender( $c['from'] );
	}

	public function inbound_ready() {
		$c = $this->credentials();
		return '' !== $c['token'];
	}

	/**
	 * URL and wp_remote_request() args for one SMS. Pure apart from credential reads.
	 *
	 * @param string $to         E.164 recipient.
	 * @param string $body       Text.
	 * @param string $status_url Status callback.
	 * @return array{url:string,args:array}
	 */
	public function build_request( $to, $body, $status_url = '' ) {
		$c       = $this->credentials();
		$from    = ORLT_SMS_Providers::normalize_sender( $c['from'] );
		$payload = array(
			'src'  => ORLT_Twilio::is_e164( $from ) ? ORLT_SMS_Providers::digits( $from ) : $from,
			'dst'  => ORLT_SMS_Providers::digits( $to ),
			'text' => (string) $body,
			'type' => 'sms',
		);
		if ( '' !== $status_url ) {
			$payload['url']    = $status_url;
			$payload['method'] = 'POST';
		}
		return array(
			'url'  => self::API_BASE . rawurlencode( $c['id'] ) . '/Message/',
			'args' => array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $c['id'] . ':' . $c['token'] ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			),
		);
	}

	public function send( $to, $body, $status_url = '' ) {
		if ( ! $this->is_configured() ) {
			return array(
				'success'       => false,
				'failure_class' => 'deferred',
				'error'         => __( 'Plivo credentials not configured.', 'orderring-lite' ),
			);
		}
		$req = $this->build_request( $to, $body, $status_url );
		return self::map_response( ORLT_SMS_Providers::http( 'POST', $req['url'], $req['args'] ) );
	}

	/**
	 * Map an http() result into send_sms()'s result shape. Pure. Plivo answers 202
	 * with message_uuid[]; errors carry an `error` string and a 4xx/5xx status, which
	 * classify_send_result() turns into terminal (4xx) or transient (429, 5xx).
	 *
	 * @param array $res ORLT_SMS_Providers::http() result.
	 * @return array
	 */
	public static function map_response( array $res ) {
		if ( ! empty( $res['network_error'] ) ) {
			return ORLT_SMS_Providers::failure( $res, (string) ( $res['error'] ?? __( 'Could not reach Plivo.', 'orderring-lite' ) ) );
		}
		$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		if ( empty( $res['success'] ) ) {
			return ORLT_SMS_Providers::failure( $res, self::explain_error( (int) ( $res['code'] ?? 0 ), isset( $data['error'] ) && is_scalar( $data['error'] ) ? (string) $data['error'] : '' ) );
		}
		$uuid = '';
		if ( isset( $data['message_uuid'] ) ) {
			$uuid = is_array( $data['message_uuid'] ) ? (string) reset( $data['message_uuid'] ) : (string) $data['message_uuid'];
		}
		if ( '' === $uuid ) {
			return ORLT_SMS_Providers::failure( $res, __( 'Unknown Plivo error', 'orderring-lite' ) );
		}
		return array(
			'success' => true,
			'sid'     => $uuid,
			'status'  => 'queued',
		);
	}

	/**
	 * @param int    $http HTTP status.
	 * @param string $text Plivo error text.
	 * @return string
	 */
	public static function explain_error( $http, $text = '' ) {
		if ( 401 === $http ) {
			$base = __( 'Plivo Auth ID or Auth Token is wrong.', 'orderring-lite' );
		} elseif ( 429 === $http ) {
			$base = __( 'Plivo is rate-limiting this account; the text will be retried.', 'orderring-lite' );
		} elseif ( $http >= 500 ) {
			$base = __( 'Plivo had a server error; the text will be retried.', 'orderring-lite' );
		} else {
			$base = __( 'Plivo refused the text.', 'orderring-lite' );
		}
		/* translators: %d: HTTP status code */
		$suffix = sprintf( __( '(Plivo HTTP %d)', 'orderring-lite' ), (int) $http );
		return trim( $base . ' ' . $suffix . ( '' !== $text ? ' ' . $text : '' ) );
	}

	/**
	 * Plivo V3 signature, as Plivo's SDKs compute it. Pure.
	 *
	 * @param string $method GET|POST.
	 * @param string $url    URL Plivo called.
	 * @param array  $params Body (POST) or query (GET) parameters.
	 * @param string $nonce  X-Plivo-Signature-V3-Nonce.
	 * @param string $token  Auth Token.
	 * @return string Base64.
	 */
	public static function signature_v3( $method, $url, array $params, $nonce, $token ) {
		$parts = wp_parse_url( (string) $url );
		$base  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}
		if ( 'GET' === strtoupper( (string) $method ) ) {
			$query = array_merge( $query, $params );
			$qs    = self::sorted_query( $query );
			if ( '' !== $qs ) {
				$base .= '?' . $qs;
			}
		} else {
			$qs = self::sorted_query( $query );
			if ( $params ) {
				$base .= '?' . $qs;
				if ( '' !== $qs ) {
					$base .= '.';
				}
				$base .= self::sorted_params( $params );
			} elseif ( '' !== $qs ) {
				$base .= '?' . $qs;
			}
		}
		return base64_encode( hash_hmac( 'sha256', $base . '.' . $nonce, (string) $token, true ) );
	}

	/**
	 * Plivo V2 signature: HMAC-SHA256 over the URL without its query, then the nonce. Pure.
	 *
	 * @param string $url   URL Plivo called.
	 * @param string $nonce X-Plivo-Signature-V2-Nonce.
	 * @param string $token Auth Token.
	 * @return string Base64.
	 */
	public static function signature_v2( $url, $nonce, $token ) {
		$parts = wp_parse_url( (string) $url );
		$base  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' );
		return base64_encode( hash_hmac( 'sha256', $base . $nonce, (string) $token, true ) );
	}

	/**
	 * @param array $params Params.
	 * @return string k=v&k=v sorted by key.
	 */
	private static function sorted_query( array $params ) {
		ksort( $params, SORT_STRING );
		$out = array();
		foreach ( $params as $k => $v ) {
			$out[] = $k . '=' . ( is_scalar( $v ) ? (string) $v : '' );
		}
		return implode( '&', $out );
	}

	/**
	 * @param array $params Params.
	 * @return string kvkv sorted by key.
	 */
	private static function sorted_params( array $params ) {
		ksort( $params, SORT_STRING );
		$out = '';
		foreach ( $params as $k => $v ) {
			$out .= $k . ( is_scalar( $v ) ? (string) $v : '' );
		}
		return $out;
	}

	public function verify_webhook( array $params, array $headers, $url, $method ) {
		$c = $this->credentials();
		if ( '' === $c['token'] ) {
			return false;
		}
		$v3  = isset( $headers['x-plivo-signature-v3'] ) ? trim( (string) $headers['x-plivo-signature-v3'] ) : '';
		$v3n = isset( $headers['x-plivo-signature-v3-nonce'] ) ? (string) $headers['x-plivo-signature-v3-nonce'] : '';
		$v2  = isset( $headers['x-plivo-signature-v2'] ) ? trim( (string) $headers['x-plivo-signature-v2'] ) : '';
		$v2n = isset( $headers['x-plivo-signature-v2-nonce'] ) ? (string) $headers['x-plivo-signature-v2-nonce'] : '';

		if ( '' !== $v3 && '' !== $v3n ) {
			$want = self::signature_v3( $method, $url, $params, $v3n, $c['token'] );
			foreach ( explode( ',', $v3 ) as $candidate ) {
				if ( hash_equals( $want, trim( $candidate ) ) ) {
					return true;
				}
			}
			return false;
		}

		if ( '' !== $v2 && '' !== $v2n ) {
			if ( ! hash_equals( self::signature_v2( $url, $v2n, $c['token'] ), $v2 ) ) {
				return false;
			}
			// V2 signs only URL + nonce: bind the nonce to this exact body so a captured
			// signature cannot be replayed with a forged "STOP"/"START".
			$key  = 'orlt_plivo_nonce_' . md5( $v2n );
			$hash = md5( (string) wp_json_encode( self::canonical( $params ) ) );
			$seen = get_transient( $key );
			if ( false !== $seen && $seen !== $hash ) {
				return false;
			}
			set_transient( $key, $hash, 2 * DAY_IN_SECONDS );
			return true;
		}

		return false;
	}

	/**
	 * @param array $params Params.
	 * @return array Sorted copy.
	 */
	private static function canonical( array $params ) {
		ksort( $params, SORT_STRING );
		return $params;
	}

	public function parse_inbound( array $params ) {
		$from = isset( $params['From'] ) ? (string) $params['From'] : '';
		if ( '' === $from || ! isset( $params['Text'] ) ) {
			return null;
		}
		return array(
			'from' => ORLT_SMS_Providers::to_e164( $from ),
			'body' => (string) $params['Text'],
			'sid'  => isset( $params['MessageUUID'] ) ? (string) $params['MessageUUID'] : '',
		);
	}

	public function parse_status( array $params ) {
		$sid = isset( $params['MessageUUID'] ) ? (string) $params['MessageUUID'] : '';
		$raw = isset( $params['Status'] ) ? strtolower( (string) $params['Status'] ) : '';
		if ( '' === $sid || '' === $raw ) {
			return null;
		}
		$map = array(
			'queued'      => 'queued',
			'sent'        => 'sent',
			'delivered'   => 'delivered',
			'undelivered' => 'undelivered',
			'failed'      => 'failed',
			'rejected'    => 'failed',
		);
		if ( ! isset( $map[ $raw ] ) ) {
			return null;
		}
		$err = isset( $params['ErrorCode'] ) ? (string) $params['ErrorCode'] : '';
		return array(
			'sid'    => $sid,
			'status' => $map[ $raw ],
			'error'  => ( '' !== $err && '0' !== $err && '000' !== $err ) ? $err : '',
		);
	}
}
