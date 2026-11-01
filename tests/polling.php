<?php
/** Real database regression checks; all Telegram traffic is intercepted in disposable QA. */
require __DIR__ . '/integration.php';
wp_set_current_user( 1 );
$calls = array(); $batch = array(); $hook = 'https://example.test/wp-json/treda/v1/webhook'; $failure = null;
$fixture = function ( $pre, $args, $url ) use ( &$calls, &$batch, &$hook, &$failure ) {
	$method = basename( wp_parse_url( $url, PHP_URL_PATH ) );
	$params = json_decode( $args['body'], true ); $calls[] = array( $method, $params );
	if ( $failure && $failure[0] === $method ) {
		return array( 'headers' => array(), 'response' => array( 'code' => $failure[1] ), 'body' => wp_json_encode( array( 'ok' => false, 'error_code' => $failure[1], 'parameters' => array( 'retry_after' => 180 ) ) ), 'cookies' => array() );
	}
	if ( $method === 'getWebhookInfo' ) { $result = array( 'url' => $hook ); }
	elseif ( $method === 'deleteWebhook' ) { treda_test_expect( $params['drop_pending_updates'] === false, 'Mode switching preserves pending Telegram events' ); $hook = ''; $result = true; }
	elseif ( $method === 'setWebhook' ) { treda_test_expect( $params['drop_pending_updates'] === false, 'Switching back to webhook preserves pending events' ); $hook = $params['url']; $result = true; }
	elseif ( $method === 'getUpdates' ) { $result = array_values( array_filter( $batch, function ( $update ) use ( $params ) { return $update['update_id'] >= ( $params['offset'] ?? 0 ); } ) ); }
	else { return treda_test_http( $pre, $args, $url ); }
	return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true, 'result' => $result ) ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $fixture, 20, 3 );
delete_option( 'treda_updates_state' );
$settings = Treda_Settings::get(); $settings['enabled'] = 0; $settings['import_enabled'] = 1; $settings['webhook_url'] = $hook;
update_option( 'treda_settings', $settings );
$fingerprint = Treda_Settings::fingerprint();
$settings['updates_mode'] = 'polling'; update_option( 'treda_settings', $settings );
treda_test_expect( $hook === '' && Treda_Updates::state()['enabled'], 'Saving polling mode removes the owned webhook and enables polling' );
treda_test_expect( Treda_Settings::fingerprint() === $fingerprint && Treda_Settings::ready(), 'Transport changes preserve the verified connection and queued entity fingerprints' );
ob_start(); Treda_Admin::render(); $html = ob_get_clean();
$dom = new DOMDocument(); @$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html ); $xpath = new DOMXPath( $dom );
treda_test_expect( $xpath->query( '//select[@id="treda-updates-mode" and @form="treda-settings-form"]/option[@value="polling" and @selected]' )->length === 1 && strpos( $html, 'public HTTPS address is needed' ) === false, 'Polling selector submits with the settings form and does not require an incoming HTTPS endpoint' );
treda_test_expect( (int) get_option( 'treda_import_author' ) === 1, 'Cron imports retain the administrator who enabled receiving' );
treda_test_expect( wp_get_schedule( 'treda_tick' ) === 'treda_minute' && wp_get_schedules()['treda_minute']['interval'] === 60 && has_action( 'treda_tick', array( 'Treda_Updates', 'tick' ) ) === 5, 'Polling runs on the existing minute cron before queue processing' );
treda_test_expect( is_wp_error( Treda_API::register_webhook() ) && $hook === '', 'A stale webhook action cannot register a webhook in polling mode' );
$channel = array( 'message_id' => 78001, 'date' => time(), 'chat' => array( 'id' => -1001111111111, 'type' => 'channel' ), 'text' => 'Cron imported article' );
$root = array( 'message_id' => 88001, 'date' => time(), 'chat' => array( 'id' => -1002222222222, 'type' => 'supergroup' ), 'is_automatic_forward' => true, 'forward_origin' => array( 'type' => 'channel', 'chat' => $channel['chat'], 'message_id' => 78001 ), 'text' => $channel['text'] );
$reply = treda_test_message( 88002, 2000, 'Cron imported comment on an existing website article' );
$batch = array( array( 'update_id' => 78001, 'channel_post' => $channel ), array( 'update_id' => 78002, 'message' => $root ), array( 'update_id' => 78003, 'message' => $reply ) );
treda_test_expect( Treda_Updates::poll() === true && Treda_Updates::state()['offset'] === 78004, 'Polling persists all three receipts and the next offset' );
$polls = array_values( array_filter( $calls, function ( $call ) { return $call[0] === 'getUpdates'; } ) );
treda_test_expect( end( $polls )[1]['offset'] === 0 && end( $polls )[1]['timeout'] === 10 && end( $polls )[1]['limit'] === 100 && count( end( $polls )[1]['allowed_updates'] ) === 4, 'Cron requests a bounded long poll with channel, comment and edit subscriptions' );
treda_test_expect( treda_test_job( 'update:9988:78001' ) && ! Treda_Messages::find( '-1001111111111', 78001 ), 'Polling acknowledges durable receipts rather than creating posts in the HTTP callback' );
treda_test_run();
$row = Treda_Messages::find( '-1001111111111', 78001 );
$comment = Treda_Messages::find( '-1002222222222', 88002 );
treda_test_expect( $row && get_post( $row->post_id )->post_status === 'draft' && (int) get_post( $row->post_id )->post_author === 1, 'Polled channel post becomes an administrator-owned WordPress draft' );
treda_test_expect( $comment && (int) get_comment( $comment->comment_id )->comment_post_ID === (int) $post_id, 'Polled discussion comment is imported under the exact published article' );
$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Treda_Queue::table() );
treda_test_expect( Treda_Updates::poll() === true && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Treda_Queue::table() ) === $count, 'Next poll acknowledges saved events without creating duplicates' );
$polls = array_values( array_filter( $calls, function ( $call ) { return $call[0] === 'getUpdates'; } ) );
treda_test_expect( end( $polls )[1]['offset'] === 78004 && Treda_Updates::state()['last_success'] > 0, 'Next request confirms only previously stored IDs and records success' );
$batch = array( array( 'update_id' => 78004, 'edited_channel_post' => array_merge( $channel, array( 'text' => 'Updated by polling', 'edit_date' => time() + 20 ) ) ) );
Treda_Updates::poll(); treda_test_run();
treda_test_expect( get_post( $row->post_id )->post_title === 'Updated by polling', 'Polled edits update the existing untouched draft' );

// A failed receipt must not advance past the failed event; accepted prefixes remain durable.
$batch = array( array( 'update_id' => 80001, 'channel_post' => array_merge( $channel, array( 'message_id' => 80001 ) ) ), array( 'update_id' => 80002, 'channel_post' => array_merge( $channel, array( 'message_id' => 80002 ) ) ) );
$break_receipt = function ( $sql ) { return strpos( $sql, 'INSERT INTO' ) !== false && strpos( $sql, 'update:9988:80002' ) !== false ? 'INVALID QA RECEIPT QUERY' : $sql; };
$suppress = $wpdb->suppress_errors( true ); add_filter( 'query', $break_receipt );
$failed = Treda_Updates::poll(); remove_filter( 'query', $break_receipt ); $wpdb->suppress_errors( $suppress );
treda_test_expect( is_wp_error( $failed ) && Treda_Updates::state()['offset'] === 80002 && treda_test_job( 'update:9988:80001' ) && ! treda_test_job( 'update:9988:80002' ), 'Storage failure preserves the accepted prefix without acknowledging the failed event' );
treda_test_expect( Treda_Updates::poll() === true && Treda_Updates::state()['offset'] === 80003 && treda_test_job( 'update:9988:80002' ), 'A retry retrieves the missing suffix without losing events' );
$hook = 'https://other.example/webhook'; $before = count( $calls );
treda_test_expect( is_wp_error( Treda_Updates::poll() ) && $hook === 'https://other.example/webhook' && count( $calls ) === $before + 1, 'A foreign webhook is never deleted or polled around' );
$hook = ''; $failure = array( 'getUpdates', 409 ); $offset = Treda_Updates::state()['offset'];
treda_test_expect( is_wp_error( Treda_Updates::poll() ) && Treda_Updates::state()['error'] === __( 'Another getUpdates request or webhook is using this bot. Use a dedicated bot and stop the other receiver.', 'threadtandem-sync-for-telegram' ) && Treda_Updates::state()['offset'] === $offset, 'Conflicting pollers are visible and do not advance the cursor' );
$failure = array( 'getUpdates', 429 ); Treda_Updates::poll(); $before = count( $calls ); Treda_Updates::tick();
treda_test_expect( Treda_Updates::state()['retry_at'] >= time() + 170 && count( $calls ) === $before, 'Cron respects Telegram retry_after without busy retrying' );
$failure = null;
Treda_Updates::disconnect(); $before = count( $calls ); Treda_Updates::tick();
treda_test_expect( ! Treda_Updates::state()['enabled'] && count( $calls ) === $before, 'Disconnect pauses cron polling' );
Treda_Updates::connect();
$state = get_option( 'treda_updates_state' ); $state['last_update'] = time() - 8 * DAY_IN_SECONDS; update_option( 'treda_updates_state', $state, false );
$batch = array( array( 'update_id' => 1234, 'channel_post' => array_merge( $channel, array( 'message_id' => 80003 ) ) ) );
treda_test_expect( Treda_Updates::poll() === true && Treda_Updates::state()['offset'] === 1235, 'A new lower update ID after a week of inactivity is not discarded' );
$batch = array( array( 'update_id' => 90001 ), array( 'update_id' => 90000 ) );
treda_test_expect( is_wp_error( Treda_Updates::poll() ) && Treda_Updates::state()['offset'] === 1235, 'Malformed out-of-order batches cannot advance the cursor' );
$batch = array( array( 'update_id' => 90002, 'message' => array( 'message_id' => 55, 'date' => time(), 'chat' => array( 'id' => -1009999999999 ), 'text' => 'Unrelated' ) ) );
treda_test_expect( Treda_Updates::poll() === true && Treda_Updates::state()['offset'] === 90003 && ! treda_test_job( 'update:9988:90002' ), 'Intentionally ignored unrelated updates can be acknowledged without importing them' );
$batch = array( array( 'update_id' => 91000, 'channel_post' => array_merge( $channel, array( 'message_id' => 91000 ) ) ) );
$break_cursor = function ( $new, $old ) { return ( $new['offset'] ?? 0 ) === 91001 ? $old : $new; };
add_filter( 'pre_update_option_treda_updates_state', $break_cursor, 10, 2 );
$failed = Treda_Updates::poll(); remove_filter( 'pre_update_option_treda_updates_state', $break_cursor, 10 );
treda_test_expect( is_wp_error( $failed ) && Treda_Updates::state()['offset'] === 90003 && treda_test_job( 'update:9988:91000' ), 'Cursor storage failure leaves the event unconfirmed and its durable receipt intact' );
$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Treda_Queue::table() );
treda_test_expect( Treda_Updates::poll() === true && Treda_Updates::state()['offset'] === 91001 && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Treda_Queue::table() ) === $count, 'Retrying after cursor failure deduplicates the already saved receipt' );

// A separate DB session proves cross-process exclusion, including settings changes.
$locker = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock_name = 'tt_updates_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 );
treda_test_expect( (string) $locker->get_var( $locker->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) === '1', 'Completed polling releases its advisory lock' );
$before = count( $calls ); $busy = Treda_Updates::poll();
treda_test_expect( is_wp_error( $busy ) && $busy->get_error_code() === 'treda_updates_busy' && count( $calls ) === $before, 'A second worker cannot start a concurrent network poll' );
$changed = $settings; $changed['updates_mode'] = 'webhook'; update_option( 'treda_settings', $changed );
treda_test_expect( Treda_Settings::updates_mode() === 'polling' && $hook === '', 'A transport switch cannot race an active poll and silently acknowledge its events' );
$locker->get_var( $locker->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); $locker->close();
update_option( 'treda_settings', $changed );
treda_test_expect( Treda_Settings::updates_mode() === 'webhook' && $hook === $settings['webhook_url'], 'Saving webhook mode restores the owned webhook' );
$before = count( $calls ); Treda_Updates::tick();
treda_test_expect( count( $calls ) === $before, 'Webhook mode makes no background polling requests' );
$invalid = Treda_Settings::sanitize( array_merge( $changed, array( 'updates_mode' => 'bad' ) ) );
treda_test_expect( $invalid['updates_mode'] === 'webhook', 'Unsupported transport values keep the previous mode' );
$new_bot = $changed; $new_bot['token'] = '9989:' . str_repeat( 'x', 32 ); update_option( 'treda_settings', $new_bot );
treda_test_expect( Treda_Updates::state()['offset'] === 0 && ! Treda_Settings::ready(), 'A different bot has no inherited cursor or verified connection' );
update_option( 'treda_settings', $changed );
remove_filter( 'pre_http_request', $fixture, 20 );
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $treda_test_checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
WP_CLI::success( count( $treda_test_checks ) . ' polling and transport checks passed.' );
