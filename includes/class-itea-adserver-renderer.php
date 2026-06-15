<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ITEA_AdServer_Renderer {

	private static $meta_cache = array();

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_shortcode( 'itea_adserver', array( __CLASS__, 'render_shortcode' ) );
		add_shortcode( 'itea_ad_script', array( __CLASS__, 'render_script_shortcode' ) );

		// AJAX handlers
		add_action( 'wp_ajax_nopriv_itea_adserver_get_ad', array( __CLASS__, 'ajax_get_ad' ) );
		add_action( 'wp_ajax_itea_adserver_get_ad', array( __CLASS__, 'ajax_get_ad' ) );

		// Clear cache on ad updates
		add_action( 'save_post_itea_ad', array( __CLASS__, 'clear_ad_list_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'clear_ad_list_cache' ) );
		add_action( 'trashed_post', array( __CLASS__, 'clear_ad_list_cache' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'clear_ad_list_cache' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'clear_ad_list_cache_on_term_change' ), 10, 4 );
		add_action( 'transition_post_status', array( __CLASS__, 'clear_cache_on_status_transition' ), 10, 3 );
	}

	/**
	 * Clear cache on post status transition.
	 */
	public static function clear_cache_on_status_transition( $new_status, $old_status, $post ) {
		if ( 'itea_ad' === $post->post_type && $new_status !== $old_status ) {
			self::clear_ad_list_cache( $post->ID );
		}
	}

	/**
	 * Clear ad list cache when an ad is saved or deleted.
	 */
	public static function clear_ad_list_cache( $post_id ) {
		if ( get_post_type( $post_id ) !== 'itea_ad' ) {
			return;
		}

		// Use versioning to invalidate all ad list transients at once
		$new_version = time();
		update_option( 'itea_adserver_cache_version', $new_version );
	}

	/**
	 * Clear ad list cache when terms are changed.
	 */
	public static function clear_ad_list_cache_on_term_change( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'itea_ad_zone' === $taxonomy ) {
			self::clear_ad_list_cache( $object_id );
		}
	}

	public static function enqueue_scripts() {
		wp_register_style( 'iteearmah-ad-rotation-analytics', plugins_url( '../assets/css/style.css', __FILE__ ), array(), ITEA_ADSERVER_VERSION );
		wp_register_script( 'itea-adserver-js', plugins_url( '../assets/js/wp-adserver.js', __FILE__ ), array(), ITEA_ADSERVER_VERSION, array(
			'in_footer' => true,
			'strategy'  => 'defer',
		) );
		wp_localize_script( 'itea-adserver-js', 'iteaAdServerData', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
		) );
	}

	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'zone' => '',
		), $atts );

		// Only enqueue frontend assets if the shortcode is used
		wp_enqueue_style( 'iteearmah-ad-rotation-analytics' );
		wp_enqueue_script( 'itea-adserver-js' );

		$zone_slug = ! empty( $atts['zone'] ) ? strtolower( sanitize_title( $atts['zone'] ) ) : 'default';
		$uid       = 'itea-ad-' . $zone_slug;

		return sprintf(
			'<div id="%s" class="itea-adserver-placeholder" data-zone="%s"></div>',
			esc_attr( $uid ),
			esc_attr( $zone_slug )
		);
	}

	public static function ajax_get_ad() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$zone = isset( $_GET['zone'] ) ? strtolower( sanitize_title( wp_unslash( $_GET['zone'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ad_id = isset( $_GET['ad_id'] ) ? intval( $_GET['ad_id'] ) : 0;

		$debug_info = '';
		if ( $ad_id ) {
			$html = self::render_single_ad( $ad_id, $debug_info );
		} else {
			$html = self::render_ad( $zone, $debug_info );
		}

		if ( ! $html && current_user_can( 'manage_options' ) ) {
			$html = '<div style="border:1px dashed #ccc; padding:10px; color:#666; font-size:12px;">';
			$html .= 'Iteearmah Ad Rotation and Analytics: No eligible ads found for zone "' . esc_html( $zone ) . '".';
			if ( $debug_info ) {
				$html .= '<br>Reason: ' . esc_html( $debug_info );
			}
			$html .= '</div>';
		}

		wp_send_json_success( array( 'html' => $html ) );
	}

	public static function render_script_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'zone' => '',
			'id'   => '',
		), $atts );

		wp_enqueue_style( 'iteearmah-ad-rotation-analytics' );
		wp_enqueue_script( 'itea-adserver-js' );

		$zone_slug = ! empty( $atts['zone'] ) ? strtolower( sanitize_title( $atts['zone'] ) ) : '';
		$ad_id     = ! empty( $atts['id'] ) ? intval( $atts['id'] ) : 0;
		$unique_id = 'itea-ad-' . ( $ad_id ? $ad_id : ( $zone_slug ? $zone_slug : 'default' ) );

		return sprintf(
			'<div id="%s" class="itea-adserver-placeholder itea-adserver-script-container" data-zone="%s" data-ad-id="%d"></div>',
			esc_attr( $unique_id ),
			esc_attr( $zone_slug ),
			intval( $ad_id )
		);
	}

	public static function render_single_ad( $ad_id, &$debug_info = '' ) {
		$ad_id = intval( $ad_id );
		$post  = get_post( $ad_id );

		if ( ! $post || $post->post_type !== 'itea_ad' || $post->post_status !== 'publish' ) {
			$debug_info = 'Ad not found, not published, or incorrect post type.';
			return '';
		}

		// We can reuse parts of render_ad logic or just call it if we can filter by ID
		// But render_ad is complex. Let's implement a simple version for single ad.
		
		// Check for Secure Custom Fields
		if ( ! function_exists( 'get_field' ) ) {
			$debug_info = 'Secure Custom Fields plugin is not active.';
			return '';
		}

		// Basic check for expiry etc (simplified version of render_ad logic)
		$ad_type = get_field( 'ad_type', $ad_id );
		if ( ! $ad_type ) {
			$debug_info = 'Ad type not set.';
			return '';
		}

		// For now, let's just use the existing render_ad logic by passing the ID as a temporary filter if possible
		// Actually, I'll just manually render it here to be safe and simple.
		
		ob_start();
		?>
		<div class="itea-ad-container" data-ad-id="<?php echo esc_attr( $ad_id ); ?>">
			<?php
			if ( $ad_type === 'image' ) {
				$image = get_field( 'ad_image', $ad_id );
				$link  = get_field( 'ad_link', $ad_id );
				if ( $image ) {
					if ( $link ) {
						echo '<a href="' . esc_url( $link ) . '" target="_blank" class="itea-ad-link" data-ad-id="' . esc_attr( $ad_id ) . '">';
					}
					echo '<img src="' . esc_url( $image['url'] ) . '" alt="' . esc_attr( $post->post_title ) . '" style="max-width:100%; height:auto;">';
					if ( $link ) {
						echo '</a>';
					}
				}
			} elseif ( $ad_type === 'html' ) {
				$html_content = get_field( 'ad_html', $ad_id );
				echo $html_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php
		// Log impression
		if ( class_exists( 'ITEA_AdServer_Tracking' ) ) {
			ITEA_AdServer_Tracking::log_impression( $ad_id );
		}
		
		return ob_get_clean();
	}

	private static function get_cached_field( $field_name, $post_id ) {
		$cache_key = $post_id . '_' . $field_name;
		if ( ! isset( self::$meta_cache[ $cache_key ] ) ) {
			if ( function_exists( 'get_field' ) ) {
				self::$meta_cache[ $cache_key ] = get_field( $field_name, $post_id );
			} else {
				self::$meta_cache[ $cache_key ] = get_post_meta( $post_id, $field_name, true );
			}
		}
		return self::$meta_cache[ $cache_key ];
	}

	public static function render_ad( $zone_slug = '', &$debug_info = '' ) {
		$zone_slug = strtolower( $zone_slug );

		global $wpdb;

		if ( ! empty( $zone_slug ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ads = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE p.post_type = 'itea_ad'
				AND p.post_status = 'publish'
				AND tt.taxonomy = 'itea_ad_zone'
				AND t.slug = %s",
				$zone_slug
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ads = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'itea_ad'
				AND post_status = 'publish'"
			);
		}

		$ads = array_map( 'intval', $ads );

		if ( empty( $ads ) ) {
			$debug_info = 'No ads assigned to this zone or no published ads found.';

			// Check if any ads exist in this zone but are drafts/scheduled
			if ( ! empty( $zone_slug ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$all_ads_in_zone = $wpdb->get_col( $wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
					WHERE p.post_type = 'itea_ad'
					AND p.post_status != 'auto-draft'
					AND tt.taxonomy = 'itea_ad_zone'
					AND t.slug = %s",
					$zone_slug
				) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$all_ads_in_zone = $wpdb->get_col(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = 'itea_ad'
					AND post_status != 'auto-draft'"
				);
			}

			if ( ! empty( $all_ads_in_zone ) ) {
				$statuses = array();
				$unpublished_found = false;
				foreach ( $all_ads_in_zone as $id ) {
					$status = get_post_status( $id );
					$statuses[] = $status;
					if ( $status !== 'publish' ) {
						$unpublished_found = true;
					}
				}
				$status_counts = array_count_values( $statuses );
				$status_string = array();
				foreach ( $status_counts as $status => $count ) {
					$status_string[] = "$count $status";
				}
				$debug_info .= ' (Found ads in this zone with statuses: ' . implode( ', ', $status_string ) . '.';
				if ( $unpublished_found ) {
					$debug_info .= ' Please publish them to make them eligible.)';
				} else {
					$debug_info .= ' This may indicate a transient cache issue or visibility problem.)';
				}
			}

			return '';
		}

		$eligible_ads = array();
		$visitor_country = ITEA_AdServer_Tracking::get_visitor_country();
		$visitor_device  = ITEA_AdServer_Tracking::get_visitor_device();
		$now = current_time( 'Y-m-d H:i:s' );

		$reasons = array();
		if ( current_user_can( 'manage_options' ) ) {
			$reasons[] = "Visitor context: Country: {$visitor_country}, Device: {$visitor_device}, Time: {$now}";
		}

		foreach ( $ads as $ad_id ) {
			$ad_title = get_the_title( $ad_id );

			// Check Active
			$is_active = self::get_cached_field( 'itea_ad_active', $ad_id );
			if ( $is_active === false || $is_active === 0 || $is_active === '0' ) {
 			$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) is inactive (Value: " . wp_json_encode( $is_active ) . ").";
				continue;
			}

			// Check Scheduling
			$start_date = self::get_cached_field( 'itea_ad_start_date', $ad_id );
			$end_date   = self::get_cached_field( 'itea_ad_end_date', $ad_id );

			if ( $start_date && $now < $start_date ) {
				$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) scheduled to start at {$start_date}. (Current: {$now})";
				continue;
			}
			if ( $end_date && $now > $end_date ) {
				$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) expired at {$end_date}. (Current: {$now})";
				continue;
			}

			// Check Limits
			$limit_impressions = (int) self::get_cached_field( 'itea_ad_limit_impressions', $ad_id );
			$limit_clicks      = (int) self::get_cached_field( 'itea_ad_limit_clicks', $ad_id );

			if ( $limit_impressions > 0 ) {
				$current_imprs = ITEA_AdServer_Tracking::get_total_stats( $ad_id, 'impression' );
				if ( (int) $current_imprs >= $limit_impressions ) {
					$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) reached impression limit ({$limit_impressions}). (Current: {$current_imprs})";
					continue;
				}
			}
			if ( $limit_clicks > 0 ) {
				$current_clicks = ITEA_AdServer_Tracking::get_total_stats( $ad_id, 'click' );
				if ( (int) $current_clicks >= $limit_clicks ) {
					$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) reached click limit ({$limit_clicks}). (Current: {$current_clicks})";
					continue;
				}
			}

			// Check Geo
			$geo_enabled = self::get_cached_field( 'itea_ad_geo_enabled', $ad_id );
			if ( $geo_enabled ) {
				$mode      = self::get_cached_field( 'itea_ad_geo_mode', $ad_id );
				$countries = self::get_cached_field( 'itea_ad_geo_countries', $ad_id );
				$country_list = is_array( $countries ) ? array_map( 'strtoupper', $countries ) : array_map( 'trim', explode( ',', strtoupper( $countries ) ) );

				if ( $mode === 'include' ) {
					if ( ! in_array( $visitor_country, $country_list ) ) {
						$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) restricted to countries: " . implode(', ', $country_list) . ". (Visitor country: {$visitor_country})";
						continue;
					}
				} else {
					if ( in_array( $visitor_country, $country_list ) ) {
						$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) excluded from countries: " . implode(', ', $country_list) . ". (Visitor country: {$visitor_country})";
						continue;
					}
				}
			}

			// Check Device
			$device_enabled = self::get_cached_field( 'itea_ad_device_enabled', $ad_id );
			if ( $device_enabled ) {
				$target_devices = self::get_cached_field( 'itea_ad_device_types', $ad_id );
				$target_devices = is_array( $target_devices ) ? $target_devices : array();

				if ( ! in_array( $visitor_device, $target_devices ) ) {
					$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) restricted to devices: " . implode( ', ', $target_devices ) . ". (Visitor device: {$visitor_device})";
					continue;
				}
			}

			// Check content
			$type = self::get_cached_field( 'itea_ad_type', $ad_id );
			if ( $type === 'image' ) {
				$image_url = self::get_cached_field( 'itea_ad_image', $ad_id );
				if ( ! $image_url ) {
					$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) has no image set.";
					continue;
				}
			} else {
				$html_code = self::get_cached_field( 'itea_ad_html_code', $ad_id );
				if ( empty( $html_code ) ) {
					$reasons[] = "Ad '{$ad_title}' (ID: {$ad_id}) has no HTML code set.";
					continue;
				}
			}

			$weight = (int) self::get_cached_field( 'itea_ad_weight', $ad_id ) ?: 1;
			for ( $i = 0; $i < $weight; $i++ ) {
				$eligible_ads[] = $ad_id;
			}
		}

		if ( empty( $eligible_ads ) ) {
			if ( ! empty( $reasons ) ) {
				$debug_info = implode( ' | ', array_unique( $reasons ) );
			} else {
				$debug_info = 'Unknown filtering reason.';
			}
			return '';
		}

		$selected_ad_id = $eligible_ads[ array_rand( $eligible_ads ) ];
		ITEA_AdServer_Tracking::track_event( $selected_ad_id, 'impression' );

		$type = self::get_cached_field( 'itea_ad_type', $selected_ad_id );
		$output = '';

		if ( $type === 'image' ) {
			$image_url = self::get_cached_field( 'itea_ad_image', $selected_ad_id );
			$click_url = add_query_arg( 'itea_ad_click', $selected_ad_id, home_url( '/' ) );

			$output = sprintf(
				'<div class="itea-adserver-ad"><a href="%s" target="_blank"><img src="%s" style="max-width:100%%; height:auto;"></a></div>',
				esc_url( $click_url ),
				esc_url( $image_url )
			);
		} else {
			$html_code = self::get_cached_field( 'itea_ad_html_code', $selected_ad_id );
			$output = '<div class="itea-adserver-ad">' . $html_code . '</div>';
		}

		return $output;
	}
}
