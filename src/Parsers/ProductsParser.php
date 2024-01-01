<?php declare( strict_types=1 );
/**
 * Class ProductsParser
 *
 * @package BytePerfect\EDI
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Utils;
use Exception;
use WC_Data_Exception;
use WC_Product;

/**
 * Class ProductsParser
 *
 * @package BytePerfect\EDI
 */
class ProductsParser {
	/**
	 * ProductsParser constructor.
	 */
	public function __construct() {
		add_action(
			'_/КоммерческаяИнформация/Каталог/Товары/Товар',
			array( $this, 'process' )
		);
	}

	/**
	 * Process product data.
	 *
	 * @param DataXML $xml_object XML object.
	 *
	 * @return void
	 */
	public function process( DataXML $xml_object ): void {
		$product_data = $this->parse_xml_object( $xml_object );

		$product_id = Utils::get_product_id( $product_data['guid'] );

		try {
			if ( is_null( $product_id ) ) {
				$this->create_product( $product_data );
			} else {
				$this->update_product( $product_id, $product_data );
			}
		} catch ( Exception $e ) {
			EDI::log()->error( "Error processing GUID {$product_data['guid']}, product ID $product_id." );
			EDI::log()->error( $e->getMessage() );
		}
	}

	/**
	 * Parse XML object.
	 *
	 * @param DataXML $xml_object XML object.
	 *
	 * @return array<string, string>
	 */
	protected function parse_xml_object( DataXML $xml_object ): array {
		$xml_data = $xml_object->GetArray();
		$xml_data = $xml_data['Товар']['#'];

		if ( isset( $xml_data['ЗначенияРеквизитов'] ) ) {
			foreach ( $xml_data['ЗначенияРеквизитов'][0]['#']['ЗначениеРеквизита'] as $property ) {
				$key   = $property['#']['Наименование'][0]['#'];
				$value = $property['#']['Значение'][0]['#'];

				switch ( $key ) {
					case 'Ширина':
						$width = (float) $value;
						break;
					case 'Длина':
						$length = (float) $value;
						break;
					case 'Высота':
						$height = (float) $value;
						break;
					case 'Вес':
						$weight = (float) $value;
						break;
				}
			}
		}

		// @phpstan-ignore-next-line
		return apply_filters(
			'edi_parse_product_xml_object',
			array(
				'guid'        => $xml_data['Ид'][0]['#'] ?? '',
				'sku'         => $xml_data['Артикул'][0]['#'] ?? '',
				'name'        => $xml_data['Наименование'][0]['#'] ?? '',
				'description' => $xml_data['Описание'][0]['#'] ?? '',
				'width'       => $width ?? null,
				'length'      => $length ?? null,
				'height'      => $height ?? null,
				'weight'      => $weight ?? null,
			),
			$xml_data
		);
	}

	/**
	 * Create product.
	 *
	 * @param array<string, string> $product_data Product data.
	 *
	 * @return void
	 */
	protected function create_product( array $product_data ): void {
		$product = new WC_Product();

		$product->set_name( $product_data['name'] );
		$product->set_description( $product_data['description'] );
		try {
			$product->set_sku( $product_data['sku'] );
		} catch ( WC_Data_Exception $e ) {
			EDI::log()->warning( "Error processing SKU of GUID {$product_data['guid']}. " . $e->getMessage() );
		}
		$product->set_stock_quantity( 0 );
		$product->set_stock_status( 'outofstock' );

		if ( ! is_null( $product_data['weight'] ) ) {
			$product->set_weight( $product_data['weight'] );
		}
		if ( ! is_null( $product_data['width'] ) ) {
			$product->set_width( $product_data['width'] );
		}
		if ( ! is_null( $product_data['length'] ) ) {
			$product->set_length( $product_data['length'] );
		}
		if ( ! is_null( $product_data['height'] ) ) {
			$product->set_height( $product_data['height'] );
		}

		$product->add_meta_data( Utils::get_product_map_key(), $product_data['guid'], true );

		do_action_ref_array( 'edi_product_before_save', array( &$product, &$product_data ) );

		$product->save();

		EDI::log()->debug(
			sprintf(
			/* translators: %1$s: product GUID, %2$d - product ID. */
				__( 'Product was created. GUID %1$s -> ID %2$d.', 'edi' ),
				$product_data['guid'],
				$product->get_id()
			)
		);
	}

	/**
	 * Update product.
	 *
	 * @param int $product_id Product.
	 * @param array<string, string> $product_data Product data.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function update_product( int $product_id, array $product_data ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			throw new Exception( __( 'Is not a valid product.', 'edi' ) );
		}

		$product->set_name( $product_data['name'] );
		$product->set_description( $product_data['description'] );
		try {
			$product->set_sku( $product_data['sku'] );
		} catch ( WC_Data_Exception $e ) {
			EDI::log()->warning( "Error processing SKU of product ID $product_id . " . $e->getMessage() );
		}
		$product->set_status( 'publish' );

		if ( ! is_null( $product_data['weight'] ) ) {
			$product->set_weight( $product_data['weight'] );
		}
		if ( ! is_null( $product_data['width'] ) ) {
			$product->set_width( $product_data['width'] );
		}
		if ( ! is_null( $product_data['length'] ) ) {
			$product->set_length( $product_data['length'] );
		}
		if ( ! is_null( $product_data['height'] ) ) {
			$product->set_height( $product_data['height'] );
		}

		do_action_ref_array( 'edi_product_before_save', array( &$product, &$product_data ) );

		$product->save();

		EDI::log()->debug(
			sprintf(
			/* translators: %1$s: product GUID, %2$d - product ID. */
				__( 'Product was updated. GUID %1$s -> ID %2$d.', 'edi' ),
				$product_data['guid'],
				$product->get_id()
			)
		);
	}
}
