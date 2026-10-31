<?php
/** Settings screen; all actions retain WordPress capabilities and nonces. */
defined( 'ABSPATH' ) || exit;
$treda_has_token     = Treda_Settings::token() !== '';
$treda_can_check     = $treda_has_token && $settings['chat_id'] !== '';
$treda_webhook_url   = Treda_Settings::webhook_url();
$treda_webhook_host  = wp_parse_url( $treda_webhook_url, PHP_URL_HOST );
$treda_local_webhook = ! Treda_Settings::valid_https_url( $treda_webhook_url )
	|| in_array( $treda_webhook_host, array( 'localhost', '127.0.0.1', '::1' ), true )
	|| preg_match( '/\.local$/i', (string) $treda_webhook_host );
?>
<div class="wrap treda-settings">
	<header class="treda-header">
		<div><h1>ThreadTandem <span class="treda-version"><?php echo esc_html( TREDA_VERSION ); ?></span></h1>
			<p><?php esc_html_e( 'Publishing and comments in Telegram.', 'threadtandem-sync-for-telegram' ); ?></p></div>
		<a class="treda-header-link" href="#treda-diagnostics"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( 'Queue and diagnostics', 'threadtandem-sync-for-telegram' ); ?></a>
	</header>
	<?php Treda_Admin::navigation( 'settings' ); ?>
	<?php // WordPress options-head.php already renders Settings API notices. ?>
	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $notice['error'] ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>
	<div class="treda-layout">
		<form id="treda-settings-form" method="post" action="options.php" class="treda-main">
			<?php settings_fields( 'treda' ); ?>
			<section class="treda-card" aria-labelledby="treda-connection-heading">
				<div class="treda-card-header"><span class="treda-section-icon dashicons dashicons-admin-links" aria-hidden="true"></span><div>
					<h2 id="treda-connection-heading"><?php esc_html_e( 'Connect Telegram', 'threadtandem-sync-for-telegram' ); ?></h2>
					<p><?php esc_html_e( 'One bot, one channel and its discussion group.', 'threadtandem-sync-for-telegram' ); ?></p>
				</div></div>
				<div class="treda-card-body">
					<div class="treda-field">
						<label for="treda-token"><?php esc_html_e( 'Bot token', 'threadtandem-sync-for-telegram' ); ?>
							<?php
							if ( $treda_has_token ) :
								?>
								<span class="treda-inline-saved"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Saved', 'threadtandem-sync-for-telegram' ); ?></span><?php endif; ?></label>
						<?php if ( defined( 'TREDA_BOT_TOKEN' ) ) : ?>
							<p class="treda-callout"><?php esc_html_e( 'Configured on the server with TREDA_BOT_TOKEN.', 'threadtandem-sync-for-telegram' ); ?></p>
						<?php else : ?>
							<div class="treda-input-group"><input id="treda-token" type="password" name="treda_settings[token]" value="" autocomplete="new-password" placeholder="<?php echo $treda_has_token ? esc_attr__( 'Leave blank to keep the saved token', 'threadtandem-sync-for-telegram' ) : esc_attr__( 'Paste the token from BotFather', 'threadtandem-sync-for-telegram' ); ?>" aria-describedby="treda-token-help">
								<button class="button treda-input-button" type="button" data-treda-token-toggle aria-pressed="false" aria-controls="treda-token"><?php esc_html_e( 'Show', 'threadtandem-sync-for-telegram' ); ?></button></div>
							<p id="treda-token-help" class="treda-help"><?php esc_html_e( 'Create a dedicated bot with @BotFather and paste its token.', 'threadtandem-sync-for-telegram' ); ?></p>
							<?php
							if ( $treda_has_token ) :
								?>
								<label class="treda-checkbox treda-remove-token"><input type="checkbox" name="treda_settings[clear_token]" value="1"> <?php esc_html_e( 'Remove the saved token', 'threadtandem-sync-for-telegram' ); ?></label><?php endif; ?>
						<?php endif; ?>
					</div>
					<div class="treda-field"><label for="treda-chat"><?php esc_html_e( 'Telegram channel', 'threadtandem-sync-for-telegram' ); ?></label>
						<input id="treda-chat" type="text" name="treda_settings[chat_id]" value="<?php echo esc_attr( $settings['chat_id'] ); ?>" placeholder="@channel" aria-describedby="treda-chat-help" spellcheck="false">
						<p id="treda-chat-help" class="treda-help"><?php esc_html_e( 'Use an @username, a t.me link or a channel ID. The linked group is detected automatically.', 'threadtandem-sync-for-telegram' ); ?></p>
					</div>
				</div>
			</section>

			<section class="treda-card" aria-labelledby="treda-sync-heading">
				<div class="treda-card-header"><span class="treda-section-icon dashicons dashicons-update" aria-hidden="true"></span><div>
					<h2 id="treda-sync-heading"><?php esc_html_e( 'What to synchronize', 'threadtandem-sync-for-telegram' ); ?></h2><p><?php esc_html_e( 'Choose how WordPress and Telegram work together.', 'threadtandem-sync-for-telegram' ); ?></p>
				</div></div>
				<div class="treda-option"><div><label id="treda-publish-label" for="treda-publish-enabled" class="treda-option-title"><?php esc_html_e( 'Post publishing', 'threadtandem-sync-for-telegram' ); ?></label>
					<p id="treda-publish-help"><?php esc_html_e( 'Send public posts to your channel and update their announcements when you edit them.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<label class="treda-switch"><input id="treda-publish-enabled" type="checkbox" role="switch" name="treda_settings[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?> aria-labelledby="treda-publish-label" aria-describedby="treda-publish-help"><span aria-hidden="true"></span></label>
				</div>
				<div class="treda-card-body"><div class="treda-field"><label for="treda-publication-format"><?php esc_html_e( 'Publication format', 'threadtandem-sync-for-telegram' ); ?></label>
					<select id="treda-publication-format" name="treda_settings[publication_format]" aria-describedby="treda-publication-format-help treda-publication-format-existing">
						<option value="preview" <?php selected( Treda_Settings::publication_format(), 'preview' ); ?>><?php esc_html_e( 'Link with preview', 'threadtandem-sync-for-telegram' ); ?></option>
						<option value="photo" <?php selected( Treda_Settings::publication_format(), 'photo' ); ?>><?php esc_html_e( 'Photo with description', 'threadtandem-sync-for-telegram' ); ?></option>
					</select>
					<p id="treda-publication-format-help" class="treda-help"><?php esc_html_e( 'Link: Telegram creates a preview of the article. Photo: upload the featured image or first content image with a description and link. Without an image, send a link preview.', 'threadtandem-sync-for-telegram' ); ?></p>
					<p id="treda-publication-format-existing" class="treda-help"><?php esc_html_e( 'Changing this setting does not resend the archive. Use Send to update an announcement. An existing photo is kept when switching to link previews, preserving its comments.', 'threadtandem-sync-for-telegram' ); ?></p>
				</div><div class="treda-field"><label for="treda-article-link-text"><?php esc_html_e( 'Article link text', 'threadtandem-sync-for-telegram' ); ?></label>
					<input id="treda-article-link-text" type="text" name="treda_settings[article_link_text]" value="<?php echo esc_attr( $settings['article_link_text'] ); ?>" maxlength="100" placeholder="<?php echo esc_attr( Treda_Settings::default_article_link_text() ); ?>" aria-describedby="treda-article-link-help">
					<p id="treda-article-link-help" class="treda-help"><?php esc_html_e( 'Text below the Telegram announcement that links to the article. Leave blank for the default in the site language. Maximum 100 characters.', 'threadtandem-sync-for-telegram' ); ?></p>
					<p class="treda-help"><?php esc_html_e( 'Applies to new announcements and queued updates. To update an existing announcement, use Send on the Post synchronization page.', 'threadtandem-sync-for-telegram' ); ?></p>
				</div></div>
				<div class="treda-option"><div><label id="treda-import-label" for="treda-import-enabled" class="treda-option-title"><?php esc_html_e( 'Import channel posts as drafts', 'threadtandem-sync-for-telegram' ); ?></label>
					<p id="treda-import-help"><?php esc_html_e( 'Receive new Telegram posts and edits. Review drafts and link existing articles on the Post synchronization page.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<label class="treda-switch"><input id="treda-import-enabled" type="checkbox" role="switch" name="treda_settings[import_enabled]" value="1" <?php checked( $settings['import_enabled'], 1 ); ?> aria-labelledby="treda-import-label" aria-describedby="treda-import-help"><span aria-hidden="true"></span></label>
				</div>
				<div class="treda-option"><div><label id="treda-comments-label" for="treda-comments-enabled" class="treda-option-title"><?php esc_html_e( 'Comment synchronization', 'threadtandem-sync-for-telegram' ); ?></label>
					<p id="treda-comments-help"><?php esc_html_e( 'Keep text replies in one conversation, whether they are written on the website or in Telegram.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<label class="treda-switch"><input id="treda-comments-enabled" type="checkbox" role="switch" name="treda_settings[comments_enabled]" value="1" <?php checked( $settings['comments_enabled'], 1 ); ?> aria-labelledby="treda-comments-label" aria-describedby="treda-comments-help"><span aria-hidden="true"></span></label>
				</div>
				<div class="treda-moderation"><label class="treda-checkbox"><input type="checkbox" name="treda_settings[telegram_moderation]" value="1" <?php checked( $settings['telegram_moderation'], 1 ); ?>> <strong><?php esc_html_e( 'Hold new Telegram comments for moderation', 'threadtandem-sync-for-telegram' ); ?></strong></label>
					<p class="treda-help"><?php esc_html_e( 'Website comments are sent after approval. Edited Telegram messages return to moderation.', 'threadtandem-sync-for-telegram' ); ?></p>
					<p class="treda-privacy-note"><span class="dashicons dashicons-privacy" aria-hidden="true"></span><?php esc_html_e( 'Inform group members that their messages may also appear on your website.', 'threadtandem-sync-for-telegram' ); ?></p>
					<?php
					if ( get_option( 'comment_registration' ) ) :
						?>
						<p class="treda-callout treda-callout-warning"><?php esc_html_e( 'This site requires registered commenters. Telegram-only authors cannot be imported while that setting is enabled.', 'threadtandem-sync-for-telegram' ); ?></p><?php endif; ?>
				</div>
			</section>

			<details class="treda-card treda-disclosure"><summary><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><span><?php esc_html_e( 'Advanced settings', 'threadtandem-sync-for-telegram' ); ?><small><?php esc_html_e( 'API server, external address and uninstall options', 'threadtandem-sync-for-telegram' ); ?></small></span><span class="dashicons dashicons-arrow-down-alt2 treda-chevron" aria-hidden="true"></span></summary>
				<div class="treda-card-body">
					<div class="treda-field"><label for="treda-api"><?php esc_html_e( 'Telegram API server', 'threadtandem-sync-for-telegram' ); ?></label><input id="treda-api" type="url" name="treda_settings[api_url]" value="<?php echo esc_attr( $settings['api_url'] ); ?>" aria-describedby="treda-api-help"><p id="treda-api-help" class="treda-help"><?php esc_html_e( 'An HTTPS Bot API server or compatible proxy. Used for all requests. WordPress may reject private addresses and nonstandard ports.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<div class="treda-field"><label for="treda-webhook"><?php esc_html_e( 'External webhook URL', 'threadtandem-sync-for-telegram' ); ?></label><input id="treda-webhook" type="url" name="treda_settings[webhook_url]" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>" placeholder="https://" aria-describedby="treda-webhook-help"><p id="treda-webhook-help" class="treda-help"><?php esc_html_e( 'Optional public HTTPS address forwarding to the REST endpoint. Preserve the request body and secret header. A .local hostname cannot receive Telegram webhooks.', 'threadtandem-sync-for-telegram' ); ?></p></div>
					<div class="treda-field"><label class="treda-checkbox"><input type="checkbox" name="treda_settings[retain_data]" value="1" <?php checked( $settings['retain_data'], 1 ); ?>> <strong><?php esc_html_e( 'Keep integration data when deleting the plugin', 'threadtandem-sync-for-telegram' ); ?></strong></label><p class="treda-help"><?php esc_html_e( 'If cleared, uninstall removes ThreadTandem settings, mappings and queue tables. Posts and comments remain. Disconnect the webhook before deleting the plugin.', 'threadtandem-sync-for-telegram' ); ?></p></div>
				</div>
			</details>
		</form>

		<aside class="treda-sidebar" aria-label="<?php esc_attr_e( 'Connection status', 'threadtandem-sync-for-telegram' ); ?>">
			<section class="treda-card treda-status-card">
				<div class="treda-sidebar-heading"><h2><?php esc_html_e( 'Connection status', 'threadtandem-sync-for-telegram' ); ?></h2><span class="treda-badge <?php echo $connection ? 'treda-badge-success' : 'treda-badge-neutral'; ?>"><?php echo $connection ? esc_html__( 'Verified', 'threadtandem-sync-for-telegram' ) : esc_html__( 'Not verified', 'threadtandem-sync-for-telegram' ); ?></span></div>
				<?php if ( $connection ) : ?>
					<div class="treda-bot-name"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><strong>@<?php echo esc_html( $connection['username'] ); ?></strong></div>
					<dl class="treda-connection-list"><div><dt><?php esc_html_e( 'Telegram channel', 'threadtandem-sync-for-telegram' ); ?></dt><dd><?php echo esc_html( $connection['title'] ); ?><small><?php echo esc_html( $connection['chat_id'] ); ?></small></dd></div><div><dt><?php esc_html_e( 'Discussion group ID:', 'threadtandem-sync-for-telegram' ); ?></dt><dd><code><?php echo esc_html( $connection['discussion_chat_id'] ?: __( 'Not linked', 'threadtandem-sync-for-telegram' ) ); ?></code></dd></div></dl>
					<?php
					if ( ! empty( $connection['checked_at'] ) ) :
						?>
						<p class="treda-help"><?php esc_html_e( 'Last checked:', 'threadtandem-sync-for-telegram' ); ?> <?php echo esc_html( wp_date( 'j M, H:i', $connection['checked_at'] ) ); ?></p><?php endif; ?>
				<?php else : ?>
					<p class="treda-status-intro"><?php esc_html_e( 'Save your bot and channel, then check their permissions.', 'threadtandem-sync-for-telegram' ); ?></p>
				<?php endif; ?>
				<?php self::action_form( 'check', __( 'Check connection', 'threadtandem-sync-for-telegram' ), 'secondary', ! $treda_can_check ); ?>
				<p class="treda-help"><?php esc_html_e( 'Save changes before checking the connection.', 'threadtandem-sync-for-telegram' ); ?></p>
			</section>

			<section class="treda-card treda-delivery-card"><div class="treda-sidebar-heading"><h2><?php esc_html_e( 'Telegram events', 'threadtandem-sync-for-telegram' ); ?></h2><span class="dashicons dashicons-randomize" aria-hidden="true"></span></div>
				<p><?php esc_html_e( 'Enable incoming events so Telegram replies can reach your website.', 'threadtandem-sync-for-telegram' ); ?></p>
				<?php
				if ( $treda_local_webhook ) :
					?>
					<p class="treda-callout treda-callout-warning"><?php esc_html_e( 'A public HTTPS address is needed to receive events. Set it in Advanced settings when testing locally.', 'threadtandem-sync-for-telegram' ); ?></p><?php endif; ?>
				<div class="treda-action-row"><?php self::action_form( 'webhook', __( 'Connect events', 'threadtandem-sync-for-telegram' ), 'secondary', ! $connection || $treda_local_webhook ); ?><?php self::action_form( 'disconnect', __( 'Disconnect', 'threadtandem-sync-for-telegram' ), 'link-delete', ! $connection ); ?></div>
				<?php self::action_form( 'recover', __( 'Receive pending events', 'threadtandem-sync-for-telegram' ), 'secondary', ! $connection || $treda_local_webhook ); ?>
				<p class="treda-help"><?php esc_html_e( 'Use after a delivery outage. Receives up to 100 pending events, then restores the webhook. Telegram retains events for up to 24 hours.', 'threadtandem-sync-for-telegram' ); ?></p>
				<details class="treda-endpoint"><summary><?php esc_html_e( 'Webhook endpoint:', 'threadtandem-sync-for-telegram' ); ?></summary><div class="treda-input-group"><input id="treda-endpoint" type="text" readonly value="<?php echo esc_attr( $treda_webhook_url ); ?>" aria-label="<?php esc_attr_e( 'Webhook endpoint:', 'threadtandem-sync-for-telegram' ); ?>"><button type="button" class="button" data-treda-copy="treda-endpoint"><?php esc_html_e( 'Copy', 'threadtandem-sync-for-telegram' ); ?></button></div><span class="treda-copy-status" role="status" aria-live="polite"></span></details>
			</section>

			<section class="treda-setup-guide"><h2><?php esc_html_e( 'Before connecting', 'threadtandem-sync-for-telegram' ); ?></h2><ol><li><?php esc_html_e( 'Create a dedicated bot in BotFather.', 'threadtandem-sync-for-telegram' ); ?></li><li><?php esc_html_e( 'Make the bot a channel administrator with permission to publish.', 'threadtandem-sync-for-telegram' ); ?></li><li><?php esc_html_e( 'Link a discussion group and add the bot as its administrator.', 'threadtandem-sync-for-telegram' ); ?></li></ol></section>
		</aside>
	</div>

	<details id="treda-diagnostics" class="treda-card treda-disclosure treda-diagnostics"
	<?php
	if ( $history_open ) :
		?>
		open<?php endif; ?>>
		<summary><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><span><?php esc_html_e( 'Queue and diagnostics', 'threadtandem-sync-for-telegram' ); ?><small><?php esc_html_e( 'Delivery history and jobs that need attention', 'threadtandem-sync-for-telegram' ); ?></small></span><span class="treda-badge <?php echo $counts['failed'] || $counts['uncertain'] ? 'treda-badge-warning' : 'treda-badge-neutral'; ?>"><?php echo esc_html( $counts['failed'] || $counts['uncertain'] ? __( 'Needs attention', 'threadtandem-sync-for-telegram' ) : __( 'In progress', 'threadtandem-sync-for-telegram' ) ); ?> <?php echo (int) ( $counts['failed'] + $counts['uncertain'] ?: $counts['pending'] + $counts['running'] ); ?></span><span class="dashicons dashicons-arrow-down-alt2 treda-chevron" aria-hidden="true"></span></summary>
		<div class="treda-diagnostics-body"><div class="treda-diagnostics-toolbar"><div class="treda-counts"><span><?php esc_html_e( 'Queued', 'threadtandem-sync-for-telegram' ); ?> <strong><?php echo (int) $counts['pending']; ?></strong></span><span><?php esc_html_e( 'Failed', 'threadtandem-sync-for-telegram' ); ?> <strong><?php echo (int) $counts['failed']; ?></strong></span><span><?php esc_html_e( 'Result unknown', 'threadtandem-sync-for-telegram' ); ?> <strong><?php echo (int) $counts['uncertain']; ?></strong></span></div><?php self::action_form( 'run', __( 'Process queue now', 'threadtandem-sync-for-telegram' ), 'secondary', false, $view ); ?></div>
			<nav class="treda-diagnostic-filters" aria-label="<?php esc_attr_e( 'Delivery status filters', 'threadtandem-sync-for-telegram' ); ?>">
			<?php
			$treda_filters = array(
				'attention' => array( __( 'Needs attention', 'threadtandem-sync-for-telegram' ), $counts['failed'] + $counts['uncertain'] ),
				'failed' => array( __( 'Failed', 'threadtandem-sync-for-telegram' ), $counts['failed'] ),
				'uncertain' => array( __( 'Result unknown', 'threadtandem-sync-for-telegram' ), $counts['uncertain'] ),
				'pending' => array( __( 'Queued', 'threadtandem-sync-for-telegram' ), $counts['pending'] ),
				'running' => array( __( 'Running', 'threadtandem-sync-for-telegram' ), $counts['running'] ),
				'done' => array( __( 'Done', 'threadtandem-sync-for-telegram' ), $counts['done'] ),
				'all' => array( __( 'All jobs', 'threadtandem-sync-for-telegram' ), array_sum( $counts ) ),
			);
			foreach ( $treda_filters as $treda_status => $treda_filter ) :
				$treda_filter_url = add_query_arg( array( 'page' => 'threadtandem', 'queue_status' => $treda_status ), admin_url( 'options-general.php' ) ) . '#treda-diagnostics';
				?>
				<a href="<?php echo esc_url( $treda_filter_url ); ?>"<?php if ( $view['queue_status'] === $treda_status ) { echo ' aria-current="page"'; } ?>><?php echo esc_html( $treda_filter[0] ); ?> <span><?php echo (int) $treda_filter[1]; ?></span></a>
			<?php endforeach; ?>
			</nav>
			<?php if ( ! $jobs ) : ?>
				<div class="treda-empty-state"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><h3><?php echo esc_html( array_sum( $counts ) ? __( 'No jobs match this filter.', 'threadtandem-sync-for-telegram' ) : __( 'The queue is empty.', 'threadtandem-sync-for-telegram' ) ); ?></h3></div>
			<?php else : ?>
				<div class="treda-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Recent delivery jobs', 'threadtandem-sync-for-telegram' ); ?>"><table class="widefat"><thead><tr><th scope="col"><?php esc_html_e( 'Job', 'threadtandem-sync-for-telegram' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'threadtandem-sync-for-telegram' ); ?></th><th scope="col"><?php esc_html_e( 'Attempts', 'threadtandem-sync-for-telegram' ); ?></th><th scope="col"><?php esc_html_e( 'Message', 'threadtandem-sync-for-telegram' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'threadtandem-sync-for-telegram' ); ?></th></tr></thead><tbody>
				<?php
				$treda_labels = array(
					'pending'   => __( 'Queued', 'threadtandem-sync-for-telegram' ),
					'running'   => __( 'Running', 'threadtandem-sync-for-telegram' ),
					'done'      => __( 'Done', 'threadtandem-sync-for-telegram' ),
					'failed'    => __( 'Failed', 'threadtandem-sync-for-telegram' ),
					'uncertain' => __( 'Result unknown', 'threadtandem-sync-for-telegram' ),
				);
				?>
				<?php foreach ( $jobs as $treda_job ) : ?>
					<tr><td><code><?php echo esc_html( $treda_job->job_key ); ?></code></td><td><span class="treda-badge treda-status-<?php echo esc_attr( isset( $treda_labels[ $treda_job->status ] ) ? $treda_job->status : 'pending' ); ?>"><?php echo esc_html( $treda_labels[ $treda_job->status ] ?? $treda_job->status ); ?></span></td><td><?php echo (int) $treda_job->attempts; ?></td><td class="treda-error-message"><?php echo esc_html( $treda_job->last_error ?: '—' ); ?></td><td>
					<?php
					if ( in_array( $treda_job->status, array( 'failed', 'uncertain' ), true ) ) :
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'treda_action' ); ?><input type="hidden" name="action" value="treda_action"><input type="hidden" name="operation" value="retry"><input type="hidden" name="job_id" value="<?php echo (int) $treda_job->id; ?>"><input type="hidden" name="queue_status" value="<?php echo esc_attr( $view['queue_status'] ); ?>"><input type="hidden" name="queue_page" value="<?php echo (int) $view['queue_page']; ?>">
						<?php
						if ( $treda_job->status === 'uncertain' ) :
							?>
						<label class="treda-retry-confirm"><input type="checkbox" name="acknowledge" value="1" required> <?php esc_html_e( 'I checked Telegram and accept the risk of a duplicate.', 'threadtandem-sync-for-telegram' ); ?></label><?php endif; ?><?php submit_button( __( 'Retry', 'threadtandem-sync-for-telegram' ), 'secondary small', 'submit', false ); ?></form><?php endif; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
				<?php if ( $history['pages'] > 1 ) :
					$treda_pagination_base = str_replace( '999999', '%#%', add_query_arg( array( 'page' => 'threadtandem', 'queue_status' => $view['queue_status'], 'queue_page' => 999999 ), admin_url( 'options-general.php' ) ) );
					?>
					<nav class="treda-sync-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'threadtandem-sync-for-telegram' ); ?>"><?php echo wp_kses_post( paginate_links( array( 'base' => $treda_pagination_base, 'total' => $history['pages'], 'current' => $history['page'], 'add_args' => false, 'add_fragment' => '#treda-diagnostics' ) ) ); ?></nav>
				<?php endif; ?>
			<?php endif; ?>
			<p class="treda-help treda-queue-help"><?php esc_html_e( 'WP-Cron processes small batches. Low-traffic sites may need a system cron. An uncertain result requires checking Telegram before retrying.', 'threadtandem-sync-for-telegram' ); ?></p>
		</div>
	</details>
	<footer class="treda-savebar"><div><strong><?php esc_html_e( 'Settings changes', 'threadtandem-sync-for-telegram' ); ?></strong><p data-treda-save-status role="status" aria-live="polite"><?php esc_html_e( 'Changes take effect after you save the settings.', 'threadtandem-sync-for-telegram' ); ?></p></div><button type="submit" form="treda-settings-form" class="button button-primary"><?php esc_html_e( 'Save settings', 'threadtandem-sync-for-telegram' ); ?></button></footer>
</div>
