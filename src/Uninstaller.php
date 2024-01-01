<?php
/**
 * Class Uninstaller
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use Exception;

/**
 * Class Uninstaller
 *
 * @package BytePerfect\EDI
 */
class Uninstaller {
	/**
	 * Uninstall hook.
	 *
	 * @return void
	 */
	public static function run(): void {
		delete_option( 'edi' );
		delete_option( '_edi_statistics_products' );
		delete_option( '_edi_statistics_offers' );

		try {
			EDI::tracker()->track( 'uninstall' );
		} catch ( Exception $e ) {
		}
	}
}
