<?php
/** Disposable WordPress/MySQL regression and channel-import integration checks. */
require __DIR__ . '/integration.php';
add_filter( 'pre_http_request', 'treda_test_http', 10, 3 );
wp_set_current_user( 1 );
$settings = Treda_Settings::get();
$settings['import_enabled'] = 1;
$settings['webhook_url'] = 'https://example.test/wp-json/treda/v1/webhook';
update_option( 'treda_settings', $settings );
treda_test_expect( ! is_wp_error( Treda_API::register_webhook() ), 'Webhook registration supports channel events' );
$hooks = treda_test_methods( 'setWebhook' );
treda_test_expect( in_array( 'channel_post', end( $hooks )['params']['allowed_updates'], true ) && in_array( 'edited_channel_post', end( $hooks )['params']['allowed_updates'], true ), 'Webhook subscribes to posts and edits without dropping pending events' );
$message = array( 'message_id' => 70001, 'date' => time(), 'chat' => array( 'id' => -1001111111111, 'type' => 'channel' ), 'text' => "🚀 Hello\nSafe <script>alert(1)</script> & link", 'entities' => array( array( 'type' => 'bold', 'offset' => 3, 'length' => 5 ), array( 'type' => 'text_link', 'offset' => 41, 'length' => 4, 'url' => 'javascript:alert(1)' ) ) );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post'" );
$response = treda_test_incoming( array( 'update_id' => 70001, 'channel_post' => $message ) );
treda_test_expect( $response->get_status() === 200 && treda_test_job( 'update:9988:70001' ), 'Signed channel post is durably queued before acknowledgment' );
treda_test_expect( ! Treda_Messages::find( '-1001111111111', 70001 ), 'Webhook does not create a draft synchronously' );
treda_test_run();
$row = Treda_Messages::find( '-1001111111111', 70001 );
treda_test_expect( $row && $row->origin === 'channel', 'Queue worker persists channel identity' );
$draft_id = (int) $row->post_id;
$draft = get_post( $draft_id );
treda_test_expect( $draft && $draft->post_status === 'draft' && (int) $draft->post_author === 1, 'Channel post becomes an administrator-owned draft' );
treda_test_expect( strpos( $draft->post_content, '<strong>Hello</strong>' ) !== false, 'UTF-16 entity offsets preserve formatting after emoji' );
treda_test_expect( strpos( $draft->post_content, '<script>' ) === false && strpos( $draft->post_content, 'href="javascript:' ) === false, 'Imported text and links cannot inject executable HTML' );
treda_test_expect( strpos( Treda_Sync::content( array( 'text' => 'X', 'entities' => array( array( 'type' => 'text_link', 'offset' => 0, 'length' => 1, 'url' => 'javascript:alert(1)' ) ) ) ), '<a ' ) === false, 'Valid-offset malicious link entity is rejected' );
treda_test_expect( get_post_meta( $draft_id, '_treda_disabled', true ) === '1' && get_post_meta( $draft_id, '_treda_sync_pending', true ) === '1', 'Imported draft awaits a decision and does not echo to Telegram' );
$stored_binding = get_post_meta( $draft_id, '_treda_publication', true );
treda_test_expect( (int) $stored_binding['message_id'] === 70001, 'Draft retains original Telegram message for later publication' );
treda_test_incoming( array( 'update_id' => 70001, 'channel_post' => $message ) );
treda_test_run();
treda_test_expect( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post'" ) === $before + 1, 'Duplicate Telegram event creates exactly one draft' );
$message['edit_date'] = time() + 10;
$message['text'] = 'Telegram edit'; unset( $message['entities'] );
treda_test_incoming( array( 'update_id' => 70002, 'edited_channel_post' => $message ) );
treda_test_run();
treda_test_expect( get_post( $draft_id )->post_title === 'Telegram edit', 'Telegram edit updates the same untouched draft' );
$message['text'] = 'Older version'; $message['edit_date']--;
treda_test_incoming( array( 'update_id' => 70003, 'edited_channel_post' => $message ) );
treda_test_run();
treda_test_expect( get_post( $draft_id )->post_title === 'Telegram edit', 'Older Telegram version cannot overwrite a newer draft' );
wp_update_post( array( 'ID' => $draft_id, 'post_content' => 'Manually edited WordPress content' ) );
$message['text'] = 'Later Telegram edit'; $message['edit_date'] += 20;
treda_test_incoming( array( 'update_id' => 70004, 'edited_channel_post' => $message ) );
treda_test_run();
treda_test_expect( get_post( $draft_id )->post_content === 'Manually edited WordPress content', 'Manual draft edits are preserved' );

$foreign = $message; $foreign['chat']['id'] = -1005555555555;
$response = treda_test_incoming( array( 'update_id' => 70005, 'channel_post' => $foreign ) );
treda_test_expect( ! empty( $response->get_data()['ignored'] ) && ! treda_test_job( 'update:9988:70005' ), 'Posts from another channel are discarded' );
$service = $message; unset( $service['text'] ); $service['new_chat_title'] = 'Service event';
$response = treda_test_incoming( array( 'update_id' => 70006, 'channel_post' => $service ) );
treda_test_expect( ! empty( $response->get_data()['ignored'] ), 'Service-only channel events do not create empty drafts' );

$settings['enabled'] = 0; update_option( 'treda_settings', $settings );
$target_id = wp_insert_post( array( 'post_title' => 'Complete WordPress article', 'post_content' => 'Full original article must remain intact.', 'post_excerpt' => 'Updated announcement', 'post_status' => 'publish' ) );
$subscriber = get_users( array( 'role' => 'subscriber', 'number' => 1 ) );
wp_set_current_user( $subscriber[0]->ID );
treda_test_expect( ! Treda_Sync::bind( $draft_id, $target_id ), 'Subscriber cannot match articles' );
ob_start(); Treda_Sync::render(); treda_test_expect( ob_get_clean() === '', 'Subscriber cannot view synchronization controls' );
wp_set_current_user( 1 );
treda_test_expect( Treda_Sync::bind( $draft_id, $target_id ), 'Administrator can explicitly link an existing article' );
treda_test_expect( get_post( $target_id )->post_content === 'Full original article must remain intact.', 'Linking preserves the complete WordPress article' );
treda_test_expect( get_post( $draft_id )->post_content === 'Manually edited WordPress content' && ! get_post_meta( $draft_id, '_treda_publication', true ), 'Source draft remains available and detached without deleting content' );
$row = Treda_Messages::find( '-1001111111111', 70001 );
treda_test_expect( (int) $row->post_id === (int) $target_id && $row->origin === 'publication', 'Link transfers the exact message identity to the selected article' );
$message['text'] = 'Short Telegram edit must not replace full article'; $message['edit_date']++;
treda_test_incoming( array( 'update_id' => 70007, 'edited_channel_post' => $message ) ); treda_test_run();
treda_test_expect( get_post( $target_id )->post_content === 'Full original article must remain intact.', 'Telegram edits cannot overwrite a linked WordPress article' );
$settings['enabled'] = 1; update_option( 'treda_settings', $settings );
$sends_before = count( treda_test_methods( 'sendMessage' ) );
Treda_Queue::post( $target_id ); treda_test_run();
$edits = treda_test_methods( 'editMessageText' );
treda_test_expect( (int) end( $edits )['params']['message_id'] === 70001 && count( treda_test_methods( 'sendMessage' ) ) === $sends_before, 'Linked WordPress export edits the original Telegram post instead of duplicating it' );

$echo = array( 'message_id' => 70001, 'date' => time(), 'chat' => array( 'id' => -1001111111111, 'type' => 'channel' ), 'text' => 'Bot announcement echo' );
treda_test_incoming( array( 'update_id' => 70008, 'channel_post' => $echo ) ); treda_test_run();
treda_test_expect( (int) Treda_Messages::find( '-1001111111111', 70001 )->post_id === (int) $target_id, 'Own outgoing channel event does not create a second article' );
$caption = array( 'message_id' => 70002, 'date' => time(), 'chat' => array( 'id' => -1001111111111, 'type' => 'channel' ), 'caption' => 'Photo caption imported as text', 'photo' => array( array( 'file_id' => 'qa-only' ) ) );
treda_test_incoming( array( 'update_id' => 70009, 'channel_post' => $caption ) ); treda_test_run();
$caption_row = Treda_Messages::find( '-1001111111111', 70002 );
treda_test_expect( $caption_row && get_post( $caption_row->post_id )->post_status === 'draft', 'Media captions import as drafts without fetching attachments' );
$caption_id = (int) $caption_row->post_id;
update_post_meta( $caption_id, '_treda_sync_ignored', '1' );
$caption['caption'] = 'Ignored edit'; $caption['edit_date'] = time() + 40;
treda_test_incoming( array( 'update_id' => 70010, 'edited_channel_post' => $caption ) ); treda_test_run();
treda_test_expect( get_post( $caption_id )->post_title === 'Photo caption imported as text', 'Ignored imports are not changed by Telegram edits' );
delete_post_meta( $caption_id, '_treda_sync_ignored' );
treda_test_expect( Treda_Sync::bind( $caption_id, $caption_id ), 'An imported draft can be explicitly linked to itself' );
wp_update_post( array( 'ID' => $caption_id, 'post_status' => 'publish' ) ); treda_test_run();
$edits = treda_test_methods( 'editMessageCaption' );
treda_test_expect( (int) end( $edits )['params']['message_id'] === 70002 && count( treda_test_methods( 'sendMessage' ) ) === $sends_before, 'Publishing an imported photo draft updates its original Telegram caption' );
wp_update_post( array( 'ID' => $caption_id, 'post_status' => 'draft' ) );
$caption['caption'] = 'Must not replace previously published website article'; $caption['edit_date']++;
treda_test_incoming( array( 'update_id' => 70011, 'edited_channel_post' => $caption ) ); treda_test_run();
treda_test_expect( get_post( $caption_id )->post_title === 'Photo caption imported as text', 'Previously linked website article stays authoritative after unpublishing' );

require_once ABSPATH . 'wp-admin/includes/template.php';
$pending_message = array( 'message_id' => 70003, 'date' => time(), 'chat' => array( 'id' => -1001111111111, 'type' => 'channel' ), 'text' => 'Unmatched publication for administrator review' );
treda_test_incoming( array( 'update_id' => 70012, 'channel_post' => $pending_message ) ); treda_test_run();
ob_start(); Treda_Sync::render(); $sync_html = ob_get_clean();
treda_test_expect( strpos( $sync_html, 'view=linked' ) !== false && strpos( $sync_html, 'target_id' ) !== false && strpos( $sync_html, 'operation' ) !== false, 'Synchronization page renders matching and queue actions' );
treda_test_expect( strpos( $sync_html, 'page=threadtandem' ) !== false && strpos( $sync_html, 'page=threadtandem-sync' ) !== false && strpos( $sync_html, 'page=treda' ) === false, 'Navigation and synchronization controls use ThreadTandem page URLs' );
$sync_dom = new DOMDocument();
@$sync_dom->loadHTML( '<?xml encoding="utf-8" ?>' . $sync_html );
$sync_xpath = new DOMXPath( $sync_dom );
$bind_buttons = $sync_xpath->query( '//input[@type="submit" and starts-with(@form,"treda-sync-bind-")]' );
treda_test_expect( $bind_buttons->length > 0 && $sync_xpath->query( '//form[@id="' . $bind_buttons->item( 0 )->getAttribute( 'form' ) . '"]//select[@name="target_id"]' )->length === 1, 'Link button submits its own article selector without nested forms' );
treda_test_expect( $sync_xpath->query( '//form[contains(@class,"treda-sync-form")][not(input[@name="_wpnonce"]) or not(input[@name="view" and @value="incoming"])]' )->length === 0, 'Every row action retains its nonce and the active publication list' );
$_GET['view'] = 'linked';
ob_start(); Treda_Sync::render(); $linked_html = ob_get_clean();
unset( $_GET['view'] );
treda_test_expect( strpos( $linked_html, 'aria-current="page"' ) !== false && strpos( $linked_html, 'name="target_id"' ) === false && strpos( $linked_html, 'value="send"' ) !== false, 'Linked tab shows queued update actions separately from unmatched imports' );
treda_test_expect( strpos( $sync_html, Treda_Settings::token() ) === false, 'Synchronization page does not expose the bot token' );
remove_filter( 'pre_http_request', 'treda_test_http', 10 );
$recovery_calls = array();
$recovery_fixture = function ( $pre, $args, $url ) use ( &$recovery_calls ) {
	$method = basename( wp_parse_url( $url, PHP_URL_PATH ) );
	$params = json_decode( $args['body'], true );
	$recovery_calls[] = array( $method, $params );
	if ( $method === 'getUpdates' ) {
		if ( isset( $params['offset'] ) ) {
			treda_test_expect( (bool) treda_test_job( 'update:9988:71000' ), 'Recovery acknowledges only after the database has accepted an update' );
			$result = array();
		} else {
			$result = array( array( 'update_id' => 71000, 'channel_post' => array( 'message_id' => 71000, 'date' => time(), 'chat' => array( 'id' => -1001111111111, 'type' => 'channel' ), 'text' => 'Recovered after an outage' ) ) );
		}
	} else {
		$result = $method === 'getWebhookInfo' ? array( 'url' => '' ) : true;
	}
	return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true, 'result' => $result ) ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $recovery_fixture, 20, 3 );
treda_test_expect( Treda_API::recover_updates() === true, 'Pending-event recovery completes and restores the webhook' );
$last_recovery_call = end( $recovery_calls );
treda_test_expect( $last_recovery_call[0] === 'setWebhook' && $last_recovery_call[1]['drop_pending_updates'] === false, 'Recovery ends with webhook restoration without discarding pending events' );
remove_filter( 'pre_http_request', $recovery_fixture, 20 );
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $treda_test_checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
WP_CLI::success( count( $treda_test_checks ) . ' regression and channel synchronization checks passed.' );
