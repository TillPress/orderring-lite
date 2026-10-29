<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger engine (Lite): "when [event], send [message] to [admin number | the person]".
 *
 * The same engine as OrderRing Pro (TOC_Triggers), limited to texts to the admin number,
 * plus texts to the person who sent a Contact Form 7 form when they ticked the consent
 * checkbox mapped in the rule (recorded as source form, detail cf7:<form id>). Every
 * text goes through ORLT_Twilio::send_sms(), so the STOP list, consent and the selected
 * provider apply. Quiet hours hold a text until they end (store clock), and events a
 * visitor can cause are rate limited per visitor IP and per number.
 *
 * Event hooks are attached only for events that have an enabled rule.
 * tests/run.php keeps the shared methods identical to Pro.
 */
final class ORLT_Triggers {

	const OPTION          = 'orlt_triggers';
	const OPT_ADMIN_PHONE = 'orlt_trigger_admin_phone';
	const OPT_RATE_IP     = 'orlt_trigger_rate_ip';
	const OPT_RATE_NUMBER = 'orlt_trigger_rate_number';
	const OPT_RATE_ADMIN  = 'orlt_trigger_rate_admin';
	const OPT_RECENT      = 'orlt_trigger_recent';
	const DEFERRED_HOOK   = 'orlt_trigger_deferred';
	const SAVE_ACTION     = 'orlt_save_triggers';
	const PARENT          = 'orderring-lite';
	const PAGE            = 'orderring-lite-triggers';
	const RATE_PREFIX     = 'orlt_trl_';
	const MAX_RULES       = 50;
	const MAX_TEMPLATE    = 640;
	const RECENT_KEEP     = 20;
	const WINDOW          = 3600;

	/** Defaults per hour: events from one visitor IP, texts to one number, texts to the admin number. */
	const DEFAULT_RATE_IP     = 5;
	const DEFAULT_RATE_NUMBER = 3;
	const DEFAULT_RATE_ADMIN  = 30;

	/**
	 * @var ORLT_Triggers|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
			add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		}
		$this->attach( self::rules() );
	}

	/* ---------- events and rules ---------- */

	/**
	 * Events a rule can start from.
	 *
	 * hook/args/handler: the WordPress action. public: a visitor can cause it, so it is
	 * rate limited. person: the event names a person who can be texted.
	 *
	 * @return array<string,array>
	 */
	public static function events() {
		$user_tags = array(
			'{user_login}'        => __( 'Username', 'orderring-lite' ),
			'{user_email}'        => __( 'Email address', 'orderring-lite' ),
			'{user_display_name}' => __( 'Display name', 'orderring-lite' ),
		);
		return array(
			'user_register'  => array(
				'label'   => __( 'Someone creates an account', 'orderring-lite' ),
				'hook'    => 'user_register',
				'args'    => 1,
				'handler' => 'on_user_register',
				'public'  => true,
				'person'  => true,
				'tags'    => $user_tags,
			),
			'password_reset' => array(
				'label'   => __( 'Someone resets their password', 'orderring-lite' ),
				'hook'    => 'after_password_reset',
				'args'    => 1,
				'handler' => 'on_password_reset',
				'public'  => true,
				'person'  => true,
				'tags'    => $user_tags,
			),
			'comment_post'   => array(
				'label'   => __( 'A new comment is posted', 'orderring-lite' ),
				'hook'    => 'comment_post',
				'args'    => 3,
				'handler' => 'on_comment_post',
				'public'  => true,
				'person'  => false,
				'tags'    => array(
					'{comment_author}'  => __( 'Comment author', 'orderring-lite' ),
					'{comment_excerpt}' => __( 'Start of the comment', 'orderring-lite' ),
					'{comment_status}'  => __( 'approved or pending', 'orderring-lite' ),
					'{post_title}'      => __( 'Post title', 'orderring-lite' ),
				),
			),
			'cf7_submit'     => array(
				'label'   => __( 'A Contact Form 7 form is sent', 'orderring-lite' ),
				'hook'    => 'wpcf7_submit',
				'args'    => 2,
				'handler' => 'on_cf7_submit',
				'public'  => true,
				'person'  => true,
				'tags'    => array(
					'{form_title}'      => __( 'Form title', 'orderring-lite' ),
					'{form_id}'         => __( 'Form ID', 'orderring-lite' ),
					'{field:your-name}' => __( 'Any form field by its name, e.g. {field:your-name}', 'orderring-lite' ),
				),
			),
		);
	}

	/**
	 * Who a rule for this event may text. Lite: the admin number for every event, and
	 * the person for Contact Form 7 (with their ticked consent).
	 *
	 * @param string $event Event id.
	 * @return string[]
	 */
	public static function recipients_for( $event ) {
		$events = self::events();
		if ( ! isset( $events[ $event ] ) ) {
			return array();
		}
		return 'cf7_submit' === $event ? array( 'admin', 'person' ) : array( 'admin' );
	}

	/**
	 * @param string $key admin|person.
	 * @return string
	 */
	public static function recipient_label( $key ) {
		$labels = array(
			'admin'  => __( 'The admin number', 'orderring-lite' ),
			'person' => __( 'The person who sent the form (needs their ticked consent)', 'orderring-lite' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Every recipient this plugin offers, for the settings screen.
	 *
	 * @return string[]
	 */
	public static function all_recipients() {
		$out = array();
		foreach ( array_keys( self::events() ) as $event ) {
			foreach ( self::recipients_for( $event ) as $who ) {
				if ( ! in_array( $who, $out, true ) ) {
					$out[] = $who;
				}
			}
		}
		return $out;
	}

	/**
	 * Saved rules (already sanitized on save; re-shaped here so a hand-edited option
	 * cannot break a request).
	 *
	 * @return array[]
	 */
	public static function rules() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $rule ) {
			if ( is_array( $rule ) && ! empty( $rule['id'] ) && ! empty( $rule['event'] ) ) {
				$out[] = array_merge( self::blank_rule(), $rule );
			}
		}
		return $out;
	}

	/**
	 * @return array
	 */
	public static function blank_rule() {
		return array(
			'id'            => '',
			'enabled'       => false,
			'event'         => '',
			'recipient'     => 'admin',
			'custom_phone'  => '',
			'form_id'       => 0,
			'phone_field'   => '',
			'consent_field' => '',
			'template'      => '',
		);
	}

	/**
	 * @param string $id Rule id.
	 * @return array|null
	 */
	public static function find_rule( $id ) {
		foreach ( self::rules() as $rule ) {
			if ( $rule['id'] === (string) $id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Attach the WordPress hooks of events that have an enabled rule.
	 *
	 * @param array[] $rules Rules.
	 * @return string[] Events attached.
	 */
	public function attach( array $rules ) {
		$events = self::events();
		$on     = array();
		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['enabled'] ) && isset( $events[ $rule['event'] ] ) && ! in_array( $rule['event'], $on, true ) ) {
				$on[] = $rule['event'];
			}
		}
		foreach ( $on as $event ) {
			$def = $events[ $event ];
			add_action( $def['hook'], array( $this, $def['handler'] ), 20, $def['args'] );
		}
		if ( $on ) {
			add_action( self::DEFERRED_HOOK, array( $this, 'run_deferred' ), 10, 1 );
		}
		return $on;
	}

	/* ---------- event handlers ---------- */

	/**
	 * @param int $user_id New user.
	 * @return array[] Outcomes.
	 */
	public function on_user_register( $user_id ) {
		return $this->fire( 'user_register', self::user_payload( $user_id ) );
	}

	/**
	 * @param object $user WP_User.
	 * @return array[] Outcomes.
	 */
	public function on_password_reset( $user ) {
		return $this->fire( 'password_reset', self::user_payload( is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0 ) );
	}

	/**
	 * @param int        $comment_id Comment.
	 * @param int|string $approved   1, 0, 'spam' or 'trash'.
	 * @param array      $data       Comment data.
	 * @return array[] Outcomes.
	 */
	public function on_comment_post( $comment_id, $approved = 0, $data = array() ) {
		if ( 'spam' === $approved || 'trash' === $approved ) {
			return array();
		}
		$comment = function_exists( 'get_comment' ) ? get_comment( $comment_id ) : null;
		$data    = is_array( $data ) ? $data : array();
		$author  = $comment && isset( $comment->comment_author ) ? (string) $comment->comment_author : (string) ( $data['comment_author'] ?? '' );
		$content = $comment && isset( $comment->comment_content ) ? (string) $comment->comment_content : (string) ( $data['comment_content'] ?? '' );
		$post_id = $comment && isset( $comment->comment_post_ID ) ? (int) $comment->comment_post_ID : (int) ( $data['comment_post_ID'] ?? 0 );
		return $this->fire(
			'comment_post',
			array(
				'tags'         => array(
					'{comment_author}'  => self::clean_value( $author, 60 ),
					'{comment_excerpt}' => self::clean_value( $content, 100 ),
					'{comment_status}'  => 1 === (int) $approved ? 'approved' : 'pending',
					'{post_title}'      => self::clean_value( $post_id && function_exists( 'get_the_title' ) ? get_the_title( $post_id ) : '', 80 ),
				),
				'context_type' => 'comment',
				'context_id'   => (int) $comment_id,
			)
		);
	}

	/**
	 * Contact Form 7 sends wpcf7_submit for every attempt; only submissions that passed
	 * validation and the spam check (mail sent, or mail failed on the server) count.
	 *
	 * @param object $form   WPCF7_ContactForm.
	 * @param array  $result Submission result.
	 * @return array[] Outcomes.
	 */
	public function on_cf7_submit( $form, $result = array() ) {
		$status = is_array( $result ) && isset( $result['status'] ) ? (string) $result['status'] : '';
		if ( ! in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) || ! class_exists( 'WPCF7_Submission' ) ) {
			return array();
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return array();
		}
		$fields  = (array) $submission->get_posted_data();
		$form_id = is_object( $form ) && is_callable( array( $form, 'id' ) ) ? (int) $form->id() : 0;
		$title   = is_object( $form ) && is_callable( array( $form, 'title' ) ) ? (string) $form->title() : '';
		return $this->fire( 'cf7_submit', self::cf7_payload( $form_id, $title, $fields ) );
	}

	/**
	 * @param int    $form_id Form.
	 * @param string $title   Form title.
	 * @param array  $fields  Posted fields.
	 * @return array
	 */
	public static function cf7_payload( $form_id, $title, array $fields ) {
		return array(
			'tags'         => array_merge(
				array(
					'{form_title}' => self::clean_value( $title, 80 ),
					'{form_id}'    => (string) (int) $form_id,
				),
				self::field_tags( $fields )
			),
			'fields'       => $fields,
			'form_id'      => (int) $form_id,
			'context_type' => 'form',
			'context_id'   => (int) $form_id,
		);
	}

	/**
	 * @param int $user_id User.
	 * @return array
	 */
	public static function user_payload( $user_id ) {
		$user  = $user_id && function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		$first = $user_id ? (string) get_user_meta( $user_id, 'first_name', true ) : '';
		return array(
			'tags'          => array(
				'{user_login}'        => $user ? self::clean_value( $user->user_login, 60 ) : '',
				'{user_email}'        => $user ? self::clean_value( $user->user_email, 100 ) : '',
				'{user_display_name}' => $user ? self::clean_value( $user->display_name, 60 ) : '',
			),
			'person_phone'  => self::user_phone( $user_id ),
			'merge_context' => '' !== $first ? array( 'first_name' => $first ) : array(),
			'context_type'  => 'user',
			'context_id'    => (int) $user_id,
		);
	}

	/**
	 * A user's phone number from their profile (billing phone first).
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function user_phone( $user_id ) {
		$phone = '';
		if ( $user_id ) {
			foreach ( array( 'billing_phone', 'phone', 'mobile' ) as $key ) {
				$phone = trim( (string) get_user_meta( $user_id, $key, true ) );
				if ( '' !== $phone ) {
					break;
				}
			}
		}
		/**
		 * Filter the phone number an account event texts.
		 *
		 * @param string $phone   Phone from the profile ('' when none).
		 * @param int    $user_id User.
		 */
		return (string) apply_filters( 'orlt_trigger_user_phone', $phone, (int) $user_id );
	}

	/* ---------- the engine ---------- */

	/**
	 * Run every enabled rule for an event.
	 *
	 * @param string $event   Event id.
	 * @param array  $payload tags, person_phone, fields, form_id, merge_context, context_type, context_id.
	 * @param int    $now     UTC epoch (tests).
	 * @return array[] One outcome per matching rule: rule, outcome, to.
	 */
	public function fire( $event, array $payload, $now = null ) {
		$events = self::events();
		if ( ! isset( $events[ $event ] ) ) {
			return array();
		}
		$now   = $now ? (int) $now : time();
		$rules = self::matching_rules( self::rules(), $event, isset( $payload['form_id'] ) ? (int) $payload['form_id'] : 0 );
		if ( ! $rules ) {
			return array();
		}
		$public = ! empty( $events[ $event ]['public'] );
		// One visitor action (a form sent, an account made) counts once against the
		// visitor's limit, however many rules it starts; refused attempts count too.
		$ip_ok = true;
		$ip    = $public ? self::client_ip() : '';
		if ( '' !== $ip ) {
			$ip_ok = self::rate_allows( get_transient( self::rate_key( 'ip:' . $ip ) ), self::rate_option( self::OPT_RATE_IP, self::DEFAULT_RATE_IP ), $now );
			if ( $ip_ok ) {
				self::rate_hit( 'ip:' . $ip, $now );
			}
		}
		$outcomes = array();
		foreach ( $rules as $rule ) {
			$outcome    = $ip_ok ? $this->run_rule( $rule, $payload, $public, $now ) : array(
				'rule'    => $rule['id'],
				'outcome' => 'rate_limited_ip',
				'to'      => '',
			);
			$outcomes[] = $outcome;
			self::note( $rule, $outcome, $now );
		}
		return $outcomes;
	}

	/**
	 * Enabled rules for an event; a form rule with a form id only matches that form.
	 *
	 * @param array[] $rules   Rules.
	 * @param string  $event   Event id.
	 * @param int     $form_id Form that was sent (0 when not a form).
	 * @return array[]
	 */
	public static function matching_rules( array $rules, $event, $form_id = 0 ) {
		$out = array();
		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) || $rule['event'] !== $event || '' === trim( (string) $rule['template'] ) ) {
				continue;
			}
			if ( ! empty( $rule['form_id'] ) && (int) $rule['form_id'] !== (int) $form_id ) {
				continue;
			}
			$out[] = $rule;
		}
		return $out;
	}

	/**
	 * @param array $rule    Rule.
	 * @param array $payload Event payload.
	 * @param bool  $public  Rate limited per number.
	 * @param int   $now     UTC epoch.
	 * @return array rule, outcome, to.
	 */
	private function run_rule( array $rule, array $payload, $public, $now ) {
		$who = (string) $rule['recipient'];
		$out = array(
			'rule'    => $rule['id'],
			'outcome' => '',
			'to'      => '',
		);
		if ( ! in_array( $who, self::recipients_for( $rule['event'] ), true ) ) {
			$out['outcome'] = 'recipient_not_allowed';
			return $out;
		}
		$person = 'person' === $who ? self::person_for( $rule, $payload ) : null;
		if ( $person && ! $person['consent'] ) {
			$out['outcome'] = 'no_consent';
			return $out;
		}
		$to        = self::normalize( 'admin' === $who ? self::admin_phone() : ( 'custom' === $who ? $rule['custom_phone'] : ( $person ? $person['phone'] : '' ) ) );
		$out['to'] = $to;
		if ( '' === $to ) {
			$out['outcome'] = 'no_number';
			return $out;
		}

		if ( $public ) {
			$bucket = 'to:' . $to;
			$limit  = 'admin' === $who ? self::rate_option( self::OPT_RATE_ADMIN, self::DEFAULT_RATE_ADMIN ) : self::rate_option( self::OPT_RATE_NUMBER, self::DEFAULT_RATE_NUMBER );
			if ( ! self::rate_allows( get_transient( self::rate_key( $bucket ) ), $limit, $now ) ) {
				$out['outcome'] = 'rate_limited_number';
				return $out;
			}
			self::rate_hit( $bucket, $now );
		}

		// Consent from a form is recorded once the text is going to be sent.
		if ( $person && ! empty( $person['record'] ) ) {
			$this->record_consent( $to, $person['record'] );
		}

		$args = self::send_args( $rule, $payload, 'person' === $who );
		$at   = $this->quiet_until( $now );
		if ( $at > $now ) {
			wp_schedule_single_event(
				$at,
				self::DEFERRED_HOOK,
				array(
					array(
						'rule' => $rule['id'],
						'to'   => $to,
						'args' => $args,
					),
				)
			);
			$out['outcome'] = 'deferred';
			$out['at']      = $at;
			return $out;
		}
		$sent           = $this->deliver( $to, (string) $rule['template'], $args );
		$out['outcome'] = ! empty( $sent['success'] ) ? 'sent' : 'failed';
		$out['result']  = $sent;
		return $out;
	}

	/**
	 * Who "the person" is for a rule, and whether they agreed to texts.
	 *
	 * @param array $rule    Rule.
	 * @param array $payload Event payload.
	 * @return array phone, consent (bool), record ('' or the consent detail to store).
	 */
	public static function person_for( array $rule, array $payload ) {
		if ( 'cf7_submit' === $rule['event'] ) {
			$fields  = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
			$phone   = '' !== $rule['phone_field'] && isset( $fields[ $rule['phone_field'] ] ) ? $fields[ $rule['phone_field'] ] : '';
			$phone   = is_array( $phone ) ? (string) reset( $phone ) : (string) $phone;
			$ticked  = '' !== $rule['consent_field'] && isset( $fields[ $rule['consent_field'] ] ) && self::consent_ticked( $fields[ $rule['consent_field'] ] );
			$form_id = isset( $payload['form_id'] ) ? (int) $payload['form_id'] : 0;
			return array(
				'phone'   => $phone,
				'consent' => $ticked,
				'record'  => $ticked ? 'cf7:' . $form_id : '',
			);
		}
		// Account events: consent must already be on the contact record; send_sms()
		// checks it (consent => transactional).
		return array(
			'phone'   => isset( $payload['person_phone'] ) ? (string) $payload['person_phone'] : '',
			'consent' => true,
			'record'  => '',
		);
	}

	/**
	 * Whether a posted consent field counts as ticked (acceptance "1", a checked checkbox).
	 *
	 * @param mixed $value Posted value.
	 * @return bool
	 */
	public static function consent_ticked( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( self::consent_ticked( $v ) ) {
					return true;
				}
			}
			return false;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		return ! in_array( strtolower( trim( (string) $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * send_sms() arguments for a trigger text.
	 *
	 * @param array $rule    Rule.
	 * @param array $payload Event payload.
	 * @param bool  $person  The text goes to the person.
	 * @return array
	 */
	public static function send_args( array $rule, array $payload, $person ) {
		$tags = isset( $payload['tags'] ) && is_array( $payload['tags'] ) ? $payload['tags'] : array();
		// A {field:x} the form did not send becomes empty rather than staying in the text.
		if ( preg_match_all( '/\{field:[a-z0-9_\-]+\}/i', (string) $rule['template'], $m ) ) {
			foreach ( $m[0] as $tag ) {
				$tag = strtolower( $tag );
				if ( ! isset( $tags[ $tag ] ) ) {
					$tags[ $tag ] = '';
				}
			}
		}
		$args = array(
			'merge'         => true,
			'merge_extra'   => $tags,
			'merge_context' => isset( $payload['merge_context'] ) && is_array( $payload['merge_context'] ) ? $payload['merge_context'] : array(),
			'context_type'  => isset( $payload['context_type'] ) ? (string) $payload['context_type'] : '',
			'context_id'    => isset( $payload['context_id'] ) ? (int) $payload['context_id'] : 0,
			'link_kind'     => 'none',
		);
		if ( $person ) {
			$args['consent'] = 'transactional';
		}
		return $args;
	}

	/**
	 * Cron: send a text that quiet hours held back. The rule must still exist and be on.
	 *
	 * @param array $job rule, to, args.
	 * @return array|null send_sms() result, or null when dropped.
	 */
	public function run_deferred( $job ) {
		if ( ! is_array( $job ) || empty( $job['rule'] ) || empty( $job['to'] ) ) {
			return null;
		}
		$rule = self::find_rule( $job['rule'] );
		if ( ! $rule || empty( $rule['enabled'] ) || ! in_array( $rule['recipient'], self::recipients_for( $rule['event'] ), true ) ) {
			return null;
		}
		$sent = $this->deliver( (string) $job['to'], (string) $rule['template'], isset( $job['args'] ) && is_array( $job['args'] ) ? $job['args'] : array() );
		self::note(
			$rule,
			array(
				'rule'    => $rule['id'],
				'outcome' => ! empty( $sent['success'] ) ? 'sent' : 'failed',
				'to'      => (string) $job['to'],
			),
			time()
		);
		return $sent;
	}

	/**
	 * Hand one text to the send pipeline.
	 *
	 * @param string $to   E.164 number.
	 * @param string $body Template.
	 * @param array  $args send_sms() $args.
	 * @return array
	 */
	public function deliver( $to, $body, array $args ) {
		/**
		 * Short-circuit a trigger text (tests). Return an array result to skip the send.
		 *
		 * @param null   $pre  Null to send normally.
		 * @param string $to   Recipient.
		 * @param string $body Template.
		 * @param array  $args send_sms() $args.
		 */
		$pre = apply_filters( 'orlt_trigger_pre_send', null, $to, $body, $args );
		if ( is_array( $pre ) ) {
			return $pre;
		}
		return ORLT_Twilio::instance()->send_sms( $to, $body, 0, false, $args );
	}

	/**
	 * @param string $phone  E.164 number.
	 * @param string $detail Where the consent came from, e.g. cf7:42.
	 */
	private function record_consent( $phone, $detail ) {
		if ( ORLT_Consents::schema_ready() ) {
			ORLT_Consents::instance()->record_consent( $phone, ORLT_Consents::KIND_TRANSACTIONAL, 'form', $detail );
		}
	}

	/**
	 * The admin number.
	 *
	 * @return string
	 */
	public static function admin_phone() {
		return trim( (string) get_option( self::OPT_ADMIN_PHONE, '' ) );
	}

	/**
	 * @return array[] Quiet windows (empty when quiet hours are off).
	 */
	public static function quiet_windows() {
		return ORLT_Auto::quiet_windows();
	}

	/**
	 * When a trigger text may go out: now, or when quiet hours end on the store's clock.
	 *
	 * @param int $now UTC epoch.
	 * @return int
	 */
	public function quiet_until( $now ) {
		$windows = self::quiet_windows();
		if ( ! $windows ) {
			return (int) $now;
		}
		return ORLT_Compliance::next_open_at( (int) $now, $windows, array( wp_timezone()->getName() ) );
	}

	/* ---------- rate limits ---------- */

	/**
	 * Whether one more text fits in a fixed hourly window. Pure.
	 *
	 * @param mixed $state Stored array( window start, count ) or false.
	 * @param int   $limit Texts per window; 0 = no limit.
	 * @param int   $now   UTC epoch.
	 * @return bool
	 */
	public static function rate_allows( $state, $limit, $now ) {
		$limit = (int) $limit;
		if ( $limit <= 0 || ! is_array( $state ) || count( $state ) < 2 || (int) $now - (int) $state[0] >= self::WINDOW ) {
			return true;
		}
		return (int) $state[1] < $limit;
	}

	/**
	 * State after counting one more text. Pure.
	 *
	 * @param mixed $state Stored state or false.
	 * @param int   $now   UTC epoch.
	 * @return array
	 */
	public static function rate_next( $state, $now ) {
		if ( ! is_array( $state ) || count( $state ) < 2 || (int) $now - (int) $state[0] >= self::WINDOW ) {
			return array( (int) $now, 1 );
		}
		return array( (int) $state[0], (int) $state[1] + 1 );
	}

	/**
	 * @param string $bucket ip:… or to:….
	 * @param int    $now    UTC epoch.
	 */
	private static function rate_hit( $bucket, $now ) {
		$key = self::rate_key( $bucket );
		set_transient( $key, self::rate_next( get_transient( $key ), $now ), self::WINDOW );
	}

	/**
	 * @param string $bucket Bucket.
	 * @return string
	 */
	public static function rate_key( $bucket ) {
		return self::RATE_PREFIX . md5( (string) $bucket );
	}

	/**
	 * @param string $option  Option.
	 * @param int    $default Default.
	 * @return int
	 */
	public static function rate_option( $option, $default ) {
		$value = get_option( $option, $default );
		return is_numeric( $value ) ? max( 0, min( 1000, (int) $value ) ) : (int) $default;
	}

	/**
	 * The visitor's IP. Only REMOTE_ADDR: forwarded headers are easy to fake. A site
	 * behind a proxy can pass the real address through the filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated just below.
		/**
		 * Filter the visitor IP the trigger rate limits count against.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'orlt_trigger_client_ip', $ip );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/* ---------- helpers ---------- */

	/**
	 * {field:name} tags for posted form fields. Pure.
	 *
	 * @param array $fields Posted fields.
	 * @return array<string,string>
	 */
	public static function field_tags( array $fields ) {
		$out = array();
		foreach ( $fields as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( ! preg_match( '/^[a-z0-9_\-]{1,64}$/', $name ) || 0 === strpos( $name, '_wpcf7' ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
			}
			$out[ '{field:' . $name . '}' ] = self::clean_value( is_scalar( $value ) ? (string) $value : '', 200 );
		}
		return $out;
	}

	/**
	 * Plain, single-line, length-capped text for a merge tag. Pure.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max characters.
	 * @return string
	 */
	public static function clean_value( $value, $max ) {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $max ) {
			return rtrim( mb_substr( $value, 0, $max - 3 ) ) . '...';
		}
		if ( strlen( $value ) > $max ) {
			return rtrim( substr( $value, 0, $max - 3 ) ) . '...';
		}
		return $value;
	}

	/**
	 * @param string $phone Any format.
	 * @return string E.164 or ''.
	 */
	public static function normalize( $phone ) {
		$norm = ORLT_Logger::instance()->normalize_phone( (string) $phone );
		return ORLT_Twilio::is_e164( $norm ) ? $norm : '';
	}

	/**
	 * Keep the last few outcomes for the settings screen (number masked).
	 *
	 * @param array $rule    Rule.
	 * @param array $outcome Outcome.
	 * @param int   $now     UTC epoch.
	 */
	private static function note( array $rule, array $outcome, $now ) {
		$recent = get_option( self::OPT_RECENT, array() );
		$recent = is_array( $recent ) ? $recent : array();
		$to     = isset( $outcome['to'] ) ? (string) $outcome['to'] : '';
		array_unshift(
			$recent,
			array(
				'at'      => (int) $now,
				'rule'    => (string) $rule['id'],
				'event'   => (string) $rule['event'],
				'outcome' => (string) $outcome['outcome'],
				'to'      => '' !== $to ? '…' . substr( $to, -4 ) : '',
			)
		);
		update_option( self::OPT_RECENT, array_slice( $recent, 0, self::RECENT_KEEP ), false );
	}

	/**
	 * @param string $outcome Outcome key.
	 * @return string
	 */
	public static function outcome_label( $outcome ) {
		$labels = array(
			'sent'                  => __( 'Sent', 'orderring-lite' ),
			'failed'                => __( 'Not sent (see Messages or the error log)', 'orderring-lite' ),
			'deferred'              => __( 'Held for quiet hours', 'orderring-lite' ),
			'no_consent'            => __( 'Skipped: no consent', 'orderring-lite' ),
			'no_number'             => __( 'Skipped: no valid number', 'orderring-lite' ),
			'rate_limited_ip'       => __( 'Skipped: too many from one visitor', 'orderring-lite' ),
			'rate_limited_number'   => __( 'Skipped: too many to one number', 'orderring-lite' ),
			'recipient_not_allowed' => __( 'Skipped: recipient not available', 'orderring-lite' ),
		);
		return isset( $labels[ $outcome ] ) ? $labels[ $outcome ] : $outcome;
	}

	/* ---------- settings screen ---------- */

	public function menu() {
		add_submenu_page(
			self::PARENT,
			__( 'Triggers', 'orderring-lite' ),
			__( 'Triggers', 'orderring-lite' ),
			ORLT_Caps::manage(),
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Clean posted rules. Pure apart from translations and id generation.
	 *
	 * @param mixed $raw Posted rows.
	 * @return array{rules:array[],errors:string[]}
	 */
	public static function sanitize_rules( $raw ) {
		$rules  = array();
		$errors = array();
		$seen   = array();
		$events = self::events();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}
			$event    = isset( $row['event'] ) ? sanitize_key( (string) $row['event'] ) : '';
			$template = isset( $row['template'] ) ? trim( sanitize_textarea_field( (string) $row['template'] ) ) : '';
			if ( ! isset( $events[ $event ] ) || '' === $template ) {
				continue;
			}
			if ( count( $rules ) >= self::MAX_RULES ) {
				/* translators: %d: maximum number of rules */
				$errors[] = sprintf( __( 'Only the first %d rules were kept.', 'orderring-lite' ), self::MAX_RULES );
				break;
			}
			$rule          = self::blank_rule();
			$id            = isset( $row['id'] ) ? (string) $row['id'] : '';
			$rule['id']    = preg_match( '/^r[a-f0-9]{8}$/', $id ) && ! isset( $seen[ $id ] ) ? $id : self::new_id( $seen );
			$seen[ $rule['id'] ] = true;
			$rule['event'] = $event;
			$rule['template'] = function_exists( 'mb_substr' ) ? mb_substr( $template, 0, self::MAX_TEMPLATE ) : substr( $template, 0, self::MAX_TEMPLATE );
			$rule['enabled']  = ! empty( $row['enabled'] );
			$who              = isset( $row['recipient'] ) ? sanitize_key( (string) $row['recipient'] ) : 'admin';
			$allowed          = self::recipients_for( $event );
			if ( ! in_array( $who, $allowed, true ) ) {
				/* translators: %s: event name */
				$errors[] = sprintf( __( 'A rule for "%s" can only text the admin number, so it was changed to that.', 'orderring-lite' ), $events[ $event ]['label'] );
				$who      = 'admin';
			}
			$rule['recipient'] = $who;
			if ( 'cf7_submit' === $event ) {
				$rule['form_id']       = isset( $row['form_id'] ) ? absint( $row['form_id'] ) : 0;
				$rule['phone_field']   = self::field_name( $row['phone_field'] ?? '' );
				$rule['consent_field'] = self::field_name( $row['consent_field'] ?? '' );
			}
			if ( 'custom' === $who ) {
				$rule['custom_phone'] = self::normalize( $row['custom_phone'] ?? '' );
				if ( '' === $rule['custom_phone'] && $rule['enabled'] ) {
					$rule['enabled'] = false;
					$errors[]        = __( 'A rule that texts another number needs a valid number with the country code (for example +15095551234). It was saved switched off.', 'orderring-lite' );
				}
			}
			if ( 'person' === $who && 'cf7_submit' === $event && ( '' === $rule['phone_field'] || '' === $rule['consent_field'] ) && $rule['enabled'] ) {
				$rule['enabled'] = false;
				$errors[]        = __( 'A form rule that texts the person needs both the phone field and the consent checkbox field. It was saved switched off.', 'orderring-lite' );
			}
			$rules[] = $rule;
		}
		return array(
			'rules'  => $rules,
			'errors' => array_values( array_unique( $errors ) ),
		);
	}

	/**
	 * @param mixed $name Posted field name.
	 * @return string
	 */
	public static function field_name( $name ) {
		return substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( (string) $name ) ) ), 0, 64 );
	}

	/**
	 * @param array $seen Ids in use.
	 * @return string
	 */
	private static function new_id( array $seen ) {
		do {
			$id = 'r' . substr( md5( uniqid( '', true ) . wp_rand() ), 0, 8 );
		} while ( isset( $seen[ $id ] ) );
		return $id;
	}

	public function handle_save() {
		if ( ! current_user_can( ORLT_Caps::manage() ) ) {
			wp_die( esc_html__( 'You do not have permission to change triggers.', 'orderring-lite' ) );
		}
		check_admin_referer( self::SAVE_ACTION );
		$posted = isset( $_POST['triggers'] ) ? wp_unslash( $_POST['triggers'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_rules() cleans every field.
		$clean  = self::sanitize_rules( $posted );
		update_option( self::OPTION, $clean['rules'] );

		$errors = $clean['errors'];
		$admin  = isset( $_POST['admin_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_phone'] ) ) : '';
		$norm   = self::normalize( $admin );
		if ( '' !== trim( $admin ) && '' === $norm ) {
			$errors[] = __( 'The admin number needs the country code, for example +15095551234. It was not changed.', 'orderring-lite' );
		} else {
			update_option( self::OPT_ADMIN_PHONE, $norm );
		}
		foreach ( array( self::OPT_RATE_IP => 'rate_ip', self::OPT_RATE_NUMBER => 'rate_number', self::OPT_RATE_ADMIN => 'rate_admin' ) as $option => $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_option( $option, max( 0, min( 1000, absint( wp_unslash( $_POST[ $field ] ) ) ) ) );
			}
		}
		if ( $errors ) {
			set_transient( 'orlt_trigger_errors_' . get_current_user_id(), $errors, 120 );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Note at the top of the screen.
	 */
	private static function page_notice() {
		echo '<p class="description">' . esc_html__( 'Texts to the person for account events, and to other numbers, are in OrderRing Pro.', 'orderring-lite' ) . '</p>';
	}

	public function render_page() {
		if ( ! current_user_can( ORLT_Caps::manage() ) ) {
			return;
		}
		$events = self::events();
		$rules  = self::rules();
		$rules[] = self::blank_rule();
		$errors  = get_transient( 'orlt_trigger_errors_' . get_current_user_id() );
		if ( false !== $errors ) {
			delete_transient( 'orlt_trigger_errors_' . get_current_user_id() );
		}
		$errors = is_array( $errors ) ? $errors : array();
		$tags   = array( '{site_name}', '{contact_first_name}' );
		foreach ( $events as $def ) {
			$tags = array_merge( $tags, array_keys( $def['tags'] ) );
		}
		$tags = array_unique( $tags );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Triggers', 'orderring-lite' ); ?></h1>
			<?php self::page_notice(); ?>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Triggers saved.', 'orderring-lite' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $errors as $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( (string) $error ); ?></p></div>
			<?php endforeach; ?>
			<p><?php echo esc_html__( 'Send a text when something happens on your site. Every trigger text respects STOP replies, consent and quiet hours. A text to the person who filled in a form needs a ticked consent checkbox on that form.', 'orderring-lite' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="orlt_trigger_admin-phone"><?php echo esc_html__( 'Admin number', 'orderring-lite' ); ?></label></th>
						<td>
							<input type="tel" id="orlt_trigger_admin-phone" name="admin_phone" class="regular-text" value="<?php echo esc_attr( (string) get_option( self::OPT_ADMIN_PHONE, '' ) ); ?>" placeholder="<?php echo esc_attr( self::admin_phone() ? self::admin_phone() : '+15095551234' ); ?>" />
							<p class="description"><?php echo esc_html__( 'The number "The admin number" rules text, with the country code.', 'orderring-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Limits per hour', 'orderring-lite' ); ?></th>
						<td>
							<label><?php echo esc_html__( 'Events from one visitor', 'orderring-lite' ); ?> <input type="number" min="0" max="1000" name="rate_ip" class="small-text" value="<?php echo esc_attr( (string) self::rate_option( self::OPT_RATE_IP, self::DEFAULT_RATE_IP ) ); ?>" /></label>
							<label><?php echo esc_html__( 'Texts to one number', 'orderring-lite' ); ?> <input type="number" min="0" max="1000" name="rate_number" class="small-text" value="<?php echo esc_attr( (string) self::rate_option( self::OPT_RATE_NUMBER, self::DEFAULT_RATE_NUMBER ) ); ?>" /></label>
							<label><?php echo esc_html__( 'Texts to the admin number', 'orderring-lite' ); ?> <input type="number" min="0" max="1000" name="rate_admin" class="small-text" value="<?php echo esc_attr( (string) self::rate_option( self::OPT_RATE_ADMIN, self::DEFAULT_RATE_ADMIN ) ); ?>" /></label>
							<p class="description"><?php echo esc_html__( 'Forms, sign-ups, password resets and comments can be caused by anyone, so they are limited: how many of them one visitor can cause in an hour, and how many texts one number gets from them. 0 means no limit.', 'orderring-lite' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Rules', 'orderring-lite' ); ?></h2>
				<?php foreach ( $rules as $i => $rule ) : ?>
					<?php $name = 'triggers[' . (int) $i . ']'; ?>
					<fieldset class="orlt_trigger_rule" style="border:1px solid #c3c4c7;padding:12px;margin:0 0 12px;max-width:760px;min-width:0;box-sizing:border-box;">
						<legend style="padding:0 6px;font-weight:600;"><?php echo '' === $rule['id'] ? esc_html__( 'New rule', 'orderring-lite' ) : esc_html( $events[ $rule['event'] ]['label'] ?? $rule['event'] ); ?></legend>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $rule['id'] ); ?>" />
						<p>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( '' === $rule['id'] || ! empty( $rule['enabled'] ) ); ?> /> <?php echo esc_html__( 'On', 'orderring-lite' ); ?></label>
							<?php if ( '' !== $rule['id'] ) : ?>
								&nbsp; <label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete]" value="1" /> <?php echo esc_html__( 'Delete this rule', 'orderring-lite' ); ?></label>
							<?php endif; ?>
						</p>
						<p>
							<label><?php echo esc_html__( 'When', 'orderring-lite' ); ?>
								<select name="<?php echo esc_attr( $name ); ?>[event]" style="max-width:100%;">
									<option value=""><?php echo esc_html__( 'Choose an event', 'orderring-lite' ); ?></option>
									<?php foreach ( $events as $key => $def ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $rule['event'], $key ); ?>><?php echo esc_html( $def['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label><?php echo esc_html__( 'text', 'orderring-lite' ); ?>
								<select name="<?php echo esc_attr( $name ); ?>[recipient]" style="max-width:100%;">
									<?php foreach ( self::all_recipients() as $who ) : ?>
										<option value="<?php echo esc_attr( $who ); ?>" <?php selected( $rule['recipient'], $who ); ?>><?php echo esc_html( self::recipient_label( $who ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</p>
						<?php if ( in_array( 'custom', self::all_recipients(), true ) ) : ?>
							<p><label><?php echo esc_html__( 'Other number', 'orderring-lite' ); ?> <input type="tel" name="<?php echo esc_attr( $name ); ?>[custom_phone]" value="<?php echo esc_attr( $rule['custom_phone'] ); ?>" placeholder="+15095551234" /></label></p>
						<?php endif; ?>
						<p class="description"><?php echo esc_html__( 'Contact Form 7 only:', 'orderring-lite' ); ?></p>
						<p>
							<label><?php echo esc_html__( 'Form ID (blank for any form)', 'orderring-lite' ); ?> <input type="number" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[form_id]" value="<?php echo esc_attr( $rule['form_id'] ? (string) $rule['form_id'] : '' ); ?>" /></label>
							<label><?php echo esc_html__( 'Phone field', 'orderring-lite' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[phone_field]" value="<?php echo esc_attr( $rule['phone_field'] ); ?>" placeholder="your-phone" /></label>
							<label><?php echo esc_html__( 'Consent checkbox field', 'orderring-lite' ); ?> <input type="text" name="<?php echo esc_attr( $name ); ?>[consent_field]" value="<?php echo esc_attr( $rule['consent_field'] ); ?>" placeholder="sms-consent" /></label>
						</p>
						<p>
							<label for="orlt_trigger_template-<?php echo (int) $i; ?>"><?php echo esc_html__( 'Message', 'orderring-lite' ); ?></label><br />
							<textarea id="orlt_trigger_template-<?php echo (int) $i; ?>" name="<?php echo esc_attr( $name ); ?>[template]" rows="3" class="large-text" maxlength="<?php echo (int) self::MAX_TEMPLATE; ?>"><?php echo esc_textarea( $rule['template'] ); ?></textarea>
						</p>
					</fieldset>
				<?php endforeach; ?>
				<p class="description">
					<?php echo esc_html__( 'Tags you can use:', 'orderring-lite' ); ?>
					<?php foreach ( $tags as $tag ) : ?>
						<code><?php echo esc_html( $tag ); ?></code>
					<?php endforeach; ?>
				</p>
				<?php submit_button( __( 'Save triggers', 'orderring-lite' ) ); ?>
			</form>

			<?php $recent = get_option( self::OPT_RECENT, array() ); ?>
			<?php if ( is_array( $recent ) && $recent ) : ?>
				<h2><?php echo esc_html__( 'Recent activity', 'orderring-lite' ); ?></h2>
				<table class="widefat striped" style="max-width:760px;">
					<thead><tr><th><?php echo esc_html__( 'When', 'orderring-lite' ); ?></th><th><?php echo esc_html__( 'Event', 'orderring-lite' ); ?></th><th><?php echo esc_html__( 'To', 'orderring-lite' ); ?></th><th><?php echo esc_html__( 'Result', 'orderring-lite' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $recent as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, g:i a', (int) ( $row['at'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( $events[ $row['event'] ?? '' ]['label'] ?? (string) ( $row['event'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['to'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( self::outcome_label( (string) ( $row['outcome'] ?? '' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
