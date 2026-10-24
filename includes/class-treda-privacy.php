<?php
/** Privacy policy suggestions and additional comment metadata handling. */
defined( 'ABSPATH' ) || exit;

final class Treda_Privacy {
	public static function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Treda',
				wp_kses_post(
					'<p>' . __( 'When Telegram synchronization is enabled, this site sends published post titles, excerpts, links, approved comment text and comment author names to the configured Telegram bot API service. Comment email addresses and IP addresses are not sent.', 'treda' ) . '</p><p>'
					. __( 'Text messages in linked Telegram discussions may be copied to this site with the sender display name and Telegram identity. Imported comments are moderated in WordPress. Telegram does not supply an email address; no WordPress account is created automatically.', 'treda' ) . '</p><p>'
					. __( 'Incoming delivery payloads are cleared after successful processing. Completed and failed incoming delivery records are deleted after seven days. Message mappings remain until integration data is removed. Copies already published in Telegram may remain there after local erasure.', 'treda' ) . '</p><p>'
					. __( 'For a Telegram-only author, contact the site administrator and prove ownership of the Telegram identity to request access or removal. Email-based WordPress privacy requests cannot automatically identify a Telegram-only author.', 'treda' ) . '</p>'
				)
			);
		}
	}

	public static function exporters( $exporters ) {
		return array_merge(
			array(
				'treda' => array(
					'exporter_friendly_name' => __( 'Treda Telegram metadata', 'treda' ),
					'callback'               => array(
						__CLASS__,
						'export',
					),
				),
			),
			$exporters
		);
	}

	public static function erasers( $erasers ) {
		// Run before the core comment eraser removes matching email addresses.
		return array_merge(
			array(
				'treda' => array(
					'eraser_friendly_name' => __( 'Treda Telegram metadata', 'treda' ),
					'callback'             => array(
						__CLASS__,
						'erase',
					),
				),
			),
			$erasers
		);
	}

	private static function comments( $email, $page ) {
		return get_comments(
			array(
				'author_email' => $email,
				'number'       => 100,
				'offset'       => ( max( 1, (int) $page ) - 1 ) * 100,
				'orderby'      => 'comment_ID',
				'order'        => 'ASC',
				'status'       => 'all',
			)
		);
	}

	public static function export( $email, $page = 1 ) {
		$comments = self::comments( $email, $page );
		$data     = array();
		foreach ( $comments as $comment ) {
			$sender = get_comment_meta( $comment->comment_ID, '_treda_sender_id', true );
			if ( $sender !== '' ) {
				$data[] = array(
					'group_id'    => 'treda',
					'group_label' => __( 'Telegram comment identities', 'treda' ),
					'item_id'     => 'comment-' . $comment->comment_ID,
					'data'        => array(
						array(
							'name'  => __( 'Telegram sender ID', 'treda' ),
							'value' => $sender,
						),
						array(
							'name'  => __( 'Telegram sender type', 'treda' ),
							'value' => get_comment_meta( $comment->comment_ID, '_treda_sender_type', true ),
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $comments ) < 100,
		);
	}

	public static function erase( $email, $page = 1 ) {
		$comments = self::comments( $email, $page );
		$removed  = false;
		$retained = false;
		foreach ( $comments as $comment ) {
			$retained = get_comment_meta( $comment->comment_ID, '_treda_origin', true ) === 'telegram'
				|| (bool) Treda_Messages::for_comment( $comment->comment_ID ) || $retained;
			$removed  = delete_comment_meta( $comment->comment_ID, '_treda_sender_id' ) || $removed;
			$removed  = delete_comment_meta( $comment->comment_ID, '_treda_sender_type' ) || $removed;
			$removed  = delete_comment_meta( $comment->comment_ID, '_treda_chat_id' ) || $removed;
			$removed  = delete_comment_meta( $comment->comment_ID, '_treda_message_id' ) || $removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Telegram copies and internal delivery mappings may remain. Contact the site administrator for further removal.', 'treda' ) ) : array(),
			'done'           => count( $comments ) < 100,
		);
	}
}
