<?php
/**
 * Class LogHandlerFile
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use WC_Log_Handler_File;

/**
 * Class LogHandlerFile
 *
 * @package BytePerfect\EDI
 */
class LogHandlerFile extends WC_Log_Handler_File {
	/**
	 * Handle a log entry.
	 *
	 * @param int $timestamp Log timestamp.
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug.
	 * @param string $message Log message.
	 * @param array $context {
	 *      Additional information for log handlers.
	 *
	 * @type string $source Optional. Determines log file to write to. Default 'log'.
	 * @type bool $_legacy Optional. Default false. True to use outdated log format
	 *         originally used in deprecated WC_Logger::add calls.
	 * }
	 *
	 * @return bool False if value was not handled and true if value was handled.
	 */
	public function handle( $timestamp, $level, $message, $context ): bool {
		$timestamp = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$handle    = 'edi';

		$entry = self::format_entry( $timestamp, $level, $message, $context );

		return $this->add( $entry, $handle );
	}
}
