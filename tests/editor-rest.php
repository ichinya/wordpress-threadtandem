<?php
/** Real core REST/editor regressions; shares the disposable integration fixtures. */
require __DIR__ . '/integration.php';
global $wpdb, $threadtandem_test_checks;
$threadtandem_editor_start = count($threadtandem_test_checks);
// The shared suite deliberately leaves an uncertain oldest send; editor cases need a fresh outbox.
$wpdb->query($wpdb->prepare('DELETE FROM %i', ThreadTandem_Queue::table()));
add_filter('pre_http_request', 'threadtandem_test_http', 10, 3);
wp_set_current_user(1);

function threadtandem_editor_request($method, $path, $params = array()) {
    $request = new WP_REST_Request($method, $path);
    if ($method === 'GET') {
        $request->set_query_params($params);
    } else {
        $request->set_body_params($params);
    }
    return rest_do_request($request);
}

$late_observation = null;
$observe = function ($post) use (&$late_observation) {
    if ($post->post_title === 'ThreadTandem REST disabled at first publication') {
        $late_observation = array('disabled' => get_post_meta($post->ID, '_threadtandem_disabled', true), 'job' => threadtandem_test_job('post:' . $post->ID));
    }
};
add_action('rest_after_insert_post', $observe, 1, 1);
$response = threadtandem_editor_request('POST', '/wp/v2/posts', array('title' => 'ThreadTandem REST disabled at first publication',
    'status' => 'publish', 'meta' => array('_threadtandem_disabled' => '1')));
remove_action('rest_after_insert_post', $observe, 1);
threadtandem_test_expect($response->get_status() === 201, 'Block-editor REST publication accepts the registered preference');
$id = $response->get_data()['id'];
threadtandem_test_expect($late_observation && $late_observation['disabled'] === '1' && !$late_observation['job'], 'No early outbox job exists before REST metadata finishes saving');
threadtandem_test_expect(!threadtandem_test_job('post:' . $id), 'Publishing with Telegram disabled creates no outgoing job');

$response = threadtandem_editor_request('POST', '/wp/v2/posts/' . $id, array('meta' => array('_threadtandem_disabled' => '0')));
threadtandem_test_expect($response->get_status() === 200 && get_post_meta($id, '_threadtandem_disabled', true) === '0', 'REST editor toggle can enable publishing');
threadtandem_test_expect((int) threadtandem_test_job('post:' . $id)->revision === 1, 'One REST save schedules exactly one queue revision');
threadtandem_test_run();
$binding = get_post_meta($id, '_threadtandem_publication', true);
threadtandem_test_expect(!empty($binding['message_id']), 'Enabled REST publication is delivered by the worker');
$revision = (int) threadtandem_test_job('post:' . $id)->revision;
$edits = count(threadtandem_test_methods('editMessageText'));
threadtandem_editor_request('POST', '/wp/v2/posts/' . $id, array('excerpt' => 'Disabled during same REST save', 'meta' => array('_threadtandem_disabled' => '1')));
threadtandem_test_run();
threadtandem_test_expect((int) threadtandem_test_job('post:' . $id)->revision === $revision && count(threadtandem_test_methods('editMessageText')) === $edits, 'REST opt-out in the same save prevents stale export scheduling');
threadtandem_editor_request('POST', '/wp/v2/posts/' . $id, array('excerpt' => 'Current excerpt after re-enable', 'meta' => array('_threadtandem_disabled' => '0')));
threadtandem_test_expect((int) threadtandem_test_job('post:' . $id)->revision === $revision + 1, 'Re-enabling through REST adds one revision');
threadtandem_test_run();
$calls = threadtandem_test_methods('editMessageText');
$last = end($calls);
threadtandem_test_expect((int) $last['params']['message_id'] === (int) $binding['message_id'] && strpos($last['params']['text'], 'Current excerpt after re-enable') !== false, 'Worker exports the latest REST excerpt into the original message');

$response = threadtandem_editor_request('GET', '/wp/v2/posts/' . $id, array('context' => 'edit'));
threadtandem_test_expect(($response->get_data()['meta']['_threadtandem_disabled'] ?? null) === '0', 'Editor receives its protected publishing preference');
threadtandem_test_expect(!isset($response->get_data()['meta']['_threadtandem_publication']), 'REST metadata does not expose Telegram bindings');
$invalid = threadtandem_editor_request('POST', '/wp/v2/posts/' . $id, array('meta' => array('_threadtandem_disabled' => 'yes')));
threadtandem_test_expect($invalid->get_status() === 400 && get_post_meta($id, '_threadtandem_disabled', true) === '0', 'REST rejects invalid preference enum values');
$author_id = wp_insert_user(array('user_login' => 'threadtandem_rest_author_' . wp_generate_password(6, false),
    'user_pass' => wp_generate_password(32), 'role' => 'author'));
$own_id = wp_insert_post(array('post_title' => 'Author REST draft', 'post_author' => $author_id, 'post_status' => 'draft'));
wp_set_current_user($author_id);
$own = threadtandem_editor_request('POST', '/wp/v2/posts/' . $own_id, array('meta' => array('_threadtandem_disabled' => '1')));
threadtandem_test_expect($own->get_status() === 200 && get_post_meta($own_id, '_threadtandem_disabled', true) === '1', 'Authors can control Telegram for their own posts');
$other = threadtandem_editor_request('POST', '/wp/v2/posts/' . $id, array('meta' => array('_threadtandem_disabled' => '1')));
threadtandem_test_expect($other->get_status() === 403 && get_post_meta($id, '_threadtandem_disabled', true) === '0', 'Authors cannot change another author publishing preference');
wp_set_current_user(0);
$public = threadtandem_editor_request('GET', '/wp/v2/posts/' . $id);
threadtandem_test_expect($public->get_status() === 200 && !isset($public->get_data()['meta']['_threadtandem_disabled']), 'Publishing preference is absent from public REST responses');
wp_set_current_user(1);
$revision = (int) threadtandem_test_job('post:' . $id)->revision;
$autosave = threadtandem_editor_request('POST', '/wp/v2/posts/' . $id . '/autosaves', array('title' => 'Autosaved editor draft', 'content' => 'Autosave only'));
threadtandem_test_expect(in_array($autosave->get_status(), array(200, 201), true) && (int) threadtandem_test_job('post:' . $id)->revision === $revision, 'REST autosaves do not export draft changes');

$future = wp_insert_post(array('post_title' => 'Scheduled editor post', 'post_status' => 'future',
    'post_date' => gmdate('Y-m-d H:i:s', time() + 7200), 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() + 7200)));
threadtandem_test_expect(!threadtandem_test_job('post:' . $future), 'Scheduled draft is not exported early');
wp_publish_post($future);
threadtandem_test_expect((int) threadtandem_test_job('post:' . $future)->revision === 1, 'Scheduled publication is queued by the final save hook');

$classic = wp_insert_post(array('post_title' => 'Classic editor preference', 'post_status' => 'draft'));
$original_post = $_POST;
$_POST = array('threadtandem_post_nonce' => wp_create_nonce('threadtandem_post'));
wp_update_post(array('ID' => $classic, 'post_status' => 'publish'));
threadtandem_test_expect(get_post_meta($classic, '_threadtandem_disabled', true) === '1' && !threadtandem_test_job('post:' . $classic), 'Classic-editor nonce preference is saved before queueing');
$_POST['threadtandem_publish'] = '1';
wp_update_post(array('ID' => $classic, 'post_excerpt' => 'Classic re-enable'));
$_POST = $original_post;
threadtandem_test_expect((int) threadtandem_test_job('post:' . $classic)->revision === 1, 'Classic-editor re-enable still queues a single export');

threadtandem_test_expect(ThreadTandem_Editor::uses_native_panel(get_post($id)), 'Block editor selects the native document panel');
add_filter('use_block_editor_for_post', '__return_false');
threadtandem_test_expect(!ThreadTandem_Editor::uses_native_panel(get_post($id)), 'Classic Editor selects the existing metabox fallback');
remove_filter('use_block_editor_for_post', '__return_false');
remove_post_type_support('post', 'custom-fields');
threadtandem_test_expect(!ThreadTandem_Editor::uses_native_panel(get_post($id)), 'Themes without REST custom fields retain the metabox fallback');
add_post_type_support('post', 'custom-fields');

foreach (array('note', 'wp_note', 'pingback', 'trackback') as $type) {
    $note = wp_insert_comment(array('comment_post_ID' => $id, 'comment_author' => 'Editorial user',
        'comment_content' => 'Private editor note', 'comment_type' => $type, 'comment_approved' => 1));
    threadtandem_test_expect(!threadtandem_test_job('comment:' . $note), 'Non-public comment type is not queued: ' . $type);
}
$changed = wp_insert_comment(array('comment_post_ID' => $id, 'comment_content' => 'Convert to editorial note before worker', 'comment_approved' => 1));
wp_update_comment(array('comment_ID' => $changed, 'comment_type' => 'note'));
$sends_before = count(threadtandem_test_methods('sendMessage'));
$result = ThreadTandem_Comments::publish(array('comment_id' => $changed, 'connection' => ThreadTandem_Settings::fingerprint()));
threadtandem_test_expect($result === true && count(threadtandem_test_methods('sendMessage')) === $sends_before, 'Worker rechecks the comment type before exporting queued text');
remove_filter('pre_http_request', 'threadtandem_test_http', 10);
wp_set_current_user(0);
$new_checks = array_slice($threadtandem_test_checks, $threadtandem_editor_start);
WP_CLI::success(count($new_checks) . ' editor/REST checks passed; ' . count($threadtandem_test_checks) . ' total.');
echo wp_json_encode(array('wordpress' => get_bloginfo('version'), 'plugin' => THREADTANDEM_VERSION,
    'editor_rest_checks' => $new_checks), JSON_PRETTY_PRINT) . PHP_EOL;
