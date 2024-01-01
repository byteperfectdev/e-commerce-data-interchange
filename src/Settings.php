<?php
/**
 * Class Settings
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BadMethodCallException;
use WC_Log_Handler_File;
use WP_Admin_Bar;

/**
 * Class Settings
 *
 * @method static get_sale_enable()
 * @method static get_export_orders()
 * @method static get_export_from_timestamp()
 * @method static get_username(): string
 * @method static get_password(): string
 * @method static get_import_chunk_size(): int
 * @method static get_import_categories()
 * @method static get_import_products()
 * @method static get_import_attributes()
 * @method static get_import_images()
 * @method static get_import_orders()
 * @method static get_logging_level(): string
 * @method static get_status_indicator(): string
 *
 * @package BytePerfect\EDI
 */
class Settings {
	/**
	 * Settings constructor.
	 */
	public function __construct() {
		add_action( 'cmb2_admin_init', array( $this, 'register_options_metabox' ) );

		if ( is_admin() ) {
			add_action( 'admin_bar_menu', array( $this, 'admin_bar_render' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_script' ), PHP_INT_MAX );
		}
	}

	/**
	 * Initialize integration settings form fields.
	 *
	 * @return void
	 */
	public function register_options_metabox() {
		$cmb_options = new_cmb2_box(
			array(
				'id'           => 'edi',
				'title'        => esc_html__( 'Synchronization settings with 1C', 'edi' ),
				'object_types' => array( 'options-page' ),
				'option_key'   => 'edi',
				'parent_slug'  => 'woocommerce',
				// @todo: Добавить переводы сообщений.
				// 'message_cb' => 'yourprefix_options_page_message_callback',
			)
		);

		$cmb_options->add_field(
			array(
				'name' => __( 'General settings', 'edi' ),
				'id'   => 'site_url',
				'type' => 'title',
				'desc' => sprintf(
					'%s %s',
					__( 'Site URL used for 1C interchange:', 'edi' ),
					make_clickable( site_url( '/edi/1c' ) )
				),
			)
		);
		$cmb_options->add_field(
			array(
				'name' => __( 'Username', 'edi' ),
				'id'   => 'username',
				'type' => 'text',
				'desc' => __( 'Username used for 1C interchange.', 'edi' ),
			)
		);
		$cmb_options->add_field(
			array(
				'name' => __( 'Password', 'edi' ),
				'id'   => 'password',
				'type' => 'text',
				'desc' => __( 'Password used for 1C interchange.', 'edi' ),
			)
		);
		$cmb_options->add_field(
			array(
				'name' => __( 'Import categories', 'edi' ),
				'id'   => 'import_categories',
				'type' => 'checkbox',
			)
		);
		$cmb_options->add_field(
			array(
				'name' => __( 'Import products', 'edi' ),
				'id'   => 'import_products',
				'type' => 'checkbox',
			)
		);
		$cmb_options->add_field(
			array(
				'name' => __( 'Import attributes', 'edi' ),
				'id'   => 'import_attributes',
				'type' => 'checkbox',
			)
		);
		$cmb_options->add_field(
			array(
				'name' => __( 'Import images', 'edi' ),
				'id'   => 'import_images',
				'type' => 'checkbox',
			)
		);

		$cmb_options->add_field(
			array(
				'name' => __( 'Sale settings', 'edi' ),
				'id'   => 'sale_settings',
				'type' => 'title',
			)
		);
		$cmb_options->add_field(
			array(
				'name' => __( 'Import orders', 'edi' ),
				'id'   => 'import_orders',
				'type' => 'checkbox',
			)
		);
		$cmb_options->add_field(
			array(
				'name' => __( 'Export orders', 'edi' ),
				'id'   => 'export_orders',
				'type' => 'checkbox',
			)
		);
		$cmb_options->add_field(
			array(
				'name' => __( 'Export orders starting from', 'edi' ),
				'id'   => 'export_from_timestamp',
				'type' => 'text_date_timestamp',
			)
		);

		$cmb_options->add_field(
			array(
				'name' => __( 'Advanced settings', 'edi' ),
				'id'   => 'expert_settings',
				'type' => 'title',
				'desc' => __(
					'🛑 Please do not change these settings unless you are sure what you are doing!',
					'edi'
				),
			)
		);
		$cmb_options->add_field(
			array(
				'name'    => __( 'Status indicator', 'edi' ),
				'id'      => 'status_indicator',
				'type'    => 'select',
				'default' => 'settings_page',
				'options' => array(
					'disable'       => __( 'Disable', 'edi' ),
					'settings_page' => __( 'Settings page', 'edi' ),
					'admin_area'    => __( 'Admin area', 'edi' ),
				),
			)
		);
		$cmb_options->add_field(
			array(
				'name'            => __( 'Import chunk size (in bytes)', 'edi' ),
				'desc'            => __( 'The maximum allowed file size to transfer per request.', 'edi' ),
				'id'              => 'import_chunk_size',
				'type'            => 'text_small',
				'attributes'      => array(
					'type' => 'number',
					'min'  => '0',
				),
				'default'         => 1000000,
				'sanitization_cb' => array( $this, 'sanitize_import_chunk_size' ),
			)
		);
		$cmb_options->add_field(
			array(
				'name'    => __( 'Logging level', 'edi' ),
				'id'      => 'logging_level',
				'type'    => 'select',
				'desc'    => sprintf(
					'<a href="%s" target="_blank">%s</a> <a href="%s" target="_blank">%s</a>',
					esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ),
					__( 'View log', 'edi' ),
					esc_url(
						str_replace(
							ABSPATH,
							site_url( '/' ),
							WC_Log_Handler_File::get_log_file_path( 'edi' )
						)
					),
					__( 'Download last log', 'edi' )
				),
				'default' => 'notice',
				'options' => array(
					'error'   => 'ERROR',
					'warning' => 'WARNING',
					'notice'  => 'NOTICE',
					'info'    => 'INFO',
					'debug'   => 'DEBUG',
				),
			)
		);
	}

	/**
	 * Get option value magic method.
	 *
	 * @param string $name Getter functions name.
	 * @param array $arguments Functions arguments.
	 *
	 * @return mixed
	 *
	 * @throws BadMethodCallException Exception if not a getter function.
	 */
	public static function __callStatic( string $name, array $arguments ) {
		if ( 0 !== strpos( $name, 'get_' ) ) {
			throw new BadMethodCallException( $name . ' is not defined in ' . __CLASS__ );
		}

		$options = shortcode_atts(
			array(
				'site_url'              => site_url( '/edi/1c' ),
				'username'              => '',
				'password'              => '',
				'import_categories'     => '',
				'import_products'       => '',
				'import_attributes'     => '',
				'import_images'         => '',
				'sale_enable'           => true,
				'export_orders'         => '',
				'import_orders'         => '',
				'export_from_timestamp' => '',
				'status_indicator'      => 'settings_page',
				'import_chunk_size'     => 1000000,
				'logging_level'         => 'debug',
			),
			(array) get_option( 'edi', array() )
		);

		$option_name = substr( $name, 4 );

		if ( 'options' === $option_name ) {
			return $options;
		} elseif ( isset( $options[ $option_name ] ) ) {
			return $options[ $option_name ];
		} else {
			throw new BadMethodCallException( $option_name . ' is not defined in ' . __CLASS__ );
		}
	}

	/**
	 * Loads all necessary admin bar items.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance, passed by reference.
	 */
	public function admin_bar_render( WP_Admin_Bar $wp_admin_bar ): void {
		if ( $this->is_status_indicator_visible() ) {
			$wp_admin_bar->add_menu(
				array(
					'parent' => '',
					'id'     => 'edi-status',
					'title'  => '',
				)
			);

			$wp_admin_bar->add_menu(
				array(
					'parent' => '',
					'id'     => 'edi-interrupt',
					'title'  => __( 'Interrupt', 'edi' ),
				)
			);
		}
	}

	/**
	 * Enqueue a script in the WordPress admin.
	 *
	 * @return void
	 */
	public function enqueue_admin_script(): void {
		if ( $this->is_status_indicator_visible() ) {
			wp_enqueue_style(
				'edi-admin',
				plugin_dir_url( EDI_PLUGIN_FILE ) . 'assets/css/edi-admin.css',
				array(),
				EDI::VERSION
			);
			wp_enqueue_script(
				'edi-admin',
				plugin_dir_url( EDI_PLUGIN_FILE ) . 'assets/js/edi-admin.js',
				array( 'jquery' ),
				EDI::VERSION,
				true
			);
			wp_localize_script(
				'edi-admin',
				'ediAdmin',
				array(
					'nonce'   => wp_create_nonce( 'edi-interrupt' ),
					'ajaxUrl' => admin_url( '/admin-ajax.php' ),
				)
			);
		}
	}

	/**
	 * Should we show status indicator?
	 *
	 * @return bool
	 */
	protected function is_status_indicator_visible(): bool {
		global $current_screen;

		return (
			'admin_area' === self::get_status_indicator()
			||
			(
				'settings_page' === self::get_status_indicator()
				&&
				'woocommerce_page_edi' === $current_screen->id
			)
		);
	}

	/**
	 * Sanitize import chunk size value.
	 *
	 * @param string $value Import chunk size.
	 *
	 * @return int
	 */
	public function sanitize_import_chunk_size( string $value ): int {
		return absint( $value );
	}
}
