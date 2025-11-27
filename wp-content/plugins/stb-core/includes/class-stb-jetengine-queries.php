<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_JetEngine_Queries {

	const VERSION_OPTION = 'stb_core_jetengine_queries_version';
	const VERSION        = '2025-11-27-queries-1';

	/**
	 * Bootstrap hooks.
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_bootstrap' ), 35 );
	}

	/**
	 * Wire into JetEngine init lifecycle.
	 */
	public static function maybe_bootstrap(): void {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'ensure_definitions' ), 2 );
	}

	/**
	 * Ensure definitions exist when JetEngine is ready.
	 */
	public static function ensure_definitions(): void {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		$current_version = get_option( self::VERSION_OPTION );

		if ( self::VERSION === $current_version ) {
			return;
		}

		self::force_sync();
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Insert or update JetEngine Query Builder definitions.
	 *
	 * @param bool $force When true, skip version checks.
	 */
	public static function force_sync( bool $force = false ): void {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		foreach ( self::definitions() as $definition ) {
			self::upsert_query( $definition );
		}

		if ( $force ) {
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}

	/**
	 * Persist a single query definition into the JetEngine storage table.
	 *
	 * @param array $definition Definition payload.
	 */
	protected static function upsert_query( array $definition ): void {
		global $wpdb;

		$table = jet_engine()->db->tables( 'post_types', 'name' );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND ( slug = %s OR args LIKE %s ) LIMIT 1",
				'query',
				$definition['slug'],
				$wpdb->esc_like( $definition['slug'] ) . '%'
			)
		);

		$labels = array( 'name' => $definition['label'] );

		$args = array(
			'name'            => $definition['label'],
			'slug'            => $definition['slug'],
			'query_id'        => $definition['slug'],
			'query_type'      => 'sql',
			'cache_query'     => true,
			'cache_expires'   => $definition['cache_expires'],
			'limit'           => $definition['limit'],
			'limit_per_page'  => $definition['limit'],
			'preview_page'    => null,
			'preview_query_string' => '',
			'preview_page_title'   => '',
			'api_endpoint'    => true,
			'api_namespace'   => 'storbystand/v1',
			'api_path'        => $definition['api_path'],
			'api_access'      => 'logged_in',
			'api_access_cap'  => 'read',
			'api_access_role' => '',
			'api_schema'      => '',
			'sql'             => array(
				'advanced_mode' => true,
				'manual_query'  => $definition['sql'],
				'count_query'   => $definition['count_sql'],
			),
		);

		$data = array(
			'slug'        => $definition['slug'],
			'status'      => 'query',
			'labels'      => $labels,
			'args'        => $args,
			'meta_fields' => array(),
		);

		if ( $existing_id ) {
			$data['id'] = absint( $existing_id );
		}

		jet_engine()->db->update(
			'post_types',
			$data,
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Build SQL helper snippets.
	 */
	protected static function table( string $suffix ): string {
		return '{prefix}jet_cct_' . $suffix;
	}

	/**
	 * Query definition manifest.
	 *
	 * @return array[]
	 */
	protected static function definitions(): array {
		$assign_table = self::table( 'stb_assignments' );
		$shift_table  = self::table( 'stb_shifts' );
		$audit_table  = self::table( 'stb_audit_log' );
		$queue_table  = self::table( 'stb_notification_queue' );

		return array(
			array(
				'slug'          => 'my_upcoming_shifts',
				'label'         => __( 'My Upcoming Shifts', 'stb-core' ),
				'api_path'      => 'my-upcoming-shifts',
				'limit'         => 50,
				'cache_expires' => 120,
				'sql'           => "
SELECT a.*, s.shift_date, s.slot_id, s.location_id, s.capacity, s.status,
       COALESCE(active.confirmed_count, 0) AS confirmed_count
FROM {$assign_table} AS a
INNER JOIN {$shift_table} AS s ON s._ID = a.shift_id
LEFT JOIN (
    SELECT shift_id, COUNT(*) AS confirmed_count
    FROM {$assign_table}
    WHERE state IN ('confirmed','requested')
    GROUP BY shift_id
) AS active ON active.shift_id = s._ID
WHERE a.user_id = %current_user_id%
  AND a.state IN ('confirmed','requested')
  AND s.shift_date >= CURDATE()
ORDER BY s.shift_date ASC, s.slot_id ASC
LIMIT 50",
				'count_sql'     => "
SELECT COUNT(*)
FROM {$assign_table} AS a
INNER JOIN {$shift_table} AS s ON s._ID = a.shift_id
WHERE a.user_id = %current_user_id%
  AND a.state IN ('confirmed','requested')
  AND s.shift_date >= CURDATE()",
			),
			array(
				'slug'          => 'shift_roster_manage',
				'label'         => __( 'Shift Roster Manage', 'stb-core' ),
				'api_path'      => 'shift-roster-manage',
				'limit'         => 200,
				'cache_expires' => 60,
				'sql'           => "
SELECT s.*,
       COALESCE(SUM(CASE WHEN a.state IN ('confirmed','requested') THEN 1 ELSE 0 END), 0) AS active_assignments,
       (s.capacity - COALESCE(SUM(CASE WHEN a.state IN ('confirmed','requested') THEN 1 ELSE 0 END), 0)) AS open_slots
FROM {$shift_table} AS s
LEFT JOIN {$assign_table} AS a ON a.shift_id = s._ID
WHERE s.shift_date BETWEEN
    IFNULL(NULLIF('%query_var|start_date%', ''), CURDATE())
    AND IFNULL(NULLIF('%query_var|end_date%', ''), DATE_ADD(CURDATE(), INTERVAL 14 DAY))
  AND ( '%query_var|location_id%' = '' OR s.location_id = CAST('%query_var|location_id%' AS UNSIGNED) )
  AND ( '%query_var|status%' = '' OR s.status = '%query_var|status%' )
GROUP BY s._ID
ORDER BY s.shift_date ASC, s.slot_id ASC
LIMIT 200",
				'count_sql'     => "
SELECT COUNT(*)
FROM {$shift_table} AS s
WHERE s.shift_date BETWEEN
    IFNULL(NULLIF('%query_var|start_date%', ''), CURDATE())
    AND IFNULL(NULLIF('%query_var|end_date%', ''), DATE_ADD(CURDATE(), INTERVAL 14 DAY))
  AND ( '%query_var|location_id%' = '' OR s.location_id = CAST('%query_var|location_id%' AS UNSIGNED) )
  AND ( '%query_var|status%' = '' OR s.status = '%query_var|status%' )",
			),
			array(
				'slug'          => 'alerts_unassigned_shifts',
				'label'         => __( 'Alerts – Understaffed Shifts', 'stb-core' ),
				'api_path'      => 'alerts-unassigned-shifts',
				'limit'         => 50,
				'cache_expires' => 30,
				'sql'           => "
SELECT s.*, (s.capacity - COALESCE(active.confirmed_count, 0)) AS open_slots
FROM {$shift_table} AS s
LEFT JOIN (
    SELECT shift_id, COUNT(*) AS confirmed_count
    FROM {$assign_table}
    WHERE state IN ('confirmed','requested')
    GROUP BY shift_id
) AS active ON active.shift_id = s._ID
WHERE s.status = 'published'
  AND (s.capacity - COALESCE(active.confirmed_count, 0)) > 0
  AND s.shift_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
ORDER BY s.shift_date ASC, s.slot_id ASC
LIMIT 50",
				'count_sql'     => "
SELECT COUNT(*)
FROM {$shift_table} AS s
LEFT JOIN (
    SELECT shift_id, COUNT(*) AS confirmed_count
    FROM {$assign_table}
    WHERE state IN ('confirmed','requested')
    GROUP BY shift_id
) AS active ON active.shift_id = s._ID
WHERE s.status = 'published'
  AND (s.capacity - COALESCE(active.confirmed_count, 0)) > 0
  AND s.shift_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)",
			),
			array(
				'slug'          => 'notification_queue_admin',
				'label'         => __( 'Notification Queue Admin', 'stb-core' ),
				'api_path'      => 'notification-queue',
				'limit'         => 200,
				'cache_expires' => 15,
				'sql'           => "
SELECT q.*
FROM {$queue_table} AS q
WHERE ( '%query_var|channel%' = '' OR q.channel = '%query_var|channel%' )
  AND ( '%query_var|status%' = '' OR q.status = '%query_var|status%' )
  AND ( '%query_var|event_type%' = '' OR q.event_type = '%query_var|event_type%' )
  AND (
        '%query_var|date_from%' = ''
        OR q.created_at >= STR_TO_DATE('%query_var|date_from%', '%Y-%m-%d')
      )
  AND (
        '%query_var|date_to%' = ''
        OR q.created_at <= DATE_ADD(STR_TO_DATE('%query_var|date_to%', '%Y-%m-%d'), INTERVAL 1 DAY)
      )
ORDER BY q.created_at DESC
LIMIT 200",
				'count_sql'     => "
SELECT COUNT(*)
FROM {$queue_table} AS q
WHERE ( '%query_var|channel%' = '' OR q.channel = '%query_var|channel%' )
  AND ( '%query_var|status%' = '' OR q.status = '%query_var|status%' )
  AND ( '%query_var|event_type%' = '' OR q.event_type = '%query_var|event_type%' )
  AND (
        '%query_var|date_from%' = ''
        OR q.created_at >= STR_TO_DATE('%query_var|date_from%', '%Y-%m-%d')
      )
  AND (
        '%query_var|date_to%' = ''
        OR q.created_at <= DATE_ADD(STR_TO_DATE('%query_var|date_to%', '%Y-%m-%d'), INTERVAL 1 DAY)
      )",
			),
			array(
				'slug'          => 'audit_log_recent',
				'label'         => __( 'Audit Log Recent', 'stb-core' ),
				'api_path'      => 'audit-log',
				'limit'         => 200,
				'cache_expires' => 15,
				'sql'           => "
SELECT log.*
FROM {$audit_table} AS log
WHERE (
        '%query_var|entity_type%' = ''
        OR log.entity_type = '%query_var|entity_type%'
      )
  AND (
        '%query_var|entity_id%' = ''
        OR log.entity_id = CAST('%query_var|entity_id%' AS UNSIGNED)
      )
  AND (
        '%query_var|date_from%' = ''
        OR log.recorded_at >= STR_TO_DATE('%query_var|date_from%', '%Y-%m-%d')
      )
  AND (
        '%query_var|date_to%' = ''
        OR log.recorded_at <= DATE_ADD(STR_TO_DATE('%query_var|date_to%', '%Y-%m-%d'), INTERVAL 1 DAY)
      )
ORDER BY log.recorded_at DESC
LIMIT 200",
				'count_sql'     => "
SELECT COUNT(*)
FROM {$audit_table} AS log
WHERE (
        '%query_var|entity_type%' = ''
        OR log.entity_type = '%query_var|entity_type%'
      )
  AND (
        '%query_var|entity_id%' = ''
        OR log.entity_id = CAST('%query_var|entity_id%' AS UNSIGNED)
      )
  AND (
        '%query_var|date_from%' = ''
        OR log.recorded_at >= STR_TO_DATE('%query_var|date_from%', '%Y-%m-%d')
      )
  AND (
        '%query_var|date_to%' = ''
        OR log.recorded_at <= DATE_ADD(STR_TO_DATE('%query_var|date_to%', '%Y-%m-%d'), INTERVAL 1 DAY)
      )",
			),
		);
	}
}

