<?php
/**
 * Cleanup tasks when uninstalling 410 for WordPress.
 *
 * @package MCLV_410_Plugin
 */

// Exit if accessed directly or not during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Drop the plugin's custom table during uninstall.
 *
 * @return void
 */
function mclv_410_remove_table() {
	global $wpdb;

	$table = esc_sql( $wpdb->prefix . '410_links' );

	// Schema change is intentional during uninstall, caching does not apply here.
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

mclv_410_remove_table();
delete_option( 'mclv_410_options_version' );
delete_option( 'mclv_410_max_404s' );
