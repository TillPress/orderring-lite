=== OrderRing Lite – SMS for WooCommerce ===
Contributors: alextechgamer
Tags: sms, woocommerce sms, order notifications, twilio, local pickup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0

Text WooCommerce customers when an order is ready for pickup (or hits a status you pick), via your own Twilio, Vonage or Plivo. Zero markup.

== Description ==

OrderRing Lite texts customers when an order is ready for pickup, using **your** Twilio, Vonage or Plivo account.

* Send SMS from the order screen
* Choose your SMS provider: Twilio, Vonage or Plivo. Replies, STOP opt-outs and delivery status work with all three
* Auto SMS when an order enters **Ready for Pickup** (or any status you map)
* Checkout consent checkbox (classic + block checkout)
* STOP / HELP / START keyword handling, with every US FCC opt-out word (STOP, QUIT, END, REVOKE, OPT OUT, CANCEL, UNSUBSCRIBE) and plain-language replies like "please stop texting me"
* Optional quiet hours in the customer's own time zone: the pickup text waits until morning where the customer is
* Optional Local Pickup filter
* Triggers: text your admin number when someone creates an account, resets their password, posts a comment or sends a Contact Form 7 form. A Contact Form 7 form can also text the person who sent it, only when they ticked a consent checkbox you map
* Compatible with WooCommerce HPOS
* Help & demo page: one-click demo order, the exact pickup text a customer would get, and a support ticket form

**Bring your own provider account.** The plugin does not sell or provide messaging services. Message costs are billed to you by Twilio, Vonage or Plivo — OrderRing adds zero markup.

Twilio and all related logos are trademarks of Twilio Inc. or its affiliates. OrderRing is not affiliated with, endorsed, or sponsored by Twilio Inc.

A paid **OrderRing** plugin (voice, WhatsApp, two-way chat, bulk reminders) is sold separately at https://tillpress.com/orderring/. It is a different plugin. This Lite plugin is complete and is never locked.

== External services ==

This plugin connects to **one** third-party SMS provider, the one **you** choose on the settings screen: **Twilio** (the default), **Vonage** or **Plivo**. It uses the account credentials you enter there (or in `wp-config.php`). A provider is required for the plugin's core function: sending and receiving text messages. The plugin does not resell or provide messaging itself — you contract with the provider directly and the provider bills you. Only the selected provider is contacted when a text is sent; a provider you do not use is never contacted.

**Twilio** (api.twilio.com) — what is sent, and when:

* **When you (or the "Ready for Pickup" automation, or a trigger rule) send an SMS:** the plugin calls the Twilio REST API (`https://api.twilio.com`) with your Account SID and Auth Token, the destination phone number, your Twilio "from" number, and the message body (which may include the customer's first name, order number, order total, and tracking number from the merge tags you configure, and for a trigger rule the account, comment or Contact Form 7 field values you put in its message).
* **When you use "Test connection":** the plugin makes a read-only call to the Twilio API to confirm your credentials are valid.
* **When a customer replies (STOP/HELP/START or any inbound text) or Twilio reports delivery status:** Twilio calls this site's webhook URLs. The plugin verifies Twilio's request signature and stores the message/status in your own database. No customer data is sent out in this direction — it is received from Twilio.

No data is sent to a provider unless you have selected it, entered its credentials, and either send a message or receive one. Apart from the optional support ticket below, this plugin sends no data to the plugin author or any other third party — there is no license check, telemetry, or phone-home.

**Support tickets (only when you send one):** the Help & demo page has a support form. When an administrator fills it in, ticks the consent box, and presses **Send ticket**, the plugin sends the name, reply-to email, topic, subject, message, this site's address, and (if ticked) a system report of WordPress/WooCommerce/PHP versions, theme, and active plugin names to the Tillpress support desk at `https://tillpress.com/wp-json/tp/v1/support`. Nothing is sent automatically, in the background, or on a schedule, and no passwords, keys, or customer/order data are included. Service provider: Tillpress (Alex Software Holdings LLC). Privacy policy: https://tillpress.com/privacy/ — Terms: https://tillpress.com/terms/ . "Email instead" opens the same ticket in your own email app and sends nothing from the site.

Twilio's terms and privacy policy:

* Terms of Service: https://www.twilio.com/legal/tos
* Privacy Policy: https://www.twilio.com/legal/privacy

**Vonage** (Vonage Communications APIs, formerly Nexmo; rest.nexmo.com) — used only when you choose Vonage as the SMS provider.

* **When a text is sent** (the "Ready for Pickup" automation, a trigger rule, a text you send from an order, a test text, or a STOP/HELP/START auto-reply): the plugin calls `https://rest.nexmo.com/sms/json` with your API key and API secret, your sender number or ID, the customer's phone number, the message text, and this site's delivery-receipt URL.
* **When a customer replies or Vonage reports delivery:** Vonage calls this site's webhook URLs. The plugin verifies Vonage's signature with your signature secret and stores the message or status in your own database. Nothing is sent out in this direction.
* Terms of Use: https://www.vonage.com/legal/communications-apis/terms-of-use/
* Privacy Policy: https://www.vonage.com/legal/privacy-policy/

**Plivo** (api.plivo.com) — used only when you choose Plivo as the SMS provider.

* **When a text is sent** (same cases as above): the plugin calls `https://api.plivo.com/v1/Account/{your Auth ID}/Message/` with your Auth ID and Auth Token, your sender number or ID, the customer's phone number, the message text, and this site's status-callback URL.
* **When a customer replies or Plivo reports delivery status:** Plivo calls this site's webhook URLs. The plugin verifies Plivo's signature with your Auth Token and stores the message or status in your own database. Nothing is sent out in this direction.
* Terms of Service: https://www.plivo.com/legal/tos/
* Privacy Policy: https://www.plivo.com/legal/privacy/

== Installation ==

1. Upload the `orderring-lite` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu (WooCommerce is optional: without it you can still send texts, use Triggers and get replies)
3. Open **OrderRing Lite** and enter your Twilio Account SID, Auth Token, and From Number
4. Map Ready for Pickup (the plugin registers that status by default)
5. In the Twilio Console, set the Incoming SMS webhook to the URL shown on the settings screen

== Frequently Asked Questions ==

= Do I need a Tillpress account? =
No. OrderRing Lite is completely self-contained. There is no account to create, no login, and no license key. The plugin never contacts Tillpress (the author's) servers unless you choose to send a support ticket from the Help & demo page. You only need your own Twilio account to send messages.

= Does it work with a Twilio trial account? =
Yes. Enter the trial Account SID, Auth Token, and From Number on the settings screen like any other Twilio account. Twilio trial accounts can only send to numbers you have verified in the Twilio Console, so use that for a smoke test. When you are ready to text real customers, upgrade the Twilio account itself — this plugin does not sell Twilio service and has no trial of its own.

= Do I need a Twilio account, and who pays for texts? =
Yes. You provide Account SID, Auth Token, and a From Number from your own Twilio account. You contract with Twilio directly; this plugin only calls the Twilio API with the credentials you enter. OrderRing Lite never bills you for messages and adds no markup.

= How much does each SMS actually cost? =
Whatever Twilio charges for that destination — typically a few cents in the US, more for some international routes, plus any A2P 10DLC or sender-registration fees Twilio collects. The plugin does not resell messages, hold a balance, or add a per-text fee. Check Twilio's current SMS pricing for your From number and destination country before you go live.

= Where do SMS charges come from? =
From your own Twilio account. You contract with Twilio directly and Twilio bills you for every message at Twilio's rates. OrderRing Lite adds no markup and never handles payment for messaging — the plugin only calls the Twilio API with the credentials you enter.

= What data leaves my site? =
Only what you send to Twilio, and only when you (or the "Ready for Pickup" automation) send a message: the destination phone number, your Twilio "from" number, and the message body (which may include the customer's first name, order number, order total, and tracking number from the merge tags you configure). Nothing is sent to the plugin author or any other third party, except a support ticket you choose to send from the Help & demo page — there is no license check, telemetry, or phone-home. See the "External services" section above for the full breakdown.

= Do I need A2P 10DLC? =
If you text US mobile numbers, carriers require A2P 10DLC (or a verified toll-free number). Register that in the Twilio Console. This plugin does not file it for you.

= Which SMS providers does it work with? =
Twilio (the default), Vonage and Plivo. Pick one under **OrderRing Lite → Settings → SMS provider** and enter that account's credentials. All three send texts, receive replies (including STOP / HELP / START) and report delivery status. Your opt-out list and consent records are kept when you switch providers, and replies that still reach a provider you used before are honored.

= Does it need WooCommerce? =

No. Without WooCommerce, Lite sends texts through your Twilio account and handles STOP/HELP/START replies. The ready-for-pickup text and the checkout consent box need WooCommerce.

= Is there a license key or trial? =
No. Every feature listed above works without a key, trial, or phone-home to the author.

= What if I also install OrderRing (the paid plugin)? =
Lite steps aside so you do not send duplicate SMS. While both are active, Lite stays idle and shows a notice on the Plugins screen. You can deactivate Lite.

= How do I get support? =
Open **OrderRing Lite → Help & demo**. The support form there sends your ticket straight to the Tillpress support desk at tillpress.com after you tick the consent box and press Send ticket; you get a ticket number on screen and the reply comes by email. It includes your name, email, message, this site's address, and (optional) a system report of versions, theme, and active plugins; never passwords, keys, or customer data. Prefer email? "Email instead" opens the same ticket in your own email app. You can also use the WordPress.org support forum for this plugin or https://tillpress.com/support/. Replies Monday to Friday, 09:00 to 17:00 Pacific Time; first reply within one business day.

= Is there a demo? =
Yes. On **OrderRing Lite → Help & demo**, click **Create demo data**. It creates one order in Ready for Pickup with Local Pickup and SMS consent, so you can see the order-screen SMS box, the consent badge, and the exact text your customer would receive. Demo orders can never be texted, even if you press Send. Everything is labelled "Demo", never emailed or texted, and removed with one click (or when you uninstall the plugin).

= Does the plugin advertise anything? =
Only on its own screens. The settings page has a short box listing what the paid OrderRing plugin adds, and the Help & demo page has one box with an optional 20% discount code that can be hidden for good with "Hide this offer". After 14 days and 10 sent texts, OrderRing Lite's own screens ask once for a review, which you can dismiss. There are no dashboard-wide notices, no tracking, and no referral IDs.

= Can I set credentials in wp-config.php? =
Yes. Define `ORLT_ACCOUNT_SID`, `ORLT_AUTH_TOKEN`, and/or `ORLT_FROM_NUMBER` (the older `ORL_` names still work). Constants override the settings fields so credentials can stay out of the database.

== Screenshots ==

1. OrderRing Lite settings screen — enter your own Twilio Account SID, Auth Token, and From Number. No license key, no account sign-up.
2. The Ready-for-Pickup message template with merge tags (first name, order number, total, tracking) and the status that triggers the automatic SMS.
3. Sending an SMS straight from the WooCommerce order screen, with the delivery result and the customer's consent badge.
4. Twilio credentials with "Test connection" — a read-only call that confirms your account is valid before you send.

== Changelog ==








= 1.5.0 =
* New Triggers screen (OrderRing Lite → Triggers): text your admin number when someone creates an account, resets their password, posts a comment or sends a Contact Form 7 form. Put any form field in the message as {field:your-name}.
* A Contact Form 7 form can also text the person who sent it, only when they ticked the consent checkbox you map in the rule. That consent is recorded with the form ID.
* Trigger texts respect STOP replies, consent and quiet hours (held until they end), and anything a visitor can cause is rate limited: by default 5 events an hour from one visitor, 3 texts an hour to one number and 30 to the admin number.
= 1.4.0 =
* New: choose your SMS provider. Besides Twilio, Lite now sends through your own Vonage or Plivo account (OrderRing Lite → Settings → SMS provider). Replies, STOP / HELP / START and delivery status work with all three; Vonage and Plivo webhooks are verified with their signatures and refused without them.
* Your opt-out list and consent records stay the same when you switch providers, and a STOP that still reaches a provider you used before is honored.
* A text whose result is unknown (for example a timeout) is never resent through another provider.
* The readme's External services section now lists Vonage and Plivo: what is sent, when, and their terms and privacy policies.
* The support report shows which provider is selected and whether it is set up, never its credentials.
= 1.3.0 =
* Every logged message now records what it was about (an order today; forms, users and other events later). Existing order messages are stamped in the background after the update, in small batches so large logs stay fast.
* Consent is now kept per phone number, with order and service texts kept apart from marketing texts. A STOP always wins and also withdraws marketing consent. Checkout consent is recorded against the phone number as well as the order.
* Merge tags come from one shared list, so {site_name}, {contact_first_name}, {contact_last_name} and {contact_phone} work in any text, with or without an order. Order tags work exactly as before.
= 1.2.0 =
* WooCommerce is now optional. On a site without WooCommerce, Lite still sends texts through your Twilio account, logs them, and answers STOP/HELP/START. The pickup text and checkout consent need WooCommerce and are hidden until it is active (your saved settings are kept). Stores with WooCommerce work exactly as before.
* New "Send test SMS" button under the Twilio credentials.
= 1.1.3 =
* Renamed to "OrderRing Lite – SMS for WooCommerce" so stores searching for WooCommerce SMS can find it. Name, tags and description only; no settings, data or behavior change.
= 1.1.2 =
* Refreshed admin look, shared across Tillpress plugins: rounded cards, pill tabs and buttons, settings forms and tables as cards. Only the plugin's own screens change; no settings or data change.
= 1.1.1 =
* Fix: {order_total} no longer shows "&#36;" instead of "$" in texts.
* Fix: a phone extension such as "ext 5" is dropped instead of being dialled, and a national number with a leading 0 on a US store is no longer turned into a wrong +1 number.
* Fix: the settings page now shows "Settings saved" and error messages, and the From number and invalid-phone messages are in plain language.
* Fix: the order note for a manual text shows the message that was sent, not the raw {tags}.
* The settings page lists every tag you can use, and the review request only shows on OrderRing Lite screens.

= 1.1.0 =
* New: REVOKE and OPT OUT now opt a customer out, alongside STOP, STOPALL, UNSUBSCRIBE, CANCEL, END and QUIT, as US FCC rules require.
* New: plain-language opt-outs such as "please stop texting me", "remove me from your list" or "wrong number" are treated as STOP. You can switch this to only add an order note. Order questions like "can I cancel my order?" are not matched.
* New: optional quiet hours (off by default). The ready-for-pickup text waits until quiet hours end in the customer's time zone, worked out from the billing country and state, or the store's time zone when the address does not say.
= 1.0.11 =
* Fix: merge tags in the pickup text are filled in once, so a customer name that looks like a tag is not expanded.
* Fix: a repeated Twilio webhook for the same inbound message is ignored even after 24 hours.
* Fix: shop managers can save the settings page they can open. The public site URL override stays admin-only.
= 1.0.10 =
* Fix: a number that replied STOP can no longer be texted from the order screen, even with "send anyway". "Send anyway" now only overrides missing checkout consent.
* Fix: uninstalling now also removes the backup of Twilio settings copied from OrderRing Pro.
* Readme: the FAQ now mentions the opt-in support ticket as the one time the plugin contacts Tillpress.
= 1.0.9 =
* Fix: inbound SMS is attributed only on a unique full-number match; suffix collisions stay unattributed.
* New: Copy settings from OrderRing Pro (credentials, templates, STOP list) with a reversible backup. No automatic copy.
* Fix: missing Twilio credentials defer pickup SMS instead of completing the notification; they resume when settings are saved.

= 1.0.8 =
* Fix: Lite keeps sending pickup SMS when OrderRing Pro is installed but unlicensed or expired, and stays idle when Pro is licensed (no duplicate texts).
* Fix: STOP and START are keyed by the full international number, so two numbers sharing their last 10 digits no longer share an opt-out.

= 1.0.7 =
* New: Help & demo page (OrderRing Lite → Help & demo, also linked from the Plugins screen).
* New: one-click demo order in Ready for Pickup with a preview of the exact pickup text. Demo orders are never texted or emailed and are removed with one click or on uninstall.
* New: support ticket form that sends straight to the Tillpress support desk after you tick the consent box and press Send ticket (ticket number shown on screen), with an optional system report (no passwords, keys, or customer data). "Email instead" opens your own email app.
* New: optional, dismissible 20% offer for the paid OrderRing plugin, shown only on the Help & demo page.

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

= 1.1.0 =
Honors every FCC opt-out word and plain-language opt-out replies, and adds optional quiet hours in the customer's time zone.

= 1.0.7 =
Adds a Help & demo page: demo order, in-plugin support tickets, and system report. No settings change.

= 1.0.5 =
Internal prefix change to "orlt" for wordpress.org compliance. Your settings, opt-out lists, and message logs migrate automatically — no reconfiguration needed.
