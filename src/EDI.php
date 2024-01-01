<?php
/**
 * Class EDI
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

namespace BytePerfect\EDI;

use BytePerfect\EDI\CLI\EDI_CLI;
use Exception;
use WC_Logger;
use WC_Order;

/**
 * Class EDI
 *
 * @package BytePerfect\EDI
 */
class EDI {
	/**
	 * Plugin version number.
	 */
	const VERSION = '2.0.0';

	/**
	 * EDI URL.
	 */
	const URL = 'https://edi.byteperfect.dev/';

	/**
	 * EDI constructor.
	 */
	public function __construct() {
		load_plugin_textdomain( 'edi', false, plugin_basename( dirname( EDI_PLUGIN_FILE ) ) . '/languages' );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			new EDI_CLI();
		}

		register_activation_hook(
			EDI_PLUGIN_FILE,
			array( __NAMESPACE__ . '\\Activator', 'run' )
		);

		register_deactivation_hook(
			EDI_PLUGIN_FILE,
			array( __NAMESPACE__ . '\\Deactivator', 'run' )
		);

		register_uninstall_hook(
			EDI_PLUGIN_FILE,
			array( __NAMESPACE__ . '\\Uninstaller', 'run' )
		);

		add_action(
			'upgrader_process_complete',
			array( __NAMESPACE__ . '\\Updater', 'run' ),
			10,
			2
		);

		add_action(
			'plugins_loaded',
			array( $this, 'edi_loaded' ),
			PHP_INT_MAX
		);

		if ( $this->is_woocommerce_activated() ) {
			add_action(
				'woocommerce_after_order_object_save',
				array( $this, 'set_order_modified_timestamp' )
			);

			// This will add the direct "Settings" link inside wp plugins menu.
			add_filter(
				'plugin_action_links_e-commerce-data-interchange/e-commerce-data-interchange.php',
				array( $this, 'settings_link' )
			);

			new Settings();
			new Request();
		} else {
			add_action(
				'admin_notices',
				array( $this, 'show_woocommerce_missing_notice' )
			);
		}
	}

	/**
	 * Add link to settings page.
	 *
	 * @param array<string, string> $links Links.
	 *
	 * @return array<string, string>
	 */
	public function settings_link( array $links ): array {
		$settings = array(
			'setting' => sprintf(
				'<a href="%s">%s</a>',
				admin_url( 'admin.php?page=edi' ),
				__( 'Settings', 'edi' )
			),
		);

		return array_merge( $settings, $links );
	}

	/**
	 * Check if WooCommerce is activated.
	 *
	 * @return bool
	 */
	protected function is_woocommerce_activated(): bool {
		return in_array(
			'woocommerce/woocommerce.php',
			(array) apply_filters( 'active_plugins', get_option( 'active_plugins' ) ),
			true
		);
	}

	/**
	 * Show error message if WooCommerce is not activate.
	 *
	 * @return void
	 */
	public function show_woocommerce_missing_notice(): void {
		echo '<div class="notice notice-error"><p>';
		printf(
		/* translators: %s: WooCommerce URL. */
			esc_html__( 'The %s plugin is required for electronic data interchange.', 'edi' ),
			'<a href="https://woocommerce.com/woocommerce-features/" target="_blank">WooCommerce</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Fire once EDI was loaded.
	 *
	 * @return void
	 */
	public function edi_loaded(): void {
		do_action( 'edi_loaded', self::VERSION );
	}

	/**
	 * Set order modified timestamp.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return void
	 */
	public function set_order_modified_timestamp( WC_Order $order ): void {
		static $is_modified;

		if ( ! is_null( $is_modified ) ) {
			return;
		}

		if ( doing_action( '_/КоммерческаяИнформация/Документ' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		update_post_meta( $order->get_id(), '_edi_modified', current_time( 'timestamp' ) );

		EDI::log()->debug(
			__( 'Order modified timestamp was set. Order ID: ', 'edi' ) . PHP_EOL . $order->get_id()
		);

		$is_modified = true;
	}

	/**
	 * Get repository.
	 *
	 * @return Repository
	 */
	public static function repository(): Repository {
		static $repository = null;

		if ( is_null( $repository ) ) {
			$repository = new Repository();
		}

		return $repository;
	}

	/**
	 * Get Logger instance.
	 *
	 * @return WC_Logger
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function log(): WC_Logger {
		static $logger = null;

		if ( is_null( $logger ) ) {
			$logger = new WC_Logger(
				array( new LogHandlerFile() ),
				Settings::get_logging_level()
			);
		}

		return $logger;
	}

	/**
	 * Get Filesystem instance.
	 *
	 * @return DirectFileSystem
	 *
	 * @throws Exception Exception.
	 */
	public static function filesystem(): DirectFileSystem {
		static $filesystem = null;

		if ( is_null( $filesystem ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$access_type = get_filesystem_method();
			if ( 'direct' === $access_type ) {
				$filesystem = new DirectFileSystem();
			} else {
				throw new Exception(
				/* translators: %s: access type. */
					sprintf( __( 'File system %s is not implemented.', 'edi' ), $access_type )
				);
			}
		}

		return $filesystem;
	}

	/**
	 * Get Tracker instance.
	 *
	 * @retrun Tracker
	 *
	 * @throws Exception Exception.
	 */
	public static function tracker(): Tracker {
		static $tracker = null;

		if ( is_null( $tracker ) ) {
			$tracker = new Tracker( untrailingslashit( self::URL ) . '/tracker/', site_url() );
		}

		return $tracker;
	}
}
