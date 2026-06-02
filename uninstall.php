<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following:
 * - This file should be as self-contained as possible.
 * - Only direct queries to the database should be used.
 * - This file should not include any other files from the plugin.
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up the database
 */
global $wpdb;

$itea_keep_data = (int) get_option( 'itea_adserver_keep_data_on_uninstall', 0 );
if ( 1 === $itea_keep_data ) {
	return;
}

// 1. Delete all ads (custom post type)
$itea_ads = get_posts( array(
	'post_type'   => 'itea_ad',
	'numberposts' => -1,
	'post_status' => 'any',
) );

foreach ( $itea_ads as $itea_ad ) {
	wp_delete_post( $itea_ad->ID, true );
}

// 2. Delete all options registered by the plugin
$itea_options = array(
	'itea_adserver_role_caps',
	'itea_adserver_allowed_users',
	'itea_adserver_keep_data_on_uninstall',
	'options_itea_adserver_allowed_users_list', // SCF Option
	'_options_itea_adserver_allowed_users_list', // SCF Option Hidden
);

foreach ( $itea_options as $itea_option ) {
	delete_option( $itea_option );
}

// 3. Drop the custom tracking table
$itea_table_name = $wpdb->prefix . 'itea_adserver_tracking';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query( "DROP TABLE IF EXISTS {$itea_table_name}" );

// 4. Clean up transients and cache version
delete_option( 'itea_adserver_cache_version' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itea_ad_stats_%' OR option_name LIKE '_transient_timeout_itea_ad_stats_%' OR option_name LIKE '_transient_itea_ad_list_%' OR option_name LIKE '_transient_timeout_itea_ad_list_%'" );
