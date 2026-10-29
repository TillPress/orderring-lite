<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SMS provider selection (Twilio default, Vonage, Plivo) and shared helpers.
 *
 * Every text OrderRing Lite sends (pickup texts, test texts, STOP/HELP/START replies) goes
 * through the selected provider. Every provider, Twilio included, is an ORLT_SMS_Provider.
 * Twin of TOC_SMS_Providers in OrderRing Pro; tests/run.php keeps the shared methods equal.
 *
 * Switching providers keeps the opt-out list and consent records (they are stored per
 * phone number, not per provider), and the webhooks of every provider stay registered,
 * so a STOP that reaches a previously used provider is still honored.
 */
class ORLT_SMS_Providers {

	const OPTION  = 'orlt_sms_provider';
	const DEFAULT = 'twilio';

	/** Capability keys a provider reports (ORLT_SMS_Provider::capabilities()). */
	const CAPABILITIES = array( 'sms', 'inbound', 'delivery_status', 'mms', 'voice', 'whatsapp' );

	/** @var array<string,ORLT_SMS_Provider> */
	private static $instances = array();

	/**
	 * @return array<string,string> key => label.
	 */
	public static function choices() {
		return array(
			'twilio' => 'Twilio',
			'vonage' => 'Vonage',
			'plivo'  => 'Plivo',
		);
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string A known provider key (unknown → twilio).
	 */
	public static function normalize_id( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return array_key_exists( $value, self::choices() ) ? $value : self::DEFAULT;
	}

	/**
	 * Selected SMS provider key. Existing installs have no option and stay on Twilio.
	 *
	 * @return string twilio|vonage|plivo
	 */
	public static function active_id() {
		return self::normalize_id( get_option( self::OPTION, self::DEFAULT ) );
	}

	/**
	 * @param string $id Provider key.
	 * @return ORLT_SMS_Provider|null Null for an unknown key.
	 */
	public static function get( $id ) {
		$id = is_string( $id ) ? strtolower( $id ) : '';
		if ( isset( self::$instances[ $id ] ) ) {
			return self::$instances[ $id ];
		}
		if ( 'twilio' === $id ) {
			self::$instances[ $id ] = new ORLT_SMS_Twilio();
		} elseif ( 'vonage' === $id ) {
			self::$instances[ $id ] = new ORLT_SMS_Vonage();
		} elseif ( 'plivo' === $id ) {
			self::$instances[ $id ] = new ORLT_SMS_Plivo();
		} else {
			return null;
		}
		return self::$instances[ $id ];
	}

	/**
	 * The alternative provider plain SMS is routed to, or null when Twilio is selected.
	 * Callers use it to ask "is a provider other than Twilio in use?".
	 *
	 * @return ORLT_SMS_Provider|null
	 */
	public static function active() {
		$id = self::active_id();
		return self::DEFAULT === $id ? null : self::get( $id );
	}

	/**
	 * The provider plain SMS is sent through, Twilio included. Never null.
	 *
	 * @return ORLT_SMS_Provider
	 */
	public static function current() {
		$provider = self::get( self::active_id() );
		return $provider ? $provider : self::get( self::DEFAULT );
	}

	/**
	 * Capability map with the given keys on and every other known key off. Pure.
	 *
	 * @param string[] $on Capability keys this provider supports.
	 * @return array<string,bool>
	 */
	public static function capability_set( array $on ) {
		$out = array();
		foreach ( self::CAPABILITIES as $key ) {
			$out[ $key ] = in_array( $key, $on, true );
		}
		return $out;
	}

	/**
	 * Whether a provider (default: the selected one) supports a capability.
	 *
	 * @param string      $capability sms|inbound|delivery_status|mms|voice|whatsapp.
	 * @param string|null $id         Provider key, or null for the selected provider.
	 * @return bool
	 */
	public static function supports( $capability, $id = null ) {
		$provider = null === $id ? self::current() : self::get( $id );
		if ( ! $provider ) {
			return false;
		}
		$caps = $provider->capabilities();
		return ! empty( $caps[ $capability ] );
	}

	/**
	 * Human labels of a provider's capabilities, for settings screens.
	 *
	 * @param string $id Provider key.
	 * @return string[]
	 */
	public static function capability_labels( $id ) {
		$labels = array(
			'sms'             => __( 'Texts', 'orderring-lite' ),
			'inbound'         => __( 'Customer replies and STOP / HELP / START', 'orderring-lite' ),
			'delivery_status' => __( 'Delivery status', 'orderring-lite' ),
			'mms'             => __( 'Picture messages (MMS)', 'orderring-lite' ),
			'voice'           => __( 'Voice calls', 'orderring-lite' ),
			'whatsapp'        => __( 'WhatsApp', 'orderring-lite' ),
		);
		$out = array();
		foreach ( self::CAPABILITIES as $key ) {
			if ( self::supports( $key, $id ) ) {
				$out[] = $labels[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Label of the selected SMS provider.
	 *
	 * @return string
	 */
	public static function active_label() {
		$choices = self::choices();
		return $choices[ self::active_id() ];
	}

	/**
	 * Read a credential: a wp-config constant wins over the saved option.
	 *
	 * @param string $option   Option name.
	 * @param string $constant Constant name.
	 * @return string
	 */
	public static function credential( $option, $constant ) {
		if ( defined( $constant ) && constant( $constant ) ) {
			return trim( (string) constant( $constant ) );
		}
		return trim( (string) get_option( $option, '' ) );
	}

	/**
	 * @param string $constant Constant name.
	 * @return bool
	 */
	public static function is_constant( $constant ) {
		return defined( $constant ) && constant( $constant );
	}

	/**
	 * Secret fields per provider: option => wp-config constant. Saved values are never
	 * printed back; leaving the field blank keeps them.
	 *
	 * @return array<string,string>
	 */
	public static function secret_fields() {
		return array(
			'orlt_vonage_api_secret'       => 'ORLT_VONAGE_API_SECRET',
			'orlt_vonage_signature_secret' => 'ORLT_VONAGE_SIGNATURE_SECRET',
			'orlt_plivo_auth_token'        => 'ORLT_PLIVO_AUTH_TOKEN',
		);
	}

	/**
	 * Non-secret fields: option => wp-config constant.
	 *
	 * @return array<string,string>
	 */
	public static function plain_fields() {
		return array(
			'orlt_vonage_api_key' => 'ORLT_VONAGE_API_KEY',
			'orlt_vonage_from'    => 'ORLT_VONAGE_FROM',
			'orlt_plivo_auth_id'  => 'ORLT_PLIVO_AUTH_ID',
			'orlt_plivo_from'     => 'ORLT_PLIVO_FROM',
		);
	}

	/**
	 * Every option this feature stores (uninstall and settings-recovery use it).
	 *
	 * @return string[]
	 */
	public static function option_names() {
		return array_merge(
			array( self::OPTION, 'orlt_vonage_signature_method' ),
			array_keys( self::plain_fields() ),
			array_keys( self::secret_fields() )
		);
	}

	/**
	 * A sender is an E.164 number or an alphanumeric sender ID (1-11 letters, digits,
	 * spaces; at least one letter). Returns the cleaned value, or '' when invalid.
	 *
	 * @param string $value Raw sender.
	 * @return string
	 */
	public static function normalize_sender( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$compact = preg_replace( '/[\s().-]/', '', $value );
		if ( ORLT_Twilio::is_e164( $compact ) ) {
			return $compact;
		}
		if ( preg_match( '/^(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11}$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * E.164 → bare digits, the form Vonage and Plivo use for numbers.
	 *
	 * @param string $number Number.
	 * @return string
	 */
	public static function digits( $number ) {
		return preg_replace( '/\D/', '', (string) $number );
	}

	/**
	 * Provider number (digits, no '+') → E.164. Inbound numbers from Vonage and Plivo
	 * are always international, so no national-number guessing is applied.
	 *
	 * @param string $number Number as received.
	 * @return string '' when not a plausible number.
	 */
	public static function to_e164( $number ) {
		$digits = self::digits( $number );
		$e164   = '' === $digits ? '' : '+' . ltrim( $digits, '0' );
		return ORLT_Twilio::is_e164( $e164 ) ? $e164 : '';
	}

	/**
	 * Shared HTTP call with the same transport-error semantics as ORLT_Twilio::request():
	 * a POST that timed out or lost its reply may have been accepted (request_sent).
	 *
	 * @param string $method GET|POST.
	 * @param string $url    URL.
	 * @param array  $args   wp_remote_request() args (headers, body).
	 * @return array{success:bool,code:int,data:array,error?:string,network_error?:bool,timed_out?:bool,request_sent?:bool}
	 */
	public static function http( $method, $url, array $args ) {
		$method          = strtoupper( (string) $method );
		$args['method']  = $method;
		$args['timeout'] = isset( $args['timeout'] ) ? $args['timeout'] : 20;

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$timed_out = ORLT_Twilio::is_timeout_error( $response );
			$receive   = ORLT_Twilio::is_receive_error( $response );
			return array(
				'success'       => false,
				'code'          => 0,
				'data'          => array(),
				'error'         => $response->get_error_message(),
				'network_error' => true,
				'timed_out'     => $timed_out,
				'request_sent'  => ( 'POST' === $method ) && ( $timed_out || $receive ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'success' => $code >= 200 && $code < 300,
			'code'    => $code,
			'data'    => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Failure result in send_sms()'s shape from an http() result.
	 *
	 * @param array  $res   http() result.
	 * @param string $error Explained error.
	 * @return array
	 */
	public static function failure( array $res, $error ) {
		return array(
			'success'       => false,
			'error'         => (string) $error,
			'http_code'     => (int) ( $res['code'] ?? 0 ),
			'timed_out'     => ! empty( $res['timed_out'] ),
			'network_error' => ! empty( $res['network_error'] ),
			'request_sent'  => ! empty( $res['request_sent'] ),
		);
	}

	/**
	 * Webhook URLs to paste into the provider's dashboard.
	 *
	 * @param string $id Provider key.
	 * @return array<string,string> inbound|status => URL. Empty for Twilio.
	 */
	public static function webhook_urls( $id ) {
		$id = self::normalize_id( $id );
		if ( 'twilio' === $id ) {
			return array();
		}
		return array(
			'inbound' => ORLT_Webhooks::rest_url( $id . '/inbound' ),
			'status'  => ORLT_Webhooks::rest_url( $id . '/status' ),
		);
	}

	/**
	 * Admin warnings for a non-Twilio provider. Pure apart from option reads.
	 * (Lite has no voice calls or WhatsApp, so Pro's Twilio-only warning has no twin.)
	 *
	 * @return string[]
	 */
	public static function admin_warnings() {
		$provider = self::active();
		if ( ! $provider ) {
			return array();
		}
		$out   = array();
		$label = $provider->label();
		if ( ! $provider->is_configured() ) {
			$out[] = sprintf(
				/* translators: %s: SMS provider name */
				__( 'OrderRing Lite is set to send SMS through %s, but its credentials or sender are missing, so texts are not sent. Complete them under OrderRing Lite → Settings → SMS provider.', 'orderring-lite' ),
				$label
			);
		}
		if ( ! $provider->inbound_ready() ) {
			$out[] = sprintf(
				/* translators: %s: SMS provider name */
				__( 'Customer replies through %s (including STOP opt-outs) are refused until you add its signing secret under OrderRing Lite → Settings → SMS provider.', 'orderring-lite' ),
				$label
			);
		}
		return $out;
	}
}
