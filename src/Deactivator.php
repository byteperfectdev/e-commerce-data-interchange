<?php
/**
 * Class Deactivator
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use Exception;

/**
 * Class Deactivator
 *
 * @package BytePerfect\EDI
 */
class Deactivator {
	/**
	 * Deactivation hook.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public static function run(): void {
		$request = new Request();
		$request->reset();

		try {
			EDI::tracker()->track( 'deactivate' );
		} catch ( Exception $e ) {
		}
	}
}
