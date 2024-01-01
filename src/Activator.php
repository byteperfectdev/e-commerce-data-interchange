<?php
/**
 * Class Activator
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use Exception;

/**
 * Class Activator
 *
 * @package BytePerfect\EDI
 */
class Activator {
	/**
	 * Activation hook.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public static function run(): void {
		$request = new Request();
		$request->reset();

		try {
			EDI::tracker()->track( 'activate' );
		} catch ( Exception $e ) {
		}
	}
}
