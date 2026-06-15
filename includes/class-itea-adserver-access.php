<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ITEA_AdServer_Access {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_filter( 'user_has_cap', array( __CLASS__, 'check_user_whitelist' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'init', array( __CLASS__, 'add_admin_caps' ) );
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'itea-adserver-access' ) === false ) {
			return;
		}

		if ( function_exists( 'scf_enqueue_scripts' ) ) {
			scf_enqueue_scripts();
		} elseif ( function_exists( 'acf_enqueue_scripts' ) ) {
			acf_enqueue_scripts();
		}

		$version = defined( 'ITEA_ADSERVER_VERSION' ) ? ITEA_ADSERVER_VERSION : '2.0.0';
		wp_enqueue_style( 'itea-adserver-admin-css', plugins_url( '../assets/css/admin.css', __FILE__ ), array(), $version );
		wp_enqueue_script( 'itea-adserver-admin-js', plugins_url( '../assets/js/admin.js', __FILE__ ), array( 'jquery' ), $version, true );
	}

	public static function check_user_whitelist( $allcaps, $caps, $args ) {
		// Admins always have access, regardless of whitelist.
		// We explicitly grant them our custom capabilities here to ensure visibility
		// even if the database-level role capabilities are not yet synchronized.
		if ( ! empty( $allcaps['manage_options'] ) || ! empty( $allcaps['administrator'] ) ) {
			foreach ( self::get_capabilities() as $cap => $label ) {
				$allcaps[ $cap ] = true;
			}
			return $allcaps;
		}

		if ( ! is_user_logged_in() ) {
			return $allcaps;
		}

		// Only intercept our custom capabilities
		$ad_caps = array_keys( self::get_capabilities() );
		$intercept = false;
		foreach ( $caps as $cap ) {
			if ( in_array( $cap, $ad_caps ) ) {
				$intercept = true;
				break;
			}
		}

		if ( ! $intercept ) {
			return $allcaps;
		}

		$allowed_user_ids = function_exists( 'get_field' ) ? get_field( 'itea_adserver_allowed_users_list', 'option' ) : array();

		// Fallback to old username-based whitelist if the new one is empty
		if ( empty( $allowed_user_ids ) ) {
			$allowed_users_raw = get_option( 'itea_adserver_allowed_users', '' );
			if ( empty( $allowed_users_raw ) ) {
				return $allcaps;
			}
		}

		$current_user = wp_get_current_user();
		if ( ! $current_user || ! $current_user->exists() ) {
			return $allcaps;
		}

		$is_allowed = false;
		if ( ! empty( $allowed_user_ids ) && is_array( $allowed_user_ids ) ) {
			if ( in_array( $current_user->ID, $allowed_user_ids ) ) {
				$is_allowed = true;
			}
		} else {
			// Check legacy whitelist
			$allowed_users = array_map( 'trim', explode( ',', $allowed_users_raw ) );
			if ( in_array( $current_user->user_login, $allowed_users ) ) {
				$is_allowed = true;
			}
		}

		if ( ! $is_allowed ) {
			foreach ( $ad_caps as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}

		return $allcaps;
	}

	public static function get_capabilities() {
		return array(
			'edit_ad'               => 'Edit Ad',
			'read_ad'               => 'Read Ad',
			'delete_ad'             => 'Delete Ad',
			'edit_ads'              => 'Edit Ads',
			'edit_others_ads'       => 'Edit Others Ads',
			'publish_ads'           => 'Publish Ads',
			'read_private_ads'      => 'Read Private Ads',
			'edit_private_ads'      => 'Edit Private Ads',
			'edit_published_ads'    => 'Edit Published Ads',
			'delete_ads'            => 'Delete Ads',
			'delete_others_ads'     => 'Delete Others Ads',
			'delete_private_ads'    => 'Delete Private Ads',
			'delete_published_ads'  => 'Delete Published Ads',
		);
	}

	public static function register_settings() {
		register_setting( 'itea_adserver_access_group', 'itea_adserver_role_caps', array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize_role_caps' ),
			'default'           => array(),
		) );
		register_setting( 'itea_adserver_access_group', 'itea_adserver_allowed_users', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'itea_adserver_access_group', 'itea_adserver_keep_data_on_uninstall', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );

		// Register the SCF options page slug so it's recognized
		if ( function_exists( 'scf_add_options_page' ) ) {
			scf_add_options_sub_page( array(
				'page_title'  => 'Access Configuration',
				'menu_title'  => 'Access Settings',
				'parent_slug' => 'edit.php?post_type=itea_ad',
				'menu_slug'   => 'itea-adserver-access',
				'capability'  => 'manage_options',
				'redirect'    => false,
			) );
		} elseif ( function_exists( 'acf_add_options_page' ) ) {
			acf_add_options_sub_page( array(
				'page_title'  => 'Access Configuration',
				'menu_title'  => 'Access Settings',
				'parent_slug' => 'edit.php?post_type=itea_ad',
				'menu_slug'   => 'itea-adserver-access',
				'capability'  => 'manage_options',
				'redirect'    => false,
			) );
		}
	}

	public static function add_settings_page() {
		// If SCF is not active, we still need the submenu page
		if ( ! function_exists( 'scf_add_options_page' ) && ! function_exists( 'acf_add_options_page' ) ) {
			add_submenu_page(
				'edit.php?post_type=itea_ad',
				'Access Configuration',
				'Access Settings',
				'manage_options',
				'itea-adserver-access',
				array( __CLASS__, 'render_settings_page' )
			);
		}
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'iteearmah-ad-rotation-analytics' ) );
		}

		// Ensure SCF is fully ready if available
		if ( function_exists( 'scf_render_field_wrap' ) ) {
			scf_enqueue_scripts();
		} elseif ( function_exists( 'acf_render_field_wrap' ) ) {
			acf_enqueue_scripts();
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The Secure Custom Fields plugin must be active to manage access settings.', 'iteearmah-ad-rotation-analytics' ) . '</p></div>';
			return;
		}

		if ( isset( $_POST['itea_adserver_save_access'] ) && check_admin_referer( 'itea_adserver_access_nonce' ) ) {
			self::save_role_caps();
			self::save_allowed_users();
			update_option( 'itea_adserver_keep_data_on_uninstall', isset( $_POST['itea_adserver_keep_data_on_uninstall'] ) ? 1 : 0 );

			// Save SCF fields if available
			if ( function_exists( 'scf_maybe_get_field' ) || function_exists( 'acf_maybe_get_field' ) ) {
				// We need to manually update the field since we are not using scf_form() or acf_form()
				$field_key = 'field_itea_adserver_allowed_users_list';
				if ( isset( $_POST['acf'][ $field_key ] ) || isset( $_POST['scf'][ $field_key ] ) ) {
					$scf_data = isset( $_POST['scf'][ $field_key ] ) ? $_POST['scf'][ $field_key ] : $_POST['acf'][ $field_key ];
					$scf_value = array_map( 'absint', (array) wp_unslash( $scf_data ) );
					update_field( $field_key, $scf_value, 'option' );
				}
			}

			echo '<div class="updated notice is-dismissible"><p>Settings saved successfully.</p></div>';
		}

		$roles = wp_roles()->roles;
		$caps  = self::get_capabilities();
		$allowed_users = get_option( 'itea_adserver_allowed_users', '' );
		$keep_data_on_uninstall = (int) get_option( 'itea_adserver_keep_data_on_uninstall', 0 );

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'user_access';
		?>
		<div class="wrap itea-adserver-settings">
			<h1 class="wp-heading-inline">Iteearmah Ad Rotation and Analytics Access Configuration</h1>
			<hr class="wp-header-end">

			<nav class="nav-tab-wrapper itea-adserver-tabs">
				<a href="?post_type=itea_ad&page=itea-adserver-access&tab=user_access" class="nav-tab <?php echo $active_tab === 'user_access' ? 'nav-tab-active' : ''; ?>">User Access</a>
				<a href="?post_type=itea_ad&page=itea-adserver-access&tab=role_permissions" class="nav-tab <?php echo $active_tab === 'role_permissions' ? 'nav-tab-active' : ''; ?>">Role Permissions</a>
			</nav>

			<div class="itea-adserver-tab-content">
				<form method="post">
					<?php wp_nonce_field( 'itea_adserver_access_nonce' ); ?>

					<?php if ( $active_tab === 'user_access' ) : ?>
						<div class="card">
							<h2>User Access Whitelist</h2>
							<p class="description">Restrict access to the Iteearmah Ad Rotation and Analytics management section to specific users. Administrators always have access.</p>
							<table class="form-table">
								<?php 
								$has_scf_render = function_exists( 'scf_render_field_wrap' );
								$has_acf_render = function_exists( 'acf_render_field_wrap' );
								if ( $has_scf_render || $has_acf_render ) : 
								?>
									<tr>
										<th scope="row">Allowed Users</th>
										<td>
											<?php
											if ( $has_scf_render ) {
												$field = scf_get_field_object( 'field_itea_adserver_allowed_users_list' );
												if ( $field ) {
													$field['name']  = 'scf[field_itea_adserver_allowed_users_list]';
													$field['value'] = get_field( 'itea_adserver_allowed_users_list', 'option' );
													scf_render_field_wrap( $field );
												}
											} elseif ( $has_acf_render ) {
												$field = acf_get_field( 'field_itea_adserver_allowed_users_list' );
												if ( $field ) {
													$field['name']  = 'acf[field_itea_adserver_allowed_users_list]';
													$field['value'] = get_field( 'itea_adserver_allowed_users_list', 'option' );
													acf_render_field_wrap( $field );
												}
											}
											?>
										</td>
									</tr>
								<?php else : ?>
									<tr>
										<th scope="row"><label for="itea_adserver_allowed_users">Allowed Usernames (Legacy)</label></th>
										<td>
											<textarea name="itea_adserver_allowed_users" id="itea_adserver_allowed_users" rows="5" class="large-text" placeholder="user1, user2, user3"><?php echo esc_textarea( get_option( 'itea_adserver_allowed_users', '' ) ); ?></textarea>
											<p class="description">Enter usernames separated by commas. <strong>Note:</strong> Secure Custom Fields plugin is recommended for a better user selection experience.</p>
										</td>
									</tr>
								<?php endif; ?>
							</table>
						</div>

						<div class="card">
							<h2>Uninstall Data Handling</h2>
							<table class="form-table">
								<tr>
									<th scope="row">Keep data on uninstall</th>
									<td>
										<label for="itea_adserver_keep_data_on_uninstall">
											<input type="checkbox" id="itea_adserver_keep_data_on_uninstall" name="itea_adserver_keep_data_on_uninstall" value="1" <?php checked( $keep_data_on_uninstall, 1 ); ?>>
											Preserve ads, tracking data, and plugin settings when uninstalling.
										</label>
										<p class="description">If unchecked, all plugin data will be permanently deleted during uninstall.</p>
									</td>
								</tr>
							</table>
						</div>
					<?php else : ?>
						<div class="card">
							<h2>Role Permissions Matrix</h2>
							<p class="description">Configure which capabilities each role should have for managing advertisements.</p>
							<div class="matrix-container" style="overflow-x: auto;">
								<table class="wp-list-table widefat fixed striped">
									<thead>
										<tr>
											<th class="role-column">Role</th>
											<?php foreach ( $caps as $cap => $label ) : ?>
												<th class="cap-column"><?php echo esc_html( $label ); ?></th>
											<?php endforeach; ?>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $roles as $role_key => $role_data ) : ?>
											<tr>
												<td class="role-name"><strong><?php echo esc_html( $role_data['name'] ); ?></strong></td>
												<?php foreach ( $caps as $cap => $label ) : ?>
													<td class="cap-check">
														<?php
														$is_admin = ( $role_key === 'administrator' );
														$checked  = isset( $role_data['capabilities'][ $cap ] ) && $role_data['capabilities'][ $cap ];
														if ( $is_admin ) {
															$checked = true; // Admins always have it
														}
														?>
														<input type="checkbox" name="role_caps[<?php echo esc_attr( $role_key ); ?>][<?php echo esc_attr( $cap ); ?>]" value="1" <?php checked( $checked ); ?> <?php disabled( $is_admin ); ?>>
													</td>
												<?php endforeach; ?>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					<?php endif; ?>

					<p class="submit">
						<input type="submit" name="itea_adserver_save_access" class="button button-primary button-hero" value="Save Changes">
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	public static function sanitize_role_caps( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$sanitized = array();
		$valid_roles = array_keys( wp_roles()->roles );
		$valid_caps  = array_keys( self::get_capabilities() );
		foreach ( $input as $role => $caps ) {
			$role = sanitize_key( $role );
			if ( ! in_array( $role, $valid_roles, true ) ) {
				continue;
			}
			$sanitized[ $role ] = array();
			foreach ( (array) $caps as $cap => $val ) {
				$cap = sanitize_key( $cap );
				if ( in_array( $cap, $valid_caps, true ) ) {
					$sanitized[ $role ][ $cap ] = absint( $val );
				}
			}
		}
		return $sanitized;
	}

	private static function save_role_caps() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_POST['role_caps'] ) || ! check_admin_referer( 'itea_adserver_access_nonce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted_caps = self::sanitize_role_caps( wp_unslash( $_POST['role_caps'] ) );
		$roles = wp_roles();
		$available_caps = self::get_capabilities();

		foreach ( $roles->roles as $role_key => $role_data ) {
			if ( $role_key === 'administrator' ) continue;

			$role = get_role( $role_key );
			if ( ! $role ) continue;

			foreach ( $available_caps as $cap => $label ) {
				if ( isset( $submitted_caps[ $role_key ][ $cap ] ) ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	private static function save_allowed_users() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! check_admin_referer( 'itea_adserver_access_nonce' ) ) {
			return;
		}

		$allowed_users = isset( $_POST['itea_adserver_allowed_users'] ) ? sanitize_text_field( wp_unslash( $_POST['itea_adserver_allowed_users'] ) ) : '';
		update_option( 'itea_adserver_allowed_users', $allowed_users );
	}

	public static function add_admin_caps() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::get_capabilities() as $cap => $label ) {
				$admin->add_cap( $cap );
			}
		}
	}
}
