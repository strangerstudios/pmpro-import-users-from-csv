=== Paid Memberships Pro - Import Users from CSV Add On ===
Contributors: strangerstudios
Tags: paid memberships pro, import users from csv, import, csv, members
Requires at least: 3.0
Tested up to: 5.1
Stable tag: .3.4

Add-on for the Import Users From CSV plugin to import PMPro and membership-related fields.
 
== Description ==

If you add specific fields (see installation section) to your CSV when importing with the Import Users from CSV, membership levels and dummy orders (for gateway syncing) will be created.

Requires both the Import Users From CSV and Paid Memberships Pro Plugins
* http://wordpress.org/plugins/import-users-from-csv/
* http://wordpress.org/extend/plugins/paid-memberships-pro

== Installation ==

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
	- pmpro_stripe_customerid (for Stripe users, will be same as membership_subscription_transaction_id above)
5. (Optional) Send a welcome email by setting the global $pmproiufcsv_email. See example below.
6. Go to Users --> Import From CSV. Browse to CSV file and import.

Copy these lines to your active theme's functions.php or custom plugin and modify as desired to send a welcome email to members after import:

global $pmproiufcsv_email;
$pmproiufcsv_email = array(
    'subject'   => sprintf('Welcome to %s', get_bloginfo('sitename')), //email subject, "Welcome to Sitename"
    'body'      => 'Your welcome email body text will go here.'        //email body
);

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the GitHub issue tracker here: https://github.com/strangerstudios/pmpro-import-users-from-csv/issues

= I need help installing, configuring, or customizing the plugin. =

Please visit our premium support site at http://www.paidmembershipspro.com for more documentation and our support forums.

== Changelog ==

= .3.4 =
* BUG/ENHANCEMENT: Setting order status to "success" by default for gateway integration.

= .3.3 =
* BUG/ENHANCEMENT: Deleting previous import_ user meta before an import. We don't want to process old import data if the same user is imported twice with different columns.
* BUG/ENHANCEMENT: Setting a membership_code_id column now adds the code_id to the pmpro_memberships_users table and also inserts a row into pmpro_discount_code_uses.
* ENHANCEMENT: Can now set a column with heading membership_discount_code to set the code used for a certain member. It must be an existing discount code to work.

= .3.2 =
* BUG: Fixed issue where users imported with no enddate (NULL) were being expired immediately after importing.

= .3.1 =
* Now setting blank membership_enddate values to "NULL" to avoid 0000-00-00 type dates in the database.

= .3 =
* If the membership_status column is inactive or the membership_enddate is in the past (from the time of import), the user will be given the specified membership level and then turned inactive. They will show up in the "old members" list of the Members List and will not have a valid membership level. Their gateway subscription if given will be assumed cancelled at the gateway. PMPro will not send a message to cancel the subscription.
* Adjusting format of startdate, enddate, and timestamp to date("Y-m-d", strtotime($var)).

= .2 =
* Added ability to set the timestamp of the dummy order created by setting a membership_timestamp column. Use a date format like 2014-01-01.
* Setting membership_id for dummy orders based on value in import.

= .1.1 =
* Added ability to send custom emails after import by defining the $pmproiufcsv_email global.

= .1 =
* Initial version.
