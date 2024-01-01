<?php
/**
 * Class OrdersXMLParser.
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

/**
 * Class OrdersXMLParser.
 *
 * @package BytePerfect\EDI
 */
class OrdersXMLParserUtils {
	/**
	 * Parse product data.
	 *
	 * @param array $product_data Product data.
	 *
	 * @return array
	 */
	public static function parse_product_data( array $product_data ): array {
		$type       = '';
		$properties = array();
		foreach ( $product_data['ЗначенияРеквизитов'][0]['#']['ЗначениеРеквизита'] as $property ) {
			$key   = $property['#']['Наименование'][0]['#'];
			$value = $property['#']['Значение'][0]['#'];

			$properties[ $key ] = $value;

			if ( 'ВидНоменклатуры' === $key ) {
				$type = $value;
			}
		}

		// @phpstan-ignore-next-line
		return (array) apply_filters(
			'edi_parse_sale_product_xml_object',
			array(
				'guid'       => $product_data['Ид'][0]['#'] ?? '',
				'sku'        => $product_data['Артикул'][0]['#'] ?? '',
				'name'       => $product_data['Наименование'][0]['#'] ?? '',
				'quantity'   => (int) $product_data['Количество'][0]['#'] ?? '',
				'price'      => (float) $product_data['ЦенаЗаЕдиницу'][0]['#'] ?? '',
				'total'      => (float) $product_data['Сумма'][0]['#'] ?? '',
				'properties' => $properties,
				'type'       => $type,
			),
			$product_data
		);
	}
}
