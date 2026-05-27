<?php

/*
Plugin Name: Iteearmah Ad Rotation and Analytics
Plugin URI: https://github.com/iteearmah/wp-adserver
Description: A specialized plugin to manage, rotate, track, and serve advertisements.
Version: 1.8.1
Author: Samuel Attoh Armah
Author URI: https://github.com/iteearmah
License: GPL2
Text Domain: iteearmah-ad-rotation-analytics
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ITEA_ADSERVER_VERSION', '1.8.1' );

// Load the modular system
require_once plugin_dir_path( __FILE__ ) . 'includes/class-itea-adserver-loader.php';

/**
 * Check if Secure Custom Fields is active
 */
function itea_adserver_check_dependencies() {
	if ( ! function_exists( 'acf_add_local_field_group' ) && ! class_exists( 'ACF' ) ) {
		add_action( 'admin_notices', 'itea_adserver_scf_missing_notice' );
		return false;
	}
	return true;
}

/**
 * Display admin notice if SCF is missing
 */
function itea_adserver_scf_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ( $screen->parent_base !== 'edit.php?post_type=itea_ad' && $screen->id !== 'plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php echo wp_kses_post( __( '<strong>Iteearmah Ad Rotation and Analytics</strong> requires the <strong>Secure Custom Fields</strong> (formerly ACF) plugin to be installed and active for full functionality.', 'iteearmah-ad-rotation-analytics' ) ); ?></p>
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
	itea_adserver_check_dependencies();
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

itea_adserver_init();
