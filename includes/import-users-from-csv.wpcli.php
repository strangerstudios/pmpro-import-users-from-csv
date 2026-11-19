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
				echo esc_html( 'provided filename does not exists', 'pmpro-import-users-from-csv' ) . PHP_EOL;
				exit;
			}

			// users_update defaults to true to match how this command has always behaved.
			$args = array(
				'users_update'          => rest_sanitize_boolean( $params['users_update'] ?? true ),
				'new_user_notification' => rest_sanitize_boolean( $params['new_user_notification'] ?? false ),
			);

			$result = PMPro_Import_Users_From_CSV::import_csv( $filepath, $args );

			echo sprintf( esc_html( 'Imported %d users', 'pmpro-import-users-from-csv' ), count( $result['user_ids'] ) );

			if ( ! empty( $result['errors'] ) ) {
				echo ' '. esc_html( 'with errors:', 'pmpro-import-users-from-csv' );
				// Errors are WP_Error objects, which can't be imploded directly.
				$error_messages = array_map( function ( $error ) {
					return is_wp_error( $error ) ? $error->get_error_message() : $error;
				}, $result['errors'] );
				echo PHP_EOL . implode( PHP_EOL, $error_messages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP-CLI terminal output, not HTML.
			}

			echo PHP_EOL;
			break;

		default:
			echo esc_html( 'usage: wp iucsv import /path/to/file.csv', 'pmpro-import-users-from-csv' ) . PHP_EOL;
			exit;
	}
} );
