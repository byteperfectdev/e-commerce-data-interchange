<?php
/**
 * Class CatalogInterchange
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use Exception;

/**
 * Class CatalogInterchange
 *
 * @package BytePerfect\EDI
 */
class CatalogInterchange extends AbstractInterchange {
	/**
	 * Do phase 'import'
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function action_import(): void {
		if ( '.zip' === substr( $this->request->previous_filename, - 4 ) ) {
			EDI::log()->debug(
			/* translators: %s: file name. */
				sprintf( __( 'Unpacking %s ...', 'edi' ), $this->request->previous_filename )
			);

			$this->action_import_unpack();
		} else {
			EDI::log()->debug(
			/* translators: %s: file name. */
				sprintf( __( 'Processing %s ...', 'edi' ), $this->request->filename )
			);

			$this->action_import_parse();
		}
	}

	/**
	 * Unpack imported file.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 *
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	protected function action_import_unpack(): void {
		EDI::filesystem()->unzip_file( $this->request->previous_filename, '' );

		EDI::log()->info( wp_json_encode( EDI::filesystem()->get_list_except_system_files() ) );

		$message = 'progress';

		EDI::log()->info( '🔙' . $message );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( $message );
	}

	/**
	 * Processing imported file.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 *
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	protected function action_import_parse(): void {
		if ( 0 === strpos( $this->request->filename, 'import' ) ) {
			$phase = 'import';

			$parser_class_name = '\BytePerfect\EDI\Parsers\ImportXMLParser';
		} elseif ( 0 === strpos( $this->request->filename, 'offers' ) ) {
			$phase = 'offers';

			$parser_class_name = '\BytePerfect\EDI\Parsers\OffersXMLParser';
		} else {
			throw new Exception(
			/* translators: %s: file name. */
				sprintf( __( 'Unexpected file name: %s.', 'edi' ), $this->request->filename )
			);
		}

		$parser = new $parser_class_name( $this->request );
		if ( $parser->parse() ) {
			// Файл импортирован полностью.

			$this->send_finish_signal( $phase );

			$this->request->reset( Request::DO_NOT_CLEAR_REPOSITORY );

			$message = 'success';
		} else {
			// Файл импортирован частично.

			$message = 'progress';
		}

		EDI::log()->info( '🔙' . $message );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( $message );
	}

	/**
	 * Send finish signal.
	 *
	 * @param string $phase 'import' or 'offers'.
	 *
	 * @return void
	 */
	protected function send_finish_signal( string $phase ): void {
		$post_args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
			'body'      => array(
				'action'      => 'edi_finish',
				'type'        => $this->request->type,
				'filename'    => $this->request->filename,
				'phase'       => $phase,
				'_ajax_nonce' => wp_create_nonce( 'edi_finish' ),
			),
		);

		// @todo w8: Реализовать проверку ошибок.
		wp_remote_post( admin_url( 'admin-ajax.php' ), $post_args );
	}
}
