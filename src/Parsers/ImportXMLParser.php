<?php declare( strict_types=1 );
/**
 * Class ImportXMLParser.
 *
 * @package BytePerfect\EDI
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Request;
use BytePerfect\EDI\Settings;
use Exception;

/**
 * Class ImportXMLParser.
 *
 * @package BytePerfect\EDI
 */
class ImportXMLParser extends XMLParser {
	/**
	 * Process only changes.
	 *
	 * @var bool
	 */
	protected bool $only_changes = false;

	/**
	 * XMLParser constructor.
	 *
	 * @param Request $request Request.
	 *
	 * @throws Exception Exception.
	 */
	public function __construct( Request $request ) {
		parent::__construct( $request );

		add_action(
			'/КоммерческаяИнформация/Каталог',
			array( $this, 'set_import_mode' )
		);

		if ( Settings::get_import_categories() ) {
			$this->parsers[] = __NAMESPACE__ . '\\CategoriesParser';
			$this->parsers[] = __NAMESPACE__ . '\\ProductCategoriesParser';
		}
		if ( Settings::get_import_products() ) {
			$this->parsers[] = __NAMESPACE__ . '\\ProductsParser';
		}
		if ( Settings::get_import_attributes() ) {
			$this->parsers[] = __NAMESPACE__ . '\\AttributesParser';
			$this->parsers[] = __NAMESPACE__ . '\\ProductAttributesParser';
		}
		if ( Settings::get_import_images() ) {
			$this->parsers[] = __NAMESPACE__ . '\\ProductImagesParser';
		}

		$this->parsers = (array) apply_filters( 'edi_register_import_parsers', $this->parsers );
		foreach ( $this->parsers as $parser ) {
			new $parser();
		}
	}

	/**
	 * Set import mode.
	 *
	 * @param null|array<string, string> $attr Attributes.
	 *
	 * @return void
	 */
	public function set_import_mode( ?array $attr ): void {
		if ( is_null( $attr ) ) {
			// конец элемента.
			return;
		}

		if ( isset( $attr['СодержитТолькоИзменения'] ) && 'true' === $attr['СодержитТолькоИзменения'] ) {
			$this->only_changes = true;
		} else {
			$this->only_changes = false;

			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->update(
				$wpdb->posts,
				array( 'post_status' => 'pending' ),
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
				)
			);

			EDI::log()->debug(
				sprintf(
				/* translators: %d: product count. */
					__( '%d products were moved to "Pending"', 'edi' ),
					$result
				)
			);
		}
	}
}
