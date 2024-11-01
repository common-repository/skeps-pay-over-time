<?php

/**
 * * WC_Gateway_Skeps_BNPL_Skeps_API
 *
 * WC_Gateway_Skeps_BNPL_Skeps_API connects to the elavon skeps API
 * to do all charge actions ie capture, return void, auth
 *
 * PHP version 7.2
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

/**
 * * WC_Gateway_Skeps_BNPL_Skeps_API
 *
 * WC_Gateway_Skeps_BNPL_Skeps_API connects to skeps API
 * to do all charge actions ie capture, return void, auth
 *
 * PHP version 7.2
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Skeps_BNPL
 * @package  WooCommerce
 * @author   Skeps <developer@skeps.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.skeps.com/
 */
class WC_Gateway_Skeps_BNPL_Skeps_API
{

	/**
	 * Pointer to gateway making the request
	 *
	 * @var WC_Gateway_Skeps_BNPL
	 */
	protected $gateway;


	/**
	 * Server Token for all interactions with Elavon's Skeps API
	 *
	 * @var string
	 */
	protected $server_token;


	/**
	 * Constructor
	 *
	 * @param array  $gateway  gateway.
	 */
	public function __construct($gateway)
	{
		$this->gateway  = $gateway;
	}

	public function get_server_token()
	{
		if (!empty($this->server_token)) {
			return $this->server_token;
		}

		$wc_session = WC()->session;

		// Sanitize and escape input from the session
		$st_in_session = isset($wc_session) ? sanitize_text_field($wc_session->get('skeps_bnpl_server_token')) : '';
		$st_exp_in_session = isset($wc_session) ? sanitize_text_field($wc_session->get('skeps_bnpl_server_token_expiry')) : '';

		if ($st_in_session !== '' && $st_exp_in_session !== '') {
			$is_st_expired = time() > (int)$st_exp_in_session;
			if (!$is_st_expired) {
				// Output escaping before returning
				$this->server_token = $st_in_session;
				$this->gateway->log(
					__FUNCTION__,
					$this->server_token
				);
				return $this->server_token;
			}
		}

		// Sanitize and escape input from the database
		$server_token_in_db = get_option('skeps_financing_bnpl_server_token');
		if (!empty($server_token_in_db)) {
			$st_in_db = sanitize_text_field($server_token_in_db['server_token']);
			$st_expiry_in_db = sanitize_text_field($server_token_in_db['server_token_expiry']);
			$is_st_db_expired = time() > (int)$st_expiry_in_db;
			if (!$is_st_db_expired) {
				// Output escaping before returning
				if (isset($wc_session)) {
					$wc_session->set('skeps_bnpl_server_token_expiry', $st_expiry_in_db);
					$wc_session->set('skeps_bnpl_server_token', $st_in_db);
				}
				$this->server_token = esc_attr($st_in_db);
				return $this->server_token;
			}
		}

		// Generate and sanitize the server token
		$serverToken = $this->generate_server_token();
		if ($serverToken) {
			return esc_attr($serverToken);
		} else {
			return false;
		}
	}


	/**
	 * Generate server token
	 *
	 * @return string
	 * @since  1.0.0
	 */
	private function generate_server_token()
	{
		try {
			delete_option('skeps_financing_bnpl_server_token');

			// Sanitize input from the gateway properties
			$client_id = sanitize_text_field($this->gateway->client_id);
			$client_secret = sanitize_text_field($this->gateway->client_secret);

			$response = $this->post_authenticated_json_request(
				"/application/api/pos/v1/oauth/server/token",
				array(
					'clientId' => $client_id,
					'clientSecret' => $client_secret,
					'grantType' => 'client_credentials'
				),
				true
			);

			// Output escaping before logging or returning
			$response_json = wp_json_encode($response, JSON_UNESCAPED_UNICODE);

			if (is_wp_error($response)) {
				\Sentry\captureMessage('Error in generate server token: ' . esc_html($response->get_error_message()));
				$this->gateway->log(
					__FUNCTION__,
					'Error in generate server token: ' . esc_html($response->get_error_message())
				);
				return false;
			}

			if (!array_key_exists('response', $response)) {
				\Sentry\captureMessage("Generate server token failed {$response_json}.");
				$this->gateway->log(
					__FUNCTION__,
					"Generate server token failed {$response_json}."
				);
				return false;
			}

			$response_response = $response['response'];
			if (!array_key_exists('code', $response_response)) {
				\Sentry\captureMessage("Unable to generate server token. Response Status {$response_json}.");
				$this->gateway->log(
					__FUNCTION__,
					"Unable to generate server token. Response Status {$response_json}."
				);
				return false;
			}

			if (200 !== $response_response['code']) {
				return false;
			}

			if (!array_key_exists('body', $response)) {
				\Sentry\captureMessage("Error in generate server token. Required request body is missing {$response_json}.");
				$this->gateway->log(
					__FUNCTION__,
					"Error in generate server token. Required request body is missing {$response_json}."
				);
				return false;
			}

			$body = json_decode($response['body'], true);

			// Sanitize and escape the server token
			$serverToken = isset($body['accessToken']) ? sanitize_text_field($body['accessToken']) : '';
			$expiresIn = isset($body['expiresIn']) ? intval($body['expiresIn']) : 0;

			// Calculate the expiry time for the token
			$server_token_expiry = (int)time() + $expiresIn;

			// Update the option with the sanitized and escaped data
			update_option('skeps_financing_bnpl_server_token', array(
				'server_token' => $serverToken,
				'server_token_expiry' => $server_token_expiry
			));

			$this->server_token = $serverToken;

			// Output escaping before setting session data
			$wc_session = WC()->session;
			if (isset($wc_session)) {
				$wc_session->set('skeps_bnpl_server_token_expiry', intval(time()) + $expiresIn);
				$wc_session->set('skeps_bnpl_server_token', $serverToken);
			}

			return $serverToken;
		} catch (\Throwable $exception) {
			// Capture exception after escaping
			\Sentry\captureException($exception);
		}
	}

	/**
	 * Save WC Settings - Get Merchant Config
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public function get_merchant_config()
	{
		try {
			// Sanitize the merchant ID
			$merchant_id = sanitize_text_field($this->gateway->merchant_id);

			$response = $this->get_authenticated_json_request(
				"/application/api/pos/v1/merchant-config/fnbo/merchant/" . $merchant_id . "/asset/woocommerce.json"
			);

			// Output escaping before logging or returning
			$response_json = wp_json_encode($response, JSON_UNESCAPED_UNICODE);

			if (is_wp_error($response)) {
				\Sentry\captureMessage('Error in Merchant config : ' . esc_html($response->get_error_message()));
				$this->gateway->log(
					__FUNCTION__,
					'Error in Merchant Config : ' . esc_html($response->get_error_message())
				);
				return false;
			}

			if (!array_key_exists('response', $response)) {
				\Sentry\captureMessage("Merchant Config API failed. {$response_json}.");
				$this->gateway->log(
					__FUNCTION__,
					"Merchant Config API failed. {$response_json}."
				);
				return false;
			}

			$response_response = $response['response'];
			if (!array_key_exists('code', $response_response)) {
				\Sentry\captureMessage("Error in Merchant Config. Response Status {$response_json}.");
				$this->gateway->log(
					__FUNCTION__,
					"Error in Merchant Config. Response Status {$response_json}."
				);
				return false;
			}

			if (200 !== $response_response['code']) {
				return false;
			}

			if (!array_key_exists('body', $response)) {
				\Sentry\captureMessage("Error in Merchant Config. Required request body is missing {$response_json}.");
				$this->gateway->log(
					__FUNCTION__,
					"Error in Merchant Config. Required request body is missing {$response_json}."
				);
				return false;
			}

			// Sanitize and escape the response body
			$decoded_response = json_decode($response['body'], true);

			// Output escaping before returning
			return $decoded_response ? array_map('esc_html', $decoded_response) : false;

		} catch (\Throwable $exception) {
			// Capture exception after escaping
			\Sentry\captureException($exception);
		}
	}


	/**
	 * Process Payment
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public function process_payment($order_amount, $bnpl_order_id)
	{
		try {

			$headers = array(
				'orderId' => sanitize_text_field($bnpl_order_id),  // Sanitize order ID
				'merchantId' => sanitize_text_field($this->gateway->merchant_id)  // Sanitize merchant ID
			);

			$body = array(
				'currency' => 'USD',
				'amount' => floatval($order_amount)
			);

			$response = $this->post_authenticated_json_request(
				"/application/api/merchant/v1/order/fund",
				$body,
				false,
				$headers
			);

			if (is_wp_error($response)) {
				\Sentry\captureMessage('Error in Payment : ' . $response->get_error_message());
				$this->gateway->log(
					__FUNCTION__,
					'Error in Payment : ' . $response->get_error_message()
				);
				return false;
			}

			if (!array_key_exists('response', $response)) {
				\Sentry\captureMessage("Payment failed. {json_encode($response)}.");
				$this->gateway->log(
					__FUNCTION__,
					"Payment failed. {json_encode($response)}."
				);
				return false;
			}

			$response_response = $response['response'];
			if (!array_key_exists('code', $response_response)) {
				\Sentry\captureMessage("Unable to make Payment. Response Status {json_encode($response_response)}.");
				$this->gateway->log(
					__FUNCTION__,
					"Unable to make Payment. Response Status {json_encode($response_response)}."
				);
				return false;
			}

			if (401 === $response_response['code']) {
				\Sentry\captureMessage("Payment Unauthorized Response Status {json_encode($response_response)}.");
				$this->gateway->log(
					__FUNCTION__,
					"Payment Unauthorized Response Status {json_encode($response_response)}."
				);
				$this->generate_server_token();
				return $this->process_payment($order_amount, $bnpl_order_id);
			}

			if (200 !== $response_response['code']) {
				return false;
			}

			if (!array_key_exists('body', $response)) {
				\Sentry\captureMessage("Error in Payment. Required request body is missing {json_encode($response)}.");
				$this->gateway->log(
					__FUNCTION__,
					"Error in Payment. Required request body is missing {json_encode($response)}."
				);
				return false;
			}

			return json_decode($response['body'], true);

		} catch (\Throwable $exception) {
			\Sentry\captureException($exception);
		}
	}

	/**
	 * Process a refund
	 *
	 * @param int    $order_id      order id.
	 * @param float  $refund_amount refund amount.
	 * @param string $reason        reason.
	 *
	 * @return boolean|WP_Error
	 */
	public function process_refund( $order_id, $refund_amount = null, $reason = '' ) {
		try {
			$this->gateway->log(
				__FUNCTION__,
				"Info: Beginning processing refund/void for order $order_id"
			);
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$this->gateway->log( __FUNCTION__, "Error: Order {$order_id} could not be found." );
				return new WP_Error(
					'error',
					__(
						'Refund failed: Unable to retrieve order',
						'woocommerce-skeps-pay-over-time'
					)
				);
			}

			$order_total = floatval( $order->get_total() );
			if ( ! $refund_amount ) {
				$refund_amount = $order_total;
			}

			$bnpl_order_id = $order->get_transaction_id();
			$headers = array(
				'orderId' => sanitize_text_field($bnpl_order_id),  // Sanitize order ID
				'merchantId' => sanitize_text_field($this->gateway->merchant_id)  // Sanitize merchant ID
			);

			$body = array(
				'currency' => 'USD',
				'amount' => floatval($refund_amount)
			);

			$response = $this->post_authenticated_json_request(
				"/application/api/merchant/v1/order/refund",
				$body,
				false,
				$headers
			);

			if (is_wp_error($response)) {
				return new WP_Error('error', $response->get_error_message());
				\Sentry\captureMessage('Error in Refund : ' . $response->get_error_message());
				$this->gateway->log(
					__FUNCTION__,
					'Error in Refund : ' . $response->get_error_message()
				);
				return false;
			}

			if (!array_key_exists('response', $response)) {
				\Sentry\captureMessage("Refund failed. {json_encode($response)}.");
				$this->gateway->log(
					__FUNCTION__,
					"Refund failed. {json_encode($response)}."
				);
				return false;
			}

			$response_response = $response['response'];
			$decoded_response = json_decode(json_encode($response['body']), true);

			if (!array_key_exists('code', $response_response)) {
				\Sentry\captureMessage("Unable to make Refund. Response Status {json_encode($response_response)}.");
				$this->gateway->log(
					__FUNCTION__,
					"Unable to make Refund. Response Status {json_encode($response_response)}."
				);
				return false;
			}

			if (401 === $response_response['code']) {
				\Sentry\captureMessage("Payment Unauthorized Response Status {json_encode($response_response)}.");
				$this->gateway->log(
					__FUNCTION__,
					"Payment Unauthorized Response Status {json_encode($response_response)}."
				);
				$this->generate_server_token();
				return $this->process_refund($bnpl_order_id, $refund_amount, '');
			}

			if (200 !== $response_response['code']) {
				return new WP_Error(
					'error',
					__(
						'Refund failed: The order had been authorized and captured, but refunding the order unexpectedly failed.',
						'woocommerce-skeps-pay-over-time'
					)
				);
				return false;
			}

			if (!array_key_exists('body', $response)) {
				\Sentry\captureMessage("Error in Refund. Required request body is missing {json_encode($response)}.");
				$this->gateway->log(
					__FUNCTION__,
					"Error in Refund. Required request body is missing {json_encode($response)}."
				);
				return false;
			}

			$refund_response = json_decode($decoded_response, true);
			$order->add_order_note(
				sprintf(
				/* translators: 1: refund amount 2: refund application id 3: reason */
					__(
						'Refunded %1$s - Refund Application ID: %2$s - Reason: %3$s',
						'woocommerce-skeps-pay-over-time'
					),
					wc_price( $refund_amount ),
					esc_html( $refund_response['applicationId'] ),
					esc_html( $reason )
				)
			);

			$this->gateway->log(
				__FUNCTION__,
				"Info: Successfully refunded {$refund_amount} for order {$order_id}"
			);
			return json_decode($refund_response['success'], true);
		} catch (\Throwable $exception) {
			\Sentry\captureException($exception);
			return new WP_Error('error', $exception);
		}
	}


	/**
	 * Helper to POST json data to Elavon Skeps using JSON Format
	 *
	 * @param string $route The API endpoint we are POSTing to e.g. 'api/pos/v1'.
	 * @param array  $body  The data (if any) to jsonify and POST to the endpoint.
	 * @param boolean $tokenRequest flag to skip auth header for server token request.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private function post_authenticated_json_request($route, $body = false, $tokenRequest = false, $extra_headers = false)
	{
		$server = $this->gateway->api_url;
		$url = $server . $route;
		// Sanitize and escape the URL using esc_url()
		$url = esc_url($url);
		$headers = array(
			'Content-Type'  => 'application/json',
		);
		if (!$tokenRequest) {
			if (empty($this->server_token)) {
				$serverToken = $this->get_server_token();
				if (!$serverToken) {
					return false;
				} else {
					return $this->post_authenticated_json_request($route, $body, $tokenRequest, $extra_headers);
				}
			} else {
				$headers = array_merge($headers, array(
					'Authorization' => 'Bearer ' . $this->server_token
				));
			}
		}

		// Sanitize and escape the extra headers using esc_attr()
		if ($extra_headers && is_array($extra_headers)) {
			foreach ($extra_headers as $key => $value) {
				$headers[$key] = esc_attr($value);
			}
		}
		$options = array(
			'method'  => 'POST',
			'headers' => $headers,
			'timeout' => 60
		);

		if (!empty($body)) {
			$options['body'] = wp_json_encode($body);
		}

		return wp_safe_remote_post($url, $options);
	}

	/**
	 * Helper to POST json data to Elavon Skeps using JSON Format
	 *
	 * @param string $route The API endpoint we are POSTing to e.g. 'api/pos/v1'.
	 * @param array  $body  The data (if any) to jsonify and POST to the endpoint.
	 * @param boolean $tokenRequest flag to skip auth header for server token request.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private function get_authenticated_json_request($route)
	{
		$server = $this->gateway->api_url;
		$url = $server . $route;
		return wp_safe_remote_get($url);
	}

}
