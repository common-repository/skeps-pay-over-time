<?php
/**
 * WooCommerce Skeps Pay-Over-Time Gateway
 *
 * Provides a form based Skeps Pay-Over-Time payment Gateway.
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Skeps_BNPL
 * @package  WooCommerce
 * @author   Skeps <developer@skeps.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.skeps.com/
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Abstract_Privacy' ) ) {
	return;
}
/**
 * Skeps Financing Payment Gateway Privacy Class
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Skeps_BNPL
 * @package  WooCommerce
 * @author   Skeps <developer@skeps.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.skeps.com/
 */
class WC_Skeps_BNPL_Privacy extends WC_Abstract_Privacy {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( __( 'Skeps_BNPL', 'woocommerce-skeps-pay-over-time' ) );
	}

	/**
	 * Returns a list of orders that are using Skeps Pay-Over-Time payment method.
	 *
	 * @param string $email_address email.
	 * @param int    $page          page.
	 *
	 * @return array WP_Post
	 */
	protected function getOrders( $email_address, $page ) {

		// Sanitize and escape the email address input.
		$email = sanitize_email( $email_address );

		// Check if user has an ID in the DB to load stored personal data.
		$user = get_user_by( 'email', $email );

		$order_query = array(
			'payment_method' => array( 'skeps-bnpl' ),
			'limit'          => 10,
			'page'           => absint( $page ), // Sanitize and escape the page number input.
		);

		if ( $user instanceof WP_User ) {
			$order_query['customer_id'] = (int) $user->ID;
		} else {
			$order_query['billing_email'] = $email;
		}

		return wc_get_orders( $order_query );
	}

	/**
	 * Gets the message of the privacy to display.
	 *
	 * @return string
	 */
	public function get_privacy_message() {
		return wpautop(
			sprintf(
				/* translators: %s: url */
				__(
					'By using this extension, you may be storing personal data or sharing data with an external service. <a href="%s" target="_blank">Learn more about how this works, including what you may want to include in your privacy policy.</a>',
					'woocommerce-skeps-pay-over-time'
				),
				esc_url('https://skeps.com')
			)
		);
	}

	/**
	 * Handle exporting data for Orders.
	 *
	 * @param string $email_address E-mail address to export.
	 * @param int    $page          Pagination of data.
	 *
	 * @return array
	 */
	public function orderDataExporter( $email_address, $page = 1 ) {
		$done           = false;
		$data_to_export = array();

		// Sanitize and escape the email address input.
		$email = sanitize_email( $email_address );

		$orders = $this->getOrders( $email, (int) $page );

		$done = true;

		if ( 0 < count( $orders ) ) {
			foreach ( $orders as $order ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_orders',
					'group_label' => __( 'Orders', 'woocommerce-skeps-pay-over-time' ),
					'item_id'     => 'order-' . $order->get_id(),
					'data'        => array(
						array(
							'name'  => __(
								'Skeps Pay-Over-Time Transaction ID',
								'woocommerce-skeps-pay-over-time'
							),
							'value' => get_post_meta(
								$order->get_id(),
								'_wc_gateway_skeps_bnpl_transaction_id',
								true
							),
						),
					),
				);
			}

			$done = 10 > count( $orders );
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Finds and erases order data by email address.
	 *
	 * @param string $email_address The user email address.
	 * @param int    $page          Page.
	 *
	 * @return array An array of personal data in name value pairs
	 */
	public function orderDataEraser( $email_address, $page ) {
		// Sanitize and escape the email address input.
		$email = sanitize_email( $email_address );
		$orders = $this->getOrders( $email, (int) $page );

		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		foreach ( (array) $orders as $order ) {
			$order = wc_get_order( $order->get_id() );

			list( $removed, $retained, $msgs ) = $this->maybeHandleOrder( $order );
			$items_removed                    |= $removed;
			$items_retained                   |= $retained;
			$messages                          = array_merge( $messages, $msgs );
		}

		// Tell core if we have more orders to work on still.
		$done = count( $orders ) < 10;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Handle eraser of data tied to Orders
	 *
	 * @param object $order order.
	 *
	 * @return array
	 */
	protected function maybeHandleOrder( $order ) {
		$order_id  = $order->get_id();
		$skepsBNPLtransactionIdText = '_wc_gateway_skeps_bnpl_transaction_id';
		$charge_id = get_post_meta( $order_id, $skepsBNPLtransactionIdText, true );

		if ( empty( $charge_id ) ) {
			return array( false, false, array() );
		}

		delete_post_meta( $order_id, $skepsBNPLtransactionIdText );

		return array(
			true,
			false,
			array( __( 'Skeps Pay-Over-Time personal data erased.', 'woocommerce-skeps-pay-over-time' ) ),
		);
	}
}
