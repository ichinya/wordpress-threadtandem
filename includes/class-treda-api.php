<?php
defined( 'ABSPATH' ) || exit;

final class Treda_API {
	/** Errors deliberately contain no remote body, token or request URL. */
	public static function call( $method, $params = array(), $creates_message = false, $files = array() ) {
		$settings = Treda_Settings::get();
		$token    = Treda_Settings::token();
		if ( $token === '' ) {
			return new WP_Error( 'treda_token', __( 'Save the bot token first.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'failed' ) );
		}
		$content_type = 'application/json';
		$body = wp_json_encode( (object) $params );
		if ( $files ) {
			$boundary = 'ThreadTandem' . wp_generate_password( 32, false, false );
			$content_type = 'multipart/form-data; boundary=' . $boundary;
			$body = '';
			foreach ( $params as $name => $value ) {
				$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . sanitize_key( $name ) . "\"\r\n\r\n" . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . "\r\n";
			}
			foreach ( $files as $name => $file ) {
				$filename = str_replace( array( '"', "\r", "\n" ), '', sanitize_file_name( $file['name'] ) );
				$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . sanitize_key( $name ) . '"; filename="' . $filename . "\"\r\nContent-Type: " . $file['type'] . "\r\n\r\n" . $file['data'] . "\r\n";
			}
			$body .= '--' . $boundary . "--\r\n";
		}
		$response = wp_safe_remote_post(
			$settings['api_url'] . '/bot' . $token . '/' . $method,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'headers'             => array( 'Content-Type' => $content_type ),
				'body'                => $body,
				'limit_response_size' => 262144,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'treda_transport',
				$creates_message
				? __( 'The send result is unknown. Check Telegram before retrying.', 'threadtandem-sync-for-telegram' )
				: __( 'Could not connect to Telegram.', 'threadtandem-sync-for-telegram' ),
				array( 'state' => $creates_message ? 'uncertain' : 'pending' )
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status === 200 && is_array( $body ) && ! empty( $body['ok'] ) && array_key_exists( 'result', $body ) ) {
			return $body['result'];
		}
		if ( in_array( $method, array( 'editMessageText', 'editMessageMedia', 'editMessageCaption' ), true ) && is_array( $body ) && isset( $body['description'] )
			&& strpos( $body['description'], 'message is not modified' ) !== false && $status === 400 ) {
			return true;
		}
		if ( $method === 'deleteMessage' && $status === 400 && is_array( $body )
			&& strpos( $body['description'] ?? '', 'message to delete not found' ) !== false ) {
			return true;
		}
		$code = is_array( $body ) && isset( $body['error_code'] ) ? (int) $body['error_code'] : $status;
		if ( $code === 429 ) {
			$delay = max( 1, (int) ( $body['parameters']['retry_after'] ?? 60 ) );
			return new WP_Error(
				'treda_rate_limit',
				__( 'Telegram has limited the request rate.', 'threadtandem-sync-for-telegram' ),
				array(
					'state' => 'pending',
					'delay' => $delay,
				)
			);
		}
		if ( $status >= 500 || ! is_array( $body ) || ! isset( $body['ok'] ) ) {
			return new WP_Error(
				'treda_response',
				__( 'Telegram did not confirm the operation result.', 'threadtandem-sync-for-telegram' ),
				array(
					'state' => $creates_message ? 'uncertain' : 'pending',
				)
			);
		}
		$messages = array(
			400 => __( 'Telegram rejected the parameters. Check the destination and bot permissions.', 'threadtandem-sync-for-telegram' ),
			401 => __( 'Telegram rejected the bot token.', 'threadtandem-sync-for-telegram' ),
			403 => __( 'The bot does not have access or permission to send messages.', 'threadtandem-sync-for-telegram' ),
		);
		return new WP_Error( 'treda_api', $messages[ $code ] ?? __( 'Telegram rejected the request.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'failed' ) );
	}

	/** Keep retry metadata and safe error text while identifying the failed connection step. */
	private static function check_error( $error, $context, $bot = array() ) {
		$message = sprintf(
			/* translators: 1: connection check step, 2: safe Telegram error message. */
			__( '%1$s: %2$s', 'threadtandem-sync-for-telegram' ),
			$context,
			$error->get_error_message()
		);
		if ( ! empty( $bot['username'] ) ) {
			/* translators: %s: Telegram bot username, without the @ prefix. */
			$message .= ' ' . sprintf( __( 'Bot: @%s.', 'threadtandem-sync-for-telegram' ), $bot['username'] );
		}
		return new WP_Error( $error->get_error_code(), $message, $error->get_error_data() );
	}

	public static function check() {
		delete_option( 'treda_connection' );
		$bot = self::call( 'getMe' );
		if ( is_wp_error( $bot ) ) {
			return self::check_error( $bot, __( 'Bot token check', 'threadtandem-sync-for-telegram' ) );
		}
		$settings = Treda_Settings::get();
		if ( $settings['chat_id'] === '' ) {
			return new WP_Error( 'treda_chat', __( 'Save the channel before checking the connection.', 'threadtandem-sync-for-telegram' ) );
		}
		$chat = self::call( 'getChat', array( 'chat_id' => $settings['chat_id'] ) );
		if ( is_wp_error( $chat ) ) {
			return self::check_error( $chat, __( 'Channel access', 'threadtandem-sync-for-telegram' ), $bot );
		}
		if ( ( $chat['type'] ?? '' ) !== 'channel' ) {
			return new WP_Error( 'treda_mode', __( 'Select a Telegram channel. Forum groups are not supported by this release.', 'threadtandem-sync-for-telegram' ) );
		}
		$member = self::call(
			'getChatMember',
			array(
				'chat_id' => $chat['id'],
				'user_id' => $bot['id'],
			)
		);
		if ( is_wp_error( $member ) ) {
			return self::check_error( $member, __( 'Channel permissions', 'threadtandem-sync-for-telegram' ), $bot );
		}
		if ( ( $member['status'] ?? '' ) !== 'administrator' || empty( $member['can_post_messages'] ) ) {
			return new WP_Error( 'treda_rights', __( 'Add the bot as a channel administrator with permission to publish.', 'threadtandem-sync-for-telegram' ) );
		}
		$connection = array(
			'fingerprint'        => Treda_Settings::fingerprint(),
			'bot_id'             => (string) $bot['id'],
			'username'           => $bot['username'] ?? '',
			'chat_id'            => (string) $chat['id'],
			'title'              => $chat['title'] ?? '',
			'discussion_chat_id' => isset( $chat['linked_chat_id'] ) ? (string) $chat['linked_chat_id'] : '',
			'checked_at'         => time(),
		);
		if ( ! empty( $settings['comments_enabled'] ) ) {
			if ( empty( $connection['discussion_chat_id'] ) ) {
				return new WP_Error( 'treda_group', __( 'Link a discussion group to the channel before enabling comment synchronization.', 'threadtandem-sync-for-telegram' ) );
			}
			$group_member = self::call(
				'getChatMember',
				array(
					'chat_id' => $connection['discussion_chat_id'],
					'user_id' => $bot['id'],
				)
			);
			if ( is_wp_error( $group_member ) ) {
				return self::check_error( $group_member, __( 'Discussion group access', 'threadtandem-sync-for-telegram' ), $bot );
			}
			if ( ( $group_member['status'] ?? '' ) !== 'administrator' ) {
				return new WP_Error( 'treda_group_rights', __( 'Add the bot as an administrator of the linked discussion group.', 'threadtandem-sync-for-telegram' ) );
			}
		}
		update_option( 'treda_connection', $connection, false );
		return $connection;
	}

	public static function register_webhook() {
		if ( ! Treda_Settings::ready() ) {
			return new WP_Error( 'treda_connection', __( 'Check the connection first.', 'threadtandem-sync-for-telegram' ) );
		}
		$url = Treda_Settings::webhook_url();
		if ( ! Treda_Settings::valid_https_url( $url ) ) {
			return new WP_Error( 'treda_https', __( 'The webhook requires a public HTTPS URL.', 'threadtandem-sync-for-telegram' ) );
		}
		$info = self::call( 'getWebhookInfo' );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( ! empty( $info['url'] ) && $info['url'] !== $url ) {
			return new WP_Error( 'treda_conflict', __( 'This bot already uses a different webhook. Use a dedicated bot or disconnect the previous integration first.', 'threadtandem-sync-for-telegram' ) );
		}
		if ( get_current_user_id() ) {
			update_option( 'treda_import_author', get_current_user_id(), false );
		}
		return self::call(
			'setWebhook',
			array(
				'url'                  => $url,
				'secret_token'         => Treda_Settings::secret(),
				'allowed_updates'      => array( 'message', 'edited_message', 'channel_post', 'edited_channel_post' ),
				'max_connections'      => 1,
				'drop_pending_updates' => false,
			)
		);
	}

	public static function disconnect_webhook() {
		$info = self::call( 'getWebhookInfo' );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( empty( $info['url'] ) ) {
			return true;
		}
		if ( $info['url'] !== Treda_Settings::webhook_url() ) {
			return new WP_Error( 'treda_conflict', __( 'The bot uses a different webhook. ThreadTandem will not change it.', 'threadtandem-sync-for-telegram' ) );
		}
		return self::call( 'deleteWebhook', array( 'drop_pending_updates' => false ) );
	}

	/** Recover one pending batch after a delivery outage; acknowledge only durable receipts. */
	public static function recover_updates() {
		if ( ! Treda_Settings::ready() ) {
			return new WP_Error( 'treda_connection', __( 'Check the connection first.', 'threadtandem-sync-for-telegram' ) );
		}
		$disconnected = self::disconnect_webhook();
		if ( is_wp_error( $disconnected ) ) { return $disconnected; }
		$result = true;
		try {
			$updates = self::call( 'getUpdates', array( 'limit' => 100, 'timeout' => 0 ) );
			if ( is_wp_error( $updates ) || ! is_array( $updates ) ) {
				$result = is_wp_error( $updates ) ? $updates : new WP_Error( 'treda_update', __( 'Invalid Telegram update.', 'threadtandem-sync-for-telegram' ) );
			} else {
				$last = -1;
				foreach ( $updates as $update ) {
					$request = new WP_REST_Request( 'POST', '/treda/v1/webhook' );
					$request->set_header( 'Content-Type', 'application/json' );
					$request->set_body( wp_json_encode( $update ) );
					$accepted = Treda_Webhook::receive( $request );
					if ( is_wp_error( $accepted ) || $accepted->get_status() >= 300 ) {
						$result = is_wp_error( $accepted ) ? $accepted : new WP_Error( 'treda_storage', __( 'Could not store the Telegram update.', 'threadtandem-sync-for-telegram' ) );
						break;
					}
					$last = max( $last, $update['update_id'] );
				}
				if ( $result === true && $last >= 0 ) {
					// A response may include the next update; it remains unconfirmed for webhook delivery.
					$ack = self::call( 'getUpdates', array( 'offset' => $last + 1, 'limit' => 1, 'timeout' => 0 ) );
					if ( is_wp_error( $ack ) ) { $result = $ack; }
				}
			}
		} finally {
			$restored = self::register_webhook();
		}
		return is_wp_error( $restored ) ? $restored : $result;
	}
}
