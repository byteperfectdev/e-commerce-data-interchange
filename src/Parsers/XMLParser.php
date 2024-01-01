<?php declare( strict_types=1 );
/**
 * Class XMLParser
 *
 * @package BytePerfect\EDI
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType
// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Request;
use Exception;

/**
 * Class XMLParser
 *
 * @package BytePerfect\EDI
 */
class XMLParser {
	/**
	 * List of registered parsers.
	 *
	 * @var array
	 */
	protected array $parsers = array();

	/**
	 * Request.
	 *
	 * @var Request
	 */
	protected Request $request;

	/**
	 * Parsing start time.
	 *
	 * @var int
	 */
	protected int $start_time;

	/**
	 * XML file handler.
	 *
	 * @var resource
	 */
	protected $file_handler;

	/**
	 * XML file charset.
	 *
	 * @var string
	 */
	protected string $file_charset = 'UTF-8';

	/**
	 * XML file read position.
	 *
	 * @var int
	 */
	protected int $file_position = 0;

	/**
	 * XML position.
	 *
	 * @var string
	 */
	protected string $xml_position = '';

	/**
	 * Position stack.
	 *
	 * @var array<integer, integer>
	 */
	protected array $position_stack = array();

	/**
	 * Element stack.
	 *
	 * @var array<integer, string>
	 */
	protected array $element_stack = array();

	/**
	 * Element handlers.
	 *
	 * @var array<string, array<callable>>
	 */
	protected array $element_handlers = array();

	/**
	 * End of file.
	 *
	 * @var bool
	 */
	protected bool $eof = false;

	/**
	 * XMLParser constructor.
	 *
	 * @param Request $request Request.
	 *
	 * @throws Exception Exception.
	 */
	public function __construct( Request $request ) {
		$this->request = $request;

		$this->start_time = time();

		$this->file_handler = EDI::filesystem()->fopen( $request->filename, 'rb' );
	}

	/**
	 * Parse XML file.
	 *
	 * @return bool
	 *
	 * @throws Exception Exception.
	 */
	public function parse(): bool {
		// Parsers are not registered. Short circuit.
		if ( empty( $this->parsers ) ) {
			return true;
		}

		set_time_limit( 0 );

		$this->set_position( $this->request->last_xml_entry );

		do {
			$this->find_next();
		} while (
			! $this->eof
			&&
			( ( defined( 'WP_CLI' ) && WP_CLI ) || ( ! $this->time_exceeded() && ! $this->memory_exceeded() ) )
		);

		$this->request->update_last_xml_entry( $this->get_position() );

		return $this->eof;
	}

	/**
	 * Memory exceeded
	 *
	 * Ensures the batch process never exceeds 90%
	 * of the maximum WordPress memory.
	 *
	 * @return bool
	 */
	protected function memory_exceeded(): bool {
		$memory_limit   = $this->get_memory_limit() * 0.9; // 90% of max memory
		$current_memory = memory_get_usage( true );

		return ( $current_memory >= $memory_limit );
	}

	/**
	 * Get memory limit
	 *
	 * @return int
	 */
	protected function get_memory_limit(): int {
		$kb_in_bytes = 1024;
		$mb_in_bytes = 1024 * $kb_in_bytes;
		$gb_in_bytes = 1024 * $mb_in_bytes;

		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			// Sensible default.
			$memory_limit = '128M';
		}

		if ( ! $memory_limit || - 1 === intval( $memory_limit ) ) {
			// Unlimited, set to 32GB.
			$memory_limit = '32000M';
		}

		$memory_limit = strtolower( trim( $memory_limit ) );
		$bytes        = (int) $memory_limit;

		if ( false !== strpos( $memory_limit, 'g' ) ) {
			$bytes *= $gb_in_bytes;
		} elseif ( false !== strpos( $memory_limit, 'm' ) ) {
			$bytes *= $mb_in_bytes;
		} elseif ( false !== strpos( $memory_limit, 'k' ) ) {
			$bytes *= $kb_in_bytes;
		}

		// Deal with large (float) values which run into the maximum integer size.
		return min( $bytes, PHP_INT_MAX );
	}

	/**
	 * Time exceeded.
	 *
	 * Ensures the batch never exceeds a sensible time limit.
	 * A timeout limit of 30s is common on shared hosting.
	 *
	 * @return bool
	 */
	protected function time_exceeded(): bool {
		$finish = $this->start_time + 20; // 20 seconds

		return ( time() >= $finish );
	}

	/**********************************************************************************************************
	 * XMLFileStreamParser                                                                                    *
	 *********************************************************************************************************/

	/**
	 * Sets the position state returned by getPosition method.
	 *
	 * @param array<int|string> $position XML file position.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function set_position( array $position ): void {
		if ( isset( $position[0] ) ) {
			$this->file_charset = (string) $position[0];
		}
		if ( isset( $position[1] ) ) {
			$this->file_position = (int) $position[1];
		}
		if ( isset( $position[2] ) ) {
			$this->xml_position = (string) $position[2];
		}

		if ( $this->file_position > 0 ) {
			EDI::filesystem()->fseek( $this->file_handler, $this->file_position );
		}

		$this->element_stack  = array();
		$this->position_stack = array();
		foreach ( explode( '/', $this->xml_position ) as $path_part ) {
			/*
			// @todo w8: Поправить ошибку Undefined offset: 1
			list( $element_position, $element_name ) = explode( '@', $path_part, 2 );

			$this->position_stack[] = (int) $element_position;
			$this->element_stack[]  = $element_name;
			*/

			$parts = explode( '@', $path_part, 2 );
			if ( count( $parts ) !== 2 ) {
				EDI::log()->critical( "🔥 XMLParser::set_position [$path_part]" );
			}

			$this->position_stack[] = (int) ( $parts[0] ?? 0 );
			$this->element_stack[]  = (string) ( $parts[1] ?? '' );
		}
	}

	/**
	 * Returns current position state needed to continue file parsing process on the next hit.
	 *
	 * @return array<int|string>
	 */
	protected function get_position(): array {
		$xml_position = array();
		foreach ( $this->element_stack as $i => $element_name ) {
			$xml_position[] = $this->position_stack[ $i ] . '@' . $element_name;
		}
		$this->xml_position = implode( '/', $xml_position );

		return array(
			$this->file_charset,
			$this->file_position,
			$this->xml_position,
		);
	}

	/**
	 * Processes file further.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function find_next(): void {
		$this->eof = false;

		do {
			$xml_chunk = $this->get_xml_chunk();

			if ( is_null( $xml_chunk ) ) {
				break;
			} elseif ( '!' === $xml_chunk[0] || '?' === $xml_chunk[0] ) {
				continue;
			} elseif ( '/' === $xml_chunk[0] ) {
				$this->end_element();

				return;
			} else {
				$this->start_element( $xml_chunk );

				// Check for self-closing tag.
				$position = mb_strpos( $xml_chunk, '>' );
				if ( ( false !== $position ) && ( '/' === mb_substr( $xml_chunk, $position - 1, 1 ) ) ) {
					$this->end_element();

					return;
				}
			}
		} while ( true );

		$this->eof = true;
	}

	/**
	 * Stores an element into xml path stack.
	 *
	 * @param string $xml_chunk XML chunk.
	 *
	 * @return void
	 */
	protected function start_element( string $xml_chunk ): void {
		$position = mb_strpos( $xml_chunk, '>' );
		if ( false === $position ) {
			return;
		}

		if ( '/' === mb_substr( $xml_chunk, $position - 1, 1 ) ) {
			$element_name = mb_substr( $xml_chunk, 0, $position - 1 );
		} else {
			$element_name = mb_substr( $xml_chunk, 0, $position );
		}

		$position = mb_strpos( $element_name, ' ' );
		if ( false === $position ) {
			$element_attrs = '';
		} else {
			$element_attrs = mb_substr( $element_name, $position + 1 );
			$element_name  = mb_substr( $element_name, 0, $position );
		}

		$this->element_stack[]  = $element_name;
		$this->position_stack[] = $this->file_position - mb_strlen( $xml_chunk, 'latin1' );

		$xml_path   = implode( '/', $this->element_stack );
		$attributes = $this->parse_attributes( $element_attrs );
		do_action( $xml_path, $attributes );
	}

	/**
	 * Parse attributes from string.
	 *
	 * @param string $element_attrs Element attributes.
	 *
	 * @return array<string>
	 */
	protected function parse_attributes( string $element_attrs ): array {
		$search = array(
			"'&(quot|#34);'i",
			"'&(lt|#60);'i",
			"'&(gt|#62);'i",
			"'&(amp|#38);'i",
		);

		$replace = array(
			'"',
			'<',
			'>',
			'&',
		);

		$attributes = array();

		if ( '' !== $element_attrs ) {
			preg_match_all( '/(\S+)\s*=\s*["](.*?)["]/s', $element_attrs, $attrs_tmp );
			if ( false === mb_strpos( $element_attrs, '&' ) ) {
				foreach ( $attrs_tmp[1] as $i => $attrs_tmp_1 ) {
					$attributes[ $attrs_tmp_1 ] = $attrs_tmp[2][ $i ];
				}
			} else {
				foreach ( $attrs_tmp[1] as $i => $attrs_tmp_1 ) {
					$attributes[ $attrs_tmp_1 ] = preg_replace(
						$search,
						$replace,
						$attrs_tmp[2][ $i ]
					);
				}
			}
		}

		return $attributes;
	}

	/**
	 * Winds tree stack back. Calls (if necessary) node handlers.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function end_element(): void {
		$element_name     = array_pop( $this->element_stack );
		$element_position = array_pop( $this->position_stack );

		if ( empty( $element_name ) || empty( $element_position ) ) {
			return;
		}

		$xml_path = implode( '/', $this->element_stack ) . '/' . $element_name;
		do_action( $xml_path, null );
		if ( has_action( "_$xml_path" ) ) {
			$xml_object = $this->read_xml( $element_position, $this->file_position );
			do_action( "_$xml_path", $xml_object );
		}
	}

	/**
	 * Used to read a xml by chunks started with "<" and ended with "<"
	 *
	 * @return string|null
	 *
	 * @throws Exception Exception.
	 */
	protected function get_xml_chunk(): ?string {
		/* @var string Буфер для чтения из потока. */
		static $buf = '';
		/* @var int Позиция указателя в буфере. */
		static $buf_position = 0;
		/* @var int Количество считанных в буфер символов. */
		static $buf_len = 0;

		/* @var int Количество байтов для чтения из потока. */
		$read_size = 1024;

		if ( $buf_position >= $buf_len ) {
			if ( ! feof( $this->file_handler ) ) {
				$buf          = EDI::filesystem()->fread( $this->file_handler, $read_size );
				$buf_position = 0;
				$buf_len      = strlen( $buf );
			} else {
				return null;
			}
		}

		// Skip line delimiters (ltrim).
		$xml_position = strpos( $buf, '<', $buf_position );
		while ( $xml_position === $buf_position ) {
			$buf_position ++;
			$this->file_position ++;
			// Buffer ended with white space so we can refill it.
			if ( $buf_position >= $buf_len ) {
				if ( ! feof( $this->file_handler ) ) {
					$buf          = EDI::filesystem()->fread( $this->file_handler, $read_size );
					$buf_position = 0;
					$buf_len      = strlen( $buf );
				} else {
					return null;
				}
			}
			$xml_position = strpos( $buf, '<', $buf_position );
		}

		// Let's find next line delimiter.
		while ( false === $xml_position ) {
			$next_search = $buf_len;
			// Delimiter not in buffer so try to add more data to it.
			if ( ! feof( $this->file_handler ) ) {
				$buf     .= EDI::filesystem()->fread( $this->file_handler, $read_size );
				$buf_len = strlen( $buf );
			} else {
				break;
			}

			// Let's find xml tag start.
			$xml_position = strpos( $buf, '<', $next_search );
		}
		if ( false === $xml_position ) {
			$xml_position = $buf_len + 1;
		}

		$len                 = $xml_position - $buf_position;
		$this->file_position += $len;
		$result              = substr( $buf, $buf_position, $len );
		$buf_position        = $xml_position;

		return $result;
	}

	/**
	 * Reads xml chunk from the file preserving its position
	 *
	 * @param int $start_position Start position.
	 * @param int $end_position End position.
	 *
	 * @return DataXML
	 *
	 * @throws Exception Exception.
	 */
	protected function read_xml( int $start_position, int $end_position ): DataXML {
		$xml_chunk = $this->read_file_part( $start_position, $end_position );

		$xml_object = new DataXML();
		$xml_object->load_string( $xml_chunk );

		return $xml_object;
	}

	/**
	 * Reads part of the file preserving its position
	 *
	 * @param int $start_position Start position.
	 * @param int $end_position End position.
	 *
	 * @return string
	 *
	 * @throws Exception Exception.
	 */
	protected function read_file_part( int $start_position, int $end_position ): string {
		$saved_position = EDI::filesystem()->ftell( $this->file_handler );

		EDI::filesystem()->fseek( $this->file_handler, $start_position );
		$xml_chunk = EDI::filesystem()->fread( $this->file_handler, $end_position - $start_position );

		EDI::filesystem()->fseek( $this->file_handler, $saved_position );

		return $xml_chunk;
	}
}
