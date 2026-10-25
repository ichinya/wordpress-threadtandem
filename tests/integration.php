<?php
/** Run with wp eval-file, only in an explicitly marked disposable sandbox. */
if (PHP_SAPI !== 'cli' || !defined('ABSPATH') || !defined('WP_CLI') || !get_option('treda_qa_sandbox')) {
    exit('A disposable Treda QA WordPress sandbox is required.');
}

global $wpdb, $treda_test_checks, $treda_test_calls, $treda_test_message_id, $treda_test_next_error, $treda_test_during_send;
$treda_test_checks = array();
$treda_test_calls = array();
$treda_test_message_id = 1000;
$treda_test_next_error = null;
$treda_test_during_send = null;
function treda_test_expect($condition, $label) {
    global $treda_test_checks;
    if (!$condition) {
        WP_CLI::error($label);
    }
    $treda_test_checks[] = $label;
}
function treda_test_http($pre, $args, $url) {
    global $treda_test_calls, $treda_test_message_id, $treda_test_next_error, $treda_test_during_send;
    if (strpos($url, '/bot9988:') === false) {
        return $pre;
    }
    $method = basename(wp_parse_url($url, PHP_URL_PATH));
    $params = json_decode($args['body'], true);
    $treda_test_calls[] = array('method' => $method, 'params' => $params);
    if ($treda_test_next_error) {
        $error = $treda_test_next_error;
        $treda_test_next_error = null;
        return $error;
    }
    if ($method === 'sendMessage' && $treda_test_during_send) {
        $callback = $treda_test_during_send;
        $treda_test_during_send = null;
        $callback();
    }
    $results = array('getMe' => array('id' => 9988, 'username' => 'treda_qa_bot'),
        'getChat' => array('id' => -1001111111111, 'type' => 'channel', 'title' => 'QA channel', 'linked_chat_id' => -1002222222222),
        'getChatMember' => array('status' => 'administrator', 'can_post_messages' => true),
        'getWebhookInfo' => array('url' => ''), 'setWebhook' => true, 'deleteWebhook' => true, 'deleteMessage' => true,
        'sendMessage' => array('message_id' => ++$treda_test_message_id), 'editMessageText' => array('message_id' => $params['message_id'] ?? 0));
    return array('headers' => array(), 'response' => array('code' => 200, 'message' => 'OK'),
        'body' => wp_json_encode(array('ok' => true, 'result' => $results[$method])), 'cookies' => array());
}
function treda_test_methods($method) {
    global $treda_test_calls;
    return array_values(array_filter($treda_test_calls, function ($call) use ($method) { return $call['method'] === $method; }));
}
function treda_test_job($key) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE job_key=%s', Treda_Queue::table(), $key));
}
function treda_test_run() {
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE %i SET available_at=UTC_TIMESTAMP() WHERE status='pending'", Treda_Queue::table()));
    Treda_Queue::run();
}
function treda_test_message($id, $reply, $text = 'Telegram test message') {
    return array('message_id' => $id, 'date' => time(), 'chat' => array('id' => -1002222222222),
        'from' => array('id' => 555000 + $id, 'is_bot' => false, 'first_name' => 'Telegram ' . $id),
        'text' => $text, 'reply_to_message' => array('message_id' => $reply));
}
function treda_test_incoming($update) {
    $request = new WP_REST_Request('POST', '/treda/v1/webhook');
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-Telegram-Bot-Api-Secret-Token', Treda_Settings::secret());
    $request->set_body(wp_json_encode($update));
    return rest_do_request($request);
}

add_filter('pre_http_request', 'treda_test_http', 10, 3);
add_filter('pre_wp_mail', '__return_true');
wp_set_current_user(0);
update_option('comment_registration', 0);
update_option('comment_moderation', 0);
update_option('moderation_notify', 0);
update_option('comments_notify', 0);
update_option('treda_settings', array('token' => '9988:' . str_repeat('a', 32), 'chat_id' => '@treda_qa',
    'api_url' => 'https://api.telegram.org', 'enabled' => 1, 'comments_enabled' => 1, 'telegram_moderation' => 1, 'retain_data' => 1));
$wpdb->query($wpdb->prepare('DELETE FROM %i', Treda_Queue::table()));
$wpdb->query($wpdb->prepare('DELETE FROM %i', Treda_Messages::table()));
treda_test_expect(get_option('treda_schema_version') === TREDA_SCHEMA_VERSION, 'Schema migration completed');
treda_test_expect(Treda_Messages::transactional(), 'Real comment, metadata and mapping tables are InnoDB');
treda_test_expect(!is_wp_error(Treda_API::check()), 'Channel and discussion bot permissions verified');

$post_id = wp_insert_post(array('post_title' => 'Treda QA post', 'post_excerpt' => 'First excerpt',
    'post_content' => 'QA content', 'post_status' => 'publish', 'comment_status' => 'open'));
treda_test_expect((bool) treda_test_job('post:' . $post_id), 'Native post hook persisted an outbox job');
Treda_Queue::post($post_id);
treda_test_run();
$binding = get_post_meta($post_id, '_treda_publication', true);
treda_test_expect(!empty($binding['message_id']), 'Worker persisted real publication metadata');
treda_test_expect(count(treda_test_methods('sendMessage')) === 1, 'Repeated enqueue produced one external send');
Treda_Queue::post($post_id);
treda_test_run();
treda_test_expect(count(treda_test_methods('sendMessage')) === 1, 'Completed publication does not send twice');
wp_update_post(array('ID' => $post_id, 'post_excerpt' => 'Updated excerpt'));
treda_test_run();
treda_test_expect(count(treda_test_methods('editMessageText')) === 1, 'Native post update edits the existing announcement');

$root = array('message_id' => 2000, 'date' => time(), 'chat' => array('id' => -1002222222222),
    'is_automatic_forward' => true, 'forward_origin' => array('type' => 'channel',
    'chat' => array('id' => -1001111111111), 'message_id' => (int) $binding['message_id']));
$request = new WP_REST_Request('POST', '/treda/v1/webhook');
$request->set_body(wp_json_encode(array('update_id' => 100, 'message' => $root)));
treda_test_expect(rest_do_request($request)->get_status() === 403, 'Actual REST permissions reject unsigned delivery');
treda_test_expect(treda_test_incoming(array('update_id' => 100, 'message' => $root))->get_status() === 200, 'Authenticated REST delivery accepted');
treda_test_incoming(array('update_id' => 100, 'message' => $root));
treda_test_expect((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE job_key=%s', Treda_Queue::table(), 'update:9988:100')) === 1, 'Repeated update has one durable database receipt');
treda_test_run();
treda_test_expect((int) get_post_meta($post_id, '_treda_discussion', true)['message_id'] === 2000, 'Automatic forward binds the exact discussion root');
treda_test_expect((int) Treda_Messages::find('-1002222222222', 2000)->post_id === (int) $post_id, 'Root has indexed correspondence');

$message = treda_test_message(2001, 2000, 'First question from Telegram');
treda_test_incoming(array('update_id' => 101, 'message' => $message));
treda_test_run();
$mapping = Treda_Messages::find('-1002222222222', 2001);
treda_test_expect($mapping && $mapping->comment_id, 'Telegram text created a native WordPress comment');
$comment_id = (int) $mapping->comment_id;
$comment = get_comment($comment_id);
treda_test_expect((int) $comment->comment_post_ID === (int) $post_id && (int) $comment->comment_parent === 0, 'Imported root reply belongs to the correct post');
treda_test_expect((string) $comment->comment_approved === '0', 'Imported comment initially held for moderation');
treda_test_expect($comment->comment_author_email === '' && (int) $comment->user_id === 0, 'No invented email or WordPress account');
treda_test_expect(get_comment_meta($comment_id, '_treda_origin', true) === 'telegram', 'Origin metadata exists before outgoing hooks run');
$sends = count(treda_test_methods('sendMessage'));
wp_set_comment_status($comment_id, 'approve');
treda_test_run();
treda_test_expect(count(treda_test_methods('sendMessage')) === $sends, 'Approving imported text does not echo to Telegram');

$reply_id = wp_insert_comment(array('comment_post_ID' => $post_id, 'comment_author' => 'Website moderator',
    'comment_content' => 'Reply from the website', 'comment_parent' => $comment_id, 'comment_approved' => 0, 'comment_type' => 'comment'));
treda_test_expect(!treda_test_job('comment:' . $reply_id), 'Unapproved website comment is not exported');
wp_set_comment_status($reply_id, 'approve');
treda_test_run();
$reply_mapping = Treda_Messages::for_comment($reply_id);
$sent = treda_test_methods('sendMessage');
$last = end($sent);
treda_test_expect($reply_mapping && (int) $last['params']['reply_parameters']['message_id'] === 2001, 'Website reply targets the exact Telegram parent');
treda_test_expect(strpos($last['params']['text'], 'Website moderator') !== false, 'Website author name is included in the bot message');
$third = treda_test_message(2002, (int) $reply_mapping->message_id, 'Continue after the website reply');
treda_test_incoming(array('update_id' => 102, 'message' => $third));
treda_test_run();
$third_mapping = Treda_Messages::find('-1002222222222', 2002);
treda_test_expect((int) get_comment($third_mapping->comment_id)->comment_parent === (int) $reply_id, 'Telegram reply to bot message retains nested website parent');

treda_test_incoming(array('update_id' => 103, 'message' => $message));
treda_test_run();
treda_test_expect((int) Treda_Messages::find('-1002222222222', 2001)->comment_id === $comment_id, 'New update ID for the same message does not duplicate a comment');
treda_test_expect((string) get_comment($comment_id)->comment_approved === '1', 'Duplicate unchanged delivery does not revoke approval');
$message['edit_date'] = time() + 10;
$message['text'] = 'Edited Telegram question';
treda_test_incoming(array('update_id' => 104, 'edited_message' => $message));
treda_test_run();
$edited = get_comment($comment_id);
treda_test_expect(strpos($edited->comment_content, 'Edited Telegram question') !== false && (string) $edited->comment_approved === '0', 'Telegram edit updates the same comment and resets approval');
treda_test_expect((int) Treda_Messages::find('-1002222222222', 2001)->comment_id === $comment_id, 'Editing preserves the actual comment ID');
$message['text'] = 'Second edit within the same second';
treda_test_incoming(array('update_id' => 106, 'edited_message' => $message));
treda_test_run();
treda_test_expect(get_comment($comment_id)->comment_content === $message['text'], 'Update ID orders edits sharing the same timestamp');
$message['edit_date']--;
$message['text'] = 'Stale edit that must not win';
treda_test_incoming(array('update_id' => 105, 'edited_message' => $message));
treda_test_run();
treda_test_expect(strpos(get_comment($comment_id)->comment_content, 'Second edit within the same second') !== false, 'Older Telegram edits cannot overwrite newer text');

wp_update_comment(array('comment_ID' => $reply_id, 'comment_content' => 'Website edit'));
treda_test_run();
$edits = treda_test_methods('editMessageText');
treda_test_expect((int) end($edits)['params']['message_id'] === (int) $reply_mapping->message_id, 'Website edit uses the saved Telegram message ID');
wp_set_comment_status($reply_id, 'hold');
treda_test_run();
treda_test_expect(!Treda_Messages::for_comment($reply_id) && count(treda_test_methods('deleteMessage')) === 1, 'Unapproving a website comment removes its bot copy');
wp_set_comment_status($reply_id, 'approve');
treda_test_run();
treda_test_expect((int) Treda_Messages::for_comment($reply_id)->message_id !== (int) $reply_mapping->message_id, 'Reapproval creates a new copy after a confirmed deletion');

$unknown = treda_test_message(2010, 999999, 'Unknown parent');
treda_test_incoming(array('update_id' => 110, 'message' => $unknown));
treda_test_run();
treda_test_expect(!Treda_Messages::find('-1002222222222', 2010), 'Unknown parent is never assigned to the latest post');
treda_test_expect(treda_test_job('update:9988:110')->status === 'pending', 'Unmatched reply waits for the parent mapping');
$wpdb->query($wpdb->prepare("UPDATE %i SET status='done' WHERE job_key=%s", Treda_Queue::table(), 'update:9988:110'));

$anonymous = treda_test_message(2011, 2000, 'Anonymous admin text');
$anonymous['from'] = array('id' => 1087968824, 'is_bot' => true, 'first_name' => 'Group');
$anonymous['sender_chat'] = array('id' => -1002222222222, 'title' => 'Anonymous group admin');
treda_test_incoming(array('update_id' => 111, 'message' => $anonymous));
treda_test_run();
$anonymous_id = Treda_Messages::find('-1002222222222', 2011)->comment_id;
treda_test_expect(get_comment($anonymous_id)->comment_author === 'Anonymous group admin', 'Anonymous sender is represented by its chat title');
treda_test_expect(get_comment_meta($anonymous_id, '_treda_sender_type', true) === 'chat', 'Anonymous sender is not attributed to a fake person');

$unsafe = treda_test_message(2012, 2000, '<script>alert(1)</script> & plain text');
treda_test_incoming(array('update_id' => 113, 'message' => $unsafe));
treda_test_run();
$unsafe_comment = get_comment(Treda_Messages::find('-1002222222222', 2012)->comment_id);
treda_test_expect(strpos(apply_filters('comment_text', $unsafe_comment->comment_content, $unsafe_comment), '<script>') === false, 'Telegram text cannot inject executable HTML');
$foreign = treda_test_message(2013, 2000, 'Unrelated group');
$foreign['chat']['id'] = -1003333333333;
$ignored = treda_test_incoming(array('update_id' => 114, 'message' => $foreign));
treda_test_expect(!empty($ignored->get_data()['ignored']) && !treda_test_job('update:9988:114'), 'Unrelated group events are neither imported nor retained');
$bot_message = treda_test_message(2014, 2000, 'Bot echo');
$bot_message['from']['is_bot'] = true;
treda_test_incoming(array('update_id' => 115, 'message' => $bot_message));
treda_test_expect(!treda_test_job('update:9988:115'), 'Bot messages cannot start an echo loop');
update_option('comment_registration', 1);
treda_test_incoming(array('update_id' => 116, 'message' => treda_test_message(2015, 2000, 'Registration policy test')));
treda_test_run();
treda_test_expect(!Treda_Messages::find('-1002222222222', 2015) && treda_test_job('update:9988:116')->status === 'failed', 'Import respects the registered-commenter policy');
update_option('comment_registration', 0);
$spam_filter = function ($approved, $data) {
    return ($data['comment_meta']['_treda_origin'] ?? '') === 'telegram' ? 'spam' : $approved;
};
add_filter('pre_comment_approved', $spam_filter, 99, 2);
treda_test_incoming(array('update_id' => 117, 'message' => treda_test_message(2016, 2000, 'Native spam filter test')));
treda_test_run();
remove_filter('pre_comment_approved', $spam_filter, 99);
treda_test_expect(get_comment(Treda_Messages::find('-1002222222222', 2016)->comment_id)->comment_approved === 'spam', 'Native spam decisions are preserved during import');
wp_update_comment(array('comment_ID' => $anonymous_id, 'comment_author_email' => 'identity@example.test'));
$export = Treda_Privacy::export('identity@example.test');
treda_test_expect(count($export['data']) === 1, 'Verified email privacy export includes additional Telegram metadata');
$erasure = Treda_Privacy::erase('identity@example.test');
treda_test_expect($erasure['items_removed'] && $erasure['items_retained'], 'Privacy erasure removes public identity references and reports retained delivery mappings');
treda_test_expect(get_comment_meta($anonymous_id, '_treda_origin', true) === 'telegram'
    && strpos(get_comment_author_link($anonymous_id), 't.me/') === false, 'Privacy erasure keeps echo protection without a public Telegram identity link');

wp_delete_comment($comment_id, true);
$message['edit_date'] = time() + 20;
$message['text'] = 'Must not resurrect deleted comment';
treda_test_incoming(array('update_id' => 112, 'edited_message' => $message));
treda_test_run();
treda_test_expect(!get_comment($comment_id), 'Editing in Telegram does not recreate a locally deleted comment');

$other_id = wp_insert_post(array('post_title' => 'Treda QA concurrent edit', 'post_excerpt' => 'Before send', 'post_status' => 'publish'));
$treda_test_during_send = function () use ($other_id) { wp_update_post(array('ID' => $other_id, 'post_excerpt' => 'Saved during send')); };
treda_test_run();
treda_test_run();
treda_test_expect(treda_test_job('post:' . $other_id)->status === 'done', 'An edit during processing leaves a completed latest revision');
$edits = treda_test_methods('editMessageText');
treda_test_expect(strpos(end($edits)['params']['text'], 'Saved during send') !== false, 'Concurrent save is not lost when the first send completes');

$uncertain_id = wp_insert_post(array('post_title' => 'Treda QA uncertain send', 'post_status' => 'publish'));
$treda_test_next_error = new WP_Error('http_request_failed', 'Private transport details');
treda_test_run();
treda_test_expect(treda_test_job('post:' . $uncertain_id)->status === 'uncertain', 'Real SQL queue preserves an ambiguous send result');
Treda_Queue::post($uncertain_id);
treda_test_expect(treda_test_job('post:' . $uncertain_id)->status === 'uncertain', 'Subsequent saves do not silently resend an ambiguous operation');
treda_test_expect(Treda_Queue::retry(treda_test_job('post:' . $uncertain_id)->id, false) === 0, 'Manual retry requires explicit acknowledgment for uncertain results');

$pending_id = wp_insert_post(array('post_title' => 'Treda QA unpublished before worker', 'post_status' => 'publish'));
wp_update_post(array('ID' => $pending_id, 'post_status' => 'draft'));
$send_count = count(treda_test_methods('sendMessage'));
treda_test_run();
treda_test_expect(count(treda_test_methods('sendMessage')) === $send_count, 'Worker rechecks visibility rather than sending stale queued content');

require_once ABSPATH . 'wp-admin/includes/template.php';
wp_set_current_user(1);
ob_start();
Treda_Admin::render();
$html = ob_get_clean();
treda_test_expect(strpos($html, Treda_Settings::token()) === false, 'Rendered admin HTML contains no stored token');
$subscriber = wp_create_user('treda_qa_subscriber_' . wp_generate_password(5, false), wp_generate_password(32, true), 'subscriber-' . time() . '@example.test');
wp_set_current_user($subscriber);
ob_start();
Treda_Admin::render();
treda_test_expect(ob_get_clean() === '', 'Subscriber cannot render Treda settings');
wp_set_current_user(0);

remove_filter('pre_http_request', 'treda_test_http', 10);
WP_CLI::success(count($treda_test_checks) . ' real WordPress/MySQL checks passed.');
echo wp_json_encode(array('wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'checks' => $treda_test_checks), JSON_PRETTY_PRINT) . PHP_EOL;
