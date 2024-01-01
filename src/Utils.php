<?php
/**
 * Class Utils
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r

use Exception;

/**
 * Class Utils
 *
 * @package BytePerfect\EDI
 */
class Utils {
	/**
	 * Get file limit.
	 *
	 * @return integer
	 */
	public static function get_file_limit(): int {
		// Получаю все значения.
		$file_limits = array(
			Settings::get_import_chunk_size(),
			self::filesize_to_bytes( (string) ini_get( 'post_max_size' ) ),
			self::filesize_to_bytes( (string) ini_get( 'upload_max_filesize' ) ),
			self::filesize_to_bytes( (string) ini_get( 'memory_limit' ) ),
		);

		// Убираю все "пустые" значения.
		$file_limits = array_filter( $file_limits );

		// Нахожу минимальное значение, иначе использую значение по-умолчанию 64 килобайта.
		$file_limit = empty( $file_limits ) ? 64000 : min( $file_limits );
		// Определяю 90% от найденного значения.
		$file_limit = $file_limit * 0.9;

		return (int) $file_limit;
	}

	/**
	 * Convert filesize to byte length.
	 *
	 * @param string $filesize Filesize as string.
	 *
	 * @return integer
	 */
	public static function filesize_to_bytes( string $filesize ): int {
		switch ( substr( $filesize, - 1 ) ) {
			case 'G':
			case 'g':
				return (int) $filesize * 1000000000;
			case 'M':
			case 'm':
				return (int) $filesize * 1000000;
			case 'K':
			case 'k':
				return (int) $filesize * 1000;
			default:
				$filesize = (int) $filesize;
				if ( $filesize < 0 ) {
					$filesize = 0;
				}

				return $filesize;
		}
	}

	/**
	 * Transliterate string.
	 *
	 * @param string $string String.
	 *
	 * @return string
	 */
	public static function transliterate( string $string ): string {
		return str_replace(
			array(
				'а',
				'б',
				'в',
				'г',
				'д',
				'е',
				'ё',
				'ж',
				'з',
				'и',
				'й',
				'к',
				'л',
				'м',
				'н',
				'о',
				'п',
				'р',
				'с',
				'т',
				'у',
				'ф',
				'х',
				'ц',
				'ч',
				'ш',
				'щ',
				'ъ',
				'ы',
				'ь',
				'э',
				'ю',
				'я',
				'А',
				'Б',
				'В',
				'Г',
				'Д',
				'Е',
				'Ё',
				'Ж',
				'З',
				'И',
				'Й',
				'К',
				'Л',
				'М',
				'Н',
				'О',
				'П',
				'Р',
				'С',
				'Т',
				'У',
				'Ф',
				'Х',
				'Ц',
				'Ч',
				'Ш',
				'Щ',
				'Ъ',
				'Ы',
				'Ь',
				'Э',
				'Ю',
				'Я',
			),
			array(
				'a',
				'b',
				'v',
				'g',
				'd',
				'e',
				'io',
				'zh',
				'z',
				'i',
				'y',
				'k',
				'l',
				'm',
				'n',
				'o',
				'p',
				'r',
				's',
				't',
				'u',
				'f',
				'h',
				'ts',
				'ch',
				'sh',
				'sht',
				'a',
				'i',
				'y',
				'e',
				'yu',
				'ya',
				'A',
				'B',
				'V',
				'G',
				'D',
				'E',
				'Io',
				'Zh',
				'Z',
				'I',
				'Y',
				'K',
				'L',
				'M',
				'N',
				'O',
				'P',
				'R',
				'S',
				'T',
				'U',
				'F',
				'H',
				'Ts',
				'Ch',
				'Sh',
				'Sht',
				'A',
				'I',
				'Y',
				'e',
				'Yu',
				'Ya',
			),
			$string
		);
	}

	/**
	 * Get product by 1C GUID.
	 *
	 * @param string $guid GUID.
	 *
	 * @return int|null
	 */
	public static function get_product_id( string $guid ): ?int {
		if ( empty( $guid ) ) {
			return null;
		}

		$posts = get_posts(
			array(
				'numberposts' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'    => self::get_product_map_key(),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'  => $guid,
				'fields'      => 'ids',
				'post_type'   => array( 'product', 'product_variation' ),
				'post_status' => 'any',
			)
		);

		if ( empty( $posts ) ) {
			return null;
		} else {
			// @phpstan-ignore-next-line because 'fields' => 'ids' provided.
			return (int) $posts[0];
		}
	}

	/**
	 * Get WC ID by 1C GUID.
	 *
	 * @param string $option Option name.
	 * @param string $guid GUID.
	 *
	 * @return int|null
	 *
	 * @throws Exception Exception.
	 */
	public static function get_guid_id_match( string $option, string $guid ): ?int {
		$attribute_map = get_option( $option, array() );
		if ( ! is_array( $attribute_map ) ) {
			throw new Exception(
			/* translators: %s: attribute map value. */
				sprintf( __( 'Attribute map is: %s.', 'edi' ), print_r( $attribute_map, true ) )
			);
		}

		return $attribute_map[ $guid ] ?? null;
	}

	/**
	 * Set "1C GUID"->"WC ID" match.
	 *
	 * @param string $option Option name.
	 * @param string $guid 1C GUID.
	 * @param int $attribute_id WC ID.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public static function set_guid_id_match( string $option, string $guid, int $attribute_id ): void {
		$attribute_map = get_option( $option, array() );
		if ( ! is_array( $attribute_map ) ) {
			throw new Exception(
			/* translators: %s: attribute map value. */
				sprintf( __( 'Attribute map is: %s.', 'edi' ), print_r( $attribute_map, true ) )
			);
		}

		$attribute_map[ $guid ] = $attribute_id;

		if ( ! update_option( $option, $attribute_map, false ) ) {
			throw new Exception( __( 'Error update attribute map.', 'edi' ) );
		}
	}

	/**
	 * Get product map key.
	 *
	 * @return string
	 */
	public static function get_product_map_key(): string {
		$product_map_key = apply_filters( 'edi_product_map_key', '_edi_1c_guid' );

		return is_string( $product_map_key ) ? $product_map_key : '_edi_1c_guid';
	}
}
