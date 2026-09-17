<?php
/**
 * Plugin Name:          HB Top-Listen
 * Plugin URI:           https://github.com/mlplugins/hb-toplisten
 * Description:          Automatische Top-Produkte und Top-Kategorien aus echten Verkaufsdaten (WooCommerce Analytics) für das Flatsome-Theme. Konfiguration direkt im UX Builder.
 * Version:              0.3.0
 * Requires at least:    6.4
 * Requires PHP:         8.0
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * Author:               mlplugins
 * Text Domain:          hb-top-listen
 * Domain Path:          /languages
 *
 * @package HB_Top_Listen
 */

// Direktaufruf verhindern.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HB_TOP_LISTEN_VERSION', '0.3.0' );
define( 'HB_TOP_LISTEN_FILE', __FILE__ );
define( 'HB_TOP_LISTEN_DIR', plugin_dir_path( __FILE__ ) );

require_once HB_TOP_LISTEN_DIR . 'includes/class-hb-top-listen-ranking.php';
require_once HB_TOP_LISTEN_DIR . 'includes/class-hb-top-listen-cache.php';
require_once HB_TOP_LISTEN_DIR . 'includes/class-hb-top-listen-elements.php';
require_once HB_TOP_LISTEN_DIR . 'includes/class-hb-top-listen-admin.php';

/*
 * HPOS-Kompatibilität: Das Plugin liest ausschliesslich die Analytics-Tabellen
 * (wc_order_product_lookup / wc_order_stats), nie Bestellungen aus wp_posts.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HB_TOP_LISTEN_FILE, true );
		}
	}
);

register_activation_hook( HB_TOP_LISTEN_FILE, array( 'HB_Top_Listen_Cache', 'activate' ) );
register_deactivation_hook( HB_TOP_LISTEN_FILE, array( 'HB_Top_Listen_Cache', 'deactivate' ) );

HB_Top_Listen_Cache::init();
HB_Top_Listen_Elements::init();
HB_Top_Listen_Admin::init();
