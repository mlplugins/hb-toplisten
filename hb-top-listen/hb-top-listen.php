<?php
/**
 * Plugin Name:       HB Top-Listen
 * Plugin URI:        https://github.com/mlplugins/hb-toplisten
 * Description:       Automatische Top-Produkte und Top-Kategorien aus echten Verkaufsdaten (WooCommerce Analytics) fuer das Flatsome-Theme. Konfiguration direkt im UX Builder.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            mlplugins
 * Text Domain:       hb-top-listen
 * Domain Path:       /languages
 *
 * @package HB_Top_Listen
 */

// Direktaufruf verhindern.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HB_TOP_LISTEN_VERSION', '0.1.0' );
define( 'HB_TOP_LISTEN_FILE', __FILE__ );
define( 'HB_TOP_LISTEN_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Etappe-1-Geruest. Die eigentliche Logik (Berechnungs-Engine, Caching,
 * UX-Builder-Elemente, Admin-Kontrolltabelle) folgt in Etappe 2.
 * Siehe HANDOFF-ETAPPE-2.md im Projekt-Repo.
 */
