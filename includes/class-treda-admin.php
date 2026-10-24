<?php
/** Capability-protected settings and queue controls. */
defined( 'ABSPATH' ) || exit;

final class Treda_Admin {
	public static function hooks() {
		add_action(
			'admin_menu',
			function () {
				add_options_page( 'Treda', 'Treda', 'manage_options', 'treda', array( __CLASS__, 'render' ) );
			}
		);
		add_action(
			'admin_init',
			function () {
				register_setting(
					'treda',
					'treda_settings',
					array(
						'type'              => 'array',
						'sanitize_callback' => array(
							'Treda_Settings',
							'sanitize',
						),
						'show_in_rest'      => false,
					)
				);
			}
		);
		add_action( 'admin_post_treda_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( $hook !== 'settings_page_treda' ) {
			return;
		}
		wp_enqueue_style( 'treda-admin', plugins_url( 'assets/admin.css', TREDA_DIR . 'treda.php' ), array(), TREDA_VERSION );
		wp_enqueue_script( 'treda-admin', plugins_url( 'assets/admin.js', TREDA_DIR . 'treda.php' ), array(), TREDA_VERSION, true );
		wp_localize_script(
			'treda-admin',
			'tredaAdmin',
			array(
				'show'       => __( 'Show', 'treda' ),
				'hide'       => __( 'Hide', 'treda' ),
				'copied'     => __( 'Copied', 'treda' ),
				'copyManual' => __( 'Address selected. Copy it with your keyboard.', 'treda' ),
				'unsaved'    => __( 'You have unsaved changes. Save them before checking the connection.', 'treda' ),
			)
		);
	}

	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Treda.', 'treda' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'treda_action' );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$result    = true;
		$message   = '';
		if ( $operation === 'check' ) {
			$result  = Treda_API::check();
			$message = __( 'Bot permissions checked. Webhook delivery still needs a real incoming event.', 'treda' );
		} elseif ( $operation === 'webhook' ) {
			$result  = Treda_API::register_webhook();
			$message = __( 'Webhook registered. Check incoming events in the queue.', 'treda' );
		} elseif ( $operation === 'disconnect' ) {
			$result  = Treda_API::disconnect_webhook();
			$message = __( 'The Treda webhook is disconnected.', 'treda' );
		} elseif ( $operation === 'run' ) {
			Treda_Queue::run();
			$message = __( 'A queue batch was processed. See the results below.', 'treda' );
		} elseif ( $operation === 'retry' ) {
			$id      = isset( $_POST['job_id'] ) && is_scalar( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
			$changed = Treda_Queue::retry( $id, ! empty( $_POST['acknowledge'] ) );
			$message = $changed ? __( 'The job was queued again.', 'treda' )
				: __( 'The job was not changed. Confirm a Telegram check before retrying an uncertain result.', 'treda' );
		} else {
			$result = new WP_Error( 'treda_action', __( 'Unknown action.', 'treda' ) );
		}
		set_transient(
			'treda_notice_' . get_current_user_id(),
			array(
				'error' => is_wp_error( $result ),
				'text'  => is_wp_error( $result ) ? $result->get_error_message() : $message,
			),
			60
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=treda' ) );
		exit;
	}

	private static function action_form( $operation, $label, $class = 'secondary', $disabled = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="treda-inline-form" data-treda-action-form>';
		wp_nonce_field( 'treda_action' );
		echo '<input type="hidden" name="action" value="treda_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		submit_button( $label, $class, 'submit', false, $disabled ? array( 'disabled' => true ) : array() );
		echo '</form>';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings   = Treda_Settings::get();
		$connection = Treda_Settings::connection();
		$jobs       = Treda_Queue::recent();
		$counts     = Treda_Queue::counts();
		$notice     = get_transient( 'treda_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'treda_notice_' . get_current_user_id() );
		}
		require TREDA_DIR . 'includes/admin-view.php';
	}
}
