<?php declare( strict_types=1 );
/**
 * Class AttributesParser
 *
 * @package BytePerfect\EDI\Parsers
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Utils;
use Exception;

/**
 * Class AttributesParser
 *
 * @package BytePerfect\EDI\Parsers
 */
class AttributesParser {
	public const ATTRIBUTE_MAP_KEY = '_edi_1c_attribute_map';

	/**
	 * AttributesParser constructor.
	 */
	public function __construct() {
		// Получаю все аттрибуты.
		add_action(
			'_/КоммерческаяИнформация/Классификатор/Свойства/Свойство',
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
		$attribute_data = $this->parse_xml_object( $xml_object );

		if ( 'Справочник' !== $attribute_data['type'] || 'true' !== $attribute_data['for_products'] ) {
			return;
		}

		try {
			$taxonomy_name = $this->process_attribute( $attribute_data );
			$this->process_attribute_terms( $taxonomy_name, $attribute_data );
		} catch ( Exception $e ) {
			// @phpstan-ignore-next-line because $attribute_data['guid'] is type of string.
			EDI::log()->error( "Error processing attribute GUID {$attribute_data['guid']}." );
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
		$xml_data = $xml_data['Свойство']['#'];

		$attribute_data = array(
			'guid'         => $xml_data['Ид'][0]['#'],
			'name'         => $xml_data['Наименование'][0]['#'],
			'type'         => $xml_data['ТипЗначений'][0]['#'],
			'for_products' => $xml_data['ДляТоваров'][0]['#'],
			'terms'        => array(),
		);

		if (
			'Справочник' === $attribute_data['type']
			&&
			$xml_data['ВариантыЗначений'][0]['#']
		) {
			foreach ( $xml_data['ВариантыЗначений'][0]['#']['Справочник'] as $variation ) {
				$guid  = $variation['#']['ИдЗначения'][0]['#'];
				$value = $variation['#']['Значение'][0]['#'];

				$attribute_data['terms'][ $guid ] = $value;
			}
		}

		return $attribute_data;
	}

	/**
	 * Process_attribute.
	 *
	 * @param array<string, mixed> $attribute_data Attribute data.
	 *
	 * @return string
	 *
	 * @throws Exception Exception.
	 */
	protected function process_attribute( array $attribute_data ): string {
		$attribute_id = Utils::get_guid_id_match(
			apply_filters( 'edi_attribute_map_key', self::ATTRIBUTE_MAP_KEY ), // @phpstan-ignore-line
			$attribute_data['guid'] // @phpstan-ignore-line
		);

		$args = array(
			// @phpstan-ignore-next-line because $attribute_data['name'] is type of string.
			'name' => $this->sanitize_attribute_name( $attribute_data['name'] ),
			'guid' => $attribute_data['guid'],
		);

		if (
			is_null( $attribute_id )
			||
			! taxonomy_exists( 'pa_' . $this->sanitize_attribute_slug( $attribute_data['name'] ) )
		) {
			// @phpstan-ignore-next-line because $attribute_data['name'] is type of string.
			$args['slug'] = $this->sanitize_attribute_slug( $attribute_data['name'] );

			$attribute_id = $this->create_attribute( $args );

			Utils::set_guid_id_match(
				apply_filters( 'edi_attribute_map_key', self::ATTRIBUTE_MAP_KEY ), // @phpstan-ignore-line
				$attribute_data['guid'], // @phpstan-ignore-line
				$attribute_id
			);
		} else {
			$args['slug'] = $this->get_attribute_slug( $attribute_id );

			$this->update_attribute( $attribute_id, $args );
		}

		$taxonomy_name = wc_attribute_taxonomy_name( $this->get_attribute_slug( $attribute_id ) );
		if ( ! taxonomy_exists( $taxonomy_name ) ) {
			$result = register_taxonomy(
				$taxonomy_name,
				array( 'product' ),
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			);
			if ( is_wp_error( $result ) ) {
				throw new Exception(
					sprintf(
					/* translators: %s: error message. */
						__( 'Error register taxonomy: %s', 'edi' ),
						// @phpstan-ignore-next-line because $attribute_id is type of WP_Error.
						$result->get_error_message()
					)
				);
			}
		}

		return $taxonomy_name;
	}

	/**
	 * Sanitize attribute name.
	 *
	 * @param string $attribute_name Attribute name.
	 *
	 * @return string
	 */
	protected function sanitize_attribute_name( string $attribute_name ): string {
		// @phpstan-ignore-next-line because $attribute_name is string.
		return wc_clean( wp_unslash( $attribute_name ) );
	}

	/**
	 * Sanitize attribute slug.
	 *
	 * @param string $attribute_name Attribute name.
	 *
	 * @return string
	 */
	protected function sanitize_attribute_slug( string $attribute_name ): string {
		$attribute_slug = $this->sanitize_attribute_name( $attribute_name );
		$attribute_slug = wc_sanitize_taxonomy_name( $attribute_slug );
		$attribute_slug = substr( Utils::transliterate( $attribute_slug ), 0, 27 );

		return $attribute_slug;
	}

	/**
	 * Create attribute.
	 *
	 * @param array<string, string> $args Attribute arguments.
	 *
	 * @return int
	 *
	 * @throws Exception Exception.
	 */
	protected function create_attribute( array $args ): int {
		$attribute_id = wc_create_attribute( $args );

		if ( is_wp_error( $attribute_id ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: error message. */
					__( 'Error create attribute: %s', 'edi' ),
					// @phpstan-ignore-next-line because $attribute_id is type of WP_Error.
					$attribute_id->get_error_message()
				)
			);
		}

		EDI::log()->debug(
			sprintf(
			/* translators: %1$s: attribute GUID, %2$d - attribute ID. */
				__( 'Attribute was created. GUID %1$s -> ID %2$d.', 'edi' ),
				$args['guid'],
				$attribute_id
			)
		);

		// @phpstan-ignore-next-line because $attribute_id is integer.
		return $attribute_id;
	}

	/**
	 * Update attribute.
	 *
	 * @param int $attribute_id Attribute ID.
	 * @param array<string, string> $args Attribute arguments.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function update_attribute( int $attribute_id, array $args ): void {
		$attribute_id = wc_update_attribute( $attribute_id, $args );

		if ( is_wp_error( $attribute_id ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: error message. */
					__( 'Error update attribute: %s', 'edi' ),
					// @phpstan-ignore-next-line because $attribute_id is type of WP_Error.
					$attribute_id->get_error_message()
				)
			);
		}

		EDI::log()->debug(
			sprintf(
			/* translators: %1$s: attribute GUID, %2$d - attribute ID. */
				__( 'Attribute was updated. GUID %1$s -> ID %2$d.', 'edi' ),
				$args['guid'],
				$attribute_id
			)
		);
	}

	/**
	 * Get attribute slug.
	 *
	 * @param int $attribute_id Attribute ID.
	 *
	 * @return string
	 *
	 * @throws Exception Exception.
	 */
	protected function get_attribute_slug( int $attribute_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attribute_name FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id = %d",
				$attribute_id
			)
		);

		if ( ! $result ) {
			throw new Exception(
				sprintf(
				/* translators: %s: attribute ID. */
					__( 'Error get attribute slug by ID: %d.', 'edi' ),
					$attribute_id
				)
			);
		}

		return $result;
	}

	/**
	 * Process attribute terms.
	 *
	 * @param string $taxonomy_name Taxonomy name.
	 * @param array<string, mixed> $attribute_data Attribute data.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function process_attribute_terms( string $taxonomy_name, array $attribute_data ): void {
		// @phpstan-ignore-next-line because $attribute_data['terms'] is type of array.
		foreach ( $attribute_data['terms'] as $guid => $term ) {
			$term_id = Utils::get_guid_id_match(
				apply_filters( 'edi_attribute_map_key', self::ATTRIBUTE_MAP_KEY ), // @phpstan-ignore-line
				$guid // @phpstan-ignore-line
			);

			if (
				is_null( $term_id )
				||
				! term_exists( $term, $taxonomy_name )
			) {
				// @phpstan-ignore-next-line because $result is type of string.
				$result = wp_insert_term( $term, $taxonomy_name );

				if ( is_wp_error( $result ) ) {
					// @phpstan-ignore-next-line because $result is type of WP_Error.
					EDI::log()->error( $result->get_error_message() . "[$term, $taxonomy_name]" );
					continue;
				}

				Utils::set_guid_id_match(
					apply_filters( 'edi_attribute_map_key', self::ATTRIBUTE_MAP_KEY ), // @phpstan-ignore-line
					$guid, // @phpstan-ignore-line
					$result['term_id'] // @phpstan-ignore-line
				);

				EDI::log()->debug(
					sprintf(
					/* translators: %1$s: attribute term GUID, %2$d - attribute term ID. */
						__( 'Attribute term was created. GUID %1$s -> ID %2$d.', 'edi' ),
						$guid,
						$result['term_id']
					)
				);
			} else {
				$result = wp_update_term( $term_id, $taxonomy_name, array( 'name' => $term ) );

				if ( is_wp_error( $result ) ) {
					// @phpstan-ignore-next-line because $result is type of WP_Error.
					EDI::log()->error( $result->get_error_message() . "[$term, $taxonomy_name]" );
				} else {
					EDI::log()->debug(
						sprintf(
						/* translators: %1$s: attribute GUID, %2$d - attribute ID. */
							__( 'Attribute term was updated. GUID %1$s -> ID %2$d.', 'edi' ),
							$guid,
							$term_id
						)
					);
				}
			}
		}
	}
}
