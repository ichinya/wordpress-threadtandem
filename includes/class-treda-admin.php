<?php
/** Capability-protected settings and queue controls. */
defined( 'ABSPATH' ) || exit;

final class Treda_Admin {
	public static function hooks() {
		add_action(
			'admin_menu',
			function () {
				add_options_page( 'ThreadTandem', 'ThreadTandem', 'manage_options', 'threadtandem', array( __CLASS__, 'render' ) );
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
		// Run before WordPress rejects an unregistered legacy page in includes/menu.php.
		add_action( 'admin_menu', array( __CLASS__, 'redirect_legacy_page' ), 99 );
	}

	public static function redirect_legacy_page() {
		global $pagenow;
		if ( $pagenow !== 'options-general.php' || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Query parameters select an existing admin page; no settings or queue jobs are changed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$legacy = array( 'treda' => 'threadtandem', 'treda-sync' => 'threadtandem-sync' );
		if ( ! isset( $legacy[ $page ] ) ) {
			return;
		}
		$args = array( 'page' => $legacy[ $page ] );
		foreach ( array( 'view', 's', 'paged', 'bulk' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function navigation( $active ) {
		$tabs = array(
			'settings' => array( 'page' => 'threadtandem', 'label' => __( 'Settings and queue', 'threadtandem-sync-for-telegram' ) ),
			'sync' => array( 'page' => 'threadtandem-sync', 'label' => __( 'Post synchronization', 'threadtandem-sync-for-telegram' ) ),
		);
		echo '<nav class="treda-page-tabs" aria-label="ThreadTandem">';
		foreach ( $tabs as $key => $tab ) {
			echo '<a href="' . esc_url( add_query_arg( 'page', $tab['page'], admin_url( 'options-general.php' ) ) ) . '"' . ( $key === $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab['label'] ) . '</a>';
		}
		echo '</nav>';
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'settings_page_threadtandem', 'settings_page_threadtandem-sync' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'treda-admin', plugins_url( 'assets/admin.css', TREDA_FILE ), array(), (string) filemtime( TREDA_DIR . 'assets/admin.css' ) );
		if ( $hook !== 'settings_page_threadtandem' ) {
			return;
		}
		wp_enqueue_script( 'treda-admin', plugins_url( 'assets/admin.js', TREDA_FILE ), array(), TREDA_VERSION, true );
		wp_localize_script(
			'treda-admin',
			'tredaAdmin',
			array(
				'show'       => __( 'Show', 'threadtandem-sync-for-telegram' ),
				'hide'       => __( 'Hide', 'threadtandem-sync-for-telegram' ),
				'copied'     => __( 'Copied', 'threadtandem-sync-for-telegram' ),
				'copyManual' => __( 'Address selected. Copy it with your keyboard.', 'threadtandem-sync-for-telegram' ),
				'unsaved'    => __( 'You have unsaved changes. Save them before checking the connection.', 'threadtandem-sync-for-telegram' ),
			)
		);
	}

	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage ThreadTandem.', 'threadtandem-sync-for-telegram' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'treda_action' );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$result    = true;
		$message   = '';
		if ( $operation === 'check' ) {
			$result  = Treda_API::check();
			$message = __( 'Bot permissions checked. Webhook delivery still needs a real incoming event.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'webhook' ) {
			$result  = Treda_API::register_webhook();
			$message = __( 'Webhook registered. Check incoming events in the queue.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'disconnect' ) {
			$result  = Treda_API::disconnect_webhook();
			$message = __( 'The ThreadTandem webhook is disconnected.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'recover' ) {
			$result = Treda_API::recover_updates();
			$message = __( 'Pending Telegram events were received and the webhook was restored. Process the queue to import them.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'run' ) {
			Treda_Queue::run();
			$message = __( 'A queue batch was processed. See the results below.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'retry' ) {
			$id      = isset( $_POST['job_id'] ) && is_scalar( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
			$changed = Treda_Queue::retry( $id, ! empty( $_POST['acknowledge'] ) );
			$message = $changed ? __( 'The job was queued again.', 'threadtandem-sync-for-telegram' )
				: __( 'The job was not changed. Confirm a Telegram check before retrying an uncertain result.', 'threadtandem-sync-for-telegram' );
		} else {
			$result = new WP_Error( 'treda_action', __( 'Unknown action.', 'threadtandem-sync-for-telegram' ) );
		}
		set_transient(
			'treda_notice_' . get_current_user_id(),
			array(
				'error' => is_wp_error( $result ),
				'text'  => is_wp_error( $result ) ? $result->get_error_message() : $message,
			),
			60
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=threadtandem' ) );
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
