<?php declare( strict_types=1 );
/**
 * Class OffersParser
 *
 * @package BytePerfect\EDI
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Utils;
use Exception;
use WC_Product;

/**
 * Class OffersParser
 *
 * @package BytePerfect\EDI
 */
class OffersParser {
	/**
	 * ProductsParser constructor.
	 */
	public function __construct() {
		add_action(
			'_/КоммерческаяИнформация/ПакетПредложений/Предложения/Предложение',
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

		if ( is_null( $product_id ) ) {
			EDI::log()->error( "Product was not found. GUID {$product_data['guid']}." );
		} else {
			try {
				$this->update_product( $product_id, $product_data );
			} catch ( Exception $e ) {
				EDI::log()->error( "Error processing GUID {$product_data['guid']}, product ID $product_id." );
				EDI::log()->error( $e->getMessage() );
			}
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
		$xml_data = $xml_data['Предложение']['#'];

		$product_data = array(
			'guid' => $xml_data['Ид'][0]['#'] ?? '',
		);
		if ( isset( $xml_data['Цены'][0]['#']['Цена'][0]['#']['ЦенаЗаЕдиницу'][0]['#'] ) ) {
			$product_data['price'] = $xml_data['Цены'][0]['#']['Цена'][0]['#']['ЦенаЗаЕдиницу'][0]['#'];
		}
		if ( isset( $xml_data['Количество'][0]['#'] ) ) {
			$product_data['stock'] = $xml_data['Количество'][0]['#'];
		}

		// @phpstan-ignore-next-line
		return apply_filters( 'edi_parse_offer_xml_object', $product_data, $xml_data );
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

		if ( isset( $product_data['price'] ) ) {
			$product->set_price( $product_data['price'] );
			$product->set_sale_price( $product_data['price'] );
			$product->set_regular_price( $product_data['price'] );
		}

		if ( isset( $product_data['stock'] ) ) {
			$quantity = (float) $product_data['stock'];

			$product->set_manage_stock( true );
			$product->set_stock_quantity( $quantity );
			$product->set_stock_status( $quantity ? 'instock' : 'outofstock' );
		}

		$product->set_status( 'publish' );

		do_action_ref_array( 'edi_offer_before_save', array( &$product, &$product_data ) );

		$product->save();

		EDI::log()->debug( "Product was updated. GUID {$product_data['guid']} -> ID {$product->get_id()}." );
	}
}
