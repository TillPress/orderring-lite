=== OrderRing Lite ===
Contributors: alextechgamer
Tags: woocommerce, sms, twilio, pickup, notifications
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0

Ready-for-pickup SMS for WooCommerce via your own Twilio account. Consent-aware. You pay Twilio directly, zero markup.

== Description ==

OrderRing Lite texts customers when an order is ready for pickup, using **your** Twilio account.

* Send SMS from the order screen
* Auto SMS when an order enters **Ready for Pickup** (or any status you map)
* Checkout consent checkbox (classic + block checkout)
* STOP / HELP / START keyword handling
* Optional Local Pickup filter
* Compatible with WooCommerce HPOS

**Bring your own Twilio account.** The plugin does not sell or provide messaging services. Message costs are billed by Twilio to you — OrderRing adds zero markup.

Twilio and all related logos are trademarks of Twilio Inc. or its affiliates. OrderRing is not affiliated with, endorsed, or sponsored by Twilio Inc.

A paid **OrderRing** plugin (voice, WhatsApp, two-way chat, bulk reminders) is sold separately at https://tillpress.com/orderring/. It is a different plugin. This Lite plugin is complete and is never locked.

== External services ==

This plugin connects to **Twilio**, a third-party SMS/messaging provider, using the Twilio account credentials **you** configure on the settings screen (or in `wp-config.php`). Twilio is required for the plugin's core function: sending and receiving order text messages. The plugin does not resell or provide messaging itself — you contract with Twilio directly and Twilio bills you.

What is sent, and when:

* **When you (or the "Ready for Pickup" automation) send an SMS:** the plugin calls the Twilio REST API (`https://api.twilio.com`) with your Account SID and Auth Token, the destination phone number, your Twilio "from" number, and the message body (which may include the customer's first name, order number, order total, and tracking number from the merge tags you configure).
* **When you use "Test connection":** the plugin makes a read-only call to the Twilio API to confirm your credentials are valid.
* **When a customer replies (STOP/HELP/START or any inbound text) or Twilio reports delivery status:** Twilio calls this site's webhook URLs. The plugin verifies Twilio's request signature and stores the message/status in your own database. No customer data is sent out in this direction — it is received from Twilio.

No data is sent to Twilio unless you have entered Twilio credentials and either send a message or receive one. This plugin sends no data to the plugin author or any other third party — there is no license check, telemetry, or phone-home.

Twilio's terms and privacy policy:

* Terms of Service: https://www.twilio.com/legal/tos
* Privacy Policy: https://www.twilio.com/legal/privacy

== Installation ==

1. Upload the `orderring-lite` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu (WooCommerce must be active)
3. Open **OrderRing Lite** and enter your Twilio Account SID, Auth Token, and From Number
4. Map Ready for Pickup (the plugin registers that status by default)
5. In the Twilio Console, set the Incoming SMS webhook to the URL shown on the settings screen

== Frequently Asked Questions ==

= Do I need a Tillpress account? =
No. OrderRing Lite is completely self-contained. There is no account to create, no login, no license key, and no call to Tillpress (the author's) servers — ever. You only need your own Twilio account to send messages.

= Does it work with a Twilio trial account? =
Yes. Enter the trial Account SID, Auth Token, and From Number on the settings screen like any other Twilio account. Twilio trial accounts can only send to numbers you have verified in the Twilio Console, so use that for a smoke test. When you are ready to text real customers, upgrade the Twilio account itself — this plugin does not sell Twilio service and has no trial of its own.

= Do I need a Twilio account, and who pays for texts? =
Yes. You provide Account SID, Auth Token, and a From Number from your own Twilio account. You contract with Twilio directly; this plugin only calls the Twilio API with the credentials you enter. OrderRing Lite never bills you for messages and adds no markup.

= How much does each SMS actually cost? =
Whatever Twilio charges for that destination — typically a few cents in the US, more for some international routes, plus any A2P 10DLC or sender-registration fees Twilio collects. The plugin does not resell messages, hold a balance, or add a per-text fee. Check Twilio's current SMS pricing for your From number and destination country before you go live.

= Where do SMS charges come from? =
From your own Twilio account. You contract with Twilio directly and Twilio bills you for every message at Twilio's rates. OrderRing Lite adds no markup and never handles payment for messaging — the plugin only calls the Twilio API with the credentials you enter.

= What data leaves my site? =
Only what you send to Twilio, and only when you (or the "Ready for Pickup" automation) send a message: the destination phone number, your Twilio "from" number, and the message body (which may include the customer's first name, order number, order total, and tracking number from the merge tags you configure). Nothing is sent to the plugin author or any other third party — there is no license check, telemetry, or phone-home. See the "External services" section above for the full breakdown.

= Do I need A2P 10DLC? =
If you text US mobile numbers, carriers require A2P 10DLC (or a verified toll-free number). Register that in the Twilio Console. This plugin does not file it for you.

= Is there a license key or trial? =
No. Every feature listed above works without a key, trial, or phone-home to the author.

= What if I also install OrderRing (the paid plugin)? =
Lite steps aside so you do not send duplicate SMS. While both are active, Lite stays idle and shows a notice on the Plugins screen. You can deactivate Lite.

= Can I set credentials in wp-config.php? =
Yes. Define `ORLT_ACCOUNT_SID`, `ORLT_AUTH_TOKEN`, and/or `ORLT_FROM_NUMBER` (the older `ORL_` names still work). Constants override the settings fields so credentials can stay out of the database.

== Screenshots ==

1. OrderRing Lite settings screen — enter your own Twilio Account SID, Auth Token, and From Number. No license key, no account sign-up.
2. The Ready-for-Pickup message template with merge tags (first name, order number, total, tracking) and the status that triggers the automatic SMS.
3. Sending an SMS straight from the WooCommerce order screen, with the delivery result and the customer's consent badge.
4. Twilio credentials with "Test connection" — a read-only call that confirms your account is valid before you send.

== Changelog ==

= 1.0.6 =
* Fix: "Use Ready for Pickup template" restores the saved template instead of your edited text.
* New: a one-time, dismissible request for a wordpress.org review, shown to admins only after 10 sent texts and 14 days.
* Clearer wording in the settings panel about what OrderRing Pro adds.

= 1.0.5 =
* Prefix all functions, options, hooks, roles, custom tables, and script/style handles with the distinct "orlt" prefix (wp.org 4-character prefix guideline). Existing settings, opt-out lists, and message logs migrate automatically on update.
* REST webhook routes now verify the Twilio request signature in a permission_callback (the in-handler signature check is kept as defense in depth).
* Document the Twilio third-party service under "External services" (what data is sent, when, and links to Twilio's terms and privacy policy).
* Point Plugin URI / Author URI / paid-plugin link at the current tillpress.com pages.
= 1.0.4 =
* Normalize mixed CRLF/LF line endings in the main plugin file (Plugin Check Internal.LineEndings.Mixed)
= 1.0.3 =
* Tested up to WordPress 7.1 (Plugin Check outdated_tested_upto_header)
* Plugin URI now points at the Lite landing page
* Author set to AlexTechGamer / alextechgamer.com
= 1.0.2 =
* Use $wpdb->prepare() %i for table names (Plugin Check InterpolatedNotPrepared)
* Requires WordPress 6.2+ (%i identifiers)
= 1.0.1 =
* Plugin Check: translators comment, TwiML escaping, drop unused logger queries
* readme: Tested up to 7.0
* Restore failed-SMS order notes; stop calling a missing Shipped helper
= 1.0.0 =
* First public release: Ready-for-pickup SMS, checkout consent, STOP/HELP/START

== Upgrade Notice ==

= 1.0.5 =
Internal prefix change to "orlt" for wordpress.org compliance. Your settings, opt-out lists, and message logs migrate automatically — no reconfiguration needed.
