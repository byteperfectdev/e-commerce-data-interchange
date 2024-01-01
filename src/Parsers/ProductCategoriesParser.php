<?php declare( strict_types=1 );
/**
 * Class ProductCategoriesParser
 *
 * @package BytePerfect\EDI\Parsers
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Utils;
use Exception;
use WC_Product;

/**
 * Class ProductCategoriesParser
 *
 * @package BytePerfect\EDI\Parsers
 */
class ProductCategoriesParser {
	/**
	 * ProductCategoriesParser constructor.
	 */
	public function __construct() {
		// Получаю категории товара.
		add_filter(
			'edi_parse_product_xml_object',
			array( $this, 'parse_xml_object' ),
			10,
			2
		);

		// Добавляю категории товару.
		add_action(
			'edi_product_before_save',
			array( $this, 'process' ),
			10,
			2
		);
	}

	/**
	 * Parse XML object.
	 *
	 * @param array<string, mixed> $product_data Product data.
	 * @param array $xml_data XML data.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws Exception Exception.
	 */
	public function parse_xml_object( array $product_data, array $xml_data ): array {
		$product_data['categories'] = array();

		if ( ! isset( $xml_data['Группы'] ) ) {
			return $product_data;
		}

		foreach ( $xml_data['Группы'] as $category ) {
			$guid = $category['#']['Ид'][0]['#'];

			if ( ! $guid ) {
				continue;
			}

			try {
				$category_id = Utils::get_guid_id_match(
				// @phpstan-ignore-next-line
					apply_filters( 'edi_attribute_map_key', CategoriesParser::CATEGORY_MAP_KEY ),
					$guid
				);

				if ( $category_id ) {
					$product_data['categories'][] = $category_id;
				}
			} catch ( Exception $e ) {
				EDI::log()->error( $e->getMessage() );

				continue;
			}
		}

		return $product_data;
	}

	/**
	 * Process product data.
	 *
	 * @param WC_Product $product Product.
	 * @param array<string, mixed> $product_data Product data.
	 *
	 * @return void
	 */
	public function process( WC_Product &$product, array &$product_data ): void {
		$product->set_category_ids( (array) $product_data['categories'] );
	}
}
