<?php
/** Serialized incoming transport, with durable getUpdates cursors. */
defined( 'ABSPATH' ) || exit;

// MySQL advisory locks serialize pollers and transport switches across PHP processes.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Treda_Updates {
	private static $settings_lock = false;

	public static function hooks() {
		add_action( 'treda_tick', array( __CLASS__, 'tick' ), 5 );
		add_filter( 'pre_update_option_treda_settings', array( __CLASS__, 'before_settings' ), 10, 2 );
		add_action( 'update_option_treda_settings', array( __CLASS__, 'settings_changed' ), 10, 2 );
	}

	private static function lock_name() {
		global $wpdb;
		return 'tt_updates_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 );
	}

	private static function lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
	}

	private static function unlock() {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	private static function busy() {
		return new WP_Error( 'treda_updates_busy', __( 'An update receiver is already running. Try again shortly.', 'threadtandem-sync-for-telegram' ) );
	}

	private static function exclusive( $callback ) {
		if ( ! self::lock() ) { return self::busy(); }
		try { return $callback(); } finally { self::unlock(); }
	}

	/** Refuse a connection change while a poll is acknowledging this connection's events. */
	public static function before_settings( $new, $old ) {
		if ( ! is_array( $new ) || ! is_array( $old ) || ( ( $new['updates_mode'] ?? 'webhook' ) !== 'polling' && ( $old['updates_mode'] ?? 'webhook' ) !== 'polling' ) ) { return $new; }
		foreach ( array( 'updates_mode', 'token', 'api_url', 'chat_id', 'comments_enabled', 'import_enabled' ) as $key ) {
			if ( ( $new[ $key ] ?? null ) !== ( $old[ $key ] ?? null ) ) {
				if ( ! self::lock() ) {
					add_settings_error( 'treda', 'updates_busy', self::busy()->get_error_message() );
					return $old;
				}
				self::$settings_lock = true;
				break;
			}
		}
		return $new;
	}

	public static function settings_changed( $old, $new ) {
		try {
			if ( ( $old['updates_mode'] ?? 'webhook' ) !== ( $new['updates_mode'] ?? 'webhook' ) ) {
				// This callback already owns the same cross-process lock as polling.
				$result = self::$settings_lock ? self::connect_locked() : self::connect();
				if ( is_wp_error( $result ) ) { add_settings_error( 'treda', 'updates_connect', $result->get_error_message() ); }
			}
		} finally {
			if ( self::$settings_lock ) { self::$settings_lock = false; self::unlock(); }
		}
	}

	public static function state() {
		$scope = hash( 'sha256', Treda_Settings::token() . '|' . Treda_Settings::get()['api_url'] );
		$state = get_option( 'treda_updates_state', array() );
		if ( ! is_array( $state ) || ( $state['scope'] ?? '' ) !== $scope ) { $state = array(); }
		return wp_parse_args( $state, array( 'scope' => $scope, 'enabled' => true, 'offset' => 0, 'last_update' => 0, 'last_attempt' => 0, 'last_success' => 0, 'error' => '', 'retry_at' => 0 ) );
	}

	private static function save( $state ) {
		return update_option( 'treda_updates_state', $state, false ) || get_option( 'treda_updates_state' ) === $state;
	}

	private static function error( $error, $state ) {
		$state['error'] = $error->get_error_message();
		$data = $error->get_error_data();
		$state['retry_at'] = time() + max( 60, (int) ( $data['delay'] ?? 60 ) );
		self::save( $state );
		return $error;
	}

	public static function connect() {
		return self::exclusive( array( __CLASS__, 'connect_locked' ) );
	}

	private static function connect_locked() {
		$state = self::state();
		$state['enabled'] = true;
		$state['error'] = '';
		$state['retry_at'] = 0;
		if ( ! self::save( $state ) ) { return self::storage_error(); }
		if ( get_current_user_id() && current_user_can( 'edit_posts' ) ) {
			update_option( 'treda_import_author', get_current_user_id(), false );
		}
		if ( ! Treda_Settings::ready() ) {
			return self::error( new WP_Error( 'treda_connection', __( 'Check the connection first.', 'threadtandem-sync-for-telegram' ) ), $state );
		}
		$result = Treda_Settings::updates_mode() === 'polling' ? Treda_API::disconnect_webhook() : Treda_API::register_webhook();
		return is_wp_error( $result ) ? self::error( $result, $state ) : $result;
	}

	public static function disconnect() {
		return self::exclusive( function () {
			$state = self::state();
			$state['enabled'] = false;
			$state['error'] = '';
			if ( ! self::save( $state ) ) { return self::storage_error(); }
			$result = Treda_API::disconnect_webhook();
			return is_wp_error( $result ) ? self::error( $result, $state ) : $result;
		} );
	}

	public static function recover() {
		return self::exclusive( function () {
			return Treda_Settings::updates_mode() === 'polling' ? self::poll_locked() : Treda_API::recover_updates();
		} );
	}

	public static function tick() {
		if ( Treda_Settings::updates_mode() !== 'polling' || ! Treda_Settings::ready() ) { return; }
		$state = self::state();
		if ( ! $state['enabled'] || $state['retry_at'] > time() ) { return; }
		self::poll();
	}

	public static function poll() {
		return self::exclusive( array( __CLASS__, 'poll_locked' ) );
	}

	private static function storage_error() {
		return new WP_Error( 'treda_storage', __( 'Could not store the Telegram update.', 'threadtandem-sync-for-telegram' ) );
	}

	private static function poll_locked() {
		if ( Treda_Settings::updates_mode() !== 'polling' || ! Treda_Settings::ready() ) {
			return new WP_Error( 'treda_mode', __( 'Select cron polling and check the connection first.', 'threadtandem-sync-for-telegram' ) );
		}
		$state = self::state();
		if ( ! $state['enabled'] ) { return new WP_Error( 'treda_paused', __( 'Polling is paused. Start receiving events to resume it.', 'threadtandem-sync-for-telegram' ) ); }
		$state['last_attempt'] = time();
		if ( ! self::save( $state ) ) { return self::storage_error(); }
		try {
			// Never remove a webhook belonging to another integration, and never drop updates.
			$result = Treda_API::disconnect_webhook();
			if ( is_wp_error( $result ) ) { return self::error( $result, $state ); }
			// Telegram may choose a new, lower update ID after a week without updates.
			if ( $state['last_update'] && $state['last_update'] < time() - 7 * DAY_IN_SECONDS ) { $state['offset'] = 0; }
			$updates = Treda_API::call( 'getUpdates', array( 'offset' => (int) $state['offset'], 'limit' => 100, 'timeout' => 10, 'allowed_updates' => array( 'message', 'edited_message', 'channel_post', 'edited_channel_post' ) ) );
			if ( is_wp_error( $updates ) ) { return self::error( $updates, $state ); }
			if ( ! is_array( $updates ) ) { return self::error( new WP_Error( 'treda_update', __( 'Invalid Telegram update.', 'threadtandem-sync-for-telegram' ) ), $state ); }
			$previous = -1;
			foreach ( $updates as $update ) {
				if ( ! is_array( $update ) || ! isset( $update['update_id'] ) || ! is_int( $update['update_id'] ) || $update['update_id'] < (int) $state['offset'] || $update['update_id'] <= $previous ) {
					return self::error( new WP_Error( 'treda_update', __( 'Invalid Telegram update.', 'threadtandem-sync-for-telegram' ) ), $state );
				}
				$previous = $update['update_id'];
			}
			foreach ( $updates as $update ) {
				$request = new WP_REST_Request( 'POST', '/treda/v1/webhook' );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( wp_json_encode( $update ) );
				$accepted = Treda_Webhook::receive( $request );
				if ( is_wp_error( $accepted ) || $accepted->get_status() >= 300 ) {
					return self::error( is_wp_error( $accepted ) ? $accepted : self::storage_error(), $state );
				}
				// The next request acknowledges this ID only after its durable receipt (or intentional ignore).
				$state['offset'] = $update['update_id'] + 1;
				$state['last_update'] = time();
				if ( ! self::save( $state ) ) { return self::storage_error(); }
			}
			$state['last_success'] = time();
			$state['error'] = '';
			$state['retry_at'] = 0;
			return self::save( $state ) ? true : self::storage_error();
		} catch ( Throwable $error ) {
			return self::error( new WP_Error( 'treda_polling', __( 'Could not receive Telegram updates. Check diagnostics and try again.', 'threadtandem-sync-for-telegram' ) ), self::state() );
		}
	}
}
