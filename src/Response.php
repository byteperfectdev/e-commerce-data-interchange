<?php declare( strict_types=1 );
/**
 * Class Response
 *
 * @package BytePerfect\EDI
 */

namespace BytePerfect\EDI;

/**
 * Class Response
 *
 * @package BytePerfect\EDI
 */
class Response {
	/**
	 * Response type.
	 *
	 * @var string
	 */
	protected string $type;

	/**
	 * Response message.
	 *
	 * @var string
	 */
	protected string $message;

	/**
	 * Response constructor.
	 *
	 * @param string $type Response type.
	 * @param string $message Response message.
	 */
	public function __construct( string $type = '', string $message = '' ) {
		$this->set_type( $type );
		$this->set_message( $message );
	}

	/**
	 * Send response.
	 *
	 * @param bool $exit Should we exit after send response?
	 *
	 * @return void
	 */
	public function send( bool $exit = true ): void {
		$message = $this->message;
		if ( $this->type ) {
			$message = "$this->type\n$message";
		}

		if ( 'failure' === $this->type || 'debug' === Settings::get_logging_level() ) {
			EDI::log()->notice( '🔙' . $message );
		}

		echo wp_kses_post( $message );

		if ( $exit ) {
			exit();
		}
	}

	/**
	 * Set response type.
	 *
	 * @param string $type Response type.
	 *
	 * @return void
	 */
	protected function set_type( string $type ): void {
		$this->type = $type;

		if ( ! in_array( $this->type, array( 'success', 'failure', 'progress' ), true ) ) {
			$this->type = '';
		}
	}

	/**
	 * Set response message.
	 *
	 * @param string $message Response message.
	 *
	 * @return void
	 */
	protected function set_message( string $message ): void {
		$this->message = $message;
	}
}
