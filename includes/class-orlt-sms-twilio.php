<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Twilio Programmable Messaging as an ORLT_SMS_Provider (twin of TOC_SMS_Twilio).
 *
 * The HTTP call itself stays in ORLT_Twilio::api_send_message(), next to the
 * authenticated request helper the connection test also uses. Twilio's inbound and
 * status webhooks keep their own routes (orderring-lite/v1/sms, /message-status) and
 * TwiML replies in ORLT_Webhooks; verify_webhook() / parse_inbound() / parse_status()
 * give the same answers for code that works with any provider.
 */
class ORLT_SMS_Twilio implements ORLT_SMS_Provider {

	public function id() {
		return 'twilio';
	}

	public function label() {
		return 'Twilio';
	}

	/**
	 * Lite sends no voice calls or WhatsApp (those are OrderRing Pro features).
	 *
	 * @return array<string,bool>
	 */
	public function capabilities() {
		return ORLT_SMS_Providers::capability_set( array( 'sms', 'inbound', 'delivery_status' ) );
	}

	public function is_configured() {
		return ORLT_Twilio::instance()->is_configured();
	}

	public function inbound_ready() {
		return '' !== ORLT_SMS_Providers::credential( 'orlt_auth_token', 'ORLT_AUTH_TOKEN' );
	}

	public function send( $to, $body, $status_url = '' ) {
		return self::map_response( ORLT_Twilio::instance()->api_send_message( $to, $body, $status_url ) );
	}

	/**
	 * Map a Twilio request() result into send_sms()'s result shape. Pure.
	 *
	 * @param array $result ORLT_Twilio request() result.
	 * @return array
	 */
	public static function map_response( array $result ) {
		if ( empty( $result['success'] ) ) {
			// Lite shows Twilio's own error text (Pro maps codes to explanations).
			return array(
				'success'       => false,
				'error'         => $result['error'] ?? __( 'Unknown Twilio error', 'orderring-lite' ),
				'http_code'     => (int) ( $result['code'] ?? 0 ),
				'timed_out'     => ! empty( $result['timed_out'] ),
				'network_error' => ! empty( $result['network_error'] ),
				'request_sent'  => ! empty( $result['request_sent'] ),
			);
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( empty( $data['sid'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Unknown Twilio error', 'orderring-lite' ),
			);
		}
		return array(
			'success' => true,
			'sid'     => (string) $data['sid'],
			'status'  => isset( $data['status'] ) ? (string) $data['status'] : 'queued',
		);
	}

	/**
	 * X-Twilio-Signature: base64(HMAC-SHA1(url . sorted key/value pairs, auth token)).
	 *
	 * @param string $url    Full URL Twilio called.
	 * @param array  $params POST parameters.
	 * @param string $token  Auth token.
	 * @return string
	 */
	public static function signature( $url, array $params, $token ) {
		$data = (string) $url;
		ksort( $params );
		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$data .= $key . $value;
		}
		return base64_encode( hash_hmac( 'sha1', $data, (string) $token, true ) );
	}

	public function verify_webhook( array $params, array $headers, $url, $method ) {
		$token = ORLT_SMS_Providers::credential( 'orlt_auth_token', 'ORLT_AUTH_TOKEN' );
		$sig   = isset( $headers['x-twilio-signature'] ) ? trim( (string) $headers['x-twilio-signature'] ) : '';
		if ( '' === $token || '' === $sig ) {
			return false;
		}
		return hash_equals( self::signature( $url, 'GET' === strtoupper( (string) $method ) ? array() : $params, $token ), $sig );
	}

	public function parse_inbound( array $params ) {
		if ( empty( $params['From'] ) || ! isset( $params['Body'] ) ) {
			return null;
		}
		return array(
			'from' => (string) $params['From'],
			'body' => (string) $params['Body'],
			'sid'  => isset( $params['MessageSid'] ) ? (string) $params['MessageSid'] : '',
		);
	}

	public function parse_status( array $params ) {
		$sid    = isset( $params['MessageSid'] ) ? (string) $params['MessageSid'] : '';
		$status = isset( $params['MessageStatus'] ) ? strtolower( (string) $params['MessageStatus'] ) : '';
		if ( '' === $sid || '' === $status ) {
			return null;
		}
		return array(
			'sid'    => $sid,
			'status' => $status,
			'error'  => isset( $params['ErrorCode'] ) ? (string) $params['ErrorCode'] : '',
		);
	}
}
