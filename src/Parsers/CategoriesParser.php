<?php declare( strict_types=1 );
/**
 * Class CategoriesParser
 *
 * @package BytePerfect\EDI\Parsers
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Utils;
use Exception;

/**
 * Class CategoriesParser
 *
 * @package BytePerfect\EDI\Parsers
 */
class CategoriesParser {
	const CATEGORY_MAP_KEY = '_edi_1c_category_map';

	/**
	 * CategoriesParser constructor.
	 */
	public function __construct() {
		// Получаю все категории.
		add_action(
			'_/КоммерческаяИнформация/Классификатор/Группы/Группа',
			array( $this, 'process' )
		);
	}

	/**
	 * Process categories data.
	 *
	 * @param DataXML $xml_object XML object.
	 *
	 * @return void
	 */
	public function process( DataXML $xml_object ): void {
		$category_data = $this->parse_xml_object( $xml_object );
		try {
			$this->process_category( $category_data, 0 );
		} catch ( Exception $e ) {
			// @phpstan-ignore-next-line because $attribute_data['guid'] is type of string.
			EDI::log()->error( "Error processing category GUID {$category_data['Ид'][0]['#']}." );
			EDI::log()->error( $e->getMessage() );
		}
	}

	/**
	 * Parse XML object.
	 *
	 * @param DataXML $xml_object XML object.
	 *
	 * @return array<string, mixed>
	 */
	protected function parse_xml_object( DataXML $xml_object ): array {
		$xml_data = $xml_object->GetArray();

		return $xml_data['Группа']['#'];
	}

	/**
	 * Process category.
	 *
	 * @param array $category Category data.
	 * @param int $parent_id Parent category ID.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function process_category( array $category, int $parent_id ): void {
		$guid = $category['Ид'][0]['#'];
		$name = $category['Наименование'][0]['#'];

		$category_id = Utils::get_guid_id_match(
			apply_filters( 'edi_category_map_key', self::CATEGORY_MAP_KEY ), // @phpstan-ignore-line
			$guid
		);

		if (
			is_null( $category_id )
			||
			! get_term_by( 'id', $category_id, 'product_cat' )
		) {
			$category_id = $this->create_category( $name, $parent_id );

			Utils::set_guid_id_match(
				apply_filters( 'edi_category_map_key', self::CATEGORY_MAP_KEY ), // @phpstan-ignore-line
				$guid,
				$category_id
			);
		} else {
			$this->update_category( $category_id, $name );
		}

		if ( isset( $category['Группы'] ) ) {
			foreach ( $category['Группы'][0]['#']['Группа'] as $child_category ) {
				$child_category = $child_category['#'];
				$this->process_category( $child_category, $category_id );
			}
		}
	}

	/**
	 * Sanitize category name.
	 *
	 * @param string $category_name Category name.
	 *
	 * @return string
	 */
	protected function sanitize_category_name( string $category_name ): string {
		// @phpstan-ignore-next-line because $category_name is string.
		return wc_clean( wp_unslash( $category_name ) );
	}

	/**
	 * Sanitize category slug.
	 *
	 * @param string $category_name Category name.
	 *
	 * @return string
	 */
	protected function sanitize_category_slug( string $category_name ): string {
		$category_slug = $this->sanitize_category_name( $category_name );
		$category_slug = wc_sanitize_taxonomy_name( $category_slug );
		$category_slug = substr( Utils::transliterate( $category_slug ), 0, 27 );

		return $category_slug;
	}

	/**
	 * Create a new product category.
	 *
	 * @param string $name Category name.
	 * @param int $parent_id Parent ID.
	 *
	 * @return int
	 *
	 * @throws Exception Exception.
	 */
	protected function create_category( string $name, int $parent_id ): int {
		// Check parent.
		if ( $parent_id ) {
			$parent = get_term_by( 'id', $parent_id, 'product_cat' );
			if ( ! $parent ) {
				throw new Exception(
				/* translators: %d: parent id. */
					sprintf( __( 'Product category parent is invalid: %d', 'edi' ), $parent_id )
				);
			}
		}

		$name = $this->sanitize_category_name( $name );
		$args = array(
			'parent' => $parent_id,
			'slug'   => $this->sanitize_category_slug( $name ),
		);

		$insert = wp_insert_term( $name, 'product_cat', $args );
		if ( is_wp_error( $insert ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: error message. */
					__( 'Error create product category: %s', 'edi' ),
					// @phpstan-ignore-next-line because $insert is type of WP_Error.
					$insert->get_error_message()
				)
			);
		}

		EDI::log()->debug(
			sprintf(
			/* translators: %s: category name. */
				__( 'Product category was created: %s', 'edi' ),
				$name
			)
		);

		return $insert['term_id'];
	}

	/**
	 * Update product category.
	 *
	 * @param int $term_id Category ID.
	 * @param string $name Category name.
	 *
	 * @throws Exception Exception.
	 */
	protected function update_category( int $term_id, string $name ): void {
		global $wpdb;

		$name = $this->sanitize_category_name( $name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $wpdb->terms, compact( 'name' ), compact( 'term_id' ) );
		if ( false === $result ) {
			EDI::log()->error( $wpdb->last_error );

			throw new Exception(
				sprintf(
				/* translators: %s: category data. */
					__( 'Cannot update product category: %s', 'edi' ),
					wc_print_r( compact( 'name', 'term_id' ), true )
				)
			);
		} elseif ( 0 === $result ) {
			EDI::log()->warning(
				sprintf(
				/* translators: %s: category data. */
					__( 'Product category was not updated: %s', 'edi' ),
					wc_print_r( compact( 'name', 'term_id' ), true )
				)
			);
		} else {
			EDI::log()->debug(
				sprintf(
				/* translators: %s: category name. */
					__( 'Product category was updated: %s', 'edi' ),
					$name
				)
			);
		}
	}
}
