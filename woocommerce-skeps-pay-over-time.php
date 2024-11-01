<?php
/**
 * Plugin Name: Skeps Pay-Over-Time
 * Description: Provides Pay-Over-Time options with monthly payment plans including no interest promos.
 * Author: Skeps
 * Author URI: https://skeps.com/
 * Version: 1.1
 * WC tested up to: 6.3
 * WC requires at least: 3.2
 *
 * Copyright (c) Streamsource Technologies Inc
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * php version 8.1
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Skeps_BNPL
 * @package  WooCommerce
 * @author   Skeps <developer@skeps.com>
 * @license  http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link     https://www.skeps.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define('SKEPS_BNPL_PLUGIN_FILE_URL', __FILE__);

// Include the main WooCommerce class.
if ( ! class_exists( 'WooCommerce_Gateway_Skeps_Financing', false ) ) {
	include_once dirname( __FILE__ ) . '/class-woocommerce-gateway-skeps-financing.php';
}

/**
 * Returns Skeps Pay-Over-Time instance.
 */
function skepsBnpl() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid.
	return WooCommerce_Gateway_Skeps_Financing::get_instance();
}

/**
 * Loads Skeps Pay-Over-Time.
 */
$GLOBALS['wc_skeps_bnpl_loader'] = skepsBnpl();


