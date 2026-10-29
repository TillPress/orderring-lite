<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An SMS carrier for order texts and STOP/HELP/START replies: Twilio (ORLT_SMS_Twilio),
 * Vonage or Plivo.
 *
 * A provider only does transport. Everything around a send (license gate, E.164 check,
 * STOP opt-out, consent, merge tags, footer, logging, retry classification) stays in
 * ORLT_Twilio::send_sms(), so it behaves the same whichever carrier is selected. Voice
 * calls, WhatsApp and the Twilio message-history reconcile stay Twilio-specific; a
 * provider says what it can do through capabilities().
 */
interface ORLT_SMS_Provider {

	/**
	 * @return string Provider key (twilio|vonage|plivo).
	 */
	public function id();

	/**
	 * @return string Human name.
	 */
	public function label();

	/**
	 * What OrderRing can do through this provider today (not everything the provider
	 * sells). Settings and features use it so nothing implies a channel the provider
	 * does not carry here.
	 *
	 * @return array<string,bool> Keys: sms, inbound, delivery_status, mms, voice, whatsapp.
	 */
	public function capabilities();

	/**
	 * Whether outbound sending has the credentials and sender it needs.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Whether inbound webhooks can be verified (a signing secret is set).
	 * Without one, inbound requests are refused rather than trusted.
	 *
	 * @return bool
	 */
	public function inbound_ready();

	/**
	 * Send one SMS. Returns the same transport shape as ORLT_Twilio's request path:
	 * success + sid + status, or success=false with error, http_code, timed_out,
	 * network_error, request_sent and optionally failure_class.
	 *
	 * @param string $to         Recipient, E.164.
	 * @param string $body       Final message text (merged, footer applied).
	 * @param string $status_url Delivery-receipt callback URL ('' = none).
	 * @return array
	 */
	public function send( $to, $body, $status_url = '' );

	/**
	 * Verify a webhook request really came from this provider's account.
	 *
	 * @param array  $params  Request parameters (query, form or JSON), unslashed.
	 * @param array  $headers Request headers, lower-case keys.
	 * @param string $url     The URL the provider was told to call.
	 * @param string $method  GET|POST.
	 * @return bool
	 */
	public function verify_webhook( array $params, array $headers, $url, $method );

	/**
	 * @param array $params Verified inbound-message parameters.
	 * @return array|null array{from:string,body:string,sid:string} or null when not an inbound SMS.
	 */
	public function parse_inbound( array $params );

	/**
	 * @param array $params Verified delivery-receipt parameters.
	 * @return array|null array{sid:string,status:string,error:string}; status uses the log's
	 *                    Twilio-style values (queued|sent|delivered|undelivered|failed).
	 */
	public function parse_status( array $params );
}
