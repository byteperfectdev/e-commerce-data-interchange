<?php
/**
 * Class SaleProductsParser
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Utils;
use Exception;
use WC_Data_Exception;
use WC_Product;

/**
 * Class SaleProductsParser
 *
 * @package BytePerfect\EDI
 */
class SaleProductsParser {
	/**
	 * ProductsParser constructor.
	 */
	public function __construct() {
		add_action(
			'_/КоммерческаяИнформация/Документ/Товары/Товар',
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

		if ( 'Товар' !== $product_data['type'] ) {
			return;
		}

		$product_id = Utils::get_product_id( $product_data['guid'] );

		try {
			if ( is_null( $product_id ) ) {
				$this->create_product( $product_data );
			}
		} catch ( Exception $e ) {
			EDI::log()->error(
				sprintf(
				/* translators: %1$s: GUID, %2$s: product ID. */
					__( 'Error processing GUID %1$s, product ID %2$s.', 'edi' ),
					$product_data['guid'],
					$product_id
				)
			);
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

		return OrdersXMLParserUtils::parse_product_data( $xml_data );
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
		try {
			$product->set_sku( $product_data['sku'] );
		} catch ( WC_Data_Exception $e ) {
			EDI::log()->warning(
				sprintf(
				/* translators: %1$s: product GUID, %2$s: error message. */
					__( 'Error processing SKU of GUID %1$s. %2$s', 'edi' ),
					$product_data['guid'],
					$e->getMessage()
				)
			);
		}
		$product->set_price( $product_data['price'] );
		$product->set_sale_price( $product_data['price'] );
		$product->set_regular_price( $product_data['price'] );

		$product->set_manage_stock( true );
		$product->set_stock_quantity( (float) $product_data['quantity'] );
		$product->set_stock_status();

		$product->add_meta_data( Utils::get_product_map_key(), $product_data['guid'], true );

		do_action_ref_array( 'edi_product_before_save', array( &$product, &$product_data ) );

		$product->save();

		EDI::log()->debug(
			sprintf(
			/* translators: %1$s: product GUID, %2$d: product ID. */
				__( 'Product was created. GUID %1$s -> ID %2$d.', 'edi' ),
				$product_data['guid'],
				$product->get_id()
			)
		);
	}
}
