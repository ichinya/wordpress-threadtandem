<?php
/** Isolated business-logic tests. No WordPress bootstrap, network or site DB. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ABSPATH', dirname(__DIR__, 4) . '/');
define('DAY_IN_SECONDS', 86400);

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code, $message, $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_Post {
    public $ID = 1;
    public $post_type = 'post';
    public $post_status = 'publish';
    public $post_password = '';
    public $post_title = 'Статья & <script>скрипт</script>';
    public $post_excerpt = 'Короткий анонс';
    public $post_content = 'Полный текст';
}
class WP_REST_Response {
    public $data;
    public $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}
class Request {
    public $secret = '';
    public $data = array('update_id' => 1);
    public $body = null;
    public function get_header($name) { return $this->secret; }
    public function get_body() { return $this->body ?? json_encode($this->data); }
    public function get_json_params() { return $this->data; }
}
class EventStorageDouble {
    public $prefix = 'wp_';
    public $result = 1;
    public $writes = 0;
    public function prepare($sql, ...$args) { return $sql; }
    public function query($sql) { $this->writes++; return $this->result; }
    public function get_var($sql) { return '1'; }
}
// These cases exercise text publishing; real HTML/photo handling is covered in posters.php.
class WP_HTML_Tag_Processor {
    public function __construct($html) {}
    public function next_tag($tag) { return false; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return esc_html($text); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function add_option($key, $value, $unused = '', $autoload = null) {
    if (array_key_exists($key, $GLOBALS['options'])) { return false; }
    return update_option($key, $value);
}
function wp_parse_args($values, $defaults) { return array_merge($defaults, $values); }
function wp_parse_url($url) { return parse_url($url); }
function wp_generate_password($length, $special = true, $extra = false) { return str_repeat('s', $length); }
function rest_url($route) { return 'https://site.example/wp-json/' . $route; }
function esc_url_raw($url, $protocols = null) { return $url; }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function add_settings_error($setting, $code, $message) { $GLOBALS['setting_errors'][] = $code; }
function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_UNICODE); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_check_invalid_utf8($text) { return $text; }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function switch_to_locale($locale) { return false; }
function restore_previous_locale() { return false; }
function get_current_user_id() { return 1; }
function get_post_thumbnail_id($id) { return 0; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); return true; }
function strip_shortcodes($text) { return preg_replace('/\[[^\]]*\]/', '', $text); }
function wp_html_excerpt($text, $length, $more) {
    preg_match_all('/./us', strip_tags($text), $characters);
    return implode('', array_slice($characters[0], 0, $length)) . (count($characters[0]) > $length ? $more : '');
}
function get_permalink($post) { return 'https://site.example/post/' . $post->ID . '/'; }
function get_post($value) { return $value instanceof WP_Post ? $value : ($GLOBALS['posts'][$value] ?? null); }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) {
    if (!empty($GLOBALS['fail_meta'])) { return false; }
    $GLOBALS['meta'][$id][$key] = $value; return true;
}
function get_posts($args) {
    foreach ($GLOBALS['posts'] as $post) {
        if (get_post_meta($post->ID, $args['meta_key'], true) === $args['meta_value']) { return array($post); }
    }
    return array();
}
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function response($result, $code = 200) { return array('code' => $code, 'body' => json_encode(array('ok' => true, 'result' => $result))); }
function wp_safe_remote_post($url, $args) {
    $GLOBALS['requests'][] = array($url, $args);
    if ($GLOBALS['responses']) { return array_shift($GLOBALS['responses']); }
    $method = substr($url, strrpos($url, '/') + 1);
    $results = array(
        'getMe' => array('id' => 1234, 'username' => 'treda_test_bot'),
        'getChat' => array('id' => -1001234567890, 'type' => 'channel', 'title' => 'Блог', 'linked_chat_id' => -1009876543210),
        'getChatMember' => array('status' => 'administrator', 'can_post_messages' => true),
        'getWebhookInfo' => array('url' => ''),
        'sendMessage' => array('message_id' => 42),
        'editMessageText' => array('message_id' => 42),
        'setWebhook' => true,
    );
    return response($results[$method]);
}
foreach (array('settings', 'api', 'queue', 'media', 'publisher', 'messages', 'comments', 'sync', 'webhook') as $component) {
    require dirname(__DIR__) . '/includes/class-treda-' . $component . '.php';
}

$count = 0;
function expect($condition, $label) {
    $GLOBALS['count']++;
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
}
function state($error) { return is_wp_error($error) ? ($error->get_error_data()['state'] ?? '') : ''; }
function reset_fixture() {
    $GLOBALS['options'] = array('treda_settings' => array('token' => '1234:' . str_repeat('a', 30),
        'chat_id' => '@test_channel', 'api_url' => 'https://api.telegram.org', 'enabled' => 1));
    $GLOBALS['meta'] = array();
    $GLOBALS['posts'] = array(1 => new WP_Post());
    $GLOBALS['responses'] = array();
    $GLOBALS['requests'] = array();
    $GLOBALS['fail_meta'] = false;
    $GLOBALS['setting_errors'] = array();
    $GLOBALS['wpdb'] = new EventStorageDouble();
    Treda_API::check();
    $GLOBALS['requests'] = array();
}
function payload() { return array('post_id' => 1, 'connection' => Treda_Settings::fingerprint()); }

reset_fixture();
expect(Treda_Settings::ready(), 'Bot and numeric destination verified');
expect(Treda_Settings::connection()['chat_id'] === '-1001234567890', 'Resolved stable ID is stored');
$old = Treda_Settings::get();
$saved = Treda_Settings::sanitize(array('token' => '', 'chat_id' => '@test_channel', 'api_url' => 'https://api.telegram.org'));
expect($saved['token'] === $old['token'], 'Blank password field preserves token');
$saved = Treda_Settings::sanitize(array('token' => 'broken', 'chat_id' => '@test_channel', 'api_url' => 'http://bad.example'));
expect($saved['token'] === $old['token'] && $saved['api_url'] === $old['api_url'], 'Invalid credentials and HTTP API rejected');
foreach (array('https://user:pass@api.example', 'https://api.example?token=x', 'https://api.example/#fragment') as $url) {
    $saved = Treda_Settings::sanitize(array('chat_id' => '@test_channel', 'api_url' => $url));
    expect($saved['api_url'] === $old['api_url'], 'Unsafe API URL does not replace saved endpoint');
}
expect(Treda_Settings::sanitize(array('clear_token' => 1))['token'] === '', 'Explicit token deletion');

foreach (array('@ichi_dev', 'https://t.me/ichi_dev', 'https://t.me/ichi_dev/', 'http://t.me/ichi_dev', 't.me/ichi_dev', " \thttps://t.me/ichi_dev/\r\n", 'HTTPS://T.ME/ichi_dev') as $chat) {
    $GLOBALS['setting_errors'] = array();
    $saved = Treda_Settings::sanitize(array('chat_id' => $chat));
    expect($saved['chat_id'] === '@ichi_dev' && !in_array('chat', $GLOBALS['setting_errors'], true), 'Public channel link and handle have the same saved destination: ' . trim($chat));
}
foreach (array('', '-1001234567890', '@Existing_Name') as $chat) {
    $GLOBALS['setting_errors'] = array();
    $saved = Treda_Settings::sanitize(array('chat_id' => $chat));
    expect($saved['chat_id'] === $chat && !in_array('chat', $GLOBALS['setting_errors'], true), 'Existing channel formats remain supported: ' . $chat);
}
foreach (array('https://t.me.evil.example/ichi_dev', 'https://evil.example/t.me/ichi_dev', 'https://t.me@evil.example/ichi_dev', 'https://evil.example@t.me/ichi_dev', 'https://t.me:443/ichi_dev', '//t.me/ichi_dev', 'ftp://t.me/ichi_dev', 'https://t.me/ichi_dev/123', 'https://t.me/c/123456/1', 'https://t.me/+invite_hash', 'https://t.me/joinchat/invite_hash', 'https://t.me/ichi_dev?start=value', 'https://t.me/ichi_dev#fragment', 'https://t.me/ichi-dev', 'https://t.me/abcd', 'https://t.me/ichi%5Fdev', 'https://t.me/ichi_dev/extra', 'https://t.me/ichi_dev https://evil.example') as $chat) {
    $GLOBALS['setting_errors'] = array();
    $saved = Treda_Settings::sanitize(array('chat_id' => $chat));
    expect($saved['chat_id'] === $old['chat_id'] && in_array('chat', $GLOBALS['setting_errors'], true), 'Invalid channel URL is rejected and preserves the saved destination: ' . $chat);
}
// Equivalent input must not invalidate a verified connection or its already queued jobs.
$GLOBALS['options']['treda_settings']['chat_id'] = '@ichi_dev';
Treda_API::check();
$fingerprint = Treda_Settings::fingerprint();
$saved = Treda_Settings::sanitize(array('chat_id' => 'https://t.me/ichi_dev', 'enabled' => 1));
update_option('treda_settings', $saved);
expect(Treda_Settings::fingerprint() === $fingerprint && Treda_Settings::ready(), 'Saving the equivalent URL preserves the verified connection fingerprint');
$GLOBALS['requests'] = array();
Treda_API::check();
$channel_request = json_decode($GLOBALS['requests'][1][1]['body'], true);
expect($channel_request['chat_id'] === '@ichi_dev', 'Bot API receives the normalized username instead of the submitted URL');
reset_fixture();

$connection_steps = array('Bot token check' => 0, 'Channel access' => 1, 'Channel permissions' => 2, 'Discussion group access' => 3);
foreach ($connection_steps as $step => $successes) {
    reset_fixture();
    $GLOBALS['options']['treda_settings']['comments_enabled'] = 1;
    $responses = array(response(array('id' => 1234, 'username' => 'treda_test_bot')),
        response(array('id' => -1001234567890, 'type' => 'channel', 'linked_chat_id' => -1009876543210)),
        response(array('status' => 'administrator', 'can_post_messages' => true)));
    $GLOBALS['responses'] = array_slice($responses, 0, $successes);
    $GLOBALS['responses'][] = array('code' => 403, 'body' => json_encode(array('ok' => false, 'error_code' => 403, 'description' => 'Forbidden private response ' . Treda_Settings::token())));
    $error = Treda_API::check();
    expect(is_wp_error($error) && strpos($error->get_error_message(), $step . ':') === 0, 'Connection error identifies the failed step: ' . $step);
    expect($error->get_error_code() === 'treda_api' && state($error) === 'failed' && !Treda_Settings::connection(), 'Context preserves failure semantics and never verifies a failed connection: ' . $step);
    expect(strpos($error->get_error_message(), Treda_Settings::token()) === false && (!$successes || strpos($error->get_error_message(), '@treda_test_bot') !== false), 'Context shows bot identity without leaking credentials: ' . $step);
}
reset_fixture();
$GLOBALS['responses'][] = response(array('id' => 1234, 'username' => 'treda_test_bot'));
$GLOBALS['responses'][] = array('code' => 429, 'body' => json_encode(array('ok' => false, 'error_code' => 429, 'parameters' => array('retry_after' => 90))));
$error = Treda_API::check();
expect($error->get_error_code() === 'treda_rate_limit' && state($error) === 'pending' && $error->get_error_data()['delay'] === 90, 'Connection context retains retry delays for the queue worker');
reset_fixture();

$post = $GLOBALS['posts'][1];
expect(Treda_Publisher::eligible($post), 'Public post eligible');
foreach (array('draft', 'private', 'future', 'trash') as $status) {
    $post->post_status = $status;
    expect(!Treda_Publisher::eligible($post), 'Non-public status excluded: ' . $status);
}
$post->post_status = 'publish';
$post->post_password = 'secret';
expect(!Treda_Publisher::eligible($post), 'Password-protected post excluded');
$post->post_password = '';
update_post_meta(1, '_treda_disabled', '1');
expect(!Treda_Publisher::eligible($post), 'Per-post opt-out honored');
update_post_meta(1, '_treda_disabled', '0');
expect(Treda_Publisher::publish(payload()) === true, 'Initial publication succeeds');
expect(count($GLOBALS['requests']) === 1 && strpos($GLOBALS['requests'][0][0], '/sendMessage') !== false, 'Single initial send');
expect(get_post_meta(1, '_treda_publication', true)['message_id'] === 42, 'Message mapping persisted');
$body = json_decode($GLOBALS['requests'][0][1]['body'], true);
expect(strpos($body['text'], '<script>') === false && strpos($body['text'], "\n\n") !== false, 'Announcement has plain input and real line breaks');
expect(!isset($body['parse_mode']) && strpos($body['text'], '<b>') === false && strpos($body['text'], '<a ') === false && strpos($body['text'], 'Статья & ') === 0, 'Announcement sends plain text without HTML escaping or formatting');
expect($body['link_preview_options'] === array('is_disabled' => false, 'url' => 'https://site.example/post/1/', 'prefer_large_media' => true, 'show_above_text' => false) && substr($body['text'], -strlen('https://site.example/post/1/')) === 'https://site.example/post/1/', 'Preview explicitly targets the visible article URL below the announcement');
expect(Treda_Publisher::publish(payload()) === true && count($GLOBALS['requests']) === 1, 'Unchanged export causes no network request');
unset($GLOBALS['meta'][1]['_treda_message_key']);
Treda_Publisher::publish(payload());
expect(get_post_meta(1, '_treda_message_key', true) === '1234:-1001234567890:42' && count($GLOBALS['requests']) === 1, 'Missing discussion index repaired without duplicate send');
$post->post_excerpt = 'Изменённый анонс';
expect(Treda_Publisher::publish(payload()) === true && count($GLOBALS['requests']) === 2, 'Changed excerpt exported');
expect(strpos($GLOBALS['requests'][1][0], '/editMessageText') !== false, 'Existing message edited');
expect(json_decode($GLOBALS['requests'][1][1]['body'], true)['message_id'] === 42, 'Edit targets original message');
$post->post_status = 'draft';
Treda_Publisher::publish(payload());
expect(count($GLOBALS['requests']) === 2, 'Unpublished queued post not exported');
$post->post_status = 'publish';
$post->post_excerpt = 'Повторная публикация';
Treda_Publisher::publish(payload());
expect(strpos($GLOBALS['requests'][2][0], '/editMessageText') !== false, 'Republished post reuses mapping');

reset_fixture();
$GLOBALS['responses'][] = new WP_Error('http_request_failed', 'URL contains token ' . Treda_Settings::token());
$result = Treda_Publisher::publish(payload());
expect(state($result) === 'uncertain', 'Ambiguous initial send never marked for automatic retry');
expect(strpos($result->get_error_message(), Treda_Settings::token()) === false, 'Transport error redacts token');
expect(get_post_meta(1, '_treda_publication', true) === '', 'Unconfirmed result creates no fake mapping');
$GLOBALS['responses'][] = array('code' => 429, 'body' => json_encode(array('ok' => false, 'error_code' => 429, 'parameters' => array('retry_after' => 90))));
$result = Treda_API::call('sendMessage', array(), true);
expect(state($result) === 'pending' && $result->get_error_data()['delay'] === 90, 'Explicit rate limit is safely retryable after delay');
$GLOBALS['responses'][] = array('code' => 502, 'body' => 'bad gateway');
expect(state(Treda_API::call('sendMessage', array(), true)) === 'uncertain', 'Gateway failure on send is ambiguous');
$GLOBALS['responses'][] = array('code' => 502, 'body' => 'bad gateway');
expect(state(Treda_API::call('editMessageText')) === 'pending', 'Editing by known message ID may be retried');
$GLOBALS['responses'][] = array('code' => 400, 'body' => json_encode(array('ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified')));
expect(Treda_API::call('editMessageText') === true, 'Already-applied edit considered success');

reset_fixture();
$GLOBALS['responses'][] = array('code' => 200, 'body' => '{invalid');
expect(state(Treda_API::call('sendMessage', array(), true)) === 'uncertain', 'Malformed send response is ambiguous');
$GLOBALS['responses'][] = response(array('url' => 'https://other.example/webhook'));
$result = Treda_API::register_webhook();
expect(is_wp_error($result) && $result->get_error_code() === 'treda_conflict', 'Existing foreign webhook protected');
expect(count($GLOBALS['requests']) === 2, 'No setWebhook request on conflict');

reset_fixture();
$GLOBALS['fail_meta'] = true;
expect(state(Treda_Publisher::publish(payload())) === 'uncertain', 'Accepted send with failed local mapping is not retried automatically');

reset_fixture();
$stale = payload();
$GLOBALS['options']['treda_settings']['token'] = '1234:' . str_repeat('b', 30);
expect(!Treda_Settings::ready(), 'Changed credentials invalidate verification');
$result = Treda_Publisher::publish($stale);
expect(state($result) === 'failed' && $result->get_error_code() === 'treda_connection_changed' && !$GLOBALS['requests'], 'Changed credentials fail visibly without using the obsolete queued connection');
expect(strpos($result->get_error_message(), Treda_Settings::token()) === false, 'Connection-change diagnostic never includes credentials');
$GLOBALS['wpdb'] = new class extends EventStorageDouble {
    public $prepared_args = array();
    public function prepare($sql, ...$args) { $this->prepared_args = $args; return $sql; }
};
$finish = new ReflectionMethod(Treda_Queue::class, 'finish');
$finish->setAccessible(true);
$finish->invoke(null, (object) array('kind' => 'post', 'revision' => 1, 'attempts' => 1, 'id' => 1), 'test-lock', $result);
expect($GLOBALS['wpdb']->prepared_args[2] === 'failed' && $GLOBALS['wpdb']->prepared_args[3] === 'failed' && $GLOBALS['wpdb']->prepared_args[4] === $result->get_error_message(), 'Queue persists changed connection as a failed job with its diagnostic instead of Done');
foreach (array('chat_id' => '@another_channel', 'api_url' => 'https://bot-api.example', 'comments_enabled' => 1) as $key => $value) {
    reset_fixture();
    $stale = payload();
    $GLOBALS['options']['treda_settings'][$key] = $value;
    $result = Treda_Publisher::publish($stale);
    expect(state($result) === 'failed' && $result->get_error_code() === 'treda_connection_changed' && !$GLOBALS['requests'] && get_post_meta(1, '_treda_publication', true) === '', 'Changed ' . $key . ' cannot report successful delivery or retarget queued posts');
}
reset_fixture();
$result = Treda_Publisher::publish(array('post_id' => 1));
expect(state($result) === 'failed' && !$GLOBALS['requests'], 'Missing queued connection is reported instead of successful delivery');
expect(Treda_Publisher::publish(payload()) === true && count($GLOBALS['requests']) === 1 && get_post_meta(1, '_treda_publication', true)['message_id'] === 42, 'Explicitly queued current connection can still publish and save the mapping');
reset_fixture();
Treda_Publisher::publish(payload());
$binding = get_post_meta(1, '_treda_publication', true);
$binding['chat_id'] = '-1005555555555';
update_post_meta(1, '_treda_publication', $binding);
expect(state(Treda_Publisher::publish(payload())) === 'failed', 'Existing binding cannot silently move to a different channel');

reset_fixture();
$request = new Request();
expect(Treda_Webhook::authorize($request)->get_error_data()['status'] === 403, 'Empty secret denied');
$request->secret = 'wrong';
expect(is_wp_error(Treda_Webhook::authorize($request)), 'Wrong secret denied');
$request->secret = Treda_Settings::secret();
expect(Treda_Webhook::authorize($request) === true, 'Correct secret accepted');
$request->data['update_id'] = '1';
expect(Treda_Webhook::receive($request)->get_error_data()['status'] === 400, 'Noninteger update ID rejected');
$request->data['update_id'] = 1;
$result = Treda_Webhook::receive($request);
expect($result->status === 200 && $GLOBALS['wpdb']->writes === 0, 'Unrelated messages not stored');
$request->data['message'] = array('is_automatic_forward' => true, 'message_id' => 73, 'date' => time(),
    'chat' => array('id' => -1009876543210), 'forward_origin' => array('type' => 'channel',
    'chat' => array('id' => -1001234567890), 'message_id' => 42));
$GLOBALS['wpdb']->result = false;
expect(Treda_Webhook::receive($request)->get_error_data()['status'] === 503, 'Storage failure not acknowledged');
$GLOBALS['wpdb']->result = 0;
expect(Treda_Webhook::receive($request)->status === 200, 'Durably existing duplicate acknowledged');
$request->body = str_repeat('x', 262145);
expect(Treda_Webhook::receive($request)->get_error_data()['status'] === 413, 'Oversized payload rejected');
// Exact discussion and comment mappings are covered with a real DB in integration.php.

echo "PASS: $count checks (isolated WordPress and HTTP doubles; no live DB or Telegram).\n";
