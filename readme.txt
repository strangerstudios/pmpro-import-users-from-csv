=== Paid Memberships Pro - Import Members From CSV Add On ===
Contributors: strangerstudios
Tags: paid memberships pro, import users from csv, import, csv, members
Requires at least: 5.4
Tested up to: 6.4
Stable tag: 1.1

Import your users or members list to WordPress and automatically assign membership levels in PMPro.
 
== Description ==

Import Members From CSV is a Paid Memberships Pro Add On that allows you to create new users and update existing users by importing a simple CSV file.

This Add On creates users, assigns membership levels to new or existing users, and updates subscription information.

If you are migrating from another platform, you can adding gateway subscription information to your import and continue existing subscriptions. After import, a single placeholder order (used for gateway syncing) will be created.

For more help using this Add On, refer to the documentation here: https://www.paidmembershipspro.com/add-ons/pmpro-import-users-csv/.

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the GitHub issue tracker here: https://github.com/strangerstudios/pmpro-import-users-from-csv/issues

= I need help installing, configuring, or customizing the plugin. =

Please visit our premium support site at http://www.paidmembershipspro.com for more documentation and our support forums.

== Changelog ==
= 1.1 - TBD =
* ENHANCEMENT: Improved cleaning up temporary user meta during member importing.
* ENHANCEMENT: New hook added 'pmproiucsv_after_member_import' to allow custom code to run after membership information is imported.
* ENHANCEMENT: Ensure that the person running the import can 'create_users'.
* ENHANCEMENT: Added support for Paid Memberships Pro 3.0+ when importing subscriptions.
* BUG FIX: Fixed an issue where an error would be shown on importing members with subscriptions, even though the import was successful.

= 1.0 - 2024-01-16 =
* ENHANCEMENT: Refactored entire plugin to be a standalone plugin. This plugin no longer requires the Import Users From CSV plugin to work.
* ENHANCEMENT: Supports importing multiple memberships per user.
* ENHANCEMENT: Importing membership_id 0 will cancel any existing memberships for the user.
* ENHANCEMENT: The password reset email will only send out if the option is selected during import and the user is new. This will never send when updating users or members.
* ENHANCEMENT: Added batch importing for large CSV files. This defaults to 50 imports per iteration, adjust the amount by using the filter `pmprocsv_ajax_import_batch`.
* ENHANCEMENT: General improvements and warnings when importing data that is required but missing from the CSV or importing a level that doesn't exist.
* ENHANCEMENT: Updated the CSV sample file to have newer values.
* REFACTOR: Improved logic around creating orders during import. See documentation for full details.
* BUG FIX: Fixed an issue where empty meta keys were being imported as "". This now skips and does not update the meta key if the value is blank.

= 0.4 - 2022-03-14 =
* ENHANCEMENT: Added in an option to skip over members if trying to import members with their current membership level. This is helpful for large CSV files that may have duplicate records.
* ENHANCEMENT: Prevent subscriptions from being cancelled by incorrect imports. Keep these subscriptions in tact, but can disable this logic by using the `pmproiufcsv_cancel_prev_sub_on_import` filter.
* ENHANCEMENT: Show a notice if the Import Users From CSV (base) plugin isn't installed or activated which is required for this Add On to work.
* ENHANCEMENT: Improved general SQL scripts where possible by using prepare method from WPDB class.
* ENHANCEMENT: General improvements to localization of plugin, now translatable.
* BUG FIX: Fixed issue where expiration date (membership_enddate) was being set to import's date when importing pre-existing members. This now removes the expiration date for existing members if the membership_enddate column is blank or missing.

= .3.4 =
* BUG FIX: Fixed bug with the welcome email sent if using the $pmproiufcsv_email global.
* BUG FIX/ENHANCEMENT: Setting order status to "success" by default for gateway integration. You can also now set a specific membership_order_status column for imported rows.
* ENHANCEMENT: Now showing a notice if the Import Users From CSV plugin is not also installed.

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
