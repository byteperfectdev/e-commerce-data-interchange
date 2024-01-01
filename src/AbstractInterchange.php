<?php
/**
 * Class AbstractInterchange
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use Exception;

/**
 * Class AbstractInterchange
 *
 * @package BytePerfect\EDI
 */
abstract class AbstractInterchange {
	/**
	 * Request.
	 *
	 * @var Request
	 */
	protected Request $request;

	/**
	 * Interchange constructor.
	 *
	 * @param Request $request Request.
	 */
	public function __construct( Request $request ) {
		$this->request = $request;
	}

	/**
	 * Run interchange session.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function run(): void {
		EDI::log()->notice(
			__( 'Running interchange...', 'edi' ) . PHP_EOL . $this->request
		);

		$callback = array( $this, 'action_' . $this->request->mode );
		if ( is_callable( $callback ) ) {
			call_user_func( $callback );
		} else {
			throw new Exception(
			/* translators: %s: request mode. */
				sprintf( __( 'Mode is not supported: %s', 'edi' ), $this->request->mode )
			);
		}
	}

	/**
	 * Do phase 'checkauth'
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	protected function action_checkauth(): void {
		$message = "success\nedi\n" . time();

		EDI::log()->info( '🔙' . $message );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( $message );
	}

	/**
	 * Do phase 'init'
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 *
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	protected function action_init(): void {
		$this->request->reset();

		// WordPress should be able to unpack archives.
		$message = sprintf(
			"zip=yes\nfile_limit=%d",
			Utils::get_file_limit()
		);

		EDI::log()->info( '🔙' . $message );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( $message );
	}

	/**
	 * Do phase 'file'
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 *
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	protected function action_file(): void {
		EDI::filesystem()->receive_file( $this->request->filename );

		$message = 'success';

		EDI::log()->info( '🔙' . $message );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( $message );
	}

	/**
	 * Do phase 'import'
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	abstract protected function action_import(): void;

	/**
	 * Unpack imported file.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	abstract protected function action_import_unpack(): void;

	/**
	 * Processing imported file.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	abstract protected function action_import_parse(): void;
}
