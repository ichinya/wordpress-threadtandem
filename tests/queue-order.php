<?php
/** Chronological announcements, retries and competing worker claims; offline sandbox only. */
require __DIR__ . '/integration.php';
add_filter( 'pre_http_request', 'treda_test_http', 10, 3 );
wp_set_current_user( 1 );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Treda_Queue::table() ) );
$settings = Treda_Settings::get();
$settings['enabled'] = 0;
update_option( 'treda_settings', $settings );
$ordered = array();
foreach ( array( 'Newest' => '2020-01-01', 'Oldest' => '2010-01-01', 'Middle' => '2015-01-01', 'Same date' => '2015-01-01' ) as $title => $date ) {
	$ordered[ $title ] = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_date' => $date . ' 12:00:00', 'post_date_gmt' => $date . ' 12:00:00' ) );
}
$settings['enabled'] = 1;
update_option( 'treda_settings', $settings );
foreach ( $ordered as $id ) { Treda_Queue::post( $id ); }
$before = count( treda_test_methods( 'sendMessage' ) );
treda_test_run();
treda_test_run();
$sent = array_slice( treda_test_methods( 'sendMessage' ), $before );
$titles = array_map( function ( $call ) { return strtok( $call['params']['text'], "\n" ); }, $sent );
treda_test_expect( $titles === array( 'Oldest', 'Middle', 'Same date', 'Newest' ), 'New announcements follow publication date, even when both post IDs and existing queue IDs disagree; same-date ties use post ID' );

$settings['enabled'] = 0;
update_option( 'treda_settings', $settings );
$old = wp_insert_post( array( 'post_title' => 'Delayed old article', 'post_status' => 'publish', 'post_date' => '2011-01-01 12:00:00', 'post_date_gmt' => '2011-01-01 12:00:00' ) );
$later = wp_insert_post( array( 'post_title' => 'Waiting newer article', 'post_status' => 'publish', 'post_date' => '2021-01-01 12:00:00', 'post_date_gmt' => '2021-01-01 12:00:00' ) );
$settings['enabled'] = 1;
update_option( 'treda_settings', $settings );
Treda_Queue::post( $later );
Treda_Queue::post( $old );
$before = count( treda_test_methods( 'sendMessage' ) );
$treda_test_next_error = array( 'headers' => array(), 'response' => array( 'code' => 429 ), 'body' => wp_json_encode( array( 'ok' => false, 'error_code' => 429, 'parameters' => array( 'retry_after' => 600 ) ) ) );
Treda_Queue::run();
$old_job = treda_test_job( 'post:' . $old );
treda_test_expect( $old_job->status === 'pending' && $old_job->available_at > gmdate( 'Y-m-d H:i:s' ) && count( treda_test_methods( 'sendMessage' ) ) === $before + 1 && (int) treda_test_job( 'post:' . $later )->attempts === 0, 'A rate-limited older article keeps later new announcements waiting rather than allowing overtaking' );
foreach ( array( 'failed', 'uncertain', 'running' ) as $state ) {
	$wpdb->query( $wpdb->prepare( "UPDATE %i SET status=%s,locked_at=UTC_TIMESTAMP(),lock_token='other-worker' WHERE id=%d", Treda_Queue::table(), $state, $old_job->id ) );
	Treda_Queue::run();
	treda_test_expect( count( treda_test_methods( 'sendMessage' ) ) === $before + 1 && (int) treda_test_job( 'post:' . $later )->attempts === 0, 'An earlier ' . $state . ' send prevents another worker from claiming a later announcement' );
}

// Ordering applies to new channel messages; it must not stop edits or incoming receipts.
wp_update_post( array( 'ID' => $ordered['Newest'], 'post_excerpt' => 'Edit while the archive is paused' ) );
Treda_Queue::event( array( 'update_id' => 987654321, 'channel_post' => array( 'message_id' => 123456, 'date' => time(), 'chat' => array( 'id' => -1001111111111, 'type' => 'channel' ), 'new_chat_title' => 'Service event' ) ) );
$edits_before = count( treda_test_methods( 'editMessageText' ) );
Treda_Queue::run();
treda_test_expect( count( treda_test_methods( 'editMessageText' ) ) === $edits_before + 1 && treda_test_job( 'update:9988:987654321' )->status === 'done', 'A waiting archive still permits edits of existing messages and incoming Telegram events' );

$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='pending',attempts=0,available_at=UTC_TIMESTAMP(),lock_token='',locked_at=NULL WHERE id=%d", Treda_Queue::table(), $old_job->id ) );
$before = count( treda_test_methods( 'sendMessage' ) );
Treda_Queue::run();
$sent = array_slice( treda_test_methods( 'sendMessage' ), $before );
$titles = array_map( function ( $call ) { return strtok( $call['params']['text'], "\n" ); }, $sent );
treda_test_expect( $titles === array( 'Delayed old article', 'Waiting newer article' ) && treda_test_job( 'post:' . $old )->status === 'done' && treda_test_job( 'post:' . $later )->status === 'done', 'Resolving the earlier send resumes the archive in chronological order without replaying completed articles' );
remove_filter( 'pre_http_request', 'treda_test_http', 10 );
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $treda_test_checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
WP_CLI::success( count( $treda_test_checks ) . ' regression and chronological queue checks passed.' );
