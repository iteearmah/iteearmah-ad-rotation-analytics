<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ITEA_AdServer_Reports {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_reports_menu' ), 15 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_report_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_export' ) );
	}

	public static function add_reports_menu() {
		add_submenu_page(
			'edit.php?post_type=itea_ad',
			esc_html__( 'Reports', 'iteearmah-ad-rotation-analytics' ),
			esc_html__( 'Reports', 'iteearmah-ad-rotation-analytics' ),
			'manage_options',
			'itea-adserver-reports',
			array( __CLASS__, 'render_reports_page' )
		);
	}

	public static function enqueue_report_assets( $hook ) {
		if ( strpos( $hook, 'itea-adserver-reports' ) === false ) {
			return;
		}

		$version = defined( 'ITEA_ADSERVER_VERSION' ) ? ITEA_ADSERVER_VERSION : '2.0.0';

		wp_register_script( 'chart-js', plugins_url( '../assets/js/chart.min.js', __FILE__ ), array(), '4.5.1', true );
		wp_register_script( 'itea-adserver-reports-js', plugins_url( '../assets/js/reports.js', __FILE__ ), array( 'chart-js' ), $version, true );
		wp_enqueue_script( 'itea-adserver-reports-js' );

		wp_enqueue_style( 'itea-adserver-admin', plugins_url( '../assets/css/admin.css', __FILE__ ), array(), $version );
	}

	public static function handle_export() {
		if ( ! isset( $_GET['itea_ad_export'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'itea_ad_export_report' );

		$days = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 30;
		$ad_id = isset( $_GET['ad_id'] ) ? intval( $_GET['ad_id'] ) : 0;

		$stats = ITEA_AdServer_Tracking::get_aggregated_stats( array(
			'days'  => $days,
			'ad_id' => $ad_id,
			'groupby' => 'date'
		) );

		header( 'Content-Type: text/csv; charset=utf-8' );
  header( 'Content-Disposition: attachment; filename=itea-adserver-report-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( __( 'Date', 'iteearmah-ad-rotation-analytics' ), __( 'Impressions', 'iteearmah-ad-rotation-analytics' ), __( 'Clicks', 'iteearmah-ad-rotation-analytics' ), __( 'CTR (%)', 'iteearmah-ad-rotation-analytics' ) ) );

		foreach ( $stats as $row ) {
			$ctr = $row->impressions > 0 ? ( $row->clicks / $row->impressions ) * 100 : 0;
			fputcsv( $output, array(
				$row->label,
				$row->impressions,
				$row->clicks,
				number_format( $ctr, 2 )
			) );
		}

		// Use direct PHP output for CSV download as WP_Filesystem is for local file operations.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
		exit;
	}

	public static function render_reports_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'iteearmah-ad-rotation-analytics' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 30;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ad_id = isset( $_GET['ad_id'] ) ? intval( $_GET['ad_id'] ) : 0;

		$stats = ITEA_AdServer_Tracking::get_aggregated_stats( array(
			'days'  => $days,
			'ad_id' => $ad_id,
			'groupby' => 'date'
		) );

		$device_stats = ITEA_AdServer_Tracking::get_aggregated_stats( array(
			'days'  => $days,
			'ad_id' => $ad_id,
			'groupby' => 'device'
		) );

		$country_stats = ITEA_AdServer_Tracking::get_aggregated_stats( array(
			'days'  => $days,
			'ad_id' => $ad_id,
			'groupby' => 'country'
		) );

		$total_impressions = 0;
		$total_clicks = 0;
		$chart_labels = array();
		$chart_impressions = array();
		$chart_clicks = array();

		foreach ( $stats as $row ) {
			$total_impressions += $row->impressions;
			$total_clicks += $row->clicks;
			$chart_labels[] = $row->label;
			$chart_impressions[] = $row->impressions;
			$chart_clicks[] = $row->clicks;
		}

		$avg_ctr = $total_impressions > 0 ? ( $total_clicks / $total_impressions ) * 100 : 0;

		$chart_data = array(
			'labels' => $chart_labels,
			'impressions' => $chart_impressions,
			'clicks' => $chart_clicks,
			'impressionsLabel' => __( 'Impressions', 'iteearmah-ad-rotation-analytics' ),
			'clicksLabel' => __( 'Clicks', 'iteearmah-ad-rotation-analytics' ),
		);

		wp_add_inline_script(
			'itea-adserver-reports-js',
			'window.iteaAdserverReportsData = ' . wp_json_encode( $chart_data ) . ';',
			'before'
		);

		?>
		<div class="wrap itea-adserver-reports">
			<h1><?php esc_html_e( 'Iteearmah Ad Rotation and Analytics Reports', 'iteearmah-ad-rotation-analytics' ); ?></h1>

			<div class="report-filters card">
				<form method="get" action="">
					<input type="hidden" name="post_type" value="itea_ad">
					<input type="hidden" name="page" value="itea-adserver-reports">

					<div class="filter-group">
						<label for="days"><?php esc_html_e( 'Period:', 'iteearmah-ad-rotation-analytics' ); ?></label>
						<select name="days" id="days">
							<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 Days', 'iteearmah-ad-rotation-analytics' ); ?></option>
							<option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( 'Last 30 Days', 'iteearmah-ad-rotation-analytics' ); ?></option>
							<option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( 'Last 90 Days', 'iteearmah-ad-rotation-analytics' ); ?></option>
						</select>
					</div>

					<div class="filter-group">
						<label for="ad_id"><?php esc_html_e( 'Filter by Ad:', 'iteearmah-ad-rotation-analytics' ); ?></label>
						<select name="ad_id" id="ad_id">
							<option value="0"><?php esc_html_e( 'All Ads', 'iteearmah-ad-rotation-analytics' ); ?></option>
							<?php
							$ads = get_posts( array( 'post_type' => 'itea_ad', 'posts_per_page' => 100 ) );
							foreach ( $ads as $ad ) {
								echo '<option value="' . esc_attr( $ad->ID ) . '" ' . selected( $ad_id, $ad->ID, false ) . '>' . esc_html( $ad->post_title ) . '</option>';
							}
							?>
						</select>
					</div>

					<?php submit_button( esc_html__( 'Filter', 'iteearmah-ad-rotation-analytics' ), 'secondary', 'submit', false ); ?>
				</form>

				<div class="report-actions">
					<a href="<?php echo esc_url( add_query_arg( array( 'itea_ad_export' => 1, '_wpnonce' => wp_create_nonce( 'itea_ad_export_report' ) ) ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-download" style="vertical-align: middle; margin-top: -3px; font-size: 18px;"></span>
						<?php esc_html_e( 'Export to CSV', 'iteearmah-ad-rotation-analytics' ); ?>
					</a>
				</div>
			</div>

			<div class="stats-overview">
				<div class="stat-card stat-impressions">
					<div class="stat-icon"><span class="dashicons dashicons-visibility"></span></div>
					<div class="stat-content">
						<h3><?php esc_html_e( 'Total Impressions', 'iteearmah-ad-rotation-analytics' ); ?></h3>
						<div class="stat-value"><?php echo esc_html( number_format( $total_impressions ) ); ?></div>
					</div>
				</div>
				<div class="stat-card stat-clicks">
					<div class="stat-icon"><span class="dashicons dashicons-external"></span></div>
					<div class="stat-content">
						<h3><?php esc_html_e( 'Total Clicks', 'iteearmah-ad-rotation-analytics' ); ?></h3>
						<div class="stat-value"><?php echo esc_html( number_format( $total_clicks ) ); ?></div>
					</div>
				</div>
				<div class="stat-card stat-ctr">
					<div class="stat-icon"><span class="dashicons dashicons-chart-area"></span></div>
					<div class="stat-content">
						<h3><?php esc_html_e( 'Average CTR', 'iteearmah-ad-rotation-analytics' ); ?></h3>
						<div class="stat-value"><?php echo esc_html( number_format( $avg_ctr, 2 ) ); ?>%</div>
					</div>
				</div>
			</div>

			<div class="card chart-container">
				<h2><?php esc_html_e( 'Performance Over Time', 'iteearmah-ad-rotation-analytics' ); ?></h2>
				<canvas id="performanceChart" width="400" height="150"></canvas>
			</div>

			<div class="reports-grid">
				<div class="card">
					<h2><?php esc_html_e( 'Devices', 'iteearmah-ad-rotation-analytics' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Device', 'iteearmah-ad-rotation-analytics' ); ?></th>
								<th><?php esc_html_e( 'Impressions', 'iteearmah-ad-rotation-analytics' ); ?></th>
								<th><?php esc_html_e( 'Clicks', 'iteearmah-ad-rotation-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $device_stats as $row ) : ?>
								<tr>
									<td><?php echo esc_html( ucfirst( $row->label ) ); ?></td>
  							<td><?php echo esc_html( number_format( $row->impressions ) ); ?></td>
  							<td><?php echo esc_html( number_format( $row->clicks ) ); ?></td>
  						</tr>
  					<?php endforeach; ?>
  					</tbody>
  				</table>
  			</div>

  				<div class="card">
  					<h2><?php esc_html_e( 'Top Countries', 'iteearmah-ad-rotation-analytics' ); ?></h2>
  					<table class="wp-list-table widefat fixed striped">
  						<thead>
  							<tr>
  								<th><?php esc_html_e( 'Country', 'iteearmah-ad-rotation-analytics' ); ?></th>
  								<th><?php esc_html_e( 'Impressions', 'iteearmah-ad-rotation-analytics' ); ?></th>
  								<th><?php esc_html_e( 'Clicks', 'iteearmah-ad-rotation-analytics' ); ?></th>
  							</tr>
  						</thead>
  						<tbody>
  							<?php foreach ( array_slice( $country_stats, 0, 10 ) as $row ) : ?>
  								<tr>
  									<td><?php echo esc_html( $row->label ?: __( 'Unknown', 'iteearmah-ad-rotation-analytics' ) ); ?></td>
  									<td><?php echo esc_html( number_format( $row->impressions ) ); ?></td>
  									<td><?php echo esc_html( number_format( $row->clicks ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}
