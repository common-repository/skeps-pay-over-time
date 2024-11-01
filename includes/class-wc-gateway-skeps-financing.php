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

/**
 * Class WC_Gateway_Skeps_BNPL
 * Load Skeps Pay-Over-Time
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

class WC_Gateway_Skeps_BNPL extends WC_Payment_Gateway
{


    /**
     * Transaction type constants
     */
    const TRANSACTION_MODE_AUTH_AND_CAPTURE = 'capture';

    /**
     * Checkout type constants
     */
    const CHECKOUT_MODE_MODAL = 'modal';
    /**
     * Promo messaging locations on product page
     */
    const AFTER_PRODUCT_PRICE = 'after_product_price';
    const AFTER_ADD_TO_CART   = 'after_add_to_cart';
    const BOTH_AFTER_PRODUCT_PRICE_AND_CART   = 'both_after_product_price_and_cart';

    /**
     * Constructor
     */
    public function __construct()
    {

        $this->id   = 'skeps-bnpl';
        $this->icon = '';
        $this->has_fields         = false;
        $this->method_title       = esc_html__('Skeps Pay-Over-Time', 'woocommerce-skeps-pay-over-time');
        $this->method_description = esc_html__('Skeps provides Pay-Over-Time options with monthly payment plans including no interest promos.', 'woocommerce-skeps-pay-over-time');
        $this->supports = array(
            'products',
            'refunds',
        );

        $this->initFormFields();
        $this->init_settings();
        $skepsMethodName           = get_option('skeps_payment_method_name');
        $this->debug               = $this->get_option('debug') === 'yes';
        $this->title               = $skepsMethodName  ? $skepsMethodName  : 'Skeps Pay-Over-Time';
        $this->description         = esc_html('Skeps provides Pay-Over-Time options with monthly payment plans including no interest promos.', 'woocommerce-skeps-pay-over-time');
        $this->merchant_id           = $this->get_option('merchant_id');
        $this->client_id             = $this->get_option('client_id');
        $this->client_secret         = $this->get_option('client_secret');
        $this->store_id              = $this->get_option('store_id');
        $this->api_url               = $this->get_option('api_url');
        if ($this->get_option('merchant_id') && $this->get_option('client_id') && $this->get_option('client_secret')) {
            $this->enabled = $this->get_option('enabled');
        } else {
            $this->enabled = false;
        }
        $this->payment_gateway          = $this->get_option('payment_gateway');
        $this->elavon_merchant_id       = $this->get_option('elavon_merchant_id');
        $this->elavon_user_id           = $this->get_option('elavon_user_id');
        $this->elavon_pin               = $this->get_option('elavon_pin');
        $this->skepsSettings            = get_option('woocommerce_skeps_settings');
        $this->auth_only_mode           = false;
        $this->category_ala             = $this->get_option('category_ala') === 'yes';
        $this->product_ala              = 'yes';
        $this->product_ala_options      = $this->get_option( 'product_ala_options' );
        $this->cart_ala                 = 'yes';
        $this->inline                   = 'yes';
        $this->min_cart_amount          = $this->get_option('min_cart_amount');

        $this->initSentry();

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            array($this, 'process_admin_options')
        );


        if (!$this->isValidForUse()) {
            return;
        }

        add_action(
            'woocommerce_api_' . strtolower(get_class($this)),
            array($this, 'handleWcApi')
        );

        add_action(
            'woocommerce_review_order_before_payment',
            array($this, 'reviewOrderBeforePayment')
        );

        add_action(
            'wp_enqueue_scripts',
            array($this, 'enqueueScripts')
        );

    }

    /**
     * Initialize the Sentry error tracking system.
     *
     * If the request is not from a local IP address (not localhost), the Sentry
     * error tracking system is initialized with the specified Data Source Name (DSN).
     * The DSN identifies the Sentry project where error reports and exceptions
     * will be sent for monitoring and debugging.
     *
     * @since  1.0.0
     */

    function initSentry() {
        $dsn = false;
        if (in_array($_SERVER['REMOTE_ADDR'], array('127.0.0.1', '::1'))) {
            $this->$dsn = false;
        }else{
            $this->$dsn = 'https://3102181dd33f45428697a5f1be6e97e6@o201295.ingest.sentry.io/6152532';
        }
        // Included autoload file generated by composer to load external dependencies (Sentry)
        require_once __DIR__.'./../vendor/autoload.php';

        \Sentry\init(
            [
                'dsn' => $this->$dsn,
                'environment' =>  get_option('skeps_sandbox_mode') ? 'dev' : 'production'
            ]
        );
    }


    /**
     * Check for the Skeps Pay-Over-Time POST back.
     *
     * If the customer completes signing up for the loan,
     * Skeps Pay-Over-Time has the client browser POST to
     * https://{$domain}/wc-api/WC_Gateway_Skeps_BNPL?action=complete_checkout
     *
     * The POST includes the checkout_token from
     * Skeps Pay-Over-Time that the server can then use to complete
     * capturing the payment.
     * By doing it this way,
     * it "fits" with the Skeps Pay-Over-Time way of working.
     *
     * @throws Exception If checkout token is missing.
     */
    public function handleWcApi()
    {
        try {
            $this->log(
                __FUNCTION__,
                'Start redirect for Skeps Pay-Over-Time Auth'
            );
            \Sentry\captureMessage('Start redirect for Skeps Pay-Over-Time Auth');
            $action = isset($_GET['action']) ?
                sanitize_text_field($_GET['action']) :
                '';
            if ('complete_checkout' !== $action) {
                $errorMessage = 'Sorry, but that endpoint is not supported.';
                \Sentry\captureMessage($errorMessage);
                $this->log(
                    __FUNCTION__,
                    $errorMessage
                );
                throw new Exception($errorMessage);
            }

            $bnpl_order_id = isset($_POST['orderId']) ?
                wc_clean($_POST['orderId']) :
                '';
            if (empty($bnpl_order_id)) {
                \Sentry\captureMessage('Checkout failed. No order id was provided by Skeps Pay-Over-Time.');
                $this->log(
                    __FUNCTION__,
                    'No order Id provided by Skeps Pay-Over-Time.'
                );
                throw new Exception(
                    __(
                        'Checkout failed. No order id was provided by Skeps Pay-Over-Time. You may wish to try a different payment method.',
                        'woocommerce-skeps-pay-over-time'
                    )
                );
            }

            // In case there's an active request that still using session after
            // udpated to 1.0.4. Session fallback can be removed after two releases.
            $order_id = (!empty($_GET['order_id'])) ?
                absint($_GET['order_id']) :
                WC()->session->order_awaiting_payment;

            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use($bnpl_order_id, $order_id) {
                $scope->setTag('orderId', $bnpl_order_id . "");
                $scope->setTag('invoiceId', $order_id . "");
            });

            $order = wc_get_order($order_id);
            // Save the order ID on the order.
            $this->updateOrderMeta($order_id, 'bnpl_order_id', $bnpl_order_id);
            if (!$order) {
                \Sentry\captureMessage('Order is not available.');
                $this->log(
                    __FUNCTION__,
                    'Order is not available.'
                );
                throw new Exception(
                    __(
                        'Sorry, but that order is not available. Please try checking out again.',
                        'woocommerce-skeps-pay-over-time'
                    )
                );
            }

            // Get the 'order_key' parameter from the URL and sanitize it
            $order_key = sanitize_text_field($_GET['order_key']);

            // Define a regular expression pattern to match alphanumeric characters
            $pattern = '/^[a-zA-Z0-9]+$/';

            // Check if 'order_key' is not empty and matches the pattern
            if (!empty($order_key) && preg_match($pattern, $order_key)) {
                if ($order->key_is_valid($order_key)) {
                    // Log a message using Sentry
                    \Sentry\captureMessage('Order key is not available.');

                    // Log a message locally
                    $this->log(__FUNCTION__, 'Order key is not available.');

                    // Throw an exception with a user-friendly error message
                    throw new Exception(
                        __(
                            'Sorry, but that order is not available. Please try checking out again.',
                            'woocommerce-skeps-pay-over-time'
                        )
                    );
                }
            }

            \Sentry\captureMessage("Processing payment for order {$order_id} with bnpl order id {$bnpl_order_id}.");
            $this->log(
                __FUNCTION__,
                "Processing payment for '.
                    'order {$order_id} with bnpl order id {$bnpl_order_id}."
            );

            // Verify the order with Skeps Pay-Over-Time.
            include_once 'class-wc-gateway-skeps-financing-skeps-api.php';
            $skeps_api = new WC_Gateway_Skeps_BNPL_Skeps_API($this);
            $order_amount = floatVal($order->get_total());
            $result = $skeps_api->process_payment($order_amount, $bnpl_order_id);

            if (is_wp_error($result)) {
                \Sentry\captureMessage('Error in payment: ' . $result->get_error_message());
                $this->log(
                    __FUNCTION__,
                    'Error in payment: ' . $result->get_error_message()
                );
                throw new Exception(
                    __(
                        'Checkout failed. Unable to make payment with Skeps Pay-Over-Time. Please try checking out again later, or try a different payment source.',
                        'woocommerce-skeps-pay-over-time'
                    )
                );
            }

            $cardPaymentStatus         = $result['success'];
            if (!$cardPaymentStatus) {
                \Sentry\captureMessage("Checkout failed. Payment failure for Skeps Pay-Over-Time order id.");
                $this->log(
                    __FUNCTION__,
                    'Payment failure for Skeps Pay-Over-Time order.'
                );
                throw new Exception(
                    __(
                        'Checkout failed. Payment failure for Skeps Pay-Over-Time order id. Please try checking out again later, or try a different payment source.',
                        'woocommerce-skeps-pay-over-time'
                    )
                );
            }

            if (!$order->needs_payment()) {
                \Sentry\captureMessage("Checkout failed. This order has already been paid.");
                $this->log(
                    __FUNCTION__,
                    'Order no longer needs payment'
                );
                throw new Exception(
                    __(
                        'Checkout failed. This order has already been paid.',
                        'woocommerce-skeps-pay-over-time'
                    )
                );
            }

            $order->set_transaction_id($bnpl_order_id);
            $order->add_order_note(
                sprintf(
                    __(
                        'Transaction Approved: (Order ID %s)',
                        'woocommerce-skeps-pay-over-time'
                    ),
                    $bnpl_order_id
                )
            );
            $order->save();
            $order->payment_complete();
            \Sentry\captureMessage("Info: Successfully captured {$order_amount} for order {$order_id}");
            $this->log(
                __FUNCTION__,
                "Info: Successfully captured {$order_amount} for order {$order_id}"
            );
            wp_safe_redirect($this->get_return_url($order));
            exit;
        } catch (Exception $e) {
            if (!empty($e)) {
                $this->log(__FUNCTION__, $e->getMessage());
                wc_add_notice($e->getMessage(), 'error');
                wp_safe_redirect(wc_get_checkout_url());
                \Sentry\captureException($e);
            }
        } // End try().
    }


    /**
     * Initialise Gateway Settings Form Fields
     *
     * @return void
     * @since  1.0.0
     */
    public function initFormFields()
    {

        $this->form_fields = array(
            'enabled'             => array(
                'title'       => __(
                    'Enable/Disable',
                    'woocommerce-skeps-pay-over-time'
                ),
                'label'       => __(
                    'Enable Skeps Pay-Over-Time',
                    'woocommerce-skeps-pay-over-time'
                ),
                'type'        => 'checkbox',
                'description' => __(
                    'This controls whether or not this gateway is enabled within WooCommerce.',
                    'woocommerce-skeps-pay-over-time'
                ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'merchant_id'   => array(
                'title'       => __(
                    'Merchant ID',
                    'woocommerce-skeps-pay-over-time'
                ),
                'type'        => 'text',
                'default'     => '',
            ),
            'client_id'   => array(
                'title'       => __(
                    'Client ID',
                    'woocommerce-skeps-pay-over-time'
                ),
                'type'        => 'text',
                'default'     => '',
            ),
            'client_secret'   => array(
                'title'       => __(
                    'Client Secret',
                    'woocommerce-skeps-pay-over-time'
                ),
				'type'     => 'password',
                'default'     => '',
            ),
            'store_id'   => array(
                'title'       => __(
                    'Store ID',
                    'woocommerce-skeps-pay-over-time'
                ),
				'type'     => 'text',
                'default'     => '',
            ),
            'api_url'   => array(
                'title'       => __(
                    'API Url',
                    'woocommerce-skeps-pay-over-time'
                ),
				'type'     => 'text',
                'default'     => '',
            ),
            'promotional_banner'               => array(
                'title'       => __(
                    'Promotional Banner',
                    'woocommerce-skeps-pay-over-time'
                ),
                'label'       => __(
                    'Enable Promotional Banner',
                    'woocommerce-skeps-pay-over-time'
                ),
                'type'        => 'checkbox',
                'default'     => 'yes',
            ),
            'product_ala_options' => array(
                'title'   => __(
                    'Product Page Promotional Messaging Position',
                    'woocommerce-skeps-pay-over-time'
                ),
            	'type'        => 'select',
            	'description' => __(
            		'Choose where the promotional messaging gets rendered on product page',
            		'woocommerce-skeps-pay-over-time'
            	),
            	'default'     => self::AFTER_PRODUCT_PRICE,
            	'options'     => array(
            		self::AFTER_PRODUCT_PRICE => __(
            			'After product price (Default)',
            			'woocommerce-skeps-pay-over-time'
            		),
            		self::AFTER_ADD_TO_CART   => __(
            			'After add to cart',
            			'woocommerce-skeps-pay-over-time'
            		),
            		self::BOTH_AFTER_PRODUCT_PRICE_AND_CART   => __(
            			'Both',
            			'woocommerce-skeps-pay-over-time'
            		),
            	),
            ),
            'category_ala'        => array(
				'title'       => __(
					'Category Promo Messaging',
					'woocommerce-skeps-pay-over-time'
				),
				'label'       => __(
					'Enable category promotional messaging',
					'woocommerce-skeps-pay-over-time'
				),
				'type'        => 'checkbox',
				'description' => __(
					'Show promotional messaging at category level pages.',
					'woocommerce-skeps-pay-over-time'
				),
				'default'     => 'yes',
			),
            'debug'               => array(
                'title'       => __(
                    'Debug',
                    'woocommerce-skeps-pay-over-time'
                ),
                'label'       => __(
                    'Enable debugging messages',
                    'woocommerce-skeps-pay-over-time'
                ),
                'type'        => 'checkbox',
                'description' => __(
                    'Sends debug messages to the WooCommerce System Status log.',
                    'woocommerce-skeps-pay-over-time'
                ),
                'default'     => 'yes',
            ),
            'min_cart_amount'   => array(
                'title'       => __(
                    'Minimum Cart Amount',
                    'woocommerce-skeps-pay-over-time'
                ),
                'type'        => 'number',
                'custom_attributes' => array(
                    'min'       =>  0
                ),
                'default'     => '',
            )
        );
    }

    public function process_admin_options() {
        try {
            parent::process_admin_options();
            include_once 'class-wc-gateway-skeps-financing-skeps-api.php';
            $skeps_api = new WC_Gateway_Skeps_BNPL_Skeps_API($this);
            $result = $skeps_api->get_merchant_config();

            // Sanitize and escape the data before saving it to options.
            $sandbox_mode = isset($result['sandboxMode']) ? sanitize_text_field($result['sandboxMode']) : '';
            $payment_method_name = isset($result['paymentMethodName']) ? sanitize_text_field($result['paymentMethodName']) : '';

            // Update options with the sanitized data.
            update_option('skeps_sandbox_mode', $sandbox_mode);
            update_option('skeps_payment_method_name', $payment_method_name);
        } catch (\Throwable $exception) {
            \Sentry\captureException($exception);
        }
    }


    /**
     * Don't even allow administration of this extension if the currency is not
     * supported.
     *
     * @since  1.0.0
     * @return boolean
     */
    function isValidForAdministration()
    {
        if ('USD' !== get_woocommerce_currency()) {
            return false;
        }

        return true;
    }

    /**
     * Admin Warning Message
     *
     * @since  1.0.0
     */
    function admin_options()
    {
        if ($this->isValidForAdministration()) {
            parent::admin_options();
        } else {
                ?>
                    <div class="inline error">
                        <p>
                            <strong>
                                <?php
                                    esc_html_e(
                                        'Gateway Disabled',
                                        'woocommerce-skeps-pay-over-time'
                                    );
                                ?>
                            </strong>:
                            <?php
                                esc_html_e(
                                    'Skeps Pay-Over-Time does not support your store currency.',
                                    'woocommerce-skeps-pay-over-time'
                                );
                            ?>
                        </p>
                    </div>
                <?php
        }
    }

    /**
     * Check for required settings, and if SSL is enabled
     *
     * @return string
     */
    public function adminNotices()
    {

        if ('no' === $this->enabled) {
            return;
        }

        $checkout_settings_url = admin_url(
            'admin.php?page=wc-settings&tab=checkout'
        );
        $skeps_bnpl_settings_url   = admin_url(
            'admin.php?page=wc-settings&tab=checkout&section=skeps-bnpl'
        );

        // Check required fields.
        if (empty($this->client_id) || empty($this->client_secret) || empty($this->merchant_id)) {
            /* translators: 1: Skeps Pay-Over-Time settings url */
            echo '<div class="error"><p>' . sprintf(
                /* translators: %s url */
                esc_html__(
                    'Skeps Pay-Over-Time: One or more of your keys is missing. Please enter your keys <a href="%s">here</a>',
                    'woocommerce-skeps-pay-over-time'
                ),
                esc_url($skeps_bnpl_settings_url)
            ) . '</p></div>';
            return;
        }

        // Show message if enabled and FORCE SSL is disabled and WordpressHTTPS
        // plugin is not detected.
        if (!wc_checkout_is_https()) {
            /* translators: 1: checkout settings url */
            echo '<div class="error"><p>' .
            sprintf(
                /* translators: %s url */
                esc_html__(
                    'Skeps Pay-Over-Time: The <a href="%s">force SSL option</a> is disabled; your checkout may not be secure! Please enable SSL and ensure your server has a valid SSL certificate - Skeps Pay-Over-Time will only work in test mode.',
                    'woocommerce-skeps-pay-over-time'
                ),
                esc_url($checkout_settings_url)
            ) . '</p></div>';
        }
    }

    /**
     * Don't allow use of this extension if the currency is not supported or if
     * setup is incomplete.
     *
     * @since 1.0.0
     *
     * @return bool Returns true if gateway is valid for use
     */
    function isValidForUse()
    {
        if ($this->isCurrentPageRequiresSsl() && !is_ssl()) {
            return false;
        }

        if ('USD' !== get_woocommerce_currency()) {
            return false;
        }

        if (empty($this->merchant_id)) {
            return false;
        }

        if (empty($this->client_id)) {
            return false;
        }

        if (empty($this->client_secret)) {
            return false;
        }

        return true;
    }

    /**
     * Checks if current page requires SSL.
     *
     * @since 1.0.6
     *
     * @return bool Returns true if current page requires SSL
     */
    public function isCurrentPageRequiresSsl()
    {
        if (get_option('skeps_sandbox_mode')) {
            return false;
        }

        return is_checkout();
    }


    /**
     * Skeps Pay-Over-Time only supports US customers
     *
     * @return  bool
     * @since   1.0.0
     * @version 1.0.9
     */
    function is_available()
    {
        try {
            $is_available = ('yes' === $this->enabled) ? true : false;
            if (!WC()->customer) {
                $this->log(
                    __FUNCTION__,
                    'Missing Customer'
                );
                return false;
            }

            $country = version_compare(
                WC_VERSION,
                '3.0',
                '<'
            ) ?
                WC()->customer->get_country() :
                WC()->customer->get_billing_country();

            $available_country = array('US');

            if (!in_array($country, $available_country, true) && '' !== $country) {
                \Sentry\captureMessage("Country not Supported, {$country}");
                $this->log(
                    __FUNCTION__,
                    "Country not Supported, {$country}"
                );
                $is_available = false;
            }
            return $is_available;
        } catch (\Throwable $exception) {
            \Sentry\captureException($exception);
        }
    }


    /**
     * Process a refund for a WooCommerce order using the Skeps API.
     *
     * @param int    $order_id The ID of the WooCommerce order.
     * @param float|null $amount The refund amount.
     * @param string $reason The reason for the refund.
     *
     * @return bool True if the refund was successful, false otherwise.
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        // Include the necessary file for Skeps API.
        require_once 'class-wc-gateway-skeps-financing-skeps-api.php';

        // Create an instance of the Skeps API gateway.
        $skeps_api = new WC_Gateway_Skeps_BNPL_Skeps_API($this);

        // Process the refund using the Skeps API.
        return $skeps_api->process_refund($order_id, $amount, $reason);
    }


    /**
     * Skeps Pay-Over-Time is different. We can't redirect to their server after validating the
     * shipping and billing info the user supplies - their Javascript object
     * needs to do the redirection, but we still want to validate the user info,
     * so we'll land here when the customer clicks Place Order and after WooCommerce
     * has validated the customer info and created the order. So, we'll redirect to
     * ourselves with some query args to prompt us to embed the Skeps Pay-Over-Time JavaScript
     * bootstrap and an Skeps Pay-Over-Time formatted version of the cart
     *
     * @param string $order_id order id.
     *
     * @return array
     * @since  1.0.0
     */
    public function process_payment($order_id)
    {
        $order        = wc_get_order($order_id);
        $order_key    = version_compare(
            WC_VERSION,
            '3.0',
            '<'
        ) ?
            $order->order_key :
            $order->get_order_key();
        $query_vars   = WC()->query->get_query_vars();
        $order_pay    = $query_vars['order-pay'];
        $redirect_url = add_query_arg(
            array(
                'skeps_bnpl'    => '1',
                'order_id'  => $order_id,
                'nonce'     => wp_create_nonce('skeps-financing-checkout-order-' . $order_id),
                'key'       => $order_key,
                'cart_hash' => WC()->cart->get_cart_hash(),
            ),
            get_permalink(
                wc_get_page_id('checkout')
            ) . $order_pay . '/' . $order_id . '/'
        );

        return array(
            'result'   => 'success',
            'redirect' => $redirect_url,
        );
    }


    /**
     * We'll hook here to embed the Skeps Pay-Over-Time JavaScript
     * object bootstrapper into the checkout page
     *
     * @since  1.0.0
     * @return void
     */
    function reviewOrderBeforePayment()
    {

        if (!$this->isCheckoutAutoPostPage()) {
            return;
        }

        $order = $this->validateOrderFromRequest();
        if (false === $order) {
            wp_die(
                __(
                    'Checkout using Skeps Pay-Over-Time failed. Please try checking out again later, or try a different payment source.',
                    'woocommerce-skeps-pay-over-time'
                )
            );
        }
    }


    /**
     * If we see the query args indicating
     * that the Skeps Pay-Over-Time bootstrap and Skeps Pay-Over-Time-formatted cart
     * is/should be loaded, return true
     *
     * @since  1.0.0
     * @return boolean
     */
    function isCheckoutAutoPostPage()
    {
        if (!is_checkout()) {
            return false;
        }

        if (
            !isset($_GET['skeps_bnpl'])
            || !isset($_GET['order_id'])
            || !isset($_GET['nonce'])
        ) {
            return false;
        }

        return true;
    }


    /**
     * Return the appropriate order based on the query args, with nonce protection.
     *
     * @since  1.0.0
     * @return object
     */
    function validateOrderFromRequest()
    {
        if (empty($_GET['order_id'])) {
            return false;
        }

        // Sanitize the order_id using wc_clean() function
        $order_id = wc_clean($_GET['order_id']);

        if (!is_numeric($order_id)) {
            return false;
        }

        // Convert the order_id to an absolute integer using absint() function
        $order_id = absint($order_id);

        if (empty($_GET['nonce'])) {
            return false;
        }

        // Escape the cart_hash using wc_clean() function
        $cart_hash = wc_clean($_GET['cart_hash']);

        if (WC()->cart->get_cart_hash() !== $cart_hash) {
            // Escape the nonce before using it in wp_verify_nonce() function
            $nonce = wc_clean($_GET['nonce']);

            // Escape the dynamic part of the nonce using esc_attr() for added security
            $dynamic_nonce_part = 'skeps-financing-checkout-order-' . $order_id;
            if (!wp_verify_nonce($nonce, $dynamic_nonce_part)) {
                return false;
            }
        }

        // Convert the order_id to an absolute integer again (in case it was changed by the nonce)
        $order_id = absint($order_id);

        // Get the order after all validations
        $order = wc_get_order($order_id);

        if (!$order) {
            return false;
        }

        $modified_id_order = wc_get_order(absint($_GET['order_id']));
        if($modified_id_order->get_order_key() !== $_GET['key'] && !$order->needs_payment()) {
            return false;
        }

        return $order;
    }

    /**
     * Encode and enqueue the cart contents for use by Skeps Pay-Over-Time's JavaScript object
     *
     * @since   1.0.0
     * @version 1.0.10
     *
     * @return void
     */
    function enqueueScripts()
    {
        if (!$this->isCheckoutAutoPostPage()) {
            return;
        }

        $order = $this->validateOrderFromRequest();
        if (false === $order) {
            return;
        }

        // We made it this far,
        // let's fire up Skeps Pay-Over-Time and embed the order data in an Skeps Pay-Over-Time friendly way.
        wp_enqueue_script(
            'woocommerce_skeps_financing',
            plugins_url(
                'assets/js/skeps-bnpl-checkout.js',
                dirname(__FILE__)
            ),
            array('jquery', 'jquery-blockui'),
            WC_GATEWAY_SKEPS_PAY_OVER_TIME_VERSION,
            true
        );

        $order_id  = version_compare(
            WC_VERSION,
            '3.0',
            '<'
        ) ? $order->id : $order->get_id();
        $order_key = version_compare(
            WC_VERSION,
            '3.0',
            '<'
        ) ? $order->order_key : $order->get_order_key();

        $confirmation_url = add_query_arg(
            array(
                'action'    => 'complete_checkout',
                'order_id'  => $order_id,
                'order_key' => $order_key,
            ),
            WC()->api_request_url(get_class($this))
        );

        $cancel_url = html_entity_decode($order->get_cancel_order_url());

        $total_discount = floor(100 * $order->get_total_discount());
        $total_tax      = floor(100 * $order->get_total_tax());
        $total_shipping = version_compare(WC_VERSION, '3.0', '<') ?
            $order->get_total_shipping() :
            $order->get_shipping_total();
        $total_shipping = !empty($order->get_shipping_method()) ?
            floor(100 * $total_shipping) :
            0;
        $total          = floor(strval(100 * $order->get_total()));
        $bnpl_order_amount = floatval($order->get_total());
        include_once 'class-wc-gateway-skeps-financing-skeps-api.php';
        $skeps_bnpl_data = array(
            'merchant'        => array(
                'user_confirmation_url' => $confirmation_url,
                'user_cancel_url'       => $cancel_url,
            ),
            'items'           => $this->getItemsFormattedForSkepsBNPL($order),
            'discounts'       => array(
                'discount' => array(
                    'discount_amount' => $total_discount,
                ),
            ),
            'metadata'        => array(
                'order_key'        => $order_key,
                'platform_type'    => 'WooCommerce',
                'platform_version' => WOOCOMMERCE_VERSION,
                'platform_skeps_bnpl'  => WC_GATEWAY_SKEPS_PAY_OVER_TIME_VERSION,
                'mode'             => $this->checkout_mode,
            ),
            'tax_amount'      => $total_tax,
            'shipping_amount' => $total_shipping,
            'total'           => $total,
            'order_id'        => $order_id,
            'checkout'        => array(
                'merchant_id' => $this->merchant_id,
                'order_amount' => $bnpl_order_amount,
                'store_id' => $this->store_id
            )
        );

        $old_wc = version_compare(WC_VERSION, '3.0', '<');
        // Get the user ID from an Order ID
        $user_id = get_post_meta( $order_id, '_customer_user', true );

        // Get an instance of the WC_Customer Object from the user ID
        $customer = new WC_Customer( $user_id );


        $skeps_bnpl_data += array(
            'currency' => $old_wc ?
                $order->get_order_currency() :
                $order->get_currency(),
            'billing'  => array(
                'name'         => array(
                    'first' => $old_wc ?
                        $order->billing_first_name :
                        $order->get_billing_first_name(),
                    'last'  => $old_wc ?
                        $order->billing_last_name :
                        $order->get_billing_last_name(),
                ),
                'address'      => array(
                    'line1'   => $old_wc ?
                        $order->billing_address_1 :
                        $order->get_billing_address_1(),
                    'line2'   => $old_wc ?
                        $order->billing_address_2 :
                        $order->get_billing_address_2(),
                    'city'    => $old_wc ?
                        $order->billing_city :
                        $order->get_billing_city(),
                    'state'   => $old_wc ?
                        $order->billing_state :
                        $order->get_billing_state(),
                    'zipcode' => $old_wc ?
                        $order->billing_postcode :
                        $order->get_billing_postcode(),
                ),
                'email'        => $old_wc ?
                    $order->billing_email :
                    $order->get_billing_email(),
                'phone_number' => $old_wc ?
                    $order->billing_phone :
                    $order->get_billing_phone(),
            ),
            'shipping' => array(
                'name'    => array(
                    'first' => $old_wc ?
                        $order->shipping_first_name :
                        $order->get_shipping_first_name(),
                    'last'  => $old_wc ?
                        $order->shipping_last_name :
                        $order->get_shipping_last_name(),
                ),
                'address' => array(
                    'line1'   => $old_wc ?
                        $order->shipping_address_1 :
                        $order->get_shipping_address_1(),
                    'line2'   => $old_wc ?
                        $order->shipping_address_2 :
                        $order->get_shipping_address_2(),
                    'city'    => $old_wc ?
                        $order->shipping_city :
                        $order->get_shipping_city(),
                    'state'   => $old_wc ?
                        $order->shipping_state :
                        $order->get_shipping_state(),
                    'zipcode' => $old_wc ?
                        $order->shipping_postcode :
                        $order->get_shipping_postcode(),
                ),
            ),
            'customer' => array(
                'name'    => array(
                    'first' => $old_wc ?
                        $customer->first_name :
                        $customer->get_first_name(),
                    'last'  => $old_wc ?
                        $customer->last_name :
                        $customer->get_last_name(),
                ),
                'email'        => $old_wc ?
                    $customer->email :
                    $customer->get_email()
            ),
        );

        /**
         * If for some reason shipping info is empty (e.g. shipping is disabled),
         * use billing address.
         *
         * @see https://github.com/woocommerce/woocommerce-gateway-skeps-financing/issues/81#event-1109051257
         */
        foreach (array('name', 'address') as $field) {
            $shipping_field = array_filter($skeps_bnpl_data['shipping'][$field]);
            if (empty($shipping_field)) {
                $skeps_bnpl_data['shipping'][$field]
                    = $skeps_bnpl_data['billing'][$field];
            }
        }

        wp_localize_script(
            'woocommerce_skeps_financing',
            'skepsBNPLData',
            $skeps_bnpl_data
        );
    }

    /**
     * Helper to encode the items in the cart for use by Skeps Pay-Over-Time's JavaScript object
     *
     * @param object $order order.
     *
     * @return array
     * @since  1.0.0
     */
    function getItemsFormattedForSkepsBNPL($order)
    {

        $items = array();

        foreach ((array) $order->get_items(array('line_item', 'fee')) as $item) {
            $display_name   = $item->get_name();
            $sku            = '';
            $unit_price     = 0;
            $qty            = $item->get_quantity();
            $item_image_url = wc_placeholder_img_src();
            $item_url       = '';

            if ('fee' === $item['type']) {

                $unit_price = $item['line_total'];
            } else {
                $product    = $item->get_product();
                $sku        = $this->_clean($product->get_sku());
                $unit_price = floor(
                    100.0 * $order->get_item_subtotal($item, false)
                ); // cents please.

                $item_image_id    = $product->get_image_id();
                $image_attributes = wp_get_attachment_image_src($item_image_id);
                if (is_array($image_attributes)) {
                    $item_image_url = $image_attributes[0];
                }

                $item_url = $product->get_permalink();
            }

            $items[] = array(
                'display_name'   => $display_name,
                'sku'            => $sku ? $sku : $product->get_id(),
                'unit_price'     => $unit_price,
                'qty'            => $qty,
                'item_image_url' => $item_image_url,
                'item_url'       => $item_url,
            );
        } // End foreach().

        return $items;
    }


    /**
	 * Helper to enqueue admin scripts
	 *
	 * @param object $hook hook.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function adminEnqueueScripts($hook) {
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }

        if (!isset($_GET['section'])) {
            return;
        }

        $section = sanitize_text_field($_GET['section']); // Sanitize the section value

        if ('wc_gateway_skeps_bnpl' === $section || 'skeps-bnpl' === $section) {
            wp_register_script(
                'woocommerce_skeps_financing_admin',
                plugins_url('assets/js/elavon-admin.js', dirname(__FILE__)),
                array('jquery'),
                WC_GATEWAY_SKEPS_PAY_OVER_TIME_VERSION
            );

            wp_localize_script(
                'woocommerce_skeps_financing_admin',
                'elavonFinancingAdminData',
                $admin_array
            );
            wp_enqueue_script('woocommerce_skeps_financing_admin');
        }
    }



    /**
     * Helper methods to check order auth flag
     *
     * @param object $order order.
     *
     * @return bool|int
     */
    public function issetOrderAuthOnlyFlag($order)
    {
        $order_id = version_compare(
            WC_VERSION,
            '3.0',
            '<'
        ) ? $order->id : $order->get_id();
        return $this->getOrderMeta($order_id, 'authorized_only');
    }

    /**
     * Helper methods to set order auth flag
     *
     * @param object $order order.
     *
     * @return bool|int
     */
    public function setOrderAuthOnlyFlag($order)
    {
        $order_id = version_compare(
            WC_VERSION,
            '3.0',
            '<'
        ) ? $order->id : $order->get_id();
        return $this->updateOrderMeta($order_id, 'authorized_only', true);
    }


    /**
     * Helper methods to update order meta with scoping for this extension
     *
     * @param string $order_id order id.
     * @param string $key      key.
     * @param string $value    value.
     *
     * @return bool|int
     */
    public function updateOrderMeta($order_id, $key, $value)
    {
        return update_post_meta($order_id, "_wc_gateway_{$this->id}_{$key}", $value);
    }

    /**
     * Helper methods to get order meta with scoping for this extension
     *
     * @param string $order_id order id.
     * @param string $key      key.
     *
     * @return bool|int
     */
    public function getOrderMeta($order_id, $key)
    {
        return get_post_meta($order_id, "_wc_gateway_{$this->id}_{$key}", true);
    }

    /**
     * Helper methods to delete order meta with scoping for this extension
     *
     * @param string $order_id order id.
     * @param string $key      key.
     *
     * @return bool|int
     */
    public function deleteOrderMeta($order_id, $key)
    {
        return delete_post_meta($order_id, "_wc_gateway_{$this->id}_{$key}");
    }

    /**
     * Logs action
     *
     * @param string $context context.
     * @param string $message message.
     *
     * @return void
     */
    public function log($context, $message)
    {
        if ($this->debug) {
            if (empty($this->log)) {
                $this->log = new WC_Logger();
            }

            $this->log->add(
                'woocommerce-gateway-' . $this->id,
                $context . ' - ' . $message
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                \Sentry\captureMessage($context . ' - ' . $message);
                error_log($context . ' - ' . $message);
            }
        }
    }

    /**
     * Removes all special characters
     *
     * @param string $sku sku.
     *
     * @return string
     */
    private function _clean($sku)
    {
        $sku = str_replace(' ', '-', $sku); // Replaces all spaces with hyphens.
        return preg_replace('/[^A-Za-z0-9\-]/', '', $sku); // Removes special chars.
    }
}
