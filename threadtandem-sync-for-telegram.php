<?php
/**
 * Plugin Name: ThreadTandem — Post Publishing and Comment Sync for Telegram
 * Description: Publish posts to Telegram and synchronize text comments with a linked discussion group.
 * Version: 1.3.0
 * Author: Ichi-nya
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: threadtandem-sync-for-telegram
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

register_activation_hook(
	__FILE__,
	function ( $network_wide = false ) {
		if ( $network_wide ) {
			ThreadTandem_Queue::activate( true );
		}
		$previous = ThreadTandem_Migration::previous_plugins();
		foreach ( $previous as $plugin ) {
			if ( $plugin !== plugin_basename( __FILE__ ) && is_plugin_active_for_network( $plugin ) ) {
				wp_die( esc_html__( 'Deactivate the previous plugin network-wide before activating ThreadTandem on this site.', 'threadtandem-sync-for-telegram' ) );
			}
		}
		$migrated = ThreadTandem_Migration::run();
		if ( is_wp_error( $migrated ) ) { wp_die( esc_html( $migrated->get_error_message() ) ); }
		ThreadTandem_Queue::activate();
		foreach ( $previous as $plugin ) {
			if ( $plugin !== plugin_basename( __FILE__ ) && is_plugin_active( $plugin ) ) {
				deactivate_plugins( $plugin, false, false );
			}
		}
		ThreadTandem_Queue::hooks();
	}
);
register_deactivation_hook( __FILE__, array( 'ThreadTandem_Queue', 'deactivate' ) );

// The earlier plugin copy can be loaded during activation of the renamed package.
if ( defined( 'THREADTANDEM_VERSION' ) ) {
	return;
}

// Upgrade the former identifiers before loading workers that use the canonical storage.
define( 'THREADTANDEM_VERSION', '1.3.0' );
define( 'THREADTANDEM_SCHEMA_VERSION', '3' );
define( 'THREADTANDEM_DIR', plugin_dir_path( __FILE__ ) );
define( 'THREADTANDEM_FILE', __FILE__ );

foreach ( array( 'migration', 'settings', 'api', 'queue', 'media', 'publisher', 'editor', 'messages', 'comments', 'sync', 'bulk', 'webhook', 'updates', 'privacy', 'admin' ) as $threadtandem_component ) {
	require_once THREADTANDEM_DIR . 'includes/class-threadtandem-' . $threadtandem_component . '.php';
}
unset( $threadtandem_component );
ThreadTandem_Migration::bootstrap();

add_action( 'change_locale', array( 'ThreadTandem_Settings', 'load_translations' ) );
add_action(
	'init',
	function () {
		// Bundled translations also support standalone installs and WordPress 6.2, without a downloaded language pack.
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- The bundled fallback is intentional.
		load_plugin_textdomain( 'threadtandem-sync-for-telegram', false, dirname( plugin_basename( THREADTANDEM_FILE ) ) . '/languages' );
		ThreadTandem_Settings::load_translations();
		$migrated = ThreadTandem_Migration::run();
		if ( is_wp_error( $migrated ) ) {
			add_action( 'admin_menu', function () use ( $migrated ) {
				add_options_page( 'ThreadTandem', 'ThreadTandem', 'manage_options', 'threadtandem', function () use ( $migrated ) {
					echo '<div class="wrap"><h1>ThreadTandem</h1><div class="notice notice-error"><p>' . esc_html( $migrated->get_error_message() ) . '</p></div></div>';
				} );
			} );
			return;
		}
		ThreadTandem_Migration::hooks();
		if ( get_option( 'threadtandem_schema_version' ) !== THREADTANDEM_SCHEMA_VERSION ) {
			ThreadTandem_Queue::install();
		}
		if ( get_option( 'threadtandem_schema_version' ) !== THREADTANDEM_SCHEMA_VERSION ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'ThreadTandem could not create its database tables. Check the database permissions.', 'threadtandem-sync-for-telegram' ) . '</p></div>';
				}
			);
			return;
		}
		ThreadTandem_Queue::hooks();
		ThreadTandem_Publisher::hooks();
		ThreadTandem_Editor::hooks();
		ThreadTandem_Comments::hooks();
		ThreadTandem_Webhook::hooks();
		ThreadTandem_Updates::hooks();
		ThreadTandem_Privacy::hooks();
		if ( is_admin() ) {
			ThreadTandem_Admin::hooks();
			ThreadTandem_Sync::hooks();
			ThreadTandem_Bulk::hooks();
		}
	}
);
