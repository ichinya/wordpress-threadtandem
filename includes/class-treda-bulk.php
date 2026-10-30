<?php
/** Explicitly confirmed bulk exports, prepared in bounded queue batches. */
defined( 'ABSPATH' ) || exit;

// Durable bulk cursors and queue transactions require uncached SQL.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Treda_Bulk {
	public static function hooks() {
		add_action( 'admin_post_treda_bulk', array( __CLASS__, 'action' ) );
	}

	private static function allowed() {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_others_posts' ) && current_user_can( 'publish_posts' );
	}

	private static function ready() {
		return Treda_Settings::ready() && ! empty( Treda_Settings::get()['enabled'] );
	}

	private static function preview_key() {
		return 'treda_bulk_preview_' . get_current_user_id();
	}

	public static function prepare() {
		global $wpdb;
		if ( ! self::allowed() || ! self::ready() ) { return false; }
		// Keep eligibility in sync with process(); archive selection ignores UI search and pagination.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total,MAX(p.ID) AS last_id FROM %i AS p
			 WHERE p.post_type='post' AND p.post_status='publish' AND p.post_password='' AND NOT EXISTS (
			 SELECT 1 FROM %i AS pm WHERE pm.post_id=p.ID
			 AND pm.meta_key IN ('_treda_disabled','_treda_sync_ignored','_treda_sync_pending','_treda_import_detached') AND pm.meta_value='1')",
			$wpdb->posts, $wpdb->postmeta
		) );
		if ( ! $row || ! $row->total ) { return false; }
		$preview = array( 'request' => wp_generate_uuid4(), 'total' => (int) $row->total, 'through' => (int) $row->last_id, 'connection' => Treda_Settings::fingerprint(), 'user_id' => get_current_user_id() );
		return set_transient( self::preview_key(), $preview, 10 * MINUTE_IN_SECONDS ) ? $preview : false;
	}

	public static function confirm( $request ) {
		if ( ! self::allowed() || ! self::ready() || ! is_string( $request ) ) { return false; }
		$preview = get_transient( self::preview_key() );
		if ( ! is_array( $preview ) || ! hash_equals( $preview['request'], $request ) || ! hash_equals( $preview['connection'], Treda_Settings::fingerprint() ) ) { return false; }
		$ok = Treda_Queue::bulk( $preview );
		if ( $ok ) { delete_transient( self::preview_key() ); }
		return $ok;
	}

	public static function action() {
		if ( ! self::allowed() ) { wp_die( esc_html__( 'Access denied.', 'threadtandem-sync-for-telegram' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'treda_bulk' );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$url = admin_url( 'options-general.php?page=threadtandem-sync' );
		if ( $operation === 'preview' ) {
			$preview = self::prepare();
			if ( $preview ) { wp_safe_redirect( add_query_arg( 'bulk', $preview['request'], $url ) ); exit; }
			$ok = false;
		} else {
			$request = isset( $_POST['request'] ) && is_string( $_POST['request'] ) ? sanitize_text_field( wp_unslash( $_POST['request'] ) ) : '';
			$ok = $operation === 'confirm' && self::confirm( $request );
		}
		set_transient( 'treda_bulk_notice_' . get_current_user_id(), $ok ? 'queued' : 'error', 60 );
		wp_safe_redirect( $url ); exit;
	}

	private static function form( $operation, $request = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="treda-bulk-form">';
		wp_nonce_field( 'treda_bulk' );
		echo '<input type="hidden" name="action" value="treda_bulk"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="request" value="' . esc_attr( $request ) . '">';
	}

	public static function confirmation() {
		// This read-only query only selects the current administrator's confirmation screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$request = isset( $_GET['bulk'] ) && is_string( $_GET['bulk'] ) ? sanitize_text_field( wp_unslash( $_GET['bulk'] ) ) : '';
		if ( $request === '' ) { return false; }
		$preview = get_transient( self::preview_key() );
		$valid = self::allowed() && self::ready() && is_array( $preview ) && hash_equals( $preview['request'], $request ) && hash_equals( $preview['connection'], Treda_Settings::fingerprint() );
		echo '<section class="treda-card treda-bulk-confirm"><h2>' . esc_html__( 'Confirm bulk sending', 'threadtandem-sync-for-telegram' ) . '</h2>';
		if ( $valid ) {
			$connection = Treda_Settings::connection();
			echo '<dl><div><dt>' . esc_html__( 'Telegram channel', 'threadtandem-sync-for-telegram' ) . '</dt><dd>' . esc_html( $connection['title'] . ' (' . $connection['chat_id'] . ')' ) . '</dd></div><div><dt>' . esc_html__( 'Posts selected', 'threadtandem-sync-for-telegram' ) . '</dt><dd>' . esc_html( number_format_i18n( $preview['total'] ) ) . '</dd></div></dl><p>' . esc_html__( 'Confirm to add these posts to the background queue. New announcements will appear in the channel; linked announcements will be updated. Posts created after this preview are not included. Eligibility is checked again before queuing.', 'threadtandem-sync-for-telegram' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'The confirmation expired or the connection changed. Open bulk sending again.', 'threadtandem-sync-for-telegram' ) . '</p>';
		}
		echo '<div class="treda-bulk-confirm-actions"><a class="button button-primary" href="' . esc_url( admin_url( 'options-general.php?page=threadtandem-sync' ) ) . '">' . esc_html__( 'Cancel', 'threadtandem-sync-for-telegram' ) . '</a>';
		if ( $valid ) { self::form( 'confirm', $request ); submit_button( __( 'Confirm and add to queue', 'threadtandem-sync-for-telegram' ), 'secondary', '', false ); echo '</form>'; }
		echo '</div></section>';
		return true;
	}

	public static function launcher() {
		$notice = get_transient( 'treda_bulk_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'treda_bulk_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . ( $notice === 'queued' ? 'success' : 'error' ) . ' inline"><p>' . ( $notice === 'queued' ? esc_html__( 'Bulk sending was queued. Progress is available in Queue and diagnostics.', 'threadtandem-sync-for-telegram' ) : esc_html__( 'Bulk sending was not started. Check the connection and available posts, then confirm again.', 'threadtandem-sync-for-telegram' ) ) . '</p></div>';
		}
		echo '<details class="treda-card treda-disclosure treda-bulk"><summary><span class="dashicons dashicons-upload" aria-hidden="true"></span><span>' . esc_html__( 'Bulk sending', 'threadtandem-sync-for-telegram' ) . '<small>' . esc_html__( 'Send the published archive to your channel', 'threadtandem-sync-for-telegram' ) . '</small></span><span class="dashicons dashicons-arrow-down-alt2 treda-chevron" aria-hidden="true"></span></summary><div class="treda-card-body"><p>' . esc_html__( 'Includes all public published posts, regardless of the current search or page. Drafts, password-protected, ignored posts and posts with synchronization disabled are skipped. Linked announcements are updated.', 'threadtandem-sync-for-telegram' ) . '</p><p class="treda-help">' . esc_html__( 'The next step shows the channel and post count. Nothing is queued until you confirm.', 'threadtandem-sync-for-telegram' ) . '</p>';
		self::form( 'preview' );
		submit_button( __( 'Send all posts to the Telegram channel', 'threadtandem-sync-for-telegram' ), 'secondary', '', false, ! self::allowed() || ! self::ready() ? array( 'disabled' => true ) : array() );
		echo '</form>';
		if ( ! self::ready() ) { echo '<p class="treda-help">' . esc_html__( 'Enable post publishing and verify the Telegram connection first.', 'threadtandem-sync-for-telegram' ) . '</p>'; }
		echo '</div></details>';
	}

	/** Each transaction admits at most 100 posts and advances its cursor together. */
	public static function process( $job, $token, $payload ) {
		global $wpdb;
		if ( empty( $payload['user_id'] ) || ! user_can( $payload['user_id'], 'manage_options' ) || ! user_can( $payload['user_id'], 'edit_others_posts' ) || ! user_can( $payload['user_id'], 'publish_posts' ) || ! self::ready() || empty( $payload['connection'] ) || ! hash_equals( Treda_Settings::fingerprint(), $payload['connection'] ) || empty( $payload['through'] ) ) {
			return new WP_Error( 'treda_bulk_changed', __( 'Bulk sending stopped because permissions or connection settings changed.', 'threadtandem-sync-for-telegram' ) );
		}
		try {
			$table = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( Treda_Queue::table() ) ) );
			if ( ! $table || strtolower( $table->Engine ) !== 'innodb' ) { throw new RuntimeException(); }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException(); }
			$owned = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id=%d AND status='running' AND lock_token=%s FOR UPDATE", Treda_Queue::table(), $job->id, $token ) );
			if ( ! $owned ) { throw new RuntimeException(); }
			// Same eligibility as prepare(), bounded by the confirmed archive and durable cursor.
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM %i AS p WHERE p.post_type='post' AND p.post_status='publish' AND p.post_password=''
				 AND p.ID>%d AND p.ID<=%d AND NOT EXISTS (
				 SELECT 1 FROM %i AS pm WHERE pm.post_id=p.ID
				 AND pm.meta_key IN ('_treda_disabled','_treda_sync_ignored','_treda_sync_pending','_treda_import_detached') AND pm.meta_value='1') ORDER BY p.ID ASC LIMIT 100",
				$wpdb->posts, (int) ( $payload['after'] ?? 0 ), (int) $payload['through'], $wpdb->postmeta
			) );
			if ( $wpdb->last_error ) { throw new RuntimeException(); }
			foreach ( $ids as $id ) {
				if ( user_can( $payload['user_id'], 'edit_post', (int) $id ) && ! Treda_Queue::post( $id, $payload['connection'] ) ) { throw new RuntimeException(); }
			}
			if ( $ids ) { $payload['after'] = (int) end( $ids ); }
			if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET payload=%s,attempts=0 WHERE id=%d AND lock_token=%s', Treda_Queue::table(), wp_json_encode( $payload ), $job->id, $token ) ) || false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException(); }
			return count( $ids ) === 100 ? 'continue' : true;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'treda_bulk_database', __( 'Could not prepare the bulk queue. Check database diagnostics.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'pending' ) );
		}
	}
}
