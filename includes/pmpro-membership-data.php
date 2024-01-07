<?php
/**
 * Get list of PMPro-related fields.
 */
function pmproiufcsv_getFields() {
	$pmpro_fields = array(
		"membership_id",
		"membership_code_id",
		"membership_discount_code",
		"membership_initial_payment",
		"membership_billing_amount",
		"membership_cycle_number",
		"membership_cycle_period",
		"membership_billing_limit",
		"membership_trial_amount",
		"membership_trial_limit",
		"membership_status",
		"membership_startdate",
		"membership_enddate",
		"membership_subscription_transaction_id",
		"membership_payment_transaction_id",
		"membership_order_status",
		"membership_gateway",
		"membership_affiliate_id",
		"membership_timestamp"
	);

	return $pmpro_fields;
}

/**
 * Delete all import_ meta fields before an import in case the user has been imported in the past.
 */
function pmproiufcsv_is_iu_pre_user_import( $userdata, $usermeta, $user ) {
	
	if(!empty($user)) {
		$pmpro_fields = pmproiufcsv_getFields();

		foreach($pmpro_fields as $field) {
			delete_user_meta($user->ID, "import_" . $field);
		}
	}

	/**
	 * Filter to allow cancellation of subscriptions during import.
	 * 
	 * @since 0.4
	 * @param boolean $allow_sub_cancellations Set this option to true if you want to let subscriptions cancel on the gateway level during import.
	 */
	if ( ! apply_filters( 'pmproiufcsv_cancel_prev_sub_on_import', false ) ) {
		add_filter( 'pmpro_cancel_previous_subscriptions', '__return_false' );
	}
	
}
add_action( 'pmproiucsv_pre_user_import', 'pmproiufcsv_is_iu_pre_user_import', 10, 3 );

/**
 * Change some of the imported columns to add "imported_" to the front so we don't confuse the data later.
 */
function pmproiufcsv_is_iu_import_usermeta($usermeta, $userdata)
{
	$pmpro_fields = pmproiufcsv_getFields();

	$newusermeta = array();
	foreach($usermeta as $key => $value)
	{
		if(in_array($key, $pmpro_fields))
			$key = "import_" . $key;

		$newusermeta[$key] = $value;
	}

	return $newusermeta;
}
add_filter("pmproiucsv_import_usermeta", "pmproiufcsv_is_iu_import_usermeta", 10, 2);

/**
 * After users are added, let's use the meta data imported to update the user.
 */
function pmproiufcsv_is_iu_post_user_import($user_id)
{
    global $wpdb;

	wp_cache_delete($user_id, 'users');
	$user = get_userdata($user_id);

	// Look for a membership level and other information.
	$membership_id = $user->import_membership_id;
	$membership_code_id = $user->import_membership_code_id;
	$membership_discount_code = $user->import_membership_discount_code;
	$membership_initial_payment = $user->import_membership_initial_payment;
	$membership_billing_amount = $user->import_membership_billing_amount;
	$membership_cycle_number = $user->import_membership_cycle_number;
	$membership_cycle_period = $user->import_membership_cycle_period;
	$membership_billing_limit = $user->import_membership_billing_limit;
	$membership_trial_amount = $user->import_membership_trial_amount;
	$membership_trial_limit = $user->import_membership_trial_limit;
	$membership_status = $user->import_membership_status;
	$membership_startdate = $user->import_membership_startdate;
	$membership_enddate = $user->import_membership_enddate;
	$membership_timestamp = $user->import_membership_timestamp;

	// Fix date formats.
	if ( ! empty( $membership_startdate ) ) {
		$membership_startdate = date( 'Y-m-d', strtotime( $membership_startdate, current_time( 'timestamp' ) ) );
	} else {
		$membership_startdate = current_time( 'mysql' );
	}

	if ( ! empty( $membership_enddate ) ) {
		$membership_enddate = date( 'Y-m-d', strtotime( $membership_enddate, current_time( 'timestamp' ) ) );
	} else {
		$membership_enddate = 'NULL';
	}

	if ( ! empty( $membership_timestamp ) ) {
		$membership_timestamp = date( 'Y-m-d', strtotime($membership_timestamp, current_time( 'timestamp' ) ) );
	}

	if ( ! empty( $membership_discount_code ) && empty( $membership_code_id ) ) {
		$membership_code_id = $wpdb->get_var(
			$wpdb->prepare( "
				SELECT id
				FROM $wpdb->pmpro_discount_codes
				WHERE `code` = %s
				LIMIT 1
			", $membership_discount_code )
		);
	}

	// Check whether the member may already have been imported.
	if( pmpro_hasMembershipLevel( $membership_id, $user_id ) && ! empty( $_REQUEST['skip_existing_members_same_level'] ) ){
		return;
  }

	// Look up discount code.
	if ( ! empty( $membership_discount_code ) && empty( $membership_code_id ) ) {
		$membership_code_id = $wpdb->get_var( "SELECT id FROM $wpdb->pmpro_discount_codes WHERE `code` = '" . esc_sql( $membership_discount_code ) . "' LIMIT 1" );
	}

	// Look for a subscription transaction id and gateway.
	$membership_subscription_transaction_id = $user->import_membership_subscription_transaction_id;
	$membership_payment_transaction_id = $user->import_membership_payment_transaction_id;
	$membership_order_status = $user->import_membership_order_status;
	$membership_affiliate_id = $user->import_membership_affiliate_id;
	$membership_gateway = $user->import_membership_gateway;

	if( !empty( $membership_subscription_transaction_id ) && ( $membership_status == 'active' || empty( $membership_status ) ) && !empty( $membership_enddate ) ){
		/**
		 * If there is a membership_subscription_transaction_id column with a value AND membership_status column with value active (or assume active if missing) AND the membership_enddate column is not empty, then (1) throw an warning but continue to import
		 */

		add_filter( 'pmproiucsv_errors_filter', 'pmproiufcsv_report_sub_error', 10, 2 );
	}

	// Process level changes if membership_id is set.
	if ( isset( $membership_id ) && ( $membership_id === '0' || ! empty( $membership_id ) ) ) {

		// Cancel all memberships if membership_id is set to 0.
		if ( $membership_id === '0' ) {
			pmpro_changeMembershipLevel( 0, $user_id );
		} else {
			// Give the user the membership level.
			$custom_level = array(
				'user_id' => $user_id,
				'membership_id' => $membership_id,
				'code_id' => $membership_code_id,
				'initial_payment' => $membership_initial_payment,
				'billing_amount' => $membership_billing_amount,
				'cycle_number' => $membership_cycle_number,
				'cycle_period' => $membership_cycle_period,
				'billing_limit' => $membership_billing_limit,
				'trial_amount' => $membership_trial_amount,
				'trial_limit' => $membership_trial_limit,
				'status' => $membership_status,
				'startdate' => $membership_startdate,
				'enddate' => $membership_enddate
			);

			pmpro_changeMembershipLevel($custom_level, $user_id);

			// If membership was in the past make it inactive.
			if($membership_status === "inactive" || (!empty($membership_enddate) && $membership_enddate !== "NULL" && strtotime($membership_enddate, current_time('timestamp')) < current_time('timestamp')))
			{
				$sqlQuery = "UPDATE $wpdb->pmpro_memberships_users SET status = 'inactive' WHERE user_id = '" . $user_id . "' AND membership_id = '" . $membership_id . "'";
				$wpdb->query($sqlQuery);
				$membership_in_the_past = true;
			}

			if($membership_status === "active" && (empty($membership_enddate) || $membership_enddate === "NULL" || strtotime($membership_enddate, current_time('timestamp')) >= current_time('timestamp')))
			{
				$sqlQuery = $wpdb->prepare("UPDATE {$wpdb->pmpro_memberships_users} SET status = 'active' WHERE user_id = %d AND membership_id = %d ORDER BY id DESC LIMIT 1", $user_id, $membership_id);
				$wpdb->query($sqlQuery);
			}
		}
	}

	// Add order so integration with gateway works.
	if(
		!empty($membership_gateway) ||
		!empty($membership_timestamp) || !empty($membership_code_id)
	)
	{
		$order = new MemberOrder();
		$order->user_id = $user_id;
		$order->membership_id = $membership_id;
		$order->InitialPayment = $membership_initial_payment;
		$order->payment_transaction_id = $membership_payment_transaction_id;
		$order->subscription_transaction_id = $membership_subscription_transaction_id;
		$order->affiliate_id = $membership_affiliate_id;
		$order->gateway = $membership_gateway;

		if ( ! empty( $membership_order_status ) ) {
			$order->status = $membership_order_status;
		} elseif ( ! empty( $membership_in_the_past ) ) {
			$order->status = 'cancelled';
		} else {
			$order->status = 'success';
		}

		$order->saveOrder();

		// Maybe update timestamp of order.
		if(!empty($membership_timestamp))
		{
			$timestamp = strtotime($membership_timestamp, current_time('timestamp'));
			$order->updateTimeStamp(date("Y", $timestamp), date("m", $timestamp), date("d", $timestamp), date("H:i:s", $timestamp));
		}
	}

	// Add code use.
	if(!empty($membership_code_id) && !empty($order) && !empty($order->id))
		$wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . esc_sql($membership_code_id) . "', '" . esc_sql($user_id) . "', '" . intval($order->id) . "', now())");

}
add_action("pmproiucsv_post_user_import", "pmproiufcsv_is_iu_post_user_import");

/**
 * Add error/warning message if user's were imported with both a subscription and expiration date.
 *
 * @since 0.4
 *
 * @param array $errors An array of various error messages.
 * @param [type] $user_ids
 * @return array $error The error message that is set when an import fails.
 */
function pmproiufcsv_report_sub_error ( $errors, $user_ids ){

	$error_message = sprintf( __( 'User imported with both an active subscription and a membership enddate. This configuration is not recommended with PMPro ($1$s). This user has been imported with no enddate.', 'pmpro-import-users-from-csv' ), 'https://www.paidmembershipspro.com/important-notes-on-recurring-billing-and-expiration-dates-for-membership-levels/' );

	$errors[] = new WP_Error( 'subscriptions_expiration', $error_message );

	return $errors;

}

/**
 * Render the additional options for the Import Users from CSV plugin settings page.
 *
 * @since 0.4
 */
function pmproiufcsv_add_import_options() { ?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Skip Existing Members' , 'pmpro-import-users-from-csv'); ?></th>
		<td>
			<fieldset>
				<legend class="screen-reader-text"><span><?php esc_html_e( 'Skip Existing Members' , 'pmpro-import-users-from-csv' ); ?></span></legend>
				<label for="skip_existing_members_same_level">
					<input id="skip_existing_members_same_level" name="skip_existing_members_same_level" type="checkbox" value="1" />
					<?php esc_html_e( 'Do not change the membership level of users with the same active membership level during import.', 'pmpro-import-users-from-csv' ) ;?>
				</label>
			</fieldset>
		</td>
	</tr>
	<?php
}
add_action( 'pmproiucsv_import_page_inside_table_bottom', 'pmproiufcsv_add_import_options' );

/**
 * Required headers when importing members that shows a notice if it's missing.
 *
 * @since 1.0
 */
function pmproiucsv_required_pmpro_import_headers( $required_headers ) {
	$required_headers[] = 'membership_id';
	return $required_headers;
}
add_filter( 'pmproiucsv_required_import_headers', 'pmproiucsv_required_pmpro_import_headers', 10, 1 );
