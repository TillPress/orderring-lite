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

		if ( $sid && $status ) {
			ORLT_Logger::instance()->update_status_by_sid( $sid, $status );

			if ( in_array( $status, array( 'failed', 'undelivered' ), true ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$err = isset( $_POST['ErrorCode'] ) ? sanitize_text_field( wp_unslash( $_POST['ErrorCode'] ) ) : '';
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

		status_header( 200 );
		echo 'OK';
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
		$order = wc_get_order( $order_id );
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

		// Idempotency: Twilio re-delivers a webhook when it doesn't receive a 200 in time.
		// Without a guard, a retry would double-log the message, re-toggle opt-out, and duplicate
		// order notes. Skip re-processing a MessageSid already handled (still return a valid 200).
		if ( self::inbound_sid_seen( $sid, 'orlt_in_sid_' ) ) {
			header( 'Content-Type: text/xml; charset=utf-8' );
			echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
			return;
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

		$reply   = '';
		$norm    = preg_replace( '/\s+/', ' ', strtoupper( trim( $body ) ) );
		$keyword = preg_replace( '/[^A-Z]/', '', $norm );

		$stop_words = array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' );
		$help_words = array( 'HELP', 'INFO' );
		// START / UNSTOP only — do not treat bare YES as re-subscribe (too easy to match casual replies).
		$start_words = array( 'START', 'UNSTOP' );

		if ( in_array( $keyword, $stop_words, true ) ) {
			$twilio->set_sms_consent( $order_id, false, $from );
			$reply = get_option(
				'orlt_stop_reply',
				__( 'You have been unsubscribed from SMS messages. Reply START to re-subscribe. Msg&data rates may apply.', 'orderring-lite' )
			);
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note( __( 'Customer opted out of SMS (STOP).', 'orderring-lite' ) );
				}
			}
		} elseif ( in_array( $keyword, $help_words, true ) ) {
			$store = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$saved = get_option( 'orlt_help_reply', '' );
			$reply = ( is_string( $saved ) && $saved !== '' )
				? $saved
				: sprintf(
					/* translators: %s: store name */
					__( '%s: For help, reply to this number or contact the store. Reply STOP to opt out of SMS.', 'orderring-lite' ),
					$store
				);
		} elseif ( in_array( $keyword, $start_words, true ) ) {
			$twilio->remove_opt_out( $from );
			if ( $order_id ) {
				$twilio->set_sms_consent( $order_id, true, $from );
			}
			$reply = get_option(
				'orlt_start_reply',
				__( 'You have been re-subscribed to SMS messages. Reply STOP to opt out.', 'orderring-lite' )
			);
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note( __( 'Customer re-subscribed to SMS (START).', 'orderring-lite' ) );
				}
			}
		} else {
			// phpcs:ignore Universal.ControlStructures.DisallowLonelyIf.Found -- Kept structurally identical to OrderRing Pro's toc-webhooks for the Lite<->Pro source-parity test.
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note(
						sprintf(
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

		header( 'Content-Type: text/xml; charset=utf-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '<Response>';
		if ( $reply !== '' ) {
			echo '<Message>' . esc_xml( $reply ) . '</Message>';
		}
		echo '</Response>';
	}
}
