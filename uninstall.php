<?php
/** Remove integration data only when the site owner explicitly selected cleanup. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function treda_uninstall_site() {
	global $wpdb;
	$settings = get_option( 'treda_settings', array() );
	if ( ! array_key_exists( 'retain_data', $settings ) || ! empty( $settings['retain_data'] ) ) {
		return;
	}
	wp_clear_scheduled_hook( 'treda_tick' );
	foreach ( array( 'treda_jobs', 'treda_messages' ) as $suffix ) {
		// Integration tables have no core API; names come solely from the trusted site prefix.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicitly opted-in removal of plugin-owned tables on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $suffix ) );
	}
	foreach ( array( '_treda_disabled', '_treda_publication', '_treda_message_key', '_treda_discussion', '_treda_sync_pending', '_treda_sync_ignored', '_treda_import_hash', '_treda_import_detached' ) as $key ) {
		delete_post_meta_by_key( $key );
	}
	foreach ( array( '_treda_origin', '_treda_sender_id', '_treda_sender_type', '_treda_message_id', '_treda_chat_id' ) as $key ) {
		delete_metadata( 'comment', 0, $key, '', true );
	}
	foreach ( array( 'treda_settings', 'treda_connection', 'treda_schema_version', 'treda_webhook_secret', 'treda_import_author' ) as $key ) {
		delete_option( $key );
	}
	// Core posts and comments, including imported text, remain site content.
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $treda_blog_id ) {
		switch_to_blog( $treda_blog_id );
		treda_uninstall_site();
		restore_current_blog();
	}
} else {
	treda_uninstall_site();
}
