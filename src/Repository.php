<?php
/**
 * Class Repository
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use Exception;

/**
 * Class Repository
 *
 * @package BytePerfect\EDI
 */
class Repository {
	/**
	 * Clear repository.
	 *
	 * @throws Exception Exception.
	 */
	public function clear(): void {
		$this->destroy();
		$this->create();
	}

	/**
	 * Create repository.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function create(): void {
		EDI::filesystem()->mkdir();

		EDI::filesystem()->file_put_contents( '.htaccess', 'Deny from all' );
		EDI::filesystem()->file_put_contents( 'index.html', '' );
	}

	/**
	 * Deactivate repository.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function destroy(): void {
		EDI::filesystem()->rmdir();
	}
}
