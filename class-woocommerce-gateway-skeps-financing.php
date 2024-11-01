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

define('WC_GATEWAY_SKEPS_PAY_OVER_TIME_VERSION', '2.1.0'); // WRCS: DEFINED_VERSION.

/**
 * Class WooCommerce_Gateway_Skeps_Financing
 * Load Skeps Pay-Over-Time
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Skeps_BNPL
 * @package  WooCommerce
 * @author   Skeps <support@skeps.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.skeps.com/
 */
class WooCommerce_Gateway_Skeps_Financing
{


	/**
	 * The reference the *Singleton* instance of this class.
	 *
	 * @var WooCommerce_Gateway_Skeps_Financing
	 */
	private static $_instance;

	/**
	 * Instance of WC_Gateway_Skeps_BNPL.
	 *
	 * @var WC_Gateway_Skeps_BNPL
	 */
	private $gateway = false;

	/**
	 * Returns the *Singleton* instance of this class.
	 *
	 * @return Singleton The *Singleton* instance.
	 */

	public static function get_instance()
	{
		if (null === self::$_instance) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Public clone method to prevent cloning of the instance of the
	 * *Singleton* instance.
	 *
	 * @return void
	 */
	public function __clone()
	{
	}

	/**
	 * Public unserialize method to prevent unserializing of the *Singleton*
	 * instance.
	 *
	 * @return void
	 */
	public function __wakeup()
	{
	}

	/**
	 * Protected constructor to prevent creating a new instance of the
	 * *Singleton* via the `new` operator from outside of this class.
	 */
	protected function __construct()
	{
		add_action(
			'plugins_loaded',
			array($this, 'init_gateway'),
			0
		);

		add_action(
			'woocommerce_order_refunded',
			array($this, 'possibly_refund_captured_charge')
		);

		add_action(
			'woocommerce_order_partially_refunded',
			array($this, 'possibly_refund_captured_charge')
		);

		// As low as.
		add_action(
			'wp_head',
			array($this, 'skeps_bnpl_js_runtime_script')
		);
		add_action(
			'wp_enqueue_scripts',
			array( $this, 'possibly_enqueue_scripts' )
		);

		add_action(
			'woocommerce_after_shop_loop_item',
			array($this, 'woocommerce_after_shop_loop_item'),9
		);
		// Uses priority 15 to get the as-low-as to appear after the product price.
		add_action(
			'woocommerce_single_product_summary',
			array($this, 'promo_message_after_product_price'),
			14
		);
		add_action(
			'woocommerce_after_add_to_cart_form',
			array($this, 'promo_message_after_add_to_cart'),
			14
		);
		add_action(
			'woocommerce_cart_totals_after_order_total',
			array($this, 'woocommerce_cart_totals_after_order_total'),
			9
		);

		add_filter(
			'woocommerce_available_payment_gateways',
			array($this, 'can_enable_skeps_bnpl'),
			10, 1
		);

		add_filter(
			'woocommerce_gateway_description',
			array($this, 'modify_skeps_bnpl_checkout_messaging'),
			10, 2
		);

	}


	/**
	 * Initialize the gateway.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	function init_gateway()
	{
		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		include_once dirname(__FILE__) . '/includes/class-wc-skeps-financing-privacy.php';
		include_once plugin_basename('includes/class-wc-gateway-skeps-financing.php');
		add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
	}

	/**
	 * Return an instance of the gateway for those loader functions that need it
	 * so we don't keep creating it over and over again.
	 *
	 * @return object
	 * @since  1.0.0
	 */
	public function get_gateway()
	{
		if (!$this->gateway) {
			$this->gateway = new WC_Gateway_Skeps_BNPL();
		}
		return $this->gateway;
	}

	/**
	 * Helper method to check the payment method and authentication.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @since  1.0.7
	 * @return bool Returns true if the payment method is Skeps Pay-Over-Time
	 * and the auth flag is set. False otherwise.
	 */
	private function check_payment_method_and_auth_flag($order)
	{
		$payment_method = version_compare(
			WC_VERSION,
			'3.0',
			'<'
		) ? $order->payment_method : $order->get_payment_method();

		return 'skeps-bnpl' === $payment_method
			&& $this->get_gateway()->issetOrderAuthOnlyFlag($order);
	}


	/**
	 * Possibly capture the charge.
	 * Used by woocommerce_order_action_wc_skeps_bnpl_capture_charge hook
	 * / possibly_add_capture_to_order_actions
	 *
	 * @param object $order order.
	 *
	 * @return bool
	 * @since  1.0.0
	 */
	public function possibly_capture_charge($order)
	{
		if (!is_object($order)) {
			$order = wc_get_order($order);
		}

		if (!$this->check_payment_method_and_auth_flag($order)) {
			return false;
		}

		return $this->get_gateway()->capture_charge($order);
	}

	/**
	 * Possibly void the charge.
	 *
	 * @param int|WC_Order $order Order ID or Order object.
	 *
	 * @return bool Returns true when succeed
	 */
	public function possibly_void_charge($order)
	{
		if (!is_object($order)) {
			$order = wc_get_order($order);
		}

		if (!$this->check_payment_method_and_auth_flag($order)) {
			return false;
		}

		return $this->get_gateway()->void_charge($order);
	}

	/**
	 * Possibly refund captured charge of an order when it's refunded.
	 *
	 * @param int|WC_Order $order Order ID or Order object.
	 *
	 * @return bool Returns true when succeed
	 */
	public function possibly_refund_captured_charge($order)
	{
		if (!is_object($order)) {
			$order = wc_get_order($order);
		}

		if (!$this->check_payment_method_and_auth_flag($order)) {
			return false;
		}

		$order_id = version_compare(
			WC_VERSION,
			'3.0',
			'<'
		) ? $order->id() : $order->get_id();

		$return_reason = __( 'Order is cancelled', 'woocommerce-skeps-pay-over-time');
		$return_reason_escaped = esc_html( $return_reason );

		return $this->get_gateway()->process_refund(
			$order_id,
			null,
			$return_reason_escaped
		);
	}


	/**
	 * Handle the capture bulk order action
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function possibly_capture_charge_bulk_order_action()
	{

		global $typenow, $post_type;

		if ('shop_order' === $typenow) {

			// Get the action (
			// I'm not entirely happy with using this internal WP function,
			// but this is the only way presently
			// )
			// See https://core.trac.wordpress.org/ticket/16031.

			// Get the action (using sanitize_text_field for safety).
			$wp_list_table = _get_list_table('WP_Posts_List_Table');
			$action = sanitize_text_field($wp_list_table->current_action());

			// Bail if not processing a capture.
			if ('wc_capture_charge_skeps_bnpl' !== $action) {
				return;
			}

			// Security check.
			check_admin_referer('bulk-posts');

			// Make sure order IDs are submitted.
			if (isset($_REQUEST['post'])) {
				$order_ids = array_map('absint', $_REQUEST['post']);
			}

			$sendback = remove_query_arg(
				array(
					'captured',
					'untrashed',
					'deleted',
					'ids',
				),
				wp_get_referer()
			);
			if (!$sendback) {
				$sendback = admin_url("edit.php?post_type=$post_type");
			}

			$capture_count = 0;

			if (!empty($order_ids)) {
				foreach ($order_ids as $order_id) {

					$order              = wc_get_order($order_id);
					$capture_successful = $this->possibly_capture_charge($order);

					if ($capture_successful) {
						$capture_count++;
					}
				}
			}

			$sendback = add_query_arg(
				array(
					'captured' => $capture_count,
				),
				$sendback
			);
			$sendback = remove_query_arg(
				array(
					'action',
					'action2',
					'tags_input',
					'post_author',
					'comment_status',
					'ping_status',
					'_status',
					'post',
					'bulk_edit',
					'post_view',
				),
				$sendback
			);
			wp_redirect($sendback);
			exit();
		} // End if().

	}


	/**
	 * Tell the user how much the capture bulk order action did
	 *
	 * @since  1.0.0
	 * @return void
	 */
	function custom_bulk_admin_notices()
	{
		global $post_type, $pagenow;

		if (
			'edit.php' === $pagenow
			&& 'shop_order' === $post_type
			&& isset($_REQUEST['captured'])
		) {

			$capturedCount = absint($_REQUEST['captured']); // Sanitize and convert to a non-negative integer

			if (0 >= $capturedCount) {
				$message = __(
					'Skeps Pay-Over-Time: No charges were able to be captured.',
					'woocommerce-skeps-pay-over-time'
				);
			} else {
				$message = sprintf(
					/* translators: 1: number of charge(s) captured */
					_n(
						'Skeps Pay-Over-Time: %s charge was captured.',
						'Skeps Pay-Over-Time: %s charges were captured.',
						$capturedCount,
						'woocommerce-skeps-pay-over-time'
					),
					number_format_i18n($capturedCount)
				);
			}

		?>
			<div class='updated'>
				<p>
					<?php echo esc_html($message); ?>
				</p>
			</div>
		<?php
		}
	}


	/**
	 * Add the gateway to WooCommerce
	 *
	 * @param array $methods methods.
	 *
	 * @return array
	 * @since  1.0.0
	 */
	public function add_gateway($methods)
	{
		 // Gateway name to be added.
		 $gateway_name = 'WC_Gateway_Skeps_BNPL';

		 // Escaping the gateway name before adding it to the array.
		 $escaped_gateway_name = esc_attr($gateway_name);

		 // Adding the gateway name to the methods array.
		 $methods[] = $escaped_gateway_name;

		return $methods;
	}

	/**
	 * Add Skeps Pay-Over-Time's monthly payment messaging to single product page.
	 *
	 * @since   1.0.0
	 * @version 1.1.0
	 *
	 * @return string
	 */
	public function woocommerce_single_product_summary()
	{
		if ($this->get_gateway()->product_ala) {
			global $product;

			// Only do this for simple, variable, and composite products. This
			// gateway does not (yet) support subscriptions.
			$supported_types = apply_filters(
				'wc_gateway_skeps_bnpl_supported_product_types',
				array(
					'simple',
					'variable',
					'grouped',
					'composite',
				)
			);

			if (!$product->is_type($supported_types)) {
				return;
			}
			$price = $product->get_price() ? $product->get_price() : 0;

			// For intial messaging in grouped product, use the most low-priced one.
			if ($product->is_type('grouped')) {
				$price = $this->get_grouped_product_price($product);
			}

			// Escape the price before echoing it as an HTML attribute.
			$escaped_price = esc_attr(floatval($price * 100));

			// Escape the 'product' string before echoing it as an HTML attribute.
			$product_type = esc_attr('product');

			echo $this->render_skeps_bnpl_monthly_payment_messaging($escaped_price, $product_type);
		}
	}

	/**
	 * Conditionally render Skeps Pay-Over-Time's monthly payment messaging
	 * to single product page after product price.
	 *
	 * @return void
	 */
	public function promo_message_after_product_price()
	{
		if (($this->get_gateway()->product_ala_options === 'after_product_price') || ($this->get_gateway()->product_ala_options === 'both_after_product_price_and_cart')) {
			$this->woocommerce_single_product_summary();
		}
	}

	/**
	 * Conditionally render Skeps Pay-Over-Time's monthly payment messaging
	 * to single product page after add to cart button.
	 *
	 * @return void
	 */
	public function promo_message_after_add_to_cart()
	{
		if (($this->get_gateway()->product_ala_options === 'after_add_to_cart') || ($this->get_gateway()->product_ala_options === 'both_after_product_price_and_cart')) {
			$this->woocommerce_single_product_summary();
		}
	}

	/**
	 * Get grouped product price by returning the most low-priced child.
	 *
	 * @param WC_Product $product Product instance.
	 *
	 * @return float Price.
	 */
	protected function get_grouped_product_price($product)
	{
		$children = array_filter(
			array_map(
				'wc_get_product',
				$product->get_children()
			),
			array(
				$this,
				'filter_visible_group_child',
			)
		);
		uasort($children, array($this, 'order_grouped_product_by_price'));

		return reset($children)->get_price();
	}

	/**
	 * Filter visible child in grouped product.
	 *
	 * @param WC_Product $product Child product of grouped product.
	 *
	 * @since   1.1.0
	 * @version 1.1.0
	 *
	 * @return bool True if it's visible group child.
	 */
	public function filter_visible_group_child($product)
	{
		return $product
			&& is_a(
				$product,
				'WC_Product'
			)
			&& ('publish' === $product->get_status()
				|| current_user_can(
					'edit_product',
					$product->get_id()
				)
			);
	}

	/**
	 * Sort callback to sort grouped product child based on price, from low to
	 * high
	 *
	 * @param object $a Product A.
	 * @param object $b Product B.
	 *
	 * @since   1.1.0
	 * @version 1.1.0
	 * @return  int
	 */
	public function order_grouped_product_by_price($a, $b)
	{
		if ($a->get_price() === $b->get_price()) {
			return 0;
		}
		return ($a->get_price() < $b->get_price()) ? -1 : 1;
	}

	/**
	 * Loads front side script when viewing product and cart pages.
	 *
	 * @since   1.0.0
	 * @version 1.1.0
	 * @return  void
	 */
	function possibly_enqueue_scripts() {
		if ( ! is_product() && ! is_cart() ) {
			return;
		}

		if ( ! $this->get_gateway()->isValidForUse() ) {
			return;
		}

		if ( ! $this->get_gateway()->enabled ) {
			return;
		}

		wp_register_script(
			'skeps_bnpl_as_low_as',
			plugins_url( 'assets/js/skeps-bnpl-as-low-as.js', __FILE__ ),
			array( 'jquery' )
		);
		wp_enqueue_script( 'skeps_bnpl_as_low_as' );
	}

	/**
	 * Add Skeps Pay-Over-Time's monthly payment messaging below the cart total.
	 *
	 * @return string
	 */
	public function woocommerce_cart_totals_after_order_total()
	{
		if (
			class_exists('WC_Subscriptions_Cart')
			&& WC_Subscriptions_Cart::cart_contains_subscription()
		) {
			return;
		}

		?>
		<tr>
			<th scope="col"></th>
			<td>
				<?php
				if ($this->get_gateway()->cart_ala) {
					echo $this->render_skeps_bnpl_monthly_payment_messaging(
						esc_attr(floatval(WC()->cart->total) * 100),
						esc_attr('cart')
					);
				}
				?>
			</td>
		</tr>
	<?php
	}

	/**
	 * Render Skeps Pay-Over-Time monthly payment messaging.
	 *
	 * @param float  $amount         Total amount to be passed to Skeps Pay-Over-Time.
	 * @param string $promotion_type type.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	protected function render_skeps_bnpl_monthly_payment_messaging(
		$amount,
		$promotion_type
	) {
		$attrs = array(
			'opportunity-amount' => floatVal($amount / 100),
			'merchant-id'   => esc_attr($this->get_gateway()->merchant_id),
			'promotion-type'      => esc_attr($promotion_type),
			'store-id'   => esc_attr($this->get_gateway()->store_id)
		);

		$data_attrs = '';
		foreach ($attrs as $attr => $val) {
			if (!$val) {
				continue;
			}
			$data_attrs .= sprintf(' data-%s="%s"', esc_attr($attr), esc_attr($val));
		}

		return '<p style="display:inline-block" name="skeps-promotion-banner" ' . $data_attrs . '></p>';
	}

	/**
	 * Render script tag for Skeps Pay-Over-Time JS runtime in the head.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	public function skeps_bnpl_js_runtime_script()
	{
		if (!$this->get_gateway()->isValidForUse()) {
			return;
		}

		if (!$this->get_gateway()->enabled) {
			return;
		}
		$script_url = esc_url($this->get_gateway()->api_url . '/application/plugins/sdk/v2/scripts/skeps.js');

	?>
		<script>
			if ('undefined' === typeof _skeps_financing_config) {
				var _skeps_financing_config = {
					script: "<?php echo esc_js($script_url); ?>"
				};
				(function(l, g, m, e, f) {
					var h = document.createElement(f),
						n = document.getElementsByTagName(f)[0];
					h.async = false;
					h.src = g[f];
					n.parentNode.insertBefore(h, n);
					delete g[f];
				})(
					window,
					_skeps_financing_config,
					"skeps_bnpl",
					"checkout",
					"script",
				);
			}
		</script>
<?php

	}

	public function skeps_bnpl_text_after_price($original_price, $product) {
		if(!is_front_page()) {
			return $original_price;
		}
		$supported_types = apply_filters(
			'wc_gateway_skeps_bnpl_supported_product_types',
			array(
				'simple',
				'variable',
				'grouped',
				'composite',
			)
		);

		if (!$product->is_type($supported_types)) {
			return $original_price;
		}
		$price = $product->get_price() ? $product->get_price() : 0;

		// For intial messaging in grouped product, use the most low-priced one.
		if ($product->is_type('grouped')) {
			$price = $this->get_grouped_product_price($product);
		}
		// Escape the original price and the rendered messaging before concatenation.
		$original_price = esc_html($original_price);
		$messaging = esc_html($this->render_skeps_bnpl_monthly_payment_messaging(floatval($price * 100), 'product'));

		return $original_price . $messaging;
	}

	/**
	 * As Low As messaging
	 *
	 * @return string
	 */
	public function woocommerce_after_shop_loop_item()
	{
		if ($this->get_gateway()->category_ala) {
			global $product;

			// Only do this for simple, variable, and composite products. This
			// gateway does not (yet) support subscriptions.
			$supported_types = apply_filters(
				'wc_gateway_skeps_bnpl_supported_product_types',
				array(
					'simple',
					'variable',
					'grouped',
					'composite',
				)
			);

			if (!$product->is_type($supported_types)) {
				return;
			}
			$price = $product->get_price() ? $product->get_price() : 0;

			// For intial messaging in grouped product, use the most low-priced one.
			if ($product->is_type('grouped')) {
				$price = $this->get_grouped_product_price($product);
			}
			echo $this->render_skeps_bnpl_monthly_payment_messaging(
				esc_attr(floatval($price * 100)),
				esc_attr('category')
			);
		}
	}

	public function can_enable_skeps_bnpl($available_gateways)
	{
		if (!is_admin() && is_checkout() && WC()->cart->total < $this->get_gateway()->min_cart_amount) {
			unset($available_gateways['skeps-bnpl']);
		}
		return $available_gateways;
	}

	public function modify_skeps_bnpl_checkout_messaging($description, $payment_id)
	{

		if (sanitize_text_field($payment_id) === 'skeps-bnpl' && is_checkout()) {
			$description = $this->render_skeps_bnpl_monthly_payment_messaging(
				floatval(WC()->cart->total * 100),
				'checkout'
			);
		}

		return $description;
	}

}
