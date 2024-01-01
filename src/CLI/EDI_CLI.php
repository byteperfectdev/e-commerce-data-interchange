<?php declare( strict_types=1 );
/**
 * Enables EDI via the command line.
 *
 * Class EDI_CLI
 *
 * @package BytePerfect\EDI\CLI
 */

namespace BytePerfect\EDI\CLI;

use BytePerfect\EDI\Request;
use Exception;
use WP_CLI;

/**
 * Class EDI_CLI
 *
 * @package BytePerfect\EDI\CLI
 */
class EDI_CLI {
	/**
	 * EDI_CLI constructor.
	 *
	 * @throws Exception
	 */
	public function __construct() {
		global $argv;

		$command    = $argv[1] ?? '';
		$subcommand = $argv[2] ?? '';

		if ( 'edi' === $command ) {
			$callback = array( $this, 'subcommand_' . $subcommand );
			if ( is_callable( $callback ) ) {
				$_SERVER['REQUEST_URI'] = Request::EDI_ENDPOINT;

				call_user_func( $callback );
			} else {
				WP_CLI::error(
					sprintf(
						"'%s' is not a registered subcommand of '%s'. See 'wp help %s' for available subcommands.",
						$subcommand,
						$command,
						$command
					)
				);
			}
		}

		WP_CLI::add_command( 'edi checkauth', array( $this, 'dummy_function' ) );
		WP_CLI::add_command( 'edi init', array( $this, 'dummy_function' ) );
		WP_CLI::add_command( 'edi import', array( $this, 'dummy_function' ) );
	}

	public function dummy_function() {
	}

	protected function subcommand_checkauth() {
		$_REQUEST['mode']     = 'checkauth';
		$_REQUEST['type']     = 'catalog';
		$_REQUEST['filename'] = '';
	}

	protected function subcommand_init() {
		$_REQUEST['mode']     = 'init';
		$_REQUEST['type']     = 'catalog';
		$_REQUEST['filename'] = '';
	}

	protected function subcommand_import() {
		global $argv;

		$file_name         = $argv[3] ?? '';
		$previous_filename = $argv[4] ?? '';

		$_REQUEST['mode']     = 'import';
		$_REQUEST['type']     = 'catalog';
		$_REQUEST['filename'] = $file_name;

		if ( $previous_filename ) {
			update_option( '_edi_filename', sanitize_text_field( $previous_filename ), false );
		}
	}
}
