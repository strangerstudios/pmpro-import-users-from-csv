<?php
/*
Plugin Name: Paid Memberships Pro - Import Users from CSV Add On
Plugin URI: http://www.paidmembershipspro.com/pmpro-import-users-from-csv/
Description: Add-on for the Import Users From CSV plugin to import PMPro and membership-related fields.
Version: 0.4
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
Text Domain: pmpro-import-users-from-csv
Domain Path: /languages
*/
/*
	Copyright 2011	Stranger Studios	(email : jason@strangerstudios.com)
	GPLv2 Full license details in license.txt

	You should have both the Import Users From CSV and Paid Memberships Pro plugins installed and activated before using this plugin.

	1. Activate Plugin
	2. Add the usual columns to your import CSV: user_login, user_email, first_name, last_name, etc.
	3. Add the following columns to your import CSV. * = required for membership level, ** = required for gateway subscription
		- membership_id * (id of the membership level)
		- membership_code_id (or use membership_discount_code if you know the code string, but not the code id #)
		- membership_initial_payment
		- membership_billing_amount
		- membership_cycle_number
		- membership_cycle_period
		- membership_billing_limit
		- membership_trial_amount
		- membership_trial_limit
		- membership_status
		- membership_startdate
		- membership_enddate
		- membership_subscription_transaction_id ** (subscription transaction id or customer id from the gateway)
		- membership_gateway ** (gateway = check, stripe, paypalstandard, paypalexpress, paypal (for website payments pro), payflowpro, authorizenet, braintree)
		- membership_payment_transaction_id
		- membership_affiliate_id
		- membership_order_status (PayPal order status)
		- membership_timestamp
	4. Go to Users --> Import From CSV. Browse to CSV file and import.
		- pmpro_stripe_customerid (for Stripe users, will be same as membership_subscription_transaction_id above)
    5. (Optional) Send a welcome email by setting the global $pmproiufcsv_email. See example below.
	6. Go to Users --> Import From CSV. Browse to CSV file and import.

    Copy these lines to your active theme's functions.php or custom plugin and modify as desired to send a welcome email to members after import:

    global $pmproiufcsv_email;
    $pmproiufcsv_email = array(
        'subject'   => sprintf('Welcome to %s', get_bloginfo('sitename')), //email subject, "Welcome to Sitename"
        'body'      => 'Your welcome email body text will go here.'        //email body
    );
*/

/*
	Load plugin textdomain.
*/
function pmproiufcsv_load_textdomain() {
	load_plugin_textdomain( 'pmpro-import-users-from-csv', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'pmproiufcsv_load_textdomain' );

/*
* Check if import users from CSV exists
*/
 function pmproiufcsv_check(){
 	if( !defined( 'IS_IU_CSV_DELIMITER' ) ){
 	add_action( 'admin_notices', 'pmproiufcsv_admin_notice' );
 	}
 }

add_action( 'admin_init', 'pmproiufcsv_check' );

function pmproiufcsv_admin_notice(){
	// Don't want to show this on the plugin install page.
	$screen = get_current_screen();
	if ( ! empty( $screen ) && $screen->base == 'plugin-install' ) {
		return;
	}

	$action_url = wp_nonce_url(
		add_query_arg(
			[
				'action' => 'install-plugin',
				'plugin' => 'import-users-from-csv',
			],
			admin_url( 'update.php' )
		),
		'install-plugin_import-users-from-csv'
	);

	// Maybe use the activate link if the plugin is installed but not activated.
	if ( pmproiufcsv_is_plugin_installed( 'import-users-from-csv/import-users-from-csv.php' ) ) {
		$action_url = wp_nonce_url(
			add_query_arg(
				[
					'action' => 'activate',
					'plugin' => 'import-users-from-csv/import-users-from-csv.php',
				],
				admin_url( 'plugins.php' )
			),
			'activate-plugin_import-users-from-csv/import-users-from-csv.php'
		);
	}

	?>
    <div class="notice notice-warning">
        <p>
			<?php
			printf(
				__( 'In order for <strong>Paid Memberships Pro - Import Users from CSV</strong> to function correctly, you must also install the <a href="%s">Import Users from CSV</a> plugin.', 'pmpro-import-users-from-csv' ),
				esc_url( $action_url )
			);
			?>
		</p>
    </div>
    <?php
}

/**
 * Determine whether a plugin is installed.
 *
 * @since TBA
 *
 * @param string $plugin The plugin to check if installed.
 *
 * @return bool Whether a plugin is installed.
 */
function pmproiufcsv_is_plugin_installed( $plugin ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$all_plugins = get_plugins();

	return ! empty( $all_plugins[ $plugin ] );
}

/*
	Get list of PMPro-related fields
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

/*
	Delete all import_ meta fields before an import in case the user has been imported in the past.
*/
function pmproiufcsv_is_iu_pre_user_import($userdata, $usermeta) {
	//try to get user by ID
	$user = $user_id = false;
	if ( isset( $userdata['ID'] ) )
		$user = get_user_by( 'ID', $userdata['ID'] );

	//try to find user by login or email
	if ( ! $user ) {
		if ( isset( $userdata['user_login'] ) )
			$user = get_user_by( 'login', $userdata['user_login'] );

		if ( ! $user && isset( $userdata['user_email'] ) )
			$user = get_user_by( 'email', $userdata['user_email'] );
	}

	//if we found someone delete the import_ user meta
	if(!empty($user)) {
		$pmpro_fields = pmproiufcsv_getFields();

		foreach($pmpro_fields as $field) {
			delete_user_meta($user->ID, "import_" . $field);
		}
	}

	/**
	 * Filter to allow cancellation of subscriptions during import.
	 * 
	 * @since TBD
	 * @param boolean $allow_sub_cancellations Set this option to true if you want to let subscriptions cancel on the gateway level during import.
	 */
	if ( ! apply_filters( 'pmproiufcsv_cancel_prev_sub_on_import', false ) ) {
		add_filter( 'pmpro_cancel_previous_subscriptions', '__return_false' );
	}
	
}
add_action('is_iu_pre_user_import', 'pmproiufcsv_is_iu_pre_user_import', 10, 2);

/*
	Change some of the imported columns to add "imported_" to the front so we don't confuse the data later.
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
add_filter("is_iu_import_usermeta", "pmproiufcsv_is_iu_import_usermeta", 10, 2);

//after users are added, let's use the meta data imported to update the user
function pmproiufcsv_is_iu_post_user_import($user_id)
{
    global $pmproiufcsv_email, $wpdb;

	wp_cache_delete($user_id, 'users');
	$user = get_userdata($user_id);

	//look for a membership level and other information
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

	//fix date formats
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

	//look up discount code
	if ( ! empty( $membership_discount_code ) && empty( $membership_code_id ) ) {
		$membership_code_id = $wpdb->get_var( "SELECT id FROM $wpdb->pmpro_discount_codes WHERE `code` = '" . esc_sql( $membership_discount_code ) . "' LIMIT 1" );
	}

	//look for a subscription transaction id and gateway
	$membership_subscription_transaction_id = $user->import_membership_subscription_transaction_id;
	$membership_payment_transaction_id = $user->import_membership_payment_transaction_id;
	$membership_order_status = $user->import_membership_order_status;
	$membership_affiliate_id = $user->import_membership_affiliate_id;
	$membership_gateway = $user->import_membership_gateway;

	if( !empty( $membership_subscription_transaction_id ) && ( $membership_status == 'active' || empty( $membership_status ) ) && !empty( $membership_enddate ) ){
		/**
		 * If there is a membership_subscription_transaction_id column with a value AND membership_status column with value active (or assume active if missing) AND the membership_enddate column is not empty, then (1) throw an warning but continue to import
		 */

		add_filter( 'is_iu_errors_filter', 'pmproiufcsv_report_sub_error', 10, 2 );
	}


	//change membership level
	if(!empty($membership_id))
	{
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

		//if membership was in the past make it inactive
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

	//add order so integration with gateway works
	if(
// 		!empty($membership_subscription_transaction_id) &&
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

		//update timestamp of order?
		if(!empty($membership_timestamp))
		{
			$timestamp = strtotime($membership_timestamp, current_time('timestamp'));
			$order->updateTimeStamp(date("Y", $timestamp), date("m", $timestamp), date("d", $timestamp), date("H:i:s", $timestamp));
		}
	}

	//add code use
	if(!empty($membership_code_id) && !empty($order) && !empty($order->id))
		$wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . esc_sql($membership_code_id) . "', '" . esc_sql($user_id) . "', '" . intval($order->id) . "', now())");

	//email user if global is set
    if(!empty($pmproiufcsv_email))
    {
        $email = new PMProEmail();
        $email->email = $user->user_email;
        $email->subject = $pmproiufcsv_email['subject'];
        $email->body = $pmproiufcsv_email['body'];
        $email->template = 'pmproiufcsv';
        $email->sendEmail();
    }
}
add_action("is_iu_post_user_import", "pmproiufcsv_is_iu_post_user_import");

/**
 * Add error/warning message if user's were imported with both a subscription and expiration date.
 *
 * @since TBD
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

/*
Function to add links to the plugin row meta
*/
function pmproiufcsv_plugin_row_meta($links, $file) {
	if(strpos($file, 'pmpro-import-users-from-csv.php') !== false)
	{
		$new_links = array(
			'<a href="' . esc_url('http://www.paidmembershipspro.com/add-ons/third-party-integration/pmpro-import-users-csv/')  . '" title="' . esc_attr( __( 'View Documentation', 'pmpro-import-users-from-csv' ) ) . '">' . __( 'Docs', 'pmpro-import-users-from-csv' ) . '</a>',
			'<a href="' . esc_url('http://paidmembershipspro.com/support/') . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro-import-users-from-csv' ) ) . '">' . __( 'Support', 'pmpro-import-users-from-csv' ) . '</a>',
		);
		$links = array_merge($links, $new_links);
	}
	return $links;
}
add_filter('plugin_row_meta', 'pmproiufcsv_plugin_row_meta', 10, 2);

/**
 * Render the additional options for the Import Users from CSV plugin settings page.
 *
 * @since 0.4
 */
function pmproiufcsv_add_import_options() {

	?>

	<tr valign="top">
		<td scope="row"><strong><?php esc_html_e( 'Skip existing members' , 'pmpro-import-users-from-csv'); ?></strong></td>

		<td>
			<fieldset>
				<legend class="screen-reader-text"><span><?php esc_html_e( 'Skip existing members' , 'pmpro-import-users-from-csv' ); ?></span></legend>


				<label for="skip_existing_members_same_level">
					<input id="skip_existing_members_same_level" name="skip_existing_members_same_level" type="checkbox" value="1" />
					<?php esc_html_e( 'Do not change membership level of user if they already have the same membership_id level during import', 'pmpro-import-users-from-csv' ) ;?>

				</label>
			</fieldset>
		</td>
	</tr>

	<?php

}
add_action( 'is_iu_import_page_inside_table_bottom', 'pmproiufcsv_add_import_options' );
