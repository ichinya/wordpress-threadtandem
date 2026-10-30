<?php
defined( 'ABSPATH' ) || exit;

final class Treda_Publisher {
	public static function hooks() {
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_preference' ), 10, 3 );
		// REST controllers fire this only after terms and registered metadata are saved.
		add_action( 'wp_after_insert_post', array( __CLASS__, 'queue' ), 20, 1 );
	}

	public static function eligible( $post ) {
		return $post instanceof WP_Post && $post->post_type === 'post' && $post->post_status === 'publish'
			&& $post->post_password === '' && get_post_meta( $post->ID, '_treda_disabled', true ) !== '1';
	}

	public static function queue( $post_or_id ) {
		$post     = get_post( $post_or_id );
		$settings = Treda_Settings::get();
		if ( ! empty( $settings['enabled'] ) && Treda_Settings::token() !== '' && $settings['chat_id'] !== '' && self::eligible( $post )
			&& ! wp_is_post_revision( $post->ID ) && ! wp_is_post_autosave( $post->ID ) ) {
			Treda_Queue::post( $post->ID );
		}
	}

	public static function text( $post ) {
		$title   = self::plain( $post->post_title );
		$source  = $post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content;
		$excerpt = self::plain( strip_shortcodes( $source ) );
		$title   = self::truncate( $title, 200 );
		$excerpt = self::truncate( $excerpt, 650 );
		return '<b>' . esc_html( $title ) . "</b>\n\n" . esc_html( $excerpt )
			. "\n\n" . '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( Treda_Settings::article_link_text() ) . '</a>';
	}

	public static function plain( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	public static function truncate( $text, $length ) {
		// WordPress ships a UTF-8 implementation even when mbstring is unavailable.
		return wp_html_excerpt( $text, $length, '…' );
	}

	public static function publish( $payload ) {
		if ( ! Treda_Sync::lock() ) {
			return new WP_Error( 'treda_busy', __( 'Another synchronization job is running.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'pending' ) );
		}
		try {
			return self::deliver( $payload );
		} finally {
			Treda_Sync::unlock();
		}
	}

	private static function deliver( $payload ) {
		if ( empty( $payload['connection'] ) || ! hash_equals( Treda_Settings::fingerprint(), $payload['connection'] ) ) {
			return new WP_Error( 'treda_connection_changed', __( 'Telegram connection settings changed after this job was queued. Nothing was sent. Check the current bot and channel, then queue this post again from the Synchronization page.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'failed' ) );
		}
		$post = get_post( (int) ( $payload['post_id'] ?? 0 ) );
		if ( empty( Treda_Settings::get()['enabled'] ) || ! self::eligible( $post ) ) {
			return true;
		}
		$connection = Treda_Settings::connection();
		if ( ! $connection ) {
			// Revalidate in the worker so changed settings cannot silently discard a new publication.
			$connection = Treda_API::check();
			if ( is_wp_error( $connection ) ) {
				return $connection;
			}
		}
		$binding = get_post_meta( $post->ID, '_treda_publication', true );
		$binding = is_array( $binding ) ? $binding : array();
		if ( $binding && ( $binding['chat_id'] !== $connection['chat_id'] || $binding['bot_id'] !== $connection['bot_id'] ) ) {
			return new WP_Error( 'treda_binding', __( 'This post is already linked to a different bot or channel. Its mapping cannot be moved automatically.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'failed' ) );
		}
		if ( ! empty( $binding['message_id'] ) && ! self::save_message_key( $post->ID, $binding ) ) {
			return new WP_Error( 'treda_index', __( 'Could not save the publication mapping index.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'pending' ) );
		}
		$media = Treda_Media::for_post( $post );
		if ( is_wp_error( $media ) ) { return $media; }
		$photo = $media || in_array( $binding['media_type'] ?? '', array( 'photo', 'caption' ), true );
		$params = array(
			'chat_id'    => $connection['chat_id'],
			'parse_mode' => 'HTML',
		);
		$params[ $photo ? 'caption' : 'text' ] = $photo ? Treda_Media::caption( $post ) : self::text( $post );
		// Preserve the hash of old text-only messages; photo hashes include the actual file bytes.
		if ( ! $photo ) { $params = array( 'chat_id' => $connection['chat_id'], 'text' => $params['text'], 'parse_mode' => 'HTML' ); }
		$photo_hash = $media ? $media['hash'] : ( $binding['photo_hash'] ?? '' );
		if ( $photo ) { $params['photo_hash'] = $photo_hash; }
		$hash   = hash( 'sha256', wp_json_encode( $params ) );
		if ( ! empty( $binding['hash'] ) && hash_equals( $binding['hash'], $hash ) ) {
			return true;
		}
		$editing = ! empty( $binding['message_id'] );
		unset( $params['photo_hash'] );
		$files = array();
		$method = $editing ? 'editMessageText' : 'sendMessage';
		if ( $photo ) {
			$changed = $media && ( ( $binding['media_type'] ?? '' ) !== 'photo' || ( $binding['photo_hash'] ?? '' ) !== $photo_hash );
			$method = $editing ? ( $changed ? 'editMessageMedia' : 'editMessageCaption' ) : 'sendPhoto';
			if ( ! $editing || $changed ) {
				$reference = $media['reference']; $files = $media['files'];
				if ( $editing ) {
					$params['media'] = wp_json_encode( array( 'type' => 'photo', 'media' => $reference, 'caption' => $params['caption'], 'parse_mode' => 'HTML' ) );
					unset( $params['caption'], $params['parse_mode'] );
				} else { $params['photo'] = $reference; }
			}
		}
		if ( $editing ) {
			$params['message_id'] = (int) $binding['message_id'];
		}
		$result = Treda_API::call( $method, $params, ! $editing, $files );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $editing && ( ! is_array( $result ) || empty( $result['message_id'] ) ) ) {
			return new WP_Error( 'treda_message', __( 'Telegram did not return a publication ID. Check the channel.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'uncertain' ) );
		}
		$binding = array_merge(
			$binding,
			array(
				'bot_id'     => $connection['bot_id'],
				'chat_id'    => $connection['chat_id'],
				'message_id' => $editing ? $binding['message_id'] : (int) $result['message_id'],
				'hash'       => $hash,
				'media_type' => $photo ? ( $media ? 'photo' : ( $binding['media_type'] ?? 'photo' ) ) : 'text',
				'photo_hash' => $photo_hash,
				'updated_at' => time(),
			)
		);
		if ( ! update_post_meta( $post->ID, '_treda_publication', $binding ) ) {
			return new WP_Error( 'treda_mapping', __( 'The message was sent but the mapping could not be saved. Check Telegram before retrying.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'uncertain' ) );
		}
		if ( ! self::save_message_key( $post->ID, $binding ) ) {
			return new WP_Error( 'treda_index', __( 'Could not save the publication mapping index.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'pending' ) );
		}
		if ( ! Treda_Sync::site_origin( $post->ID, $binding ) ) {
			return new WP_Error( 'treda_index', __( 'Could not save the publication mapping index.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'pending' ) );
		}
		return true;
	}

	private static function save_message_key( $post_id, $binding ) {
		$key   = $binding['bot_id'] . ':' . $binding['chat_id'] . ':' . $binding['message_id'];
		$saved = get_post_meta( $post_id, '_treda_message_key', true ) === $key
			|| update_post_meta( $post_id, '_treda_message_key', $key ) !== false;
		return $saved && Treda_Messages::save(
			array(
				'chat_id'    => $binding['chat_id'],
				'message_id' => $binding['message_id'],
				'post_id'    => $post_id,
				'origin'     => 'publication',
			)
		);
	}

	public static function link( $binding ) {
		if ( ! is_array( $binding ) || empty( $binding['chat_id'] ) || empty( $binding['message_id'] ) ) {
			return '';
		}
		return 'https://t.me/c/' . substr( $binding['chat_id'], 4 ) . '/' . (int) $binding['message_id'];
	}

	public static function meta_box( $post = null ) {
		if ( $post instanceof WP_Post && Treda_Editor::uses_native_panel( $post ) ) {
			return;
		}
		add_meta_box( 'treda', __( 'ThreadTandem · Telegram', 'threadtandem-sync-for-telegram' ), array( __CLASS__, 'render_box' ), 'post', 'side' );
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'treda_post', 'treda_post_nonce' );
		$disabled = get_post_meta( $post->ID, '_treda_disabled', true ) === '1';
		echo '<p><label><input type="checkbox" name="treda_publish" value="1" ' . checked( ! $disabled, true, false ) . '> ' . esc_html__( 'Publish to Telegram', 'threadtandem-sync-for-telegram' ) . '</label></p>';
		$binding = get_post_meta( $post->ID, '_treda_publication', true );
		$url     = self::link( $binding );
		if ( $url ) {
			echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open publication', 'threadtandem-sync-for-telegram' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'No publication is linked yet.', 'threadtandem-sync-for-telegram' ) . '</p>';
		}
		if ( empty( Treda_Settings::get()['enabled'] ) ) {
			echo '<p>' . esc_html__( 'Automatic publishing is disabled in ThreadTandem settings.', 'threadtandem-sync-for-telegram' ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Saving this post queues its changes in the background.', 'threadtandem-sync-for-telegram' ) . '</p>';
	}

	public static function save_preference( $id ) {
		if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id )
			|| ! current_user_can( 'edit_post', $id ) || empty( $_POST['treda_post_nonce'] )
			|| ! is_string( $_POST['treda_post_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['treda_post_nonce'] ) ), 'treda_post' ) ) {
			return;
		}
		update_post_meta( $id, '_treda_disabled', empty( $_POST['treda_publish'] ) ? '1' : '0' );
	}
}
