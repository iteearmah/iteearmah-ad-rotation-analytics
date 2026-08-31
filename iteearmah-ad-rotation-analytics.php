<?php

/*
Plugin Name: Iteearmah Ad Rotation and Analytics
Plugin URI: https://github.com/iteearmah/wp-adserver
Description: A specialized plugin to manage, rotate, track, and serve advertisements.
Version: 2.2.5
Author: Samuel Attoh Armah
Author URI: https://github.com/iteearmah
Donate link: https://buymeacoffee.com/iteearmah
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: iteearmah-ad-rotation-analytics
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ITEA_ADSERVER_VERSION', '2.2.5' );


// Load the modular system
require_once plugin_dir_path( __FILE__ ) . 'includes/class-itea-adserver-loader.php';

/**
 * Check if Secure Custom Fields is active
 */
function itea_adserver_check_dependencies() {
	if ( ! function_exists( 'scf_add_local_field_group' ) && ! function_exists( 'acf_add_local_field_group' ) && ! class_exists( 'ACF' ) && ! itea_adserver_is_scf_plugin_active() ) {
		add_action( 'admin_notices', 'itea_adserver_scf_missing_notice' );
		return false;
	}
	return true;
}

/**
 * Check active plugin records for Secure Custom Fields/ACF.
 *
 * This covers admin screens where this plugin may load before SCF has registered
 * its functions/classes.
 */
function itea_adserver_is_scf_plugin_active() {
	if ( ! function_exists( 'is_plugin_active' ) && is_admin() ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$dependency_plugins = array(
		'secure-custom-fields/secure-custom-fields.php',
		'advanced-custom-fields/acf.php',
		'advanced-custom-fields-pro/acf.php',
	);

	foreach ( $dependency_plugins as $plugin ) {
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin ) ) {
			return true;
		}

		if ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Display admin notice if SCF is missing
 */
function itea_adserver_scf_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ( ! in_array( $screen->parent_base, array( 'edit.php?post_type=itea_ad', 'iteearmah-ad-rotation-analytics' ), true ) && $screen->id !== 'plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php echo wp_kses_post( __( '<strong>Iteearmah Ad Rotation and Analytics</strong> requires the <strong>Secure Custom Fields</strong> plugin to be installed and active for full functionality.', 'iteearmah-ad-rotation-analytics' ) ); ?></p>
		<p>
			<a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=search&s=secure+custom+fields' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Install Secure Custom Fields', 'iteearmah-ad-rotation-analytics' ); ?></a>
		</p>
	</div>
	<?php
}

/**
 * Initialize the plugin
 */
function itea_adserver_init() {
	new ITEA_AdServer_Loader();
}

/**
 * Activate the plugin
 */
function itea_adserver_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-itea-adserver-tracking.php';
	ITEA_AdServer_Tracking::create_tables();
}
register_activation_hook( __FILE__, 'itea_adserver_activate' );

add_action( 'plugins_loaded', 'itea_adserver_check_dependencies', 20 );

itea_adserver_init();
