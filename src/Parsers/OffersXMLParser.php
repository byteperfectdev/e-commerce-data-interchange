<?php declare( strict_types=1 );
/**
 * Class OffersXMLParser.
 *
 * @package BytePerfect\EDI
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\Request;
use BytePerfect\EDI\Settings;
use Exception;

/**
 * Class OffersXMLParser.
 *
 * @package BytePerfect\EDI
 */
class OffersXMLParser extends XMLParser {
	/**
	 * XMLParser constructor.
	 *
	 * @param Request $request Request.
	 *
	 * @throws Exception Exception.
	 */
	public function __construct( Request $request ) {
		parent::__construct( $request );

		if ( Settings::get_import_products() ) {
			$this->parsers[] = __NAMESPACE__ . '\\OffersParser';
		}

		$this->parsers = (array) apply_filters( 'edi_register_offers_parsers', $this->parsers );
		foreach ( $this->parsers as $parser ) {
			new $parser();
		}
	}
}
