<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ORLT_Twilio {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
	}

	public static function is_e164( $number ) {
		return (bool) preg_match( '/^\+[1-9]\d{7,14}$/', (string) $number );
	}

	/**
	 * True when OrderRing Pro is installed AND currently licensed to send.
	 *
	 * @return bool
	 */
	public static function pro_is_licensed() {
		if ( class_exists( 'TOC_Twilio', false ) && method_exists( 'TOC_Twilio', 'pro_active' ) ) {
			return (bool) TOC_Twilio::pro_active();
		}
		if ( class_exists( 'TOC_License', false ) && method_exists( 'TOC_License', 'is_pro' ) ) {
			return (bool) TOC_License::instance()->is_pro();
		}
		return false;
	}

	/**
	 * @param WP_Error $err Transport error.
	 * @return bool
	 */
	public static function is_timeout_error( $err ) {
		if ( ! is_wp_error( $err ) ) {
			return false;
		}
		$code = (string) $err->get_error_code();
		$msg  = strtolower( (string) $err->get_error_message() );
		if ( $code === 'http_request_timeout' ) {
			return true;
		}
		return ( false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'timeout' ) || false !== strpos( $msg, 'curl error 28' ) );
	}

	/**
	 * Connection reset / receive errors after the request left this host.
	 *
	 * @param WP_Error $err Transport error.
	 * @return bool
	 */
	public static function is_receive_error( $err ) {
		if ( ! is_wp_error( $err ) ) {
			return false;
		}
		$msg = strtolower( (string) $err->get_error_message() );
		foreach ( array( 'connection reset', 'recv failure', 'curl error 56', 'curl error 52', 'empty reply', 'ssl_connect', 'ssl_read' ) as $needle ) {
			if ( false !== strpos( $msg, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/** Public site base for webhooks/TwiML. Optional override for reverse proxies. */
	public function public_base_url() {
		$override = trim( (string) get_option( 'orlt_webhook_base_url', '' ) );
		if ( $override !== '' ) {
			return trailingslashit( $override );
		}
		return home_url( '/' );
	}

	public function webhook_url( $query_key ) {
		$map = array(
			'orlt_sms'        => 'sms',
			'orlt_msg_status' => 'message-status',
		);

		// Prefer REST routes; query-string aliases remain supported forever.
		if ( isset( $map[ $query_key ] ) ) {
			return ORLT_Webhooks::rest_url( $map[ $query_key ] );
		}

		return add_query_arg( $query_key, '1', $this->public_base_url() );
	}

	private function get_credentials() {
		$sid   = self::config_constant( 'ORLT_ACCOUNT_SID', 'ORL_ACCOUNT_SID' );
		$token = self::config_constant( 'ORLT_AUTH_TOKEN', 'ORL_AUTH_TOKEN' );
		$from  = self::config_constant( 'ORLT_FROM_NUMBER', 'ORL_FROM_NUMBER' );

		return array(
			'sid'   => '' !== $sid ? $sid : (string) get_option( 'orlt_account_sid', '' ),
			'token' => '' !== $token ? $token : (string) get_option( 'orlt_auth_token', '' ),
			'from'  => '' !== $from ? $from : (string) get_option( 'orlt_from_number', '' ),
		);
	}

	/**
	 * Read a wp-config credential constant, preferring the current name and
	 * falling back to the legacy pre-1.0.5 name so existing installs that set
	 * ORL_ACCOUNT_SID / ORL_AUTH_TOKEN / ORL_FROM_NUMBER keep working.
	 *
	 * @param string $current Current constant name (ORLT_*).
	 * @param string $legacy  Legacy constant name (ORL_*).
	 * @return string Value, or '' when neither is defined/truthy.
	 */
	private static function config_constant( $current, $legacy ) {
		if ( defined( $current ) && constant( $current ) ) {
			return (string) constant( $current );
		}
		if ( defined( $legacy ) && constant( $legacy ) ) {
			return (string) constant( $legacy );
		}
		return '';
	}

	/**
	 * Whether a credential is locked via wp-config constant.
	 *
	 * @param string $which sid|token|from
	 * @return bool
	 */
	public function credential_is_constant( $which ) {
		if ( $which === 'sid' ) {
			return ( defined( 'ORLT_ACCOUNT_SID' ) && ORLT_ACCOUNT_SID ) || ( defined( 'ORL_ACCOUNT_SID' ) && ORL_ACCOUNT_SID );
		}
		if ( $which === 'token' ) {
			return ( defined( 'ORLT_AUTH_TOKEN' ) && ORLT_AUTH_TOKEN ) || ( defined( 'ORL_AUTH_TOKEN' ) && ORL_AUTH_TOKEN );
		}
		if ( $which === 'from' ) {
			return ( defined( 'ORLT_FROM_NUMBER' ) && ORLT_FROM_NUMBER ) || ( defined( 'ORL_FROM_NUMBER' ) && ORL_FROM_NUMBER );
		}
		return false;
	}

	/**
	 * Shared Twilio REST request helper.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   Path under /2010-04-01/Accounts/{SID}/ (or absolute path starting with /Accounts/…).
	 * @param array  $body   Request body for POST.
	 * @return array{success:bool,code:int,data:array,error?:string}
	 */
	private function request( $method, $path, $body = array() ) {
		$creds = $this->get_credentials();
		if ( $creds['sid'] === '' || $creds['token'] === '' ) {
			return array(
				'success' => false,
				'code'    => 0,
				'data'    => array(),
				'error'   => __( 'Twilio credentials not configured.', 'orderring-lite' ),
			);
		}

		$method = strtoupper( (string) $method );
		$path   = ltrim( (string) $path, '/' );

		if ( strpos( $path, 'Accounts/' ) === 0 ) {
			$url = 'https://api.twilio.com/2010-04-01/' . $path;
		} else {
			$url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $creds['sid'] ) . '/' . $path;
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth header for the Twilio REST API, not obfuscation.
				'Authorization' => 'Basic ' . base64_encode( $creds['sid'] . ':' . $creds['token'] ),
			),
			'timeout' => 20,
		);

		if ( $method === 'POST' ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$timed_out    = self::is_timeout_error( $response );
			$receive      = self::is_receive_error( $response );
			$request_sent = ( 'POST' === $method ) && ( $timed_out || $receive );
			return array(
				'success'       => false,
				'code'          => 0,
				'data'          => array(),
				'error'         => $response->get_error_message(),
				'network_error' => true,
				'timed_out'     => $timed_out,
				'request_sent'  => $request_sent,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'success' => true,
				'code'    => $code,
				'data'    => $data,
			);
		}

		$msg = ! empty( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code;
		return array(
			'success' => false,
			'code'    => $code,
			'data'    => $data,
			'error'   => $msg,
		);
	}

	/**
	 * POST one plain SMS to Twilio's Messages API (ORLT_SMS_Twilio's transport).
	 *
	 * @param string $to         E.164 recipient.
	 * @param string $body       Final text.
	 * @param string $status_url Delivery-status callback ('' = none).
	 * @return array request() result.
	 */
	public function api_send_message( $to, $body, $status_url = '' ) {
		$creds  = $this->get_credentials();
		$params = array(
			'To'   => (string) $to,
			'From' => $creds['from'],
			'Body' => (string) $body,
		);
		if ( '' !== (string) $status_url ) {
			$params['StatusCallback']       = (string) $status_url;
			$params['StatusCallbackMethod'] = 'POST';
		}
		return $this->request( 'POST', 'Messages.json', $params );
	}

	public function is_configured() {
		$c = $this->get_credentials();
		return $c['sid'] !== '' && $c['token'] !== '' && $c['from'] !== '';
	}

	/**
	 * Replace template merge tags.
	 * {order_number} {order_id} {customer_first_name} {customer_last_name}
	 * {customer_full_name} {store_name} {phone} {order_total} {billing_email}
	 * {tracking} {tracking_url}, plus {site_name} and {contact_*} (ORLT_Merge_Tags).
	 *
	 * @param string        $message Template.
	 * @param WC_Order|null $order   Order for order tags, or null.
	 * @param array         $extra   Extra '{tag}' => value replacements.
	 * @param array         $context More context for registered tags (contact, phone, ...).
	 */
	public function merge_tags( $message, $order = null, $extra = array(), $context = array() ) {
		$message = (string) $message;
		if ( $message === '' ) {
			return $message;
		}

		// Tags come from ORLT_Merge_Tags: core ({site_name}, {store_name}, {contact_*}),
		// the WooCommerce module (order tags) and any integration.
		$context          = is_array( $context ) ? $context : array();
		$context['order'] = $order;
		$replacements     = ORLT_Merge_Tags::values( $context );

		foreach ( (array) $extra as $tag => $value ) {
			$replacements[ strtolower( (string) $tag ) ] = (string) $value;
		}

		// One pass over the template: a value inserted for one tag (customer-typed name,
		// email, tracking number, store name) is never scanned again, so text such as a
		// first name of "{order_number}" stays literal. Tags match case-insensitively.
		return (string) preg_replace_callback(
			'/\{[a-z_]+(?::[a-z0-9_\-]+)?\}/i',
			static function ( $m ) use ( $replacements ) {
				$tag = strtolower( $m[0] );
				return array_key_exists( $tag, $replacements ) ? (string) $replacements[ $tag ] : $m[0];
			},
			$message
		);
	}

	/**
	 * Resolve a tracking number + URL for an order from common sources (order meta).
	 *
	 * @param WC_Order $order Order.
	 * @return array{number:string,url:string}
	 */
	public function tracking_for( $order ) {
		$t = self::tracking_from_meta(
			(string) $order->get_meta( '_ob_tracking_number' ),
			(string) $order->get_meta( '_ob_tracking_url' ),
			$order->get_meta( '_wc_shipment_tracking_items' )
		);

		/**
		 * Filter the resolved tracking number/URL used by the {tracking} / {tracking_url} tags.
		 *
		 * @param array    $t     array{number:string,url:string}.
		 * @param WC_Order $order Order.
		 */
		$t = apply_filters( 'orlt_order_tracking', $t, $order );

		return array(
			'number' => isset( $t['number'] ) ? (string) $t['number'] : '',
			'url'    => isset( $t['url'] ) ? (string) $t['url'] : '',
		);
	}

	/**
	 * Pure precedence resolver for tracking data (unit-testable, no WooCommerce runtime).
	 * OrderBay meta wins; otherwise the first WooCommerce Shipment Tracking item with a number.
	 *
	 * @param string $ob_number OrderBay tracking number.
	 * @param string $ob_url    OrderBay tracking URL.
	 * @param mixed  $wc_items  WooCommerce Shipment Tracking items array (or non-array → ignored).
	 * @return array{number:string,url:string}
	 */
	public static function tracking_from_meta( $ob_number, $ob_url, $wc_items ) {
		$number = trim( (string) $ob_number );
		$url    = trim( (string) $ob_url );

		if ( '' === $number && is_array( $wc_items ) ) {
			foreach ( $wc_items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$n = trim( (string) ( $item['tracking_number'] ?? '' ) );
				if ( '' === $n ) {
					continue;
				}
				$number = $n;
				if ( '' === $url ) {
					$url = trim( (string) ( $item['custom_tracking_link'] ?? ( $item['formatted_tracking_link'] ?? '' ) ) );
				}
				break;
			}
		}

		return array(
			'number' => $number,
			'url'    => $url,
		);
	}

	public function validate_twilio_signature() {
		$creds = $this->get_credentials();
		if ( $creds['token'] === '' ) {
			return false;
		}

		$signature = isset( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ) )
			: '';

		if ( $signature === '' ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$params = wp_unslash( $_POST );
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		foreach ( $this->candidate_signature_urls() as $url ) {
			$data = $url;
			if ( ! empty( $params ) ) {
				ksort( $params );
				foreach ( $params as $key => $value ) {
					if ( is_array( $value ) ) {
						continue;
					}
					$data .= $key . $value;
				}
			}
			$expected = base64_encode( hash_hmac( 'sha1', $data, $creds['token'], true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Twilio X-Twilio-Signature is a base64-encoded HMAC-SHA1; must match Twilio's wire format.
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}

		return false;
	}

	private function candidate_signature_urls() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$urls = array();

		$override = trim( (string) get_option( 'orlt_webhook_base_url', '' ) );
		if ( $override !== '' ) {
			$parsed = wp_parse_url( trailingslashit( $override ) );
			if ( ! empty( $parsed['host'] ) ) {
				$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
				$port   = ! empty( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
				$urls[] = $scheme . '://' . $parsed['host'] . $port . $uri;
			}
		}

		$urls[] = $this->current_request_url();

		$home = wp_parse_url( home_url( '/' ) );
		if ( ! empty( $home['host'] ) ) {
			$scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : 'https';
			$port   = ! empty( $home['port'] ) ? ':' . (int) $home['port'] : '';
			$urls[] = $scheme . '://' . $home['host'] . $port . $uri;
			$urls[] = 'https://' . $home['host'] . $port . $uri;
		}

		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
			$fwd    = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
				? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
				: '';
			$scheme = ( $fwd === 'https' || is_ssl() ) ? 'https' : 'http';
			$urls[] = $scheme . '://' . $host . $uri;
			$urls[] = 'https://' . $host . $uri;
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private function current_request_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
			: '';

		if ( $forwarded === 'https' || is_ssl() ) {
			$scheme = 'https';
		} else {
			$scheme = 'http';
		}

		$home      = wp_parse_url( home_url( '/' ) );
		$home_host = isset( $home['host'] ) ? $home['host'] : '';
		$req_host  = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: $home_host;

		if ( $forwarded === '' && ! empty( $home['scheme'] ) ) {
			$scheme = $home['scheme'];
		}

		$host = $home_host !== '' ? $home_host : $req_host;

		if ( ! empty( $home['port'] ) ) {
			$host_only = preg_replace( '/:\d+$/', '', $host );
			$host      = $host_only . ':' . (int) $home['port'];
		}

		return $scheme . '://' . $host . $uri;
	}

	public function test_credentials() {
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'error' => __( 'Credentials missing. Save Account SID, Auth Token and From Number first.', 'orderring-lite' ) );
		}

		$creds  = $this->get_credentials();
		$result = $this->request( 'GET', 'Accounts/' . rawurlencode( $creds['sid'] ) . '.json' );

		if ( empty( $result['success'] ) ) {
			return array(
				'success' => false,
				/* translators: %s: error detail from Twilio. */
				'error'   => sprintf( __( 'Twilio rejected credentials: %s', 'orderring-lite' ), $result['error'] ?? __( 'unknown', 'orderring-lite' ) ),
			);
		}

		$data = $result['data'];
		if ( empty( $data['sid'] ) ) {
			return array( 'success' => false, 'error' => __( 'Twilio rejected credentials: unexpected response.', 'orderring-lite' ) );
		}

		return array(
			'success'       => true,
			'friendly_name' => isset( $data['friendly_name'] ) ? (string) $data['friendly_name'] : '',
			'status'        => isset( $data['status'] ) ? (string) $data['status'] : '',
		);
	}

	/**
	 * Optional SMS compliance footer appended to outbound bodies.
	 *
	 * @param string $body Message body.
	 * @return string
	 */
	public function append_sms_footer( $body ) {
		if ( ! (int) get_option( 'orlt_sms_footer_enabled', 0 ) ) {
			return $body;
		}
		$footer = trim( (string) get_option( 'orlt_sms_footer_text', 'Reply STOP to opt out. Msg & data rates may apply.' ) );
		if ( $footer === '' ) {
			return $body;
		}
		$body = rtrim( (string) $body );
		if ( stripos( $body, 'STOP' ) !== false && stripos( $body, 'opt out' ) !== false ) {
			return $body;
		}
		return $body . "\n\n" . $footer;
	}

	/**
	 * Which consent a send needs from the consent record (pure; twin of
	 * TOC_Twilio::consent_requirement): '', 'transactional' or 'marketing'.
	 *
	 * @param array $log      send_sms() $args; 'consent' => transactional|marketing.
	 * @param int   $order_id Order ID.
	 * @param bool  $force    Staff force-send.
	 * @param bool  $require  orlt_require_sms_consent.
	 * @return string
	 */
	public static function consent_requirement( $log, $order_id, $force, $require ) {
		$kind = is_array( $log ) && isset( $log['consent'] ) ? (string) $log['consent'] : '';
		if ( 'marketing' === $kind ) {
			return 'marketing';
		}
		if ( 'transactional' === $kind && ! $order_id && ! $force && $require ) {
			return 'transactional';
		}
		return '';
	}

	/**
	 * Send one SMS.
	 *
	 * @param string $to       Recipient.
	 * @param string $body     Text.
	 * @param int    $order_id Order ID (0 = not about an order).
	 * @param bool   $force    Skip the missing-consent check (never the STOP list).
	 * @param array  $args     consent (transactional|marketing), merge (bool), merge_context,
	 *                         merge_extra, context_type, context_id.
	 * @return array
	 */
	public function send_sms( $to, $body, $order_id = 0, $force = false, $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		if ( self::pro_is_licensed() ) {
			return array(
				'success'     => false,
				'skipped_pro' => true,
				'error'       => __( 'OrderRing Pro is licensed and handling SMS. Lite will not send.', 'orderring-lite' ),
			);
		}
		if ( $order_id && class_exists( 'ORLT_Demo' ) && ORLT_Demo::is_demo_order( $order_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'This is an OrderRing Lite demo order. Demo orders are never texted.', 'orderring-lite' ),
			);
		}
		// Every text goes through the selected provider (Twilio unless Vonage or Plivo is
		// chosen). Everything else in this method is provider-agnostic.
		$provider = $this->sms_provider();
		if ( ! $provider->is_configured() ) {
			return array(
				'success'       => false,
				'failure_class' => 'deferred',
				'error'         => 'twilio' === $provider->id()
					? __( 'Twilio credentials not configured.', 'orderring-lite' )
					/* translators: %s: SMS provider name */
					: sprintf( __( '%s credentials not configured.', 'orderring-lite' ), $provider->label() ),
			);
		}

		$to = ORLT_Logger::instance()->normalize_phone( $to );
		if ( empty( $to ) || ! self::is_e164( $to ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The phone number is incomplete or invalid. It needs the country code, for example +15095551234.', 'orderring-lite' ),
			);
		}

		// A number that replied STOP is never texted, even with $force (TCPA / CTIA).
		// $force only bypasses the missing-consent check below; START is the only way back.
		if ( $this->phone_is_opted_out( $to ) ) {
			return array(
				'success'   => false,
				'opted_out' => true,
				'error'     => __( 'Phone number has opted out (STOP).', 'orderring-lite' ),
			);
		}

		if ( ! $force && get_option( 'orlt_require_sms_consent', 1 ) ) {
			if ( $order_id && ! $this->customer_consented_sms( $order_id ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Customer has not consented to SMS.', 'orderring-lite' ),
				);
			}
		}

		// Sends that are not about an order name the consent they need; it is checked
		// here, so no module can skip it. Marketing consent cannot be forced.
		$need = self::consent_requirement( $args, $order_id, $force, (bool) get_option( 'orlt_require_sms_consent', 1 ) );
		if ( '' !== $need && ! ORLT_Consents::instance()->has_consent( $to, $need ) ) {
			return array(
				'success'         => false,
				'consent_missing' => true,
				'error'           => 'marketing' === $need
					? __( 'This number has not agreed to marketing texts.', 'orderring-lite' )
					: __( 'This number has not agreed to texts.', 'orderring-lite' ),
			);
		}

		$order = $order_id ? ORLT_Woo::order( $order_id ) : null;
		$mctx  = isset( $args['merge_context'] ) && is_array( $args['merge_context'] ) ? $args['merge_context'] : array();
		if ( ! isset( $mctx['phone'] ) ) {
			$mctx['phone'] = $to;
		}
		if ( $order ) {
			$body = $this->merge_tags( $body, $order, array(), $mctx );
		} elseif ( ! empty( $args['merge'] ) ) {
			// Callers without an order opt in to merging, so a typed "{...}" is sent as is.
			$body = $this->merge_tags( $body, null, isset( $args['merge_extra'] ) ? (array) $args['merge_extra'] : array(), $mctx );
		}

		$body = $this->append_sms_footer( $body );

		// One transport call. A failed or unknown result is returned as is and never
		// retried through a different provider, so a text that may have been accepted
		// is not sent twice.
		$status_cb = 'twilio' === $provider->id() ? $this->webhook_url( 'orlt_msg_status' ) : ORLT_Webhooks::rest_url( $provider->id() . '/status' );
		$data      = $provider->send( $to, $body, $status_cb );
		if ( empty( $data['success'] ) || empty( $data['sid'] ) ) {
			$data['success'] = false;
			if ( empty( $data['error'] ) ) {
				/* translators: %s: SMS provider name */
				$data['error'] = sprintf( __( 'Unknown %s error', 'orderring-lite' ), $provider->label() );
			}
			return $data;
		}

		ORLT_Logger::instance()->log(
			array(
				'order_id'     => $order_id,
				'phone'        => $to,
				'direction'    => 'outbound',
				'type'         => 'sms',
				'body'         => $body,
				'twilio_sid'   => $data['sid'],
				'status'       => isset( $data['status'] ) ? $data['status'] : 'queued',
				'context_type' => isset( $args['context_type'] ) ? (string) $args['context_type'] : '',
				'context_id'   => isset( $args['context_id'] ) ? (int) $args['context_id'] : 0,
			)
		);
		$out = array(
			'success' => true,
			'sid'     => $data['sid'],
			'status'  => $data['status'] ?? 'queued',
			'body'    => $body,
		);
		if ( 'twilio' !== $provider->id() ) {
			$out['provider'] = $provider->id();
		}
		return $out;
	}

	/**
	 * Provider every text is sent through (Twilio, Vonage or Plivo).
	 *
	 * @return ORLT_SMS_Provider
	 */
	public function sms_provider() {
		return ORLT_SMS_Providers::current();
	}

	/**
	 * Whether texts can be sent with the selected provider's credentials.
	 * (is_configured() keeps meaning "Twilio is configured".)
	 *
	 * @return bool
	 */
	public function sms_is_configured() {
		return $this->sms_provider()->is_configured();
	}

	public function customer_consented_sms( $order_id ) {
		$order = ORLT_Woo::order( $order_id );
		if ( ! $order ) {
			return false;
		}
		return $this->consent_for_order( $order );
	}

	/**
	 * Consent check for an already-hydrated order. Avoids the redundant wc_get_order() of
	 * customer_consented_sms() and, when given a precomputed opted-out last-10 set, skips the
	 * per-row opt-out query (bulk-tab N+1 fix). Behavior is identical to the per-order path.
	 *
	 * @param WC_Order   $order            Hydrated order.
	 * @param array|null $opted_out_last10 Precomputed opted-out last-10 set, or null to query.
	 * @return bool
	 */
	public function consent_for_order( $order, $opted_out_last10 = null ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$phone = ORLT_Logger::instance()->normalize_phone( $order->get_billing_phone() );
		if ( $phone ) {
			$is_out = is_array( $opted_out_last10 )
				? in_array( $phone, $opted_out_last10, true )
				: $this->phone_is_opted_out( $phone );
			if ( $is_out ) {
				return false;
			}
		}

		$meta_key = get_option( 'orlt_sms_consent_meta', '_orlt_sms_consent' );
		if ( $meta_key && $this->is_truthy_consent( $order->get_meta( $meta_key ) ) ) {
			return true;
		}

		$fallbacks = array(
			'_orlt_sms_consent',
			'_sms_consent',
			'sms_opt_in',
			'_wc_sms_consent',
			'billing_sms_consent',
			'sms_consent',
			'_sms_opt_in',
			'orlt_sms_consent',
			'_billing_sms_consent',
			'accept_sms',
			'_accept_sms',
		);
		foreach ( $fallbacks as $key ) {
			if ( $this->is_truthy_consent( $order->get_meta( $key ) ) ) {
				return true;
			}
		}

		// WC additional checkout field meta keys (block): any key containing sms-consent.
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
			if ( $key === '' ) {
				continue;
			}
			if ( false === stripos( $key, 'sms-consent' ) && false === stripos( $key, 'sms_consent' ) ) {
				continue;
			}
			if ( $this->is_truthy_consent( $data['value'] ?? '' ) ) {
				return true;
			}
		}

		$user_id = $order->get_user_id();
		if ( $user_id ) {
			foreach ( array( $meta_key, '_orlt_sms_consent', 'sms_opt_in', '_sms_consent' ) as $key ) {
				if ( $key && $this->is_truthy_consent( get_user_meta( $user_id, $key, true ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Explicit consent state for admin display: yes|no|unknown.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public function get_sms_consent_state( $order_id ) {
		$order = ORLT_Woo::order( $order_id );
		if ( ! $order ) {
			return 'unknown';
		}
		if ( $this->customer_consented_sms( $order_id ) ) {
			return 'yes';
		}
		// Explicit no on canonical keys only.
		$keys = array_filter(
			array(
				get_option( 'orlt_sms_consent_meta', '_orlt_sms_consent' ),
				'_orlt_sms_consent',
			)
		);
		foreach ( $keys as $key ) {
			$val = $order->get_meta( $key );
			if ( $val === '' || $val === null ) {
				continue;
			}
			if ( $this->is_falsy_consent( $val ) ) {
				return 'no';
			}
		}
		return 'unknown';
	}

	/**
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function is_falsy_consent( $value ) {
		if ( is_bool( $value ) ) {
			return false === $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (int) $value === 0;
		}
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return false;
		}
		$v = strtolower( trim( (string) $value ) );
		if ( $v === '' ) {
			return false;
		}
		return in_array( $v, array( 'no', 'n', '0', 'false', 'off', 'unchecked', 'opt-out', 'optout', 'declined' ), true );
	}

	public function set_sms_consent( $order_id, $consented, $phone = '' ) {
		$value    = $consented ? 'yes' : 'no';
		$meta_key = get_option( 'orlt_sms_consent_meta', '_orlt_sms_consent' );
		if ( ! $meta_key ) {
			$meta_key = '_orlt_sms_consent';
		}

		if ( $order_id ) {
			$order = ORLT_Woo::order( $order_id );
			if ( $order ) {
				$order->update_meta_data( $meta_key, $value );
				$order->update_meta_data( '_orlt_sms_consent', $value );
				if ( ! $phone ) {
					$phone = $order->get_billing_phone();
				}
				$user_id = $order->get_user_id();
				if ( $user_id ) {
					update_user_meta( $user_id, $meta_key, $value );
					update_user_meta( $user_id, '_orlt_sms_consent', $value );
				}
				$order->save();
			}
		}

		$norm = ORLT_Logger::instance()->normalize_phone( $phone );
		if ( $norm ) {
			if ( $consented ) {
				$this->remove_opt_out( $norm );
			} else {
				$this->add_opt_out( $norm );
			}
		}
	}

	public function phone_is_opted_out( $phone ) {
		return ORLT_Logger::instance()->phone_is_opted_out( $phone );
	}

	public function add_opt_out( $phone ) {
		ORLT_Logger::instance()->add_opt_out_phone( $phone );
	}

	public function remove_opt_out( $phone ) {
		ORLT_Logger::instance()->remove_opt_out_phone( $phone );
	}

	public function is_truthy_consent( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (int) $value === 1;
		}
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return false;
		}
		$v = strtolower( trim( (string) $value ) );
		return in_array( $v, array( 'yes', 'y', '1', 'true', 'on', 'checked', 'opt-in', 'optin', 'agreed', 'agree' ), true );
	}
}
