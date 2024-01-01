<?php
/**
 * Class DirectFileSystem
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use Exception;
use WP_Error;

use function WP_Filesystem;

/**
 * Class DirectFileSystem
 *
 * @package BytePerfect\EDI
 */
class DirectFileSystem {
	/**
	 * Filesystem root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * DirectFileSystem constructor.
	 */
	public function __construct() {
		// Initializes WordPress Direct Filesystem.
		WP_Filesystem();

		$this->set_root();
	}

	/**
	 * Set Filesystem root.
	 *
	 * @return void
	 */
	protected function set_root(): void {
		$upload_dir = wp_upload_dir();
		$this->root = wp_normalize_path( path_join( $upload_dir['basedir'], 'edi-1c' ) );
	}

	/**
	 * Strip prefix.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	public function strip_prefix( string $path ): string {
		return substr( $path, strlen( $this->root ) );
	}

	/**
	 * Normalize path to a file/directory.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	public function normalize_path( string $path ): string {
		$path = wp_normalize_path( $path );
		if ( path_is_absolute( $path ) || wp_is_stream( $path ) ) {
			return $path;
		}

		return wp_normalize_path( path_join( $this->root, $path ) );
	}

	/**
	 * Create directory recursively.
	 *
	 * @param string $directory Path to the directory.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function mkdir( string $directory = '' ): void {
		$directory = $this->normalize_path( $directory );
		if ( ! is_dir( $directory ) ) {
			if ( ! mkdir( $directory, FS_CHMOD_DIR, true ) ) {
				throw new Exception(
					sprintf(
					/* translators: %s: directory name. */
						__( 'Error create directory: %s.', 'edi' ),
						$directory
					)
				);
			}
		}
	}

	/**
	 * Removes directory recursively.
	 *
	 * @param string $directory Path to the directory.
	 *
	 * @throws Exception Exception.
	 */
	public function rmdir( string $directory = '' ): void {
		$directory = $this->normalize_path( $directory );
		$directory = rtrim( $directory, '\\/' );

		if ( ! is_dir( $directory ) ) {
			return;
		}

		$files = $this->get_list( $directory );

		foreach ( $files as $file ) {
			$filename = $this->normalize_path( path_join( $directory, $file ) );

			if ( is_dir( $filename ) ) {
				$this->rmdir( $filename );
			} else {
				$this->unlink( $filename );
			}
		}

		if ( ! rmdir( $directory ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: property name. */
					__( 'Error remove directory: %s.', 'edi' ),
					$directory
				)
			);
		}
	}

	/**
	 * Get repository file list.
	 *
	 * @param string $directory Directory path.
	 *
	 * @return array<string>
	 */
	public function get_list( string $directory = '' ): array {
		$directory = $this->normalize_path( $directory );

		// @todo w8: Реализовать лучшую проверку.
		if ( ! is_readable( $directory ) ) {
			return array();
		}

		$list = scandir( $directory );
		if ( empty( $list ) ) {
			return array();
		}

		return array_diff( $list, array( '..', '.' ) );
	}

	/**
	 * Get repository file list except system files.
	 *
	 * @param string $directory Directory path.
	 *
	 * @return array<string>
	 */
	public function get_list_except_system_files( string $directory = '' ): array {
		$file_list = array_diff(
			$this->get_list( $directory ),
			array( '.htaccess', 'index.html' )
		);

		return array_values( $file_list );
	}

	/**
	 * Open file stream.
	 *
	 * @param string $filename File name.
	 * @param string $mode Mode.
	 *
	 * @return resource
	 *
	 * @throws Exception Exception.
	 */
	public function fopen( string $filename, string $mode ) {
		$filename = $this->normalize_path( $filename );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		$handle = fopen( $filename, $mode );
		if ( ! $handle ) {
			throw new Exception(
				sprintf(
				/* translators: %s: file name. */
					__( 'Error open stream: %s.', 'edi' ),
					$filename
				)
			);
		}

		return $handle;
	}

	/**
	 * Binary-safe file read.
	 *
	 * @param resource $handle File pointer.
	 * @param int $length Up to length number of bytes read.
	 *
	 * @return string
	 *
	 * @throws Exception Exception.
	 */
	public function fread( $handle, int $length ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fread
		$string = fread( $handle, $length );
		if ( false === $string ) {
			throw new Exception(
				sprintf(
				/* translators: %s: file name. */
					__( 'Error read from stream: %s.', 'edi' ),
					$this->get_stream_url( $handle )
				)
			);
		}

		return $string;
	}

	/**
	 * Returns the current position of the file read/write pointer.
	 *
	 * @param resource $handle File pointer.
	 *
	 * @return integer
	 *
	 * @throws Exception Exception.
	 */
	public function ftell( $handle ): int {
		$position = ftell( $handle );
		if ( false === $position ) {
			throw new Exception(
				sprintf(
				/* translators: %s: file name. */
					__( 'Error get pointer position: %s.', 'edi' ),
					$this->get_stream_url( $handle )
				)
			);
		}

		return $position;
	}

	/**
	 * Binary-safe file write.
	 *
	 * @param resource $handle File pointer.
	 * @param string $data The string that is to be written.
	 *
	 * @throws Exception Exception.
	 */
	public function fwrite( $handle, string $data ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		if ( false === fwrite( $handle, $data ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: file name. */
					__( 'Error write to stream: %s.', 'edi' ),
					$this->get_stream_url( $handle )
				)
			);
		}
	}

	/**
	 * Seeks on a file pointer.
	 *
	 * @param resource $handle File pointer.
	 * @param int $offset The offset.
	 *
	 * @throws Exception Exception.
	 */
	public function fseek( $handle, int $offset ): void {
		if ( - 1 === fseek( $handle, $offset ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: file name. */
					__( 'Error seek stream: %s.', 'edi' ),
					$this->get_stream_url( $handle )
				)
			);
		}
	}

	/**
	 * Close file stream.
	 *
	 * @param resource $handle File pointer.
	 *
	 * @throws Exception Exception.
	 */
	public function fclose( $handle ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		if ( ! fclose( $handle ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: file name. */
					__( 'Error close stream: %s.', 'edi' ),
					$this->get_stream_url( $handle )
				)
			);
		}
	}

	/**
	 * Copies data from one stream to another.
	 *
	 * @param resource $source The source stream.
	 * @param resource $destination The destination stream.
	 *
	 * @throws Exception Exception.
	 */
	public function stream_copy_to_stream( $source, $destination ): void {
		if ( ! stream_copy_to_stream( $source, $destination ) ) {
			throw new Exception(
				sprintf(
				/* translators: %1$s: source file name, %2$s: destination file name. */
					__( 'Error copy stream from %1$s to %2$s.', 'edi' ),
					$this->get_stream_url( $source ),
					$this->get_stream_url( $destination )
				)
			);
		}
	}

	/**
	 * Copies data from one stream to another.
	 *
	 * @param string $filename Path to the file where to write the data.
	 * @param string $data The data to write.
	 *
	 * @throws Exception Exception.
	 */
	public function file_put_contents( string $filename, string $data ): void {
		$filename = $this->normalize_path( $filename );

		$handle = $this->fopen( $filename, 'wb' );
		$this->fwrite( $handle, $data );
		$this->fclose( $handle );

		$this->chmod( $filename, FS_CHMOD_FILE );
	}

	/**
	 * Unlink file.
	 *
	 * @param string $filename File name.
	 *
	 * @throws Exception Exception.
	 */
	public function unlink( string $filename ): void {
		$filename = $this->normalize_path( $filename );

		if ( ! unlink( $filename ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: file name. */
					__( 'Error unlink file: %s.', 'edi' ),
					$filename
				)
			);
		}
	}

	/**
	 * Changes file mode.
	 *
	 * @param string $filename File name.
	 * @param int $permissions Permissions.
	 *
	 * @throws Exception Exception.
	 */
	public function chmod( string $filename, int $permissions ): void {
		$filename = $this->normalize_path( $filename );

		if ( ! chmod( $filename, $permissions ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: property name. */
					__( 'Error set file mode: %s.', 'edi' ),
					$filename
				)
			);
		}
	}

	/**
	 * Unzips a specified ZIP file to a location on the filesystem.
	 *
	 * @param string $filename Path and filename of ZIP archive.
	 * @param string $destination Path on the filesystem to extract archive to.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function unzip_file( string $filename, string $destination ): void {
		$filename    = $this->normalize_path( $filename );
		$destination = $this->normalize_path( $destination );

		$result = unzip_file( $filename, $destination );
		if ( is_wp_error( $result ) ) {
			/**
			 * Unzip file error.
			 *
			 * @var WP_Error $result
			 */
			throw new Exception(
				sprintf(
				/* translators: %s: error message. */
					__( 'Error unzip file: %s', 'edi' ),
					$result->get_error_message()
				)
			);
		}

		$this->unlink( $filename );
	}

	/**
	 * Receive file.
	 *
	 * @param string $filename File name to save.
	 *
	 * @throws Exception Exception.
	 */
	public function receive_file( string $filename ): void {
		$filename = $this->normalize_path( $filename );

		$this->mkdir( dirname( $filename ) );

		$source      = $this->fopen( 'php://input', 'r' );
		$destination = $this->fopen( $filename, 'ab' );

		$this->stream_copy_to_stream( $source, $destination );

		$this->fclose( $source );
		$this->fclose( $destination );

		$this->chmod( $filename, FS_CHMOD_FILE );
	}

	/**
	 * Get stream URL.
	 *
	 * @param resource $handle File pointer.
	 *
	 * @return string.
	 */
	protected function get_stream_url( $handle ): string {
		$meta_data = stream_get_meta_data( $handle );

		return $meta_data['uri'];
	}
}
