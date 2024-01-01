<?php
/**
 * Class DocumentsParser
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
use WC_DateTime;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;
use WC_Shipping_Rate;

/**
 * Class DocumentsParser
 *
 * @package BytePerfect\EDI
 */
class DocumentsParser {
	/**
	 * ProductsParser constructor.
	 */
	public function __construct() {
		add_action(
			'_/КоммерческаяИнформация/Документ',
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
		try {
			$document_data = $this->parse_xml_object( $xml_object );

			$this->update_order( $document_data );
		} catch ( Exception $e ) {
			$guid = isset( $document_data, $document_data['guid'] )
				? ( is_string( $document_data['guid'] ) ? $document_data['guid'] : '' )
				: '';
			$id   = isset( $document_data, $document_data['id'] )
				? ( is_string( $document_data['id'] ) ? $document_data['id'] : '' )
				: '';

			EDI::log()->error(
				sprintf(
				/* translators: %1$s: document GUID, %2$d: order ID. */
					__( 'Error processing GUID %1$s, order ID %2$s.', 'edi' ),
					$guid,
					$id
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
	 * @return array<string, mixed>
	 *
	 * @throws Exception Exception.
	 */
	protected function parse_xml_object( DataXML $xml_object ): array {
		$xml_data = $xml_object->GetArray();
		$xml_data = $xml_data['Документ']['#'];

		$products = array();
		$fees     = array();
		$shipping = array();
		foreach ( $xml_data['Товары'][0]['#']['Товар'] as $product_data ) {
			$product_data = $product_data['#'];

			$product_data = OrdersXMLParserUtils::parse_product_data( $product_data );

			if ( 'Товар' === $product_data['type'] ) {
				$product_id = Utils::get_product_id( $product_data['guid'] );
				if ( ! $product_id ) {
					throw new Exception(
						sprintf(
						/* translators: %s: action mode. */
							__( 'Product is not synchronized: %s.', 'edi' ),
							$product_data['guid']
						)
					);
				}

				$products[ $product_id ] = $product_data;
			} elseif ( 'Услуга' === $product_data['type'] ) {
				if ( 0 === strpos( $product_data['name'], 'SHIPPING' ) ) {
					$shipping[ $product_data['name'] ] = $product_data;
				} else {
					$fees[ $product_data['name'] ] = $product_data;
				}
			}
		}

		$details = array();
		foreach ( $xml_data['ЗначенияРеквизитов'][0]['#']['ЗначениеРеквизита'] as $details_item ) {
			$details_item = $details_item['#'];

			$details[ $details_item['Наименование'][0]['#'] ] = $details_item['Значение'][0]['#'];
		}

		// @phpstan-ignore-next-line
		return apply_filters(
			'edi_parse_document_xml_object',
			array(
				'guid'     => $xml_data['Ид'][0]['#'] ?? '',
				'id'       => (int) ( $xml_data['Номер'][0]['#'] ?? '' ),
				'total'    => $xml_data['Сумма'][0]['#'] ?? '',
				'products' => $products,
				'fees'     => $fees,
				'shipping' => $shipping,
				'details'  => $details,
			),
			$xml_data
		);
	}

	/**
	 * Update order.
	 *
	 * @param array $document_data Document data.
	 *
	 * @return void
	 *
	 * @throws Exception|WC_Data_Exception Exception.
	 */
	protected function update_order( array $document_data ): void {
		$order = wc_get_order( $document_data['id'] );

		if ( ! $order instanceof WC_Order ) {
			throw new Exception(
				sprintf(
				/* translators: %d: order ID. */
					__( 'Order does not exist: %d.', 'edi' ),
					$document_data['id']
				)
			);
		}

		$this->process_products( $document_data['products'], $order );
		$this->process_fees( $document_data['fees'], $order );
		$this->process_shipping( $document_data['shipping'], $order );

		$order->recalculate_coupons();
		$order->calculate_shipping();
		$order->calculate_totals();

		$this->update_status( $order, $document_data['details'] );

		do_action_ref_array( 'edi_order_before_save', array( &$order, &$document_data ) );

		$order->save();

		EDI::log()->debug(
			sprintf(
			/* translators: %1$s: GUID, %2$S : order ID. */
				__( 'Order was updated. GUID %1$s -> ID %2$s.', 'edi' ),
				$document_data['guid'],
				$order->get_id()
			)
		);
	}

	/**
	 * Process products.
	 *
	 * @param array $products_data Products data.
	 * @param WC_Order $order Order.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function process_products( array $products_data, WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( isset( $products_data[ $item->get_product_id() ] ) ) {
				// Обновляю данные об импортируемом товаре.
				$this->update_order_item( $item, $products_data[ $item->get_product_id() ] );

				// Импортируемый товар обработан. Удаляю его из списка для импорта.
				unset( $products_data[ $item->get_product_id() ] );
			} else {
				// Удаляю товар, если он отсутствует в импортируемом заказе.
				$order->remove_item( $item->get_id() );
			}
		}

		// Добавляю недостающие товар в заказ.
		foreach ( $products_data as $product_id => $product_data ) {
			$this->add_order_items( $order, $product_id, $product_data );
		}
	}

	/**
	 * Process fees.
	 *
	 * @param array $fees_data Fees data.
	 * @param WC_Order $order Order.
	 *
	 * @return void
	 */
	protected function process_fees( array $fees_data, WC_Order $order ): void {
		foreach ( $order->get_fees() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Fee ) {
				continue;
			}

			if ( isset( $fees_data[ $item->get_name() ] ) ) {
				// Обновляю данные о наценке.
				$item->set_total( $fees_data[ $item->get_name() ]['total'] );
				$item->save();

				// Импортируемая наценка обработана. Удаляю её из списка для импорта.
				unset( $fees_data[ $item->get_name() ] );
			} else {
				// Удаляю наценку, если она отсутствует в импортируемом заказе.
				$order->remove_item( $item->get_id() );
			}
		}

		// Добавляю недостающие наценки в заказ.
		foreach ( $fees_data as $service_name => $item ) {
			if ( 0 === strpos( $service_name, 'SHIPPING' ) ) {
				continue;
			}

			$fee = new WC_Order_Item_Fee();
			$fee->set_amount( $item['total'] );
			$fee->set_total( $item['total'] );
			$fee->set_name( $service_name );
			$order->add_item( $fee );

			unset( $fees_data[ $service_name ] );
		}
	}

	/**
	 * Process shipping methods.
	 *
	 * @param array $shipping_data Shipping data.
	 * @param WC_Order $order Order.
	 *
	 * @return void
	 *
	 * @throws WC_Data_Exception Exception.
	 */
	protected function process_shipping( array $shipping_data, WC_Order $order ): void {
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Shipping ) {
				continue;
			}

			$item_name = sprintf(
				'SHIPPING|%s|%s|%s',
				$item->get_instance_id(),
				$item->get_method_id(),
				$item->get_method_title()
			);

			if ( isset( $shipping_data[ $item_name ] ) ) {
				// Обновляю цену доставки.
				$item->set_total( $shipping_data[ $item_name ]['total'] );
				$item->save();

				// Импортируемая доставка обработана. Удаляю её из списка для импорта.
				unset( $shipping_data[ $item_name ] );
			} else {
				// Удаляю доставку, если она отсутствует в импортируемом заказе.
				$order->remove_item( $item->get_id() );
			}
		}

		foreach ( $shipping_data as $name => $item ) {
			if ( 0 !== strpos( $name, 'SHIPPING' ) ) {
				continue;
			}

			$item_data = explode( '|', $name );
			if ( count( $item_data ) !== 4 ) {
				continue;
			}

			$item_data = (array) array_combine(
				array( 'label', 'instance_id', 'method_id', 'method_title' ),
				$item_data
			);

			$rate = new WC_Shipping_Rate(
				$item_data['method_id'],
				$item_data['method_title'],
				(float) $item['total'],
				array(),
				$item_data['method_id'],
				$item_data['instance_id']
			);

			$item = new WC_Order_Item_Shipping();
			$item->set_order_id( $order->get_id() );
			$item->set_shipping_rate( $rate );
			$order->add_item( $item );

			unset( $shipping_data[ $name ] );
		}

		if ( count( $shipping_data ) > 0 ) {
			EDI::log()->error(
				sprintf(
				/* translators: %s: error message. */
					__( 'Error processing shipping methods: %s', 'edi' ),
					wc_print_r( $shipping_data, true )
				)
			);
		}
	}

	/**
	 * Update order item.
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @param array<string, int|float> $product_data Product data.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function update_order_item( WC_Order_Item_Product $item, array $product_data ): void {
		$product = wc_get_product( $item->get_product_id() );
		if ( ! $product instanceof WC_Product ) {
			throw new Exception(
				sprintf(
				/* translators: %s: action mode. */
					__( 'Product was not found: %d.', 'edi' ),
					$item->get_product_id()
				)
			);
		}

		$total = $this->get_price_excluding_tax(
			$product,
			// @phpstan-ignore-next-line because $product_data['quantity'] is type of integer
			$product_data['quantity'],
			$product_data['price'],
			$item->get_order()
		);

		// @phpstan-ignore-next-line because $product_data['quantity'] is type of integer
		$item->set_quantity( $product_data['quantity'] );
		$item->set_subtotal( (string) $total );
		$item->set_total( (string) $total );

		do_action_ref_array( 'edi_order_item_before_save', array( &$item, &$product_data ) );

		$item->save();
	}

	/**
	 * Add order items.
	 *
	 * @param WC_Order $order Order.
	 * @param int $product_id Product ID.
	 * @param array<string, int|float> $product_data Products data.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	protected function add_order_items( WC_Order $order, int $product_id, array $product_data ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			throw new Exception(
				sprintf(
				/* translators: %s: action mode. */
					__( 'Product was not found: %d.', 'edi' ),
					$product_id
				)
			);
		}

		$total = $this->get_price_excluding_tax(
			$product,
			// @phpstan-ignore-next-line because $product_data['quantity'] is type of integer
			$product_data['quantity'],
			$product_data['price'],
			$order
		);

		$item_id = $order->add_product(
			$product,
			// @phpstan-ignore-next-line because $product_data['quantity'] is type of integer
			$product_data['quantity'],
			// @phpstan-ignore-next-line
			apply_filters(
				'edi_add_order_product_args',
				array(
					'order'    => $order,
					'subtotal' => $total,
					'total'    => $total,
				),
				$order,
				$product_id,
				$product_data
			)
		);

		do_action_ref_array( 'edi_order_item_after_save', array( &$item_id, &$product_data ) );
	}

	/**
	 * For a given product, and optionally price/qty, work out the price with tax excluded, based on store settings.
	 *
	 * @param WC_Product $product WC_Product object.
	 * @param int $quantity Quantity.
	 * @param float $price Price.
	 * @param WC_Order $order Order.
	 *
	 * @return float|string Price with tax excluded, or an empty string if price calculation failed.
	 */
	protected function get_price_excluding_tax(
		WC_Product $product,
		int $quantity,
		float $price,
		WC_Order $order
	) {
		return wc_get_price_excluding_tax(
			$product,
			array(
				'qty'   => $quantity,
				'price' => $price,
				'order' => $order,
			)
		);
	}

	/**
	 * Update order status.
	 *
	 * @param WC_Order $order Order.
	 * @param array<string, string> $details Order details.
	 *
	 * @return void
	 */
	private function update_status( WC_Order $order, array $details ): void {
		/**
		 * Pending payment — Order received, no payment initiated. Awaiting payment (unpaid).
		 * Failed — Payment failed or was declined (unpaid) or requires authentication (SCA). Note that this status may not show immediately and instead show as Pending until verified (e.g., PayPal).
		 * Processing — Payment received (paid) and stock has been reduced; order is awaiting fulfillment. All product orders require processing, except those that only contain products which are both Virtual and Downloadable.
		 * Completed — Order fulfilled and complete – requires no further action.
		 * On hold — Awaiting payment – stock is reduced, but you need to confirm payment.
		 * Canceled — Canceled by an admin or the customer – stock is increased, no further action required.
		 * Refunded — Refunded by an admin – no further action required.
		 * Authentication required — Awaiting action by the customer to authenticate the transaction and/or complete SCA requirements.
		 *
		 * 'wc-pending'    => _x( 'Pending payment', 'Order status', 'woocommerce' ),
		 * 'wc-processing' => _x( 'Processing', 'Order status', 'woocommerce' ),
		 * 'wc-on-hold'    => _x( 'On hold', 'Order status', 'woocommerce' ),
		 * 'wc-completed'  => _x( 'Completed', 'Order status', 'woocommerce' ),
		 * 'wc-cancelled'  => _x( 'Cancelled', 'Order status', 'woocommerce' ),
		 * 'wc-refunded'   => _x( 'Refunded', 'Order status', 'woocommerce' ),
		 * 'wc-failed'     => _x( 'Failed', 'Order status', 'woocommerce' ),
		 */

		if ( 'true' === $details['ПометкаУдаления'] ) {
			$status = 'trash';
		} else {
			if ( wc_get_order_status_name( $details['Статус заказа'] ) === $details['Статус заказа'] ) {
				// Код статуса отсутствует в зарегистрированном списке статусов.
				$status = 'processing';
			} else {
				$status = $details['Статус заказа'];
			}
		}

		$date = new WC_DateTime();
		$order->set_status(
		// @phpstan-ignore-next-line
			apply_filters( 'edi_order_status', $status, $order, $details ),
			'Статус заказа изменен в результате синхронизации ' . $date->date( 'Y-m-d H:i:s' )
		);
	}
}
