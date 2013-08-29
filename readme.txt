=== PMPro Import Users from CSV Add-on ===
Contributors: strangerstudios
Tags: paid memberships pro, import users from csv, import, csv, members
Requires at least: 3.0
Tested up to: 3.6
Stable tag: .1

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
4. Go to Users --> Import From CSV. Browse to CSV file and import.

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the GitHub issue tracker here: https://github.com/strangerstudios/pmpro-import-users-from-csv/issues

= I need help installing, configuring, or customizing the plugin. =

Please visit our premium support site at http://www.paidmembershipspro.com for more documentation and our support forums.

== Changelog ==
= .1 =
* Initial version.