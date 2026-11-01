<?php
/** Real uninstall and reinstallation checks, disposable sandbox only. */
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !get_option('threadtandem_qa_sandbox')) { exit('Disposable sandbox required.'); }
global $wpdb, $threadtandem_qa_count;
$threadtandem_qa_count = 0;
function threadtandem_lifecycle_expect($condition, $message) {
    global $threadtandem_qa_count;
    if (!$condition) { WP_CLI::error($message); }
    $threadtandem_qa_count++;
}
$settings = ThreadTandem_Settings::get();
$settings['retain_data'] = 1;
update_option('threadtandem_settings', $settings);
update_option('threadtandem_updates_state', array('scope'=>'qa-only','offset'=>123), false);
$posts_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$comments_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}");
ThreadTandem_Queue::deactivate();
threadtandem_lifecycle_expect(!wp_next_scheduled('threadtandem_tick'), 'Deactivation must clear its scheduled worker');
threadtandem_lifecycle_expect($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(ThreadTandem_Queue::table()))) === ThreadTandem_Queue::table(), 'Deactivation must preserve queue data');
define('WP_UNINSTALL_PLUGIN', plugin_basename(THREADTANDEM_FILE));
require THREADTANDEM_DIR . 'uninstall.php';
threadtandem_lifecycle_expect((bool) get_option('threadtandem_settings'), 'Default uninstall must keep settings');
threadtandem_lifecycle_expect((bool) get_option('threadtandem_updates_state'), 'Default uninstall must keep the polling cursor');
threadtandem_lifecycle_expect($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(ThreadTandem_Messages::table()))) === ThreadTandem_Messages::table(), 'Default uninstall must preserve mappings');
$settings['retain_data'] = 0;
update_option('threadtandem_settings', $settings);
threadtandem_uninstall_site();
threadtandem_lifecycle_expect(!get_option('threadtandem_settings') && !get_option('threadtandem_webhook_secret'), 'Opted-in cleanup must remove options and secrets');
threadtandem_lifecycle_expect(!get_option('threadtandem_updates_state'), 'Opted-in cleanup must remove polling state');
threadtandem_lifecycle_expect(!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(ThreadTandem_Queue::table()))), 'Opted-in cleanup must remove queue table');
threadtandem_lifecycle_expect(!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(ThreadTandem_Messages::table()))), 'Opted-in cleanup must remove mapping table');
threadtandem_lifecycle_expect((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}") === $posts_before, 'Cleanup must retain native posts');
threadtandem_lifecycle_expect((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}") === $comments_before, 'Cleanup must retain native comments');
threadtandem_lifecycle_expect((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key LIKE '\\_threadtandem\\_%'") === 0, 'Cleanup must remove plugin comment metadata');
ThreadTandem_Queue::install();
ThreadTandem_Queue::hooks();
threadtandem_lifecycle_expect(get_option('threadtandem_schema_version') === THREADTANDEM_SCHEMA_VERSION && wp_next_scheduled('threadtandem_tick'), 'Reinstall must create schema and schedule a worker');
WP_CLI::success($threadtandem_qa_count . ' lifecycle checks passed.');
