<?php
/*
Plugin Name: Paid Memberships Pro - Import Users from CSV Add On
Plugin URI: http://www.paidmembershipspro.com/pmpro-import-users-from-csv/
Description: Add-on for the Import Users From CSV plugin to import PMPro and membership-related fields.
Version: 1.0
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
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
	private static $log_dir_path = '';
	private static $log_dir_url  = '';

	/**
	 * Initialization
	 *
	 * @since 0.1
	 **/
	public static function init() {
		add_action( 'admin_menu', array( get_called_class(), 'add_admin_pages' ) );
		add_action( 'init', array( get_called_class(), 'process_csv' ) );
		add_action( 'init', array( get_called_class(), 'pmproiufcsv_load_textdomain' ) );
		add_action( 'admin_enqueue_scripts', array( get_called_class(), 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_pmpro_import_users_from_csv', array( get_called_class(), 'wp_ajax_pmpro_import_users_from_csv' ) );

		$upload_dir         = wp_upload_dir();
		self::$log_dir_path = trailingslashit( $upload_dir['basedir'] );
		self::$log_dir_url  = trailingslashit( $upload_dir['baseurl'] );

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
	public static function pmproiufcsv_load_textdomain() {
		load_plugin_textdomain( 'pmpro-import-users-from-csv', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Add admin menus and load the location based on PMPro installed status or not.
	 *
	 * @since 0.1
	 **/
	public static function add_admin_pages() {
		$location = defined( 'PMPRO_VERSION' ) ? 'options-general.php' : 'users.php';
		add_submenu_page( $location, 'Import From CSV', 'Import From CSV', 'manage_options', 'pmpro-import-users-from-csv', array( get_called_class(), 'users_page' ) );
	}

	/**
	 * Add admin JS
	 *
	 * @since ?
	 **/
	public static function admin_enqueue_scripts( $hook ) {
		if ( empty( $_GET['page'] ) || $_GET['page'] != 'pmpro-import-users-from-csv' ) {
			return;
		}

		wp_enqueue_script( 'pmpro-import-users-from-csv', plugin_dir_url( __FILE__ ) . 'includes/ajaximport.js' );
	}

	/**
	 * Process content of CSV file
	 *
	 * @since 0.1
	 **/
	public static function process_csv() {
		if ( isset( $_REQUEST['_wpnonce_pmproiucsv_process_csv'] ) ) {
			check_admin_referer( 'pmproiucsv_page_import', '_wpnonce_pmproiucsv_process_csv' );

			if ( isset( $_FILES['users_csv']['tmp_name'] ) ) {
				// Setup settings variables
				$filename              = $_FILES['users_csv']['tmp_name'];
				$users_update          = isset( $_POST['users_update'] ) ? $_POST['users_update'] : false;
				$new_user_notification = isset( $_POST['new_user_notification'] ) ? $_POST['new_user_notification'] : false;

				/// Check this at a later stage.
				// Before continuing, let's make sure the CSV file is valid.
				// $is_csv_valid = self::is_csv_valid( $filename );			

				// use AJAX?
				if ( ! empty( $_POST['ajaximport'] ) ) {
					// check for a imports directory in wp-content
					$upload_dir = wp_upload_dir();
					$import_dir = $upload_dir['basedir'] . '/imports/';

					// create the dir and subdir if needed
					if ( ! is_dir( $import_dir ) ) {
						wp_mkdir_p( $import_dir );
					}

					// figure out filename
					$filename = $_FILES['users_csv']['name'];
					$count    = 0;

					while ( file_exists( $import_dir . $filename ) ) {
						if ( $count ) {
							$filename = str_replace( '-' . $count . '.' . $filetype['ext'], '-' . strval( $count + 1 ) . '.' . $filetype['ext'], $filename );
						} else {
							$filename = str_replace( '.' . $filetype['ext'], '-1.' . $filetype['ext'], $filename );
						}

						$count++;

						// let's not expect more than 50 files with the same name
						if ( $count > 50 ) {
							die( 'Error uploading file. Too many files with the same name. Clean out the ' . $import_dir . ' directory on your server.' );
						}
					}

					// save file
					if ( strpos( $_FILES['users_csv']['tmp_name'], $upload_dir['basedir'] ) !== false ) {
						// was uploaded and saved to $_SESSION
						rename( $_FILES['users_csv']['tmp_name'], $import_dir . $filename );
					} else {
						// it was just uploaded
						move_uploaded_file( $_FILES['users_csv']['tmp_name'], $import_dir . $filename );
					}

					// redurect to the page to run AJAX
					$url = admin_url( 'users.php?page=pmpro-import-users-from-csv&import=resume&filename=' . $filename );
					$url = add_query_arg(
						array(
							'new_user_notification' => $new_user_notification,
							'users_update'          => $users_update,
						),
						$url
					);

					wp_redirect( $url );
					exit;
				} else {
					$results = self::import_csv(
						$filename,
						array(
							'new_user_notification' => $new_user_notification,
							'users_update'          => $users_update,
						)
					);

					// No users imported?
					if ( ! $results['user_ids'] ) {
						wp_redirect( add_query_arg( 'import', 'fail', wp_get_referer() ) );
					}

					// Some users imported?
					elseif ( $results['errors'] ) {
						wp_redirect( add_query_arg( 'import', 'errors', wp_get_referer() ) );
					}

					// All users imported? :D
					else {
						wp_redirect( add_query_arg( 'import', 'success', wp_get_referer() ) );
					}

					exit;
				}
			}

			wp_redirect( add_query_arg( 'import', 'file', wp_get_referer() ) );
			exit;
		}
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
		?>
	<div class="wrap">
		<h2><?php _e( 'Import users from a CSV file', 'pmpro-import-users-from-csv' ); ?></h2>
		<?php
		$error_log_file = self::$log_dir_path . 'pmproiucsv_errors.log';
		$error_log_url  = self::$log_dir_url . 'pmproiucsv_errors.log';

		if ( ! file_exists( $error_log_file ) ) {
			if ( ! @fopen( $error_log_file, 'x' ) ) {
				echo '<div class="updated"><p><strong>' . sprintf( __( 'Notice: please make the directory %s writable so that you can see the error log.', 'pmpro-import-users-from-csv' ), self::$log_dir_path ) . '</strong></p></div>';
			}
		}

		// Adds a query arg in the
		if ( isset( $_GET['import'] ) ) {
			$error_log_msg = '';
			if ( file_exists( $error_log_file ) ) {
				$error_log_msg = sprintf( __( ', please <a href="%s">check the error log</a>', 'pmpro-import-users-from-csv' ), $error_log_url );
			}

			switch ( $_GET['import'] ) {
				case 'file':
					echo '<div class="error"><p><strong>' . __( 'Error during file upload.', 'pmpro-import-users-from-csv' ) . '</strong></p></div>';
					break;
				case 'data':
					echo '<div class="error"><p><strong>' . __( 'Cannot extract data from uploaded file or no file was uploaded.', 'pmpro-import-users-from-csv' ) . '</strong></p></div>';
					break;
				case 'fail':
					echo '<div class="error"><p><strong>' . sprintf( __( 'No user was successfully imported%s.', 'pmpro-import-users-from-csv' ), $error_log_msg ) . '</strong></p></div>';
					break;
				case 'errors':
					echo '<div class="error"><p><strong>' . sprintf( __( 'Some users were successfully imported but some were not%s.', 'pmpro-import-users-from-csv' ), $error_log_msg ) . '</strong></p></div>';
					break;
				case 'success':
					echo '<div class="updated"><p><strong>' . __( 'Users import was successful.', 'pmpro-import-users-from-csv' ) . '</strong></p></div>';
					break;
				default:
					break;
			}

			if ( $_GET['import'] == 'resume' && ! empty( $_GET['filename'] ) ) {
				$filename              = sanitize_file_name( $_GET['filename'] );
				$users_update          = isset( $_GET['users_update'] ) ? $_GET['users_update'] : false;
				$new_user_notification = isset( $_GET['new_user_notification'] ) ? $_GET['new_user_notification'] : false;

				// resetting position transients?
				if ( ! empty( $_GET['reset'] ) ) {
					delete_transient( 'pmproiucsv_' . $filename );
				}
				?>
			<h3>Importing file over AJAX</h3>
			<p><strong>IMPORTANT:</strong> Your import is not finished. Closing this page will stop it. If the import stops or you have to close your browser, you can navigate to <a href="<?php echo admin_url( $_SERVER['QUERY_STRING'] ); ?>">this URL</a> to resume the import later.</p>

			<p>
				<a id="pauseimport" href="#">Click here to pause.</a>
				<a id="resumeimport" href="#" style="display:none;">Paused. Click here to resume.</a>
			</p>

			<textarea id="importstatus" rows="10" cols="60">Loading...</textarea>
			<script>
				var ai_filename = <?php echo json_encode( $filename ); ?>;
				var ai_users_update = <?php echo json_encode( $users_update ); ?>;
				var ai_new_user_notification = <?php echo json_encode( $new_user_notification ); ?>;
			</script>
				<?php
			}
		}

		if ( empty( $_GET['filename'] ) ) {
			?>
		<form method="post" action="" enctype="multipart/form-data">
			<?php wp_nonce_field( 'pmproiucsv_page_import', '_wpnonce_pmproiucsv_process_csv' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="users_csv"><?php _e( 'CSV file', 'pmpro-import-users-from-csv' ); ?></label></th>
					<td>
						<input type="file" id="users_csv" name="users_csv" value="" class="all-options" /><br />
						<span class="description"><?php echo sprintf( __( 'You may want to see <a href="%s">the example of the CSV file</a>.', 'pmpro-import-users-from-csv' ), plugin_dir_url( __FILE__ ) . 'examples/import.csv' ); ?></span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e( 'Notification', 'pmpro-import-users-from-csv' ); ?></th>
					<td><fieldset>
						<legend class="screen-reader-text"><span><?php _e( 'Notification', 'pmpro-import-users-from-csv' ); ?></span></legend>
						<label for="new_user_notification">
							<input id="new_user_notification" name="new_user_notification" type="checkbox" value="1" />
							<?php _e( 'Send to new users', 'pmpro-import-users-from-csv' ); ?>
						</label>
					</fieldset></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e( 'Users update', 'pmpro-import-users-from-csv' ); ?></th>
					<td><fieldset>
						<legend class="screen-reader-text"><span><?php _e( 'Users update', 'pmpro-import-users-from-csv' ); ?></span></legend>
						<label for="users_update">
							<input id="users_update" name="users_update" type="checkbox" value="1" />
							<?php _e( 'Update user when a username or email exists', 'pmpro-import-users-from-csv' ); ?>
						</label>
					</fieldset></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e( 'AJAX', 'pmpro-import-users-from-csv' ); ?></th>
					<td><fieldset>
						<legend class="screen-reader-text"><span><?php _e( 'AJAX', 'pmpro-import-users-from-csv' ); ?></span></legend>
						<label for="ajaximport">
							<input id="ajaximport" name="ajaximport" type="checkbox" value="1" />
							<?php _e( 'Use AJAX to process the import gradually over time.', 'pmpro-import-users-from-csv' ); ?>
						</label>
					</fieldset></td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" class="button-primary" name="pmproiucsv_import" value="<?php esc_html_e( 'Import', 'pmpro-import-users-from-csv' ); ?>" />
			</p>
		</form>


			<?php
		}
	}

	/**
	 * AJAX service to import file
	 *
	 * @since ?
	 */
	public static function wp_ajax_pmpro_import_users_from_csv() {
		// check for filename
		if ( empty( $_GET['filename'] ) ) {
			die( 'No file name given.' );
		} else {
			$filename = sanitize_file_name( $_GET['filename'] );
		}

		// figure out upload dir
		$upload_dir = wp_upload_dir();
		$import_dir = $upload_dir['basedir'] . '/imports/';

		// make sure file exists
		if ( ! file_exists( $import_dir . $filename ) ) {
			die( 'nofile' );
		}

		// get settings
		$users_update          = isset( $_GET['users_update'] ) ? $_GET['users_update'] : false;
		$new_user_notification = isset( $_GET['new_user_notification'] ) ? $_GET['new_user_notification'] : false;

		// import next few lines of file
		$args = array(
			'partial'               => true,
			'users_update'          => $users_update,
			'new_user_notification' => $new_user_notification,
		);

		$results = self::import_csv( $import_dir . $filename, $args );

		// No users imported?
		if ( ! $results['user_ids'] ) {
			echo 'done';

			// delete file
			unlink( $import_dir . $filename );

			// delete position transient
			delete_transient( 'pmproiucsv_' . $filename );
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
		$errors = $user_ids = array();

		$defaults = array(
			'new_user_notification' => false,
			'users_update'          => false,
			'partial'               => false,
			'per_partial'           => apply_filters( 'pmprocsv_ajax_import_batch', 20 ),
		);
		extract( wp_parse_args( $args, $defaults ) );

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
				$headers = $line;
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

			// Separate user data from meta
			$userdata = $usermeta = array();
			foreach ( $line as $ckey => $column ) {
				$column_name = $headers[ $ckey ];
				$column      = trim( $column );

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

			// Something to be done before importing one user?
			do_action( 'pmproiucsv_pre_user_import', $userdata, $usermeta );

			$user = $user_id = false;

			if ( isset( $userdata['ID'] ) ) {
				$user = get_user_by( 'ID', $userdata['ID'] );
			}

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

			if ( $update ) {
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
						$metavalue = maybe_unserialize( $metavalue );
						update_user_meta( $user_id, $metakey, $metavalue );
					}
				}

				// If we created a new user, maybe set password nag and send new user notification?
				if ( ! $update ) {
					if ( $new_user_notification ) {
						wp_new_user_notification( $user_id, $userdata['user_pass'] );
					}
				}

				// Some plugins may need to do things after one user has been imported. Who know?
				do_action( 'pmproiucsv_post_user_import', $user_id );

				$user_ids[] = $user_id;
			}

			$rkey++;

			// if doing a partial import, save our spot and break
			if ( ! empty( $partial ) && $rkey ) {
				$position = $csv_reader->get_position();
				set_transient( 'pmproiucsv_' . basename( $filename ), $position, 60 * 60 * 24 * 2 );

				if ( $rkey > $per_partial - 1 ) {
					break;
				}
			}
		}
		fclose( $file_handle );

		// One more thing to do after all imports?
		do_action( 'pmproiucsv_post_users_import', $user_ids, $errors );

		// Let's log the errors
		self::log_errors( $errors );

		return array(
			'user_ids' => $user_ids,
			'errors'   => $errors,
		);
	}

	/**
	 * Check the first line of the CSV is valid and contains the required headers.
	 *
	 * @param file $file The CSV file to check for specific headers.
	 * @return boolean|array $is_csv_valid True if everything is okay, otherwise it will return an array of a list of headers that are invalid or missing?
	 */
	public static function is_csv_valid( $filename ) {
		$is_csv_valid = true;

		// Return an empty array if empty.
		if ( empty( $filename ) ) {
			return array();
		}
		// Let's get the first line of the CSV file and make sure the headers are listed here.
		$file_handle = fopen( $filename, 'r' );
		$csv_reader  = new ReadCSV( $file_handle, PMPROIUCSV_CSV_DELIMITER, "\xEF\xBB\xBF" ); // Skip any UTF-8 byte order mark.
		$headers = $csv_reader->get_row(); // Gets the first row.

		// Valid headers we need for import.
		$valid_headers = apply_filters( 'pmproiucsv_required_csv_headers_array', array( 'user_login', 'user_email' ) );
		$missing_required_headers = array_diff( $valid_headers, $headers );
		
		// If there are missing required headers, lets show a warning.
		if ( ! empty( $missing_required_headers ) ) {
			$is_csv_valid = $missing_required_headers;
		}

		// Close the file now that we're done with it.
		fclose($file_handle);
		return $is_csv_valid;
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

		$log = @fopen( self::$log_dir_path . 'pmproiucsv_error.log', 'a' );
		@fwrite( $log, sprintf( __( 'BEGIN %s', 'pmpro-import-users-from-csv' ), date_i18n( 'Y-m-d H:i:s', time() ) ) . "\n" );

		foreach ( $errors as $key => $error ) {
			$line    = $key + 1;
			$message = $error->get_error_message();
			@fwrite( $log, sprintf( __( '[Line %1$s] %2$s', 'pmpro-import-users-from-csv' ), $line, $message ) . "\n" );
		}

		@fclose( $log );
	}
}

PMPro_Import_Users_From_CSV::init();
