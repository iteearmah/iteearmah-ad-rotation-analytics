<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ITEA_AdServer_Loader {

	public function __construct() {
		$this->load_dependencies();
		$this->init_components();
	}

	private function load_dependencies() {
		require_once plugin_dir_path( __FILE__ ) . 'class-itea-adserver-post-types.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-itea-adserver-fields.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-itea-adserver-tracking.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-itea-adserver-renderer.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-itea-adserver-admin.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-itea-adserver-access.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-itea-adserver-reports.php';
	}

	private function init_components() {
		ITEA_AdServer_Post_Types::init();
		ITEA_AdServer_Fields::init();
		ITEA_AdServer_Tracking::init();
		ITEA_AdServer_Renderer::init();
		ITEA_AdServer_Admin::init();
		ITEA_AdServer_Access::init();
		ITEA_AdServer_Reports::init();
	}
}
