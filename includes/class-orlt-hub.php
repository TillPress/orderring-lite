<?php
/**
 * Help & demo page for OrderRing Lite: support ticket composer, demo data, and the
 * optional offer for the paid plugin.
 *
 * The only outbound request is the support ticket (ORLT_Support), sent to
 * tillpress.com when an administrator ticks the consent box and presses Send.
 *
 * @package OrderRingLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Help & demo admin page.
 */
class ORLT_Hub {

	const PAGE         = 'orlt-help';
	const SUPPORT_MAIL = 'info@tillpress.com';
	const SUPPORT_URL  = 'https://tillpress.com/support/';
	const DOCS_URL     = 'https://tillpress.com/docs/orderring-lite/';
	const FORUM_URL    = 'https://wordpress.org/support/plugin/orderring-lite/';
	const PRO_URL      = 'https://tillpress.com/orderring/';
	const OFFER_CODE   = 'LITE20';
	const OFFER_META   = 'orlt_offer_dismissed';

	/**
	 * Singleton instance.
	 *
	 * @var ORLT_Hub|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return ORLT_Hub
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_orlt_demo_create', array( $this, 'handle_demo_create' ) );
		add_action( 'admin_post_orlt_demo_remove', array( $this, 'handle_demo_remove' ) );
		add_action( 'admin_post_orlt_offer_dismiss', array( $this, 'handle_offer_dismiss' ) );
		add_filter( 'plugin_action_links_' . ORLT_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Capability required for the page and its actions.
	 *
	 * @return string
	 */
	public static function capability() {
		return ORLT_Caps::manage();
	}

	/**
	 * Register the submenu page.
	 */
	public function menu() {
		add_submenu_page(
			'orderring-lite',
			__( 'OrderRing Lite help & demo', 'orderring-lite' ),
			__( 'Help & demo', 'orderring-lite' ),
			self::capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Admin URL of this page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Plugins-screen link.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Help & demo', 'orderring-lite' ) . '</a>';
		return $links;
	}

	/**
	 * Enqueue page assets only on this screen.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function assets( $hook ) {
		if ( ! is_string( $hook ) || false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'orlt-hub', ORLT_PLUGIN_URL . 'assets/hub.css', array(), ORLT_VERSION );
		ORLT_Support::enqueue();
	}

	/**
	 * Whether the offer box should show (pure).
	 *
	 * @param bool $dismissed Whether this user dismissed it.
	 * @return bool
	 */
	public static function should_show_offer( $dismissed ) {
		return ! $dismissed;
	}

	/**
	 * Format report lines as "Label: value" text (pure).
	 *
	 * @param array $lines Label => value.
	 * @return string
	 */
	public static function format_report( $lines ) {
		$out = array();
		foreach ( (array) $lines as $label => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			} elseif ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$out[] = $label . ': ' . (string) $value;
		}
		return implode( "\n", $out );
	}

	/**
	 * Environment lines. Never includes credentials, keys, or customer data.
	 *
	 * @return array
	 */
	public static function environment_lines() {
		global $wpdb;
		$theme   = wp_get_theme();
		$plugins = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$plugins[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
			}
		}
		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return array(
			'Plugin'         => 'OrderRing Lite ' . ORLT_VERSION,
			'Site'           => home_url( '/' ),
			'WordPress'      => get_bloginfo( 'version' ) . ( is_multisite() ? ' (multisite)' : '' ),
			'WooCommerce'    => defined( 'WC_VERSION' ) ? WC_VERSION : 'not active',
			'PHP'            => PHP_VERSION,
			'Database'       => ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) ? (string) $wpdb->db_version() : 'unknown',
			'Theme'          => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ? ' (block theme)' : '' ),
			'HPOS'           => (bool) $hpos,
			'Language'       => get_locale(),
			'Memory limit'   => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'unknown',
			'WP_DEBUG'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'Active plugins' => $plugins,
		);
	}

	/**
	 * Full system report text for support tickets.
	 *
	 * @return string
	 */
	public static function system_report() {
		return self::format_report( array_merge( self::environment_lines(), ORLT_Demo::report_lines() ) );
	}

	/**
	 * Redirect back to this page with a status flag.
	 *
	 * @param string $state Status key.
	 */
	private function back( $state ) {
		wp_safe_redirect( self::page_url( array( 'orlt_hub' => $state ) ) );
		exit;
	}

	/**
	 * Guard for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'orderring-lite' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Create demo data.
	 */
	public function handle_demo_create() {
		$this->guard( 'orlt_demo_create' );
		$result = ORLT_Demo::create();
		$this->back( is_wp_error( $result ) ? 'failed' : 'created' );
	}

	/**
	 * Remove demo data.
	 */
	public function handle_demo_remove() {
		$this->guard( 'orlt_demo_remove' );
		ORLT_Demo::remove();
		$this->back( 'removed' );
	}

	/**
	 * Hide the offer box for the current user.
	 */
	public function handle_offer_dismiss() {
		$this->guard( 'orlt_offer_dismiss' );
		update_user_meta( get_current_user_id(), self::OFFER_META, 1 );
		$this->back( 'offer_hidden' );
	}

	/**
	 * One-button form posting to admin-post.php.
	 *
	 * @param string $action Action name (also the nonce action).
	 * @param string $label  Button label.
	 * @param string $class  Button class.
	 */
	private function action_button( $action, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="orlt-hub-inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Status notice after an action.
	 */
	private function render_status() {
		$state = isset( $_GET['orlt_hub'] ) ? sanitize_key( wp_unslash( $_GET['orlt_hub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$map   = array(
			'created'      => array( 'success', __( 'Demo data created.', 'orderring-lite' ) ),
			'removed'      => array( 'success', __( 'Demo data removed.', 'orderring-lite' ) ),
			'failed'       => array( 'error', __( 'Demo data could not be created. Check that WooCommerce is active, then try again.', 'orderring-lite' ) ),
			'offer_hidden' => array( 'success', __( 'Offer hidden. It will not show again for your account.', 'orderring-lite' ) ),
		);
		if ( isset( $map[ $state ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $state ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $state ][1] ) . '</p></div>';
		}
	}

	/**
	 * Render the page.
	 */
	public function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'orderring-lite' ) );
		}
		echo '<div class="wrap orlt-hub">';
		echo '<h1>' . esc_html__( 'OrderRing Lite help & demo', 'orderring-lite' ) . '</h1>';
		$this->render_status();
		echo '<div class="orlt-hub-grid">';

		// Demo.
		echo '<section class="orlt-hub-card"><h2>' . esc_html__( 'Try it with demo data', 'orderring-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Creates one demo order in Ready for Pickup with Local Pickup and SMS consent, so you can see the order-screen SMS box, the consent badge, and the exact pickup text your customer would get. No text is ever sent for a demo order.', 'orderring-lite' ) . '</p>';
		if ( ORLT_Demo::exists() ) {
			echo '<ul class="orlt-hub-list">';
			foreach ( ORLT_Demo::links() as $link ) {
				echo '<li><a href="' . esc_url( $link['url'] ) . '"' . ( ! empty( $link['new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $link['label'] ) . '</a></li>';
			}
			echo '</ul>';
			ORLT_Demo::render_extra();
			$this->action_button( 'orlt_demo_remove', __( 'Remove demo data', 'orderring-lite' ) );
		} else {
			$this->action_button( 'orlt_demo_create', __( 'Create demo data', 'orderring-lite' ), 'button button-primary' );
		}
		echo '<p class="description">' . esc_html__( 'Demo data is labelled "Demo", is never emailed or texted, and is deleted with one click here (and when you uninstall the plugin).', 'orderring-lite' ) . '</p>';
		echo '</section>';

		// Support.
		echo '<section class="orlt-hub-card"><h2>' . esc_html__( 'Get support', 'orderring-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Send a ticket straight to the Tillpress team from here. You get a ticket number right away and the reply comes by email. Nothing is sent until you tick the consent box and press Send.', 'orderring-lite' ) . '</p>';
		ORLT_Support::instance()->render_form();
		echo '<p>' . esc_html__( 'Other ways to reach us:', 'orderring-lite' ) . ' ';
		echo '<a href="' . esc_url( self::DOCS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'orderring-lite' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( self::FORUM_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WordPress.org support forum', 'orderring-lite' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support form on tillpress.com', 'orderring-lite' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( 'mailto:' . self::SUPPORT_MAIL ) . '">' . esc_html( self::SUPPORT_MAIL ) . '</a></p>';
		echo '</section>';

		// Offer.
		if ( self::should_show_offer( (bool) get_user_meta( get_current_user_id(), self::OFFER_META, true ) ) ) {
			echo '<section class="orlt-hub-card orlt-hub-offer"><h2>' . esc_html__( 'OrderRing Pro: 20% off for OrderRing Lite users', 'orderring-lite' ) . '</h2>';
			echo '<p>' . esc_html__( 'Adds two-way SMS chat on the order, voice calls, WhatsApp, bulk pickup reminders, and more message templates.', 'orderring-lite' ) . '</p>';
			echo '<p>' . esc_html__( 'Enter this code at checkout on tillpress.com for 20% off a yearly license:', 'orderring-lite' ) . ' <code class="orlt-hub-code">' . esc_html( self::OFFER_CODE ) . '</code></p>';
			echo '<p class="orlt-hub-actions"><a class="button button-primary" href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See OrderRing Pro', 'orderring-lite' ) . '</a> ';
			$this->action_button( 'orlt_offer_dismiss', __( 'Hide this offer', 'orderring-lite' ), 'button-link' );
			echo '</p>';
			echo '<p class="description">' . esc_html__( 'OrderRing Lite is complete and is never locked. The paid plugin is a separate download; this code is optional.', 'orderring-lite' ) . '</p>';
			echo '</section>';
		}

		echo '</div></div>';
	}
}
