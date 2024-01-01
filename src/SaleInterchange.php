<?php
/**
 * Class SaleInterchange
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use BytePerfect\EDI\Parsers\OrdersXMLParser;
use Exception;

/**
 * Class SaleInterchange
 *
 * @package BytePerfect\EDI
 */
class SaleInterchange extends AbstractInterchange {
	/**
	 * Run interchange session.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function run(): void {
		if ( ! Settings::get_sale_enable() ) {
			return;
		}

		parent::run();
	}

	/**
	 * Do phase 'query'
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function action_query(): void {
		$order_query = new OrderQuery();
		$order_query->output_as_xml();

		$response = new Response();
		$response->send();
	}

	/**
	 * Do phase 'success'
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function action_success(): void {
		$this->request->reset();

		$response = new Response( 'success' );
		$response->send();
	}

	/**
	 * Do phase 'file'
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function action_file(): void {
		EDI::filesystem()->receive_file( $this->request->filename );

		$response = new Response( 'success' );
		$response->send( false );

		$this->process_file( $this->request->filename );
	}

	/**
	 * Do phase 'import'
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function action_import(): void {
		if ( '.zip' === substr( $this->request->filename, - 4 ) ) {
			$this->action_import_unpack();
		} else {
			$this->action_import_parse();
		}
	}

	/**
	 * Unpack imported file.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function action_import_unpack(): void {
		EDI::filesystem()->unzip_file( $this->request->previous_filename, '' );

		$message = sprintf(
		/* translators: %s: archive file name. */
			__( '%s was unpacked.', 'edi' ),
			$this->request->previous_filename
		);
		EDI::log()->info( $message );

		$file_list = EDI::filesystem()->get_list_except_system_files();
		if ( 1 === count( $file_list ) && preg_match( Request::SALE_IMPORT_FILENAME_MASK, $file_list[0] ) ) {
			$this->process_file( $file_list[0] );
		} else {
			EDI::log()->warning(
				__( 'Unexpected contents of the import directory.', 'edi' ) .
				PHP_EOL .
				wc_print_r( $file_list, true )
			);
		}
	}

	/**
	 * Processing imported file.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function action_import_parse(): void {
		EDI::log()->debug(
		/* translators: %s: file name. */
			sprintf( __( 'Processing %s ...', 'edi' ), $this->request->filename )
		);

		$parser = new OrdersXMLParser( $this->request );
		if ( $parser->parse() ) {
			EDI::log()->debug(
			/* translators: %s: file name. */
				sprintf( __( '%s was processed successfully.', 'edi' ), $this->request->filename )
			);

			// Файл импортирован полностью.
			$this->request->reset( Request::DO_NOT_CLEAR_REPOSITORY );
		} else {
			// Файл импортирован частично.
			$this->process_file( $this->request->filename );
		}
	}

	/**
	 * Dispatch the async request.
	 *
	 * @param string $filename File name to process in background.
	 *
	 * @return void
	 */
	protected function process_file( string $filename ): void {
		$username = (string) Settings::get_username();
		$password = (string) Settings::get_password();

		$query_args = array(
			'mode'     => 'import',
			'type'     => $this->request->type,
			'filename' => $filename,
		);

		$post_args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
			'headers'   => array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Authorization' => 'Basic ' . base64_encode( "$username:$password" ),
			),
		);

		// @todo w8: Реализовать проверку ошибок.
		wp_remote_post( add_query_arg( $query_args, site_url( Request::EDI_ENDPOINT ) ), $post_args );

		$response = new Response();
		$response->send();
	}
}
