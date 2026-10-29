<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ORLT_Webhooks {

	const REST_NAMESPACE = 'orderring-lite/v1';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'handle_query_aliases' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Preferred REST webhook URL for a channel.
	 *
	 * Uses WordPress rest_url() so plain permalinks (index.php?rest_route=…),
	 * pretty permalinks (/wp-json/…), and subdirectory installs stay correct.
	 * When toc_webhook_base_url is set (reverse proxy), the home_url origin is
	 * swapped for that override while keeping the REST path/query intact.
	 *
	 * @param string $route sms|voice-status|message-status
	 * @return string
	 */
	public static function rest_url( $route ) {
		$route = ltrim( (string) $route, '/' );
		$path  = self::REST_NAMESPACE . '/' . $route;
		// Call the global WP helper (not this method).
		$url = \rest_url( $path );

		$override = trim( (string) get_option( 'orlt_webhook_base_url', '' ) );
		if ( $override === '' ) {
			return $url;
		}

		$home = untrailingslashit( home_url( '/' ) );
		$over = untrailingslashit( $override );
		if ( $home !== '' && strpos( $url, $home ) === 0 ) {
			return $over . substr( $url, strlen( $home ) );
		}

		// Fallback when home_url prefix does not match (odd proxy setups).
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$built = trailingslashit( $over ) . ltrim( $path, '/' );
		if ( $query !== '' ) {
			$built .= '?' . $query;
		}
		return $built;
	}

	/**
	 * Permanent query-string aliases (?orlt_sms=1 etc.) so existing Twilio configs keep working.
	 */
	public function handle_query_aliases() {
		if ( isset( $_GET['orlt_msg_status'] ) && (string) $_GET['orlt_msg_status'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->message_status();
			exit;
		}
		if ( isset( $_GET['orlt_sms'] ) && (string) $_GET['orlt_sms'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->incoming_sms();
			exit;
		}
	}

	public function register_rest_routes() {
		$routes = array(
			'/sms'            => 'rest_incoming_sms',
			'/message-status' => 'rest_message_status',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'verify_twilio_request' ),
				)
			);
		}

		// Vonage / Plivo inbound SMS and delivery receipts. Registered whichever provider
		// is selected, so a STOP that reaches a previously used provider is still honored.
		foreach ( array( 'vonage', 'plivo' ) as $provider_id ) {
			foreach ( array( 'inbound', 'status' ) as $kind ) {
				register_rest_route(
					self::REST_NAMESPACE,
					'/' . $provider_id . '/' . $kind,
					array(
						'methods'             => array( 'GET', 'POST' ),
						'callback'            => function ( $request ) use ( $provider_id, $kind ) {
							return $this->rest_provider_webhook( $request, $provider_id, $kind );
						},
						'permission_callback' => '__return_true', // Authenticated via the provider's signature.
					)
				);
			}
		}
	}

	/**
	 * REST entry for Vonage / Plivo webhooks.
	 *
	 * @param WP_REST_Request $request     Request.
	 * @param string          $provider_id vonage|plivo.
	 * @param string          $kind        inbound|status.
	 * @return WP_REST_Response
	 */
	public function rest_provider_webhook( $request, $provider_id, $kind ) {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_query_params();
		}
		$params = is_array( $params ) ? $params : array();
		// Plain permalinks put the route in the query string; it is ours, not the provider's.
		unset( $params['rest_route'] );

		$headers = array();
		foreach ( (array) $request->get_headers() as $name => $values ) {
			$headers[ str_replace( '_', '-', strtolower( (string) $name ) ) ] = is_array( $values ) ? (string) reset( $values ) : (string) $values;
		}

		$out = $this->handle_provider_webhook(
			$provider_id,
			$kind,
			$params,
			$headers,
			self::rest_url( $provider_id . '/' . $kind ),
			(string) $request->get_method()
		);
		return new WP_REST_Response( $out['body'], $out['code'] );
	}

	/**
	 * Verify and process one Vonage / Plivo webhook. Replies to STOP/HELP/START go back
	 * through the provider's API (these providers have no TwiML-style reply).
	 *
	 * @param string $provider_id vonage|plivo.
	 * @param string $kind        inbound|status.
	 * @param array  $params      Request parameters (unslashed).
	 * @param array  $headers     Headers, lower-case dashed keys.
	 * @param string $url         Webhook URL the provider was given.
	 * @param string $method      GET|POST.
	 * @return array{code:int,body:string}
	 */
	public function handle_provider_webhook( $provider_id, $kind, array $params, array $headers, $url, $method ) {
		$provider = ORLT_SMS_Providers::get( $provider_id );
		if ( ! $provider ) {
			return array(
				'code' => 404,
				'body' => 'Unknown provider',
			);
		}
		if ( ! $provider->inbound_ready() ) {
			return array(
				'code' => 403,
				'body' => 'Webhook signing secret not configured.',
			);
		}
		if ( ! $provider->verify_webhook( $params, $headers, (string) $url, strtoupper( (string) $method ) ) ) {
			return array(
				'code' => 403,
				'body' => 'Invalid signature.',
			);
		}

		if ( 'status' === $kind ) {
			$st = $provider->parse_status( $params );
			if ( $st ) {
				$this->apply_message_status( sanitize_text_field( $st['sid'] ), sanitize_text_field( $st['status'] ), sanitize_text_field( $st['error'] ) );
			}
			return array(
				'code' => 200,
				'body' => 'OK',
			);
		}

		$in = $provider->parse_inbound( $params );
		if ( ! $in ) {
			return array(
				'code' => 400,
				'body' => 'Missing data',
			);
		}
		$from = sanitize_text_field( $in['from'] );
		$body = sanitize_textarea_field( $in['body'] );
		$sid  = sanitize_text_field( $in['sid'] );
		if ( '' === $from || '' === $body ) {
			return array(
				'code' => 400,
				'body' => 'Missing data',
			);
		}

		$res = $this->process_inbound_sms( $from, $body, $sid );
		if ( ! $res['duplicate'] && '' !== $res['reply'] ) {
			$provider->send( $from, $res['reply'], '' );
		}
		return array(
			'code' => 200,
			'body' => 'OK',
		);
	}


	/**
	 * REST permission gate for the Twilio webhooks.
	 *
	 * These endpoints are intentionally public (Twilio calls them unauthenticated
	 * by WP standards) but are authenticated by the Twilio request signature. We
	 * hydrate $_POST from the REST body first so the signature validator can hash
	 * the posted params, then verify the X-Twilio-Signature header. Returning
	 * false yields a 401 before the callback runs; the inner require_valid_twilio()
	 * remains as defense-in-depth for the legacy query-string alias path.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function verify_twilio_request( $request ) {
		$this->hydrate_post_from_rest( $request );
		return ORLT_Twilio::instance()->validate_twilio_signature();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return void Serves raw response for Twilio (not JSON).
	 */
	public function rest_incoming_sms( $request ) {
		$this->hydrate_post_from_rest( $request );
		ob_start();
		$this->incoming_sms();
		$this->serve_raw_and_exit( (string) ob_get_clean(), 'text/xml; charset=utf-8' );
	}

	public function rest_message_status( $request ) {
		$this->hydrate_post_from_rest( $request );
		ob_start();
		$this->message_status();
		$this->serve_raw_and_exit( (string) ob_get_clean(), 'text/plain; charset=utf-8' );
	}

	/**
	 * Bypass WP REST JSON encoding — Twilio expects raw XML/text bodies.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type Content-Type header.
	 */
	private function serve_raw_and_exit( $body, $content_type ) {
		$code = http_response_code();
		if ( ! $code || $code < 100 ) {
			$code = 200;
		}
		status_header( $code );
		header( 'Content-Type: ' . $content_type );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Twilio TwiML / plain OK body.
		echo $body !== '' ? $body : ( strpos( $content_type, 'xml' ) !== false ? '' : 'OK' );
		exit;
	}

	/**
	 * Copy REST params into $_POST so existing handlers / signature validation work.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function hydrate_post_from_rest( $request ) {
		$params = $request->get_body_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}
		if ( ! is_array( $params ) ) {
			return;
		}
		foreach ( $params as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$_POST[ $key ] = $value; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}
	}

	private function require_valid_twilio() {
		if ( ORLT_Twilio::instance()->validate_twilio_signature() ) {
			return true;
		}

		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Invalid Twilio signature.';
		return false;
	}

	private function message_status() {
		if ( ! $this->require_valid_twilio() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Twilio signed webhook, not a WP form.
		$sid = isset( $_POST['MessageSid'] ) ? sanitize_text_field( wp_unslash( $_POST['MessageSid'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Twilio signed webhook, not a WP form.
		$status = isset( $_POST['MessageStatus'] ) ? sanitize_text_field( wp_unslash( $_POST['MessageStatus'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$err = isset( $_POST['ErrorCode'] ) ? sanitize_text_field( wp_unslash( $_POST['ErrorCode'] ) ) : '';
		$this->apply_message_status( $sid, $status, $err );

		status_header( 200 );
		echo 'OK';
	}

	/**
	 * Record a delivery status (any SMS provider) and note failures on the order.
	 *
	 * @param string $sid    Message id.
	 * @param string $status queued|sent|delivered|undelivered|failed.
	 * @param string $err    Provider error code ('' = none).
	 */
	private function apply_message_status( $sid, $status, $err ) {
		if ( $sid && $status ) {
			ORLT_Logger::instance()->update_status_by_sid( $sid, $status );

			if ( in_array( $status, array( 'failed', 'undelivered' ), true ) ) {
				$this->note_order_for_sid(
					$sid,
					sprintf(
						/* translators: 1: message status, 2: Twilio SID, 3: optional error suffix */
						__( 'SMS %1$s (SID: %2$s)%3$s', 'orderring-lite' ),
						$status,
						$sid,
						$err ? ' ' . sprintf(
							/* translators: %s: Twilio error code */
							__( 'Error: %s', 'orderring-lite' ),
							$err
						) : ''
					)
				);
			}
		}
	}

	/**
	 * Attach a delivery note to the order that sent this Twilio SID.
	 *
	 * @param string $sid  Twilio SID.
	 * @param string $note Note text.
	 */
	private function note_order_for_sid( $sid, $note ) {
		$order_id = ORLT_Logger::instance()->get_order_id_by_sid( $sid );
		if ( ! $order_id ) {
			return;
		}
		$order = ORLT_Woo::order( $order_id );
		if ( $order ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Whether an inbound MessageSid was already processed, and claim it if not.
	 *
	 * The transient catches Twilio's fast retries cheaply; a replay arriving after it
	 * expired is still caught by the message log, which stores every inbound SID.
	 *
	 * @param string $sid    Twilio MessageSid ('' = cannot dedupe; never "seen").
	 * @param string $prefix Transient key prefix.
	 * @return bool
	 */
	public static function inbound_sid_seen( $sid, $prefix ) {
		$sid = (string) $sid;
		if ( '' === $sid ) {
			return false;
		}
		$seen_key = $prefix . md5( $sid );
		if ( get_transient( $seen_key ) ) {
			return true;
		}
		set_transient( $seen_key, 1, DAY_IN_SECONDS );
		return ORLT_Logger::instance()->has_sid( $sid );
	}

	private function incoming_sms() {
		if ( ! $this->require_valid_twilio() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$from = isset( $_POST['From'] ) ? sanitize_text_field( wp_unslash( $_POST['From'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$body = isset( $_POST['Body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['Body'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sid = isset( $_POST['MessageSid'] ) ? sanitize_text_field( wp_unslash( $_POST['MessageSid'] ) ) : '';

		if ( empty( $from ) || $body === '' ) {
			status_header( 400 );
			echo 'Missing data';
			return;
		}

		$res = $this->process_inbound_sms( $from, $body, $sid );
		if ( $res['duplicate'] ) {
			header( 'Content-Type: text/xml; charset=utf-8' );
			echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
			return;
		}
		$reply = $res['reply'];

		header( 'Content-Type: text/xml; charset=utf-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '<Response>';
		if ( $reply !== '' ) {
			echo '<Message>' . esc_xml( $reply ) . '</Message>';
		}
		echo '</Response>';
	}

	/**
	 * Shared inbound-SMS path for every provider: dedupe, log, STOP/HELP/START and order
	 * notes. The caller delivers the returned auto-reply (TwiML for Twilio, an API send
	 * for Vonage / Plivo).
	 *
	 * @param string $from E.164 sender.
	 * @param string $body Message text.
	 * @param string $sid  Provider message id ('' = cannot dedupe).
	 * @return array{duplicate:bool,reply:string}
	 */
	public function process_inbound_sms( $from, $body, $sid ) {
		// Idempotency: providers re-deliver a webhook when it doesn't receive a 200 in time.
		// Without a guard, a retry would double-log the message, re-toggle opt-out, and duplicate
		// order notes. Skip re-processing a MessageSid already handled (still return a valid 200).
		if ( self::inbound_sid_seen( $sid, 'orlt_in_sid_' ) ) {
			return array(
				'duplicate' => true,
				'reply'     => '',
			);
		}

		$logger   = ORLT_Logger::instance();
		$twilio   = ORLT_Twilio::instance();
		$order_id = $logger->find_order_by_phone( $from );

		$logger->log(
			array(
				'order_id'      => $order_id,
				'phone'         => $from,
				'direction'     => 'inbound',
				'type'          => 'sms',
				'body'          => $body,
				'twilio_sid'    => $sid,
				'status'        => 'received',
				'admin_user_id' => 0,
			)
		);

		// STOP-type words always opt out. A plain-language request ("please stop texting me")
		// is treated as STOP too, unless the store chose to only flag it.
		$reply   = '';
		$verdict = ORLT_Compliance::classify_inbound( $body );
		$plain   = 'possible_stop' === $verdict;
		if ( $plain && 'auto' === ORLT_Compliance::plain_optout_mode() ) {
			$verdict = 'stop';
		}

		if ( 'stop' === $verdict ) {
			$twilio->set_sms_consent( $order_id, false, $from );
			$reply = get_option(
				'orlt_stop_reply',
				__( 'You have been unsubscribed from SMS messages. Reply START to re-subscribe. Msg&data rates may apply.', 'orderring-lite' )
			);
			if ( $order_id ) {
				$order = ORLT_Woo::order( $order_id );
				if ( $order ) {
					$order->add_order_note(
						$plain
							/* translators: %s: customer's message, shortened */
							? sprintf( __( 'Customer opted out of SMS with a plain-language reply: "%s".', 'orderring-lite' ), wp_trim_words( $body, 20 ) )
							: __( 'Customer opted out of SMS (STOP).', 'orderring-lite' )
					);
				}
			}
		} elseif ( 'help' === $verdict ) {
			$store = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$saved = get_option( 'orlt_help_reply', '' );
			$reply = ( is_string( $saved ) && $saved !== '' )
				? $saved
				: sprintf(
					/* translators: %s: store name */
					__( '%s: For help, reply to this number or contact the store. Reply STOP to opt out of SMS.', 'orderring-lite' ),
					$store
				);
		} elseif ( 'start' === $verdict ) {
			$twilio->remove_opt_out( $from );
			if ( $order_id ) {
				$twilio->set_sms_consent( $order_id, true, $from );
			}
			$reply = get_option(
				'orlt_start_reply',
				__( 'You have been re-subscribed to SMS messages. Reply STOP to opt out.', 'orderring-lite' )
			);
			if ( $order_id ) {
				$order = ORLT_Woo::order( $order_id );
				if ( $order ) {
					$order->add_order_note( __( 'Customer re-subscribed to SMS (START).', 'orderring-lite' ) );
				}
			}
		} else {
			// phpcs:ignore Universal.ControlStructures.DisallowLonelyIf.Found -- Kept structurally identical to OrderRing Pro's toc-webhooks for the Lite<->Pro source-parity test.
			if ( $order_id ) {
				$order = ORLT_Woo::order( $order_id );
				if ( $order ) {
					$order->add_order_note(
						$plain
							? sprintf(
								/* translators: %s: trimmed inbound SMS body */
								__( 'Possible SMS opt-out: "%s". The customer may be asking to stop texts; if so, do not text them again until they reply START.', 'orderring-lite' ),
								wp_trim_words( $body, 20 )
							)
							: sprintf(
								/* translators: %s: trimmed inbound SMS body */
								__( 'Incoming SMS: "%s"', 'orderring-lite' ),
								wp_trim_words( $body, 20 )
							)
					);
				}
			}
		}

		// Log auto keyword reply so staff see it in order chat (task 11).
		if ( $reply !== '' ) {
			$logger->log(
				array(
					'order_id'      => $order_id,
					'phone'         => $from,
					'direction'     => 'outbound',
					'type'          => 'sms',
					'body'          => $reply,
					'twilio_sid'    => '',
					'status'        => 'auto-reply',
					'admin_user_id' => 0,
				)
			);
		}

		return array(
			'duplicate' => false,
			'reply'     => (string) $reply,
		);
	}
}
