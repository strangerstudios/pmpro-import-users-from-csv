<?php
/*
Plugin Name: PMPro Import Users from CSV
Plugin URI: http://www.paidmembershipspro.com/pmpro-import-users-from-csv/
Description: Add-on for the Import Users From CSV plugin to import PMPro and membership-related fields.
Version: .2
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
		- membership_code_id
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
		- membership_timestamp
	4. Go to Users --> Import From CSV. Browse to CSV file and import.
*/

/*
	Change some of the imported columns to add "imported_" to the front so we don't confuse the data later.
*/
function pmproiufcsv_is_iu_import_usermeta($usermeta, $userdata)
{
	$pmpro_fields = array(
		"membership_id",
		"membership_code_id",
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
		"membership_gateway",
		"membership_affiliate_id",
		"membership_timestamp"
	);
		
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
	wp_cache_delete($user_id, 'users');
	$user = get_userdata($user_id);
	
	//look for a membership level and other information
	$membership_id = $user->import_membership_id;
	$membership_code_id = $user->import_membership_code_id;
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
	}
	
	//look for a subscription transaction id and gateway
	$membership_subscription_transaction_id = $user->import_membership_subscription_transaction_id;
	$membership_payment_transaction_id = $user->import_membership_payment_transaction_id;
	$membership_affiliate_id = $user->import_membership_affiliate_id;
	$membership_gateway = $user->import_membership_gateway;
		
	//add order so integration with gateway works
	if(
		!empty($membership_subscription_transaction_id) && !empty($membership_gateway) ||
		!empty($membership_timestamp)
	)
	{
		$order = new MemberOrder();
		$order->user_id = $user_id;
		$order->InitialPayment = $membership_initial_payment;		
		$order->payment_transaction_id = $membership_payment_transaction_id;
		$order->subscription_transaction_id = $membership_subscription_transaction_id;
		$order->affiliate_id = $membership_affiliate_id;
		$order->gateway = $membership_gateway;
		$order->saveOrder();

		//update timestamp of order?
		if(!empty($membership_timestamp))
		{
			$timestamp = strtotime($membership_timestamp);
			$order->updateTimeStamp(date("Y", $timestamp), date("m", $timestamp), date("d", $timestamp), date("H:i:s", $timestamp));
		}
	}
}
add_action("is_iu_post_user_import", "pmproiufcsv_is_iu_post_user_import");