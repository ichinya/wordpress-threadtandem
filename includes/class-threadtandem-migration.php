<?php
/** The previous name is used only here to upgrade existing installations safely. */
defined( 'ABSPATH' ) || exit;

// Prefix migration requires atomic table renames and uncached checks across old/new identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
final class ThreadTandem_Migration {
	public static function bootstrap() {
		if ( ! defined( 'THREADTANDEM_BOT_TOKEN' ) && defined( 'TREDA_BOT_TOKEN' ) ) {
			define( 'THREADTANDEM_BOT_TOKEN', TREDA_BOT_TOKEN );
		}
	}

	public static function previous_plugins() {
		return array( 'treda/treda.php', 'treda/threadtandem-sync-for-telegram.php' );
	}

	public static function webhook_url( $url ) {
		// Keep the host, query and secret configuration; change only the exact REST namespace.
		$url = preg_replace( '~/wp-json/treda/v1/webhook(?=\?|$)~', '/wp-json/threadtandem/v1/webhook', $url );
		return preg_replace( '~([?&]rest_route=)(?:/|%2F)treda(?:/|%2F)v1(?:/|%2F)webhook(?=&|$)~i', '${1}%2Fthreadtandem%2Fv1%2Fwebhook', $url );
	}

	public static function owns_webhook( $url, $current ) {
		return $url === $current || ( $url !== '' && self::webhook_url( $url ) === $current );
	}

	private static function error() {
		return new WP_Error( 'threadtandem_migration', __( 'ThreadTandem could not migrate the previous identifiers. Data was preserved. Wait for active jobs to finish, then check for conflicting tables or keys and retry.', 'threadtandem-sync-for-telegram' ) );
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	public static function run() {
		if ( get_option( 'threadtandem_identifiers_migrated' ) === '1' ) { return true; }
		$cron = (float) get_transient( 'doing_cron' );
		if ( $cron > microtime( true ) - 60 ) { return self::error(); }
		global $wpdb;
		$locks = array();
		$lock_names = array(
			'threadtandem_migrate_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 ),
			'tt_updates_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 ),
		);
		$connection = get_option( 'treda_connection', get_option( 'threadtandem_connection', array() ) );
		$identity = substr( hash( 'sha256', ( $connection['bot_id'] ?? '' ) . ':' . ( $connection['chat_id'] ?? '' ) ), 0, 50 );
		$lock_names[] = 'treda:' . $identity;
		$lock_names[] = 'threadtandem:' . $identity;
		$option_names = array(); $meta_ids = array(); $transaction = false;
		try {
			foreach ( $lock_names as $name ) {
				if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) !== '1' ) { return self::error(); }
				$locks[] = $name;
			}
			if ( get_option( 'threadtandem_identifiers_migrated' ) === '1' ) { return true; }
			$renames = array();
			foreach ( array( 'jobs', 'messages' ) as $suffix ) {
				$old = $wpdb->prefix . 'treda_' . $suffix;
				$new = $wpdb->prefix . 'threadtandem_' . $suffix;
				$exists = self::table_exists( $old );
				if ( $wpdb->last_error || ( $exists && self::table_exists( $new ) ) ) { return self::error(); }
				if ( $exists ) { $renames[] = $wpdb->prepare( '%i TO %i', $old, $new ); }
				if ( $suffix === 'jobs' && ( $exists || self::table_exists( $new ) ) ) {
					$active = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='running' AND locked_at>=%s", $exists ? $old : $new, gmdate( 'Y-m-d H:i:s', time() - 300 ) ) );
					if ( $wpdb->last_error || (int) $active > 0 ) { return self::error(); }
				}
			}
			$legacy_keys = false;
			foreach ( array( $wpdb->options => array( 'option_name', 'treda_' ), $wpdb->postmeta => array( 'meta_key', '_treda_' ), $wpdb->commentmeta => array( 'meta_key', '_treda_' ) ) as $table => $key ) {
				$found = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE %i LIKE %s LIMIT 1', $table, $key[0], $wpdb->esc_like( $key[1] ) . '%' ) );
				if ( $wpdb->last_error ) { return self::error(); }
				$legacy_keys = $legacy_keys || $found !== null;
			}
			if ( ! $renames && ! $legacy_keys && ! get_option( 'threadtandem_settings' ) && ! wp_next_scheduled( 'treda_tick' ) ) {
				// A fresh installation has no data to migrate and needs no metadata transaction.
				return update_option( 'threadtandem_identifiers_migrated', '1', false ) || get_option( 'threadtandem_identifiers_migrated' ) === '1' ? true : self::error();
			}
			// Core settings and metadata must support rollback before changing their keys.
			foreach ( array( $wpdb->options, $wpdb->postmeta, $wpdb->commentmeta ) as $table ) {
				$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name=%s', $table ) );
				if ( ! $status || strtolower( $status->Engine ) !== 'innodb' ) { return self::error(); }
			}
			// Detect collisions before DDL, so an independent newer installation is never overwritten.
			foreach ( array( 'treda_' => 'threadtandem_', '_transient_treda_' => '_transient_threadtandem_', '_transient_timeout_treda_' => '_transient_timeout_threadtandem_' ) as $old => $new ) {
				$conflict = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i AS a INNER JOIN %i AS b ON b.option_name=CONCAT(%s,SUBSTRING(a.option_name,%d)) WHERE a.option_name LIKE %s LIMIT 1', $wpdb->options, $wpdb->options, $new, strlen( $old ) + 1, $wpdb->esc_like( $old ) . '%' ) );
				if ( $conflict || $wpdb->last_error ) { return self::error(); }
			}
			foreach ( array( 'post' => $wpdb->postmeta, 'comment' => $wpdb->commentmeta ) as $type => $table ) {
				$column = $type . '_id';
				$conflict = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i AS a INNER JOIN %i AS b ON a.%i=b.%i AND b.meta_key=CONCAT(%s,SUBSTRING(a.meta_key,8)) WHERE a.meta_key LIKE %s LIMIT 1', $table, $table, $column, $column, '_threadtandem_', $wpdb->esc_like( '_treda_' ) . '%' ) );
				if ( $conflict || $wpdb->last_error ) { return self::error(); }
			}
			if ( $renames && false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { return self::error(); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each identifier pair is prepared above.
			// DDL commits independently. If later work fails, the next request resumes with the renamed tables.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error(); }
			$transaction = true;
			foreach ( array( 'treda_' => 'threadtandem_', '_transient_treda_' => '_transient_threadtandem_', '_transient_timeout_treda_' => '_transient_timeout_threadtandem_' ) as $old => $new ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_id,option_name,option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $old ) . '%' ) );
				if ( $wpdb->last_error ) { return self::error(); }
				foreach ( $rows as $row ) {
					$name = $new . substr( $row->option_name, strlen( $old ) );
					if ( $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name=%s', $wpdb->options, $name ) ) !== null || $wpdb->last_error ) { return self::error(); }
					$value = $row->option_value;
					if ( $name === 'threadtandem_settings' ) {
						$settings = maybe_unserialize( $value );
						if ( is_array( $settings ) && ! empty( $settings['webhook_url'] ) && is_string( $settings['webhook_url'] ) ) {
							$settings['webhook_url'] = self::webhook_url( $settings['webhook_url'] );
							$value = maybe_serialize( $settings );
						}
					}
					if ( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name=%s,option_value=%s WHERE option_id=%d', $wpdb->options, $name, $value, $row->option_id ) ) !== 1 ) { return self::error(); }
					$option_names[] = $row->option_name; $option_names[] = $name;
				}
			}
			foreach ( array( 'post' => $wpdb->postmeta, 'comment' => $wpdb->commentmeta ) as $type => $table ) {
				$column = $type . '_id';
				$conflict = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i AS a INNER JOIN %i AS b ON a.%i=b.%i AND b.meta_key=CONCAT(%s,SUBSTRING(a.meta_key,8)) WHERE a.meta_key LIKE %s LIMIT 1', $table, $table, $column, $column, '_threadtandem_', $wpdb->esc_like( '_treda_' ) . '%' ) );
				if ( $conflict || $wpdb->last_error ) { return self::error(); }
				$meta_ids[ $type ] = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT %i FROM %i WHERE meta_key LIKE %s', $column, $table, $wpdb->esc_like( '_treda_' ) . '%' ) );
				if ( $wpdb->last_error || false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_key=CONCAT(%s,SUBSTRING(meta_key,8)) WHERE meta_key LIKE %s', $table, '_threadtandem_', $wpdb->esc_like( '_treda_' ) . '%' ) ) ) { return self::error(); }
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { return self::error(); }
			$transaction = false;
			self::clear_caches( $option_names, $meta_ids );
			// Persist the registration repair before the completion marker, including resumed migrations.
			$settings = get_option( 'threadtandem_settings', array() );
			if ( $settings && ( $settings['updates_mode'] ?? 'webhook' ) === 'webhook' ) {
				if ( ! update_option( 'threadtandem_webhook_upgrade', 1, false ) && ! get_option( 'threadtandem_webhook_upgrade' ) ) { return self::error(); }
			}
			$cleared = wp_clear_scheduled_hook( 'treda_tick', array(), true );
			if ( is_wp_error( $cleared ) || $cleared === false ) { return self::error(); }
			if ( ! update_option( 'threadtandem_identifiers_migrated', '1', false ) && get_option( 'threadtandem_identifiers_migrated' ) !== '1' ) { return self::error(); }
			return true;
		} finally {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); }
			self::clear_caches( $option_names, $meta_ids );
			foreach ( array_reverse( $locks ) as $name ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
		}
	}

	private static function clear_caches( $names, $ids ) {
		if ( $names ) {
			wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
			foreach ( $names as $name ) { wp_cache_delete( $name, 'options' ); }
		}
		foreach ( $ids as $type => $values ) { foreach ( $values as $id ) { wp_cache_delete( $id, $type . '_meta' ); } }
	}

	public static function hooks() {
		// Old URLs remain authenticated aliases while an earlier webhook registration is replaced.
		add_action( 'rest_api_init', function () {
			register_rest_route( 'treda/v1', '/webhook', array( 'methods' => 'POST', 'permission_callback' => array( 'ThreadTandem_Webhook', 'authorize' ), 'callback' => array( 'ThreadTandem_Webhook', 'receive' ), 'show_in_index' => false ) );
		} );
		if ( is_admin() ) { add_action( 'admin_menu', array( __CLASS__, 'redirect_page' ), 99 ); }
	}

	public static function redirect_page() {
		global $pagenow;
		if ( $pagenow !== 'options-general.php' || ! current_user_can( 'manage_options' ) ) { return; }
		// These parameters only select an existing read-only admin view.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$legacy = array( 'treda' => 'threadtandem', 'treda-sync' => 'threadtandem-sync' );
		if ( ! isset( $legacy[ $page ] ) ) { return; }
		$args = array( 'page' => $legacy[ $page ] );
		foreach ( array( 'view', 's', 'paged', 'bulk' ) as $key ) { if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) { $args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); } }
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) ); exit;
	}
}
