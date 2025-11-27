<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_Data_Seeder {

	/**
	 * Cached demo user IDs keyed by handle.
	 *
	 * @var array<string,int>
	 */
	protected static $user_ids = array();

	/**
	 * Insert mock rows for local testing.
	 */
	public static function seed(): void {
		self::seed_users();
		self::truncate_tables();
		self::seed_shifts_and_assignments();
		self::seed_availability();
		self::seed_audit_log();
		self::seed_notifications();

		if ( class_exists( 'Stb_Stats' ) ) {
			Stb_Stats::generate_for_period( 'day' );
		}

		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::log( 'Mock data inserted.' );
		}
	}

	protected static function seed_shifts_and_assignments(): void {
		$publisher_one   = self::$user_ids['publisher_one'] ?? 0;
		$publisher_two   = self::$user_ids['publisher_two'] ?? 0;
		$publisher_three = self::$user_ids['publisher_three'] ?? $publisher_two;

		$shift_db      = self::get_cct_db( 'stb_shifts' );
		$assign_db     = self::get_cct_db( 'stb_assignments' );
		$today         = current_time( 'Y-m-d' );
		$tomorrow      = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		$day_after     = gmdate( 'Y-m-d', strtotime( $today . ' +2 day' ) );

		$shifts = array(
			array(
				'shift_date'   => $today,
				'location_id'  => 101,
				'slot_id'      => '09:00',
				'capacity'     => 3,
				'status'       => 'published',
				'notes_public' => __( 'Bring literature for morning outreach.', 'stb-core' ),
			),
			array(
				'shift_date'   => $today,
				'location_id'  => 102,
				'slot_id'      => '13:00',
				'capacity'     => 2,
				'status'       => 'published',
				'notes_public' => __( 'Focus on youth contacts.', 'stb-core' ),
			),
			array(
				'shift_date'   => $tomorrow,
				'location_id'  => 103,
				'slot_id'      => '18:00',
				'capacity'     => 4,
				'status'       => 'draft',
				'notes_public' => __( 'Need extra Norwegian speakers.', 'stb-core' ),
			),
			array(
				'shift_date'   => $day_after,
				'location_id'  => 101,
				'slot_id'      => '11:00',
				'capacity'     => 3,
				'status'       => 'locked',
				'notes_public' => __( 'Invite-season kickoff.', 'stb-core' ),
			),
		);

		$shift_ids = array();

		foreach ( $shifts as $shift ) {
			$shift_ids[] = $shift_db->insert( $shift );
		}

		$assignments = array(
			array(
				'shift_id'       => $shift_ids[0],
				'user_id'        => $publisher_one,
				'state'          => 'confirmed',
				'joined_via'     => 'self',
				'admin_override' => 0,
				'points_weight'  => 1,
			),
			array(
				'shift_id'       => $shift_ids[0],
				'user_id'        => $publisher_two,
				'state'          => 'confirmed',
				'joined_via'     => 'admin',
				'admin_override' => 1,
				'points_weight'  => 1,
			),
			array(
				'shift_id'       => $shift_ids[1],
				'user_id'        => $publisher_three,
				'state'          => 'requested',
				'joined_via'     => 'self',
				'admin_override' => 0,
			),
		);

		foreach ( $assignments as $assignment ) {
			$assign_db->insert( $assignment );
		}
	}

	protected static function truncate_tables(): void {
		global $wpdb;

		$slugs = array(
			'stb_shifts',
			'stb_assignments',
			'stb_availability',
			'stb_admin_notes',
			'stb_audit_log',
			'stb_notification_queue',
		);

		foreach ( $slugs as $slug ) {
			$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'jet_cct_' . $slug );
		}
	}

	protected static function seed_availability(): void {
		$publisher_one = self::$user_ids['publisher_one'] ?? 0;
		$publisher_two = self::$user_ids['publisher_two'] ?? 0;

		$db = self::get_cct_db( 'stb_availability' );

		$records = array(
			array(
				'user_id'        => $publisher_one,
				'mode'           => 'available',
				'start_datetime' => gmdate( 'Y-m-d 08:00:00' ),
				'end_datetime'   => gmdate( 'Y-m-d 12:00:00' ),
				'recurrence_rule'=> 'FREQ=WEEKLY;BYDAY=MO,TU,WE',
			),
			array(
				'user_id'        => $publisher_two,
				'mode'           => 'unavailable_full_day',
				'start_datetime' => gmdate( 'Y-m-d 00:00:00', strtotime( '+4 days' ) ),
				'end_datetime'   => gmdate( 'Y-m-d 23:59:59', strtotime( '+6 days' ) ),
				'recurrence_rule'=> '',
			),
		);

		foreach ( $records as $record ) {
			$db->insert( $record );
		}
	}

	protected static function seed_audit_log(): void {
		$db = self::get_cct_db( 'stb_audit_log' );

		$entries = array(
			array(
				'entity_type'   => 'shift',
				'entity_id'     => 1,
				'action'        => 'shift_created',
				'actor_user_id' => 1,
				'actor_role'    => 'administrator',
				'metadata_json' => wp_json_encode( array( 'note' => 'Initial generation for pilot data.' ) ),
				'recorded_at'   => current_time( 'mysql' ),
			),
			array(
				'entity_type'   => 'assignment',
				'entity_id'     => 1,
				'action'        => 'admin_override',
				'actor_user_id' => 1,
				'actor_role'    => 'administrator',
				'metadata_json' => wp_json_encode( array( 'reason' => 'Needed lead for training.' ) ),
				'recorded_at'   => current_time( 'mysql' ),
			),
		);

		foreach ( $entries as $entry ) {
			$db->insert( $entry );
		}
	}

	protected static function seed_notifications(): void {
		$publisher_one = self::$user_ids['publisher_one'] ?? 0;
		$publisher_two = self::$user_ids['publisher_two'] ?? 0;

		$db = self::get_cct_db( 'stb_notification_queue' );

		$rows = array(
			array(
				'entity_type'         => 'assignment',
				'entity_id'           => 1,
				'recipient_id'        => $publisher_one,
				'channel'             => 'email',
				'event_type'          => 'assigned',
				'deep_link'           => '/dashboard/shifts',
				'payload_json'        => wp_json_encode( array( 'subject' => 'You have a shift tomorrow.' ) ),
				'status'              => 'sent',
				'provider_message_id' => 'mock-123',
				'processed_at'        => current_time( 'mysql' ),
			),
			array(
				'entity_type'  => 'shift',
				'entity_id'    => 2,
				'recipient_id' => $publisher_two,
				'channel'      => 'email',
				'event_type'   => 'reminder_24h',
				'status'       => 'queued',
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ),
			),
		);

		foreach ( $rows as $row ) {
			$db->insert( $row );
		}
	}

	protected static function get_cct_db( string $slug ) {
		if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\DB' ) ) {
			require_once jet_engine()->modules->modules_path( 'custom-content-types/inc/db.php' );
		}

		$schema = array(); // db class resolves schema automatically on install.

		return new \Jet_Engine\Modules\Custom_Content_Types\DB( $slug, $schema );
	}

	/**
	 * Ensure demo WordPress users exist and cache their IDs.
	 */
	protected static function seed_users(): void {
		$defaults = array(
			'publisher_one'   => array(
				'user_login'   => 'publisher.one',
				'user_email'   => 'publisher.one@example.com',
				'display_name' => 'Publisher One',
			),
			'publisher_two'   => array(
				'user_login'   => 'publisher.two',
				'user_email'   => 'publisher.two@example.com',
				'display_name' => 'Publisher Two',
			),
			'publisher_three' => array(
				'user_login'   => 'publisher.three',
				'user_email'   => 'publisher.three@example.com',
				'display_name' => 'Publisher Three',
			),
		);

		foreach ( $defaults as $key => $args ) {
			$existing = get_user_by( 'email', $args['user_email'] );

			if ( $existing ) {
				self::$user_ids[ $key ] = $existing->ID;
				self::seed_user_meta( (int) $existing->ID, $key );
				continue;
			}

			$user_id = wp_insert_user(
				array(
					'user_login'   => $args['user_login'],
					'user_email'   => $args['user_email'],
					'display_name' => $args['display_name'],
					'first_name'   => $args['display_name'],
					'user_pass'    => 'Password!123',
					'role'         => 'subscriber',
				)
			);

			if ( ! is_wp_error( $user_id ) ) {
				self::$user_ids[ $key ] = (int) $user_id;
				self::seed_user_meta( (int) $user_id, $key );
			}
		}
	}

	/**
	 * Provide predictable notification prefs/tokens for mock users.
	 */
	protected static function seed_user_meta( int $user_id, string $handle ): void {
		if ( ! get_user_meta( $user_id, 'stb_notification_prefs', true ) ) {
			update_user_meta(
				$user_id,
				'stb_notification_prefs',
				array(
					'channels' => array(
						'email' => true,
						'push'  => 'publisher_three' === $handle, // only one mock user defaults to push
						'sms'   => false,
					),
					'events'   => array(),
				)
			);
		}

		if ( ! get_user_meta( $user_id, 'stb_push_tokens', true ) ) {
			update_user_meta(
				$user_id,
				'stb_push_tokens',
				array(
					sprintf( 'mock-token-%s', $handle ),
				)
			);
		}
	}
}

