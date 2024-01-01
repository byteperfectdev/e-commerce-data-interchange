<?php declare( strict_types=1 );
/**
 * Class ProductAttributesParser
 *
 * @package BytePerfect\EDI\Parsers
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Utils;
use Exception;
use WC_Product;
use WC_Product_Attribute;

/**
 * Class ProductAttributesParser
 *
 * @package BytePerfect\EDI\Parsers
 */
class ProductAttributesParser {
	/**
	 * AttributesParser constructor.
	 */
	public function __construct() {
		// Получаю аттрибуты товара.
		add_filter(
			'edi_parse_product_xml_object',
			array( $this, 'parse_xml_object' ),
			10,
			2
		);

		// Добавляю аттрибуты товару.
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
		$product_data['attributes'] = array();

		if ( ! isset(
			$xml_data['ЗначенияСвойств'],
			$xml_data['ЗначенияСвойств'][0],
			$xml_data['ЗначенияСвойств'][0]['#'],
			$xml_data['ЗначенияСвойств'][0]['#']['ЗначенияСвойства']
		) ) {
			return $product_data;
		}

		foreach ( $xml_data['ЗначенияСвойств'][0]['#']['ЗначенияСвойства'] as $attribute ) {
			$guid  = $attribute['#']['Ид'][0]['#'];
			$value = $attribute['#']['Значение'][0]['#'];

			if ( ! $guid || ! $value ) {
				continue;
			}

			try {
				$attribute_id = Utils::get_guid_id_match(
				// @phpstan-ignore-next-line
					apply_filters( 'edi_attribute_map_key', AttributesParser::ATTRIBUTE_MAP_KEY ),
					$guid
				);

				$term_id = Utils::get_guid_id_match(
				// @phpstan-ignore-next-line
					apply_filters( 'edi_attribute_map_key', AttributesParser::ATTRIBUTE_MAP_KEY ),
					$value
				);
			} catch ( Exception $e ) {
				EDI::log()->error( $e->getMessage() );

				continue;
			}

			if ( $attribute_id && $term_id ) {
				$product_data['attributes'][ $attribute_id ] = $term_id;
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
		$attributes = array();

		foreach ( (array) $product_data['attributes'] as $attribute_id => $term_id ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( $attribute_id );
			// @phpstan-ignore-next-line because $attribute->set_name accepts strings.
			$attribute->set_name( wc_attribute_taxonomy_name_by_id( $attribute_id ) );
			$attribute->set_options( array( $term_id ) );
			$attribute->set_position( count( $attributes ) );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$attributes[] = $attribute;
		}

		$product->set_attributes( $attributes );
	}
}
