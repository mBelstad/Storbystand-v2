<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_JetEngine {

	const VERSION_OPTION = 'stb_core_jetengine_version';
	const VERSION        = '2025-11-27-jetengine-ccts-5';

	protected static $notice_added = false;

	/**
	 * Bootstrap hooks.
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_bootstrap' ), 30 );
	}

	/**
	 * Ensure JetEngine definitions are synced once JetEngine is ready.
	 */
	public static function maybe_bootstrap(): void {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'ensure_definitions' ), 1 );
	}

	/**
	 * CLI entry point.
	 */
	public static function cli_sync(): void {
		self::force_sync( true );
		\WP_CLI::success( 'JetEngine CCT definitions synchronized.' );
	}

	/**
	 * Ensure all required JetEngine objects are registered.
	 */
	public static function ensure_definitions(): void {
		if ( did_action( 'stb_core_jetengine_synced' ) ) {
			return;
		}

		if ( ! self::is_custom_content_types_active() ) {
			self::add_missing_module_notice();
			return;
		}

		$current_version = get_option( self::VERSION_OPTION );

		if ( self::VERSION === $current_version ) {
			return;
		}

		self::force_sync();
		update_option( self::VERSION_OPTION, self::VERSION );
		do_action( 'stb_core_jetengine_synced' );
	}

	/**
	 * Insert or update JetEngine records.
	 *
	 * @param bool $force When true, skip version checks.
	 */
	public static function force_sync( bool $force = false ): void {
		if ( ! self::is_custom_content_types_active() ) {
			return;
		}

		foreach ( self::cct_definitions() as $definition ) {
			self::upsert_cct( $definition );
		}

		if ( $force ) {
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}

	/**
	 * Determine if the Custom Content Types module is active.
	 */
	protected static function is_custom_content_types_active(): bool {
		if ( ! function_exists( 'jet_engine' ) ) {
			return false;
		}

		return (bool) jet_engine()->modules->is_module_active( 'custom-content-types' );
	}

	/**
	 * Output admin notice if the required JetEngine module is not active.
	 */
	protected static function add_missing_module_notice(): void {
		if ( self::$notice_added || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::$notice_added = true;

		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					esc_html__( 'Storbystand Core requires the JetEngine “Custom Content Types” module to be enabled. Please activate it in JetEngine → Dashboard.', 'stb-core' )
				);
			}
		);
	}

	/**
	 * Insert or update a single CCT definition.
	 *
	 * @param array $definition CCT definition array.
	 */
	protected static function upsert_cct( array $definition ): void {
		global $wpdb;

		$table = jet_engine()->db->tables( 'post_types', 'name' );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE slug = %s AND status = %s LIMIT 1",
				$definition['slug'],
				'content-type'
			)
		);

		$data = array(
			'slug'        => $definition['slug'],
			'status'      => 'content-type',
			'labels'      => '',
			'args'        => $definition['args'],
			'meta_fields' => $definition['meta_fields'],
		);

		if ( $existing_id ) {
			$data['id'] = absint( $existing_id );
		}

		jet_engine()->db->update(
			'post_types',
			$data,
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		self::ensure_cct_table( $definition );
	}

	/**
	 * Make sure JetEngine creates/updates the physical CCT table using native methods.
	 *
	 * @param array $definition Definition array.
	 */
	protected static function ensure_cct_table( array $definition ): void {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || empty( $module->instance ) || empty( $module->instance->manager ) || empty( $module->instance->manager->data ) ) {
			return;
		}

		// Use JetEngine's native Data class to get schema and create DB instance.
		$data_manager = $module->instance->manager->data;
		$schema       = $data_manager->get_sql_columns_from_fields( $definition['meta_fields'] );

		// Load JetEngine's DB class if not already loaded.
		if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\DB' ) ) {
			require_once jet_engine()->modules->modules_path( 'custom-content-types/inc/db.php' );
		}

		// Create DB instance using JetEngine's native class.
		$db = new \Jet_Engine\Modules\Custom_Content_Types\DB( $definition['slug'], $schema );

		// Use JetEngine's native install_table() method if table doesn't exist.
		if ( ! $db->is_table_exists() ) {
			$db->install_table();
		}
	}

	/**
	 * Base args shared by all CCTs.
	 */
	protected static function base_args( string $name, string $slug, string $icon ): array {
		return array(
			'name'                      => $name,
			'slug'                      => $slug,
			'position'                  => '-1',
			'icon'                      => $icon,
			'capability'                => 'manage_options',
			'has_single'                => false,
			'create_index'              => true,
			'rest_get_enabled'          => true,
			'rest_put_enabled'          => true,
			'rest_post_enabled'         => true,
			'rest_delete_enabled'       => true,
			'rest_get_access'           => '',
			'rest_put_access'           => 'manage_options',
			'rest_post_access'          => 'manage_options',
			'rest_delete_access'        => 'manage_options',
			'related_post_type'         => '',
			'related_post_type_title'   => '',
			'related_post_type_content' => '',
			'hide_field_names'          => false,
			'admin_columns'             => array(),
		);
	}

	/**
	 * Helper to describe a meta field.
	 */
	protected static function field( string $name, string $label, string $type = 'text', array $extra = array() ): array {
		return array_merge(
			array(
				'name'        => $name,
				'title'       => $label,
				'type'        => $type,
				'default'     => '',
				'description' => '',
				'object_type' => 'field',
				'is_required' => false,
				'is_indexed'  => false,
				'options'     => array(),
			),
			$extra
		);
	}

	/**
	 * Return full list of required CCT definitions.
	 */
	protected static function cct_definitions(): array {
		$definitions = array();

		// Shifts.
		$shifts_args                     = self::base_args( 'Shifts', 'stb_shifts', 'dashicons-calendar-alt' );
		$shifts_args['admin_columns']    = array(
			'shift_date' => array(
				'enabled'     => true,
				'is_sortable' => true,
				'is_num'      => false,
			),
			'location_id' => array(
				'enabled'     => true,
				'is_sortable' => true,
				'is_num'      => true,
			),
			'status'      => array(
				'enabled'     => true,
				'is_sortable' => false,
				'is_num'      => false,
			),
		);
		$definitions[] = array(
			'slug'        => 'stb_shifts',
			'args'        => $shifts_args,
			'meta_fields' => array(
				self::field( 'shift_date', 'Shift Date', 'date', array( 'is_required' => true, 'is_indexed' => true ) ),
				self::field( 'location_id', 'Location ID', 'number', array( 'is_required' => true, 'is_indexed' => true ) ),
				self::field( 'template_id', 'Template ID', 'number', array( 'is_indexed' => true ) ),
				self::field( 'slot_id', 'Slot ID', 'text', array( 'is_indexed' => true ) ),
				self::field( 'capacity', 'Capacity', 'number', array( 'default' => 1 ) ),
				self::field(
					'status',
					'Status',
					'select',
					array(
						'default' => 'draft',
						'options' => array(
							array( 'value' => 'draft', 'label' => 'Draft' ),
							array( 'value' => 'published', 'label' => 'Published' ),
							array( 'value' => 'locked', 'label' => 'Locked' ),
							array( 'value' => 'cancelled', 'label' => 'Cancelled' ),
						),
					)
				),
				self::field( 'notes_public', 'Public Notes', 'textarea' ),
				self::field( 'notes_internal', 'Internal Notes', 'textarea' ),
				self::field( 'generated_via', 'Generated Via', 'text' ),
				self::field( 'copy_batch_id', 'Copy Batch ID', 'text' ),
			),
		);

		// Assignments.
		$assign_args                  = self::base_args( 'Assignments', 'stb_assignments', 'dashicons-groups' );
		$assign_args['admin_columns'] = array(
			'shift_id' => array(
				'enabled'     => true,
				'is_sortable' => true,
				'is_num'      => true,
			),
			'user_id'  => array(
				'enabled'     => true,
				'is_sortable' => true,
				'is_num'      => true,
			),
			'state'    => array(
				'enabled'     => true,
				'is_sortable' => false,
				'is_num'      => false,
			),
		);
		$definitions[] = array(
			'slug'        => 'stb_assignments',
			'args'        => $assign_args,
			'meta_fields' => array(
				self::field( 'shift_id', 'Shift ID', 'number', array( 'is_required' => true, 'is_indexed' => true ) ),
				self::field( 'user_id', 'User ID', 'number', array( 'is_required' => true, 'is_indexed' => true ) ),
				self::field(
					'state',
					'State',
					'select',
					array(
						'default' => 'requested',
						'options' => array(
							array( 'value' => 'requested', 'label' => 'Requested' ),
							array( 'value' => 'confirmed', 'label' => 'Confirmed' ),
							array( 'value' => 'completed', 'label' => 'Completed' ),
							array( 'value' => 'dropped', 'label' => 'Dropped' ),
							array( 'value' => 'cancelled_by_admin', 'label' => 'Cancelled by Admin' ),
						),
					)
				),
				self::field( 'joined_via', 'Joined Via', 'text' ),
				self::field( 'check_in', 'Check-In', 'sql-date' ),
				self::field( 'check_out', 'Check-Out', 'sql-date' ),
				self::field( 'points_weight', 'Points Weight', 'number', array( 'default' => 1 ) ),
				self::field(
					'admin_override',
					'Admin Override',
					'switcher',
					array(
						'default' => false,
					)
				),
			),
		);

		// Availability.
		$availability_args                  = self::base_args( 'Availability', 'stb_availability', 'dashicons-schedule' );
		$availability_args['admin_columns'] = array(
			'user_id'        => array(
				'enabled'     => true,
				'is_sortable' => true,
				'is_num'      => true,
			),
			'mode'           => array(
				'enabled'     => true,
				'is_sortable' => false,
				'is_num'      => false,
			),
			'start_datetime' => array(
				'enabled'     => true,
				'is_sortable' => true,
				'is_num'      => false,
			),
		);
		$definitions[] = array(
			'slug'        => 'stb_availability',
			'args'        => $availability_args,
			'meta_fields' => array(
				self::field( 'user_id', 'User ID', 'number', array( 'is_indexed' => true ) ),
				self::field(
					'mode',
					'Mode',
					'select',
					array(
						'default' => 'available',
						'options' => array(
							array( 'value' => 'available', 'label' => 'Available' ),
							array( 'value' => 'unavailable_full_day', 'label' => 'Unavailable (Full Day)' ),
						),
					)
				),
				self::field( 'start_datetime', 'Start', 'sql-date', array( 'is_indexed' => true ) ),
				self::field( 'end_datetime', 'End', 'sql-date' ),
				self::field( 'recurrence_rule', 'Recurrence Rule', 'text' ),
				self::field( 'preferred_locations', 'Preferred Locations', 'textarea' ),
				self::field( 'edit_lock', 'Edit Lock', 'sql-date' ),
			),
		);

		// Admin Notes.
		$notes_args                  = self::base_args( 'Admin Notes', 'stb_admin_notes', 'dashicons-sticky' );
		$notes_args['admin_columns'] = array(
			'subject_type' => array(
				'enabled'     => true,
				'is_sortable' => false,
				'is_num'      => false,
			),
			'subject_id'   => array(
				'enabled'     => true,
				'is_sortable' => true,
				'is_num'      => true,
			),
		);
		$definitions[] = array(
			'slug'        => 'stb_admin_notes',
			'args'        => $notes_args,
			'meta_fields' => array(
				self::field( 'subject_type', 'Subject Type', 'text' ),
				self::field( 'subject_id', 'Subject ID', 'number' ),
				self::field( 'note_body', 'Note', 'textarea', array( 'is_required' => true ) ),
				self::field(
					'visibility',
					'Visibility',
					'select',
					array(
						'default' => 'admin-only',
						'options' => array(
							array( 'value' => 'admin-only', 'label' => 'Admin Only' ),
							array( 'value' => 'shift-leads', 'label' => 'Shift Leads' ),
						),
					)
				),
				self::field( 'pinned', 'Pinned', 'switcher' ),
				self::field( 'author_id', 'Author ID', 'number' ),
			),
		);

		// Audit log.
		$audit_args                  = self::base_args( 'Audit Log', 'stb_audit_log', 'dashicons-visibility' );
		$audit_args['admin_columns'] = array(
			'entity_type' => array(
				'enabled'     => true,
				'is_sortable' => false,
				'is_num'      => false,
			),
			'action'      => array(
				'enabled'     => true,
				'is_sortable' => false,
				'is_num'      => false,
			),
			'recorded_at' => array(
				'enabled'     => true,
				'is_sortable' => true,
				'is_num'      => false,
			),
		);
		$definitions[] = array(
			'slug'        => 'stb_audit_log',
			'args'        => $audit_args,
			'meta_fields' => array(
				self::field( 'entity_type', 'Entity Type', 'text', array( 'is_indexed' => true ) ),
				self::field( 'entity_id', 'Entity ID', 'number', array( 'is_indexed' => true ) ),
				self::field( 'action', 'Action', 'text', array( 'is_indexed' => true ) ),
				self::field( 'actor_user_id', 'Actor User ID', 'number' ),
				self::field( 'actor_role', 'Actor Role', 'text' ),
				self::field( 'metadata_json', 'Metadata', 'textarea' ),
				self::field( 'recorded_at', 'Recorded At', 'sql-date', array( 'is_required' => true, 'is_indexed' => true ) ),
			),
		);

		// Notification Queue.
		$queue_args                  = self::base_args( 'Notifications', 'stb_notification_queue', 'dashicons-megaphone' );
		$queue_args['admin_columns'] = array(
			'channel'    => array(
				'enabled'     => true,
				'is_sortable' => false,
				'is_num'      => false,
			),
			'event_type' => array(
				'enabled'     => true,
				'is_sortable' => false,
				'is_num'      => false,
			),
			'status'     => array(
				'enabled'     => true,
				'is_sortable' => false,
				'is_num'      => false,
			),
		);
		$definitions[] = array(
			'slug'        => 'stb_notification_queue',
			'args'        => $queue_args,
			'meta_fields' => array(
				self::field( 'entity_type', 'Entity Type', 'text' ),
				self::field( 'entity_id', 'Entity ID', 'number' ),
				self::field( 'recipient_id', 'Recipient User ID', 'number', array( 'is_indexed' => true ) ),
				self::field(
					'channel',
					'Channel',
					'select',
					array(
						'default' => 'email',
						'options' => array(
							array( 'value' => 'email', 'label' => 'Email' ),
							array( 'value' => 'push', 'label' => 'Push' ),
							array( 'value' => 'sms', 'label' => 'SMS' ),
						),
					)
				),
				self::field( 'event_type', 'Event Type', 'text' ),
				self::field( 'deep_link', 'Deep Link', 'text' ),
				self::field( 'payload_json', 'Payload', 'textarea' ),
				self::field(
					'status',
					'Status',
					'select',
					array(
						'default' => 'queued',
						'options' => array(
							array( 'value' => 'queued', 'label' => 'Queued' ),
							array( 'value' => 'sending', 'label' => 'Sending' ),
							array( 'value' => 'sent', 'label' => 'Sent' ),
							array( 'value' => 'failed', 'label' => 'Failed' ),
							array( 'value' => 'cancelled', 'label' => 'Cancelled' ),
						),
					)
				),
				self::field( 'last_error', 'Last Error', 'textarea' ),
				self::field( 'provider_message_id', 'Provider Message ID', 'text' ),
				self::field( 'scheduled_at', 'Scheduled At', 'sql-date' ),
				self::field( 'processed_at', 'Processed At', 'sql-date' ),
			),
		);

		return $definitions;
	}
}

