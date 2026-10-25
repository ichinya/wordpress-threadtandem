<?php
/** A settings change must not silently lose the next publication. Disposable QA only. */
require __DIR__ . '/integration.php';
$refresh_start = count($treda_test_checks);
add_filter('pre_http_request', 'treda_test_http', 10, 3);
$settings = Treda_Settings::get();
$settings['comments_enabled'] = 0;
update_option('treda_settings', $settings, false);
$checked = Treda_API::check();
treda_test_expect(!is_wp_error($checked), 'Publication connection is initially verified');
$settings['comments_enabled'] = 1;
update_option('treda_settings', $settings, false);
treda_test_expect(!Treda_Settings::connection(), 'Enabling comments requires updated permission validation');
$id = wp_insert_post(array('post_title' => 'Publish after enabling Telegram comments', 'post_status' => 'publish'));
treda_test_expect(treda_test_job('post:' . $id)->status === 'pending', 'An enabled publication is queued even when cached validation is stale');
$checks_before = count(treda_test_methods('getMe'));
for ($i = 0; $i < 8 && treda_test_job('post:' . $id)->status === 'pending'; $i++) { treda_test_run(); }
treda_test_expect(treda_test_job('post:' . $id)->status === 'done' && get_post_meta($id, '_treda_publication', true), 'The worker verifies the connection and delivers the queued publication');
treda_test_expect(count(treda_test_methods('getMe')) > $checks_before && Treda_Settings::ready(), 'The worker refreshes real connection checks before sending');
$sent_before = count(treda_test_methods('sendMessage'));
Treda_Publisher::queue($id);
treda_test_run();
treda_test_expect(count(treda_test_methods('sendMessage')) === $sent_before, 'Repeating the job after validation does not duplicate the announcement');
delete_option('treda_connection');
$failed = wp_insert_post(array('post_title' => 'Invalid bot permission remains diagnosable', 'post_status' => 'publish'));
$deny_check = function ($pre, $args, $url) {
    if (strpos($url, '/bot9988:') !== false && basename(wp_parse_url($url, PHP_URL_PATH)) === 'getMe') {
        return array('headers'=>array(), 'response'=>array('code'=>403), 'body'=>'{"ok":false,"error_code":403}', 'cookies'=>array());
    }
    return $pre;
};
add_filter('pre_http_request', $deny_check, 11, 3);
treda_test_run();
remove_filter('pre_http_request', $deny_check, 11);
treda_test_expect(treda_test_job('post:' . $failed)->status === 'failed' && !get_post_meta($failed, '_treda_publication', true), 'A rejected connection remains in queue diagnostics without sending');
treda_test_expect(treda_test_job('post:' . $failed)->last_error !== '', 'The connection failure has an actionable queue message');
remove_filter('pre_http_request', 'treda_test_http', 10);
WP_CLI::success((count($treda_test_checks) - $refresh_start) . ' connection-refresh checks passed; ' . count($treda_test_checks) . ' total.');
