<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ITEA_AdServer_Tracking {

	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'itea_adserver_tracking';
	}

	public static function get_legacy_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'ITEA_AdServer_Tracking';
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_repair_table' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_click_tracking' ) );
		add_action( 'init', array( __CLASS__, 'handle_script_request' ), 1 );
	}

	public static function table_exists( $table_name ) {
		global $wpdb;
		$existing_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $existing_table === $table_name;
	}

	public static function maybe_repair_table() {
		$table_name = self::get_table_name();
		if ( self::table_exists( $table_name ) ) {
			return;
		}

		global $wpdb;
		$legacy_table_name = self::get_legacy_table_name();
		if ( self::table_exists( $legacy_table_name ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( "RENAME TABLE `{$legacy_table_name}` TO `{$table_name}`" );
		}

		if ( ! self::table_exists( $table_name ) ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		$table_name = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			ad_id bigint(20) NOT NULL,
			event_type varchar(20) NOT NULL,
			country varchar(10) DEFAULT '',
			device varchar(20) DEFAULT '',
			timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY ad_id (ad_id),
			KEY event_type (event_type),
			KEY timestamp (timestamp)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	public static function handle_click_tracking() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['itea_ad_click'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ad_id = intval( $_GET['itea_ad_click'] );
			self::track_event( $ad_id, 'click' );

			$dest_url = function_exists( 'get_field' ) ? get_field( 'itea_ad_destination_url', $ad_id ) : get_post_meta( $ad_id, 'itea_ad_destination_url', true );
			if ( $dest_url && wp_http_validate_url( $dest_url ) ) {
				wp_safe_redirect( esc_url_raw( $dest_url ) );
				exit;
			}
			wp_safe_redirect( home_url() );
			exit;
		}
	}

	public static function track_event( $ad_id, $type ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$country = self::get_visitor_country();
		$device  = self::get_visitor_device();

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO $table_name (ad_id, event_type, country, device, timestamp) VALUES (%d, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ad_id, $type, $country, $device, current_time( 'mysql' )
		) );

		// Clear stat cache for this ad/type
		delete_transient( 'itea_ad_stats_' . $ad_id . '_' . $type );
		delete_transient( 'itea_ad_stats_aggregated' ); // Clear aggregated cache too

		// Invalidate zone list cache if impression limit might be reached
		// This is a safety measure to ensure ads are removed from rotation when limits hit
		// We don't know the zone, so we clear all zone lists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_itea_ad_list_%'" );
	}

	public static function get_visitor_country() {
		static $visitor_country = null;
		if ( null !== $visitor_country ) {
			return $visitor_country;
		}

		$headers = array(
			'HTTP_CF_IPCOUNTRY',
			'HTTP_X_COUNTRY_CODE',
			'HTTP_X_REAL_COUNTRY',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$visitor_country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				return $visitor_country;
			}
		}

		$visitor_country = 'Unknown';
		return $visitor_country;
	}

	/**
	 * Detect visitor device type (mobile, tablet, desktop)
	 */
	public static function get_visitor_device() {
		static $visitor_device = null;
		if ( null !== $visitor_device ) {
			return $visitor_device;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( empty( $user_agent ) ) {
			$visitor_device = 'desktop';
			return $visitor_device;
		}

		// Use WordPress's built-in wp_is_mobile() as a baseline, but refine it
		if ( wp_is_mobile() ) {
			if ( preg_match( '/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $user_agent ) ) {
				$visitor_device = 'tablet';
			} else {
				$visitor_device = 'mobile';
			}
		} else {
			$visitor_device = 'desktop';
		}

		return $visitor_device;
	}

	/**
	 * Get total stats for an ad with transient caching.
	 */
	public static function get_total_stats( $ad_id, $type ) {
		$cache_key = 'itea_ad_stats_' . $ad_id . '_' . $type;
		$total = get_transient( $cache_key );

		if ( false === $total ) {
			global $wpdb;
			$table_name = self::get_table_name();

			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE ad_id = %d AND event_type = %s", // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$ad_id,
				$type
			) );

			// Cache for 1 hour (3600 seconds)
			set_transient( $cache_key, $total, HOUR_IN_SECONDS );
		}

		return (int) $total;
	}

	/**
	 * Get statistics for an ad from the custom tracking table.
	 */
	public static function get_ad_stats( $ad_id, $days = 7 ) {
		global $wpdb;
		$table_name = self::get_table_name();
		$results = array();

		for ( $i = 0; $i < $days; $i++ ) {
			$date = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
			$day_start = $date . ' 00:00:00';
			$day_end   = $date . ' 23:59:59';

			// Get impressions
			$impressions = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE ad_id = %d AND event_type = 'impression' AND timestamp BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$ad_id,
				$day_start,
				$day_end
			) );

			// Get clicks
			$clicks = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE ad_id = %d AND event_type = 'click' AND timestamp BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$ad_id,
				$day_start,
				$day_end
			) );

			// Get top countries
			$countries_data = $wpdb->get_results( $wpdb->prepare(
				"SELECT country, COUNT(*) as count FROM $table_name WHERE ad_id = %d AND event_type = 'impression' AND timestamp BETWEEN %s AND %s AND country != '' GROUP BY country ORDER BY count DESC LIMIT 3", // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$ad_id,
				$day_start,
				$day_end
			) );

			$countries = array();
			foreach ( $countries_data as $row ) {
				if ( $row->country ) {
					$countries[ $row->country ] = $row->count;
				}
			}

			$results[ $date ] = array(
				'impression' => (int) $impressions,
				'click'      => (int) $clicks,
				'countries'  => $countries,
			);
		}

		return $results;
	}

	/**
	 * Get aggregated stats for reporting.
	 */
	public static function get_aggregated_stats( $args = array() ) {
		$cache_key = 'itea_ad_stats_aggregated_' . md5( serialize( $args ) );
		$results = get_transient( $cache_key );

		if ( false !== $results ) {
			return $results;
		}

		global $wpdb;
		$table_name = self::get_table_name();

		$defaults = array(
			'days'    => 30,
			'ad_id'   => 0,
			'groupby' => 'date', // date, ad, country, device
		);
		$args = wp_parse_args( $args, $defaults );

		$days = intval( $args['days'] );
		$start_date = gmdate( 'Y-m-d 00:00:00', strtotime( "-$days days" ) );

		$where = $wpdb->prepare( "timestamp >= %s", $start_date );
		if ( $args['ad_id'] ) {
			$where .= $wpdb->prepare( " AND ad_id = %d", $args['ad_id'] );
		}

		$select = "";
		$groupby = "";

		switch ( $args['groupby'] ) {
			case 'ad':
				$select = "ad_id as label";
				$groupby = "ad_id";
				break;
			case 'country':
				$select = "country as label";
				$groupby = "country";
				break;
			case 'device':
				$select = "device as label";
				$groupby = "device";
				break;
			case 'date':
			default:
				$select = "DATE(timestamp) as label";
				$groupby = "DATE(timestamp)";
				break;
		}

		$sql = "SELECT $select,
				SUM(CASE WHEN event_type = 'impression' THEN 1 ELSE 0 END) as impressions,
				SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) as clicks
				FROM $table_name
				WHERE $where
				GROUP BY $groupby
				ORDER BY label ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $sql );

		// Cache for 1 hour (aggregated stats don't need to be real-time in reports)
		set_transient( $cache_key, $results, HOUR_IN_SECONDS );

		return $results;
	}

	public static function handle_script_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['itea_ad_serve'] ) ) {
			header( 'Content-Type: application/javascript' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$zone = isset( $_GET['zone'] ) ? sanitize_text_field( wp_unslash( $_GET['zone'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$uid  = isset( $_GET['uid'] ) ? sanitize_text_field( wp_unslash( $_GET['uid'] ) ) : '';

			$debug_info = '';
			$ad_html = ITEA_AdServer_Renderer::render_ad( $zone, $debug_info );

			if ( ! $ad_html ) {
				if ( current_user_can( 'manage_options' ) ) {
					$error_msg = 'Iteearmah Ad Rotation and Analytics: No eligible ads found for zone "' . esc_html( $zone ) . '".';
					if ( $debug_info ) {
						$error_msg .= ' Reason: ' . esc_html( $debug_info );
					}
					$ad_html = '<div style="border:1px dashed #ccc; padding:10px; color:#666; font-size:12px;">' . $error_msg . '</div>';
				} else {
					// Add a comment to the JS output for debugging instead of silent exit
					if ( $uid ) {
 					echo '// Iteearmah Ad Rotation and Analytics: No eligible ads found for zone ' . wp_json_encode( $zone );
					}
					exit;
				}
			}

			if ( $uid ) {
				echo "(function() {
					var serveAd = function() {
						var container = document.getElementById(" . wp_json_encode( $uid ) . ");
						if (!container) {
							// If container doesn't exist, create it before the script tag
							container = document.createElement('div');
							container.id = " . wp_json_encode( $uid ) . ";
							var scripts = document.getElementsByTagName('script');
							var thisScript = scripts[scripts.length - 1];
							thisScript.parentNode.insertBefore(container, thisScript);
						}

						var xhr = new XMLHttpRequest();
						xhr.open('GET', " . wp_json_encode( admin_url( 'admin-ajax.php' ) . '?action=itea_adserver_get_ad&zone=' . urlencode( $zone ) ) . ", true);
						xhr.onload = function() {
							if (xhr.status >= 200 && xhr.status < 400) {
								var response = JSON.parse(xhr.responseText);
								if (response.success && response.data.html) {
								container.innerHTML = response.data.html;

									// Execute scripts
									var scripts = container.getElementsByTagName('script');
									var scriptsCount = scripts.length;
									for (var i = 0; i < scriptsCount; i++) {
										var script = document.createElement('script');
										if (scripts[i].src) {
											script.src = scripts[i].src;
										} else {
											script.textContent = scripts[i].textContent;
										}
										document.head.appendChild(script).parentNode.removeChild(script);
									}
								}
							}
						};
						xhr.send();
					};
					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', serveAd);
					} else {
						serveAd();
					}
				})();";
			} else {
				// Fallback for legacy tags or direct calls without UID
				echo "document.write(" . wp_json_encode( $ad_html ) . ");";
			}
			exit;
		}
	}
}
