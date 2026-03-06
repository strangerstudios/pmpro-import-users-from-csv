<?php
/*
Plugin Name: Paid Memberships Pro - Import Members From CSV Add On
Plugin URI:  https://www.paidmembershipspro.com/add-ons/pmpro-import-users-csv/
Description: Import your users or members list to WordPress and automatically assign membership levels in PMPro.
Version: 1.1.2
Author: Paid Memberships Pro
Author URI: https://www.paidmembershipspro.com
Text Domain: pmpro-import-users-from-csv
Domain Path: /languages
*/

if ( ! defined( 'PMPROIUCSV_CSV_DELIMITER' ) ) {
	define( 'PMPROIUCSV_CSV_DELIMITER', ',' );
}

/**
 * Main plugin class
 *
 * @since 0.1
 **/
class PMPro_Import_Users_From_CSV {
	private static $log_dir_path    = '';
	private static $log_dir_url     = '';
	private static $import_dir_path = '';

	/**
	 * Initialization
	 *
	 * @since 0.1
	 **/
	public static function init() {
		add_action( 'admin_menu', array( get_called_class(), 'add_admin_pages' ) );
		add_action( 'init', array( get_called_class(), 'process_csv' ) );
		add_action( 'init', array( get_called_class(), 'handle_mapping_submission' ) );
		add_action( 'init', array( get_called_class(), 'handle_cancel_mapping' ) );
		add_action( 'init', array( get_called_class(), 'pmproiucsv_load_textdomain' ) );
		add_action( 'admin_init', array( get_called_class(), 'deactivate_old_plugin' ) );
		add_action( 'admin_enqueue_scripts', array( get_called_class(), 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_pmpro_import_users_from_csv', array( get_called_class(), 'wp_ajax_pmpro_import_users_from_csv' ) );

		add_filter( 'pmpro_can_access_restricted_file', array( get_called_class(), 'pmpro_can_access_restricted_file' ), 10, 2 );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( get_called_class(), 'add_action_links' ), 10, 2);
		add_filter( 'plugin_row_meta', array( get_called_class(), 'plugin_row_meta' ), 10, 2);

		// Add support for PMPro 3.5+ with the restricted file system.
		if ( function_exists( 'pmpro_get_restricted_file_path' ) ) {
			// Create the directories for the restricted files.
			$upload_dir              = pmpro_get_restricted_file_path( 'pmpro-import-users-from-csv' );
			self::$log_dir_path      = $upload_dir . 'pmproiucsv_error.log';
			self::$log_dir_url       = add_query_arg( array( 'pmpro_restricted_file_dir' => 'pmpro-import-users-from-csv', 'pmpro_restricted_file' => 'pmproiucsv_error.log' ), home_url() );
			self::$import_dir_path   = $upload_dir;
		} else {
			$upload_dir              = wp_upload_dir();
			self::$log_dir_path      = trailingslashit( $upload_dir['basedir'] ) . 'pmproiucsv_error.log';
			self::$log_dir_url       = trailingslashit( $upload_dir['baseurl'] ) . 'pmproiucsv_error.log';
			self::$import_dir_path   = trailingslashit( $upload_dir['basedir'] ) . 'pmpro-imports/';
		}


		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/includes/import-users-from-csv.wpcli.php';
		}

		// Enable support for Paid Memberships Pro.
		if ( defined( 'PMPRO_VERSION' ) ) {
			require_once __DIR__ . '/includes/pmpro-membership-data.php';
		}

		// Include the class here.
		if ( ! class_exists( 'ReadCSV' ) ) {
			include plugin_dir_path( __FILE__ ) . 'class-readcsv.php';
		}

		do_action( 'pmproiucsv_after_init' );
	}

	/**
	 * Load the plugin's text domain for translations and localization.
	 *
	 * @since 0.1
	 */
	public static function pmproiucsv_load_textdomain() {
		load_plugin_textdomain( 'pmpro-import-users-from-csv', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Add admin menus and load the location based on PMPro installed status or not.
	 *
	 * @since 0.1
	 **/
	public static function add_admin_pages() {
		add_submenu_page( 'users.php', 'Import Members', 'Import Members', 'create_users', 'pmpro-import-users-from-csv', array( get_called_class(), 'users_page' ) );
	}

	/**
	 * Add admin JS
	 *
	 * @since TBD
	 **/
	public static function admin_enqueue_scripts( $hook ) {
		if ( empty( $_REQUEST['page'] ) || $_REQUEST['page'] != 'pmpro-import-users-from-csv' ) {
			return;
		}

		wp_enqueue_script( 'pmpro-import-users-from-csv', plugin_dir_url( __FILE__ ) . 'includes/ajaximport.js' );

		// localize the script
		wp_localize_script(
			'pmpro-import-users-from-csv',
			'pmproiucsv',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'required_headers' => apply_filters( 'pmproiucsv_required_import_headers', array( 'user_email' ) )
			)
		);
	}

	/**
	 * Process content of CSV file — saves file to disk and redirects to field mapping screen.
	 *
	 * @since 0.1
	 **/
	public static function process_csv() {
		if ( ! isset( $_REQUEST['_wpnonce_pmproiucsv_process_csv'] ) ) {
			return;
		}

		check_admin_referer( 'pmproiucsv_page_import', '_wpnonce_pmproiucsv_process_csv' );

		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( __( 'You do not have sufficient permissions to process this import.', 'pmpro-import-users-from-csv' ) );
		}

		if ( empty( $_FILES['users_csv']['tmp_name'] ) ) {
			wp_redirect( add_query_arg( 'import', 'file', wp_get_referer() ) );
			exit;
		}

		$users_update          = isset( $_REQUEST['users_update'] ) ? $_REQUEST['users_update'] : false;
		$new_user_notification = isset( $_REQUEST['new_user_notification'] ) ? $_REQUEST['new_user_notification'] : false;

		// Always save the uploaded file so we can read headers on the mapping screen.
		$import_dir = self::$import_dir_path;

		if ( ! is_dir( $import_dir ) ) {
			wp_mkdir_p( $import_dir );
		}

		// Protect the directory from direct web access when not using the PMPro restricted file system.
		if ( ! function_exists( 'pmpro_get_restricted_file_path' ) ) {
			if ( ! file_exists( $import_dir . '.htaccess' ) ) {
				if ( false === file_put_contents( $import_dir . '.htaccess', 'deny from all' ) ) {
					error_log( 'PMPro Import Users from CSV: Could not write .htaccess to ' . $import_dir . '. The import directory may be publicly accessible.' );
				}
			}
			if ( ! file_exists( $import_dir . 'index.php' ) ) {
				if ( false === file_put_contents( $import_dir . 'index.php', '<?php // Silence is golden.' ) ) {
					error_log( 'PMPro Import Users from CSV: Could not write index.php to ' . $import_dir . '. The import directory may be publicly accessible.' );
				}
			}
		}

		$original_name = $_FILES['users_csv']['name'];

		// Use extension-only validation: CSV MIME types are inconsistently reported across
		// browsers, OSes, and tools (e.g. Google Sheets exports as text/plain), making
		// wp_check_filetype_and_ext() unreliable and prone to false rejections.
		$filetype = wp_check_filetype( $original_name );
		if ( $filetype['ext'] !== 'csv' ) {
			wp_die( __( 'Invalid file type. Please upload a CSV file.', 'pmpro-import-users-from-csv' ) );
		}

		// Guard against binary files disguised with a .csv extension by checking for null bytes.
		$file_sample = file_get_contents( $_FILES['users_csv']['tmp_name'], false, null, 0, 1024 );
		if ( $file_sample !== false && strpos( $file_sample, "\x00" ) !== false ) {
			wp_die( __( 'Invalid file type. Please upload a CSV file.', 'pmpro-import-users-from-csv' ) );
		}
		$filename      = preg_replace( '/[^a-zA-Z0-9\.\-]/', '_', $original_name );
		$count         = 0;

		while ( file_exists( $import_dir . $filename ) ) {
			if ( $count ) {
				$filename = str_replace( '-' . $count . '.' . $filetype['ext'], '-' . strval( $count + 1 ) . '.' . $filetype['ext'], $filename );
			} else {
				$filename = str_replace( '.' . $filetype['ext'], '-1.' . $filetype['ext'], $filename );
			}
			$count++;
			if ( $count > 50 ) {
				wp_die( 'Error uploading file. Too many files with the same name. Clean out the ' . esc_html( $import_dir ) . ' directory on your server.' );
			}
		}

		// Enforce WordPress's configured upload size limit, since we bypass wp_handle_upload().
		if ( $_FILES['users_csv']['size'] > wp_max_upload_size() ) {
			wp_die(
				sprintf(
					/* translators: %s: max upload size */
					esc_html__( 'The uploaded file exceeds the maximum upload size of %s.', 'pmpro-import-users-from-csv' ),
					size_format( wp_max_upload_size() )
				)
			);
		}

		if ( ! move_uploaded_file( $_FILES['users_csv']['tmp_name'], $import_dir . $filename ) ) {
			wp_die( __( 'Failed to save the uploaded file. Please try again.', 'pmpro-import-users-from-csv' ) );
		}

		// Redirect to the field mapping screen.
		$url = add_query_arg(
			array(
				'page'                  => 'pmpro-import-users-from-csv',
				'import'                => 'map',
				'filename'              => $filename,
				'users_update'          => $users_update,
				'new_user_notification' => $new_user_notification,
			),
			admin_url( 'users.php' )
		);

		wp_redirect( $url );
		exit;
	}

	/**
	 * Handle the field mapping form submission.
	 * Stores the mapping in a transient and redirects to the AJAX processing screen.
	 *
	 * @since TBD
	 */
	public static function handle_mapping_submission() {
		if ( empty( $_REQUEST['_wpnonce_pmproiucsv_mapping'] ) ) {
			return;
		}

		check_admin_referer( 'pmproiucsv_mapping', '_wpnonce_pmproiucsv_mapping' );

		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( __( 'You do not have sufficient permissions to process this import.', 'pmpro-import-users-from-csv' ) );
		}

		$filename              = sanitize_file_name( wp_unslash( $_REQUEST['filename'] ) );
		$users_update          = isset( $_REQUEST['users_update'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['users_update'] ) ) : false;
		$new_user_notification = isset( $_REQUEST['new_user_notification'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['new_user_notification'] ) ) : false;

		$field_map = array();

		if ( ! empty( $_REQUEST['field_map'] ) && is_array( $_REQUEST['field_map'] ) ) {
			foreach ( $_REQUEST['field_map'] as $csv_col => $mapped_to ) {
				$csv_col   = sanitize_text_field( wp_unslash( $csv_col ) );
				$mapped_to = sanitize_text_field( wp_unslash( $mapped_to ) );

				if ( $mapped_to === '_custom_' ) {
					$field_map[ $csv_col ] = 'custom:' . sanitize_key( $csv_col );
				} else {
					$field_map[ $csv_col ] = $mapped_to; // empty string = skip
				}
			}
		}

		// Ensure all required fields are mapped before proceeding.
		$required_fields = apply_filters( 'pmproiucsv_required_import_headers', array( 'user_email' ) );
		$mapped_values   = array_values( $field_map );
		foreach ( $required_fields as $required_field ) {
			if ( ! in_array( $required_field, $mapped_values, true ) ) {
				wp_die(
					sprintf(
						/* translators: %s: required field name */
						esc_html__( 'Import cancelled: the required field "%s" must be mapped to a column before importing.', 'pmpro-import-users-from-csv' ),
						esc_html( $required_field )
					)
				);
			}
		}

		// Verify the file exists before storing the mapping transient.
		$import_dir = self::$import_dir_path;
		if ( ! file_exists( $import_dir . $filename ) ) {
			wp_die( __( 'The uploaded CSV file could not be found. Please try uploading it again.', 'pmpro-import-users-from-csv' ) );
		}

		// Store the mapping for retrieval during AJAX import.
		// Delete first to avoid stale data from a previous mapping submission for the same filename.
		delete_transient( 'pmproiucsv_map_' . $filename );
		set_transient( 'pmproiucsv_map_' . $filename, $field_map, DAY_IN_SECONDS * 2 );

		// Redirect to the AJAX processing screen.
		$url = add_query_arg(
			array(
				'page'                  => 'pmpro-import-users-from-csv',
				'import'                => 'resume',
				'filename'              => $filename,
				'users_update'          => $users_update,
				'new_user_notification' => $new_user_notification,
			),
			admin_url( 'users.php' )
		);

		wp_redirect( $url );
		exit;
	}

	/**
	 * Handle cancellation from the mapping screen.
	 *
	 * Deletes the uploaded CSV file and any stored mapping transient,
	 * then redirects back to the import page with a cancelled status.
	 *
	 * @since TBD
	 */
	public static function handle_cancel_mapping() {
		if ( empty( $_REQUEST['_wpnonce_pmproiucsv_cancel'] ) ) {
			return;
		}

		check_admin_referer( 'pmproiucsv_cancel', '_wpnonce_pmproiucsv_cancel' );

		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( __( 'You do not have sufficient permissions to cancel this import.', 'pmpro-import-users-from-csv' ) );
		}

		$filename = sanitize_file_name( wp_unslash( $_REQUEST['filename'] ?? '' ) );

		if ( $filename ) {
			$import_dir = self::$import_dir_path;
			$file_path  = $import_dir . $filename;

			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}

			delete_transient( 'pmproiucsv_map_' . $filename );
		}

		wp_redirect( add_query_arg( array( 'page' => 'pmpro-import-users-from-csv', 'import' => 'cancelled' ), admin_url( 'users.php' ) ) );
		exit;
	}


	/**
	 * Content of the settings page
	 *
	 * @since 0.1
	 **/
	public static function users_page() {
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'pmpro-import-users-from-csv' ) );
		}

		// Show PMPro Header if PMPro is installed.
		if ( defined( 'PMPRO_VERSION' ) ) {
			require_once( PMPRO_DIR . '/adminpages/admin_header.php' );
		} else {
			?>
			<div class="wrap">
			<?php
		}
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Import Users and Members from CSV', 'pmpro-import-users-from-csv' ); ?></h1>
		<?php
		$error_log_file = self::$log_dir_path;
		$error_log_url  = self::$log_dir_url;

		if ( ! file_exists( $error_log_file ) ) {
			if ( ! @fopen( $error_log_file, 'x' ) ) {
				echo '<div class="updated"><p>' . sprintf( __( 'Notice: please make the directory %s writable so that you can see the error log.', 'pmpro-import-users-from-csv' ), '<code>' . self::$log_dir_path . '</code>' ) . '</p></div>';
			}
		}

		// Show error log link if there are errors.
		if ( isset( $_REQUEST['import'] ) ) {
			$error_log_msg = '';
			if ( file_exists( $error_log_file ) ) {
				$error_log_msg = sprintf( __( ', please <a href="%s">check the error log</a>', 'pmpro-import-users-from-csv' ), $error_log_url );
			}

			switch ( $_REQUEST['import'] ) {
				case 'file':
					echo '<div class="error"><p>' . __( 'Error during file upload.', 'pmpro-import-users-from-csv' ) . '</p></div>';
					break;
				case 'data':
					echo '<div class="error"><p>' . __( 'Cannot extract data from uploaded file or no file was uploaded.', 'pmpro-import-users-from-csv' ) . '</p></div>';
					break;
				case 'fail':
					echo '<div class="error"><p>' . sprintf( __( 'No user was successfully imported%s.', 'pmpro-import-users-from-csv' ), $error_log_msg ) . '</p></div>';
					break;
				case 'errors':
					echo '<div class="error"><p>' . sprintf( __( 'Some users were successfully imported but some were not%s.', 'pmpro-import-users-from-csv' ), $error_log_msg ) . '</p></div>';
					break;
				case 'success':
					echo '<div class="updated"><p>' . __( 'Users import was successful.', 'pmpro-import-users-from-csv' ) . '</p></div>';
					break;
				case 'cancelled':
					echo '<div class="notice notice-warning"><p>' . __( 'Import cancelled. No information was imported.', 'pmpro-import-users-from-csv' ) . '</p></div>';
					break;
				default:
					break;
			}

			if ( $_REQUEST['import'] == 'map' && ! empty( $_REQUEST['filename'] ) ) {
				self::render_mapping_screen();

				// Show PMPro Footer if PMPro is installed.
				if ( defined( 'PMPRO_VERSION' ) ) {
					require_once( PMPRO_DIR . '/adminpages/admin_footer.php' );
				} else {
					echo '</div><!-- end wrap -->';
				}
				return;
			}

			if ( $_REQUEST['import'] == 'resume' && ! empty( $_REQUEST['filename'] ) ) {
				$filename              = sanitize_file_name( $_REQUEST['filename'] );
				$users_update          = isset( $_REQUEST['users_update'] ) ? $_REQUEST['users_update'] : false;
				$new_user_notification = isset( $_REQUEST['new_user_notification'] ) ? $_REQUEST['new_user_notification'] : false;

				// resetting position transients?
				if ( ! empty( $_REQUEST['reset'] ) ) {
					delete_transient( 'pmproiucsv_' . $filename );
				}
				?>
				<div id="pmproiucsv_result" style="display:none;"></div>
				<div class="pmpro_section">
					<div class="pmpro_section_inside">
						<h2><?php esc_html_e( 'Processing Import', 'pmpro-import-users-from-csv' ); ?></h2>
						<p>
							<strong><?php esc_html_e( 'Do not close this page until your import is finished processing.', 'pmpro-import-users-from-csv' ); ?></strong>
							<?php esc_html_e( 'If the import stops or if you have to close your browser, navigate to the URL below to resume the import:', 'pmpro-import-users-from-csv' ); ?>
						</p>
						<p>
							<?php
								// Build the URL to return to.
								$return_url = esc_url( add_query_arg( 'page', 'pmpro-import-users-from-csv', admin_url( 'users.php' ) ) );

								// Get the current query args and sanitize them.
								$url_query_args = array_map( 'sanitize_text_field', $_REQUEST );

								// Show the return URL.
								echo '<code>' . esc_url( add_query_arg( $url_query_args, $return_url ) ) . '</code>';
							?>
						</p>
						<hr />
						<p>
							<a id="pauseimport" href="javascript:void(0);"><?php esc_html_e( 'Click here to pause the import', 'pmpro-import-users-from-csv' ); ?></a>
							<a id="resumeimport" href="javascript:void(0);" style="display:none;"><?php esc_html_e( 'Import paused. Click here to resume the import.', 'pmpro-import-users-from-csv' ); ?></a>
						</p>
						<textarea id="importstatus" rows="10" cols="60"><?php esc_html_e( 'Loading...', 'pmpro-import-users-from-csv' ); ?></textarea>
								<p id="pmproiucsv_return_home" style="display:none;"><a href="<?php echo esc_url( add_query_arg( 'page', 'pmpro-import-users-from-csv', admin_url( 'users.php' ) ) ); ?>"><?php esc_html_e( 'Return to the Import Members From CSV screen', 'pmpro-import-users-from-csv' ); ?></a></p>
						<script>
							var ai_filename = <?php echo json_encode( $filename ); ?>;
							var ai_users_update = <?php echo json_encode( $users_update ); ?>;
							var ai_new_user_notification = <?php echo json_encode( $new_user_notification ); ?>;
							var ai_error_log_url = <?php echo json_encode( self::$log_dir_url ); ?>;
						</script>
					</div> <!-- end pmpro_section_inside -->
				</div> <!-- end pmpro_section -->
				<?php
			}
		}

		if ( empty( $_REQUEST['filename'] ) ) {
			?>
		<div class="pmpro_section">
			<div class="pmpro_section_inside">
				<form id="import_users_csv" method="post" action="" enctype="multipart/form-data">
					<?php wp_nonce_field( 'pmproiucsv_page_import', '_wpnonce_pmproiucsv_process_csv' ); ?>
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="users_csv"><?php esc_html_e( 'Import File (.csv)', 'pmpro-import-users-from-csv' ); ?></label>
								</th>
								<td>
									<input type="file" id="users_csv" name="users_csv" value="" class="all-options" accept=".csv" required /><br />
									<p class="description"><?php printf( __( 'Download the <a href="%s">example CSV file</a> for help formatting your data for import.', 'pmpro-import-users-from-csv' ), esc_url( plugin_dir_url( __FILE__ ) . 'examples/import.csv' ) ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Notify Members', 'pmpro-import-users-from-csv' ); ?></th>
								<td><fieldset>
									<legend class="screen-reader-text"><span><?php esc_html_e( 'Notify Members', 'pmpro-import-users-from-csv' ); ?></span></legend>
									<label for="new_user_notification">
										<input id="new_user_notification" name="new_user_notification" type="checkbox" value="1" />
										<?php esc_html_e( 'Send new users an email with their username and a link to reset their password.', 'pmpro-import-users-from-csv' ); ?>
									</label>
								</fieldset></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Update Existing Users', 'pmpro-import-users-from-csv' ); ?></th>
								<td><fieldset>
									<legend class="screen-reader-text"><span><?php esc_html_e( 'Update Existing Users', 'pmpro-import-users-from-csv' ); ?></span></legend>
									<label for="users_update">
										<input id="users_update" name="users_update" type="checkbox" value="1" />
										<?php esc_html_e( 'Update existing users when a matching username or email address is found (recommended).', 'pmpro-import-users-from-csv' ); ?>
									</label>
								</fieldset></td>
							</tr>
							<?php do_action( 'pmproiucsv_import_page_inside_table_bottom' ); ?>
						</tbody>
					</table>

					<?php do_action( 'pmproiucsv_import_page_after_table' ); ?>

					<p class="submit">
						<input type="submit" class="button button-primary" name="pmproiucsv_import" value="<?php esc_html_e( 'Import', 'pmpro-import-users-from-csv' ); ?>" />
					</p>
				</form>
			</div> <!-- end pmpro_section_inside -->
		</div> <!-- end pmpro_section -->
		<?php
			// Show PMPro Footer if PMPro is installed.
			if ( defined( 'PMPRO_VERSION' ) ) {
				require_once( PMPRO_DIR . '/adminpages/admin_footer.php' );
			} else {
				?>
				</div> <!-- end wrap -->
				<?php
			}
		}
	}

	/**
	 * AJAX service to import file
	 *
	 * @since ?
	 */
	public static function wp_ajax_pmpro_import_users_from_csv() {
		// check for filename
		if ( empty( $_REQUEST['filename'] ) ) {
			die( 'No file name given.' );
		} else {
			$filename = sanitize_file_name( $_REQUEST['filename'] );
		}

		// figure out upload dir
		$import_dir = self::$import_dir_path;

		// make sure file exists
		if ( ! file_exists( $import_dir . $filename ) ) {
			die( 'nofile' );
		}

		// get settings
		$users_update          = isset( $_REQUEST['users_update'] ) ? $_REQUEST['users_update'] : false;
		$new_user_notification = isset( $_REQUEST['new_user_notification'] ) ? $_REQUEST['new_user_notification'] : false;

		// Load the field mapping saved during the mapping screen step.
		$field_map = get_transient( 'pmproiucsv_map_' . $filename );
		if ( ! is_array( $field_map ) ) {
			$field_map = array();
		}

		// import next few lines of file
		$args = array(
			'partial'               => true,
			'users_update'          => $users_update,
			'new_user_notification' => $new_user_notification,
			'field_map'             => $field_map,
		);

		$results = self::import_csv( $import_dir . $filename, $args );

		// Track whether any errors have occurred across batches.
		if ( $results['errors'] ) {
			set_transient( 'pmproiucsv_errors_' . $filename, true, DAY_IN_SECONDS * 2 );
		}

		// No users imported? Import is complete.
		if ( ! $results['user_ids'] ) {
			$has_errors = get_transient( 'pmproiucsv_errors_' . $filename );

			echo $has_errors ? 'done_with_errors' : 'done';

			// delete file
			unlink( $import_dir . $filename );

			// delete position, mapping, and error transients
			delete_transient( 'pmproiucsv_' . $filename );
			delete_transient( 'pmproiucsv_map_' . $filename );
			delete_transient( 'pmproiucsv_errors_' . $filename );
		}

		// Some users imported?
		elseif ( $results['errors'] ) {
			echo 'X ';

		} else {
			echo 'Importing ' . str_pad( '', count( $results['user_ids'] ), '.' ) . "\n";
		}

		exit;
	}

	/**
	 * Import a csv file
	 *
	 * @since 0.5
	 */
	public static function import_csv( $filename, $args ) {
		if ( ! defined( 'DOING_PMPRO_IMPORT' ) ) {
			define( 'DOING_PMPRO_IMPORT', true );
		}

		$errors = $user_ids = array();

		$defaults = array(
			'new_user_notification' => false,
			'users_update'          => false,
			'partial'               => false,
			'per_partial'           => apply_filters( 'pmprocsv_ajax_import_batch', 50 ),
			'field_map'             => array(),
		);
		extract( wp_parse_args( $args, $defaults ) );

		// Cast to boolean, for some reason it's cast to a string for the AJAX.
		if ( $users_update === 'false' ) {
			$users_update = false;
		}

		if ( $new_user_notification === 'false' ) {
			$new_user_notification = false;
		}

		// User data fields list used to differentiate with user meta
		$userdata_fields = array(
			'ID',
			'user_login',
			'user_pass',
			'user_email',
			'user_url',
			'user_nicename',
			'display_name',
			'user_registered',
			'first_name',
			'last_name',
			'nickname',
			'description',
			'rich_editing',
			'comment_shortcuts',
			'admin_color',
			'use_ssl',
			'show_admin_bar_front',
			'show_admin_bar_admin',
			'role',
		);

		// We need this for AJAX calls.
		if ( ! class_exists( 'ReadCSV' ) ) {
			include plugin_dir_path( __FILE__ ) . 'class-readcsv.php';
		}

		// Loop through the file lines
		$file_handle = fopen( $filename, 'r' );
		$csv_reader  = new ReadCSV( $file_handle, PMPROIUCSV_CSV_DELIMITER, "\xEF\xBB\xBF" ); // Skip any UTF-8 byte order mark.

		$first = true;
		$rkey  = 0;
		while ( ( $line = $csv_reader->get_row() ) !== null ) {

			// If the first line is empty, abort
			// If another line is empty, just skip it
			if ( empty( $line ) ) {
				if ( $first ) {
					break;
				} else {
					continue;
				}
			}

			// If we are on the first line, the columns are the headers
			if ( $first ) {
				$headers = array_map( 'trim', $line );
				$first   = false;

				// skip ahead for partial imports
				if ( ! empty( $partial ) ) {
					$position = get_transient( 'pmproiucsv_' . basename( $filename ) );
					if ( ! empty( $position ) ) {
						$csv_reader->seek( $position );
					}
				}

				continue;
			}

			//Support multiple roles during import.
			$user_roles = array();

			// Separate user data from meta, applying any field mapping.
			$userdata = $usermeta = array();
			foreach ( $line as $ckey => $column ) {
				$csv_column = isset( $headers[ $ckey ] ) ? $headers[ $ckey ] : '';
				$column     = sanitize_text_field( trim( $column ) );

				if ( ! empty( $field_map ) ) {
					// If this CSV column was not included in the mapping, skip it.
					if ( ! array_key_exists( (string) $ckey, $field_map ) ) {
						continue;
					}

					$column_name = $field_map[ (string) $ckey ];

					// Empty mapped value means the user chose to skip this column.
					if ( $column_name === '' || $column_name === null ) {
						continue;
					}

					// Custom user meta: stored as "custom:meta_key".
					if ( strpos( $column_name, 'custom:' ) === 0 ) {
						$usermeta[ substr( $column_name, 7 ) ] = $column;
						continue;
					}
				} else {
					$column_name = $csv_column;
				}

				if ( in_array( $column_name, $userdata_fields ) ) {
					$userdata[ $column_name ] = $column;
				} else {
					$usermeta[ $column_name ] = $column;
				}
			}

			// A plugin may need to filter the data and meta
			$userdata = apply_filters( 'pmproiucsv_import_userdata', $userdata, $usermeta );
			$usermeta = apply_filters( 'pmproiucsv_import_usermeta', $usermeta, $userdata );

			// If no user data, bailout!
			if ( empty( $userdata ) ) {
				continue;
			}

			// Validate email address, if it's invalid let's skip. It causes more issues than not.
			if ( ! empty( $userdata['user_email'] ) && ! is_email( $userdata['user_email'] ) ) {
				$error_message = sprintf( __( 'Invalid email address: %s', 'pmpro-import-users-from-csv' ), $userdata['user_email'] );
				$error = new WP_Error( 'invalid_email', $error_message );
				$errors[ $rkey ] = $error;
				$rkey++;
				continue;
			}

			$user = $user_id = false;

			if ( ! empty( $userdata['ID'] ) ) {
				$user = get_user_by( 'ID', $userdata['ID'] );
			}

			// Something to be done before importing one user?
			do_action( 'pmproiucsv_pre_user_import', $userdata, $usermeta, $user );

			// If the user doesn't exist and we're updating, get them via email.
			if ( ! $user && $users_update ) {
				if ( isset( $userdata['user_login'] ) ) {
					$user = get_user_by( 'login', $userdata['user_login'] );
				}

				if ( ! $user && isset( $userdata['user_email'] ) ) {
					$user = get_user_by( 'email', $userdata['user_email'] );
				}
			}

			$update = false;
			if ( $user ) {
				$userdata['ID'] = $user->ID;
				$update         = true;
			}
			


			// If creating a new user and no password was set, let auto-generate one!
			if ( ! $update && empty( $userdata['user_pass'] ) ) {
				$userdata['user_pass'] = wp_generate_password( 12, false );
			}

			if ( ! empty( $userdata['role'] ) ) {
				$userdata['role'] = strtolower( $userdata['role'] );
				$user_roles = explode( ',', $userdata['role'] );

				// Let's trim and sanitize the roles array items.
				$user_roles = array_map( function( $role ){
					return trim( sanitize_text_field( $role ) );
				}, $user_roles );

				// Reset the array to the beginning and get the first one, for initial import - we'll add any other ones later.
				if ( count( $user_roles ) > 1 ) {
					$userdata['role'] = reset( $user_roles );
				}
			}

			if ( $update && $users_update ) {
				// If we're updating the user, don't send any password emails.
				add_filter( 'send_password_change_email', '__return_false' );  
				$user_id = wp_update_user( $userdata );
			} else {
				$user_id = wp_insert_user( $userdata );
			}

			// Is there an error o_O?
			if ( is_wp_error( $user_id ) ) {
				$errors[ $rkey ] = $user_id;
			} else {
				// If no error, let's update the user meta too!
				if ( $usermeta ) {
					foreach ( $usermeta as $metakey => $metavalue ) {
						// If the value of the meta key is empty, lets not do anything but skip it.
						if ( empty( $metavalue ) && $metavalue !== '0' ) {
							continue;
						}

						$metavalue = maybe_unserialize( $metavalue );
						update_user_meta( $user_id, $metakey, $metavalue );
					}
				}

				// Let's add the additional roles.
				if ( is_array( $user_roles ) && count( $user_roles ) > 1 ) {
					foreach( $user_roles as $user_role ) {
						$user = new WP_User( $user_id );
						$user->add_role( $user_role );
					}
				}

				// If we need to show the new password notification, let's send it.
				if ( $new_user_notification && ! $update ) {
					wp_new_user_notification( $user_id, null, 'user' );
				}
				
				// Some plugins may need to do things after one user has been imported. Who know?
				do_action( 'pmproiucsv_post_user_import', $user_id );

				$user_ids[] = $user_id;
			}

			$rkey++;

			// if doing a partial import, save our spot and break
			if ( ! empty( $partial ) && $rkey ) {
				$position = $csv_reader->get_position();
				set_transient( 'pmproiucsv_' . basename( $filename ), $position, DAY_IN_SECONDS * 2 );

				if ( $rkey > $per_partial - 1 ) {
					break;
				}
			}
		}
		fclose( $file_handle );

		// One more thing to do after all imports?
		do_action( 'pmproiucsv_post_users_import', $user_ids, $errors );

		$errors = apply_filters( 'pmproiucsv_errors_filter', $errors, $user_ids );
		
		// Let's log the errors
		self::log_errors( $errors );

		return array(
			'user_ids' => $user_ids,
			'errors'   => $errors,
		);
	}

	/**
	 * Return available field groups for the mapping screen.
	 * Filterable so add-ons can register additional field groups.
	 *
	 * @since TBD
	 * @return array Associative array of group_key => array( 'label' => string, 'fields' => array( field_key => label ) )
	 */
	public static function get_mapping_fields() {
		$fields = array(
			'wp_user' => array(
				'label'  => __( 'WordPress User Fields', 'pmpro-import-users-from-csv' ),
				'fields' => array(
					'user_email'      => __( 'Email Address', 'pmpro-import-users-from-csv' ),
					'user_login'      => __( 'Username', 'pmpro-import-users-from-csv' ),
					'user_pass'       => __( 'Password', 'pmpro-import-users-from-csv' ),
					'first_name'      => __( 'First Name', 'pmpro-import-users-from-csv' ),
					'last_name'       => __( 'Last Name', 'pmpro-import-users-from-csv' ),
					'display_name'    => __( 'Display Name', 'pmpro-import-users-from-csv' ),
					'user_nicename'   => __( 'Nicename / Slug', 'pmpro-import-users-from-csv' ),
					'user_url'        => __( 'Website URL', 'pmpro-import-users-from-csv' ),
					'user_registered' => __( 'Registration Date', 'pmpro-import-users-from-csv' ),
					'description'     => __( 'Bio / Description', 'pmpro-import-users-from-csv' ),
					'nickname'        => __( 'Nickname', 'pmpro-import-users-from-csv' ),
					'role'            => __( 'User Role', 'pmpro-import-users-from-csv' ),
					'ID'              => __( 'User ID', 'pmpro-import-users-from-csv' ),
				),
			),
		);

		if ( defined( 'PMPRO_VERSION' ) ) {
			$fields['pmpro'] = array(
				'label'  => __( 'PMPro Membership Fields', 'pmpro-import-users-from-csv' ),
				'fields' => array(
					'membership_id'                          => __( 'Membership Level ID', 'pmpro-import-users-from-csv' ),
					'membership_status'                      => __( 'Membership Status', 'pmpro-import-users-from-csv' ),
					'membership_startdate'                   => __( 'Start Date', 'pmpro-import-users-from-csv' ),
					'membership_enddate'                     => __( 'End Date', 'pmpro-import-users-from-csv' ),
					'membership_billing_amount'              => __( 'Billing Amount', 'pmpro-import-users-from-csv' ),
					'membership_cycle_number'                => __( 'Billing Cycle Number', 'pmpro-import-users-from-csv' ),
					'membership_cycle_period'                => __( 'Billing Cycle Period', 'pmpro-import-users-from-csv' ),
					'membership_initial_payment'             => __( 'Initial Payment', 'pmpro-import-users-from-csv' ),
					'membership_billing_limit'               => __( 'Billing Limit', 'pmpro-import-users-from-csv' ),
					'membership_trial_amount'                => __( 'Trial Amount', 'pmpro-import-users-from-csv' ),
					'membership_trial_limit'                 => __( 'Trial Limit', 'pmpro-import-users-from-csv' ),
					'membership_discount_code'               => __( 'Discount Code', 'pmpro-import-users-from-csv' ),
					'membership_code_id'                     => __( 'Discount Code ID', 'pmpro-import-users-from-csv' ),
					'membership_gateway'                     => __( 'Payment Gateway', 'pmpro-import-users-from-csv' ),
					'membership_subscription_transaction_id' => __( 'Subscription Transaction ID', 'pmpro-import-users-from-csv' ),
					'membership_payment_transaction_id'      => __( 'Payment Transaction ID', 'pmpro-import-users-from-csv' ),
					'membership_order_status'                => __( 'Order Status', 'pmpro-import-users-from-csv' ),
					'membership_affiliate_id'                => __( 'Affiliate ID', 'pmpro-import-users-from-csv' ),
					'membership_timestamp'                   => __( 'Order Timestamp', 'pmpro-import-users-from-csv' ),
				),
			);
		}
		
		/**
		 * Filter the dropdown fields options. 
		 * This is useful for existing plugins to hook into our process and add their own fields to the mapping screen.
		 * 
		 * @since TBD
		 * 
		 * @param array $fields The available fields for mapping, organized by group.
		 */
		return apply_filters( 'pmproiucsv_mapping_fields', $fields );
	}

	/**
	 * Try to auto-detect the best mapping for a given CSV column header.
	 * Returns a known field key on match, or an empty string if none found.
	 *
	 * @since TBD
	 * @param string $header CSV column header.
	 * @return string
	 */
	public static function auto_detect_field( $header ) {
		$header_lower = strtolower( trim( $header ) );

		// Build flat list of all known field keys.
		$all_field_keys = array();
		foreach ( self::get_mapping_fields() as $group ) {
			foreach ( $group['fields'] as $key => $label ) {
				$all_field_keys[] = strtolower( $key );
			}
		}

		// Direct match (CSV column already uses the field key, e.g. "user_email").
		if ( in_array( $header_lower, $all_field_keys, true ) ) {
			return $header_lower;
		}

		/**
		 * Common aliases to assume the field's linkage. See 'pmproiucsv_mapping_fields' for a complete list.
		 * This allows plugins to predefine or assume a column header to a field type. (i.e. 'parent_id' => 'sponsored_parent')
		 * 
		 * @since TBD
		 * 
		 * @param array $aliases An array of column header aliases and the field type.
		 */
		$aliases = apply_filters( 'pmproiucsv_field_aliases', array(
			'email'            => 'user_email',
			'e-mail'           => 'user_email',
			'mail'             => 'user_email',
			'username'         => 'user_login',
			'login'            => 'user_login',
			'password'         => 'user_pass',
			'pass'             => 'user_pass',
			'first name'       => 'first_name',
			'firstname'        => 'first_name',
			'fname'            => 'first_name',
			'last name'        => 'last_name',
			'lastname'         => 'last_name',
			'lname'            => 'last_name',
			'surname'          => 'last_name',
			'name'             => 'display_name',
			'display name'     => 'display_name',
			'full name'        => 'display_name',
			'fullname'         => 'display_name',
			'website'          => 'user_url',
			'url'              => 'user_url',
			'registered'       => 'user_registered',
			'bio'              => 'description',
			'level'            => 'membership_id',
			'level id'         => 'membership_id',
			'membership level' => 'membership_id',
			'membership'       => 'membership_id',
			'status'           => 'membership_status',
			'start date'       => 'membership_startdate',
			'startdate'        => 'membership_startdate',
			'end date'         => 'membership_enddate',
			'enddate'          => 'membership_enddate',
			'expiry'           => 'membership_enddate',
			'expiry date'      => 'membership_enddate',
			'expiration'       => 'membership_enddate',
			'expiration date'  => 'membership_enddate',
			'gateway'          => 'membership_gateway',
		) );

		if ( isset( $aliases[ $header_lower ] ) ) {
			return $aliases[ $header_lower ];
		}

		return '';
	}

	/**
	 * Read the first two rows of a saved CSV file and return them as headers + sample data.
	 *
	 * @since TBD
	 * @param string $filename Sanitized filename (no path) inside the pmpro-imports directory.
	 * @return array{ headers: string[], sample: string[] }
	 */
	public static function get_csv_sample_data( $filename ) {
		$filepath = self::$import_dir_path . $filename;

		if ( ! file_exists( $filepath ) ) {
			return array( 'headers' => array(), 'sample' => array() );
		}

		if ( ! class_exists( 'ReadCSV' ) ) {
			include plugin_dir_path( __FILE__ ) . 'class-readcsv.php';
		}

		$file_handle = fopen( $filepath, 'r' );
		if ( $file_handle === false ) {
			return array( 'headers' => array(), 'sample' => array() );
		}
		$csv_reader  = new ReadCSV( $file_handle, PMPROIUCSV_CSV_DELIMITER, "\xEF\xBB\xBF" );

		$headers = $csv_reader->get_row();
		$sample  = $csv_reader->get_row();

		fclose( $file_handle );

		return array(
			'headers' => $headers ? array_map( 'trim', $headers ) : array(),
			'sample'  => $sample  ? array_map( 'trim', $sample )  : array(),
		);
	}

	/**
	 * Render the field mapping screen.
	 *
	 * @since TBD
	 */
	public static function render_mapping_screen() {
		$filename              = sanitize_file_name( wp_unslash( $_REQUEST['filename'] ) );
		$users_update          = isset( $_REQUEST['users_update'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['users_update'] ) ) : '';
		$new_user_notification = isset( $_REQUEST['new_user_notification'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['new_user_notification'] ) ) : '';

		$csv_data = self::get_csv_sample_data( $filename );
		$headers  = $csv_data['headers'];
		$sample   = $csv_data['sample'];

		if ( empty( $headers ) ) {
			echo '<div class="error"><p>' . esc_html__( 'Could not read CSV file headers. Please try uploading your file again.', 'pmpro-import-users-from-csv' ) . '</p></div>';
			return;
		}

		// Warn if the first row looks like data rather than headers.
		$looks_like_data = false;
		foreach ( $headers as $header ) {
			if ( is_email( $header ) ) {
				$looks_like_data = true;
				break;
			}
			// Matches common date formats: YYYY-MM-DD, MM/DD/YYYY, DD-MM-YYYY, etc.
			if ( preg_match( '/^\d{1,4}[-\/]\d{1,2}[-\/]\d{1,4}$/', $header ) ) {
				$looks_like_data = true;
				break;
			}
			// Pure integer (e.g. a user ID or membership level ID).
			if ( ctype_digit( $header ) ) {
				$looks_like_data = true;
				break;
			}
		}

		if ( $looks_like_data ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Warning:', 'pmpro-import-users-from-csv' ) . '</strong> ' . esc_html__( 'It looks like your CSV may be missing a header row. The first row appears to contain user data rather than column names, which will cause the import to fail. Please add a header row to your CSV file and re-upload.', 'pmpro-import-users-from-csv' ) . '</p></div>';
		}

		$mapping_fields = self::get_mapping_fields();
		?>
		<div class="pmpro_section">
			<div class="pmpro_section_inside">
				<h2><?php esc_html_e( 'Map CSV Fields', 'pmpro-import-users-from-csv' ); ?></h2>
				<p><?php esc_html_e( 'Match each column from your CSV file to the corresponding WordPress or membership field. Columns set to "Skip" will not be imported.', 'pmpro-import-users-from-csv' ); ?></p>

				<form method="post" action="">
					<?php wp_nonce_field( 'pmproiucsv_mapping', '_wpnonce_pmproiucsv_mapping' ); ?>
					<input type="hidden" name="filename"              value="<?php echo esc_attr( $filename ); ?>">
					<input type="hidden" name="users_update"          value="<?php echo esc_attr( $users_update ); ?>">
					<input type="hidden" name="new_user_notification" value="<?php echo esc_attr( $new_user_notification ); ?>">

					<table class="widefat striped" id="pmproiucsv-mapping-table">
						<thead>
							<tr>
								<th style="width:25%"><?php esc_html_e( 'CSV Column', 'pmpro-import-users-from-csv' ); ?></th>
								<th style="width:25%"><?php esc_html_e( 'Sample Value', 'pmpro-import-users-from-csv' ); ?></th>
								<th><?php esc_html_e( 'Maps To', 'pmpro-import-users-from-csv' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $headers as $i => $header ) :
								if ( $header === '' ) {
									$header = sprintf( __( 'Column %d', 'pmpro-import-users-from-csv' ), $i + 1 );
								}
								$sample_val = isset( $sample[ $i ] ) ? $sample[ $i ] : '';
								$auto_map   = self::auto_detect_field( $header );
								$select_name = 'field_map[' . $i . ']';
								?>
							<tr>
								<td><strong><?php echo esc_html( $header ); ?></strong></td>
								<td><code><?php echo esc_html( $sample_val !== '' ? $sample_val : '—' ); ?></code></td>
								<td>
									<select
										name="<?php echo esc_attr( $select_name ); ?>"
										class="pmproiucsv-field-select"
										style="min-width:260px"
									>
										<option value=""><?php esc_html_e( '— Skip Column —', 'pmpro-import-users-from-csv' ); ?></option>
										<?php foreach ( $mapping_fields as $group_key => $group ) : ?>
										<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
											<?php foreach ( $group['fields'] as $field_value => $field_label ) : ?>
											<option value="<?php echo esc_attr( $field_value ); ?>" <?php selected( $auto_map, $field_value ); ?>>
												<?php echo esc_html( $field_label ); ?>
											</option>
											<?php endforeach; ?>
										</optgroup>
										<?php endforeach; ?>
										<option value="_custom_"><?php esc_html_e( 'Custom User Meta', 'pmpro-import-users-from-csv' ); ?></option>
										
									</select>
									<p class="pmproiucsv-custom-hint" style="display:none; margin:4px 0 0; color:#646970; font-style:italic;"><?php printf( esc_html__( 'Will be saved as meta key: %s', 'pmpro-import-users-from-csv' ), '<code>' . esc_html( sanitize_key( $header ) ) . '</code>' ); ?></p>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<script>
					jQuery( document ).ready( function( $ ) {
						$( '.pmproiucsv-field-select' ).on( 'change', function() {
							var $hint = $( this ).closest( 'td' ).find( '.pmproiucsv-custom-hint' );
							if ( $( this ).val() === '_custom_' ) {
								$hint.show();
							} else {
								$hint.hide();
							}
						} );
					} );
					</script>

					<p class="submit">
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Confirm Mapping and Import', 'pmpro-import-users-from-csv' ); ?>">
						&nbsp;
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'pmpro-import-users-from-csv', 'filename' => $filename ), admin_url( 'users.php' ) ), 'pmproiucsv_cancel', '_wpnonce_pmproiucsv_cancel' ) ); ?>" class="button">
							<?php esc_html_e( 'Cancel', 'pmpro-import-users-from-csv' ); ?>
						</a>
					</p>
				</form>
			</div><!-- end pmpro_section_inside -->
		</div><!-- end pmpro_section -->
		<?php
	}

	/**
	 * Deactivate Import Users From CSV if our plugin is activated.
	 */
	public static function deactivate_old_plugin() {
		// See if the old Import Users From CSV is activated, if so deactivate it.
		if ( is_plugin_active( 'import-users-from-csv/import-users-from-csv.php' ) ) {
			deactivate_plugins( 'import-users-from-csv/import-users-from-csv.php' );
			// Show an admin notice that it was deactivated and not needed for the PMPro Import?
			add_action( 'admin_notices', array( get_called_class(), 'admin_notice_deactivate_old_plugin' ) );
		}

	}

	/**
	 * Show an admin notice that the old plugin was deactivated.
	 */
	public static function admin_notice_deactivate_old_plugin() {
		// Show admin notice that the old plugin was deactivated.
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'Import Users From CSV is no longer required to import users and members with Paid Memberships Pro. The plugin has been deactivated.', 'pmpro-import-users-from-csv' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Log errors to a file
	 *
	 * @since 0.2
	 **/
	private static function log_errors( $errors ) {
		if ( empty( $errors ) ) {
			return;
		}

		$log = @fopen( self::$log_dir_path, 'a' );
		@fwrite( $log, sprintf( __( 'BEGIN %s', 'pmpro-import-users-from-csv' ), date_i18n( 'Y-m-d H:i:s', time() ) ) . "\n" );

		foreach ( $errors as $key => $error ) {
			$line    = $key + 1;
			$message = $error->get_error_message();
			@fwrite( $log, sprintf( __( '[Line %1$s] %2$s', 'pmpro-import-users-from-csv' ), $line, $message ) . "\n" );
		}

		@fclose( $log );
	}

	/**
	 * Add Run Import link to the plugin action links.
	 */
	public static function add_action_links($links) {
		// Only add this if plugin is active.
		if( is_plugin_active( 'pmpro-import-users-from-csv/pmpro-import-users-from-csv.php' ) ) {
			$new_links = array(
				'<a href="' . wp_nonce_url( get_admin_url( NULL, 'users.php?page=pmpro-import-users-from-csv' ), 'pmproiucsv_page_import' ) . '">' . esc_html__( 'Run Import', 'pmpro-import-users-from-csv' ) . '</a>',
			);
			return array_merge($new_links, $links);
		}

		return $links;
	}

	/**
	 * Allow access of the restricted file if the current user can create users.
	 * 
	 * @since TBD
	 *
	 * @param bool $can_access Whether or not the current user can access the log file.
	 * @param string $file_dir The directory of the file being accessed.
	 * @return bool $can_access 
	 */
	public static function pmpro_can_access_restricted_file( $can_access, $file_dir ) {
		if ( 'pmpro-import-users-from-csv' === $file_dir ) {
			// Only users who can create users should be able to access the restricted file
			// and trigger any related cleanup.
			$can_access = current_user_can( 'create_users' );

			// While we are at it, let's see if the uploads directory has pmproiucsv_error.log file.
			// Delete it as a one-time cleanup now that we moved to the pmpro restricted file system.
			if ( $can_access ) {
				$upload_dir      = wp_upload_dir();
				$error_log_file  = $upload_dir['basedir'] . '/pmproiucsv_error.log';
				if ( file_exists( $error_log_file ) && is_writable( $error_log_file ) ) {
					@unlink( $error_log_file );
				}
			}
		}
 
		return $can_access;
	}
	/**
	 * Function to add links to the plugin row meta
	 */
	public static function plugin_row_meta( $links, $file ) {
		if ( strpos( $file, 'pmpro-import-users-from-csv.php') !== false ) {
			$new_links = array(
				'<a href="' . esc_url( 'https://www.paidmembershipspro.com/add-ons/pmpro-import-users-csv/' )  . '" title="' . esc_attr( __( 'View Documentation', 'pmpro-import-users-from-csv' ) ) . '">' . __( 'Docs', 'pmpro-import-users-from-csv' ) . '</a>',
				'<a href="' . esc_url( 'https://www.paidmembershipspro.com/support/' ) . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro-import-users-from-csv' ) ) . '">' . __( 'Support', 'pmpro-import-users-from-csv' ) . '</a>',
			);
			$links = array_merge( $links, $new_links );
		}
		return $links;
	}

}

PMPro_Import_Users_From_CSV::init();