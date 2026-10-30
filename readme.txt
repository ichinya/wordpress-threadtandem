=== ThreadTandem — Post Publishing and Comment Sync for Telegram ===
Contributors: ichi-nya
Tags: telegram, comments, discussion, crossposting
Requires at least: 6.2
Tested up to: 7.1.4
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish posts to Telegram and synchronize text comments with a linked discussion group, using native WordPress comments and moderation.

== Description ==

ThreadTandem connects one WordPress site to one Telegram bot, one channel and its linked discussion group.
Each published post keeps a persistent link to its Telegram announcement and discussion.
ThreadTandem is independently developed and is not affiliated with or endorsed by Telegram.

Features:

* Publish text announcements for public posts and update the same message when the exported title, excerpt or link changes.
* Customize the article link text in settings. Russian sites default to "Читать статью" even without an installed plugin language pack.
* Import new channel text posts and media captions as WordPress drafts through the background queue.
* Recover up to 100 pending events after a delivery outage, acknowledging only events durably accepted by the queue and restoring the webhook afterwards.
* Review unmatched publications under Settings > ThreadTandem synchronization: ignore, link to an existing article, or queue a website article for delivery.
* Preserve WordPress as the source for linked articles; Telegram edits update untouched imported drafts only. Matching keeps the original source draft available.
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
* English interface and bundled Russian translations, including block-editor controls. Also supports WordPress.org language packs.

This release supports text posts and comments. Media captions can be imported as text, without downloading attachments. Telegram Login, forum topics, attachments, automatic historical import and moderation commands in Telegram are not included.
Long announcements and website comments are shortened to fit Telegram; their complete original text remains on the website.

= External service: Telegram =

ThreadTandem uses the Telegram Bot API, normally at https://api.telegram.org. You need a Telegram account, a bot token and permission to administer the channel and discussion group. You may configure another HTTPS Bot API endpoint; that endpoint then receives the same requests.

ThreadTandem contacts Telegram when you check the connection, manage the webhook, or process enabled synchronization jobs. It sends the bot token, chat and message identifiers, published post titles, excerpts and links. When comment synchronization is enabled, approved website comment text and the comment author display name are also sent. Comment email addresses and IP addresses are not sent.

Telegram delivers channel publications and discussion messages to your webhook. When channel import is enabled, new text publications and media captions are stored as drafts with their channel/message identifiers and dates. Eligible text replies are stored in WordPress with their display names, numeric sender identities, chat/message identifiers and reply relationships. Inform participants before enabling import, including participants of private groups. The comment form informs website visitors that approved comments and their names will also appear in Telegram.

Telegram service terms: https://telegram.org/tos
Telegram privacy policy: https://telegram.org/privacy
Bot API documentation: https://core.telegram.org/bots/api

ThreadTandem is an independent integration and is not affiliated with Telegram or WordPress.org. It does not include analytics or advertising.

== Installation ==

1. Upload the threadtandem-sync-for-telegram directory to wp-content/plugins/ or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate ThreadTandem on the individual site. Network-wide activation is not supported.
3. Open Settings > ThreadTandem. Save a dedicated bot token and a channel ID, @username or public link such as https://t.me/username.
4. Add the bot as a channel administrator with permission to publish. For comments, link a discussion group to the channel and make the bot an administrator of that group too.
5. Enable the features you want, save, and click Check connection. Publishing and comment synchronization are off by default.
6. Register the webhook. Telegram must be able to reach your site through public HTTPS. ThreadTandem refuses to replace a different integration webhook.
7. Publish a test post. Check Queue and diagnostics, or use Process queue now.
8. Reply under the announcement in Telegram, review the imported comment in WordPress, then reply from the site to verify the conversation.

An optional TREDA_BOT_TOKEN constant in wp-config.php takes precedence over the database token. The saved token is never rendered back into the settings form. Protect database backups as you would other site credentials.

WordPress 6.2 or later is required for safely prepared database table identifiers. The comment, comment metadata and ThreadTandem mapping tables must use InnoDB for transactional imports.

== Frequently Asked Questions ==

= Does viewing a post call Telegram? =

No. Visitors read native WordPress comments. Network work runs in the background queue or through explicit administrator actions.

= Why is delivery delayed? =

WP-Cron depends on site visits. ThreadTandem schedules a batch about once a minute and handles up to three jobs per batch. Configure a server cron for wp-cron.php when predictable delivery matters. Rate limits and unresolved parent mappings delay individual jobs.

= What if the send result is unknown? =

A timeout may occur after Telegram accepted a message. ThreadTandem marks this as Result unknown and does not automatically repeat it. Check Telegram before using the administrator retry control. A manual retry can create a duplicate; automatic reconciliation is not provided.

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

Successful incoming jobs have their message payload cleared. Completed and failed incoming job records are removed after seven days. Comment content is native site content; message mappings remain until integration data is removed. The Privacy Policy Guide includes suggested ThreadTandem text. Additional sender metadata participates in email-based WordPress privacy tools when a matching comment email exists. Telegram-only authors have no email supplied by Telegram and must contact the site administrator to verify their identity for access or removal requests.

= What happens when I deactivate or uninstall ThreadTandem? =

Deactivation stops the scheduled worker and preserves data. Disconnect the webhook before removing the plugin. By default, uninstall keeps integration data. Clear Keep integration data when deleting the plugin to remove ThreadTandem options, tables and integration metadata during uninstall. Core posts and comments remain. Telegram copies and other externally stored data are not removed automatically.

== Screenshots ==

1. Connect a bot and channel, choose what to synchronize, and manage moderation and incoming Telegram events.
2. Control Telegram publishing for each post from the WordPress block editor sidebar.
3. Native WordPress comments preserve nested replies. The Telegram author's name and paper-plane icon link to the original message.

== Translations ==

Help translate ThreadTandem at https://translate.wordpress.org/projects/wp-plugins/threadtandem-sync-for-telegram/.
WordPress.org language packs are installed automatically when an approved translation is available for your site language.
English is the source language. Russian translations are included with the plugin and follow the WordPress site or user language, without requiring a separate download.

== Changelog ==

= 1.2.0 =
* Include complete Russian and English PHP and block-editor translations, including fallback for older language packs.
* Add editable article link text for Telegram announcements with a Russian default on Russian sites.
* Apply custom link text when creating or updating the same announcement through the queue.
* Add queued channel-to-WordPress imports as drafts, with safe text formatting and duplicate protection.
* Add publication matching, ignore/restore controls and queued manual delivery on a synchronization page.
* Keep linked website articles authoritative and preserve manually edited drafts.
* Subscribe to channel publication and edit events while preserving existing comment delivery.
* Include a featured image or the first post image in Telegram announcements, with direct uploads for local JPEG and PNG files.
* Add archive sending through the queue, behind a collapsed panel and a separate confirmation screen.
* Rename settings URLs to page=threadtandem and page=threadtandem-sync, with redirects from the previous URLs.

= 1.1.1 =
* Accept public t.me channel links in settings and normalize them to the equivalent @username before validation and saving.
* Keep existing connection verification when the same channel is entered as a link instead of an @username.
* Identify whether a connection error concerns the token, channel or linked discussion group, and show the checked bot username when available.

= 1.1.0 =
* Rename the plugin to ThreadTandem — Post Publishing and Comment Sync for Telegram.
* Use the threadtandem-sync-for-telegram text domain and standard WordPress.org language packs.
* Preserve existing bot settings, publication mappings, comments and queue data when replacing the previous plugin.

= 1.0.4 =
* Link imported comment authors to their original Telegram message, with a small black paper-plane icon.
* Remove the separate source paragraph below imported comments and respect privacy erasure when rendering author links.

= 1.0.3 =
* Keep new publications in the queue when saved connection settings need a fresh permission check.
* Revalidate the connection in the background worker before delivery and report failures in queue diagnostics.

= 1.0.2 =
* Redesign the settings screen with separate connection, synchronization, delivery and diagnostics sections.
* Add responsive layout, clear status indicators, token visibility control and webhook URL copying.
* Keep the save action visible and prevent connection actions from using unsaved changes.

= 1.0.1 =
* Add a native Telegram publishing panel for the WordPress block editor, using the current editor API with a fallback for WordPress 6.2.
* Save the per-post publishing preference through capability-protected REST metadata.
* Queue post exports after WordPress has saved REST metadata, avoiding stale publishing preferences and duplicate scheduling.
* Retain the classic-editor control and exclude editorial notes from public Telegram comments.

= 1.0.0 =
* Initial release: channel publishing, exact discussion mapping, bidirectional text replies, native moderation, durable queue and privacy controls.
