<?php
/**
 * Plugin Name: ThreadTandem — Post Publishing and Comment Sync for Telegram
 * Description: Publish posts to Telegram and synchronize text comments with a linked discussion group.
 * Version: 1.1.0
 * Author: Ichi-nya
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: threadtandem-sync-for-telegram
 */

defined( 'ABSPATH' ) || exit;

register_activation_hook(
	__FILE__,
	function ( $network_wide = false ) {
		if ( $network_wide ) {
			Treda_Queue::activate( true );
		}
		$previous = array( 'treda/treda.php', 'treda/threadtandem-sync-for-telegram.php' );
		foreach ( $previous as $plugin ) {
			if ( $plugin !== plugin_basename( __FILE__ ) && is_plugin_active_for_network( $plugin ) ) {
				wp_die( esc_html__( 'Deactivate the previous plugin network-wide before activating ThreadTandem on this site.', 'threadtandem-sync-for-telegram' ) );
			}
		}
		Treda_Queue::activate();
		foreach ( $previous as $plugin ) {
			if ( $plugin !== plugin_basename( __FILE__ ) && is_plugin_active( $plugin ) ) {
				deactivate_plugins( $plugin, false, false );
			}
		}
		Treda_Queue::hooks();
	}
);
register_deactivation_hook( __FILE__, array( 'Treda_Queue', 'deactivate' ) );

// The earlier plugin copy can be loaded during activation of the renamed package.
if ( defined( 'TREDA_VERSION' ) ) {
	return;
}

// Keep storage identifiers and the existing webhook route compatible with earlier releases.
define( 'TREDA_VERSION', '1.1.0' );
define( 'TREDA_SCHEMA_VERSION', '3' );
define( 'TREDA_DIR', plugin_dir_path( __FILE__ ) );
define( 'TREDA_FILE', __FILE__ );

foreach ( array( 'settings', 'api', 'queue', 'publisher', 'editor', 'messages', 'comments', 'webhook', 'privacy', 'admin' ) as $treda_component ) {
	require_once TREDA_DIR . 'includes/class-treda-' . $treda_component . '.php';
}
unset( $treda_component );

add_action(
	'init',
	function () {
		if ( get_option( 'treda_schema_version' ) !== TREDA_SCHEMA_VERSION ) {
			Treda_Queue::install();
		}
		if ( get_option( 'treda_schema_version' ) !== TREDA_SCHEMA_VERSION ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'ThreadTandem could not create its database tables. Check the database permissions.', 'threadtandem-sync-for-telegram' ) . '</p></div>';
				}
			);
			return;
		}
		Treda_Queue::hooks();
		Treda_Publisher::hooks();
		Treda_Editor::hooks();
		Treda_Comments::hooks();
		Treda_Webhook::hooks();
		Treda_Privacy::hooks();
		if ( is_admin() ) {
			Treda_Admin::hooks();
		}
	}
);
