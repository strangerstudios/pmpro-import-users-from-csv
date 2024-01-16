<?php
// Clean up the folder and all of it's items when deleting this plugin.

// if uninstall.php is not called by WordPress, die
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// Delete the folder and all it's files.
$upload_dir = wp_upload_dir();
$import_dir = $upload_dir['basedir'] . '/pmpro-imports/';

//Delete all the files inside the upload folder with any extension.
if ( is_dir( $import_dir ) ) {
    $files = glob( $import_dir . '*', GLOB_MARK ); //GLOB_MARK adds a slash to directories returned

    foreach ( $files as $file ) {
        if ( ! is_dir( $file ) ) {
            unlink( $file );
        }
    }

    rmdir( $import_dir );
}

// Delete the log file too pmproiucsv_error.log
$log_file = $upload_dir['basedir'] . '/pmproiucsv_error.log';
if ( file_exists( $log_file ) ) {
    unlink( $log_file );
}