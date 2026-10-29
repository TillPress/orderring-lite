<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merge-tag registry. Core provides site and contact tags; each module adds its own
 * through a provider (the WooCommerce module adds the order tags, a form module can
 * add {field:email}-style tags). ORLT_Twilio::merge_tags() replaces them in one pass.
 *
 * A provider is callable( array $context ): array<string,string> keyed by '{tag}'.
 * $context may hold: order (WC_Order|null), contact (row object/array), phone, and
 * anything a module passes through.
 */
final class ORLT_Merge_Tags {

	/**
	 * @var array<string,array{callback:callable,tags:array<string,string>}>|null
	 */
	private static $providers = null;

	/**
	 * Register (or replace) a provider for a group of tags.
	 *
	 * @param string   $group    Group key, e.g. 'core', 'order', 'cf7'.
	 * @param callable $callback Returns '{tag}' => value for a context.
	 * @param array    $tags     '{tag}' => short label, for the admin tag list. Tags listed
	 *                           here but missing from the callback result become ''.
	 */
	public static function register( $group, $callback, array $tags = array() ) {
		self::boot();
		$clean = array();
		foreach ( $tags as $tag => $label ) {
			$tag = strtolower( (string) $tag );
			if ( preg_match( '/^\{[a-z_]+(:[a-z0-9_\-]+)?\}$/', $tag ) ) {
				$clean[ $tag ] = (string) $label;
			}
		}
		self::$providers[ sanitize_key( (string) $group ) ] = array(
			'callback' => $callback,
			'tags'     => $clean,
		);
	}

	/**
	 * Every registered tag, grouped: group => '{tag}' => label.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function groups() {
		self::boot();
		$out = array();
		foreach ( self::$providers as $group => $p ) {
			$out[ $group ] = $p['tags'];
		}
		return $out;
	}

	/**
	 * Values for one send. Later groups never override a tag an earlier group set
	 * to a non-empty value, so a module cannot blank a core tag.
	 *
	 * @param array $context Send context.
	 * @return array<string,string> '{tag}' => value.
	 */
	public static function values( array $context ) {
		self::boot();
		$values = array();
		foreach ( self::$providers as $p ) {
			foreach ( $p['tags'] as $tag => $label ) {
				if ( ! isset( $values[ $tag ] ) ) {
					$values[ $tag ] = '';
				}
			}
			$got = is_callable( $p['callback'] ) ? call_user_func( $p['callback'], $context ) : array();
			foreach ( (array) $got as $tag => $value ) {
				$tag = strtolower( (string) $tag );
				if ( isset( $values[ $tag ] ) && '' !== $values[ $tag ] ) {
					continue;
				}
				$values[ $tag ] = (string) $value;
			}
		}
		return $values;
	}

	/**
	 * Forget registrations (tests).
	 */
	public static function reset() {
		self::$providers = null;
	}

	/**
	 * Core tags, then the order tags, then whatever integrations add.
	 */
	private static function boot() {
		if ( null !== self::$providers ) {
			return;
		}
		self::$providers = array();
		self::register(
			'core',
			array( __CLASS__, 'core_values' ),
			array(
				'{site_name}'          => __( 'Site name', 'orderring-lite' ),
				'{store_name}'         => __( 'Site name (same as {site_name})', 'orderring-lite' ),
				'{contact_first_name}' => __( "Contact's first name", 'orderring-lite' ),
				'{contact_last_name}'  => __( "Contact's last name", 'orderring-lite' ),
				'{contact_phone}'      => __( "Contact's phone number", 'orderring-lite' ),
			)
		);
		ORLT_Woo::register_merge_tags();
		/**
		 * Register more merge tags with ORLT_Merge_Tags::register().
		 */
		do_action( 'orlt_register_merge_tags' );
	}

	/**
	 * @param array $context Send context.
	 * @return array<string,string>
	 */
	public static function core_values( array $context ) {
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$contact = isset( $context['contact'] ) ? $context['contact'] : null;
		$contact = is_object( $contact ) ? (array) $contact : ( is_array( $contact ) ? $contact : array() );
		$name    = isset( $contact['name'] ) ? trim( (string) $contact['name'] ) : '';
		$first   = isset( $context['first_name'] ) ? (string) $context['first_name'] : '';
		$last    = isset( $context['last_name'] ) ? (string) $context['last_name'] : '';
		if ( '' === $first && '' !== $name ) {
			$parts = preg_split( '/\s+/', $name, 2 );
			$first = (string) $parts[0];
			$last  = isset( $parts[1] ) ? (string) $parts[1] : $last;
		}
		$phone = isset( $context['phone'] ) ? (string) $context['phone'] : ( isset( $contact['phone'] ) ? (string) $contact['phone'] : '' );
		return array(
			'{site_name}'          => $site,
			'{store_name}'         => $site,
			'{contact_first_name}' => $first,
			'{contact_last_name}'  => $last,
			'{contact_phone}'      => $phone,
		);
	}
}
