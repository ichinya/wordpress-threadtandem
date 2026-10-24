<?php
/** Indexed correspondence between Telegram messages and local comments. */
defined( 'ABSPATH' ) || exit;

// Mapping reads participate in transactions and cannot use stale cache entries.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Treda_Messages {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'treda_messages';
	}

	public static function install() {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bot_id varchar(32) NOT NULL,
            chat_id varchar(32) NOT NULL,
            message_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            origin varchar(20) NOT NULL,
            version bigint(20) unsigned NOT NULL DEFAULT 0,
            update_id bigint(20) unsigned NOT NULL DEFAULT 0,
            content_hash varchar(64) NOT NULL DEFAULT '',
            state varchar(20) NOT NULL DEFAULT 'active',
            PRIMARY KEY  (id),
            UNIQUE KEY telegram_message (bot_id,chat_id,message_id),
            KEY local_comment (comment_id),
            KEY local_post (post_id)
        ) ENGINE=InnoDB $collate;"
		);
	}

	public static function find( $chat_id, $message_id, $lock = false ) {
		global $wpdb;
		$connection = Treda_Settings::connection();
		if ( ! $connection ) {
			return null;
		}
		if ( $lock ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE bot_id=%s AND chat_id=%s AND message_id=%d FOR UPDATE',
					self::table(),
					$connection['bot_id'],
					(string) $chat_id,
					(int) $message_id
				)
			);
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE bot_id=%s AND chat_id=%s AND message_id=%d',
				self::table(),
				$connection['bot_id'],
				(string) $chat_id,
				(int) $message_id
			)
		);
	}

	public static function for_comment( $comment_id ) {
		global $wpdb;
		$connection = Treda_Settings::connection();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE comment_id=%d AND bot_id=%s AND origin='site' AND state='active' ORDER BY id DESC LIMIT 1",
				self::table(),
				(int) $comment_id,
				$connection['bot_id'] ?? ''
			)
		);
	}

	public static function parent_message( $comment_id, $chat_id ) {
		global $wpdb;
		$connection = Treda_Settings::connection();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE comment_id=%d AND bot_id=%s AND chat_id=%s AND state='active' ORDER BY id DESC LIMIT 1",
				self::table(),
				(int) $comment_id,
				$connection['bot_id'] ?? '',
				(string) $chat_id
			)
		);
	}

	/** Called inside the import transaction; the unique key also serializes first import. */
	public static function reserve( $chat_id, $message_id, $post_id ) {
		global $wpdb;
		$connection = Treda_Settings::connection();
		return false !== $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (bot_id,chat_id,message_id,post_id,origin) VALUES (%s,%s,%d,%d,'telegram')
             ON DUPLICATE KEY UPDATE id=id",
				self::table(),
				$connection['bot_id'],
				(string) $chat_id,
				(int) $message_id,
				(int) $post_id
			)
		);
	}

	public static function save( $data ) {
		global $wpdb;
		$connection = Treda_Settings::connection();
		return false !== $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (bot_id,chat_id,message_id,post_id,comment_id,origin,version,update_id,content_hash,state)
				VALUES (%s,%s,%d,%d,%d,%s,%d,%d,%s,'active')
             ON DUPLICATE KEY UPDATE comment_id=VALUES(comment_id),version=VALUES(version),update_id=VALUES(update_id),content_hash=VALUES(content_hash)",
				self::table(),
				$connection['bot_id'],
				(string) $data['chat_id'],
				(int) $data['message_id'],
				(int) $data['post_id'],
				(int) ( $data['comment_id'] ?? 0 ),
				$data['origin'],
				(int) ( $data['version'] ?? 0 ),
				(int) ( $data['update_id'] ?? 0 ),
				$data['content_hash'] ?? ''
			)
		);
	}

	public static function mark_deleted( $id ) {
		global $wpdb;
		return false !== $wpdb->update( self::table(), array( 'state' => 'deleted' ), array( 'id' => (int) $id ) );
	}

	public static function transactional() {
		global $wpdb;
		foreach ( array( $wpdb->comments, $wpdb->commentmeta, self::table() ) as $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! $status || strtolower( $status->Engine ) !== 'innodb' ) {
				return false;
			}
		}
		return true;
	}
}
