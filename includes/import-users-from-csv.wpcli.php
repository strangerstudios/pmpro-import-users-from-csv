<?php

WP_CLI::add_command( 'iucsv', function ( $args, $params ) {
	$subcommand = $args[0] ?? '';

	switch ( $subcommand ) {
		case 'import':
			$filepath = $args[1] ?? false;
			if ( $filepath === false || ! file_exists( $filepath ) ) {
				echo esc_html( 'provided filename does not exists', 'pmpro-import-users-from-csv' ) . PHP_EOL;
				exit;
			}

			$args = array(
				'users_update'               => rest_sanitize_boolean( $params['users_update'] ?? false ),
				'new_user_notificationd_nag' => rest_sanitize_boolean( $params['new_user_notificationd_nag'] ?? false ),
			);

			$result = PMPro_Import_Users_From_CSV::import_csv( $filepath, [ 'users_update' => true ] );

			echo sprintf( esc_html( 'Updated %d users', 'pmpro-import-users-from-csv' ), count( $result['user_ids'] ) );

			if ( ! empty( $result['errors'] ) ) {
				echo ' '. esc_html( 'with errors:', 'pmpro-import-users-from-csv' );
				echo implode( PHP_EOL, $result['errors'] );
			}

			echo PHP_EOL;
			break;

		default:
			echo esc_html( 'usage: wp iucsv import /path/to/file.csv', 'pmpro-import-users-from-csv' ) . PHP_EOL;
			exit;
	}
} );
