<?php
/** Text comments with exact parent mappings and native WordPress moderation. */
defined( 'ABSPATH' ) || exit;

final class Treda_Comments {
	public static function hooks() {
		add_action( 'wp_insert_comment', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'edit_comment', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'transition_comment_status', array( __CLASS__, 'transition' ), 20, 3 );
		add_action( 'deleted_comment', array( __CLASS__, 'queue' ), 20, 1 );
		add_filter( 'pre_comment_approved', array( __CLASS__, 'moderate' ), 20, 2 );
		add_action( 'comment_form_after_fields', array( __CLASS__, 'form_notice' ) );
		add_action( 'comment_form_logged_in_after', array( __CLASS__, 'form_notice' ) );
		add_filter( 'get_comment_author_url', array( __CLASS__, 'author_url' ), 20, 3 );
		add_filter( 'get_comment_author_link', array( __CLASS__, 'author_link' ), 20, 3 );
	}

	public static function enabled() {
		return ! empty( Treda_Settings::get()['comments_enabled'] ) && Treda_Settings::ready();
	}

	public static function transition( $new, $old, $comment ) {
		self::queue( $comment->comment_ID );
	}

	public static function queue( $id ) {
		if ( ! self::enabled() ) {
			return;
		}
		$comment = get_comment( $id );
		if ( $comment && get_comment_meta( $id, '_treda_origin', true ) === 'telegram' ) {
			return;
		}
		$mapping = Treda_Messages::for_comment( $id );
		if ( $mapping || ( $comment && in_array( $comment->comment_type, array( '', 'comment' ), true ) && (string) $comment->comment_approved === '1'
			&& Treda_Publisher::eligible( get_post( $comment->comment_post_ID ) ) ) ) {
			Treda_Queue::comment( $id );
		}
	}

	public static function moderate( $approved, $data ) {
		if ( ( $data['comment_meta']['_treda_origin'] ?? '' ) === 'telegram'
			&& ! empty( Treda_Settings::get()['telegram_moderation'] ) && (string) $approved === '1' ) {
			return 0;
		}
		return $approved;
	}

	public static function publish( $payload ) {
		if ( ! self::enabled() ) {
			return true;
		}
		if ( empty( $payload['connection'] ) || ! hash_equals( Treda_Settings::fingerprint(), $payload['connection'] ) ) {
			return new WP_Error( 'treda_changed', __( 'Connection settings changed. Save the comment again after checking the connection.', 'treda' ), array( 'state' => 'failed' ) );
		}
		$id      = (int) ( $payload['comment_id'] ?? 0 );
		$comment = get_comment( $id );
		if ( $comment && get_comment_meta( $id, '_treda_origin', true ) === 'telegram' ) {
			return true;
		}
		$mapping = Treda_Messages::for_comment( $id );
		if ( ! $comment || ! in_array( $comment->comment_type, array( '', 'comment' ), true ) || (string) $comment->comment_approved !== '1' ) {
			if ( ! $mapping ) {
				return true;
			}
			$deleted = Treda_API::call(
				'deleteMessage',
				array(
					'chat_id'    => $mapping->chat_id,
					'message_id' => (int) $mapping->message_id,
				)
			);
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
			return Treda_Messages::mark_deleted( $mapping->id ) ? true
				: new WP_Error( 'treda_mapping', __( 'Could not update the message mapping.', 'treda' ), array( 'state' => 'pending' ) );
		}
		$post = get_post( $comment->comment_post_ID );
		if ( ! Treda_Publisher::eligible( $post ) ) {
			return true;
		}
		$connection = Treda_Settings::connection();
		$discussion = get_post_meta( $post->ID, '_treda_discussion', true );
		if ( ! is_array( $discussion ) || ( $discussion['chat_id'] ?? '' ) !== $connection['discussion_chat_id'] ) {
			return new WP_Error( 'treda_waiting', __( 'Waiting for the linked discussion.', 'treda' ), array( 'state' => 'pending' ) );
		}
		$parent_id = (int) $discussion['message_id'];
		if ( $comment->comment_parent ) {
			$parent = Treda_Messages::parent_message( $comment->comment_parent, $discussion['chat_id'] );
			if ( ! $parent || (int) $parent->post_id !== (int) $post->ID ) {
				self::queue( $comment->comment_parent );
				return new WP_Error( 'treda_parent', __( 'Waiting for the parent comment. Approve and synchronize it first.', 'treda' ), array( 'state' => 'pending' ) );
			}
			$parent_id = (int) $parent->message_id;
		}
		$author = Treda_Publisher::truncate( Treda_Publisher::plain( $comment->comment_author ), 150 );
		$text   = Treda_Publisher::truncate( Treda_Publisher::plain( $comment->comment_content ), 3400 );
		$params = array(
			'chat_id'    => $discussion['chat_id'],
			'parse_mode' => 'HTML',
			'text'       => '<b>' . esc_html( $author ) . '</b> ' . esc_html__( 'via website', 'treda' ) . "\n" . esc_html( $text ),
		);
		$hash   = hash( 'sha256', wp_json_encode( $params ) );
		if ( $mapping && hash_equals( $mapping->content_hash, $hash ) ) {
			return true;
		}
		if ( $mapping ) {
			$params['message_id'] = (int) $mapping->message_id;
		} else {
			$params['reply_parameters'] = array(
				'message_id'                  => $parent_id,
				'allow_sending_without_reply' => false,
			);
		}
		$result = Treda_API::call( $mapping ? 'editMessageText' : 'sendMessage', $params, ! $mapping );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$message_id = $mapping ? (int) $mapping->message_id : (int) ( $result['message_id'] ?? 0 );
		if ( ! $message_id || ! Treda_Messages::save(
			array(
				'chat_id'      => $discussion['chat_id'],
				'message_id'   => $message_id,
				'post_id'      => $post->ID,
				'comment_id'   => $id,
				'origin'       => 'site',
				'content_hash' => $hash,
			)
		) ) {
			return new WP_Error( 'treda_mapping', __( 'The message may have been sent, but its mapping could not be saved. Check Telegram before retrying.', 'treda' ), array( 'state' => 'uncertain' ) );
		}
		return true;
	}

	/** Imports only messages within an exactly mapped reply chain or thread. */
	public static function import( $message, $update_id = 0 ) {
		global $wpdb;
		if ( ! self::enabled() || empty( $message['text'] ) || ! is_string( $message['text'] )
			|| ( empty( $message['sender_chat'] ) && ! empty( $message['from']['is_bot'] ) )
			|| preg_match( '/^\s*\//u', $message['text'] ) ) {
			return true;
		}
		$chat_id   = (string) $message['chat']['id'];
		$reply_id  = (int) ( $message['reply_to_message']['message_id'] ?? 0 );
		$thread_id = (int) ( $message['message_thread_id'] ?? 0 );
		if ( ! $reply_id && ! $thread_id ) {
			return true;
		}
		$parent = Treda_Messages::find( $chat_id, $reply_id ?: $thread_id );
		if ( ! $parent || $parent->state !== 'active' ) {
			return new WP_Error( 'treda_parent', __( 'Waiting for the exact parent message mapping.', 'treda' ), array( 'state' => 'pending' ) );
		}
		if ( $parent->comment_id && ! get_comment( $parent->comment_id ) ) {
			return true;
		}
		$post = get_post( $parent->post_id );
		if ( ! Treda_Publisher::eligible( $post ) || ! comments_open( $post->ID ) ) {
			return true;
		}
		if ( get_option( 'comment_registration' ) ) {
			return new WP_Error( 'treda_registration', __( 'This site requires registered commenters. Telegram-only authors cannot be imported.', 'treda' ), array( 'state' => 'failed' ) );
		}
		if ( ! Treda_Messages::transactional() ) {
			return new WP_Error( 'treda_engine', __( 'Comment import requires InnoDB comments, comment metadata and mapping tables.', 'treda' ), array( 'state' => 'failed' ) );
		}
		// These direct queries form one import transaction, including core comment APIs.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'treda_transaction', __( 'Could not start the import transaction.', 'treda' ), array( 'state' => 'pending' ) );
		}
		if ( ! Treda_Messages::reserve( $chat_id, $message['message_id'], $post->ID ) ) {
			return self::rollback();
		}
		$mapping = Treda_Messages::find( $chat_id, $message['message_id'], true );
		if ( ! $mapping ) {
			return self::rollback();
		}
		$version = (int) ( $message['edit_date'] ?? $message['date'] ?? 0 );
		if ( $mapping->state === 'deleted' || $mapping->origin !== 'telegram'
			|| ( (int) $mapping->comment_id && ( (int) $mapping->version > $version
				|| ( (int) $mapping->version === $version && (int) $mapping->update_id >= $update_id ) ) ) ) {
			return self::commit();
		}
		$existing = $mapping->comment_id ? get_comment( $mapping->comment_id ) : null;
		if ( $mapping->comment_id && ( ! $existing || in_array( $existing->comment_approved, array( 'spam', 'trash' ), true ) ) ) {
			return self::commit(); // Never resurrect a deleted or rejected comment.
		}
		$text = esc_html( $message['text'] );
		if ( $existing && $existing->comment_content === $text ) {
			$id = (int) $existing->comment_ID;
		} elseif ( $existing ) {
			// Every Telegram edit is held for review; old approval cannot authorize replacement text.
			$updated = wp_update_comment(
				wp_slash(
					array(
						'comment_ID'       => (int) $existing->comment_ID,
						'comment_content'  => $text,
						'comment_approved' => 0,
					)
				),
				true
			);
			$id      = is_wp_error( $updated ) || $updated === false ? $updated : (int) $existing->comment_ID;
		} else {
			$sender_chat = $message['sender_chat'] ?? array();
			$sender      = $message['from'] ?? array();
			$author      = ! empty( $sender_chat ) ? ( $sender_chat['title'] ?? __( 'Telegram chat', 'treda' ) )
				: trim( ( $sender['first_name'] ?? '' ) . ' ' . ( $sender['last_name'] ?? '' ) );
			$metadata    = array(
				'_treda_origin'      => 'telegram',
				'_treda_sender_id'   => (string) ( $sender_chat['id'] ?? $sender['id'] ?? '' ),
				'_treda_sender_type' => $sender_chat ? 'chat' : 'user',
				'_treda_message_id'  => (int) $message['message_id'],
				'_treda_chat_id'     => $chat_id,
			);
			// Telegram supplies no author IP. Do not apply website-client IP flood checks to queued imports.
			add_filter( 'wp_is_comment_flood', '__return_false', PHP_INT_MAX );
			try {
				$id = wp_new_comment(
					wp_slash(
						array(
							'comment_post_ID'      => (int) $post->ID,
							'comment_author'       => sanitize_text_field( $author ?: __( 'Telegram user', 'treda' ) ),
							'comment_author_email' => '',
							'comment_author_url'   => '',
							'comment_author_IP'    => '',
							'comment_agent'        => 'Treda / Telegram',
							'comment_content'      => $text,
							'comment_type'         => 'comment',
							'comment_parent'       => (int) $parent->comment_id,
							'user_id'              => 0,
							'comment_meta'         => $metadata,
						)
					),
					true
				);
			} finally {
				remove_filter( 'wp_is_comment_flood', '__return_false', PHP_INT_MAX );
			}
		}
		if ( ! $id || is_wp_error( $id ) ) {
			return self::rollback();
		}
		if ( ! Treda_Messages::save(
			array(
				'chat_id'    => $chat_id,
				'message_id' => $message['message_id'],
				'post_id'    => $post->ID,
				'comment_id' => (int) $id,
				'origin'     => 'telegram',
				'version'    => $version,
				'update_id'  => $update_id,
			)
		) ) {
			return self::rollback();
		}
		return self::commit();
	}

	private static function rollback() {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'treda_import', __( 'The comment import could not be committed.', 'treda' ), array( 'state' => 'pending' ) );
	}

	private static function commit() {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->query( 'COMMIT' ) ? true : self::rollback();
	}

	public static function form_notice() {
		$post = get_post();
		if ( self::enabled() && Treda_Publisher::eligible( $post ) && get_post_meta( $post->ID, '_treda_discussion', true ) ) {
			echo '<p class="treda-comment-notice">' . esc_html__( 'Approved comments and the author name will also appear in the linked Telegram discussion.', 'treda' ) . '</p>';
		}
	}

	private static function message_url( $comment ) {
		if ( ! $comment instanceof WP_Comment || get_comment_meta( $comment->comment_ID, '_treda_origin', true ) !== 'telegram' ) {
			return '';
		}
		$chat = get_comment_meta( $comment->comment_ID, '_treda_chat_id', true );
		$message = get_comment_meta( $comment->comment_ID, '_treda_message_id', true );
		if ( ! is_scalar( $chat ) || ! preg_match( '/^-100\d+$/D', (string) $chat )
			|| ! is_scalar( $message ) || ! ctype_digit( (string) $message ) || (int) $message < 1 ) {
			return '';
		}
		return Treda_Publisher::link( array( 'chat_id' => (string) $chat, 'message_id' => (int) $message ) );
	}

	public static function author_url( $url, $comment_id, $comment ) {
		$link = self::message_url( $comment );
		return $link !== '' ? $link : $url;
	}

	public static function author_link( $html, $author, $comment_id ) {
		$link = self::message_url( get_comment( $comment_id ) );
		if ( $link === '' ) {
			return $html;
		}
		return '<a class="url treda-comment-author" href="' . esc_url( $link )
			. '" target="_blank" rel="external nofollow ugc noopener noreferrer" aria-label="'
			. esc_attr( $author . ' — ' . __( 'From Telegram', 'treda' ) ) . '">'
			. esc_html( $author )
			. '<svg class="treda-telegram-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="13" height="13" fill="#111" aria-hidden="true" focusable="false" style="display:inline-block;vertical-align:-0.1em;margin-inline-start:0.3em"><path d="M22 2 2 9.5l7 3L18 6l-6.5 8.5 3 7Z"/></svg></a>';
	}
}
