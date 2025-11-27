<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_Stats {

	const VERSION   = '0.1.0';
	const CRON_HOOK = 'stb_stats_generate_daily';

	/**
	 * Bootstrap scheduled jobs and endpoints.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'generate_daily_snapshots' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Ensure the daily cron exists.
	 */
	public static function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$timestamp = self::next_run_timestamp();
			wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Default handler for the cron event.
	 */
	public static function generate_daily_snapshots(): array {
		return self::generate_for_period( 'day' );
	}

	/**
	 * Manually trigger the generator for a period.
	 *
	 * @param string $period Period slug (day|week|month).
	 * @param string $reference_date Optional date string parsable by DateTime.
	 *
	 * @return array<int>
	 */
	public static function generate_for_period( string $period = 'day', string $reference_date = '' ): array {
		$bounds = self::get_period_bounds( $period, $reference_date );

		if ( empty( $bounds ) ) {
			return array();
		}

		$range_start = $bounds['shift_range_start'];
		$range_end   = $bounds['shift_range_end'];

		$global_metrics = self::query_global_metrics( $range_start, $range_end );
		$notifications  = self::query_notification_metrics( $bounds['datetime_start'], $bounds['datetime_end'] );

		$global_metrics['notifications'] = $notifications;

		$ids   = array();
		$ids[] = self::store_snapshot(
			'global',
			0,
			__( 'All Locations', 'stb-core' ),
			$period,
			$bounds,
			$global_metrics
		);

		foreach ( self::query_user_rollups( $range_start, $range_end ) as $user_id => $metrics ) {
			$user = get_userdata( $user_id );
			$ids[] = self::store_snapshot(
				'user',
				$user_id,
				$user ? $user->display_name : sprintf( __( 'User #%d', 'stb-core' ), $user_id ),
				$period,
				$bounds,
				$metrics
			);
		}

		foreach ( self::query_location_rollups( $range_start, $range_end ) as $location_id => $metrics ) {
			$location = get_post( $location_id );
			$label    = $location ? $location->post_title : sprintf( __( 'Location #%d', 'stb-core' ), $location_id );

			$ids[] = self::store_snapshot(
				'location',
				$location_id,
				$label,
				$period,
				$bounds,
				$metrics
			);
		}

		return array_filter( $ids );
	}

	/**
	 * REST route for retrieving the latest snapshot.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'storbystand/v1',
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'rest_permission_callback' ),
				'args'                => array(
					'scope'    => array(
						'type'    => 'string',
						'default' => 'global',
					),
					'scope_id' => array(
						'type'     => 'integer',
						'required' => false,
					),
					'period'   => array(
						'type'    => 'string',
						'default' => 'day',
					),
				),
				'callback'            => array( __CLASS__, 'rest_get_snapshot' ),
			)
		);
	}

	/**
	 * REST permission gate.
	 */
	public static function rest_permission_callback( WP_REST_Request $request ): bool {
		$scope   = $request['scope'] ?? 'global';
		$scope_id = (int) ( $request['scope_id'] ?? 0 );

		if ( 'global' === $scope || 'location' === $scope ) {
			return current_user_can( 'manage_options' ) || current_user_can( 'view_stats' );
		}

		if ( 'user' === $scope ) {
			if ( 0 === $scope_id ) {
				return is_user_logged_in();
			}

			return (int) get_current_user_id() === $scope_id || current_user_can( 'list_users' ) || current_user_can( 'view_stats' );
		}

		return is_user_logged_in();
	}

	/**
	 * Return a snapshot payload over REST.
	 */
	public static function rest_get_snapshot( WP_REST_Request $request ): WP_REST_Response {
		$scope    = sanitize_key( $request['scope'] ?? 'global' );
		$scope_id = (int) ( $request['scope_id'] ?? 0 );
		$period   = sanitize_key( $request['period'] ?? 'day' );

		if ( 'user' === $scope && 0 === $scope_id ) {
			$scope_id = get_current_user_id();
		}

		$post = get_posts(
			array(
				'post_type'      => 'stb_stats_snapshot',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'   => 'stb_scope_type',
						'value' => $scope,
					),
					array(
						'key'   => 'stb_scope_ref',
						'value' => $scope_id,
					),
					array(
						'key'   => 'stb_period_key',
						'value' => $period,
					),
				),
				'orderby'        => 'meta_value',
				'meta_key'       => 'stb_period_start',
				'order'          => 'DESC',
			)
		);

		if ( empty( $post ) ) {
			return new WP_REST_Response(
				array(
					'scope'    => $scope,
					'scope_id' => $scope_id,
					'period'   => $period,
					'metrics'  => array(),
				),
				200
			);
		}

		$post      = $post[0];
		$payload   = get_post_meta( $post->ID, 'stb_payload', true );
		$decoded   = $payload ? json_decode( $payload, true ) : array();
		$response  = array(
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'scope'      => get_post_meta( $post->ID, 'stb_scope_type', true ),
			'scope_id'   => (int) get_post_meta( $post->ID, 'stb_scope_ref', true ),
			'scope_label'=> get_post_meta( $post->ID, 'stb_scope_label', true ),
			'period'     => get_post_meta( $post->ID, 'stb_period_key', true ),
			'start'      => get_post_meta( $post->ID, 'stb_period_start', true ),
			'end'        => get_post_meta( $post->ID, 'stb_period_end', true ),
			'metrics'    => $decoded,
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Calculate when the next cron should run (roughly 02:00 server time).
	 */
	protected static function next_run_timestamp(): int {
		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$next     = $now->setTime( 2, 0, 0 );

		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	/**
	 * Determine start/end bounds for a period.
	 */
	protected static function get_period_bounds( string $period, string $reference_date ): array {
		try {
			$timezone = wp_timezone();
			$ref      = $reference_date ? new DateTimeImmutable( $reference_date, $timezone ) : new DateTimeImmutable( 'now', $timezone );
		} catch ( Exception $exception ) {
			return array();
		}

		switch ( $period ) {
			case 'week':
				$start = $ref->modify( 'monday this week' )->setTime( 0, 0, 0 );
				$end   = $start->modify( '+6 days' )->setTime( 23, 59, 59 );
				break;
			case 'month':
				$start = $ref->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$end   = $ref->modify( 'last day of this month' )->setTime( 23, 59, 59 );
				break;
			case 'day':
			default:
				$start = $ref->setTime( 0, 0, 0 );
				$end   = $ref->setTime( 23, 59, 59 );
				break;
		}

		return array(
			'datetime_start'    => $start,
			'datetime_end'      => $end,
			'shift_range_start' => $start->format( 'Y-m-d' ),
			'shift_range_end'   => $end->format( 'Y-m-d' ),
		);
	}

	/**
	 * Query global assignment metrics.
	 */
	protected static function query_global_metrics( string $shift_start, string $shift_end ): array {
		global $wpdb;

		$assignments = "{$wpdb->prefix}jet_cct_stb_assignments";
		$shifts      = "{$wpdb->prefix}jet_cct_stb_shifts";

		$sql = "
			SELECT
				SUM(CASE WHEN a.state = 'completed' THEN 1 ELSE 0 END) AS completed,
				SUM(CASE WHEN a.state IN ('dropped','cancelled_by_admin') THEN 1 ELSE 0 END) AS cancellations,
				SUM(
					CASE
						WHEN a.state IN ('dropped','cancelled_by_admin')
							AND a.cct_modified IS NOT NULL
							AND TIMESTAMPDIFF(HOUR, a.cct_modified, CONCAT(s.shift_date, ' 00:00:00')) BETWEEN 0 AND 24
						THEN 1 ELSE 0
					END
				) AS late_cancels,
				SUM(CASE WHEN a.joined_via = 'automation' THEN 1 ELSE 0 END) AS substitutions,
				SUM(CASE WHEN a.admin_override = 1 THEN 1 ELSE 0 END) AS overrides,
				SUM(
					CASE
						WHEN a.state = 'completed' AND a.check_in IS NOT NULL AND a.check_out IS NOT NULL
						THEN GREATEST(TIMESTAMPDIFF(MINUTE, a.check_in, a.check_out), 0)
						ELSE 0
					END
				) AS minutes,
				SUM(COALESCE(a.points_weight, 0)) AS load_score
			FROM {$assignments} a
			INNER JOIN {$shifts} s ON s._ID = a.shift_id
			WHERE s.shift_date BETWEEN %s AND %s
		";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $shift_start, $shift_end ) );

		return self::normalize_metric_row( $row );
	}

	/**
	 * Query per-user rollups.
	 */
	protected static function query_user_rollups( string $shift_start, string $shift_end ): array {
		global $wpdb;

		$assignments = "{$wpdb->prefix}jet_cct_stb_assignments";
		$shifts      = "{$wpdb->prefix}jet_cct_stb_shifts";

		$sql = "
			SELECT
				a.user_id,
				SUM(CASE WHEN a.state = 'completed' THEN 1 ELSE 0 END) AS completed,
				SUM(CASE WHEN a.state IN ('dropped','cancelled_by_admin') THEN 1 ELSE 0 END) AS cancellations,
				SUM(
					CASE
						WHEN a.state IN ('dropped','cancelled_by_admin')
							AND a.cct_modified IS NOT NULL
							AND TIMESTAMPDIFF(HOUR, a.cct_modified, CONCAT(s.shift_date, ' 00:00:00')) BETWEEN 0 AND 24
						THEN 1 ELSE 0
					END
				) AS late_cancels,
				SUM(CASE WHEN a.joined_via = 'automation' THEN 1 ELSE 0 END) AS substitutions,
				SUM(CASE WHEN a.admin_override = 1 THEN 1 ELSE 0 END) AS overrides,
				SUM(
					CASE
						WHEN a.state = 'completed' AND a.check_in IS NOT NULL AND a.check_out IS NOT NULL
						THEN GREATEST(TIMESTAMPDIFF(MINUTE, a.check_in, a.check_out), 0)
						ELSE 0
					END
				) AS minutes,
				SUM(COALESCE(a.points_weight, 0)) AS load_score
			FROM {$assignments} a
			INNER JOIN {$shifts} s ON s._ID = a.shift_id
			WHERE s.shift_date BETWEEN %s AND %s
			GROUP BY a.user_id
			HAVING completed > 0 OR cancellations > 0
			ORDER BY completed DESC
			LIMIT 50
		";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $shift_start, $shift_end ) );
		$output  = array();

		foreach ( $results as $row ) {
			$output[ (int) $row->user_id ] = self::normalize_metric_row( $row );
		}

		return $output;
	}

	/**
	 * Query per-location rollups.
	 */
	protected static function query_location_rollups( string $shift_start, string $shift_end ): array {
		global $wpdb;

		$assignments = "{$wpdb->prefix}jet_cct_stb_assignments";
		$shifts      = "{$wpdb->prefix}jet_cct_stb_shifts";

		$sql = "
			SELECT
				s.location_id,
				SUM(CASE WHEN a.state = 'completed' THEN 1 ELSE 0 END) AS completed,
				SUM(CASE WHEN a.state IN ('dropped','cancelled_by_admin') THEN 1 ELSE 0 END) AS cancellations,
				SUM(
					CASE
						WHEN a.state IN ('dropped','cancelled_by_admin')
							AND a.cct_modified IS NOT NULL
							AND TIMESTAMPDIFF(HOUR, a.cct_modified, CONCAT(s.shift_date, ' 00:00:00')) BETWEEN 0 AND 24
						THEN 1 ELSE 0
					END
				) AS late_cancels,
				SUM(CASE WHEN a.joined_via = 'automation' THEN 1 ELSE 0 END) AS substitutions,
				SUM(CASE WHEN a.admin_override = 1 THEN 1 ELSE 0 END) AS overrides,
				SUM(
					CASE
						WHEN a.state = 'completed' AND a.check_in IS NOT NULL AND a.check_out IS NOT NULL
						THEN GREATEST(TIMESTAMPDIFF(MINUTE, a.check_in, a.check_out), 0)
						ELSE 0
					END
				) AS minutes,
				SUM(COALESCE(a.points_weight, 0)) AS load_score
			FROM {$assignments} a
			INNER JOIN {$shifts} s ON s._ID = a.shift_id
			WHERE s.shift_date BETWEEN %s AND %s
			GROUP BY s.location_id
			HAVING completed > 0 OR cancellations > 0
			LIMIT 50
		";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $shift_start, $shift_end ) );
		$output  = array();

		foreach ( $results as $row ) {
			$output[ (int) $row->location_id ] = self::normalize_metric_row( $row );
		}

		return $output;
	}

	/**
	 * Query notification queue metrics.
	 */
	protected static function query_notification_metrics( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;

		$table     = "{$wpdb->prefix}jet_cct_stb_notification_queue";
		$start_str = $start->format( 'Y-m-d H:i:s' );
		$end_str   = $end->format( 'Y-m-d H:i:s' );

		$sql = "
			SELECT
				SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
				SUM(CASE WHEN channel = 'email' THEN 1 ELSE 0 END) AS channel_email,
				SUM(CASE WHEN channel = 'push' THEN 1 ELSE 0 END) AS channel_push,
				SUM(CASE WHEN channel = 'sms' THEN 1 ELSE 0 END) AS channel_sms,
				SUM(CASE WHEN event_type = 'reminder_24h' THEN 1 ELSE 0 END) AS reminder_24h,
				SUM(CASE WHEN event_type = 'reminder_1h' THEN 1 ELSE 0 END) AS reminder_1h
			FROM {$table}
			WHERE COALESCE(processed_at, scheduled_at, cct_created) BETWEEN %s AND %s
		";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $start_str, $end_str ) );

		return array(
			'sent'     => (int) ( $row->sent ?? 0 ),
			'failed'   => (int) ( $row->failed ?? 0 ),
			'channels' => array(
				'email' => (int) ( $row->channel_email ?? 0 ),
				'push'  => (int) ( $row->channel_push ?? 0 ),
				'sms'   => (int) ( $row->channel_sms ?? 0 ),
			),
			'reminders' => array(
				'reminder_24h' => (int) ( $row->reminder_24h ?? 0 ),
				'reminder_1h'  => (int) ( $row->reminder_1h ?? 0 ),
			),
		);
	}

	/**
	 * Convert a SQL row into the canonical metrics array.
	 */
	protected static function normalize_metric_row( ?stdClass $row ): array {
		if ( ! $row ) {
			return array(
				'total_shifts'   => 0,
				'total_hours'    => 0,
				'cancellations'  => 0,
				'late_cancels'   => 0,
				'substitutions'  => 0,
				'override_count' => 0,
				'load_score'     => 0,
			);
		}

		$hours = isset( $row->minutes ) ? round( ( (int) $row->minutes ) / 60, 2 ) : 0;

		return array(
			'total_shifts'   => (int) ( $row->completed ?? 0 ),
			'total_hours'    => $hours,
			'cancellations'  => (int) ( $row->cancellations ?? 0 ),
			'late_cancels'   => (int) ( $row->late_cancels ?? 0 ),
			'substitutions'  => (int) ( $row->substitutions ?? 0 ),
			'override_count' => (int) ( $row->overrides ?? 0 ),
			'load_score'     => (int) ( $row->load_score ?? 0 ),
		);
	}

	/**
	 * Insert or update a snapshot post.
	 */
	protected static function store_snapshot( string $scope_type, int $scope_ref, string $label, string $period_key, array $bounds, array $metrics ): int {
		$existing = get_posts(
			array(
				'post_type'      => 'stb_stats_snapshot',
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft' ),
				'meta_query'     => array(
					array(
						'key'   => 'stb_scope_type',
						'value' => $scope_type,
					),
					array(
						'key'   => 'stb_scope_ref',
						'value' => $scope_ref,
					),
					array(
						'key'   => 'stb_period_key',
						'value' => $period_key,
					),
					array(
						'key'   => 'stb_period_start',
						'value' => $bounds['datetime_start']->format( DATE_ATOM ),
					),
				),
			)
		);

		$title = sprintf(
			/* translators: 1: scope label, 2: date string */
			__( '%1$s – %2$s snapshot', 'stb-core' ),
			$label,
			$bounds['datetime_start']->format( get_option( 'date_format' ) )
		);

		if ( $existing ) {
			$post_id = $existing[0]->ID;
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $title,
					'post_status'=> 'publish',
				)
			);
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'stb_stats_snapshot',
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);
		}

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$notifications = $metrics['notifications'] ?? array(
			'sent'   => 0,
			'failed' => 0,
		);

		update_post_meta( $post_id, 'stb_scope_type', $scope_type );
		update_post_meta( $post_id, 'stb_scope_ref', $scope_ref );
		update_post_meta( $post_id, 'stb_scope_label', $label );
		update_post_meta( $post_id, 'stb_period_key', $period_key );
		update_post_meta( $post_id, 'stb_period_start', $bounds['datetime_start']->format( DATE_ATOM ) );
		update_post_meta( $post_id, 'stb_period_end', $bounds['datetime_end']->format( DATE_ATOM ) );
		update_post_meta( $post_id, 'stb_total_shifts', $metrics['total_shifts'] ?? 0 );
		update_post_meta( $post_id, 'stb_total_hours', $metrics['total_hours'] ?? 0 );
		update_post_meta( $post_id, 'stb_cancellations', $metrics['cancellations'] ?? 0 );
		update_post_meta( $post_id, 'stb_late_cancels', $metrics['late_cancels'] ?? 0 );
		update_post_meta( $post_id, 'stb_substitutions', $metrics['substitutions'] ?? 0 );
		update_post_meta( $post_id, 'stb_generated_at', current_time( 'mysql' ) );
		update_post_meta( $post_id, 'stb_generator_ver', self::VERSION );
		update_post_meta( $post_id, 'stb_notifications_sent', $notifications['sent'] ?? 0 );
		update_post_meta( $post_id, 'stb_notifications_failed', $notifications['failed'] ?? 0 );
		update_post_meta( $post_id, 'stb_payload', wp_json_encode( $metrics ) );

		return (int) $post_id;
	}
}

