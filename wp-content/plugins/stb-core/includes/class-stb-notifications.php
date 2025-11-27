<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_Notification_Service {

	const CRON_HOOK     = 'stb_notifications_process_queue';
	const CRON_INTERVAL = 'stb_five_minutes';
	const DEFAULT_LIMIT = 25;

	protected static $queue_table;

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
		add_action( 'stb_notify_event', array( __CLASS__, 'handle_event' ), 10, 2 );
	}

	public static function register_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 Minutes (Storbystand Notifications)', 'stb-core' ),
			);
		}

		return $schedules;
	}

	public static function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Public entry point for other modules to queue notification events.
	 *
	 * @param string $event_type
	 * @param array  $context
	 */
	public static function handle_event( string $event_type, array $context = array() ): void {
		$recipients = self::normalize_recipients( $context );

		if ( empty( $recipients ) ) {
			return;
		}

		foreach ( $recipients as $user_id ) {
			$channels = self::resolve_channels( $user_id, $context, $event_type );

			foreach ( $channels as $channel ) {
				if ( ! self::user_allows_channel( $user_id, $channel, $event_type ) ) {
					continue;
				}

				$payload = self::build_payload( $event_type, $context, $user_id, $channel );

				self::queue_row(
					array(
						'entity_type' => $context['entity_type'] ?? ( ! empty( $context['shift_id'] ) ? 'shift' : 'generic' ),
						'entity_id'   => isset( $context['entity_id'] ) ? absint( $context['entity_id'] ) : absint( $context['shift_id'] ?? 0 ),
						'recipient_id'=> $user_id,
						'channel'     => $channel,
						'event_type'  => $event_type,
						'scheduled_at'=> self::normalize_schedule_time( $context['schedule_at'] ?? null, $event_type, $context ),
					),
					$payload
				);
			}
		}
	}

	/**
	 * Convenience helper for CLI/tests.
	 */
	public static function queue_custom_notification( array $config ): void {
		$event = $config['event_type'] ?? 'custom';
		self::handle_event( $event, $config );
	}

	/**
	 * Process queued notifications.
	 */
	public static function process_queue( int $limit = self::DEFAULT_LIMIT ): int {
		global $wpdb;

		$table = self::queue_table();
		$now   = current_time( 'mysql' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status IN (%s,%s)
				 AND (scheduled_at IS NULL OR scheduled_at <= %s)
				 ORDER BY cct_created ASC
				 LIMIT %d",
				'queued',
				'retry',
				$now,
				$limit
			)
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$processed = 0;

		foreach ( $rows as $row ) {
			$payload = json_decode( $row->payload_json ?? '', true ) ?: array();

			try {
				self::deliver_notification( $row, $payload );
				$processed ++;
			} catch ( \Throwable $exception ) {
				self::mark_failed( (int) $row->_ID, $exception->getMessage() );
			}
		}

		return $processed;
	}

	/**
	 * Actually send one notification row.
	 */
	protected static function deliver_notification( \stdClass $row, array $payload ): void {
		switch ( $row->channel ) {
			case 'push':
				self::deliver_push( $row, $payload );
				break;
			case 'sms':
				self::deliver_sms( $row, $payload );
				break;
			default:
				self::deliver_email( $row, $payload );
		}

		self::mark_sent( (int) $row->_ID, $payload['provider_message_id'] ?? '' );
	}

	protected static function deliver_email( \stdClass $row, array $payload ): void {
		$user = get_userdata( $row->recipient_id );

		if ( ! $user || empty( $user->user_email ) ) {
			throw new \RuntimeException( 'Recipient does not have a valid email address.' );
		}

		$body    = self::format_email_body( $payload, $row );
		$subject = $payload['subject'] ?? __( 'New notification', 'stb-core' );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( self::should_simulate_email() ) {
			do_action( 'stb_notifications/email_simulated', $row, $payload, $body );
			return;
		}

		if ( class_exists( '\FluentCrm\App\Services\Libs\Mailer\Mailer' ) ) {
			$result = \FluentCrm\App\Services\Libs\Mailer\Mailer::send(
				array(
					'to'      => array(
						'email' => $user->user_email,
						'name'  => $user->display_name,
					),
					'subject' => $subject,
					'body'    => $body,
					'headers' => array(
						'From'     => get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
						'Reply-To' => get_option( 'admin_email' ),
					),
				)
			);
		} else {
			$result = wp_mail( $user->user_email, $subject, $body, $headers );
		}

		if ( ! $result ) {
			throw new \RuntimeException( 'Email transport failed.' );
		}
	}

	protected static function deliver_push( \stdClass $row, array $payload ): void {
		$tokens = self::get_push_tokens( (int) $row->recipient_id );

		if ( empty( $tokens ) ) {
			throw new \RuntimeException( 'No push tokens registered for recipient.' );
		}

		/**
		 * Let integrators implement push delivery (e.g. Firebase/Web Push).
		 */
		do_action(
			'stb_notifications/send_push',
			$row,
			$payload,
			$tokens
		);
	}

	protected static function deliver_sms( \stdClass $row, array $payload ): void {
		$handled = apply_filters( 'stb_notifications/send_sms', false, $row, $payload );

		if ( ! $handled ) {
			throw new \RuntimeException( 'SMS channel is not configured.' );
		}
	}

	protected static function format_email_body( array $payload, \stdClass $row ): string {
		$body      = $payload['body'] ?? __( 'You have a new update in Storbystand.', 'stb-core' );
		$cta_label = $payload['cta'] ?? __( 'Open dashboard', 'stb-core' );
		$link      = ! empty( $payload['deep_link'] ) ? esc_url( home_url( ltrim( $payload['deep_link'], '/' ) ) ) : home_url();

		ob_start();
		?>
		<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#111;padding:16px;">
			<p><?php echo wp_kses_post( nl2br( $body ) ); ?></p>
			<p>
				<a href="<?php echo esc_url( $link ); ?>" style="display:inline-block;padding:10px 16px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:4px;">
					<?php echo esc_html( $cta_label ); ?>
				</a>
			</p>
			<p style="font-size:12px;color:#666;">
				<?php esc_html_e( 'You are receiving this email because you are registered on Storbystand.', 'stb-core' ); ?>
			</p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	protected static function mark_sent( int $row_id, string $provider_id = '' ): void {
		global $wpdb;

		$wpdb->update(
			self::queue_table(),
			array(
				'status'              => 'sent',
				'processed_at'        => current_time( 'mysql' ),
				'provider_message_id' => $provider_id,
				'last_error'          => '',
				'cct_modified'        => current_time( 'mysql' ),
			),
			array( '_ID' => $row_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	protected static function mark_failed( int $row_id, string $message ): void {
		global $wpdb;

		$wpdb->update(
			self::queue_table(),
			array(
				'status'       => 'failed',
				'last_error'   => wp_strip_all_tags( $message ),
				'processed_at' => current_time( 'mysql' ),
				'cct_modified' => current_time( 'mysql' ),
			),
			array( '_ID' => $row_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	protected static function queue_row( array $row, array $payload ): void {
		global $wpdb;

		$wpdb->insert(
			self::queue_table(),
			array(
				'entity_type'         => sanitize_key( $row['entity_type'] ?? 'generic' ),
				'entity_id'           => absint( $row['entity_id'] ?? 0 ),
				'recipient_id'        => absint( $row['recipient_id'] ?? 0 ),
				'channel'             => sanitize_key( $row['channel'] ?? 'email' ),
				'event_type'          => sanitize_key( $row['event_type'] ?? 'custom' ),
				'deep_link'           => $payload['deep_link'] ?? '',
				'payload_json'        => wp_json_encode( $payload ),
				'status'              => $row['status'] ?? 'queued',
				'last_error'          => '',
				'provider_message_id' => '',
				'scheduled_at'        => $row['scheduled_at'] ?? current_time( 'mysql' ),
				'processed_at'        => null,
				'cct_status'          => 'publish',
				'cct_author_id'       => get_current_user_id() ?: 0,
				'cct_created'         => current_time( 'mysql' ),
				'cct_modified'        => current_time( 'mysql' ),
			),
			array(
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
			)
		);
	}

	protected static function queue_table(): string {
		if ( ! self::$queue_table ) {
			global $wpdb;
			self::$queue_table = $wpdb->prefix . 'jet_cct_stb_notification_queue';
		}

		return self::$queue_table;
	}

	protected static function normalize_recipients( array $context ): array {
		$recipients = array();

		if ( isset( $context['recipients'] ) ) {
			$list = is_array( $context['recipients'] ) ? $context['recipients'] : array( $context['recipients'] );

			foreach ( $list as $maybe_user ) {
				if ( $maybe_user instanceof \WP_User ) {
					$recipients[] = (int) $maybe_user->ID;
				} elseif ( is_numeric( $maybe_user ) ) {
					$recipients[] = (int) $maybe_user;
				}
			}
		} elseif ( ! empty( $context['recipient_id'] ) ) {
			$recipients[] = (int) $context['recipient_id'];
		} elseif ( ! empty( $context['assignment_id'] ) ) {
			$assignment = self::get_assignment( (int) $context['assignment_id'] );
			if ( $assignment && ! empty( $assignment->user_id ) ) {
				$recipients[] = (int) $assignment->user_id;
			}
		}

		return array_values( array_unique( array_filter( $recipients ) ) );
	}

	protected static function resolve_channels( int $user_id, array $context, string $event_type ): array {
		$channels = $context['channels'] ?? null;

		if ( is_string( $channels ) ) {
			$channels = array_map( 'trim', explode( ',', $channels ) );
		}

		if ( empty( $channels ) ) {
			$template = self::get_event_template( $event_type );
			$channels = $template['channels'] ?? array( 'email' );
		}

		$channels = array_map( 'sanitize_key', (array) $channels );

		$channels = array_values(
			array_filter(
				array_unique( $channels ),
				static function ( $channel ) use ( $user_id, $event_type ) {
					return self::user_allows_channel( $user_id, $channel, $event_type );
				}
			)
		);

		return $channels ?: array( 'email' );
	}

	protected static function user_allows_channel( int $user_id, string $channel, string $event_type ): bool {
		$prefs = self::get_user_preferences( $user_id );

		if ( isset( $prefs['channels'][ $channel ] ) && false === $prefs['channels'][ $channel ] ) {
			return false;
		}

		if ( isset( $prefs['events'][ $event_type ] ) && false === $prefs['events'][ $event_type ] ) {
			return false;
		}

		if ( 'email' === $channel ) {
			$user = get_userdata( $user_id );
			return ( $user && ! empty( $user->user_email ) );
		}

		if ( 'push' === $channel ) {
			return ! empty( self::get_push_tokens( $user_id ) );
		}

		return true;
	}

	protected static function get_user_preferences( int $user_id ): array {
		$prefs = get_user_meta( $user_id, 'stb_notification_prefs', true );

		if ( is_array( $prefs ) ) {
			return wp_parse_args(
				$prefs,
				array(
					'channels' => array(),
					'events'   => array(),
				)
			);
		}

		return array(
			'channels' => array(
				'email' => true,
				'push'  => false,
				'sms'   => false,
			),
			'events'   => array(),
		);
	}

	protected static function get_push_tokens( int $user_id ): array {
		$tokens = get_user_meta( $user_id, 'stb_push_tokens', true );

		if ( ! is_array( $tokens ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_text_field', $tokens ) ) );
	}

	protected static function build_payload( string $event_type, array $context, int $user_id, string $channel ): array {
		$template = self::get_event_template( $event_type );
		$user     = get_userdata( $user_id );
		$shift    = null;

		if ( ! empty( $context['shift'] ) && is_array( $context['shift'] ) ) {
			$shift = (object) $context['shift'];
		} elseif ( ! empty( $context['shift_id'] ) ) {
			$shift = self::get_shift( (int) $context['shift_id'] );
		}

		$tokens = array(
			'user_name'  => $user ? $user->display_name : __( 'there', 'stb-core' ),
			'shift_date' => $shift && ! empty( $shift->shift_date ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $shift->shift_date ) ) : '',
			'shift_slot' => $shift->slot_id ?? '',
			'location'   => $shift && ! empty( $shift->location_id ) ? sprintf( __( 'Location %s', 'stb-core' ), $shift->location_id ) : __( 'assigned location', 'stb-core' ),
			'event_type' => esc_html( ucwords( str_replace( '_', ' ', $event_type ) ) ),
			'site_name'  => get_bloginfo( 'name' ),
		);

		$payload = $context['payload'] ?? array();

		if ( empty( $payload['subject'] ) && ! empty( $template['subject'] ) && 'email' === $channel ) {
			$payload['subject'] = self::tokenize( $template['subject'], $tokens );
		}

		if ( empty( $payload['body'] ) && ! empty( $template['body'] ) ) {
			$payload['body'] = self::tokenize( $template['body'], $tokens );
		}

		if ( empty( $payload['deep_link'] ) ) {
			if ( ! empty( $context['deep_link'] ) ) {
				$payload['deep_link'] = $context['deep_link'];
			} elseif ( ! empty( $template['deep_link'] ) ) {
				$payload['deep_link'] = $template['deep_link'];
			} else {
				$payload['deep_link'] = '/dashboard';
			}
		}

		$payload['cta'] = $payload['cta'] ?? __( 'Open dashboard', 'stb-core' );

		return $payload;
	}

	protected static function get_event_template( string $event_type ): array {
		$templates = array(
			'assignment_confirmed' => array(
				'subject'   => __( 'You are confirmed for %shift_date% (%shift_slot%)', 'stb-core' ),
				'body'      => __( 'Hi %user_name%, you are confirmed for %shift_date% at %shift_slot% in %location%.', 'stb-core' ),
				'channels'  => array( 'email', 'push' ),
				'deep_link' => '/dashboard/shifts',
			),
			'assignment_cancelled' => array(
				'subject'   => __( 'Shift update for %shift_date%', 'stb-core' ),
				'body'      => __( 'Your assignment on %shift_date% has changed. Review the dashboard for updated details.', 'stb-core' ),
				'channels'  => array( 'email', 'push' ),
				'deep_link' => '/dashboard/shifts',
			),
			'reminder_24h'         => array(
				'subject'   => __( 'Reminder: shift on %shift_date%', 'stb-core' ),
				'body'      => __( 'Friendly reminder that your shift starts on %shift_date% at %shift_slot%.', 'stb-core' ),
				'channels'  => array( 'email', 'push' ),
				'deep_link' => '/dashboard/shifts',
			),
			'reminder_1h'          => array(
				'subject'   => __( 'Your shift starts soon', 'stb-core' ),
				'body'      => __( 'You have one hour until your %shift_slot% shift at %location%.', 'stb-core' ),
				'channels'  => array( 'push' ),
				'deep_link' => '/dashboard/shifts',
			),
		);

		return $templates[ $event_type ] ?? array(
			'body'      => __( 'You have a new notification.', 'stb-core' ),
			'channels'  => array( 'email' ),
			'deep_link' => '/dashboard',
		);
	}

	protected static function tokenize( string $text, array $tokens ): string {
		foreach ( $tokens as $token => $value ) {
			$text = str_replace( '%' . $token . '%', (string) $value, $text );
		}

		return $text;
	}

	protected static function normalize_schedule_time( $value, string $event_type, array $context ): string {
		if ( $value ) {
			$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
			if ( $timestamp ) {
				return gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		if ( in_array( $event_type, array( 'reminder_24h', 'reminder_1h' ), true ) && ! empty( $context['shift_id'] ) ) {
			$shift_time = self::infer_shift_timestamp( (int) $context['shift_id'] );

			if ( $shift_time ) {
				$modifier = 'reminder_1h' === $event_type ? '-1 hour' : '-1 day';
				return gmdate( 'Y-m-d H:i:s', strtotime( $modifier, $shift_time ) );
			}
		}

		return current_time( 'mysql' );
	}

	protected static function get_shift( int $shift_id ): ?\stdClass {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}jet_cct_stb_shifts WHERE _ID = %d",
				$shift_id
			)
		);

		return $row ?: null;
	}

	protected static function get_assignment( int $assignment_id ): ?\stdClass {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}jet_cct_stb_assignments WHERE _ID = %d",
				$assignment_id
			)
		);

		return $row ?: null;
	}

	protected static function infer_shift_timestamp( int $shift_id ): ?int {
		$shift = self::get_shift( $shift_id );

		if ( ! $shift || empty( $shift->shift_date ) ) {
			return null;
		}

		$slot = ! empty( $shift->slot_id ) ? $shift->slot_id : '09:00';

		return strtotime( "{$shift->shift_date} {$slot}" ) ?: null;
	}

	protected static function should_simulate_email(): bool {
		return defined( 'STB_SIMULATE_EMAILS' ) ? (bool) STB_SIMULATE_EMAILS : false;
	}
}
