<?php
/** Capability-protected settings and queue controls. */
defined( 'ABSPATH' ) || exit;

final class ThreadTandem_Admin {
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
					'threadtandem',
					'threadtandem_settings',
					array(
						'type'              => 'array',
						'sanitize_callback' => array(
							'ThreadTandem_Settings',
							'sanitize',
						),
						'show_in_rest'      => false,
					)
				);
			}
		);
		add_action( 'admin_post_threadtandem_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function navigation( $active ) {
		$tabs = array(
			'settings' => array( 'page' => 'threadtandem', 'label' => __( 'Settings and queue', 'threadtandem-sync-for-telegram' ) ),
			'sync' => array( 'page' => 'threadtandem-sync', 'label' => __( 'Post synchronization', 'threadtandem-sync-for-telegram' ) ),
		);
		echo '<nav class="threadtandem-page-tabs" aria-label="ThreadTandem">';
		foreach ( $tabs as $key => $tab ) {
			echo '<a href="' . esc_url( add_query_arg( 'page', $tab['page'], admin_url( 'options-general.php' ) ) ) . '"' . ( $key === $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab['label'] ) . '</a>';
		}
		echo '</nav>';
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'settings_page_threadtandem', 'settings_page_threadtandem-sync' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'threadtandem-admin', plugins_url( 'assets/admin.css', THREADTANDEM_FILE ), array(), (string) filemtime( THREADTANDEM_DIR . 'assets/admin.css' ) );
		if ( $hook !== 'settings_page_threadtandem' ) {
			return;
		}
		wp_enqueue_script( 'threadtandem-admin', plugins_url( 'assets/admin.js', THREADTANDEM_FILE ), array(), (string) filemtime( THREADTANDEM_DIR . 'assets/admin.js' ), true );
		wp_localize_script(
			'threadtandem-admin',
			'threadtandemAdmin',
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
		check_admin_referer( 'threadtandem_action' );
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$result    = true;
		$message   = '';
		if ( $operation === 'check' ) {
			$result  = ThreadTandem_API::check();
			if ( ! is_wp_error( $result ) && ThreadTandem_Settings::updates_mode() === 'polling' && ThreadTandem_Updates::state()['enabled'] ) { $result = ThreadTandem_Updates::connect(); }
			$message = __( 'Bot permissions checked. Verify a real incoming event using the selected delivery mode.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'webhook' ) {
			$result  = ThreadTandem_Updates::connect();
			$message = ThreadTandem_Settings::updates_mode() === 'polling' ? __( 'Cron polling enabled. The webhook was removed without discarding pending events.', 'threadtandem-sync-for-telegram' ) : __( 'Webhook registered. Check incoming events in the queue.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'disconnect' ) {
			$result  = ThreadTandem_Updates::disconnect();
			$message = __( 'Receiving Telegram events is paused.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'recover' ) {
			$result = ThreadTandem_Updates::recover();
			$message = ThreadTandem_Settings::updates_mode() === 'polling' ? __( 'A Telegram update batch was received. The webhook remains disabled.', 'threadtandem-sync-for-telegram' ) : __( 'Pending Telegram events were received and the webhook was restored. Process the queue to import them.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'run' ) {
			ThreadTandem_Queue::run();
			$message = __( 'A queue batch was processed. See the results below.', 'threadtandem-sync-for-telegram' );
		} elseif ( $operation === 'retry' ) {
			$id      = isset( $_POST['job_id'] ) && is_scalar( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
			$changed = ThreadTandem_Queue::retry( $id, ! empty( $_POST['acknowledge'] ) );
			$message = $changed ? __( 'The job was queued again.', 'threadtandem-sync-for-telegram' )
				: __( 'The job was not changed. Confirm a Telegram check before retrying an uncertain result.', 'threadtandem-sync-for-telegram' );
		} else {
			$result = new WP_Error( 'threadtandem_action', __( 'Unknown action.', 'threadtandem-sync-for-telegram' ) );
		}
		set_transient(
			'threadtandem_notice_' . get_current_user_id(),
			array(
				'error' => is_wp_error( $result ),
				'text'  => is_wp_error( $result ) ? $result->get_error_message() : $message,
			),
			60
		);
		$url = admin_url( 'options-general.php?page=threadtandem' );
		if ( in_array( $operation, array( 'run', 'retry' ), true ) ) {
			$view = self::diagnostic_request( $_POST, 'attention' );
			$url = add_query_arg( $view, $url ) . '#threadtandem-diagnostics';
		}
		wp_safe_redirect( $url );
		exit;
	}

	private static function action_form( $operation, $label, $class = 'secondary', $disabled = false, $context = array() ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="threadtandem-inline-form" data-threadtandem-action-form>';
		wp_nonce_field( 'threadtandem_action' );
		echo '<input type="hidden" name="action" value="threadtandem_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		foreach ( $context as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
		submit_button( $label, $class, 'submit', false, $disabled ? array( 'disabled' => true ) : array() );
		echo '</form>';
	}

	private static function diagnostic_request( $input, $default ) {
		return array(
			'queue_status' => ThreadTandem_Queue::diagnostic_status( isset( $input['queue_status'] ) && is_string( $input['queue_status'] ) ? wp_unslash( $input['queue_status'] ) : $default ),
			'queue_page' => isset( $input['queue_page'] ) && is_scalar( $input['queue_page'] ) ? max( 1, absint( $input['queue_page'] ) ) : 1,
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings   = ThreadTandem_Settings::get();
		$connection = ThreadTandem_Settings::connection();
		$counts     = ThreadTandem_Queue::counts();
		// These GET parameters only filter the read-only delivery history.
		$view       = self::diagnostic_request( $_GET, $counts['failed'] || $counts['uncertain'] ? 'attention' : 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$history_open = $counts['failed'] || $counts['uncertain'] || isset( $_GET['queue_status'] ) || isset( $_GET['queue_page'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$history    = ThreadTandem_Queue::diagnostics( $view['queue_status'], $view['queue_page'] );
		$view['queue_page'] = $history['page'];
		$jobs       = $history['jobs'];
		$notice     = get_transient( 'threadtandem_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'threadtandem_notice_' . get_current_user_id() );
		}
		require THREADTANDEM_DIR . 'includes/admin-view.php';
	}
}
