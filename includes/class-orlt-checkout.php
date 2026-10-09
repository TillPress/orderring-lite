<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built-in checkout SMS consent checkbox (classic + block checkout).
 *
 * Important (1.14.0): never overwrite an explicit Yes with No when the block
 * field is merely missing/unknown. Only write "no" for an explicit false choice.
 */
class ORLT_Checkout {

	const BLOCK_FIELD_ID = 'orl/sms-consent';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! (int) get_option( 'orlt_checkout_consent_enabled', 1 ) ) {
			return;
		}

		// Classic checkout.
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_classic_checkbox' ), 20 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_to_order' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'sync_classic_after_process' ), 20, 3 );

		// Block / Store API additional checkout field (WooCommerce 8.9+).
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_block_from_request' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'sync_block_field_to_consent_meta' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'sync_block_field_to_consent_meta' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'sync_after_order_processed' ), 25, 3 );
	}

	public static function consent_label() {
		$default = __( 'I agree to receive SMS updates about my order (msg & data rates may apply). Reply STOP to opt out.', 'orderring-lite' );
		$label   = get_option( 'orlt_checkout_consent_label', $default );
		return is_string( $label ) && $label !== '' ? $label : $default;
	}

	public static function meta_key() {
		$key = get_option( 'orlt_sms_consent_meta', '_orlt_sms_consent' );
		return $key ? $key : '_orlt_sms_consent';
	}

	public function render_classic_checkbox() {
		$checked = ! empty( $_POST['orlt_sms_consent'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		?>
		<p class="form-row form-row-wide toc-checkout-consent">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input type="checkbox"
					class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
					name="orlt_sms_consent"
					id="orlt_sms_consent"
					value="1"
					<?php checked( $checked, true ); ?>
				/>
				<span class="woocommerce-terms-and-conditions-checkbox-text"><?php echo esc_html( self::consent_label() ); ?></span>
				<?php if ( (int) get_option( 'orlt_checkout_consent_required', 0 ) ) : ?>
					&nbsp;<abbr class="required" title="<?php echo esc_attr__( 'required', 'orderring-lite' ); ?>">*</abbr>
				<?php endif; ?>
			</label>
		</p>
		<?php
	}

	public function validate_classic() {
		if ( ! (int) get_option( 'orlt_checkout_consent_required', 0 ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['orlt_sms_consent'] ) ) {
			wc_add_notice(
				__( 'Please agree to receive SMS updates about your order, or contact the store for alternatives.', 'orderring-lite' ),
				'error'
			);
		}
	}

	/**
	 * Classic: POST present → explicit yes/no (unchecked = explicit no for classic checkbox).
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data is part of the fixed WooCommerce checkout hook signature.
	public function save_classic_to_order( $order, $data ) {
		// Classic checkbox: key present with value = yes; missing key = explicit no (user left unchecked).
		$consented = ! empty( $_POST['orlt_sms_consent'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC checkout nonce already verified.
		$this->apply_consent_to_order( $order, $consented, 'classic', true );
	}

	/**
	 * Final classic sync after order is fully processed.
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order.
	 */
	public function sync_classic_after_process( $order_id, $posted = array(), $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		// Only re-apply if classic POST still available (same request).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['orlt_sms_consent'] ) && empty( $_POST ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( array_key_exists( 'orlt_sms_consent', $_POST ) || ! empty( $_POST['billing_first_name'] ) || ! empty( $_POST['woocommerce-process-checkout-nonce'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$consented = ! empty( $_POST['orlt_sms_consent'] );
			// Classic unchecked is explicit no only when we know this was a classic checkout post.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$explicit = array_key_exists( 'orlt_sms_consent', $_POST ) || ! empty( $_POST['woocommerce-process-checkout-nonce'] );
			if ( $explicit || $consented ) {
				$this->apply_consent_to_order( $order, $consented, 'classic-sync', $explicit || $consented );
				$order->save();
			}
		}
	}

	public function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::BLOCK_FIELD_ID,
				'label'    => self::consent_label(),
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => (bool) (int) get_option( 'orlt_checkout_consent_required', 0 ),
			)
		);
	}

	/**
	 * Read block consent from Store API request.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 */
	public function save_block_from_request( $order, $request ) {
		$state = $this->read_block_consent_state( $order, $request );

		if ( 'yes' === $state ) {
			$this->apply_consent_to_order( $order, true, 'block', true );
		} elseif ( 'no' === $state ) {
			// Explicit false from a reliable field read only.
			$this->apply_consent_to_order( $order, false, 'block', true );
		}
		// 'unknown' → leave existing meta alone (do not write "no").
	}

	/**
	 * After order create / Store API process: re-sync block field → consent meta.
	 *
	 * @param WC_Order|int $order Order or ID.
	 */
	public function sync_block_field_to_consent_meta( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$state = $this->read_block_consent_state( $order, null );
		if ( 'yes' === $state ) {
			$this->apply_consent_to_order( $order, true, 'block-sync', true );
			$order->save();
		} elseif ( 'no' === $state ) {
			// Only write no if we have an explicit false value on the order field meta.
			$this->apply_consent_to_order( $order, false, 'block-sync', true );
			$order->save();
		}
		// unknown: leave alone.
	}

	/**
	 * Classic + block final hook (order_id, posted, order).
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order.
	 */
	public function sync_after_order_processed( $order_id, $posted = array(), $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// Prefer classic POST if present.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['orlt_sms_consent'] ) ) {
			$this->apply_consent_to_order( $order, true, 'checkout-processed', true );
			$order->save();
			return;
		}

		// Block field meta may land after create.
		$state = $this->read_block_consent_state( $order, null );
		if ( 'yes' === $state ) {
			$this->apply_consent_to_order( $order, true, 'checkout-processed', true );
			$order->save();
		}
		// Do not force "no" on this late hook when field is missing.
	}

	/**
	 * Resolve block consent to yes|no|unknown.
	 *
	 * @param WC_Order             $order   Order.
	 * @param WP_REST_Request|null $request Optional request.
	 * @return string yes|no|unknown
	 */
	private function read_block_consent_state( $order, $request = null ) {
		$twilio = ORLT_Twilio::instance();

		// 1) Store API request bags.
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$bags = array();
			foreach ( array( 'additional_fields', 'extensions' ) as $param ) {
				$extra = $request->get_param( $param );
				if ( is_array( $extra ) ) {
					$bags[] = $extra;
				}
			}
			foreach ( $bags as $bag ) {
				// Nested extensions may wrap fields.
				$flat = $bag;
				if ( isset( $bag['toc'] ) && is_array( $bag['toc'] ) ) {
					$flat = array_merge( $flat, $bag['toc'] );
				}
				foreach ( array( self::BLOCK_FIELD_ID, 'orl/sms-consent', 'toc-sms-consent', 'sms-consent' ) as $key ) {
					if ( array_key_exists( $key, $flat ) ) {
						$val = $flat[ $key ];
						if ( $twilio->is_truthy_consent( $val ) ) {
							return 'yes';
						}
						if ( $this->is_explicit_false_consent( $val ) ) {
							return 'no';
						}
						// Present but empty/null → unknown (do not treat as no).
						return 'unknown';
					}
				}
				// Scan bag keys containing sms-consent.
				foreach ( $flat as $k => $v ) {
					if ( ! is_string( $k ) ) {
						continue;
					}
					if ( false === stripos( $k, 'sms-consent' ) && false === stripos( $k, 'sms_consent' ) ) {
						continue;
					}
					if ( $twilio->is_truthy_consent( $v ) ) {
						return 'yes';
					}
					if ( $this->is_explicit_false_consent( $v ) ) {
						return 'no';
					}
				}
			}
		}

		// 2) Order meta written by WC additional fields.
		return $this->order_block_consent_state( $order );
	}

	/**
	 * @param WC_Order $order Order.
	 * @return string yes|no|unknown
	 */
	private function order_block_consent_state( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 'unknown';
		}
		$twilio = ORLT_Twilio::instance();

		$keys = array(
			'_wc_other/' . self::BLOCK_FIELD_ID,
			'_wc_billing/' . self::BLOCK_FIELD_ID,
			'_wc_shipping/' . self::BLOCK_FIELD_ID,
			'_wc_other/orl/sms-consent',
			'_wc_other/toc-sms-consent',
			self::BLOCK_FIELD_ID,
			'orl/sms-consent',
			'toc-sms-consent',
			'_orlt_block_sms_consent',
		);
		foreach ( $keys as $key ) {
			// Distinguish missing meta from present empty.
			$raw = $order->get_meta( $key, true, 'edit' );
			if ( '' === $raw || null === $raw ) {
				// get_meta returns '' for missing — check meta_data for real presence.
				continue;
			}
			if ( $twilio->is_truthy_consent( $raw ) ) {
				return 'yes';
			}
			if ( $this->is_explicit_false_consent( $raw ) ) {
				return 'no';
			}
		}

		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
			if ( $key === '' ) {
				continue;
			}
			if ( false === stripos( $key, 'sms-consent' ) && false === stripos( $key, 'sms_consent' ) ) {
				continue;
			}
			// Skip our own canonical keys when reading "block field" state to avoid loops.
			if ( in_array( $key, array( '_orlt_sms_consent', self::meta_key() ), true ) ) {
				continue;
			}
			$val = $data['value'] ?? '';
			if ( $twilio->is_truthy_consent( $val ) ) {
				return 'yes';
			}
			if ( $this->is_explicit_false_consent( $val ) ) {
				return 'no';
			}
		}

		return 'unknown';
	}

	/**
	 * Explicit false only (not empty/missing).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_explicit_false_consent( $value ) {
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
			return false; // empty string is unknown, not explicit no.
		}
		return in_array( $v, array( 'no', 'n', '0', 'false', 'off', 'unchecked', 'opt-out', 'optout', 'declined' ), true );
	}

	/**
	 * Persist consent + audit trail.
	 *
	 * @param WC_Order $order     Order.
	 * @param bool     $consented Whether opted in.
	 * @param string   $source    classic|block|….
	 * @param bool     $explicit  True when the choice is reliable (yes or explicit no).
	 */
	public function apply_consent_to_order( $order, $consented, $source = '', $explicit = true ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Never write "no" for non-explicit / unknown paths.
		if ( ! $consented && ! $explicit ) {
			return;
		}

		$meta_key = self::meta_key();

		if ( $consented ) {
			$value = 'yes';
			$order->update_meta_data( $meta_key, $value );
			$order->update_meta_data( '_orlt_sms_consent', $value );
			$order->update_meta_data( '_orlt_sms_consent_at', time() );
			$order->update_meta_data( '_orlt_sms_consent_ip', $this->client_ip() );
			if ( $source ) {
				$order->update_meta_data( '_orlt_sms_consent_source', sanitize_key( $source ) );
			}
		} else {
			$value = 'no';
			$order->update_meta_data( $meta_key, $value );
			$order->update_meta_data( '_orlt_sms_consent', $value );
			if ( $source ) {
				$order->update_meta_data( '_orlt_sms_consent_source', sanitize_key( $source ) );
			}
		}

		$user_id = $order->get_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, $meta_key, $value );
			update_user_meta( $user_id, '_orlt_sms_consent', $value );
		}
	}

	/**
	 * Best-effort client IP for the consent audit trail (TCPA/GDPR record).
	 *
	 * Uses the server-set REMOTE_ADDR, which the client cannot forge. The
	 * client-supplied X-Forwarded-For header is only honored when the site opts in via
	 * the `orlt_trust_proxy_ip` filter (i.e. it sits behind a trusted reverse proxy),
	 * otherwise a visitor could spoof the recorded consent IP. The result is validated.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( apply_filters( 'orlt_trust_proxy_ip', false ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts     = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$candidate = trim( $parts[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
