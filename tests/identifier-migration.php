<?php
/** Upgrade fixtures deliberately use the former name; run only in disposable WordPress QA. */
require __DIR__ . '/integration.php';
wp_set_current_user( 1 );
global $wpdb;
remove_filter( 'pre_update_option_threadtandem_settings', array( 'ThreadTandem_Updates', 'before_settings' ), 10 );
remove_action( 'update_option_threadtandem_settings', array( 'ThreadTandem_Updates', 'settings_changed' ), 10 );
$settings = ThreadTandem_Settings::get();
$settings['updates_mode'] = 'polling'; $settings['enabled'] = 0;
$settings['webhook_url'] = 'https://example.test/wp-json/treda/v1/webhook';
update_option( 'threadtandem_settings', $settings );
$cursor = array( 'scope' => ThreadTandem_Updates::state()['scope'], 'offset' => 456789, 'enabled' => false, 'last_success' => time(), 'last_update' => time() );
update_option( 'threadtandem_updates_state', $cursor, false );
set_transient( 'threadtandem_bulk_preview_1', array( 'connection' => ThreadTandem_Settings::fingerprint(), 'through' => 9999 ), 600 );
$secret = ThreadTandem_Settings::secret(); $fingerprint = ThreadTandem_Settings::fingerprint();
$binding_before = get_post_meta( $post_id, '_threadtandem_publication', true );
$native_before = array( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ) );
$jobs_before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', ThreadTandem_Queue::table() ), ARRAY_A );
$messages_before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', ThreadTandem_Messages::table() ), ARRAY_A );
delete_option( 'threadtandem_identifiers_migrated' ); delete_option( 'threadtandem_webhook_upgrade' );
wp_clear_scheduled_hook( 'threadtandem_tick' );
add_filter( 'cron_schedules', function ( $s ) { $s['treda_minute'] = array( 'interval' => 60, 'display' => 'Old QA schedule' ); return $s; } );
wp_schedule_event( time() + 30, 'treda_minute', 'treda_tick' );
$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $wpdb->prefix . 'threadtandem_jobs', $wpdb->prefix . 'treda_jobs', $wpdb->prefix . 'threadtandem_messages', $wpdb->prefix . 'treda_messages' ) );
foreach ( array( 'threadtandem_' => 'treda_', '_transient_threadtandem_' => '_transient_treda_', '_transient_timeout_threadtandem_' => '_transient_timeout_treda_' ) as $new => $old ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name=CONCAT(%s,SUBSTRING(option_name,%d)) WHERE option_name LIKE %s', $wpdb->options, $old, strlen( $new ) + 1, $wpdb->esc_like( $new ) . '%' ) );
}
foreach ( array( $wpdb->postmeta, $wpdb->commentmeta ) as $table ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_key=CONCAT(%s,SUBSTRING(meta_key,%d)) WHERE meta_key LIKE %s', $table, '_treda_', strlen( '_threadtandem_' ) + 1, $wpdb->esc_like( '_threadtandem_' ) . '%' ) );
}
wp_cache_flush();
// Prime the caches that a real request may already have loaded before upgrading.
get_option( 'treda_settings' ); get_post_meta( $post_id );
$legacy_table = $wpdb->prefix . 'treda_jobs';
$exists = function ( $name ) use ( $wpdb ) { return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name; };

// Refuse active workers and conflicting state before changing any table names.
$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='running',locked_at=%s WHERE id=%d", $legacy_table, gmdate( 'Y-m-d H:i:s' ), $jobs_before[0]['id'] ) );
threadtandem_test_expect( is_wp_error( ThreadTandem_Migration::run() ) && $exists( $legacy_table ), 'Migration waits for an active outgoing worker without renaming its table' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status=%s,locked_at=%s WHERE id=%d', $legacy_table, $jobs_before[0]['status'], $jobs_before[0]['locked_at'], $jobs_before[0]['id'] ) );
// Restore NULL faithfully (the prepared %s above intentionally cannot represent NULL).
if ( $jobs_before[0]['locked_at'] === null ) { $wpdb->query( $wpdb->prepare( 'UPDATE %i SET locked_at=NULL WHERE id=%d', $legacy_table, $jobs_before[0]['id'] ) ); }
update_option( 'threadtandem_settings', array( 'separate_installation' => true ) );
threadtandem_test_expect( is_wp_error( ThreadTandem_Migration::run() ) && $exists( $legacy_table ) && get_option( 'threadtandem_settings' ) === array( 'separate_installation' => true ), 'Conflicting canonical settings are preserved before any DDL' );
delete_option( 'threadtandem_settings' );
$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', ThreadTandem_Queue::table(), $legacy_table ) );
threadtandem_test_expect( is_wp_error( ThreadTandem_Migration::run() ) && $exists( $legacy_table ) && $exists( ThreadTandem_Queue::table() ), 'A canonical table collision never replaces either installation' );
$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', ThreadTandem_Queue::table() ) );
$conflict_id = add_post_meta( $post_id, '_threadtandem_publication', array( 'message_id' => 99999 ) );
threadtandem_test_expect( is_wp_error( ThreadTandem_Migration::run() ) && $exists( $legacy_table ), 'Conflicting publication metadata blocks migration without overwriting either binding' );
delete_metadata_by_mid( 'post', $conflict_id );
$locker = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock_name = 'tt_updates_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 );
$locker->get_var( $locker->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
threadtandem_test_expect( is_wp_error( ThreadTandem_Migration::run() ) && $exists( $legacy_table ), 'Migration cannot race another process polling the old bot connection' );
$locker->get_var( $locker->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); $locker->close();
set_transient( 'doing_cron', microtime( true ), 60 );
threadtandem_test_expect( is_wp_error( ThreadTandem_Migration::run() ) && $exists( $legacy_table ), 'An active cron process is allowed to finish before migration' );
delete_transient( 'doing_cron' );

// Fail after atomic DDL; options and metadata must roll back and be resumable.
$break_meta = function ( $sql ) use ( $wpdb ) { return strpos( $sql, 'UPDATE `' . $wpdb->commentmeta . '` SET meta_key=CONCAT' ) === 0 ? 'INVALID QA MIGRATION QUERY' : $sql; };
$suppressed = $wpdb->suppress_errors( true ); add_filter( 'query', $break_meta );
$failed = ThreadTandem_Migration::run(); remove_filter( 'query', $break_meta ); $wpdb->suppress_errors( $suppressed );
threadtandem_test_expect( is_wp_error( $failed ) && ! $exists( $legacy_table ) && $exists( ThreadTandem_Queue::table() ) && get_option( 'treda_settings' ) && ! get_option( 'threadtandem_settings' ), 'A post-DDL failure rolls back core keys while retaining the renamed job table for safe resumption' );
threadtandem_test_expect( get_post_meta( $post_id, '_treda_publication', true ) === $binding_before && ! get_option( 'threadtandem_identifiers_migrated' ), 'Failed migration does not lose mappings or write a completion marker' );
threadtandem_test_expect( ThreadTandem_Migration::run() === true, 'Interrupted migration resumes successfully on its next attempt' );
ThreadTandem_Queue::hooks();
threadtandem_test_expect( ThreadTandem_Settings::ready() && ThreadTandem_Settings::fingerprint() === $fingerprint && ThreadTandem_Settings::secret() === $secret, 'Verified connection fingerprints and webhook secrets survive the rename' );
threadtandem_test_expect( ThreadTandem_Settings::get()['webhook_url'] === 'https://example.test/wp-json/threadtandem/v1/webhook', 'An explicit standard webhook URL receives the new REST namespace' );
threadtandem_test_expect( ThreadTandem_Updates::state()['offset'] === 456789 && ThreadTandem_Settings::updates_mode() === 'polling' && ! get_option( 'threadtandem_webhook_upgrade' ), 'Polling mode and its durable offset survive without enabling a webhook' );
threadtandem_test_expect( get_option( 'threadtandem_updates_state' ) === $cursor && ! ThreadTandem_Updates::state()['enabled'], 'A paused receiver stays paused and retains its complete cursor state after migration and cron registration' );
threadtandem_test_expect( get_post_meta( $post_id, '_threadtandem_publication', true ) === $binding_before && get_post_meta( $post_id, '_treda_publication', true ) === '', 'Publication metadata is migrated and primed caches are invalidated' );
threadtandem_test_expect( get_transient( 'threadtandem_bulk_preview_1' )['through'] === 9999 && ! get_transient( 'treda_bulk_preview_1' ), 'Bulk preview transients and their expiry keys move together' );
threadtandem_test_expect( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', ThreadTandem_Queue::table() ), ARRAY_A ) === $jobs_before && $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', ThreadTandem_Messages::table() ), ARRAY_A ) === $messages_before, 'All queue states, attempts, payloads, message IDs and relationships remain byte-for-byte equal' );
threadtandem_test_expect( $native_before === array( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ) ), 'Migration preserves every native post and comment' );
threadtandem_test_expect( ! wp_next_scheduled( 'treda_tick' ) && wp_get_schedule( 'threadtandem_tick' ) === 'threadtandem_minute', 'Only the canonical minute worker remains scheduled' );
threadtandem_test_expect( ! $exists( $wpdb->prefix . 'treda_messages' ) && ! get_option( 'treda_settings' ), 'Former runtime tables and settings are no longer used' );
threadtandem_test_expect( ThreadTandem_Migration::run() === true && $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', ThreadTandem_Queue::table() ), ARRAY_A ) === $jobs_before, 'Repeated bootstrap is idempotent and does not requeue or resend publications' );

// Both endpoints use the same authenticated durable receiver, never an HTTP redirect for POST.
$new_request = new WP_REST_Request( 'POST', '/threadtandem/v1/webhook' );
$new_request->set_header( 'Content-Type', 'application/json' ); $new_request->set_body( wp_json_encode( array( 'update_id' => 99001 ) ) );
threadtandem_test_expect( rest_do_request( $new_request )->get_status() === 403, 'The canonical webhook rejects requests without a secret' );
$new_request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $secret );
threadtandem_test_expect( rest_do_request( $new_request )->get_status() === 200, 'The canonical webhook accepts the preserved secret' );
$legacy_request = new WP_REST_Request( 'POST', '/treda/v1/webhook' ); $legacy_request->set_header( 'Content-Type', 'application/json' ); $legacy_request->set_body( wp_json_encode( array( 'update_id' => 99002, 'message' => threadtandem_test_message( 99002, 2000, 'Delivered through the previous endpoint' ) ) ) );
threadtandem_test_expect( rest_do_request( $legacy_request )->get_status() === 403, 'The legacy webhook alias cannot bypass authentication' );
$legacy_request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $secret );
threadtandem_test_expect( rest_do_request( $legacy_request )->get_status() === 200, 'Already registered legacy webhooks remain accepted during transition' );
threadtandem_test_expect( (bool) threadtandem_test_job( 'update:9988:99002' ), 'The authenticated old endpoint uses the same durable canonical queue' );
$current = ThreadTandem_Settings::webhook_url();
threadtandem_test_expect( ThreadTandem_Migration::owns_webhook( 'https://example.test/wp-json/treda/v1/webhook', $current ) && ! ThreadTandem_Migration::owns_webhook( 'https://another.example/wp-json/treda/v1/webhook', $current ), 'Old webhook ownership is recognized only for the same destination' );
threadtandem_test_expect( ThreadTandem_Migration::webhook_url( 'https://example.test/?rest_route=%2Ftreda%2Fv1%2Fwebhook' ) === 'https://example.test/?rest_route=%2Fthreadtandem%2Fv1%2Fwebhook', 'Plain-permalink REST URLs are also upgraded' );

$registered = null;
$fixture = function ( $pre, $args, $url ) use ( &$registered ) {
	$method = basename( wp_parse_url( $url, PHP_URL_PATH ) );
	if ( $method === 'setWebhook' ) { $registered = json_decode( $args['body'], true ); }
	$result = $method === 'getWebhookInfo' ? array( 'url' => 'https://example.test/wp-json/treda/v1/webhook' ) : true;
	return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true, 'result' => $result ) ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $fixture, 20, 3 );
$settings = ThreadTandem_Settings::get(); $settings['updates_mode'] = 'webhook'; update_option( 'threadtandem_settings', $settings );
update_option( 'threadtandem_webhook_upgrade', 1, false ); ThreadTandem_Updates::tick();
threadtandem_test_expect( $registered && $registered['url'] === $current && $registered['secret_token'] === $secret && $registered['drop_pending_updates'] === false && ! get_option( 'threadtandem_webhook_upgrade' ), 'Cron replaces an owned old webhook with the canonical URL while preserving its secret and pending events' );
remove_filter( 'pre_http_request', $fixture, 20 );
$settings['updates_mode'] = 'polling'; update_option( 'threadtandem_settings', $settings );

echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $threadtandem_test_checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
WP_CLI::success( count( $threadtandem_test_checks ) . ' identifier migration checks passed.' );
