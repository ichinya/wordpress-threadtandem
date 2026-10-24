<?php
/**
 * Plugin Name: Treda
 * Description: Publish posts to Telegram and synchronize text comments with a linked discussion group.
 * Version: 1.0.2
 * Author: Ichi-nya
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: treda
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'TREDA_VERSION', '1.0.2' );
define( 'TREDA_SCHEMA_VERSION', '3' );
define( 'TREDA_DIR', plugin_dir_path( __FILE__ ) );

foreach ( array( 'settings', 'api', 'queue', 'publisher', 'editor', 'messages', 'comments', 'webhook', 'privacy', 'admin' ) as $treda_component ) {
	require_once TREDA_DIR . 'includes/class-treda-' . $treda_component . '.php';
}
unset( $treda_component );

register_activation_hook( __FILE__, array( 'Treda_Queue', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Treda_Queue', 'deactivate' ) );

add_action(
	'init',
	function () {
		// Support bundled translations for direct ZIP installs before a directory language pack exists.
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
		load_plugin_textdomain( 'treda', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		if ( get_option( 'treda_schema_version' ) !== TREDA_SCHEMA_VERSION ) {
			Treda_Queue::install();
		}
		if ( get_option( 'treda_schema_version' ) !== TREDA_SCHEMA_VERSION ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Treda could not create its database tables. Check the database permissions.', 'treda' ) . '</p></div>';
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
