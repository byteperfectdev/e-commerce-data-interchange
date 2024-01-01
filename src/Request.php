<?php
/**
 * Class Request
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use Exception;
use Throwable;

/**
 * Class Request
 *
 * @property string $previous_mode
 * @property string $previous_type
 * @property string $previous_filename
 * @property string $mode
 * @property string $type
 * @property string $filename
 * @property array<int, int|string> $last_xml_entry;
 *
 * @package BytePerfect\EDI
 */
class Request {
	const EDI_ENDPOINT = '/edi/1c';

	const XML_IMPORT_FILENAME_MASK = '/^(?:import|offers).*\.xml$/';

	const ZIP_IMPORT_FILENAME_MASK = '/^v8_.+\.zip$/';

	const SALE_IMPORT_FILENAME_MASK = '/^orders-[a-f0-9-]*_?.\.xml$/';

	const CLEAR_REPOSITORY = true;
	const DO_NOT_CLEAR_REPOSITORY = false;

	/**
	 * Previous request mode.
	 *
	 * @var string
	 */
	protected string $previous_mode = '';

	/**
	 * Previous request type.
	 *
	 * @var string
	 */
	protected string $previous_type = '';

	/**
	 * Previous request filename.
	 *
	 * @var string
	 */
	protected string $previous_filename = '';

	/**
	 * Request mode.
	 *
	 * @var string
	 */
	protected string $mode = '';

	/**
	 * Request type.
	 *
	 * @var string
	 */
	protected string $type = '';

	/**
	 * Request filename.
	 *
	 * @var string
	 */
	protected string $filename = '';

	/**
	 * Last XML entry.
	 *
	 * @var array<int, int|string>
	 */
	protected array $last_xml_entry;

	/**
	 * Request constructor.
	 *
	 * @throws Exception Exception.
	 */
	public function __construct() {
		$this->previous_mode     = strval( get_option( '_edi_mode', '' ) );
		$this->previous_type     = strval( get_option( '_edi_type', '' ) );
		$this->previous_filename = strval( get_option( '_edi_filename', '' ) );

		if ( $this->is_edi_request() ) {
			$this->set_mode();
			$this->set_type();
			$this->set_filename();
			$this->set_last_xml_entry();

			add_action( 'wp_loaded', array( $this, 'process_request' ), PHP_INT_MAX );
		}

		add_action( 'wp_ajax_edi_get_status', array( $this, 'get_status' ) );
		add_action( 'wp_ajax_edi_interrupt', array( $this, 'interrupt' ) );

//		add_action( 'edi_product_before_save', array( $this, 'collect_products_data_for_statistics' ) );
//		add_action( 'wp_ajax_edi_finish', array( $this, 'send_statistics_data' ) );
//		add_action( 'wp_ajax_nopriv_edi_finish', array( $this, 'send_statistics_data' ) );
	}

	/**
	 * Set session mode.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function set_mode(): void {
		$this->mode = isset( $_REQUEST['mode'] ) ? sanitize_text_field( $_REQUEST['mode'] ) : '';
		if (
			! in_array(
				$this->mode,
				array( 'checkauth', 'init', 'file', 'import', 'query', 'success' ),
				true
			) ) {
			throw new Exception( 'Unexpected mode: ' . $this->mode );
		}

		update_option( '_edi_mode', $this->mode, false );
	}

	/**
	 * Set session type.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function set_type(): void {
		$this->type = isset( $_REQUEST['type'] ) ? sanitize_text_field( $_REQUEST['type'] ) : '';
		if ( ! in_array( $this->type, array( 'catalog', 'sale' ), true ) ) {
			throw new Exception( 'Unexpected type: ' . $this->type );
		}

		update_option( '_edi_type', $this->type, false );
	}

	/**
	 * Set session filename.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function set_filename(): void {
		$this->filename = isset( $_REQUEST['filename'] ) ? sanitize_text_field( $_REQUEST['filename'] ) : '';
		if (
			! empty( $this->filename )
			&&
			! preg_match( self::XML_IMPORT_FILENAME_MASK, $this->filename )
			&&
			! preg_match( self::ZIP_IMPORT_FILENAME_MASK, $this->filename )
			&&
			! preg_match( self::SALE_IMPORT_FILENAME_MASK, $this->filename )
		) {
			throw new Exception( 'Unexpected file name: ' . $this->filename );
		}

		update_option( '_edi_filename', $this->filename, false );
	}

	/**
	 * Set last XML entry.
	 *
	 * @throws Exception Exception.
	 */
	protected function set_last_xml_entry(): void {
		try {
			// @todo w8: Нужно добавить проверку.
			$this->last_xml_entry = get_option( '_edi_last_xml_entry', array() );
		} catch ( Throwable $e ) {
			throw new Exception( __( 'Unexpected XML entry.', 'edi' ) );
		}
	}

	/**
	 * Update last XML entry.
	 *
	 * @param array<int, int|string> $last_xml_entry Last XML entry.
	 *
	 * @return void
	 */
	public function update_last_xml_entry( array $last_xml_entry ): void {
		$this->last_xml_entry = $last_xml_entry;

		update_option( '_edi_last_xml_entry', $this->last_xml_entry );
	}

	/**
	 * Makes private properties readable.
	 *
	 * @param string $name Property name.
	 *
	 * @return string|array
	 *
	 * @throws Exception Exception.
	 */
	public function __get( string $name ) {
		if ( property_exists( $this, $name ) ) {
			return $this->{$name};
		}

		/* translators: %s: property name. */
		throw new Exception( sprintf( __( 'Undefined property: %s', 'edi' ), $name ) );
	}

	/**
	 * Check if current request is addressed to EDI.
	 *
	 * @return bool
	 */
	protected function is_edi_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return self::EDI_ENDPOINT === wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	}

	/**
	 * Process interchange request.
	 *
	 * @return void
	 */
	public function process_request(): void {
		try {
			$this->maybe_interrupt();

			$this->authorize();

			$interchange = __NAMESPACE__ . '\\' . ucfirst( $this->type ) . 'Interchange';
			$interchange = new $interchange( $this );
			$interchange->run();
		} catch ( Exception $e ) {
			EDI::log()->error( $e->getMessage() );
			exit( 'failure' );
		}
	}

	/**
	 * Check interrupting.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function maybe_interrupt(): void {
		if ( get_transient( 'edi-interrupt' ) ) {
			$this->reset();

			throw new Exception( __( 'Synchronization was interrupted on the site side.', 'edi' ) );
		}
	}

	/**
	 * Authorize request.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function authorize(): void {
		// Bypass authorization if WP_CLI request.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$username    = Settings::get_username();
		$password    = Settings::get_password();
		$username_1c = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_text_field( $_SERVER['PHP_AUTH_USER'] ) : '';
		$password_1c = isset( $_SERVER['PHP_AUTH_PW'] ) ? sanitize_text_field( $_SERVER['PHP_AUTH_PW'] ) : '';

		if ( empty( $username ) ) {
			throw new Exception( 'Empty username on site.' );
		}

		if ( empty( $password ) ) {
			throw new Exception( 'Empty password on site.' );
		}

		if ( empty( $username_1c ) ) {
			throw new Exception( 'Empty username' );
		}

		if ( empty( $password_1c ) ) {
			throw new Exception( 'Empty password.' );
		}

		if ( $username !== $username_1c || $password !== $password_1c ) {
			throw new Exception( 'Wrong username or password.' );
		}
	}

	/**
	 * Get synchronization process status.
	 *
	 * @return void
	 */
	public function get_status(): void {
		$status = array();
		if ( 'catalog' === $this->previous_type ) {
			$status[] = __( 'Products synchronization', 'edi' );
		} elseif ( 'sale' === $this->previous_type ) {
			$status[] = __( 'Orders synchronization', 'edi' );
		}

		if ( 'checkauth' === $this->previous_mode ) {
			// $status[] = __( 'Authorization', 'edi' );
			$status = array();
		} elseif ( 'init' === $this->previous_mode ) {
			$status[] = __( 'Initialization', 'edi' );
		} elseif ( 'file' === $this->previous_mode ) {
			$status[] = __( 'Getting the import file', 'edi' );
		} elseif ( 'import' === $this->previous_mode ) {
			$status[] = __( 'Import', 'edi' );
		} elseif ( 'query' === $this->previous_mode ) {
			$status[] = __( 'Export orders', 'edi' );
		}
		$status = implode( ' > ', $status );

		if ( get_transient( 'edi-interrupt' ) ) {
			$status       = __( 'Interrupting the import process...', 'edi' );
			$interrupting = true;
		} else {
			$interrupting = false;
		}

		wp_send_json_success( compact( 'status', 'interrupting' ) );
	}

	/**
	 * Interrupt synchronization.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function interrupt(): void {
		if ( check_admin_referer( 'edi-interrupt' ) ) {
			set_transient( 'edi-interrupt', true, 60 );

			$this->reset();

			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * Reset request state.
	 *
	 * @return void
	 * @throws Exception Exception.
	 */
	public function reset( $clear_repository = self::CLEAR_REPOSITORY ) {
		update_option( '_edi_mode', '', false );
		update_option( '_edi_type', '', false );
		update_option( '_edi_filename', '', false );
		update_option( '_edi_last_xml_entry', array() );

		if ( $clear_repository ) {
			EDI::repository()->clear();
		}
	}

	/**
	 * Converting the object to a string.
	 *
	 * @return string
	 */
	public function __toString() {
		$string = sprintf(
			'%-11s %-10s %-8s %s' . PHP_EOL,
			'',
			'[mode]',
			'[type]',
			'[filename]'
		);
		$string .= sprintf(
			'%-11s %-10s %-8s %s' . PHP_EOL,
			'[current]',
			$this->mode,
			$this->type,
			$this->filename
		);
		$string .= sprintf(
			'%-11s %-10s %-8s %s',
			'[previous]',
			$this->previous_mode,
			$this->previous_type,
			$this->previous_filename
		);

		return $string;
	}

	/**
	 * Collect products data for statistics.
	 *
	 * @return void
	 */
	public function collect_products_data_for_statistics(): void {
		$count = (int) get_option( '_edi_statistics_products', 0 );

		$count ++;

		update_option( '_edi_statistics_products', $count, false );
	}

	/**
	 * Send statistics data.
	 *
	 * @throws Exception
	 */
	public function send_statistics_data(): void {
		check_ajax_referer( 'edi_finish' );

		if ( isset( $_REQUEST['phase'] ) && 'import' === $_REQUEST['phase'] ) {
			$count = (string) get_option( '_edi_statistics_products', 0 );

			EDI::tracker()->track( 'synchronize', $count );

			delete_option( '_edi_statistics_products' );
		}
	}
}
