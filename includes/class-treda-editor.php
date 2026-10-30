<?php
/** Native block-editor controls with a classic-editor fallback. */
defined( 'ABSPATH' ) || exit;

final class Treda_Editor {

	public static function hooks() {
		register_post_meta(
			'post',
			'_treda_disabled',
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '0',
				'sanitize_callback' => array( __CLASS__, 'sanitize_preference' ),
				'auth_callback'     => array( __CLASS__, 'can_edit_preference' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'string',
						'enum'    => array( '0', '1' ),
						'default' => '0',
						'context' => array( 'edit' ),
					),
				),
			)
		);
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	public static function sanitize_preference( $value ) {
		return (string) $value === '1' ? '1' : '0';
	}

	public static function can_edit_preference( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', (int) $post_id );
	}

	public static function uses_native_panel( $post ) {
		return $post->post_type === 'post' && post_type_supports( 'post', 'custom-fields' )
			&& use_block_editor_for_post( $post );
	}

	public static function enqueue() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== 'post' || ! post_type_supports( 'post', 'custom-fields' ) ) {
			return;
		}
		wp_enqueue_script(
			'treda-editor',
			plugins_url( 'assets/editor.js', TREDA_FILE ),
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			TREDA_VERSION,
			true
		);
		$post   = get_post();
		$config = array(
			'publishingEnabled' => ! empty( Treda_Settings::get()['enabled'] ),
			'publicationUrl'    => $post ? Treda_Publisher::link( get_post_meta( $post->ID, '_treda_publication', true ) ) : '',
		);
		wp_add_inline_script( 'treda-editor', 'window.tredaEditor = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_set_script_translations( 'treda-editor', 'threadtandem-sync-for-telegram', TREDA_DIR . 'languages' );
	}
}
