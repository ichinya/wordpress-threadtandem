=== Treda ===
Contributors: ichi-nya
Tags: telegram, comments, discussion, crossposting
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish posts to Telegram and synchronize text comments with a linked discussion group, using native WordPress comments and moderation.

== Description ==

Treda connects one WordPress site to one Telegram bot, one channel and its linked discussion group.
Each published post keeps a persistent link to its Telegram announcement and discussion.

Features:

* Publish text announcements for public posts and update the same message when the exported title, excerpt or link changes.
* Disable Telegram publishing for an individual post. Drafts, private posts and password-protected posts are excluded.
* Control publishing in the block editor sidebar on WordPress 7.1, with a classic-editor fallback.
* Copy text replies from linked Telegram discussions into native WordPress comments.
* Send approved website comments to the same discussion, replying to the exact parent message.
* Continue a nested conversation across both platforms without echoing imported comments back to Telegram.
* Hold new Telegram comments for review by default. WordPress spam and moderation rules still apply.
* Hold edited Telegram text for review, even if its previous text was approved.
* Identify users by Telegram IDs rather than names. No invented email addresses or automatically created WordPress accounts.
* Process deliveries in a database queue with duplicate detection, worker leases, rate-limit delays and visible diagnostics.
* Keep uncertain outgoing results for manual investigation instead of silently sending duplicate messages.
* Configure a separate HTTPS Bot API server and an optional external webhook URL.
* English interface with a bundled Russian translation.

This release supports text posts and comments. Telegram Login, forum topics, attachments, automatic historical import and moderation commands in Telegram are not included.
Long announcements and website comments are shortened to fit Telegram; their complete original text remains on the website.

= External service: Telegram =

Treda uses the Telegram Bot API, normally at https://api.telegram.org. You need a Telegram account, a bot token and permission to administer the channel and discussion group. You may configure another HTTPS Bot API endpoint; that endpoint then receives the same requests.

Treda contacts Telegram when you check the connection, manage the webhook, or process enabled synchronization jobs. It sends the bot token, chat and message identifiers, published post titles, excerpts and links. When comment synchronization is enabled, approved website comment text and the comment author display name are also sent. Comment email addresses and IP addresses are not sent.

Telegram delivers discussion messages to your webhook. Eligible text replies are stored in WordPress with their display names, numeric sender identities, chat/message identifiers and reply relationships. Inform participants before enabling import, including participants of private groups. The comment form informs website visitors that approved comments and their names will also appear in Telegram.

Telegram service terms: https://telegram.org/tos
Telegram privacy policy: https://telegram.org/privacy
Bot API documentation: https://core.telegram.org/bots/api

Treda is an independent integration and is not affiliated with Telegram or WordPress.org. It does not include analytics or advertising.

== Installation ==

1. Upload the treda directory to wp-content/plugins/ or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate Treda on the individual site. Network-wide activation is not supported.
3. Open Settings > Treda. Save a dedicated bot token and a channel ID or @username.
4. Add the bot as a channel administrator with permission to publish. For comments, link a discussion group to the channel and make the bot an administrator of that group too.
5. Enable the features you want, save, and click Check connection. Publishing and comment synchronization are off by default.
6. Register the webhook. Telegram must be able to reach your site through public HTTPS. Treda refuses to replace a different integration webhook.
7. Publish a test post. Check Queue and diagnostics, or use Process queue now.
8. Reply under the announcement in Telegram, review the imported comment in WordPress, then reply from the site to verify the conversation.

An optional TREDA_BOT_TOKEN constant in wp-config.php takes precedence over the database token. The saved token is never rendered back into the settings form. Protect database backups as you would other site credentials.

WordPress 6.2 or later is required for safely prepared database table identifiers. The comment, comment metadata and Treda mapping tables must use InnoDB for transactional imports.

== Frequently Asked Questions ==

= Does viewing a post call Telegram? =

No. Visitors read native WordPress comments. Network work runs in the background queue or through explicit administrator actions.

= Why is delivery delayed? =

WP-Cron depends on site visits. Treda schedules a batch about once a minute and handles up to three jobs per batch. Configure a server cron for wp-cron.php when predictable delivery matters. Rate limits and unresolved parent mappings delay individual jobs.

= What if the send result is unknown? =

A timeout may occur after Telegram accepted a message. Treda marks this as Result unknown and does not automatically repeat it. Check Telegram before using the administrator retry control. A manual retry can create a duplicate; automatic reconciliation is not provided.

= Can I use a local WordPress installation? =

Outgoing API checks and sends can work locally. Incoming Telegram events require a public HTTPS route to the REST endpoint. The external webhook URL option does not create a tunnel. A proxy must preserve the request body and X-Telegram-Bot-Api-Secret-Token header.

= Can I use a custom Bot API server? =

Yes, through the advanced HTTPS API URL setting. All requests use it. WordPress safe HTTP rules may reject private IP addresses and nonstandard ports. HTTP endpoints and URLs containing credentials or query parameters are rejected.

= Which comments are imported? =

Only text messages in the configured discussion group that belong to an exactly mapped post thread or reply chain. Unmatched parents wait rather than being assigned to another post. Bot messages, commands, media and unrelated chat messages are not imported. A site requiring registered commenters cannot import Telegram-only authors.

= Are moderation and deletion mirrored everywhere? =

Website comments are sent only after approval. Unapproving, spamming, trashing or deleting a website-origin comment attempts to remove its bot-authored Telegram copy. Telegram may reject removal because of permissions or message age; the queue reports that failure.

Moderation or deletion of an imported Telegram comment affects its WordPress copy. It does not delete the participant's original Telegram message. Telegram does not deliver ordinary group message deletions to Bot API clients, so those deletions cannot automatically remove the WordPress copy.

= Can I change the bot or channel? =

New settings need a new connection check. Existing post mappings are not silently moved to another bot or channel. An old queued job may need to be saved again after a connection change. Historical remapping is outside this release.

= What data is retained? =

Successful incoming jobs have their message payload cleared. Completed and failed incoming job records are removed after seven days. Comment content is native site content; message mappings remain until integration data is removed. The Privacy Policy Guide includes suggested Treda text. Additional sender metadata participates in email-based WordPress privacy tools when a matching comment email exists. Telegram-only authors have no email supplied by Telegram and must contact the site administrator to verify their identity for access or removal requests.

= What happens when I deactivate or uninstall Treda? =

Deactivation stops the scheduled worker and preserves data. Disconnect the webhook before removing the plugin. By default, uninstall keeps integration data. Clear Keep integration data when deleting the plugin to remove Treda options, tables and integration metadata during uninstall. Core posts and comments remain. Telegram copies and other externally stored data are not removed automatically.

== Changelog ==

= 1.0.1 =
* Add a native Telegram publishing panel for the WordPress block editor, using the current editor API with a fallback for WordPress 6.2.
* Save the per-post publishing preference through capability-protected REST metadata.
* Queue post exports after WordPress has saved REST metadata, avoiding stale publishing preferences and duplicate scheduling.
* Retain the classic-editor control and exclude editorial notes from public Telegram comments.

= 1.0.0 =
* Initial release: channel publishing, exact discussion mapping, bidirectional text replies, native moderation, durable queue and privacy controls.
