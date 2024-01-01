<?php
/**
 * Class Updater
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

namespace BytePerfect\EDI;

use Exception;
use WP_Upgrader;

/**
 * Class Updater
 *
 * @package BytePerfect\TopWebVitals
 */
final class Updater {
	/**
	 * Update hook.
	 *
	 * @param WP_Upgrader $upgrader WP_Upgrader instance.
	 * @param array $options Upgrader options.
	 *
	 * @return void
	 */
	public static function run( WP_Upgrader $upgrader, array $options ): void {
		if (
			isset( $options['action'] ) && 'update' === $options['action']
			&&
			isset( $options['type'] ) && 'plugin' === $options['type']
			&&
			isset( $options['plugins'] ) && is_array( $options['plugins'] )
			&&
			plugin_basename( EDI_PLUGIN_FILE ) === $options['plugins'][0]
		) {
			try {
				EDI::tracker()->track( 'update' );
			} catch ( Exception $e ) {
			}
		}
	}
}
