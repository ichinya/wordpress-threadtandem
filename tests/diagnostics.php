<?php
/** A busy successful event stream must never conceal delivery failures. */
if ( ! defined( 'WP_CLI' ) || ! get_option( 'treda_qa_sandbox' ) || DB_HOST !== '127.0.0.1:13316' ) { exit( 'Disposable offline QA only.' ); }
require __DIR__ . '/integration.php';
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Treda_Queue::table() ) );
foreach ( array( 'failed' => 9, 'uncertain' => 52, 'done' => 25 ) as $status => $number ) {
	for ( $i = 1; $i <= $number; $i++ ) {
		$wpdb->insert( Treda_Queue::table(), array( 'job_key' => $status . ':' . $i, 'kind' => $status === 'done' ? 'update' : 'post', 'payload' => '{}', 'status' => $status, 'attempts' => 1, 'last_error' => $status === 'done' ? '' : 'Diagnostic fixture <script>alert(1)</script>', 'updated_at' => $status === 'done' ? '2026-10-31 12:00:00' : '2026-10-30 12:00:00', 'available_at' => '2026-10-30 12:00:00' ) );
	}
}
$before = Treda_Queue::counts();
$all = Treda_Queue::diagnostics();
treda_test_expect( $all['total'] === 86 && count( $all['jobs'] ) === 20 && ! array_filter( $all['jobs'], static function ( $job ) { return $job->status === 'done'; } ), 'Old failures precede newer successful events in the complete history' );
$seen = array();
for ( $page = 1; $page <= 4; $page++ ) {
	$history = Treda_Queue::diagnostics( 'attention', $page );
	treda_test_expect( $history['total'] === 61 && $history['pages'] === 4 && $history['page'] === $page, 'Attention page ' . $page . ' retains the complete problem count' );
	foreach ( $history['jobs'] as $job ) { $seen[] = $job->id; }
}
treda_test_expect( count( $seen ) === 61 && count( array_unique( $seen ) ) === 61, 'Every problem is reachable exactly once across pages with tied timestamps' );
foreach ( array( 'failed' => 9, 'uncertain' => 52, 'done' => 25, 'pending' => 0, 'running' => 0 ) as $status => $expected ) {
	$history = Treda_Queue::diagnostics( $status );
	treda_test_expect( $history['total'] === $expected && ! array_filter( $history['jobs'], static function ( $job ) use ( $status ) { return $job->status !== $status; } ), 'Status filter ' . $status . ' contains only matching jobs' );
}
treda_test_expect( Treda_Queue::diagnostics( 'attention', 999999999 )['page'] === 4 && Treda_Queue::diagnostics( 'attention', -10 )['page'] === 1, 'Out-of-range pages are clamped instead of hiding existing errors' );
treda_test_expect( Treda_Queue::diagnostic_status( "failed' OR 1=1 --" ) === 'all' && Treda_Queue::diagnostic_status( array() ) === 'all', 'Malformed statuses cannot enter the SQL filter' );
wp_set_current_user( 1 );
$_GET = array();
ob_start(); Treda_Admin::render(); $html = ob_get_clean();
treda_test_expect( strpos( $html, 'queue_status=attention#treda-diagnostics" aria-current="page"' ) !== false && strpos( $html, 'uncertain:52' ) !== false && strpos( $html, 'done:25' ) === false, 'Default settings render problems rather than the latest successful updates' );
treda_test_expect( strpos( $html, 'queue_page=4#treda-diagnostics' ) !== false && strpos( $html, 'name="queue_status" value="attention"' ) !== false && strpos( $html, 'name="acknowledge" value="1" required' ) !== false, 'Pagination and retry forms preserve the filter and duplicate-risk acknowledgement' );
treda_test_expect( strpos( $html, '<script>alert(1)</script>' ) === false && strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) !== false, 'Diagnostic errors are escaped before rendering' );
$_GET = array( 'queue_status' => 'failed', 'queue_page' => 1000 );
ob_start(); Treda_Admin::render(); $html = ob_get_clean();
treda_test_expect( strpos( $html, 'failed:9' ) !== false && strpos( $html, 'uncertain:52' ) === false && strpos( $html, 'name="queue_page" value="1"' ) !== false, 'Failed view clamps stale pages and preserves the actual page in action forms' );
$_GET = array( 'queue_status' => 'pending' );
ob_start(); Treda_Admin::render(); $html = ob_get_clean();
treda_test_expect( strpos( $html, __( 'No jobs match this filter.', 'threadtandem-sync-for-telegram' ) ) !== false && strpos( $html, __( 'The queue is empty.', 'threadtandem-sync-for-telegram' ) ) === false, 'An empty filter does not claim the entire delivery history is empty' );
$_GET = array( 'queue_status' => array( 'bad' ), 'queue_page' => array( 'bad' ) );
ob_start(); Treda_Admin::render(); $html = ob_get_clean();
treda_test_expect( strpos( $html, 'uncertain:52' ) !== false, 'Array-valued query parameters do not break the default problem view' );
treda_test_expect( Treda_Queue::counts() === $before, 'Browsing and filtering diagnostics never requeues or modifies jobs' );
$_GET = array();
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $treda_test_checks ), JSON_PRETTY_PRINT ) . PHP_EOL;
WP_CLI::success( count( $treda_test_checks ) . ' checks passed, including diagnostic visibility and pagination.' );
