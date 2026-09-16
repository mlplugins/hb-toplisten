<?php
/**
 * Aufräumen beim Löschen des Plugins.
 *
 * @package HB_Top_Listen
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'hb_top_listen_registry' );
delete_option( 'hb_top_listen_cache_gen' );
wp_clear_scheduled_hook( 'hb_top_listen_refresh' );
wp_clear_scheduled_hook( 'hb_top_listen_midnight' );

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_hb_tl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_hb_tl_' ) . '%'
	)
);
