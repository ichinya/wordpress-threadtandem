<?php
/** Durable jobs and compare-and-swap leases. */
defined( 'ABSPATH' ) || exit;

// Queue claims and leases require uncached, atomic SQL; WordPress has no queue API.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Treda_Queue {
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'treda_jobs';
	}

	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Treda separately on each site, rather than network-wide.', 'treda' ) );
		}
		self::install();
		if ( get_option( 'treda_schema_version' ) !== TREDA_SCHEMA_VERSION ) {
			wp_die( esc_html__( 'Treda could not create its database tables.', 'treda' ) );
		}
	}

	public static function hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'treda_tick', array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( 'treda_tick' ) ) {
			wp_schedule_event( time() + 60, 'treda_minute', 'treda_tick' );
		}
	}

	public static function schedules( $schedules ) {
		$schedules['treda_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Treda: every minute', 'treda' ),
		);
		return $schedules;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_key varchar(191) NOT NULL,
            kind varchar(20) NOT NULL,
            payload longtext NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            locked_at datetime DEFAULT NULL,
            lock_token varchar(64) NOT NULL DEFAULT '',
            last_error text NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_key (job_key),
            KEY ready (status,available_at)
        ) ENGINE=InnoDB $collate;"
		);
		Treda_Messages::install();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Treda_Messages::table() ) ) ) === Treda_Messages::table() ) {
			update_option( 'treda_schema_version', TREDA_SCHEMA_VERSION, false );
		}
		Treda_Settings::secret();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'treda_tick' );
	}

	/** A single mutable job serializes each local entity, including concurrent edits. */
	private static function local( $kind, $id ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		return false !== $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (job_key,kind,payload,available_at,updated_at,last_error)
             VALUES (%s,%s,%s,%s,%s,'')
             ON DUPLICATE KEY UPDATE payload=VALUES(payload),revision=revision+1,
                attempts=IF(status IN ('running','uncertain'),attempts,0),
                status=IF(status IN ('running','uncertain'),status,'pending'),
                available_at=VALUES(available_at),updated_at=VALUES(updated_at)",
				self::table(),
				$kind . ':' . (int) $id,
				$kind,
				wp_json_encode(
					array(
						$kind . '_id' => (int) $id,
						'connection'  => Treda_Settings::fingerprint(),
					)
				),
				$now,
				$now
			)
		);
	}

	public static function post( $post_id ) {
		return self::local( 'post', $post_id );
	}

	public static function comment( $comment_id ) {
		return self::local( 'comment', $comment_id );
	}

	/** A duplicate preserves its durable receipt, without hiding other SQL errors. */
	public static function event( $update ) {
		global $wpdb;
		$connection = Treda_Settings::connection();
		if ( ! $connection ) {
			return false;
		}
		$key = 'update:' . $connection['bot_id'] . ':' . $update['update_id'];
		$now = gmdate( 'Y-m-d H:i:s' );
		return false !== $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (job_key,kind,payload,available_at,updated_at,last_error)
             VALUES (%s,'update',%s,%s,%s,'') ON DUPLICATE KEY UPDATE id=id",
				self::table(),
				$key,
				wp_json_encode(
					array(
						'update'     => $update,
						'connection' => Treda_Settings::fingerprint(),
					)
				),
				$now,
				$now
			)
		);
	}

	public static function run() {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status=IF(kind='update','pending','uncertain'),lock_token='',
                last_error=%s,updated_at=%s WHERE status='running' AND locked_at<%s",
				self::table(),
				__( 'The worker stopped. Check Telegram before retrying an outgoing operation.', 'treda' ),
				$now,
				gmdate( 'Y-m-d H:i:s', time() - 300 )
			)
		);
		$started = microtime( true );
		for ( $i = 0; $i < 3 && microtime( true ) - $started < 35; $i++ ) {
			$job = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE status='pending' AND available_at<=%s ORDER BY id LIMIT 1",
					self::table(),
					gmdate( 'Y-m-d H:i:s' )
				)
			);
			if ( ! $job ) {
				break;
			}
			$token   = wp_generate_password( 32, false, false );
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status='running',locked_at=%s,lock_token=%s,attempts=attempts+1
                 WHERE id=%d AND status='pending' AND revision=%d",
					self::table(),
					gmdate( 'Y-m-d H:i:s' ),
					$token,
					$job->id,
					$job->revision
				)
			);
			if ( $claimed !== 1 ) {
				continue;
			}
			$payload = json_decode( $job->payload, true );
			try {
				if ( ! is_array( $payload ) ) {
					$result = new WP_Error( 'treda_payload', __( 'Invalid job data.', 'treda' ), array( 'state' => 'failed' ) );
				} elseif ( $job->kind === 'post' ) {
					$result = Treda_Publisher::publish( $payload );
				} elseif ( $job->kind === 'comment' ) {
					$result = Treda_Comments::publish( $payload );
				} elseif ( $job->kind === 'update' ) {
					$result = Treda_Webhook::process( $payload );
				} else {
					$result = new WP_Error( 'treda_kind', __( 'Unknown job type.', 'treda' ), array( 'state' => 'failed' ) );
				}
			} catch ( Throwable $error ) {
				$wpdb->query( 'ROLLBACK' );
				$result = new WP_Error(
					'treda_exception',
					__( 'The worker encountered an error. Check diagnostics.', 'treda' ),
					array(
						'state' => $job->kind === 'update' ? 'failed' : 'uncertain',
					)
				);
			}
			self::finish( $job, $token, $result );
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE kind='update' AND status IN ('done','failed') AND updated_at<%s",
				self::table(),
				gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
			)
		);
	}

	private static function finish( $job, $token, $result ) {
		global $wpdb;
		$state = 'done';
		$error = '';
		$delay = 0;
		if ( is_wp_error( $result ) ) {
			$data  = $result->get_error_data();
			$state = $data['state'] ?? 'failed';
			$state = in_array( $state, array( 'pending', 'failed', 'uncertain' ), true ) ? $state : 'failed';
			$error = $result->get_error_message();
			$delay = max( 5, (int) ( $data['delay'] ?? min( 3600, 30 * pow( 2, min( 7, (int) $job->attempts ) ) ) ) );
			if ( $state === 'pending' && (int) $job->attempts >= 7 ) {
				$state = 'failed';
			}
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status=IF(revision<>%d AND %s<>'uncertain','pending',%s),
                last_error=%s,available_at=%s,updated_at=%s,lock_token='',locked_at=NULL,
                payload=IF(kind='update' AND %s='done','{}',payload)
             WHERE id=%d AND status='running' AND lock_token=%s",
				self::table(),
				$job->revision,
				$state,
				$state,
				$error,
				gmdate( 'Y-m-d H:i:s', time() + $delay ),
				gmdate( 'Y-m-d H:i:s' ),
				$state,
				$job->id,
				$token
			)
		);
	}

	public static function recent() {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id,job_key,kind,status,attempts,last_error,updated_at FROM %i ORDER BY updated_at DESC,id DESC LIMIT 20',
				self::table()
			)
		);
	}

	public static function counts() {
		global $wpdb;
		$counts = array_fill_keys( array( 'pending', 'running', 'done', 'failed', 'uncertain' ), 0 );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS total FROM %i GROUP BY status', self::table() ) );
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->total;
			}
		}
		return $counts;
	}

	public static function retry( $id, $acknowledged ) {
		global $wpdb;
		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status='pending',attempts=0,last_error='',available_at=%s,updated_at=%s
             WHERE id=%d AND (status='failed' OR (status='uncertain' AND %d=1))",
				self::table(),
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				(int) $acknowledged
			)
		);
	}
}
