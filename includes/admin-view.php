<?php
/** Settings screen; all actions retain WordPress capabilities and nonces. */
defined( 'ABSPATH' ) || exit;
$threadtandem_has_token     = ThreadTandem_Settings::token() !== '';
$threadtandem_can_check     = $threadtandem_has_token && $settings['chat_id'] !== '';
$threadtandem_webhook_url   = ThreadTandem_Settings::webhook_url();
$threadtandem_polling       = ThreadTandem_Settings::updates_mode() === 'polling';
$threadtandem_updates       = ThreadTandem_Updates::state();
$threadtandem_webhook_host  = wp_parse_url( $threadtandem_webhook_url, PHP_URL_HOST );
$threadtandem_local_webhook = ! ThreadTandem_Settings::valid_https_url( $threadtandem_webhook_url )
	|| in_array( $threadtandem_webhook_host, array( 'localhost', '127.0.0.1', '::1' ), true )
	|| preg_match( '/\.local$/i', (string) $threadtandem_webhook_host );
?>
<div class="wrap threadtandem-settings">
	<header class="threadtandem-header">
		<div><h1>ThreadTandem <span class="threadtandem-version"><?php echo esc_html( THREADTANDEM_VERSION ); ?></span></h1>
			<p><?php esc_html_e( 'Publishing and comments in Telegram.', 'threadtandem-sync-for-telegram' ); ?></p></div>
		<a class="threadtandem-header-link" href="#threadtandem-diagnostics"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( 'Queue and diagnostics', 'threadtandem-sync-for-telegram' ); ?></a>
	</header>
	<?php ThreadTandem_Admin::navigation( 'settings' ); ?>
	<?php // WordPress options-head.php already renders Settings API notices. ?>
	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $notice['error'] ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>
	<div class="threadtandem-layout">
		<form id="threadtandem-settings-form" method="post" action="options.php" class="threadtandem-main">
			<?php settings_fields( 'threadtandem' ); ?>
			<section class="threadtandem-card" aria-labelledby="threadtandem-connection-heading">
				<div class="threadtandem-card-header"><span class="threadtandem-section-icon dashicons dashicons-admin-links" aria-hidden="true"></span><div>
					<h2 id="threadtandem-connection-heading"><?php esc_html_e( 'Connect Telegram', 'threadtandem-sync-for-telegram' ); ?></h2>
					<p><?php esc_html_e( 'One bot, one channel and its discussion group.', 'threadtandem-sync-for-telegram' ); ?></p>
				</div></div>
				<div class="threadtandem-card-body">
					<div class="threadtandem-field">
						<label for="threadtandem-token"><?php esc_html_e( 'Bot token', 'threadtandem-sync-for-telegram' ); ?>
							<?php
							if ( $threadtandem_has_token ) :
								?>
								<span class="threadtandem-inline-saved"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Saved', 'threadtandem-sync-for-telegram' ); ?></span><?php endif; ?></label>
						<?php if ( defined( 'THREADTANDEM_BOT_TOKEN' ) ) : ?>
							<p class="threadtandem-callout"><?php esc_html_e( 'Configured on the server with THREADTANDEM_BOT_TOKEN.', 'threadtandem-sync-for-telegram' ); ?></p>
						<?php else : ?>
							<div class="threadtandem-input-group"><input id="threadtandem-token" type="password" name="threadtandem_settings[token]" value="" autocomplete="new-password" placeholder="<?php echo $threadtandem_has_token ? esc_attr__( 'Leave blank to keep the saved token', 'threadtandem-sync-for-telegram' ) : esc_attr__( 'Paste the token from BotFather', 'threadtandem-sync-for-telegram' ); ?>" aria-describedby="threadtandem-token-help">
								<button class="button threadtandem-input-button" type="button" data-threadtandem-token-toggle aria-pressed="false" aria-controls="threadtandem-token"><?php esc_html_e( 'Show', 'threadtandem-sync-for-telegram' ); ?></button></div>
							<p id="threadtandem-token-help" class="threadtandem-help"><?php esc_html_e( 'Create a dedicated bot with @BotFather and paste its token.', 'threadtandem-sync-for-telegram' ); ?></p>
							<?php
							if ( $threadtandem_has_token ) :
								?>
								<label class="threadtandem-checkbox threadtandem-remove-token"><input type="checkbox" name="threadtandem_settings[clear_token]" value="1"> <?php esc_html_e( 'Remove the saved token', 'threadtandem-sync-for-telegram' ); ?></label><?php endif; ?>
						<?php endif; ?>
					</div>
					<div class="threadtandem-field"><label for="threadtandem-chat"><?php esc_html_e( 'Telegram channel', 'threadtandem-sync-for-telegram' ); ?></label>
						<input id="threadtandem-chat" type="text" name="threadtandem_settings[chat_id]" value="<?php echo esc_attr( $settings['chat_id'] ); ?>" placeholder="@channel" aria-describedby="threadtandem-chat-help" spellcheck="false">
						<p id="threadtandem-chat-help" class="threadtandem-help"><?php esc_html_e( 'Use an @username, a t.me link or a channel ID. The linked group is detected automatically.', 'threadtandem-sync-for-telegram' ); ?></p>
					</div>
				</div>
			</section>

			<section class="threadtandem-card" aria-labelledby="threadtandem-sync-heading">
				<div class="threadtandem-card-header"><span class="threadtandem-section-icon dashicons dashicons-update" aria-hidden="true"></span><div>
					<h2 id="threadtandem-sync-heading"><?php esc_html_e( 'What to synchronize', 'threadtandem-sync-for-telegram' ); ?></h2><p><?php esc_html_e( 'Choose how WordPress and Telegram work together.', 'threadtandem-sync-for-telegram' ); ?></p>
				</div></div>
				<div class="threadtandem-option"><div><label id="threadtandem-publish-label" for="threadtandem-publish-enabled" class="threadtandem-option-title"><?php esc_html_e( 'Post publishing', 'threadtandem-sync-for-telegram' ); ?></label>
					<p id="threadtandem-publish-help"><?php esc_html_e( 'Send public posts to your channel and update their announcements when you edit them.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<label class="threadtandem-switch"><input id="threadtandem-publish-enabled" type="checkbox" role="switch" name="threadtandem_settings[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?> aria-labelledby="threadtandem-publish-label" aria-describedby="threadtandem-publish-help"><span aria-hidden="true"></span></label>
				</div>
				<div class="threadtandem-card-body"><div class="threadtandem-field"><label for="threadtandem-publication-format"><?php esc_html_e( 'Publication format', 'threadtandem-sync-for-telegram' ); ?></label>
					<select id="threadtandem-publication-format" name="threadtandem_settings[publication_format]" aria-describedby="threadtandem-publication-format-help threadtandem-publication-format-existing">
						<option value="preview" <?php selected( ThreadTandem_Settings::publication_format(), 'preview' ); ?>><?php esc_html_e( 'Link with preview', 'threadtandem-sync-for-telegram' ); ?></option>
						<option value="photo" <?php selected( ThreadTandem_Settings::publication_format(), 'photo' ); ?>><?php esc_html_e( 'Photo with description', 'threadtandem-sync-for-telegram' ); ?></option>
					</select>
					<p id="threadtandem-publication-format-help" class="threadtandem-help"><?php esc_html_e( 'Link: Telegram creates a preview of the article. Photo: upload the featured image or first content image with a description and link. Without an image, send a link preview.', 'threadtandem-sync-for-telegram' ); ?></p>
					<p id="threadtandem-publication-format-existing" class="threadtandem-help"><?php esc_html_e( 'Changing this setting does not resend the archive. Use Send to update an announcement. An existing photo is kept when switching to link previews, preserving its comments.', 'threadtandem-sync-for-telegram' ); ?></p>
				</div><div class="threadtandem-field"><label for="threadtandem-article-link-text"><?php esc_html_e( 'Article link text', 'threadtandem-sync-for-telegram' ); ?></label>
					<input id="threadtandem-article-link-text" type="text" name="threadtandem_settings[article_link_text]" value="<?php echo esc_attr( $settings['article_link_text'] ); ?>" maxlength="100" placeholder="<?php echo esc_attr( ThreadTandem_Settings::default_article_link_text() ); ?>" aria-describedby="threadtandem-article-link-help">
					<p id="threadtandem-article-link-help" class="threadtandem-help"><?php esc_html_e( 'Text below the Telegram announcement that links to the article. Leave blank for the default in the site language. Maximum 100 characters.', 'threadtandem-sync-for-telegram' ); ?></p>
					<p class="threadtandem-help"><?php esc_html_e( 'Applies to new announcements and queued updates. To update an existing announcement, use Send on the Post synchronization page.', 'threadtandem-sync-for-telegram' ); ?></p>
				</div></div>
				<div class="threadtandem-option"><div><label id="threadtandem-import-label" for="threadtandem-import-enabled" class="threadtandem-option-title"><?php esc_html_e( 'Import channel posts as drafts', 'threadtandem-sync-for-telegram' ); ?></label>
					<p id="threadtandem-import-help"><?php esc_html_e( 'Receive new Telegram posts and edits. Review drafts and link existing articles on the Post synchronization page.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<label class="threadtandem-switch"><input id="threadtandem-import-enabled" type="checkbox" role="switch" name="threadtandem_settings[import_enabled]" value="1" <?php checked( $settings['import_enabled'], 1 ); ?> aria-labelledby="threadtandem-import-label" aria-describedby="threadtandem-import-help"><span aria-hidden="true"></span></label>
				</div>
				<div class="threadtandem-option"><div><label id="threadtandem-comments-label" for="threadtandem-comments-enabled" class="threadtandem-option-title"><?php esc_html_e( 'Comment synchronization', 'threadtandem-sync-for-telegram' ); ?></label>
					<p id="threadtandem-comments-help"><?php esc_html_e( 'Keep text replies in one conversation, whether they are written on the website or in Telegram.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<label class="threadtandem-switch"><input id="threadtandem-comments-enabled" type="checkbox" role="switch" name="threadtandem_settings[comments_enabled]" value="1" <?php checked( $settings['comments_enabled'], 1 ); ?> aria-labelledby="threadtandem-comments-label" aria-describedby="threadtandem-comments-help"><span aria-hidden="true"></span></label>
				</div>
				<div class="threadtandem-moderation"><label class="threadtandem-checkbox"><input type="checkbox" name="threadtandem_settings[telegram_moderation]" value="1" <?php checked( $settings['telegram_moderation'], 1 ); ?>> <strong><?php esc_html_e( 'Hold new Telegram comments for moderation', 'threadtandem-sync-for-telegram' ); ?></strong></label>
					<p class="threadtandem-help"><?php esc_html_e( 'Website comments are sent after approval. Edited Telegram messages return to moderation.', 'threadtandem-sync-for-telegram' ); ?></p>
					<p class="threadtandem-privacy-note"><span class="dashicons dashicons-privacy" aria-hidden="true"></span><?php esc_html_e( 'Inform group members that their messages may also appear on your website.', 'threadtandem-sync-for-telegram' ); ?></p>
					<?php
					if ( get_option( 'comment_registration' ) ) :
						?>
						<p class="threadtandem-callout threadtandem-callout-warning"><?php esc_html_e( 'This site requires registered commenters. Telegram-only authors cannot be imported while that setting is enabled.', 'threadtandem-sync-for-telegram' ); ?></p><?php endif; ?>
				</div>
			</section>

			<details class="threadtandem-card threadtandem-disclosure"><summary><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><span><?php esc_html_e( 'Advanced settings', 'threadtandem-sync-for-telegram' ); ?><small><?php esc_html_e( 'API server, external address and uninstall options', 'threadtandem-sync-for-telegram' ); ?></small></span><span class="dashicons dashicons-arrow-down-alt2 threadtandem-chevron" aria-hidden="true"></span></summary>
				<div class="threadtandem-card-body">
					<div class="threadtandem-field"><label for="threadtandem-api"><?php esc_html_e( 'Telegram API server', 'threadtandem-sync-for-telegram' ); ?></label><input id="threadtandem-api" type="url" name="threadtandem_settings[api_url]" value="<?php echo esc_attr( $settings['api_url'] ); ?>" aria-describedby="threadtandem-api-help"><p id="threadtandem-api-help" class="threadtandem-help"><?php esc_html_e( 'An HTTPS Bot API server or compatible proxy. Used for all requests. WordPress may reject private addresses and nonstandard ports.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<div class="threadtandem-field"><label for="threadtandem-webhook"><?php esc_html_e( 'External webhook URL', 'threadtandem-sync-for-telegram' ); ?></label><input id="threadtandem-webhook" type="url" name="threadtandem_settings[webhook_url]" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>" placeholder="https://" aria-describedby="threadtandem-webhook-help"><p id="threadtandem-webhook-help" class="threadtandem-help"><?php esc_html_e( 'Optional public HTTPS address forwarding to the REST endpoint. Preserve the request body and secret header. A .local hostname cannot receive Telegram webhooks.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<div class="threadtandem-field"><label class="threadtandem-checkbox"><input type="checkbox" name="threadtandem_settings[retain_data]" value="1" <?php checked( $settings['retain_data'], 1 ); ?>> <strong><?php esc_html_e( 'Keep integration data when deleting the plugin', 'threadtandem-sync-for-telegram' ); ?></strong></label><p class="threadtandem-help"><?php esc_html_e( 'If cleared, uninstall removes ThreadTandem settings, mappings and queue tables. Posts and comments remain. Disconnect the webhook before deleting the plugin.', 'threadtandem-sync-for-telegram' ); ?></p></div>
				</div>
			</details>
		</form>

		<aside class="threadtandem-sidebar" aria-label="<?php esc_attr_e( 'Connection status', 'threadtandem-sync-for-telegram' ); ?>">
			<section class="threadtandem-card threadtandem-status-card">
				<div class="threadtandem-sidebar-heading"><h2><?php esc_html_e( 'Connection status', 'threadtandem-sync-for-telegram' ); ?></h2><span class="threadtandem-badge <?php echo $connection ? 'threadtandem-badge-success' : 'threadtandem-badge-neutral'; ?>"><?php echo $connection ? esc_html__( 'Verified', 'threadtandem-sync-for-telegram' ) : esc_html__( 'Not verified', 'threadtandem-sync-for-telegram' ); ?></span></div>
				<?php if ( $connection ) : ?>
					<div class="threadtandem-bot-name"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><strong>@<?php echo esc_html( $connection['username'] ); ?></strong></div>
					<dl class="threadtandem-connection-list"><div><dt><?php esc_html_e( 'Telegram channel', 'threadtandem-sync-for-telegram' ); ?></dt><dd><?php echo esc_html( $connection['title'] ); ?><small><?php echo esc_html( $connection['chat_id'] ); ?></small></dd></div><div><dt><?php esc_html_e( 'Discussion group ID:', 'threadtandem-sync-for-telegram' ); ?></dt><dd><code><?php echo esc_html( $connection['discussion_chat_id'] ?: __( 'Not linked', 'threadtandem-sync-for-telegram' ) ); ?></code></dd></div></dl>
					<?php
					if ( ! empty( $connection['checked_at'] ) ) :
						?>
						<p class="threadtandem-help"><?php esc_html_e( 'Last checked:', 'threadtandem-sync-for-telegram' ); ?> <?php echo esc_html( wp_date( 'j M, H:i', $connection['checked_at'] ) ); ?></p><?php endif; ?>
				<?php else : ?>
					<p class="threadtandem-status-intro"><?php esc_html_e( 'Save your bot and channel, then check their permissions.', 'threadtandem-sync-for-telegram' ); ?></p>
				<?php endif; ?>
				<?php self::action_form( 'check', __( 'Check connection', 'threadtandem-sync-for-telegram' ), 'secondary', ! $threadtandem_can_check ); ?>
				<p class="threadtandem-help"><?php esc_html_e( 'Save changes before checking the connection.', 'threadtandem-sync-for-telegram' ); ?></p>
			</section>

			<section class="threadtandem-card threadtandem-delivery-card"><div class="threadtandem-sidebar-heading"><h2><?php esc_html_e( 'Telegram events', 'threadtandem-sync-for-telegram' ); ?></h2><span class="dashicons dashicons-randomize" aria-hidden="true"></span></div>
				<div class="threadtandem-field"><label for="threadtandem-updates-mode"><?php esc_html_e( 'Update delivery mode', 'threadtandem-sync-for-telegram' ); ?></label>
					<select id="threadtandem-updates-mode" name="threadtandem_settings[updates_mode]" form="threadtandem-settings-form"><option value="webhook" <?php selected( ! $threadtandem_polling ); ?>><?php esc_html_e( 'Webhook', 'threadtandem-sync-for-telegram' ); ?></option><option value="polling" <?php selected( $threadtandem_polling ); ?>><?php esc_html_e( 'Cron polling, every minute', 'threadtandem-sync-for-telegram' ); ?></option></select>
					<p class="threadtandem-help"><?php esc_html_e( 'Save to switch modes. Cron polling removes this site\'s webhook and receives updates without a public incoming HTTPS connection.', 'threadtandem-sync-for-telegram' ); ?></p>
				</div>
				<?php
				if ( ! $threadtandem_polling && $threadtandem_local_webhook ) :
					?>
					<p class="threadtandem-callout threadtandem-callout-warning"><?php esc_html_e( 'A public HTTPS address is needed to receive events. Set it in Advanced settings when testing locally.', 'threadtandem-sync-for-telegram' ); ?></p><?php endif; ?>
				<?php if ( $threadtandem_polling ) : ?>
					<p><strong><?php echo esc_html( $threadtandem_updates['enabled'] ? __( 'Polling enabled', 'threadtandem-sync-for-telegram' ) : __( 'Polling paused', 'threadtandem-sync-for-telegram' ) ); ?></strong></p>
					<p class="threadtandem-help"><?php esc_html_e( 'WP-Cron checks for updates every minute. For reliable delivery, configure a server cron to run wp-cron.php every minute. Telegram retains updates for up to 24 hours.', 'threadtandem-sync-for-telegram' ); ?></p>
					<p><?php esc_html_e( 'Last successful poll:', 'threadtandem-sync-for-telegram' ); ?> <?php echo esc_html( $threadtandem_updates['last_success'] ? wp_date( get_option( 'date_format' ) . ' H:i:s', $threadtandem_updates['last_success'] ) : __( 'Not yet', 'threadtandem-sync-for-telegram' ) ); ?></p>
				<?php endif; ?>
				<?php if ( $threadtandem_updates['error'] ) : ?><p class="threadtandem-callout threadtandem-callout-warning" role="status"><?php echo esc_html( $threadtandem_updates['error'] ); ?></p><?php endif; ?>
				<div class="threadtandem-action-row"><?php self::action_form( 'webhook', $threadtandem_polling ? __( 'Start polling', 'threadtandem-sync-for-telegram' ) : __( 'Connect events', 'threadtandem-sync-for-telegram' ), 'secondary', ! $connection || ( ! $threadtandem_polling && $threadtandem_local_webhook ) ); ?><?php self::action_form( 'disconnect', __( 'Disconnect', 'threadtandem-sync-for-telegram' ), 'link-delete', ! $connection ); ?></div>
				<?php self::action_form( 'recover', $threadtandem_polling ? __( 'Receive updates now', 'threadtandem-sync-for-telegram' ) : __( 'Receive pending events', 'threadtandem-sync-for-telegram' ), 'secondary', ! $connection || ( $threadtandem_polling ? ! $threadtandem_updates['enabled'] : $threadtandem_local_webhook ) ); ?>
				<?php if ( ! $threadtandem_polling ) : ?>
				<p class="threadtandem-help"><?php esc_html_e( 'Use after a delivery outage. Receives up to 100 pending events, then restores the webhook. Telegram retains events for up to 24 hours.', 'threadtandem-sync-for-telegram' ); ?></p>
				<details class="threadtandem-endpoint"><summary><?php esc_html_e( 'Webhook endpoint:', 'threadtandem-sync-for-telegram' ); ?></summary><div class="threadtandem-input-group"><input id="threadtandem-endpoint" type="text" readonly value="<?php echo esc_attr( $threadtandem_webhook_url ); ?>" aria-label="<?php esc_attr_e( 'Webhook endpoint:', 'threadtandem-sync-for-telegram' ); ?>"><button type="button" class="button" data-threadtandem-copy="threadtandem-endpoint"><?php esc_html_e( 'Copy', 'threadtandem-sync-for-telegram' ); ?></button></div><span class="threadtandem-copy-status" role="status" aria-live="polite"></span></details>
				<?php endif; ?>
			</section>

			<section class="threadtandem-setup-guide"><h2><?php esc_html_e( 'Before connecting', 'threadtandem-sync-for-telegram' ); ?></h2><ol><li><?php esc_html_e( 'Create a dedicated bot in BotFather.', 'threadtandem-sync-for-telegram' ); ?></li><li><?php esc_html_e( 'Make the bot a channel administrator with permission to publish.', 'threadtandem-sync-for-telegram' ); ?></li><li><?php esc_html_e( 'Link a discussion group and add the bot as its administrator.', 'threadtandem-sync-for-telegram' ); ?></li></ol></section>
		</aside>
	</div>

	<details id="threadtandem-diagnostics" class="threadtandem-card threadtandem-disclosure threadtandem-diagnostics"
	<?php
	if ( $history_open ) :
		?>
		open<?php endif; ?>>
		<summary><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><span><?php esc_html_e( 'Queue and diagnostics', 'threadtandem-sync-for-telegram' ); ?><small><?php esc_html_e( 'Delivery history and jobs that need attention', 'threadtandem-sync-for-telegram' ); ?></small></span><span class="threadtandem-badge <?php echo $counts['failed'] || $counts['uncertain'] ? 'threadtandem-badge-warning' : 'threadtandem-badge-neutral'; ?>"><?php echo esc_html( $counts['failed'] || $counts['uncertain'] ? __( 'Needs attention', 'threadtandem-sync-for-telegram' ) : __( 'In progress', 'threadtandem-sync-for-telegram' ) ); ?> <?php echo (int) ( $counts['failed'] + $counts['uncertain'] ?: $counts['pending'] + $counts['running'] ); ?></span><span class="dashicons dashicons-arrow-down-alt2 threadtandem-chevron" aria-hidden="true"></span></summary>
		<div class="threadtandem-diagnostics-body"><div class="threadtandem-diagnostics-toolbar"><div class="threadtandem-counts"><span><?php esc_html_e( 'Queued', 'threadtandem-sync-for-telegram' ); ?> <strong><?php echo (int) $counts['pending']; ?></strong></span><span><?php esc_html_e( 'Failed', 'threadtandem-sync-for-telegram' ); ?> <strong><?php echo (int) $counts['failed']; ?></strong></span><span><?php esc_html_e( 'Result unknown', 'threadtandem-sync-for-telegram' ); ?> <strong><?php echo (int) $counts['uncertain']; ?></strong></span></div><?php self::action_form( 'run', __( 'Process queue now', 'threadtandem-sync-for-telegram' ), 'secondary', false, $view ); ?></div>
			<nav class="threadtandem-diagnostic-filters" aria-label="<?php esc_attr_e( 'Delivery status filters', 'threadtandem-sync-for-telegram' ); ?>">
			<?php
			$threadtandem_filters = array(
				'attention' => array( __( 'Needs attention', 'threadtandem-sync-for-telegram' ), $counts['failed'] + $counts['uncertain'] ),
				'failed' => array( __( 'Failed', 'threadtandem-sync-for-telegram' ), $counts['failed'] ),
				'uncertain' => array( __( 'Result unknown', 'threadtandem-sync-for-telegram' ), $counts['uncertain'] ),
				'pending' => array( __( 'Queued', 'threadtandem-sync-for-telegram' ), $counts['pending'] ),
				'running' => array( __( 'Running', 'threadtandem-sync-for-telegram' ), $counts['running'] ),
				'done' => array( __( 'Done', 'threadtandem-sync-for-telegram' ), $counts['done'] ),
				'all' => array( __( 'All jobs', 'threadtandem-sync-for-telegram' ), array_sum( $counts ) ),
			);
			foreach ( $threadtandem_filters as $threadtandem_status => $threadtandem_filter ) :
				$threadtandem_filter_url = add_query_arg( array( 'page' => 'threadtandem', 'queue_status' => $threadtandem_status ), admin_url( 'options-general.php' ) ) . '#threadtandem-diagnostics';
				?>
				<a href="<?php echo esc_url( $threadtandem_filter_url ); ?>"<?php if ( $view['queue_status'] === $threadtandem_status ) { echo ' aria-current="page"'; } ?>><?php echo esc_html( $threadtandem_filter[0] ); ?> <span><?php echo (int) $threadtandem_filter[1]; ?></span></a>
			<?php endforeach; ?>
			</nav>
			<?php if ( ! $jobs ) : ?>
				<div class="threadtandem-empty-state"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><h3><?php echo esc_html( array_sum( $counts ) ? __( 'No jobs match this filter.', 'threadtandem-sync-for-telegram' ) : __( 'The queue is empty.', 'threadtandem-sync-for-telegram' ) ); ?></h3></div>
			<?php else : ?>
				<div class="threadtandem-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Recent delivery jobs', 'threadtandem-sync-for-telegram' ); ?>"><table class="widefat"><thead><tr><th scope="col"><?php esc_html_e( 'Job', 'threadtandem-sync-for-telegram' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'threadtandem-sync-for-telegram' ); ?></th><th scope="col"><?php esc_html_e( 'Attempts', 'threadtandem-sync-for-telegram' ); ?></th><th scope="col"><?php esc_html_e( 'Message', 'threadtandem-sync-for-telegram' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'threadtandem-sync-for-telegram' ); ?></th></tr></thead><tbody>
				<?php
				$threadtandem_labels = array(
					'pending'   => __( 'Queued', 'threadtandem-sync-for-telegram' ),
					'running'   => __( 'Running', 'threadtandem-sync-for-telegram' ),
					'done'      => __( 'Done', 'threadtandem-sync-for-telegram' ),
					'failed'    => __( 'Failed', 'threadtandem-sync-for-telegram' ),
					'uncertain' => __( 'Result unknown', 'threadtandem-sync-for-telegram' ),
				);
				?>
				<?php foreach ( $jobs as $threadtandem_job ) : ?>
					<tr><td><code><?php echo esc_html( $threadtandem_job->job_key ); ?></code></td><td><span class="threadtandem-badge threadtandem-status-<?php echo esc_attr( isset( $threadtandem_labels[ $threadtandem_job->status ] ) ? $threadtandem_job->status : 'pending' ); ?>"><?php echo esc_html( $threadtandem_labels[ $threadtandem_job->status ] ?? $threadtandem_job->status ); ?></span></td><td><?php echo (int) $threadtandem_job->attempts; ?></td><td class="threadtandem-error-message"><?php echo esc_html( $threadtandem_job->last_error ?: '—' ); ?></td><td>
					<?php
					if ( in_array( $threadtandem_job->status, array( 'failed', 'uncertain' ), true ) ) :
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'threadtandem_action' ); ?><input type="hidden" name="action" value="threadtandem_action"><input type="hidden" name="operation" value="retry"><input type="hidden" name="job_id" value="<?php echo (int) $threadtandem_job->id; ?>"><input type="hidden" name="queue_status" value="<?php echo esc_attr( $view['queue_status'] ); ?>"><input type="hidden" name="queue_page" value="<?php echo (int) $view['queue_page']; ?>">
						<?php
						if ( $threadtandem_job->status === 'uncertain' ) :
							?>
						<label class="threadtandem-retry-confirm"><input type="checkbox" name="acknowledge" value="1" required> <?php esc_html_e( 'I checked Telegram and accept the risk of a duplicate.', 'threadtandem-sync-for-telegram' ); ?></label><?php endif; ?><?php submit_button( __( 'Retry', 'threadtandem-sync-for-telegram' ), 'secondary small', 'submit', false ); ?></form><?php endif; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
				<?php if ( $history['pages'] > 1 ) :
					$threadtandem_pagination_base = str_replace( '999999', '%#%', add_query_arg( array( 'page' => 'threadtandem', 'queue_status' => $view['queue_status'], 'queue_page' => 999999 ), admin_url( 'options-general.php' ) ) );
					?>
					<nav class="threadtandem-sync-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'threadtandem-sync-for-telegram' ); ?>"><?php echo wp_kses_post( paginate_links( array( 'base' => $threadtandem_pagination_base, 'total' => $history['pages'], 'current' => $history['page'], 'add_args' => false, 'add_fragment' => '#threadtandem-diagnostics' ) ) ); ?></nav>
				<?php endif; ?>
			<?php endif; ?>
			<p class="threadtandem-help threadtandem-queue-help"><?php esc_html_e( 'WP-Cron processes small batches. Low-traffic sites may need a system cron. An uncertain result requires checking Telegram before retrying.', 'threadtandem-sync-for-telegram' ); ?></p>
		</div>
	</details>
	<footer class="threadtandem-savebar"><div><strong><?php esc_html_e( 'Settings changes', 'threadtandem-sync-for-telegram' ); ?></strong><p data-threadtandem-save-status role="status" aria-live="polite"><?php esc_html_e( 'Changes take effect after you save the settings.', 'threadtandem-sync-for-telegram' ); ?></p></div><button type="submit" form="threadtandem-settings-form" class="button button-primary"><?php esc_html_e( 'Save settings', 'threadtandem-sync-for-telegram' ); ?></button></footer>
</div>
