<?php
/**
 * Class OrderQuery
 *
 * @package BytePerfect\EDI
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

use WC_DateTime;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Shipping;

/**
 * Class OrderQuery
 *
 * @package BytePerfect\EDI
 */
class OrderQuery {
	/**
	 * Order IDs need to be synced.
	 *
	 * @var int[]
	 */
	protected array $order_ids = array();

	/**
	 * Return or echo xml.
	 * For testing and debugging purposes.
	 *
	 * @var bool
	 */
	public bool $return = false;

	/**
	 * OrderQuery constructor.
	 */
	public function __construct() {
		// Export orders is disabled. Short circuit.
		if ( ! Settings::get_export_orders() ) {
			return;
		}

		$args = array(
			'post_type'   => 'shop_order',
			'post_status' => array_keys( wc_get_order_statuses() ),
			'fields'      => 'ids',
			'numberposts' => - 1,
		);

		if ( Settings::get_export_from_timestamp() ) {
			$date = getdate( Settings::get_export_from_timestamp() );

			$args['date_query'] = array(
				array(
					'column'    => 'post_modified',
					'after'     => array(
						'year'   => $date['year'],
						'month'  => $date['mon'],
						'day'    => $date['mday'],
						'hour'   => $date['hours'],
						'minute' => $date['minutes'],
						'second' => $date['seconds'],
					),
					'inclusive' => true,
				),
			);
		} else {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				array(
					'key'     => '_edi_modified',
					'compare' => 'EXISTS',
				),
			);
		}

		$this->order_ids = array_unique( get_posts( $args ) );
	}

	/**
	 * Output orders as XML file.
	 *
	 * @return void
	 */
	public function output_as_xml(): void {
		$date = gmdate( 'Y-m-dTH:i:s' );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/xml; charset=UTF-8' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<КоммерческаяИнформация ВерсияСхемы="2.05" ДатаФормирования="' . $date . '">';

		$this->output_documents();

		echo '</КоммерческаяИнформация>';

		$this->delete_order_modified_timestamp();
	}

	/**
	 * Delete order modified timestamp.
	 *
	 * @return void
	 */
	public function delete_order_modified_timestamp(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			sprintf(
				'DELETE FROM %s WHERE meta_key = \'_edi_modified\' AND post_id IN (%s)',
				$wpdb->postmeta,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				implode( ',', array_map( 'absint', $this->order_ids ) )
			)
		);
	}

	/**
	 * Output documents.
	 *
	 * @return void
	 */
	protected function output_documents(): void {
		static $document_xml = null;

		if ( is_null( $document_xml ) ) {
			$document_xml = file(
				__DIR__ . '/partials/document.xml',
				FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
			);
		}

		switch ( get_woocommerce_currency() ) {
			case 'RUB':
				$currency = 'руб';
				break;
			case 'UAH':
				$currency = 'грн';
				break;
			default:
				$currency = 'USD';
		}

		$orders_processed = 0;
		foreach ( $this->order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				EDI::log()->warning(
				/* translators: %d: order ID. */
					sprintf( __( 'Order #%d was nor exported.', 'edi' ), $order_id )
				);

				continue;
			}

			$date = $order->get_date_modified();
			if ( ! $date instanceof WC_DateTime ) {
				continue;
				// @todo: Implement error handling.
			}
			list( $date, $time ) = explode( ' ', $date->date( 'Y-m-d H:i:s' ) );

			$this->output_xml(
				$document_xml,
				array(
					'order_id' => $order->get_id(),
					'date'     => $date,
					'time'     => $time,
					'currency' => $currency,
					'total'    => $order->get_total(),
					'comment'  => $order->get_customer_note(),
					'order'    => $order,
				)
			);

			$orders_processed ++;
		}

		EDI::log()->debug(
			sprintf(
			/* translators: %1$d: total order processed, %2$d: total orders. */
				__( 'Exported %1$d of %2$d orders.', 'edi' ),
				$orders_processed,
				count( $this->order_ids )
			)
		);
	}

	/**
	 * Output counterparties.
	 *
	 * @param array $params Parameters.
	 *
	 * @return void
	 */
	protected function output_counterparties( array $params ): void {
		static $xml = null;

		if ( is_null( $xml ) ) {
			$xml = file(
				__DIR__ . '/partials/counterparty.xml',
				FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
			);
		}

		$order = $params['order'];

		if ( $order->get_billing_company() ) {
			$name = $order->get_billing_company();
		} else {
			$name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
		}

		$this->output_xml(
			$xml,
			array(
				'name'       => $name,
				'id'         => $order->get_customer_id(),
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'email'      => $order->get_billing_email(),
				'phone'      => $order->get_billing_phone(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'company'    => $order->get_billing_company(),
				'country'    => $order->get_billing_country(),
				'postcode'   => $order->get_billing_postcode(),
				'state'      => $order->get_billing_state(),
				'order'      => $order,
			)
		);
	}

	/**
	 * Output products.
	 *
	 * @param array $params Parameters.
	 *
	 * @return void
	 */
	protected function output_products( array $params ): void {
		static $xml = null;

		if ( is_null( $xml ) ) {
			$xml = file(
				__DIR__ . '/partials/product.xml',
				FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
			);
		}

		foreach ( $params['order']->get_items() as $order_item ) {
			$guid = get_post_meta( $order_item->get_product_id(), Utils::get_product_map_key(), true );

			$this->output_xml(
				$xml,
				array(
					'guid'       => $guid,
					'name'       => $order_item->get_name(),
					'price'      => $order_item->get_subtotal() / $order_item->get_quantity(),
					'quantity'   => $order_item->get_quantity(),
					'total'      => $order_item->get_total(),
					'type'       => 'Товар',
					'order_item' => $order_item,
				)
			);
		}
	}

	/**
	 * Output fees.
	 *
	 * @param array $params Parameters.
	 *
	 * @return void
	 */
	protected function output_fees( array $params ): void {
		static $xml = null;

		if ( is_null( $xml ) ) {
			$xml = file(
				__DIR__ . '/partials/service.xml',
				FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
			);
		}

		$order = $params['order'];
		foreach ( $order->get_fees() as $order_item_fee ) {
			if ( $order_item_fee instanceof WC_Order_item_Fee ) {
				$this->output_xml(
					$xml,
					array(
						'guid'       => 'ORDER_FEE',
						'name'       => $order_item_fee->get_name(),
						'price'      => $order_item_fee->get_amount(),
						'quantity'   => 1,
						'total'      => $order_item_fee->get_total(),
						'type'       => 'Услуга',
						'order_item' => $order_item_fee,
					)
				);
			} else {
				EDI::log()->error( __FUNCTION__ . PHP_EOL . wp_json_encode( $order_item_fee ) );
			}
		}
	}

	/**
	 * Output shipping methods.
	 *
	 * @param array $params Parameters.
	 *
	 * @return void
	 */
	protected function output_shipping_methods( array $params ): void {
		$order = $params['order'];

		static $xml = null;

		if ( is_null( $xml ) ) {
			$xml = file(
				__DIR__ . '/partials/service.xml',
				FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
			);
		}

		foreach ( $order->get_shipping_methods() as $order_item_shipping ) {
			if ( $order_item_shipping instanceof WC_Order_Item_Shipping ) {
				$name = sprintf(
					'SHIPPING|%s|%s|%s',
					$order_item_shipping->get_instance_id(),
					$order_item_shipping->get_method_id(),
					$order_item_shipping->get_method_title()
				);

				$this->output_xml(
					$xml,
					array(
						'guid'  => 'ORDER_DELIVERY',
						'name'  => $name,
						'price' => $order_item_shipping->get_total(),
						'total' => $order_item_shipping->get_total(),
					)
				);
			} else {
				EDI::log()->error( __FUNCTION__ . PHP_EOL . wp_json_encode( $order_item_shipping ) );
			}
		}
	}

	/**
	 * Output discount.
	 *
	 * @param array $params Parameters.
	 *
	 * @return void
	 */
	protected function output_discount( array $params ): void {
		$order_item = $params['order_item'];

		static $xml = null;

		if ( is_null( $xml ) ) {
			$xml = file(
				__DIR__ . '/partials/discount.xml',
				FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
			);
		}

		// Сумма без скидки.
		$subtotal = (float) $order_item->get_subtotal();
		// Сумма со скидкой.
		$total = (float) $order_item->get_total();

		if ( $subtotal === $total ) {
			return;
		}

		$amount = $subtotal - $total;
		$this->output_xml(
			$xml,
			array(
				'amount' => $amount,
			)
		);
	}

	/**
	 * Output attributes.
	 *
	 * @param array $params Parameters.
	 *
	 * @return void
	 */
	protected function output_attributes( array $params ): void {
		static $xml = null;

		if ( is_null( $xml ) ) {
			$xml = file(
				__DIR__ . '/partials/attribute.xml',
				FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
			);
		}

		$final_statuses = array( 'completed', 'cancelled', 'refunded', 'failed' );

		/**
		 * Order.
		 *
		 * @var WC_Order $order
		 */
		$order = $params['order'];

		$this->output_xml(
			$xml,
			array(
				'is_paid'              => $order->is_paid() ? 'true' : 'false',
				'has_shipping'         => ( (float) $order->get_shipping_total() ) ? 'true' : 'false',
				'is_cancelled'         => 'false',
				'is_final'             => $order->has_status( $final_statuses ) ? 'true' : 'false',
				'status_name'          => $order->get_status(),
				'date_modified'        => (string) $order->get_date_modified(),
				'payment_method_title' => $order->get_payment_method_title(),
			)
		);
	}

	/**
	 * Output XML.
	 *
	 * @param array<string> $lines XML template lines.
	 * @param array<string, string> $params Params.
	 *
	 * @return void
	 */
	protected function output_xml( array $lines, array $params ): void {
		foreach ( $lines as $line ) {
			if ( preg_match_all( '/{{\s*(.+?)\s*}}/', $line, $matches ) ) {
				$replace = array();

				foreach ( $matches[1] as $index => $match ) {
					if ( isset( $params[ $match ] ) ) {
						$replace[ $matches[0][ $index ] ] = $params[ $match ];
					} elseif ( method_exists( $this, $match ) ) {
						$replace[ $matches[0][ $index ] ] = (string) $this->{$match}( $params );
					} else {
						$replace[ $matches[0][ $index ] ] = '';
					}
				}

				$line = str_replace( array_keys( $replace ), $replace, $line );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $line . PHP_EOL;

			if ( ! $this->return ) {
				flush();
				ob_flush();
			}
		}
	}
}
