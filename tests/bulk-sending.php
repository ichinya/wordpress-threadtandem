<?php
/** Only runs in the disposable offline sandbox used by channel-sync.php. */
require __DIR__ . '/channel-sync.php';
add_filter( 'pre_http_request', 'treda_test_http', 10, 3 );
wp_set_current_user( 1 );
$settings = Treda_Settings::get();
$settings['enabled'] = 0;
update_option( 'treda_settings', $settings );
// Isolate this archive from fixtures left by earlier disposable runs.
foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $old_id ) { update_post_meta( $old_id, '_treda_disabled', '1' ); }
$ids = array();
for ( $i = 0; $i < 121; $i++ ) {
	$ids[] = wp_insert_post( array( 'post_title' => 'Bulk QA ' . $i, 'post_content' => 'Offline archive fixture', 'post_status' => 'publish' ) );
}
$excluded = array();
foreach ( array( 'draft', 'private', 'password', '_treda_sync_ignored', '_treda_disabled', '_treda_sync_pending', '_treda_import_detached' ) as $flag ) {
	$id = wp_insert_post( array( 'post_title' => 'Excluded bulk QA ' . $flag, 'post_status' => in_array( $flag, array( 'draft', 'private' ), true ) ? $flag : 'publish', 'post_password' => $flag === 'password' ? 'qa' : '' ) );
	if ( strpos( $flag, '_treda_' ) === 0 ) { update_post_meta( $id, $flag, '1' ); }
	$excluded[] = $id;
}
$settings['enabled'] = 1;
update_option( 'treda_settings', $settings );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Treda_Queue::table() ) );
$before_calls = count( $treda_test_calls );
$preview = Treda_Bulk::prepare();
treda_test_expect( $preview && $preview['total'] === 121 && $preview['through'] === (int) end( $ids ), 'Bulk preview includes all eligible public posts and excludes private, draft, protected, ignored and disabled records' );
treda_test_expect( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Treda_Queue::table() ) ) === 0 && count( $treda_test_calls ) === $before_calls, 'Opening confirmation neither queues nor sends any post' );
ob_start(); Treda_Bulk::launcher(); $launcher = ob_get_clean();
$bulk_dom = new DOMDocument(); @$bulk_dom->loadHTML( $launcher ); $bulk_xpath = new DOMXPath( $bulk_dom );
treda_test_expect( $bulk_xpath->query( '//details[not(@open)]//input[@name="operation" and @value="preview"]' )->length === 1 && $bulk_xpath->query( '//input[@value="confirm"]' )->length === 0, 'Bulk sending is collapsed by default and its first button only opens the preview' );
$_GET['bulk'] = $preview['request'];
ob_start(); $shown = Treda_Bulk::confirmation(); $confirmation = ob_get_clean(); unset( $_GET['bulk'] );
treda_test_expect( $shown && strpos( $confirmation, 'QA channel' ) !== false && strpos( $confirmation, '121' ) !== false && strpos( $confirmation, 'value="confirm"' ) !== false && strpos( $confirmation, 'name="_wpnonce"' ) !== false, 'Separate confirmation shows destination and count, with a nonce-protected final action' );
treda_test_expect( ! Treda_Bulk::confirm( 'wrong-confirmation' ), 'An unrecognized confirmation cannot start bulk sending' );
$saved_connection = get_option( 'treda_connection' );
$settings['chat_id'] = '@different_qa'; update_option( 'treda_settings', $settings );
treda_test_expect( ! Treda_Bulk::confirm( $preview['request'] ), 'Changing the channel invalidates an earlier confirmation' );
$settings['chat_id'] = '@treda_qa'; update_option( 'treda_settings', $settings ); update_option( 'treda_connection', $saved_connection );
$settings['enabled'] = 0; update_option( 'treda_settings', $settings );
treda_test_expect( ! Treda_Bulk::confirm( $preview['request'] ), 'Disabling publication prevents confirmation from queuing work' );
$settings['enabled'] = 1; update_option( 'treda_settings', $settings );
wp_set_current_user( 0 );
treda_test_expect( ! Treda_Bulk::prepare() && ! Treda_Bulk::confirm( $preview['request'] ), 'Anonymous callers cannot preview or confirm bulk sending' );
wp_set_current_user( 1 );
$late_id = wp_insert_post( array( 'post_title' => 'Published after bulk preview', 'post_status' => 'publish' ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Treda_Queue::table() ) );
treda_test_expect( Treda_Bulk::confirm( $preview['request'] ), 'Explicit confirmation creates a durable bulk preparation job' );
treda_test_expect( ! Treda_Bulk::confirm( $preview['request'] ), 'A confirmation is consumed once' );
$job = treda_test_job( 'bulk:' . $preview['request'] );
treda_test_expect( $job && $job->kind === 'bulk' && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Treda_Queue::table() ) ) === 1, 'Confirmation queues only the controller, not the whole archive in the request' );
treda_test_expect( count( $treda_test_calls ) === $before_calls, 'Confirming performs no Telegram request' );
Treda_Queue::bulk( $preview );
treda_test_expect( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Treda_Queue::table() ) ) === 1, 'Repeated admission of the same confirmation does not duplicate its job' );
// Admission and cursor movement roll back together if an individual enqueue fails.
$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='running',lock_token='qa-bulk-rollback',locked_at=UTC_TIMESTAMP() WHERE id=%d", Treda_Queue::table(), $job->id ) );
$inserts = 0;
$fail_insert = function ( $sql ) use ( &$inserts ) {
	if ( strpos( $sql, "VALUES ('post:" ) !== false && ++$inserts === 2 ) { return 'SELECT * FROM treda_missing_bulk_qa_table'; }
	return $sql;
};
add_filter( 'query', $fail_insert );
$was_suppressed = $wpdb->suppress_errors( true );
$failure = Treda_Bulk::process( $job, 'qa-bulk-rollback', json_decode( $job->payload, true ) );
$wpdb->suppress_errors( $was_suppressed ); remove_filter( 'query', $fail_insert );
$rolled_back = treda_test_job( 'bulk:' . $preview['request'] );
treda_test_expect( is_wp_error( $failure ) && $failure->get_error_data()['state'] === 'pending' && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE kind='post'", Treda_Queue::table() ) ) === 0 && json_decode( $rolled_back->payload, true )['after'] === 0, 'A failed batch rolls back admitted posts and cursor, ready for a safe retry' );
// A stale local controller resumes, while outgoing network jobs retain uncertainty semantics.
$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='running',lock_token='expired',locked_at=%s,attempts=6 WHERE id=%d", Treda_Queue::table(), gmdate( 'Y-m-d H:i:s', time() - 600 ), $job->id ) );
treda_test_run();
$first_batch = treda_test_job( 'bulk:' . $preview['request'] );
$cursor = json_decode( $first_batch->payload, true );
treda_test_expect( $first_batch->status === 'pending' && (int) $cursor['after'] === (int) $ids[99] && (int) $first_batch->attempts === 0, 'Expired controller lease resumes with a durable 100-post cursor and resets attempts after progress' );
treda_test_expect( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE kind='post'", Treda_Queue::table() ) ) === 100, 'One preparation batch admits at most 100 post jobs' );
treda_test_expect( json_decode( treda_test_job( 'post:' . $ids[99] )->payload, true )['connection'] === $preview['connection'], 'Every outgoing job retains the connection explicitly confirmed for this bulk operation' );
// Eligibility is checked again while the queue is being prepared.
update_post_meta( $ids[120], '_treda_sync_ignored', '1' );
treda_test_run();
$finished = treda_test_job( 'bulk:' . $preview['request'] );
treda_test_expect( $finished->status === 'done' && $finished->payload === '{}', 'The remaining batch finishes and clears controller payload' );
treda_test_expect( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE kind='post'", Treda_Queue::table() ) ) === 120, 'Queue preparation rechecks eligibility rather than exporting a newly ignored post' );
treda_test_expect( ! treda_test_job( 'post:' . $late_id ) && ! treda_test_job( 'post:' . $ids[120] ), 'Posts published after confirmation preview and subsequently ignored posts are not admitted' );
foreach ( $excluded as $id ) { treda_test_expect( ! treda_test_job( 'post:' . $id ), 'Excluded archive record ' . $id . ' has no bulk job' ); }
$next_preview = Treda_Bulk::prepare();
delete_transient( 'treda_bulk_preview_' . get_current_user_id() );
treda_test_expect( ! Treda_Bulk::confirm( $next_preview['request'] ), 'Expired previews cannot be confirmed' );
remove_filter( 'pre_http_request', 'treda_test_http', 10 );
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $treda_test_checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
WP_CLI::success( count( $treda_test_checks ) . ' regression, synchronization and bulk queue checks passed.' );
