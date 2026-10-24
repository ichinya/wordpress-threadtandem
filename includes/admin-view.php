<?php
/** Settings screen. Values are prepared by Treda_Admin::render(). */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1>Treda <small><?php echo esc_html( TREDA_VERSION ); ?></small></h1>
	<p><?php esc_html_e( 'Publish posts to Telegram and keep text replies together across your site and a linked discussion group.', 'treda' ); ?></p>
	<?php settings_errors(); ?>
	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $notice['error'] ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>
	<h2><?php esc_html_e( 'Connection', 'treda' ); ?></h2>
	<?php if ( $connection ) : ?>
		<p><strong><?php echo esc_html( $connection['title'] ); ?></strong> · @<?php echo esc_html( $connection['username'] ); ?></p>
		<p><?php esc_html_e( 'Channel ID:', 'treda' ); ?> <code><?php echo esc_html( $connection['chat_id'] ); ?></code>
			· <?php esc_html_e( 'Discussion group ID:', 'treda' ); ?> <code><?php echo esc_html( $connection['discussion_chat_id'] ?: __( 'Not linked', 'treda' ) ); ?></code></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Save the settings, then check the connection.', 'treda' ); ?></p>
	<?php endif; ?>
	<form method="post" action="options.php">
		<?php settings_fields( 'treda' ); ?>
		<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><label for="treda-token"><?php esc_html_e( 'Bot token', 'treda' ); ?></label></th><td>
				<?php if ( defined( 'TREDA_BOT_TOKEN' ) ) : ?>
					<p><?php esc_html_e( 'Configured on the server with TREDA_BOT_TOKEN.', 'treda' ); ?></p>
				<?php else : ?>
					<input id="treda-token" type="password" name="treda_settings[token]" value="" class="regular-text" autocomplete="new-password" aria-describedby="treda-token-help">
					<p id="treda-token-help" class="description"><?php echo $settings['token'] !== '' ? esc_html__( 'A token is saved. Leave this blank to keep it.', 'treda' ) : esc_html__( 'Create a dedicated bot with @BotFather and paste its token.', 'treda' ); ?></p>
					<label><input type="checkbox" name="treda_settings[clear_token]" value="1"> <?php esc_html_e( 'Remove the saved token', 'treda' ); ?></label>
				<?php endif; ?>
			</td></tr>
			<tr><th scope="row"><label for="treda-chat"><?php esc_html_e( 'Telegram channel', 'treda' ); ?></label></th><td>
				<input id="treda-chat" type="text" name="treda_settings[chat_id]" value="<?php echo esc_attr( $settings['chat_id'] ); ?>" class="regular-text" placeholder="@channel / -100…" aria-describedby="treda-chat-help">
				<p id="treda-chat-help" class="description"><?php esc_html_e( 'Add the bot as a channel administrator with permission to publish. For comments, also add it as an administrator of the linked discussion group.', 'treda' ); ?></p>
			</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Post publishing', 'treda' ); ?></th><td>
				<label><input type="checkbox" name="treda_settings[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?>> <?php esc_html_e( 'Automatically publish public posts', 'treda' ); ?></label>
				<p class="description"><?php esc_html_e( 'Existing posts are queued only when saved again. Drafts, private posts and password-protected posts are excluded.', 'treda' ); ?></p>
			</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Comment synchronization', 'treda' ); ?></th><td>
				<label><input type="checkbox" name="treda_settings[comments_enabled]" value="1" <?php checked( $settings['comments_enabled'], 1 ); ?>> <?php esc_html_e( 'Synchronize text comments and replies in both directions', 'treda' ); ?></label>
				<p class="description"><?php esc_html_e( 'Approved website comments and author names are sent to Telegram. Messages in linked discussions are copied to WordPress. Inform group members before enabling import and update your privacy policy.', 'treda' ); ?></p>
				<p><label><input type="checkbox" name="treda_settings[telegram_moderation]" value="1" <?php checked( $settings['telegram_moderation'], 1 ); ?>> <?php esc_html_e( 'Hold new Telegram comments for moderation', 'treda' ); ?></label></p>
				<p class="description"><?php esc_html_e( 'WordPress spam and moderation rules still apply. Edited Telegram messages are always held for review. Approving an imported comment does not send it back to Telegram.', 'treda' ); ?></p>
				<?php if ( get_option( 'comment_registration' ) ) : ?>
					<p><strong><?php esc_html_e( 'This site requires registered commenters. Telegram-only authors cannot be imported while that setting is enabled.', 'treda' ); ?></strong></p>
				<?php endif; ?>
			</td></tr>
		</tbody></table>
		<details><summary><?php esc_html_e( 'Advanced settings', 'treda' ); ?></summary>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="treda-api"><?php esc_html_e( 'Telegram API server', 'treda' ); ?></label></th><td>
					<input id="treda-api" type="url" name="treda_settings[api_url]" class="large-text" value="<?php echo esc_attr( $settings['api_url'] ); ?>">
					<p class="description"><?php esc_html_e( 'An HTTPS Bot API server or compatible proxy. Used for all requests. WordPress may reject private addresses and nonstandard ports.', 'treda' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="treda-webhook"><?php esc_html_e( 'External webhook URL', 'treda' ); ?></label></th><td>
					<input id="treda-webhook" type="url" name="treda_settings[webhook_url]" class="large-text" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>">
					<p class="description"><?php esc_html_e( 'Optional public HTTPS address forwarding to the REST endpoint. Preserve the request body and secret header. A .local hostname cannot receive Telegram webhooks.', 'treda' ); ?></p>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Uninstall', 'treda' ); ?></th><td>
					<label><input type="checkbox" name="treda_settings[retain_data]" value="1" <?php checked( $settings['retain_data'], 1 ); ?>> <?php esc_html_e( 'Keep integration data when deleting the plugin', 'treda' ); ?></label>
					<p class="description"><?php esc_html_e( 'If cleared, uninstall removes Treda settings, mappings and queue tables. Posts and comments remain. Disconnect the webhook before deleting the plugin.', 'treda' ); ?></p>
				</td></tr>
			</tbody></table>
		</details>
		<?php submit_button( __( 'Save settings', 'treda' ) ); ?>
	</form>
	<?php self::action_form( 'check', __( 'Check connection', 'treda' ) ); ?>
	<?php self::action_form( 'webhook', __( 'Register webhook', 'treda' ) ); ?>
	<?php self::action_form( 'disconnect', __( 'Disconnect webhook', 'treda' ) ); ?>
	<p><?php esc_html_e( 'Webhook endpoint:', 'treda' ); ?> <code><?php echo esc_html( Treda_Settings::webhook_url() ); ?></code></p>
	<p><?php esc_html_e( 'Treda will not replace another integration webhook. Disabling the plugin does not delete messages or disconnect the webhook automatically.', 'treda' ); ?></p>
	<h2><?php esc_html_e( 'Queue and diagnostics', 'treda' ); ?></h2>
	<p><?php esc_html_e( 'WP-Cron processes small batches. Low-traffic sites may need a system cron. An uncertain result requires checking Telegram before retrying.', 'treda' ); ?></p>
	<?php self::action_form( 'run', __( 'Process queue now', 'treda' ) ); ?>
	<table class="widefat striped"><thead><tr>
		<th scope="col"><?php esc_html_e( 'Job', 'treda' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'treda' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Attempts', 'treda' ); ?></th><th scope="col"><?php esc_html_e( 'Message', 'treda' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'treda' ); ?></th>
	</tr></thead><tbody>
	<?php
	$treda_labels = array(
		'pending'   => __( 'Queued', 'treda' ),
		'running'   => __( 'Running', 'treda' ),
		'done'      => __( 'Done', 'treda' ),
		'failed'    => __( 'Failed', 'treda' ),
		'uncertain' => __( 'Result unknown', 'treda' ),
	);
	$treda_jobs   = Treda_Queue::recent();
	foreach ( $treda_jobs as $treda_job ) :
		?>
		<tr><td><?php echo esc_html( $treda_job->job_key ); ?></td><td><?php echo esc_html( $treda_labels[ $treda_job->status ] ?? $treda_job->status ); ?></td>
			<td><?php echo (int) $treda_job->attempts; ?></td><td><?php echo esc_html( $treda_job->last_error ); ?></td><td>
			<?php if ( in_array( $treda_job->status, array( 'failed', 'uncertain' ), true ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'treda_action' ); ?>
					<input type="hidden" name="action" value="treda_action"><input type="hidden" name="operation" value="retry"><input type="hidden" name="job_id" value="<?php echo (int) $treda_job->id; ?>">
					<?php if ( $treda_job->status === 'uncertain' ) : ?>
						<p><label><input type="checkbox" name="acknowledge" value="1" required> <?php esc_html_e( 'I checked Telegram and accept the risk of a duplicate.', 'treda' ); ?></label></p>
					<?php endif; ?>
					<?php submit_button( __( 'Retry', 'treda' ), 'secondary small', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</td></tr>
	<?php endforeach; ?>
	<?php if ( ! $treda_jobs ) : ?>
		<tr><td colspan="5"><?php esc_html_e( 'The queue is empty.', 'treda' ); ?></td></tr>
	<?php endif; ?>
	</tbody></table>
</div>
