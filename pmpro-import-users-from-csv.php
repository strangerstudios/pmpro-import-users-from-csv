<?php
/*
Plugin Name: Paid Memberships Pro - Import Users from CSV Add On
Plugin URI: http://www.paidmembershipspro.com/pmpro-import-users-from-csv/
Description: Add-on for the Import Users From CSV plugin to import PMPro and membership-related fields.
Version: .3.4
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
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
	
	?>
    <div class="notice notice-warning">
        <p><?php printf( __( 'In order for <strong>Paid Memberships Pro - Import Users from CSV</strong> to function correctly, you must also install the <a href="%s">Import Users from CSV</a> plugin.', 'pmproiufcsv' ), esc_url( admin_url( 'tools.php?page=pmproiufcsv-install-plugin' ) ) ); ?></p>
    </div>
    <?php
}

function pmproiufcsv_installation_page(){

	add_submenu_page( '', __( 'Install Import Users from CSV Plugin', 'pmpro-import-users-from-csv' ), 'Install Import Users from CSV Plugin', 'manage_options', 'pmproiufcsv-install-plugin', 'pmproiufcsv_auto_activate_importer', null );

}
add_action( 'admin_menu', 'pmproiufcsv_installation_page' );

function pmproiufcsv_auto_activate_importer() {

	echo "<h3>".__( 'Paid Memberships Pro - Import Users from CSV Auto Installer', 'pmpro-import-users-from-csv' )."</h3>";

	$plugin_slug = 'import-users-from-csv/import-users-from-csv.php';

	$plugin_zip = 'https://downloads.wordpress.org/plugin/import-users-from-csv.zip';

	if ( pmproiufcsv_is_plugin_installed( $plugin_slug ) ) {

		pmproiufcsv_upgrade_plugin( $plugin_slug );

		$installed = true;
	
	} else {

		$installed = pmproiufcsv_install_plugin( $plugin_zip );

	}

	if ( !is_wp_error( $installed ) && $installed ) {

		$activate = activate_plugin( $plugin_slug );
	 
	} else {

		//Failure to launch

	}


}

function pmproiufcsv_is_plugin_installed( $slug ) {
	
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	
	$all_plugins = get_plugins();

	if ( !empty( $all_plugins[$slug] ) ) {
		return true;
	} else {
		return false;
	}
}
 
function pmproiufcsv_install_plugin( $plugin_zip ) {
	
	include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	
	wp_cache_flush();

	$upgrader = new Plugin_Upgrader();
	$installed = $upgrader->install( $plugin_zip );

	return $installed;
}
 
function pmproiufcsv_upgrade_plugin( $plugin_slug ) {
	
	include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	wp_cache_flush();

	$upgrader = new Plugin_Upgrader();
	$upgraded = $upgrader->upgrade( $plugin_slug );

	return $upgraded;
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
	if(!empty($membership_startdate))
		$membership_startdate = date("Y-m-d", strtotime($membership_startdate, current_time('timestamp')));
	if(!empty($membership_enddate))
		$membership_enddate = date("Y-m-d", strtotime($membership_enddate, current_time('timestamp')));
	else
		$membership_enddate = "NULL";
	if(!empty($membership_timestamp))	
		$membership_timestamp = date("Y-m-d", strtotime($membership_timestamp, current_time('timestamp')));
	
	//look up discount code
	if(!empty($membership_discount_code) && empty($membership_code_id))
		$membership_code_id = $wpdb->get_var("SELECT id FROM $wpdb->pmpro_discount_codes WHERE `code` = '" . esc_sql($membership_discount_code) . "' LIMIT 1");		
		
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
	
	//look for a subscription transaction id and gateway
	$membership_subscription_transaction_id = $user->import_membership_subscription_transaction_id;
	$membership_payment_transaction_id = $user->import_membership_payment_transaction_id;
	$membership_order_status = $user->import_membership_order_status;
	$membership_affiliate_id = $user->import_membership_affiliate_id;
	$membership_gateway = $user->import_membership_gateway;
		
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

/*
Function to add links to the plugin row meta
*/
function pmproiufcsv_plugin_row_meta($links, $file) {
	if(strpos($file, 'pmpro-import-users-from-csv.php') !== false)
	{
		$new_links = array(
			'<a href="' . esc_url('http://www.paidmembershipspro.com/add-ons/third-party-integration/pmpro-import-users-csv/')  . '" title="' . esc_attr( __( 'View Documentation', 'pmpro' ) ) . '">' . __( 'Docs', 'pmpro' ) . '</a>',
			'<a href="' . esc_url('http://paidmembershipspro.com/support/') . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro' ) ) . '">' . __( 'Support', 'pmpro' ) . '</a>',
		);
		$links = array_merge($links, $new_links);
	}
	return $links;
}
add_filter('plugin_row_meta', 'pmproiufcsv_plugin_row_meta', 10, 2);
