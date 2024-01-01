<?php
/**
 * Class Tracker
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use WP_Error;

/**
 * Class Tracker
 *
 * @package BytePerfect\EDI
 */
class Tracker {
	/**
	 * Tracker URL.
	 *
	 * @var string
	 */
	private string $tracker_url = '';

	/**
	 * Site URL.
	 *
	 * @var string
	 */
	private string $site_url = '';

	/**
	 * Tracker constructor.
	 */
	public function __construct( string $tracker_url, string $site_url ) {
		if ( filter_var( $tracker_url, FILTER_VALIDATE_URL ) ) {
			$this->tracker_url = $tracker_url;
		} else {
			EDI::log()->warning(
				sprintf( __( 'Tracker URL is not correct: %s' ), $tracker_url )
			);
		}

		if ( filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
			$this->site_url = $site_url;
		} else {
			EDI::log()->warning(
				sprintf( __( 'Tracker site URL is not correct: %s' ), $site_url )
			);
		}
	}

	/**
	 * Check if tracker is initialized.
	 *
	 * @return bool
	 */
	protected function is_initialized(): bool {
		return $this->tracker_url && $this->site_url;
	}

	/**
	 * Track data.
	 *
	 * @param string $action Action name.
	 * @param string $data Data.
	 *
	 * @return array|WP_Error
	 */
	public function track( string $action, string $data = '' ) {
		if ( ! $this->is_initialized() ) {
			$message = __( 'Tracker is not initialized.', 'edi' );

			EDI::log()->warning( $message );

			return new WP_Error( 500, $message );
		}

		$actions = array( 'activate', 'deactivate', 'synchronize', 'uninstall', 'update' );
		if ( ! in_array( $action, $actions, true ) ) {
			$message = sprintf(
				__( 'Expected tracking action one of: %2$s. Got: %s', 'edi' ),
				$action,
				implode( ', ', $actions )
			);

			EDI::log()->warning( $message );

			return new WP_Error( 500, $message );
		}

		$post_args = array(
			'headers'   => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
			'body'      => json_encode( array(
				'site_url' => $this->site_url,
				'action'   => $action,
				'version'  => EDI::VERSION,
				'data'     => $data,
			) ),
		);

		$response = wp_remote_post( $this->tracker_url, $post_args );

		return $response;
	}
}
