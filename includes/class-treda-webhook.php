<?php
defined( 'ABSPATH' ) || exit;

final class Treda_Webhook {
	public static function hooks() {
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'treda/v1',
					'/webhook',
					array(
						'methods'             => 'POST',
						'permission_callback' => array( __CLASS__, 'authorize' ),
						'callback'            => array( __CLASS__, 'receive' ),
					)
				);
			}
		);
	}

	public static function authorize( $request ) {
		$secret   = get_option( 'treda_webhook_secret', '' );
		$provided = (string) $request->get_header( 'x-telegram-bot-api-secret-token' );
		if ( $secret === '' || $provided === '' || ! hash_equals( $secret, $provided ) ) {
			return new WP_Error( 'treda_forbidden', __( 'Access denied.', 'threadtandem-sync-for-telegram' ), array( 'status' => 403 ) );
		}
		if ( ! Treda_Settings::ready() ) {
			return new WP_Error( 'treda_unavailable', __( 'The connection needs to be checked.', 'threadtandem-sync-for-telegram' ), array( 'status' => 503 ) );
		}
		return true;
	}

	public static function receive( $request ) {
		if ( strlen( $request->get_body() ) > 262144 ) {
			return new WP_Error( 'treda_size', __( 'The update is too large.', 'threadtandem-sync-for-telegram' ), array( 'status' => 413 ) );
		}
		$update = $request->get_json_params();
		if ( ! is_array( $update ) || ! isset( $update['update_id'] ) || ! is_int( $update['update_id'] ) || $update['update_id'] < 0 ) {
			return new WP_Error( 'treda_update', __( 'Invalid Telegram update.', 'threadtandem-sync-for-telegram' ), array( 'status' => 400 ) );
		}
		$connection = Treda_Settings::connection();
		$message    = $update['message'] ?? $update['edited_message'] ?? array();
		if ( ! is_array( $message ) || empty( $message['message_id'] ) || ! is_int( $message['message_id'] )
			|| ! isset( $message['date'] ) || ! is_int( $message['date'] ) || $message['date'] <= 0 ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'ignored' => true,
				),
				200
			);
		}
		if ( empty( $connection['discussion_chat_id'] )
			|| (string) ( $message['chat']['id'] ?? '' ) !== $connection['discussion_chat_id'] ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'ignored' => true,
				),
				200
			);
		}
		if ( empty( $message['is_automatic_forward'] ) && ( ! Treda_Comments::enabled() || empty( $message['text'] )
			|| ! is_string( $message['text'] ) || ( empty( $message['sender_chat'] ) && ! empty( $message['from']['is_bot'] ) )
			|| preg_match( '/^\s*\//u', $message['text'] )
			|| ( empty( $message['reply_to_message']['message_id'] ) && empty( $message['message_thread_id'] ) ) ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'ignored' => true,
				),
				200
			);
		}
		// Acknowledge only after the database has durably accepted the event.
		if ( ! Treda_Queue::event( $update ) ) {
			return new WP_Error( 'treda_storage', __( 'Could not store the Telegram update.', 'threadtandem-sync-for-telegram' ), array( 'status' => 503 ) );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function process( $payload ) {
		if ( empty( $payload['connection'] ) || ! hash_equals( Treda_Settings::fingerprint(), $payload['connection'] ) ) {
			return true;
		}
		$connection = Treda_Settings::connection();
		$message    = $payload['update']['message'] ?? $payload['update']['edited_message'] ?? array();
		if ( empty( $connection['discussion_chat_id'] )
			|| (string) ( $message['chat']['id'] ?? '' ) !== $connection['discussion_chat_id'] ) {
			return true;
		}
		if ( empty( $message['is_automatic_forward'] ) ) {
			return Treda_Comments::import( $message, (int) ( $payload['update']['update_id'] ?? 0 ) );
		}
		$origin = $message['forward_origin'] ?? array();
		if ( ( $origin['type'] ?? '' ) !== 'channel' || (string) ( $origin['chat']['id'] ?? '' ) !== $connection['chat_id']
			|| empty( $origin['message_id'] ) || empty( $message['message_id'] ) ) {
			return true;
		}
		$publication = Treda_Messages::find( $connection['chat_id'], (int) $origin['message_id'] );
		if ( ! $publication || $publication->origin !== 'publication' ) {
			return new WP_Error( 'treda_waiting', __( 'Waiting for the exact publication mapping. The forward may have arrived before the API response.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'pending' ) );
		}
		$post = get_post( $publication->post_id );
		if ( ! $post ) {
			return true;
		}
		$binding = get_post_meta( $post->ID, '_treda_publication', true );
		if ( ! is_array( $binding ) || ( $binding['message_id'] ?? 0 ) !== (int) $origin['message_id'] ) {
			return new WP_Error( 'treda_binding', __( 'The publication mapping needs to be checked.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'failed' ) );
		}
		// Separate metadata avoids a race with an outgoing publication update.
		$discussion = array(
			'chat_id'    => $connection['discussion_chat_id'],
			'message_id' => (int) $message['message_id'],
		);
		if ( get_post_meta( $post->ID, '_treda_discussion', true ) !== $discussion
			&& ! update_post_meta( $post->ID, '_treda_discussion', $discussion ) ) {
			return new WP_Error( 'treda_discussion', __( 'Could not save the discussion mapping.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'pending' ) );
		}
		if ( ! Treda_Messages::save(
			array(
				'chat_id'    => $discussion['chat_id'],
				'message_id' => $discussion['message_id'],
				'post_id'    => $post->ID,
				'origin'     => 'root',
			)
		) ) {
			return new WP_Error( 'treda_mapping', __( 'Could not save the discussion mapping.', 'threadtandem-sync-for-telegram' ), array( 'state' => 'pending' ) );
		}
		return true;
	}
}
