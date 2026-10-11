<?php
/**
 * Plugin Name:          KetaPay Escrow for WooCommerce
 * Plugin URI:           https://github.com/KetaPay/ketapay-woocommerce
 * Description:          Buyers pay into KetaPay escrow. KetaPay holds the money until they confirm they received their order, then pays you.
 * x-release-please-start-version
 * Version:              0.1.0-beta.1
 * x-release-please-end
 * Requires at least:    6.6
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      10.2
 * Author:               KetaPay
 * Author URI:           https://ketapay.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          ketapay-woocommerce
 *
 * @package KetaPay\WooCommerce
 */

// This file only uses syntax old PHP can parse, so a site on an unsupported PHP sees a notice
// instead of a fatal error. Everything else lives in src/ and needs PHP 8.1.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'KetaPay Escrow for WooCommerce needs PHP 8.1 or later. Ask your host to upgrade PHP.', 'ketapay-woocommerce' ) . '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return; // WooCommerce is not active; "Requires Plugins" tells the store owner.
		}
		\KetaPay\WooCommerce\Plugin::boot( __FILE__ );
	}
);
