<?php
/** Channel imports and explicit publication matching. */
defined( 'ABSPATH' ) || exit;

// Message identity and the channel lock require atomic, uncached database operations.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Treda_Sync {
	public static function hooks() {
		add_action( 'admin_menu', function () {
			add_submenu_page( 'options-general.php', __( 'ThreadTandem synchronization', 'threadtandem-sync-for-telegram' ), __( 'ThreadTandem synchronization', 'threadtandem-sync-for-telegram' ), 'manage_options', 'threadtandem-sync', array( __CLASS__, 'render' ) );
			// Both screens share one visible plugin menu entry.
			remove_submenu_page( 'options-general.php', 'threadtandem-sync' );
		} );
		add_filter( 'submenu_file', function ( $submenu_file ) {
			$screen = get_current_screen();
			return $screen && $screen->id === 'settings_page_threadtandem-sync' ? 'threadtandem' : $submenu_file;
		} );
		add_action( 'admin_post_treda_sync', array( __CLASS__, 'action' ) );
	}

	private static function lock_name() {
		$connection = Treda_Settings::connection();
		return 'treda:' . substr( hash( 'sha256', ( $connection['bot_id'] ?? '' ) . ':' . ( $connection['chat_id'] ?? '' ) ), 0, 50 );
	}

	public static function lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', self::lock_name() ) );
	}

	public static function unlock() {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	public static function accepts( $message ) {
		$connection = Treda_Settings::connection();
		return ! empty( Treda_Settings::get()['import_enabled'] ) && is_array( $message )
			&& (string) ( $message['chat']['id'] ?? '' ) === ( $connection['chat_id'] ?? '' )
			&& ( $message['chat']['type'] ?? '' ) === 'channel'
			&& isset( $message['message_id'], $message['date'] ) && is_int( $message['message_id'] ) && $message['message_id'] > 0
			&& is_int( $message['date'] ) && $message['date'] > 0
			&& ( ( isset( $message['text'] ) && is_string( $message['text'] ) && trim( $message['text'] ) !== '' )
				|| ( isset( $message['caption'] ) && is_string( $message['caption'] ) && trim( $message['caption'] ) !== '' ) );
	}

	/** Telegram offsets count UTF-16 units, including two units for emoji. */
	public static function content( $message ) {
		$text     = $message['text'] ?? $message['caption'] ?? '';
		$entities = isset( $message['text'] ) ? ( $message['entities'] ?? array() ) : ( $message['caption_entities'] ?? array() );
		$chars    = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return '';
		}
		$offsets = array( 0 => 0 );
		$units   = 0;
		foreach ( $chars as $index => $char ) {
			$units += strlen( $char ) === 4 ? 2 : 1;
			$offsets[ $units ] = $index + 1;
		}
		$spans = array();
		$tags  = array( 'bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strikethrough' => 's', 'code' => 'code', 'pre' => 'pre' );
		foreach ( is_array( $entities ) ? $entities : array() as $entity ) {
			if ( ! is_array( $entity ) || ! isset( $entity['offset'], $entity['length'] ) || ! is_int( $entity['offset'] ) || ! is_int( $entity['length'] ) || $entity['length'] <= 0 ) {
				continue;
			}
			$start = $entity['offset'];
			$end   = $start + $entity['length'];
			if ( ! isset( $offsets[ $start ], $offsets[ $end ] ) ) {
				continue;
			}
			$type = $entity['type'] ?? '';
			$tag  = $tags[ $type ] ?? '';
			$open = $tag ? '<' . $tag . '>' : '';
			$close = $tag ? '</' . $tag . '>' : '';
			if ( $type === 'text_link' || $type === 'url' ) {
				$url = $type === 'text_link' ? ( $entity['url'] ?? '' ) : implode( '', array_slice( $chars, $offsets[ $start ], $offsets[ $end ] - $offsets[ $start ] ) );
				$url = is_string( $url ) ? esc_url( $url, array( 'http', 'https' ) ) : '';
				if ( $url ) {
					$open = '<a href="' . $url . '" rel="nofollow noopener noreferrer">';
					$close = '</a>';
				}
			}
			if ( $open ) {
				$spans[] = array( 'start' => $start, 'end' => $end, 'open' => $open, 'close' => $close );
			}
		}
		// Telegram entities are nested; reject malformed crossings instead of creating invalid HTML.
		usort( $spans, function ( $a, $b ) { return $a['start'] <=> $b['start'] ?: $b['end'] <=> $a['end']; } );
		$opens = array();
		$closes = array();
		$stack = array();
		foreach ( $spans as $span ) {
			while ( $stack && end( $stack ) <= $span['start'] ) { array_pop( $stack ); }
			if ( $stack && $span['end'] > end( $stack ) ) { continue; }
			$stack[] = $span['end'];
			$opens[ $span['start'] ][] = $span['open'];
			$closes[ $span['end'] ][] = $span['close'];
		}
		$html = '';
		$position = 0;
		foreach ( $chars as $char ) {
			$html .= implode( '', array_reverse( $closes[ $position ] ?? array() ) ) . implode( '', $opens[ $position ] ?? array() ) . esc_html( $char );
			$position += strlen( $char ) === 4 ? 2 : 1;
		}
		$html .= implode( '', array_reverse( $closes[ $position ] ?? array() ) );
		return wp_kses_post( wpautop( $html ) );
	}

	private static function content_hash( $post ) {
		return hash( 'sha256', $post->post_title . '|' . $post->post_content );
	}

	/** A completed website export makes its article authoritative, even if later unpublished. */
	public static function site_origin( $post_id, $binding ) {
		global $wpdb;
		$ok = false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET origin=%s WHERE bot_id=%s AND chat_id=%s AND message_id=%d AND post_id=%d AND origin=%s', Treda_Messages::table(), 'publication', $binding['bot_id'], $binding['chat_id'], $binding['message_id'], $post_id, 'channel' ) );
		if ( $ok ) { delete_post_meta( $post_id, '_treda_sync_pending' ); delete_post_meta( $post_id, '_treda_import_hash' ); }
		return $ok;
	}

	private static function meta( $id, $key, $value ) {
		return get_post_meta( $id, $key, true ) === $value || update_post_meta( $id, $key, wp_slash( $value ) ) !== false;
	}

	private static function error() {
		return new WP_Error( 'treda_import', __( 'Could not save the channel import. Check database diagnostics.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'pending' ) );
	}

	private static function transactional() {
		global $wpdb;
		foreach ( array( $wpdb->posts, $wpdb->postmeta, Treda_Messages::table() ) as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! $row || strtolower( $row->Engine ) !== 'innodb' ) { return false; }
		}
		return true;
	}

	public static function import( $message, $update_id ) {
		global $wpdb;
		if ( ! self::accepts( $message ) ) { return true; }
		if ( ! self::lock() ) { return self::error(); }
		$post_id = 0;
		try {
			if ( ! self::transactional() || false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error(); }
			$row = Treda_Messages::find( $message['chat']['id'], $message['message_id'], true );
			// Site-origin messages and explicit matches always retain WordPress as their source.
			if ( $row && ( $row->origin !== 'channel' || $row->state !== 'active' || (int) $row->update_id >= $update_id ) ) {
				$wpdb->query( 'COMMIT' );
				return true;
			}
			$version = (int) ( $message['edit_date'] ?? $message['date'] );
			if ( $row && (int) $row->version > $version ) { $wpdb->query( 'COMMIT' ); return true; }
			$connection = Treda_Settings::connection();
			$binding = array( 'bot_id' => $connection['bot_id'], 'chat_id' => $connection['chat_id'], 'message_id' => $message['message_id'], 'hash' => '', 'updated_at' => time(), 'media_type' => isset( $message['photo'] ) ? 'photo' : ( isset( $message['caption'] ) ? 'caption' : 'text' ) );
			if ( $row ) {
				$post_id = (int) $row->post_id;
				clean_post_cache( $post_id );
				$post = get_post( $post_id );
				// Never overwrite a published article or a draft already edited by its owner.
				if ( ! $post || $post->post_status !== 'draft' || get_post_meta( $post_id, '_treda_sync_ignored', true ) === '1' || get_post_meta( $post_id, '_treda_import_hash', true ) !== self::content_hash( $post ) ) {
					$wpdb->query( 'COMMIT' );
					return true;
				}
			}
			$text = $message['text'] ?? $message['caption'];
			$title = sanitize_text_field( strtok( str_replace( "\r", '', $text ), "\n" ) );
			$data = array( 'post_type' => 'post', 'post_title' => Treda_Publisher::truncate( $title, 150 ), 'post_content' => self::content( $message ) );
			if ( $row ) {
				$data['ID'] = $post_id;
			} else {
				$data['post_status'] = 'draft';
				$data['post_author'] = (int) get_option( 'treda_import_author', 0 );
				$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $message['date'] );
				$data['post_date'] = get_date_from_gmt( $data['post_date_gmt'] );
				$data['meta_input'] = array( '_treda_disabled' => '1', '_treda_sync_pending' => '1', '_treda_publication' => $binding );
			}
			$saved = wp_insert_post( wp_slash( $data ), true );
			if ( is_wp_error( $saved ) || ! $saved ) { $wpdb->query( 'ROLLBACK' ); return self::error(); }
			$post_id = (int) $saved;
			if ( ! self::meta( $post_id, '_treda_import_hash', self::content_hash( get_post( $post_id ) ) )
				|| ! Treda_Messages::save( array( 'chat_id' => $message['chat']['id'], 'message_id' => $message['message_id'], 'post_id' => $post_id, 'origin' => 'channel', 'version' => $version, 'update_id' => $update_id, 'content_hash' => hash( 'sha256', $text ) ) ) ) {
				$wpdb->query( 'ROLLBACK' ); clean_post_cache( $post_id ); return self::error();
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); clean_post_cache( $post_id ); return self::error(); }
			return true;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $post_id ) { clean_post_cache( $post_id ); }
			return self::error();
		} finally {
			self::unlock();
		}
	}

	/** Matching is explicit and never overwrites either article's text. */
	public static function bind( $source_id, $target_id ) {
		global $wpdb;
		if ( ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'edit_post', $target_id ) || ! self::lock() ) { return false; }
		try {
			$connection = Treda_Settings::connection();
			$source = get_post( $source_id );
			$target = get_post( $target_id );
			$binding = get_post_meta( $source_id, '_treda_publication', true );
			if ( ! $source || ! $target || $target->post_type !== 'post' || in_array( $target->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) || ! is_array( $binding )
				|| ( $binding['bot_id'] ?? '' ) !== ( $connection['bot_id'] ?? '' ) || ( $binding['chat_id'] ?? '' ) !== ( $connection['chat_id'] ?? '' )
				|| get_post_meta( $source_id, '_treda_sync_pending', true ) !== '1'
				|| ( $source_id !== $target_id && get_post_meta( $target_id, '_treda_publication', true ) ) || ! self::transactional() ) { return false; }
			$row = Treda_Messages::find( $binding['chat_id'], $binding['message_id'] );
			if ( ! $row || (int) $row->post_id !== $source_id || $row->origin !== 'channel' || $row->state !== 'active' ) { return false; }
			if ( $source_id !== $target_id && (int) get_comments( array( 'post_id' => $source_id, 'count' => true, 'status' => 'all' ) ) > 0 ) { return false; }
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
			$binding['hash'] = '';
			$ok = self::meta( $target_id, '_treda_publication', $binding ) && self::meta( $target_id, '_treda_disabled', '0' )
				&& self::meta( $target_id, '_treda_message_key', $binding['bot_id'] . ':' . $binding['chat_id'] . ':' . $binding['message_id'] );
			if ( $source_id !== $target_id ) {
				$discussion = get_post_meta( $source_id, '_treda_discussion', true );
				if ( $discussion ) { $ok = $ok && self::meta( $target_id, '_treda_discussion', $discussion ); }
				delete_post_meta( $source_id, '_treda_publication' ); delete_post_meta( $source_id, '_treda_discussion' );
				$ok = $ok && self::meta( $source_id, '_treda_import_detached', '1' );
			}
			$ok = $ok && false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET post_id=%d,origin=IF(origin=%s,%s,origin) WHERE bot_id=%s AND post_id=%d AND comment_id=0', Treda_Messages::table(), $target_id, 'channel', 'publication', $connection['bot_id'], $source_id ) );
			delete_post_meta( $source_id, '_treda_sync_pending' );
			if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); clean_post_cache( $source_id ); clean_post_cache( $target_id ); return false; }
			return true;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); clean_post_cache( $source_id ); clean_post_cache( $target_id ); return false;
		} finally { self::unlock(); }
	}

	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'threadtandem-sync-for-telegram' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'treda_sync' );
		$id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$ok = false;
		if ( $id && current_user_can( 'edit_post', $id ) ) {
			if ( $operation === 'bind' ) {
				$target = isset( $_POST['target_id'] ) && is_scalar( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
				$ok = self::bind( $id, $target );
			} elseif ( $operation === 'send' && ! empty( Treda_Settings::get()['enabled'] ) && Treda_Settings::ready() ) {
				$post = get_post( $id );
				if ( $post && $post->post_status === 'publish' && $post->post_type === 'post' && $post->post_password === '' ) {
					self::meta( $id, '_treda_disabled', '0' ); delete_post_meta( $id, '_treda_sync_ignored' );
					$ok = Treda_Queue::post( $id );
				}
			} elseif ( $operation === 'ignore' && self::lock() ) {
				try {
					$ok = self::meta( $id, '_treda_disabled', '1' ) && self::meta( $id, '_treda_sync_ignored', '1' );
					delete_post_meta( $id, '_treda_sync_pending' );
				} finally { self::unlock(); }
			} elseif ( $operation === 'restore' && self::lock() ) {
				try {
					$binding = get_post_meta( $id, '_treda_publication', true );
					$row = is_array( $binding ) ? Treda_Messages::find( $binding['chat_id'], $binding['message_id'] ) : null;
					$ok = true;
					if ( $row && $row->origin === 'channel' ) { $ok = self::meta( $id, '_treda_sync_pending', '1' ); }
					delete_post_meta( $id, '_treda_sync_ignored' );
				} finally { self::unlock(); }
			}
		}
		set_transient( 'treda_sync_notice_' . get_current_user_id(), $ok ? 'success' : 'error', 60 );
		$view = isset( $_POST['view'] ) && is_string( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : '';
		$url = admin_url( 'options-general.php?page=threadtandem-sync' );
		if ( in_array( $view, array( 'incoming', 'outgoing', 'linked', 'ignored' ), true ) ) { $url = add_query_arg( 'view', $view, $url ); }
		wp_safe_redirect( $url ); exit;
	}

	private static function form_start( $id, $operation, $view ) {
		echo '<form id="treda-sync-' . esc_attr( $operation . '-' . $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="treda-sync-form treda-sync-form-' . esc_attr( $operation ) . '">';
		wp_nonce_field( 'treda_sync' );
		echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '">';
		echo '<input type="hidden" name="action" value="treda_sync"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="post_id" value="' . esc_attr( $id ) . '">';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		// These GET values only filter this read-only administrator view. Mutations use action() and its nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// Native post metadata keeps drafts available after uninstall; administrator results are paginated and bounded.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$incoming = new WP_Query( array( 'post_type' => 'post', 'post_status' => array( 'draft', 'pending', 'publish', 'future', 'private' ), 'posts_per_page' => 20, 'paged' => $paged, 'meta_key' => '_treda_sync_pending', 'meta_value' => '1' ) );
		$available = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft', 'pending', 'future' ), 'numberposts' => 50, 's' => $search, 'meta_query' => array( array( 'key' => '_treda_publication', 'compare' => 'NOT EXISTS' ), array( 'key' => '_treda_import_detached', 'compare' => 'NOT EXISTS' ) ) ) );
		$outgoing = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 20, 'paged' => $paged, 's' => $search, 'has_password' => false, 'meta_query' => array( array( 'key' => '_treda_publication', 'compare' => 'NOT EXISTS' ), array( 'key' => '_treda_sync_ignored', 'compare' => 'NOT EXISTS' ) ) ) );
		$linked = new WP_Query( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft', 'pending', 'future' ), 'posts_per_page' => 20, 'paged' => $paged, 's' => $search, 'meta_query' => array( array( 'key' => '_treda_publication', 'compare' => 'EXISTS' ), array( 'key' => '_treda_sync_pending', 'compare' => 'NOT EXISTS' ), array( 'key' => '_treda_sync_ignored', 'compare' => 'NOT EXISTS' ) ) ) );
		$ignored = new WP_Query( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft', 'pending', 'future' ), 'posts_per_page' => 20, 'paged' => $paged, 's' => $search, 'meta_key' => '_treda_sync_ignored', 'meta_value' => '1' ) );
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$views = array(
			'incoming' => array( 'label' => __( 'From Telegram', 'threadtandem-sync-for-telegram' ), 'title' => __( 'From Telegram — awaiting a decision', 'threadtandem-sync-for-telegram' ), 'query' => $incoming ),
			'outgoing' => array( 'label' => __( 'From WordPress', 'threadtandem-sync-for-telegram' ), 'title' => __( 'From WordPress — not sent to Telegram', 'threadtandem-sync-for-telegram' ), 'query' => $outgoing ),
			'linked' => array( 'label' => __( 'Linked articles', 'threadtandem-sync-for-telegram' ), 'title' => __( 'Linked articles — WordPress is the source', 'threadtandem-sync-for-telegram' ), 'query' => $linked ),
			'ignored' => array( 'label' => __( 'Ignored publications', 'threadtandem-sync-for-telegram' ), 'title' => __( 'Ignored publications', 'threadtandem-sync-for-telegram' ), 'query' => $ignored ),
		);
		if ( ! isset( $views[ $view ] ) ) { $view = $incoming->found_posts ? 'incoming' : 'outgoing'; }
		$current = $views[ $view ];
		$base = add_query_arg( array( 'page' => 'threadtandem-sync', 'view' => $view, 's' => $search ), admin_url( 'options-general.php' ) );
		echo '<div class="wrap treda-settings treda-sync"><header class="treda-header"><div><h1>' . esc_html__( 'ThreadTandem synchronization', 'threadtandem-sync-for-telegram' ) . '</h1><p>' . esc_html__( 'Review publications and keep WordPress and Telegram connected.', 'threadtandem-sync-for-telegram' ) . '</p></div></header>';
		Treda_Admin::navigation( 'sync' );
		if ( Treda_Bulk::confirmation() ) { echo '</div>'; return; }
		$notice = get_transient( 'treda_sync_notice_' . get_current_user_id() ); delete_transient( 'treda_sync_notice_' . get_current_user_id() );
		if ( $notice ) { echo '<div class="notice notice-' . ( $notice === 'success' ? 'success' : 'error' ) . '"><p>' . ( $notice === 'success' ? esc_html__( 'Synchronization action saved. Deliveries use the background queue.', 'threadtandem-sync-for-telegram' ) : esc_html__( 'Action failed. Check connection settings, permissions and existing links.', 'threadtandem-sync-for-telegram' ) ) . '</p></div>'; }
		echo '<p class="treda-sync-intro">' . esc_html__( 'New Telegram posts become drafts. Linking preserves the selected WordPress article. Send queues a public article for Telegram; drafts are never published automatically.', 'threadtandem-sync-for-telegram' ) . '</p>';
		Treda_Bulk::launcher();
		echo '<nav class="treda-sync-tabs" aria-label="' . esc_attr__( 'Publication lists', 'threadtandem-sync-for-telegram' ) . '">';
		foreach ( $views as $key => $tab ) {
			$url = add_query_arg( array( 'page' => 'threadtandem-sync', 'view' => $key, 's' => $search ), admin_url( 'options-general.php' ) );
			echo '<a class="treda-sync-tab' . ( $key === $view ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $key === $view ? ' aria-current="page"' : '' ) . '><span>' . esc_html( $tab['label'] ) . '</span><span class="treda-sync-count">' . esc_html( number_format_i18n( $tab['query']->found_posts ) ) . '</span></a>';
		}
		echo '</nav><section class="treda-card treda-sync-panel" aria-labelledby="treda-sync-list-title"><div class="treda-sync-panel-header"><h2 id="treda-sync-list-title">' . esc_html( $current['title'] ) . '</h2>';
		if ( $view === 'incoming' ) { echo '<p class="treda-help">' . esc_html__( 'Link to the imported draft or select an existing article. Ignoring keeps the draft but stops Telegram edits. A source draft remains available after linking to another article.', 'threadtandem-sync-for-telegram' ) . '</p>'; }
		echo '</div><form method="get" class="treda-sync-search"><input type="hidden" name="page" value="treda-sync"><input type="hidden" name="view" value="' . esc_attr( $view ) . '"><div class="treda-field"><label for="treda-sync-search">' . esc_html( $view === 'incoming' ? __( 'Find a WordPress article to link', 'threadtandem-sync-for-telegram' ) : __( 'Search publications', 'threadtandem-sync-for-telegram' ) ) . '</label><input type="search" id="treda-sync-search" name="s" value="' . esc_attr( $search ) . '"></div>';
		submit_button( __( 'Search', 'threadtandem-sync-for-telegram' ), 'secondary', '', false );
		if ( $search !== '' ) { echo '<a class="treda-sync-clear" href="' . esc_url( remove_query_arg( 's', $base ) ) . '">' . esc_html__( 'Clear search', 'threadtandem-sync-for-telegram' ) . '</a>'; }
		echo '</form><table class="widefat treda-sync-table' . ( $view === 'incoming' ? ' treda-sync-table-incoming' : '' ) . '"><thead><tr><th scope="col">' . esc_html__( 'Publication', 'threadtandem-sync-for-telegram' ) . '</th><th scope="col" class="treda-sync-actions-heading">' . esc_html__( 'Actions', 'threadtandem-sync-for-telegram' ) . '</th></tr></thead><tbody>';
		foreach ( $current['query']->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) { continue; }
			$binding = get_post_meta( $post->ID, '_treda_publication', true );
			$status = get_post_status_object( $post->post_status );
			echo '<tr><td class="treda-sync-publication"><a class="treda-sync-title" href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( $post->post_title ?: __( 'Untitled', 'threadtandem-sync-for-telegram' ) ) . '</a><div class="treda-sync-meta"><span>#' . esc_html( $post->ID ) . '</span><span>' . esc_html( $status ? $status->label : $post->post_status ) . '</span>';
			if ( is_array( $binding ) && Treda_Publisher::link( $binding ) ) { echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( Treda_Publisher::link( $binding ) ) . '">Telegram <span aria-hidden="true">↗</span></a>'; }
			echo '</div>';
			if ( $view === 'incoming' ) { echo '<p class="treda-sync-excerpt">' . esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ) ) . '</p>'; }
			echo '</td><td class="treda-sync-actions"><div class="treda-sync-action-group">';
			if ( $view === 'incoming' ) {
				self::form_start( $post->ID, 'bind', $view );
				echo '<label for="target-' . esc_attr( $post->ID ) . '">' . esc_html__( 'WordPress article', 'threadtandem-sync-for-telegram' ) . '</label><select id="target-' . esc_attr( $post->ID ) . '" name="target_id"><option value="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Use this imported draft', 'threadtandem-sync-for-telegram' ) . '</option>';
				foreach ( $available as $candidate ) { if ( current_user_can( 'edit_post', $candidate->ID ) ) { echo '<option value="' . esc_attr( $candidate->ID ) . '">#' . esc_html( $candidate->ID . ' — ' . $candidate->post_title ) . '</option>'; } }
				echo '</select></form>'; submit_button( __( 'Link', 'threadtandem-sync-for-telegram' ), 'primary', '', false, array( 'form' => 'treda-sync-bind-' . $post->ID ) );
			} elseif ( $view === 'outgoing' || $view === 'linked' ) {
				self::form_start( $post->ID, 'send', $view ); submit_button( __( 'Send', 'threadtandem-sync-for-telegram' ), 'primary', '', false, $post->post_status !== 'publish' || ! Treda_Settings::ready() || empty( Treda_Settings::get()['enabled'] ) ? array( 'disabled' => true ) : array() ); echo '</form>';
			} else {
				self::form_start( $post->ID, 'restore', $view ); submit_button( __( 'Restore', 'threadtandem-sync-for-telegram' ), 'secondary', '', false ); echo '</form>';
			}
			if ( $view === 'incoming' || $view === 'outgoing' ) { self::form_start( $post->ID, 'ignore', $view ); submit_button( __( 'Ignore', 'threadtandem-sync-for-telegram' ), 'secondary', '', false ); echo '</form>'; }
			echo '</div></td></tr>';
		}
		if ( ! $current['query']->posts ) {
			$empty = $view === 'incoming' ? __( 'No unmatched Telegram posts.', 'threadtandem-sync-for-telegram' ) : ( $view === 'outgoing' ? __( 'No unmatched WordPress articles.', 'threadtandem-sync-for-telegram' ) : ( $view === 'linked' ? __( 'No linked articles.', 'threadtandem-sync-for-telegram' ) : __( 'No ignored publications.', 'threadtandem-sync-for-telegram' ) ) );
			echo '<tr><td colspan="2" class="treda-sync-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><p>' . esc_html( $empty ) . '</p></td></tr>';
		}
		echo '</tbody></table>';
		if ( $current['query']->max_num_pages > 1 ) {
			$pagination_base = str_replace( '999999999', '%#%', add_query_arg( 'paged', 999999999, $base ) );
			echo '<nav class="treda-sync-pagination" aria-label="' . esc_attr__( 'Pagination', 'threadtandem-sync-for-telegram' ) . '">' . wp_kses_post( paginate_links( array( 'base' => $pagination_base, 'total' => $current['query']->max_num_pages, 'current' => $paged ) ) ) . '</nav>';
		}
		echo '</section></div>';
	}
}
