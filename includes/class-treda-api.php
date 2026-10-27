<?php
defined( 'ABSPATH' ) || exit;

final class Treda_API {
	/** Errors deliberately contain no remote body, token or request URL. */
	public static function call( $method, $params = array(), $creates_message = false ) {
		$settings = Treda_Settings::get();
		$token    = Treda_Settings::token();
		if ( $token === '' ) {
			return new WP_Error( 'treda_token', __( 'Save the bot token first.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'failed' ) );
		}
		$response = wp_safe_remote_post(
			$settings['api_url'] . '/bot' . $token . '/' . $method,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'headers'             => array( 'Content-Type' => 'application/json' ),
				'body'                => wp_json_encode( (object) $params ),
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
		if ( $method === 'editMessageText' && is_array( $body ) && isset( $body['description'] )
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

	public static function check() {
		delete_option( 'treda_connection' );
		$bot = self::call( 'getMe' );
		if ( is_wp_error( $bot ) ) {
			return $bot;
		}
		$settings = Treda_Settings::get();
		if ( $settings['chat_id'] === '' ) {
			return new WP_Error( 'treda_chat', __( 'Save the channel before checking the connection.', 'threadtandem-sync-for-telegram' ) );
		}
		$chat = self::call( 'getChat', array( 'chat_id' => $settings['chat_id'] ) );
		if ( is_wp_error( $chat ) ) {
			return $chat;
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
			return $member;
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
				return $group_member;
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
		return self::call(
			'setWebhook',
			array(
				'url'                  => $url,
				'secret_token'         => Treda_Settings::secret(),
				'allowed_updates'      => array( 'message', 'edited_message' ),
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
}
