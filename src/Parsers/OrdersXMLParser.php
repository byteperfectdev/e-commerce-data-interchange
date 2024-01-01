<?php
/**
 * Class OrdersXMLParser.
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\Request;
use BytePerfect\EDI\Settings;
use Exception;

/**
 * Class OrdersXMLParser.
 *
 * @package BytePerfect\EDI
 */
class OrdersXMLParser extends XMLParser {
	/**
	 * OrdersXMLParser constructor.
	 *
	 * @param Request $request Request.
	 *
	 * @throws Exception Exception.
	 */
	public function __construct( Request $request ) {
		parent::__construct( $request );

		if ( Settings::get_import_orders() ) {
			$this->parsers[] = __NAMESPACE__ . '\\SaleProductsParser';
			$this->parsers[] = __NAMESPACE__ . '\\DocumentsParser';
		}

		$this->parsers = (array) apply_filters( 'edi_register_offers_parsers', $this->parsers );
		foreach ( $this->parsers as $parser ) {
			new $parser();
		}
	}
}
