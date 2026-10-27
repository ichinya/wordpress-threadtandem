<?php
/** Real uninstall and reinstallation checks, disposable sandbox only. */
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !get_option('treda_qa_sandbox')) { exit('Disposable sandbox required.'); }
global $wpdb, $treda_qa_count;
$treda_qa_count = 0;
function treda_lifecycle_expect($condition, $message) {
    global $treda_qa_count;
    if (!$condition) { WP_CLI::error($message); }
    $treda_qa_count++;
}
$settings = Treda_Settings::get();
$settings['retain_data'] = 1;
update_option('treda_settings', $settings);
$posts_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$comments_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}");
Treda_Queue::deactivate();
treda_lifecycle_expect(!wp_next_scheduled('treda_tick'), 'Deactivation must clear its scheduled worker');
treda_lifecycle_expect($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(Treda_Queue::table()))) === Treda_Queue::table(), 'Deactivation must preserve queue data');
define('WP_UNINSTALL_PLUGIN', plugin_basename(TREDA_FILE));
require TREDA_DIR . 'uninstall.php';
treda_lifecycle_expect((bool) get_option('treda_settings'), 'Default uninstall must keep settings');
treda_lifecycle_expect($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(Treda_Messages::table()))) === Treda_Messages::table(), 'Default uninstall must preserve mappings');
$settings['retain_data'] = 0;
update_option('treda_settings', $settings);
treda_uninstall_site();
treda_lifecycle_expect(!get_option('treda_settings') && !get_option('treda_webhook_secret'), 'Opted-in cleanup must remove options and secrets');
treda_lifecycle_expect(!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(Treda_Queue::table()))), 'Opted-in cleanup must remove queue table');
treda_lifecycle_expect(!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(Treda_Messages::table()))), 'Opted-in cleanup must remove mapping table');
treda_lifecycle_expect((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}") === $posts_before, 'Cleanup must retain native posts');
treda_lifecycle_expect((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}") === $comments_before, 'Cleanup must retain native comments');
treda_lifecycle_expect((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key LIKE '\\_treda\\_%'") === 0, 'Cleanup must remove plugin comment metadata');
Treda_Queue::install();
Treda_Queue::hooks();
treda_lifecycle_expect(get_option('treda_schema_version') === TREDA_SCHEMA_VERSION && wp_next_scheduled('treda_tick'), 'Reinstall must create schema and schedule a worker');
WP_CLI::success($treda_qa_count . ' lifecycle checks passed.');
