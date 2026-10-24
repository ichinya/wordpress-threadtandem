<?php
defined( 'ABSPATH' ) || exit;

final class Treda_Settings {
	public static function get() {
		return wp_parse_args(
			get_option( 'treda_settings', array() ),
			array(
				'token'               => '',
				'api_url'             => 'https://api.telegram.org',
				'chat_id'             => '',
				'webhook_url'         => '',
				'enabled'             => 0,
				'comments_enabled'    => 0,
				'telegram_moderation' => 1,
				'retain_data'         => 1,
			)
		);
	}

	public static function token() {
		return defined( 'TREDA_BOT_TOKEN' ) ? (string) TREDA_BOT_TOKEN : self::get()['token'];
	}

	public static function fingerprint() {
		$settings = self::get();
		return hash( 'sha256', self::token() . '|' . $settings['api_url'] . '|' . $settings['chat_id'] . '|' . (int) $settings['comments_enabled'] );
	}

	public static function connection() {
		$connection = get_option( 'treda_connection', array() );
		return ! empty( $connection['fingerprint'] ) && hash_equals( $connection['fingerprint'], self::fingerprint() )
			? $connection : array();
	}

	public static function ready() {
		return self::token() !== '' && ! empty( self::connection()['chat_id'] );
	}

	public static function webhook_url() {
		$settings = self::get();
		return $settings['webhook_url'] ?: rest_url( 'treda/v1/webhook' );
	}

	public static function secret() {
		$secret = get_option( 'treda_webhook_secret', '' );
		if ( $secret === '' ) {
			add_option( 'treda_webhook_secret', wp_generate_password( 48, false, false ), '', false );
			$secret = get_option( 'treda_webhook_secret', '' );
		}
		return $secret;
	}

	public static function valid_https_url( $url ) {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] )
			&& strtolower( $parts['scheme'] ) === 'https'
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& ! isset( $parts['fragment'] );
	}

	public static function sanitize( $input ) {
		$old = self::get();
		if ( ! is_array( $input ) ) {
			return $old;
		}
		$new                        = $old;
		$new['enabled']             = empty( $input['enabled'] ) ? 0 : 1;
		$new['comments_enabled']    = empty( $input['comments_enabled'] ) ? 0 : 1;
		$new['telegram_moderation'] = empty( $input['telegram_moderation'] ) ? 0 : 1;
		$new['retain_data']         = empty( $input['retain_data'] ) ? 0 : 1;
		if ( ! defined( 'TREDA_BOT_TOKEN' ) ) {
			$token = isset( $input['token'] ) && is_string( $input['token'] ) ? trim( $input['token'] ) : '';
			if ( ! empty( $input['clear_token'] ) ) {
				$new['token'] = '';
			} elseif ( $token !== '' ) {
				if ( preg_match( '/^\d+:[A-Za-z0-9_-]{20,}$/D', $token ) ) {
					$new['token'] = $token;
				} else {
					add_settings_error( 'treda', 'token', __( 'The token format is invalid. The previous token was kept.', 'treda' ) );
				}
			}
		}
		$chat = isset( $input['chat_id'] ) && is_string( $input['chat_id'] ) ? trim( $input['chat_id'] ) : '';
		if ( $chat === '' || preg_match( '/^(?:-100\d+|@[A-Za-z0-9_]{5,})$/D', $chat ) ) {
			$new['chat_id'] = $chat;
		} else {
			add_settings_error( 'treda', 'chat', __( 'Enter a channel ID starting with -100 or an @username.', 'treda' ) );
		}
		$api   = isset( $input['api_url'] ) && is_string( $input['api_url'] ) ? rtrim( trim( $input['api_url'] ), '/' ) : '';
		$api   = $api ?: 'https://api.telegram.org';
		$parts = wp_parse_url( $api );
		if ( self::valid_https_url( $api ) && ! isset( $parts['query'] ) ) {
			$new['api_url'] = esc_url_raw( $api, array( 'https' ) );
		} else {
			add_settings_error( 'treda', 'api', __( 'The API URL must use HTTPS without credentials or query parameters.', 'treda' ) );
		}
		$url = isset( $input['webhook_url'] ) && is_string( $input['webhook_url'] ) ? trim( $input['webhook_url'] ) : '';
		if ( $url === '' || self::valid_https_url( $url ) ) {
			$new['webhook_url'] = esc_url_raw( $url, array( 'https' ) );
		} else {
			add_settings_error( 'treda', 'webhook', __( 'The external webhook URL must use HTTPS.', 'treda' ) );
		}
		return $new;
	}
}
