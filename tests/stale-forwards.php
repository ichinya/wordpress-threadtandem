<?php
/** Delayed webhook events must not revive explicitly removed publication mappings. */
require __DIR__ . '/channel-sync.php';
add_filter( 'pre_http_request', 'threadtandem_test_http', 10, 3 );
$settings = ThreadTandem_Settings::get(); $settings['enabled'] = 0; update_option( 'threadtandem_settings', $settings );
$connection = ThreadTandem_Settings::connection();
$id = wp_insert_post( array( 'post_title' => 'Replaced publication QA', 'post_status' => 'publish' ) );
$binding = array( 'bot_id' => $connection['bot_id'], 'chat_id' => $connection['chat_id'], 'message_id' => 78002 );
$discussion = array( 'chat_id' => $connection['discussion_chat_id'], 'message_id' => 79002 );
update_post_meta( $id, '_threadtandem_publication', $binding ); update_post_meta( $id, '_threadtandem_discussion', $discussion );
ThreadTandem_Messages::save( array( 'chat_id' => $connection['chat_id'], 'message_id' => 78001, 'post_id' => $id, 'origin' => 'publication' ) );
$old = ThreadTandem_Messages::find( $connection['chat_id'], 78001 );
$message = array( 'message_id' => 79001, 'date' => time(), 'chat' => array( 'id' => (int) $connection['discussion_chat_id'], 'type' => 'supergroup' ), 'is_automatic_forward' => true, 'text' => 'Delayed old announcement', 'forward_origin' => array( 'type' => 'channel', 'chat' => array( 'id' => (int) $connection['chat_id'], 'type' => 'channel' ), 'message_id' => 78001 ) );
$payload = array( 'connection' => ThreadTandem_Settings::fingerprint(), 'update' => array( 'update_id' => 78001, 'message' => $message ) );
$mismatch = ThreadTandem_Webhook::process( $payload );
threadtandem_test_expect( is_wp_error( $mismatch ) && $mismatch->get_error_code() === 'threadtandem_binding', 'An active mismatched mapping still requires investigation' );
ThreadTandem_Messages::mark_deleted( $old->id );
threadtandem_test_expect( ThreadTandem_Webhook::process( $payload ) === true, 'A delayed forward of a deleted publication is safely acknowledged' );
threadtandem_test_expect( get_post_meta( $id, '_threadtandem_discussion', true ) === $discussion && get_post_meta( $id, '_threadtandem_publication', true ) === $binding, 'The replacement publication and its current discussion remain unchanged' );
threadtandem_test_expect( ! ThreadTandem_Messages::find( $connection['discussion_chat_id'], 79001 ) && ThreadTandem_Messages::find( $connection['chat_id'], 78001 )->state === 'deleted', 'The obsolete discussion root is not recreated or reactivated' );
$response = threadtandem_test_incoming( $payload['update'] );
threadtandem_test_expect( $response->get_status() === 200, 'Authenticated stale forwards still use durable webhook receipt' );
threadtandem_test_run();
threadtandem_test_expect( threadtandem_test_job( 'update:' . $connection['bot_id'] . ':78001' )->status === 'done', 'The queue completes a stale forward instead of recording a misleading binding failure' );
remove_filter( 'pre_http_request', 'threadtandem_test_http', 10 );
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $threadtandem_test_checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
WP_CLI::success( count( $threadtandem_test_checks ) . ' webhook and stale-forward checks passed.' );
