<?php
/** Real core REST/editor regressions; shares the disposable integration fixtures. */
require __DIR__ . '/integration.php';
global $wpdb, $treda_test_checks;
$treda_editor_start = count($treda_test_checks);
add_filter('pre_http_request', 'treda_test_http', 10, 3);
wp_set_current_user(1);

function treda_editor_request($method, $path, $params = array()) {
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
        $late_observation = array('disabled' => get_post_meta($post->ID, '_treda_disabled', true), 'job' => treda_test_job('post:' . $post->ID));
    }
};
add_action('rest_after_insert_post', $observe, 1, 1);
$response = treda_editor_request('POST', '/wp/v2/posts', array('title' => 'ThreadTandem REST disabled at first publication',
    'status' => 'publish', 'meta' => array('_treda_disabled' => '1')));
remove_action('rest_after_insert_post', $observe, 1);
treda_test_expect($response->get_status() === 201, 'Block-editor REST publication accepts the registered preference');
$id = $response->get_data()['id'];
treda_test_expect($late_observation && $late_observation['disabled'] === '1' && !$late_observation['job'], 'No early outbox job exists before REST metadata finishes saving');
treda_test_expect(!treda_test_job('post:' . $id), 'Publishing with Telegram disabled creates no outgoing job');

$response = treda_editor_request('POST', '/wp/v2/posts/' . $id, array('meta' => array('_treda_disabled' => '0')));
treda_test_expect($response->get_status() === 200 && get_post_meta($id, '_treda_disabled', true) === '0', 'REST editor toggle can enable publishing');
treda_test_expect((int) treda_test_job('post:' . $id)->revision === 1, 'One REST save schedules exactly one queue revision');
treda_test_run();
$binding = get_post_meta($id, '_treda_publication', true);
treda_test_expect(!empty($binding['message_id']), 'Enabled REST publication is delivered by the worker');
$revision = (int) treda_test_job('post:' . $id)->revision;
$edits = count(treda_test_methods('editMessageText'));
treda_editor_request('POST', '/wp/v2/posts/' . $id, array('excerpt' => 'Disabled during same REST save', 'meta' => array('_treda_disabled' => '1')));
treda_test_run();
treda_test_expect((int) treda_test_job('post:' . $id)->revision === $revision && count(treda_test_methods('editMessageText')) === $edits, 'REST opt-out in the same save prevents stale export scheduling');
treda_editor_request('POST', '/wp/v2/posts/' . $id, array('excerpt' => 'Current excerpt after re-enable', 'meta' => array('_treda_disabled' => '0')));
treda_test_expect((int) treda_test_job('post:' . $id)->revision === $revision + 1, 'Re-enabling through REST adds one revision');
treda_test_run();
$calls = treda_test_methods('editMessageText');
$last = end($calls);
treda_test_expect((int) $last['params']['message_id'] === (int) $binding['message_id'] && strpos($last['params']['text'], 'Current excerpt after re-enable') !== false, 'Worker exports the latest REST excerpt into the original message');

$response = treda_editor_request('GET', '/wp/v2/posts/' . $id, array('context' => 'edit'));
treda_test_expect(($response->get_data()['meta']['_treda_disabled'] ?? null) === '0', 'Editor receives its protected publishing preference');
treda_test_expect(!isset($response->get_data()['meta']['_treda_publication']), 'REST metadata does not expose Telegram bindings');
$invalid = treda_editor_request('POST', '/wp/v2/posts/' . $id, array('meta' => array('_treda_disabled' => 'yes')));
treda_test_expect($invalid->get_status() === 400 && get_post_meta($id, '_treda_disabled', true) === '0', 'REST rejects invalid preference enum values');
$author_id = wp_insert_user(array('user_login' => 'treda_rest_author_' . wp_generate_password(6, false),
    'user_pass' => wp_generate_password(32), 'role' => 'author'));
$own_id = wp_insert_post(array('post_title' => 'Author REST draft', 'post_author' => $author_id, 'post_status' => 'draft'));
wp_set_current_user($author_id);
$own = treda_editor_request('POST', '/wp/v2/posts/' . $own_id, array('meta' => array('_treda_disabled' => '1')));
treda_test_expect($own->get_status() === 200 && get_post_meta($own_id, '_treda_disabled', true) === '1', 'Authors can control Telegram for their own posts');
$other = treda_editor_request('POST', '/wp/v2/posts/' . $id, array('meta' => array('_treda_disabled' => '1')));
treda_test_expect($other->get_status() === 403 && get_post_meta($id, '_treda_disabled', true) === '0', 'Authors cannot change another author publishing preference');
wp_set_current_user(0);
$public = treda_editor_request('GET', '/wp/v2/posts/' . $id);
treda_test_expect($public->get_status() === 200 && !isset($public->get_data()['meta']['_treda_disabled']), 'Publishing preference is absent from public REST responses');
wp_set_current_user(1);
$revision = (int) treda_test_job('post:' . $id)->revision;
$autosave = treda_editor_request('POST', '/wp/v2/posts/' . $id . '/autosaves', array('title' => 'Autosaved editor draft', 'content' => 'Autosave only'));
treda_test_expect(in_array($autosave->get_status(), array(200, 201), true) && (int) treda_test_job('post:' . $id)->revision === $revision, 'REST autosaves do not export draft changes');

$future = wp_insert_post(array('post_title' => 'Scheduled editor post', 'post_status' => 'future',
    'post_date' => gmdate('Y-m-d H:i:s', time() + 7200), 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() + 7200)));
treda_test_expect(!treda_test_job('post:' . $future), 'Scheduled draft is not exported early');
wp_publish_post($future);
treda_test_expect((int) treda_test_job('post:' . $future)->revision === 1, 'Scheduled publication is queued by the final save hook');

$classic = wp_insert_post(array('post_title' => 'Classic editor preference', 'post_status' => 'draft'));
$original_post = $_POST;
$_POST = array('treda_post_nonce' => wp_create_nonce('treda_post'));
wp_update_post(array('ID' => $classic, 'post_status' => 'publish'));
treda_test_expect(get_post_meta($classic, '_treda_disabled', true) === '1' && !treda_test_job('post:' . $classic), 'Classic-editor nonce preference is saved before queueing');
$_POST['treda_publish'] = '1';
wp_update_post(array('ID' => $classic, 'post_excerpt' => 'Classic re-enable'));
$_POST = $original_post;
treda_test_expect((int) treda_test_job('post:' . $classic)->revision === 1, 'Classic-editor re-enable still queues a single export');

treda_test_expect(Treda_Editor::uses_native_panel(get_post($id)), 'Block editor selects the native document panel');
add_filter('use_block_editor_for_post', '__return_false');
treda_test_expect(!Treda_Editor::uses_native_panel(get_post($id)), 'Classic Editor selects the existing metabox fallback');
remove_filter('use_block_editor_for_post', '__return_false');
remove_post_type_support('post', 'custom-fields');
treda_test_expect(!Treda_Editor::uses_native_panel(get_post($id)), 'Themes without REST custom fields retain the metabox fallback');
add_post_type_support('post', 'custom-fields');

foreach (array('note', 'wp_note', 'pingback', 'trackback') as $type) {
    $note = wp_insert_comment(array('comment_post_ID' => $id, 'comment_author' => 'Editorial user',
        'comment_content' => 'Private editor note', 'comment_type' => $type, 'comment_approved' => 1));
    treda_test_expect(!treda_test_job('comment:' . $note), 'Non-public comment type is not queued: ' . $type);
}
$changed = wp_insert_comment(array('comment_post_ID' => $id, 'comment_content' => 'Convert to editorial note before worker', 'comment_approved' => 1));
wp_update_comment(array('comment_ID' => $changed, 'comment_type' => 'note'));
$sends_before = count(treda_test_methods('sendMessage'));
$result = Treda_Comments::publish(array('comment_id' => $changed, 'connection' => Treda_Settings::fingerprint()));
treda_test_expect($result === true && count(treda_test_methods('sendMessage')) === $sends_before, 'Worker rechecks the comment type before exporting queued text');
remove_filter('pre_http_request', 'treda_test_http', 10);
wp_set_current_user(0);
$new_checks = array_slice($treda_test_checks, $treda_editor_start);
WP_CLI::success(count($new_checks) . ' editor/REST checks passed; ' . count($treda_test_checks) . ' total.');
echo wp_json_encode(array('wordpress' => get_bloginfo('version'), 'plugin' => TREDA_VERSION,
    'editor_rest_checks' => $new_checks), JSON_PRETTY_PRINT) . PHP_EOL;
