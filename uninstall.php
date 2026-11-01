<?php
/** Remove integration data only when the site owner explicitly selected cleanup. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function threadtandem_uninstall_site() {
	global $wpdb;
	$settings = get_option( 'threadtandem_settings', array() );
	if ( ! array_key_exists( 'retain_data', $settings ) || ! empty( $settings['retain_data'] ) ) {
		return;
	}
	wp_clear_scheduled_hook( 'threadtandem_tick' );
	foreach ( array( 'threadtandem_jobs', 'threadtandem_messages' ) as $suffix ) {
		// Integration tables have no core API; names come solely from the trusted site prefix.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicitly opted-in removal of plugin-owned tables on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $suffix ) );
	}
	foreach ( array( '_threadtandem_disabled', '_threadtandem_publication', '_threadtandem_message_key', '_threadtandem_discussion', '_threadtandem_sync_pending', '_threadtandem_sync_ignored', '_threadtandem_import_hash', '_threadtandem_import_detached' ) as $key ) {
		delete_post_meta_by_key( $key );
	}
	foreach ( array( '_threadtandem_origin', '_threadtandem_sender_id', '_threadtandem_sender_type', '_threadtandem_message_id', '_threadtandem_chat_id' ) as $key ) {
		delete_metadata( 'comment', 0, $key, '', true );
	}
	foreach ( array( 'threadtandem_settings', 'threadtandem_connection', 'threadtandem_schema_version', 'threadtandem_webhook_secret', 'threadtandem_import_author', 'threadtandem_updates_state', 'threadtandem_identifiers_migrated', 'threadtandem_webhook_upgrade' ) as $key ) {
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
	) as $threadtandem_blog_id ) {
		switch_to_blog( $threadtandem_blog_id );
		threadtandem_uninstall_site();
		restore_current_blog();
	}
} else {
	threadtandem_uninstall_site();
}
