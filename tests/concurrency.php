<?php
/** Two-process lease and incoming-message uniqueness tests, disposable sandbox only. */
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !get_option('treda_qa_sandbox')) {
    exit('Disposable sandbox required.');
}
global $wpdb;
$treda_qa_mode = getenv('TREDA_QA_CONCURRENCY_MODE') ?: 'worker';
$treda_qa_log = get_option('treda_qa_concurrency_log');
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, '/bot9988:') === false) {
        return new WP_Error('treda_test_guard', 'Real network is disabled in concurrency tests.');
    }
    $method = basename(wp_parse_url($url, PHP_URL_PATH));
    if ($method === 'sendMessage') {
        $params = json_decode($args['body'], true);
        $line = get_option('treda_qa_concurrency_order') ? strtok($params['text'], "\n") : 'sendMessage';
        file_put_contents(get_option('treda_qa_concurrency_log'), $line . "\n", FILE_APPEND | LOCK_EX);
        usleep(350000);
        $result = array('message_id' => 90001);
    } elseif ($method === 'getMe') {
        $result = array('id' => 9988, 'username' => 'treda_qa_bot');
    } elseif ($method === 'getChat') {
        $result = array('id' => -1001111111111, 'type' => 'channel', 'title' => 'QA', 'linked_chat_id' => -1002222222222);
    } elseif ($method === 'getChatMember') {
        $result = array('status' => 'administrator', 'can_post_messages' => true);
    } else {
        $result = true;
    }
    return array('headers' => array(), 'response' => array('code' => 200, 'message' => 'OK'),
        'body' => wp_json_encode(array('ok' => true, 'result' => $result)), 'cookies' => array());
}, 10, 3);
add_filter('preprocess_comment', function ($data) {
    if (($data['comment_meta']['_treda_origin'] ?? '') === 'telegram') { usleep(350000); }
    return $data;
});

if (in_array($treda_qa_mode, array('prepare', 'prepare-order'), true)) {
    update_option('treda_qa_concurrency_order', $treda_qa_mode === 'prepare-order');
    update_option('treda_settings', array('token' => '9988:' . str_repeat('a', 32), 'chat_id' => '@treda_qa',
        'api_url' => 'https://api.telegram.org', 'enabled' => 1, 'comments_enabled' => 1, 'telegram_moderation' => 1));
    $check = Treda_API::check();
    if (is_wp_error($check)) { WP_CLI::error($check->get_error_message()); }
    $wpdb->query($wpdb->prepare('DELETE FROM %i', Treda_Queue::table()));
    $path = get_temp_dir() . 'treda-concurrency-' . wp_generate_password(10, false) . '.log';
    file_put_contents($path, '');
    update_option('treda_qa_concurrency_log', $path);
    $id = wp_insert_post(array('post_title' => 'Newer concurrent article', 'post_status' => 'publish', 'comment_status' => 'open', 'post_date' => '2020-01-01 12:00:00', 'post_date_gmt' => '2020-01-01 12:00:00'));
    update_option('treda_qa_concurrency_post', $id);
    if ($treda_qa_mode === 'prepare-order') {
        wp_insert_post(array('post_title' => 'Older concurrent article', 'post_status' => 'publish', 'post_date' => '2010-01-01 12:00:00', 'post_date_gmt' => '2010-01-01 12:00:00'));
    }
    WP_CLI::success('Outgoing concurrency jobs prepared.');
} elseif ($treda_qa_mode === 'prepare-import') {
    $post_id = get_option('treda_qa_concurrency_post');
    Treda_Messages::save(array('chat_id' => '-1002222222222', 'message_id' => 70000, 'post_id' => $post_id, 'origin' => 'root'));
    $message = array('message_id' => 70001, 'date' => time(), 'chat' => array('id' => -1002222222222),
        'from' => array('id' => 5555, 'is_bot' => false, 'first_name' => 'Parallel sender'),
        'text' => 'One import despite two workers', 'reply_to_message' => array('message_id' => 70000));
    Treda_Queue::event(array('update_id' => 9000001, 'message' => $message));
    Treda_Queue::event(array('update_id' => 9000002, 'message' => $message));
    WP_CLI::success('Two incoming jobs for one message prepared.');
} elseif ($treda_qa_mode === 'verify-order') {
    $lines = file($treda_qa_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $incomplete = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status<>'done'", Treda_Queue::table()));
    if ($lines !== array('Older concurrent article', 'Newer concurrent article') || $incomplete !== 0) {
        WP_CLI::error('Concurrent chronological sending failed.');
    }
    WP_CLI::success('Two workers sent two distinct articles oldest first, without duplicates or overtaking.');
} elseif ($treda_qa_mode === 'verify') {
    $lines = file($treda_qa_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $post_id = get_option('treda_qa_concurrency_post');
    $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE comment_post_ID=%d AND comment_content=%s',
        $wpdb->comments, $post_id, 'One import despite two workers'));
    $incomplete = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status<>'done'", Treda_Queue::table()));
    if (count($lines) !== 1 || $count !== 1 || $incomplete !== 0) {
        WP_CLI::error('Concurrency failed: sends=' . count($lines) . ', imported=' . $count . ', incomplete=' . $incomplete);
    }
    WP_CLI::success('Two workers: one external send, one native imported comment, all jobs completed.');
} else {
    Treda_Queue::run();
}
