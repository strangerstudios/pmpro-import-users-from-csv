<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

WP_CLI::add_command( 'iucsv', function ( $args, $params ) {
	$subcommand = $args[0] ?? '';

	switch ( $subcommand ) {
		case 'import':
			$filepath = $args[1] ?? false;
			if ( $filepath === false || ! file_exists( $filepath ) ) {
				echo esc_html__( 'provided filename does not exists', 'pmpro-import-users-from-csv' ) . PHP_EOL;
				exit;
			}

			// users_update defaults to true to match how this command has always behaved.
			$args = array(
				'users_update'          => rest_sanitize_boolean( $params['users_update'] ?? true ),
				'new_user_notification' => rest_sanitize_boolean( $params['new_user_notification'] ?? false ),
			);

			$result = PMPro_Import_Users_From_CSV::import_csv( $filepath, $args );

			/* translators: %d: number of users imported or updated */
			echo sprintf( esc_html__( 'Imported %d users', 'pmpro-import-users-from-csv' ), count( $result['user_ids'] ) );

			if ( ! empty( $result['errors'] ) ) {
				echo ' '. esc_html__( 'with errors:', 'pmpro-import-users-from-csv' );
				// Errors are WP_Error objects keyed by row, which can't be imploded directly. Match the line numbers in the error log.
				$error_messages = array();
				foreach ( $result['errors'] as $key => $error ) {
					/* translators: 1: line number, 2: error message */
					$error_messages[] = sprintf( __( '[Line %1$s] %2$s', 'pmpro-import-users-from-csv' ), $key + 1, is_wp_error( $error ) ? $error->get_error_message() : $error );
				}
				echo PHP_EOL . implode( PHP_EOL, $error_messages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP-CLI terminal output, not HTML.
			}

			echo PHP_EOL;
			break;

		default:
			echo esc_html__( 'usage: wp iucsv import /path/to/file.csv [--users_update=false] [--new_user_notification]', 'pmpro-import-users-from-csv' ) . PHP_EOL;
			exit;
	}
} );
