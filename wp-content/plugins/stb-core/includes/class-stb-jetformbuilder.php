<?php
/**
 * JetFormBuilder Form Management
 *
 * @package Storbystand
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_JetFormBuilder {

	const VERSION_OPTION = 'stb_core_jetformbuilder_forms_version';
	const VERSION        = '2025-11-28-forms-native-1';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_sync' ), 40 );
		// Only hook into after-send for forms that need custom validation/side effects
		add_action( 'jet-form-builder/form-handler/after-send', array( __CLASS__, 'handle_form_submission' ), 10, 2 );
		// Hook into before-send for validation
		add_action( 'jet-form-builder/form-handler/before-send', array( __CLASS__, 'validate_form_submission' ), 10, 2 );
	}

	/**
	 * Check if forms need to be synced.
	 */
	public static function maybe_sync(): void {
		if ( ! post_type_exists( 'jet-form-builder' ) ) {
			return;
		}

		$current = get_option( self::VERSION_OPTION );

		if ( self::VERSION === $current ) {
			return;
		}

		self::force_sync();
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Force sync all forms.
	 *
	 * @param bool $force Force update even if version matches.
	 */
	public static function force_sync( bool $force = false ): void {
		if ( ! post_type_exists( 'jet-form-builder' ) ) {
			return;
		}

		foreach ( self::definitions() as $definition ) {
			self::upsert_form( $definition );
		}

		if ( $force ) {
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}

	/**
	 * Create or update a JetFormBuilder form.
	 *
	 * @param array $definition Form definition.
	 */
	protected static function upsert_form( array $definition ): void {
		$form_id = self::get_form_id( $definition['slug'] );

		// Create basic Gutenberg block content for the form
		$content = self::build_form_content( $definition );

		$postarr = array(
			'post_title'   => $definition['title'],
			'post_status'  => 'publish',
			'post_type'    => 'jet-form-builder',
			'post_content' => $content,
		);

		if ( $form_id ) {
			$postarr['ID'] = $form_id;
			wp_update_post( $postarr );
		} else {
			$postarr['post_name'] = $definition['slug'];
			$form_id              = wp_insert_post( $postarr );
		}

		if ( ! $form_id || is_wp_error( $form_id ) ) {
			return;
		}

		// Store form slug for reference
		update_post_meta( $form_id, '_stb_form_slug', $definition['slug'] );
		update_post_meta( $form_id, '_stb_form_description', $definition['description'] ?? '' );

		// Store form definition as meta for reference
		update_post_meta( $form_id, '_stb_form_definition', $definition );

		// Configure JetFormBuilder actions if provided
		if ( ! empty( $definition['actions'] ) ) {
			update_post_meta( $form_id, '_jfb_actions', $definition['actions'] );
		}

		// Configure JetFormBuilder settings if provided
		if ( ! empty( $definition['settings'] ) ) {
			update_post_meta( $form_id, '_jfb_settings', $definition['settings'] );
		}
	}

	/**
	 * Build Gutenberg block content for form.
	 *
	 * @param array $definition Form definition.
	 * @return string
	 */
	protected static function build_form_content( array $definition ): string {
		// JetFormBuilder uses Gutenberg blocks
		// For now, create a basic form structure that can be edited manually
		// The form will need to be completed in the WordPress admin
		
		$blocks = array();

		// Add form fields as blocks
		if ( ! empty( $definition['fields'] ) ) {
			foreach ( $definition['fields'] as $field ) {
				$block = self::field_to_block( $field );
				if ( $block ) {
					$blocks[] = $block;
				}
			}
		}

		// Add submit button
		$blocks[] = array(
			'blockName' => 'jet-forms/submit-field',
			'attrs'     => array(
				'label' => $definition['settings']['submit_button_text'] ?? __( 'Submit', 'stb-core' ),
			),
		);

		// Convert blocks to Gutenberg format
		return self::blocks_to_content( $blocks );
	}

	/**
	 * Convert field definition to Gutenberg block.
	 *
	 * @param array $field Field definition.
	 * @return array|null
	 */
	protected static function field_to_block( array $field ): ?array {
		if ( empty( $field['type'] ) ) {
			return null;
		}

		$block_name = 'jet-forms/' . self::get_field_block_name( $field['type'] );

		$attrs = array(
			'label'    => $field['label'] ?? '',
			'required' => $field['required'] ?? false,
		);

		// Only add name for fields that need it (not headings)
		if ( 'heading' !== $field['type'] && ! empty( $field['name'] ) ) {
			$attrs['name'] = $field['name'];
		}

		// Add type-specific attributes
		switch ( $field['type'] ) {
			case 'select':
				$attrs['field_options'] = self::format_options( $field['options'] ?? array() );
				$attrs['multiple']     = $field['multiple'] ?? false;
				break;
			case 'textarea':
				$attrs['placeholder'] = $field['placeholder'] ?? '';
				break;
			case 'number':
				$attrs['min'] = $field['min'] ?? '';
				$attrs['max'] = $field['max'] ?? '';
				break;
			case 'switch':
				$attrs['default'] = $field['default'] ?? false;
				break;
			case 'hidden':
				$attrs['default_value'] = $field['default'] ?? '';
				break;
		}

		return array(
			'blockName' => $block_name,
			'attrs'     => $attrs,
		);
	}

	/**
	 * Get JetFormBuilder block name for field type.
	 *
	 * @param string $type Field type.
	 * @return string
	 */
	protected static function get_field_block_name( string $type ): string {
		$map = array(
			'text'     => 'text-field',
			'textarea' => 'textarea-field',
			'select'   => 'select-field',
			'date'     => 'date-field',
			'time'     => 'time-field',
			'number'   => 'number-field',
			'switch'   => 'switch-field',
			'checkbox' => 'checkbox-field',
			'hidden'   => 'hidden-field',
			'heading'  => 'heading-field',
		);

		return $map[ $type ] ?? 'text-field';
	}

	/**
	 * Format options for select field.
	 *
	 * @param array $options Options array.
	 * @return string
	 */
	protected static function format_options( array $options ): string {
		$lines = array();
		foreach ( $options as $value => $label ) {
			$lines[] = $value . '|' . $label;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Convert blocks array to Gutenberg content format.
	 *
	 * @param array $blocks Blocks array.
	 * @return string
	 */
	protected static function blocks_to_content( array $blocks ): string {
		$content_blocks = array();
		foreach ( $blocks as $block ) {
			$content_blocks[] = '<!-- wp:' . $block['blockName'] . ' ' . wp_json_encode( $block['attrs'] ) . ' /-->';
		}
		return implode( "\n\n", $content_blocks );
	}

	/**
	 * Build field mapping array for JetFormBuilder actions.
	 * Maps form field names to CCT column names or user meta keys.
	 *
	 * @param array $mappings Array of 'cct_column' => 'form_field' or 'cct_column' => '{macro}' mappings.
	 * @return array
	 */
	protected static function build_field_mapping( array $mappings ): array {
		$field_map = array();
		foreach ( $mappings as $target => $source ) {
			// If source starts with {, it's a macro (e.g., {current_user_id})
			// Otherwise, it's a form field name
			if ( str_starts_with( $source, '{' ) ) {
				$field_map[ $target ] = $source;
			} else {
				$field_map[ $target ] = '{field|' . $source . '}';
			}
		}
		return $field_map;
	}

	/**
	 * Get form ID by slug.
	 *
	 * @param string $slug Form slug.
	 * @return int|null
	 */
	protected static function get_form_id( string $slug ): ?int {
		$existing = get_posts(
			array(
				'post_type'   => 'jet-form-builder',
				'post_status' => 'any',
				'numberposts' => 1,
				'meta_key'    => '_stb_form_slug',
				'meta_value'  => $slug,
				'fields'      => 'ids',
			)
		);

		return $existing ? intval( $existing[0] ) : null;
	}

	/**
	 * Get form ID by slug (public method).
	 *
	 * @param string $slug Form slug.
	 * @return int|null
	 */
	public static function get_form_id_by_slug( string $slug ): ?int {
		return self::get_form_id( $slug );
	}

	/**
	 * Validate form submissions before native actions run.
	 *
	 * @param \JFB_Modules\Form_Record $record Form record.
	 * @param array                     $handler Handler data.
	 */
	public static function validate_form_submission( $record, $handler ): void {
		if ( ! $record || ! method_exists( $record, 'get_form_id' ) ) {
			return;
		}

		$form_id = $record->get_form_id();
		$slug    = get_post_meta( $form_id, '_stb_form_slug', true );

		if ( ! $slug ) {
			return;
		}

		$fields = $record->get_fields();

		// Route to specific validation handler based on form slug
		switch ( $slug ) {
			case 'publisher_join_shift':
				self::validate_join_shift( $fields, $record );
				break;
			case 'publisher_leave_shift':
				self::validate_leave_shift( $fields, $record );
				break;
			case 'admin_assign_shift':
				self::validate_admin_assign( $fields, $record );
				break;
		}
	}

	/**
	 * Handle form submissions (side effects only - data persistence handled by native actions).
	 *
	 * @param \JFB_Modules\Form_Record $record Form record.
	 * @param array                     $handler Handler data.
	 */
	public static function handle_form_submission( $record, $handler ): void {
		if ( ! $record || ! method_exists( $record, 'get_form_id' ) ) {
			return;
		}

		$form_id = $record->get_form_id();
		$slug    = get_post_meta( $form_id, '_stb_form_slug', true );

		if ( ! $slug ) {
			return;
		}

		$fields = $record->get_fields();

		// Route to specific handler based on form slug
		// Only forms that need side effects (audit logging, notifications) are handled here
		switch ( $slug ) {
			case 'publisher_join_shift':
				self::handle_join_shift_side_effects( $fields, $record );
				break;
			case 'publisher_leave_shift':
				self::handle_leave_shift_side_effects( $fields, $record );
				break;
			case 'availability_submit':
				self::handle_availability_side_effects( $fields, $record );
				break;
			case 'unavailability_full_day':
				self::handle_unavailability_side_effects( $fields, $record );
				break;
			case 'admin_assign_shift':
				self::handle_admin_assign_side_effects( $fields, $record );
				break;
			case 'copy_day_action':
				self::handle_copy_day( $fields, $record );
				break;
			case 'shift_template_builder':
				self::handle_shift_template( $fields, $record );
				break;
			case 'audit_log_note':
				self::handle_audit_note_side_effects( $fields, $record );
				break;
			case 'bulk_notification_broadcast':
				self::handle_bulk_broadcast( $fields, $record );
				break;
			// notification_preferences is fully native - no custom handlers needed
		}
	}

	/**
	 * Validate join shift submission (capacity check).
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function validate_join_shift( array $fields, $record ): void {
		$shift_id = isset( $fields['shift_id'] ) ? intval( $fields['shift_id']['value'] ?? 0 ) : 0;

		if ( ! $shift_id ) {
			$record->add_error( 'shift_id', __( 'Shift ID is required.', 'stb-core' ) );
			return;
		}

		// Check capacity
		$capacity = self::check_shift_capacity( $shift_id );
		if ( ! $capacity['available'] ) {
			$record->add_error(
				'capacity',
				sprintf(
					__( 'Shift is full. Current: %d/%d', 'stb-core' ),
					$capacity['current'],
					$capacity['capacity']
				)
			);
		}
	}

	/**
	 * Handle join shift side effects (audit logging, notifications).
	 * NOTE: Data persistence is handled by native insert_cct action.
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_join_shift_side_effects( array $fields, $record ): void {
		$shift_id = isset( $fields['shift_id'] ) ? intval( $fields['shift_id']['value'] ?? 0 ) : 0;
		$user_id  = get_current_user_id();

		if ( ! $shift_id || ! $user_id ) {
			return;
		}

		// Get the most recently created assignment for this user and shift
		// (since we can't get the ID from the native action)
		$assignment_db = self::get_cct_db( 'stb_assignments' );
		if ( ! $assignment_db ) {
			return;
		}

		// Use JetEngine's native query method to find existing assignment.
		$results = $assignment_db->query(
			array(
				'shift_id'     => $shift_id,
				'publisher_id' => $user_id,
				'state'       => 'requested',
			),
			1, // limit
			0, // offset
			array( 'cct_created' => 'DESC' ) // order
		);
		$assignment_id = ! empty( $results ) && isset( $results[0]['_ID'] ) ? (int) $results[0]['_ID'] : false;

		if ( false !== $assignment_id ) {
			// Log audit entry
			self::log_audit(
				array(
					'entity_type' => 'assignment',
					'entity_id'   => $assignment_id,
					'action'      => 'join_request',
					'actor_user_id' => $user_id,
				)
			);

			// Trigger notification workflow
			Stb_Notification_Service::handle_event(
				'join_requested',
				array(
					'assignment_id' => $assignment_id,
					'shift_id'      => $shift_id,
				)
			);
		}
	}

	/**
	 * Handle join shift submission (legacy - kept for backward compatibility).
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_join_shift( array $fields, $record ): void {
		self::handle_join_shift_side_effects( $fields, $record );
	}

	/**
	 * Validate leave shift submission (permission check).
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function validate_leave_shift( array $fields, $record ): void {
		$assignment_id = isset( $fields['assignment_id'] ) ? intval( $fields['assignment_id']['value'] ?? 0 ) : 0;
		$user_id       = get_current_user_id();

		if ( ! $assignment_id ) {
			$record->add_error( 'assignment_id', __( 'Assignment ID is required.', 'stb-core' ) );
			return;
		}

		// Get assignment to verify ownership
		$assignment = self::get_assignment( $assignment_id );
		if ( ! $assignment || (int) $assignment['publisher_id'] !== $user_id ) {
			$record->add_error( 'permission', __( 'You do not have permission to leave this shift.', 'stb-core' ) );
		}
	}

	/**
	 * Handle leave shift side effects (data update, audit logging, notifications).
	 * NOTE: Hybrid approach - native update_cct action has limitations (needs item_id),
	 * so we handle the update here along with side effects.
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_leave_shift_side_effects( array $fields, $record ): void {
		$assignment_id = isset( $fields['assignment_id'] ) ? intval( $fields['assignment_id']['value'] ?? 0 ) : 0;
		$reason        = isset( $fields['reason'] ) ? sanitize_text_field( $fields['reason']['value'] ?? '' ) : '';
		$user_id       = get_current_user_id();

		if ( ! $assignment_id ) {
			return;
		}

		$assignment = self::get_assignment( $assignment_id );
		if ( ! $assignment ) {
			return;
		}

		// Update assignment state to dropped (native action limitation - needs item_id)
		$updated = self::update_assignment(
			$assignment_id,
			array(
				'state' => 'dropped',
			)
		);

		if ( ! $updated ) {
			$record->add_error( 'update', __( 'Failed to update assignment. Please try again.', 'stb-core' ) );
			return;
		}

		// Log audit entry
		self::log_audit(
			array(
				'entity_type' => 'assignment',
				'entity_id'   => $assignment_id,
				'action'      => 'leave',
				'actor_user_id' => $user_id,
				'metadata_json' => wp_json_encode(
					array(
						'reason' => $reason,
					)
				),
			)
		);

		// Trigger notification workflow
		Stb_Notification_Service::handle_event(
			'removed',
			array(
				'assignment_id' => $assignment_id,
				'shift_id'      => $assignment['shift_id'],
			)
		);
	}

	/**
	 * Handle leave shift submission (legacy - kept for backward compatibility).
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_leave_shift( array $fields, $record ): void {
		self::handle_leave_shift_side_effects( $fields, $record );
	}

	/**
	 * Handle availability submission.
	 * NOTE: Hybrid approach - native action handles direct mappings, handler computes fields and updates.
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_availability_side_effects( array $fields, $record ): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$start_date = isset( $fields['start_date'] ) ? sanitize_text_field( $fields['start_date']['value'] ?? '' ) : '';
		$end_date   = isset( $fields['end_date'] ) ? sanitize_text_field( $fields['end_date']['value'] ?? '' ) : '';
		$start_time = isset( $fields['start_time'] ) ? sanitize_text_field( $fields['start_time']['value'] ?? '' ) : '';
		$end_time   = isset( $fields['end_time'] ) ? sanitize_text_field( $fields['end_time']['value'] ?? '' ) : '';
		$recurring  = isset( $fields['recurring'] ) ? $fields['recurring']['value'] ?? array() : array();
		$locations  = isset( $fields['preferred_locations'] ) ? (array) ( $fields['preferred_locations']['value'] ?? array() ) : array();

		// Build datetime strings (computed fields)
		$start_datetime = $start_date . ( $start_time ? ' ' . $start_time : ' 00:00:00' );
		$end_datetime   = $end_date . ( $end_time ? ' ' . $end_time : ' 23:59:59' );

		// Build recurrence rule if applicable
		$recurrence_rule = '';
		if ( ! empty( $recurring ) ) {
			if ( in_array( 'weekly', $recurring, true ) ) {
				$recurrence_rule = 'FREQ=WEEKLY';
			} elseif ( in_array( 'monthly', $recurring, true ) ) {
				$recurrence_rule = 'FREQ=MONTHLY';
			}
		}

		// Get the most recently created availability for this user
		$availability_db = self::get_cct_db( 'stb_availability' );
		if ( ! $availability_db ) {
			return;
		}

		// Use JetEngine's native query method to find existing availability.
		$results = $availability_db->query(
			array(
				'publisher_id' => $user_id,
				'mode'         => 'available',
			),
			1, // limit
			0, // offset
			array( 'cct_created' => 'DESC' ) // order
		);
		$availability_id = ! empty( $results ) && isset( $results[0]['_ID'] ) ? (int) $results[0]['_ID'] : false;

		if ( false !== $availability_id ) {
			// Update with computed fields
			$availability_db->update(
				array(
					'start_datetime'      => $start_datetime,
					'end_datetime'        => $end_datetime,
					'recurrence_rule'     => $recurrence_rule,
					'preferred_locations' => wp_json_encode( array_map( 'absint', $locations ) ),
				),
				array( '_ID' => $availability_id )
			);

			// Log audit entry
			self::log_audit(
				array(
					'entity_type' => 'availability',
					'entity_id'   => $availability_id,
					'action'      => 'availability_submitted',
					'actor_user_id' => $user_id,
				)
			);
		}

		// TODO: Recalculate suggestion cache
	}

	/**
	 * Handle unavailability submission.
	 * NOTE: Hybrid approach - native action handles direct mappings, handler computes fields and updates.
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_unavailability_side_effects( array $fields, $record ): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$start_date = isset( $fields['start_date'] ) ? sanitize_text_field( $fields['start_date']['value'] ?? '' ) : '';
		$end_date   = isset( $fields['end_date'] ) ? sanitize_text_field( $fields['end_date']['value'] ?? '' ) : '';
		$reason     = isset( $fields['reason'] ) ? sanitize_textarea_field( $fields['reason']['value'] ?? '' ) : '';

		// Full-day unavailability spans entire days (computed fields)
		$start_datetime = $start_date . ' 00:00:00';
		$end_datetime   = $end_date . ' 23:59:59';

		// Get the most recently created availability for this user
		$availability_db = self::get_cct_db( 'stb_availability' );
		if ( ! $availability_db ) {
			return;
		}

		// Use JetEngine's native query method to find existing unavailability.
		$results = $availability_db->query(
			array(
				'publisher_id' => $user_id,
				'mode'         => 'unavailable_full_day',
			),
			1, // limit
			0, // offset
			array( 'cct_created' => 'DESC' ) // order
		);
		$availability_id = ! empty( $results ) && isset( $results[0]['_ID'] ) ? (int) $results[0]['_ID'] : false;

		if ( false !== $availability_id ) {
			// Update with computed fields
			$availability_db->update(
				array(
					'start_datetime' => $start_datetime,
					'end_datetime'   => $end_datetime,
				),
				array( '_ID' => $availability_id )
			);

			// Log audit entry
			self::log_audit(
				array(
					'entity_type' => 'availability',
					'entity_id'   => $availability_id,
					'action'      => 'unavailability_submitted',
					'actor_user_id' => $user_id,
					'metadata_json' => wp_json_encode( array( 'reason' => $reason ) ),
				)
			);
		}
	}

	/**
	 * Validate admin assignment submission.
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function validate_admin_assign( array $fields, $record ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			$record->add_error( 'permission', __( 'You do not have permission to assign shifts.', 'stb-core' ) );
			return;
		}

		$shift_id      = isset( $fields['shift_id'] ) ? intval( $fields['shift_id']['value'] ?? 0 ) : 0;
		$publisher_ids = isset( $fields['publisher_id'] ) ? (array) ( $fields['publisher_id']['value'] ?? array() ) : array();

		if ( ! $shift_id || empty( $publisher_ids ) ) {
			$record->add_error( 'fields', __( 'Shift and at least one publisher are required.', 'stb-core' ) );
			return;
		}

		// Check capacity for each publisher assignment
		$capacity = self::check_shift_capacity( $shift_id );
		$needed   = count( $publisher_ids );
		if ( $capacity['current'] + $needed > $capacity['capacity'] ) {
			$record->add_error(
				'capacity',
				sprintf(
					__( 'Not enough capacity. Current: %d/%d, Needed: %d', 'stb-core' ),
					$capacity['current'],
					$capacity['capacity'],
					$needed
				)
			);
		}
	}

	/**
	 * Handle admin assignment side effects (audit logging, notifications).
	 * NOTE: Hybrid approach - native insert_cct action may only create one assignment per form submission.
	 * For multiple publishers, we need to handle creation in the handler OR rely on JetFormBuilder's
	 * ability to create multiple records from a multiple select field (if supported).
	 * This handler processes side effects and handles multiple publishers if needed.
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_admin_assign_side_effects( array $fields, $record ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$shift_id        = isset( $fields['shift_id'] ) ? intval( $fields['shift_id']['value'] ?? 0 ) : 0;
		$publisher_ids   = isset( $fields['publisher_id'] ) ? (array) ( $fields['publisher_id']['value'] ?? array() ) : array();
		$admin_override  = isset( $fields['admin_override_flag'] ) && $fields['admin_override_flag']['value'];
		$admin_id        = get_current_user_id();

		if ( ! $shift_id || empty( $publisher_ids ) ) {
			return;
		}

		// Get the most recently created assignments for this shift
		// (since we can't get IDs from native actions easily)
		$assignment_db = self::get_cct_db( 'stb_assignments' );
		if ( ! $assignment_db ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_stb_assignments';
		
		// Get assignments created in the last few seconds for this shift
		$assignment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT _ID FROM {$table} WHERE shift_id = %d AND state = 'confirmed' AND joined_via = 'admin-assign' AND cct_created >= DATE_SUB(NOW(), INTERVAL 5 SECOND) ORDER BY cct_created DESC LIMIT %d",
				$shift_id,
				count( $publisher_ids )
			)
		);

		foreach ( $assignment_ids as $assignment_id ) {
			$assignment_id = (int) $assignment_id;

			// Log audit entry
			self::log_audit(
				array(
					'entity_type' => 'assignment',
					'entity_id'   => $assignment_id,
					'action'      => $admin_override ? 'admin_override' : 'admin_assign',
					'actor_user_id' => $admin_id,
					'metadata_json' => wp_json_encode(
						array(
							'shift_id' => $shift_id,
							'override' => $admin_override,
						)
					),
				)
			);

			// Get assignment to find publisher_id for notification
			$assignment = self::get_assignment( $assignment_id );
			if ( $assignment && isset( $assignment['publisher_id'] ) ) {
				// Trigger confirmation notification
				Stb_Notification_Service::handle_event(
					'assigned',
					array(
						'assignment_id' => $assignment_id,
						'shift_id'      => $shift_id,
						'recipient_id'  => (int) $assignment['publisher_id'],
					)
				);
			}
		}
	}

	/**
	 * Handle admin assignment (legacy - kept for backward compatibility).
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_admin_assign( array $fields, $record ): void {
		self::handle_admin_assign_side_effects( $fields, $record );
	}

	/**
	 * Handle notification preferences update.
	 * NOTE: This form is now fully native - data persistence handled by update_user_meta action.
	 * This handler is kept for potential future side effects (e.g., recalculating reminder queue).
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_notification_preferences( array $fields, $record ): void {
		// Fully native - no custom handler needed
		// TODO: If needed in future, add reminder queue recalculation here
	}

	/**
	 * Handle copy day submission.
	 * 
	 * NOTE: This form requires a custom handler because:
	 * - Complex batch operation: Queries multiple shifts, duplicates them, optionally copies assignments
	 * - Requires transaction-like behavior (all-or-nothing with rollback capability via copy_batch_id)
	 * - Needs to generate summary report for admin
	 * - Cannot be done with simple native actions
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_copy_day( array $fields, $record ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			$record->add_error( 'permission', __( 'You do not have permission to copy days.', 'stb-core' ) );
			return;
		}

		$source_date = isset( $fields['source_date'] ) ? sanitize_text_field( $fields['source_date']['value'] ?? '' ) : '';
		$target_date = isset( $fields['target_date'] ) ? sanitize_text_field( $fields['target_date']['value'] ?? '' ) : '';
		$locations   = isset( $fields['locations'] ) ? (array) ( $fields['locations']['value'] ?? array() ) : array();
		$copy_assignments = isset( $fields['copy_assignments'] ) && $fields['copy_assignments']['value'];
		$assignment_status = isset( $fields['assignment_status'] ) ? sanitize_text_field( $fields['assignment_status']['value'] ?? 'pending' ) : 'pending';

		if ( ! $source_date || ! $target_date ) {
			$record->add_error( 'dates', __( 'Source date and target date are required.', 'stb-core' ) );
			return;
		}

		// TODO: Implement copy-day logic
		// This should query shifts from source_date, duplicate them to target_date,
		// optionally copy assignments, and set copy_batch_id for rollback

		// Log audit entry
		self::log_audit(
			array(
				'entity_type' => 'shift',
				'entity_id'   => 0,
				'action'      => 'copy_day',
				'actor_user_id' => get_current_user_id(),
				'metadata_json' => wp_json_encode(
					array(
						'source_date' => $source_date,
						'target_date' => $target_date,
						'locations'   => $locations,
						'copy_assignments' => $copy_assignments,
					)
				),
			)
		);
	}

	/**
	 * Handle shift template submission.
	 * 
	 * NOTE: Hybrid approach - native insert_post action handles template creation,
	 * but this handler is needed for:
	 * - Optional immediate shift generation (if run_generator_now is checked)
	 * - Audit logging with correct template ID (needs post ID from action)
	 * - Complex logic that can't be done natively
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_shift_template( array $fields, $record ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			$record->add_error( 'permission', __( 'You do not have permission to create templates.', 'stb-core' ) );
			return;
		}

		// Template creation is handled by JetFormBuilder's insert_post action
		// This handler can trigger the generator if requested
		$run_now = isset( $fields['run_generator_now'] ) && $fields['run_generator_now']['value'];

		if ( $run_now ) {
			// TODO: Trigger shift generator for this template
		}

		// Log audit entry
		self::log_audit(
			array(
				'entity_type' => 'template',
				'entity_id'   => 0, // Will be set after post creation
				'action'      => 'template_created',
				'actor_user_id' => get_current_user_id(),
			)
		);
	}

	/**
	 * Handle audit note submission side effects.
	 * NOTE: Data persistence is handled by native insert_cct action.
	 * This handler only logs to audit log.
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_audit_note_side_effects( array $fields, $record ): void {
		$subject_type = isset( $fields['subject_type'] ) ? sanitize_text_field( $fields['subject_type']['value'] ?? '' ) : '';
		$subject_id   = isset( $fields['subject_id'] ) ? intval( $fields['subject_id']['value'] ?? 0 ) : 0;
		$visibility   = isset( $fields['visibility'] ) ? sanitize_text_field( $fields['visibility']['value'] ?? 'admin-only' ) : 'admin-only';
		$pinned       = isset( $fields['pinned'] ) && $fields['pinned']['value'];

		if ( ! $subject_type || ! $subject_id ) {
			return;
		}

		// Log to audit log (admin note CCT insertion is handled by native action)
		self::log_audit(
			array(
				'entity_type' => $subject_type,
				'entity_id'   => $subject_id,
				'action'      => 'admin_note_added',
				'actor_user_id' => get_current_user_id(),
				'metadata_json' => wp_json_encode(
					array(
						'visibility' => $visibility,
						'pinned'     => $pinned,
					)
				),
			)
		);
	}

	/**
	 * Handle bulk broadcast submission.
	 * 
	 * NOTE: This form requires a custom handler because:
	 * - Complex audience resolution: Queries assignments/shifts based on multiple filters (location, date range)
	 * - Batch queue creation: Creates multiple Notification Queue entries (one per recipient)
	 * - Respects user preferences: Checks per-user channel preferences before queuing
	 * - Requires batching to avoid rate limits
	 * - Cannot be done with simple native actions
	 *
	 * @param array                     $fields Form fields.
	 * @param \JFB_Modules\Form_Record $record Form record.
	 */
	protected static function handle_bulk_broadcast( array $fields, $record ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			$record->add_error( 'permission', __( 'You do not have permission to send broadcasts.', 'stb-core' ) );
			return;
		}

		$locations   = isset( $fields['location_filter'] ) ? (array) ( $fields['location_filter']['value'] ?? array() ) : array();
		$date_from   = isset( $fields['date_from'] ) ? sanitize_text_field( $fields['date_from']['value'] ?? '' ) : '';
		$date_to     = isset( $fields['date_to'] ) ? sanitize_text_field( $fields['date_to']['value'] ?? '' ) : '';
		$message     = isset( $fields['message_body'] ) ? wp_kses_post( $fields['message_body']['value'] ?? '' ) : '';
		$channels    = isset( $fields['channels'] ) ? (array) ( $fields['channels']['value'] ?? array() ) : array();
		$language    = isset( $fields['language'] ) ? sanitize_text_field( $fields['language']['value'] ?? 'en' ) : 'en';

		if ( ! $message || empty( $channels ) ) {
			$record->add_error( 'fields', __( 'Message and at least one channel are required.', 'stb-core' ) );
			return;
		}

		// TODO: Resolve audience based on filters
		// TODO: Create notification queue entries for each recipient
		// TODO: Respect user preferences

		// Log audit entry
		self::log_audit(
			array(
				'entity_type' => 'notification',
				'entity_id'   => 0,
				'action'      => 'bulk_broadcast',
				'actor_user_id' => get_current_user_id(),
				'metadata_json' => wp_json_encode(
					array(
						'locations' => $locations,
						'date_from' => $date_from,
						'date_to'   => $date_to,
						'channels'  => $channels,
						'language'  => $language,
					)
				),
			)
		);
	}

	/**
	 * Form definitions.
	 *
	 * @return array[]
	 */
	protected static function definitions(): array {
		return array(
			self::availability_form(),
			self::unavailability_form(),
			self::join_shift_form(),
			self::leave_shift_form(),
			self::admin_assign_form(),
			self::notification_preferences_form(),
			self::copy_day_form(),
			self::shift_template_form(),
			self::audit_note_form(),
			self::bulk_broadcast_form(),
		);
	}

	/**
	 * Availability submission form.
	 *
	 * @return array
	 */
	protected static function availability_form(): array {
		return array(
			'slug'        => 'availability_submit',
			'title'       => __( 'Submit Availability', 'stb-core' ),
			'description' => __( 'Form for publishers to submit their recurring availability preferences.', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'select',
					'name'        => 'mode',
					'label'       => __( 'Mode', 'stb-core' ),
					'required'    => true,
					'options'     => array(
						'available' => __( 'Available', 'stb-core' ),
					),
					'default'     => 'available',
				),
				array(
					'type'        => 'date',
					'name'        => 'start_date',
					'label'       => __( 'Start Date', 'stb-core' ),
					'required'    => true,
				),
				array(
					'type'        => 'date',
					'name'        => 'end_date',
					'label'       => __( 'End Date', 'stb-core' ),
					'required'    => true,
				),
				array(
					'type'        => 'time',
					'name'        => 'start_time',
					'label'       => __( 'Start Time', 'stb-core' ),
					'required'    => false,
				),
				array(
					'type'        => 'time',
					'name'        => 'end_time',
					'label'       => __( 'End Time', 'stb-core' ),
					'required'    => false,
				),
				array(
					'type'        => 'checkbox',
					'name'        => 'recurring',
					'label'       => __( 'Recurring', 'stb-core' ),
					'options'     => array(
						'weekly'  => __( 'Weekly', 'stb-core' ),
						'monthly' => __( 'Monthly', 'stb-core' ),
					),
				),
				array(
					'type'        => 'select',
					'name'        => 'preferred_locations',
					'label'       => __( 'Preferred Locations', 'stb-core' ),
					'multiple'    => true,
					'options'     => self::get_location_options(),
				),
			),
			'actions'  => array(
				// Note: Computed fields (datetime combinations, recurrence_rule) are handled in handler
				// Native action handles direct mappings only
				array(
					'type'       => 'insert_cct',
					'cct'        => 'stb_availability',
					'fields_map' => self::build_field_mapping(
						array(
							'publisher_id'        => '{current_user_id}',
							'mode'                => 'available',
							'preferred_locations' => 'preferred_locations',
						)
					),
				),
			),
			'settings' => array(
				'submit_button_text' => __( 'Save Availability', 'stb-core' ),
			),
		);
	}

	/**
	 * Unavailability form.
	 *
	 * @return array
	 */
	protected static function unavailability_form(): array {
		return array(
			'slug'        => 'unavailability_full_day',
			'title'       => __( 'Record Unavailability / Vacation', 'stb-core' ),
			'description' => __( 'Form for publishers to record full-day unavailability periods (vacations, etc.).', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'date',
					'name'        => 'start_date',
					'label'       => __( 'Start Date', 'stb-core' ),
					'required'    => true,
				),
				array(
					'type'        => 'date',
					'name'        => 'end_date',
					'label'       => __( 'End Date', 'stb-core' ),
					'required'    => true,
				),
				array(
					'type'        => 'textarea',
					'name'        => 'reason',
					'label'       => __( 'Reason', 'stb-core' ),
					'required'    => false,
					'placeholder' => __( 'Optional reason for unavailability', 'stb-core' ),
				),
			),
			'actions'  => array(
				// Note: Computed fields (datetime combinations) are handled in handler
				// Native action handles direct mappings only
				array(
					'type'       => 'insert_cct',
					'cct'        => 'stb_availability',
					'fields_map' => self::build_field_mapping(
						array(
							'publisher_id' => '{current_user_id}',
							'mode'         => 'unavailable_full_day',
						)
					),
				),
			),
			'settings' => array(
				'submit_button_text' => __( 'Save Unavailability', 'stb-core' ),
			),
		);
	}

	/**
	 * Join shift form.
	 *
	 * @return array
	 */
	protected static function join_shift_form(): array {
		return array(
			'slug'        => 'publisher_join_shift',
			'title'       => __( 'Join Shift', 'stb-core' ),
			'description' => __( 'Form for publishers to request joining an open shift.', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'hidden',
					'name'        => 'shift_id',
					'default'     => '{get_param|shift_id}',
				),
				array(
					'type'        => 'textarea',
					'name'        => 'note',
					'label'       => __( 'Note (Optional)', 'stb-core' ),
					'required'    => false,
					'placeholder' => __( 'Add a note for coordinators', 'stb-core' ),
				),
			),
			'actions'  => array(
				array(
					'type'       => 'insert_cct',
					'cct'        => 'stb_assignments',
					'fields_map' => self::build_field_mapping(
						array(
							'shift_id'   => 'shift_id',
							'publisher_id' => '{current_user_id}',
							'state'      => 'requested',
							'joined_via' => 'self-join',
						)
					),
				),
			),
			'settings' => array(
				'submit_button_text' => __( 'Request to Join', 'stb-core' ),
			),
		);
	}

	/**
	 * Leave shift form.
	 *
	 * @return array
	 */
	protected static function leave_shift_form(): array {
		return array(
			'slug'        => 'publisher_leave_shift',
			'title'       => __( 'Leave Shift', 'stb-core' ),
			'description' => __( 'Form for publishers to leave or cancel an assigned shift.', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'hidden',
					'name'        => 'assignment_id',
					'default'     => '{get_param|assignment_id}',
				),
				array(
					'type'        => 'select',
					'name'        => 'reason',
					'label'       => __( 'Reason', 'stb-core' ),
					'required'    => true,
					'options'     => array(
						'conflict'     => __( 'Schedule Conflict', 'stb-core' ),
						'emergency'    => __( 'Emergency', 'stb-core' ),
						'unavailable' => __( 'No Longer Available', 'stb-core' ),
						'other'        => __( 'Other', 'stb-core' ),
					),
				),
			),
			'actions'  => array(
				// Note: update_cct requires item_id which is not easily accessible from form fields
				// The update is handled in the custom handler (handle_leave_shift_side_effects)
				// This is a limitation of native actions - they work best for inserts
			),
			'settings' => array(
				'submit_button_text' => __( 'Leave Shift', 'stb-core' ),
			),
		);
	}

	/**
	 * Admin assign form.
	 *
	 * @return array
	 */
	protected static function admin_assign_form(): array {
		return array(
			'slug'        => 'admin_assign_shift',
			'title'       => __( 'Assign Publisher to Shift', 'stb-core' ),
			'description' => __( 'Admin form to directly assign publisher(s) to shifts with optional availability override.', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'select',
					'name'        => 'shift_id',
					'label'       => __( 'Shift', 'stb-core' ),
					'required'    => true,
					'options'     => self::get_shift_options(),
				),
				array(
					'type'        => 'select',
					'name'        => 'publisher_id',
					'label'       => __( 'Publisher', 'stb-core' ),
					'required'    => true,
					'multiple'    => true,
					'options'     => self::get_publisher_options(),
				),
				array(
					'type'        => 'number',
					'name'        => 'priority_weight',
					'label'       => __( 'Priority Weight', 'stb-core' ),
					'required'    => false,
					'default'     => 1,
					'min'         => 0,
					'max'         => 10,
				),
				array(
					'type'        => 'switch',
					'name'        => 'admin_override_flag',
					'label'       => __( 'Override Availability', 'stb-core' ),
					'default'     => false,
				),
			),
			'actions'  => array(
				array(
					'type'       => 'insert_cct',
					'cct'        => 'stb_assignments',
					'fields_map' => self::build_field_mapping(
						array(
							'shift_id'          => 'shift_id',
							'publisher_id'      => 'publisher_id',
							'state'             => 'confirmed',
							'joined_via'        => 'admin-assign',
							'admin_override_flag' => 'admin_override_flag',
							'points_weight'     => 'priority_weight',
						)
					),
				),
			),
			'settings' => array(
				'submit_button_text' => __( 'Assign Publisher', 'stb-core' ),
				'require_capability' => 'manage_options',
			),
		);
	}

	/**
	 * Notification preferences form.
	 *
	 * @return array
	 */
	protected static function notification_preferences_form(): array {
		return array(
			'slug'        => 'notification_preferences',
			'title'       => __( 'Notification Preferences', 'stb-core' ),
			'description' => __( 'Form for users to manage their notification preferences (email, push, language).', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'heading',
					'label'       => __( 'Email Notifications', 'stb-core' ),
				),
				array(
					'type'        => 'switch',
					'name'        => 'notify_email_assignments',
					'label'       => __( 'Email me when assigned to shifts', 'stb-core' ),
					'default'     => true,
				),
				array(
					'type'        => 'switch',
					'name'        => 'notify_email_reminders',
					'label'       => __( 'Email reminders (24h & 1h before)', 'stb-core' ),
					'default'     => true,
				),
				array(
					'type'        => 'heading',
					'label'       => __( 'Push Notifications', 'stb-core' ),
				),
				array(
					'type'        => 'switch',
					'name'        => 'notify_push_assignments',
					'label'       => __( 'Push notifications for assignments', 'stb-core' ),
					'default'     => false,
				),
				array(
					'type'        => 'switch',
					'name'        => 'notify_push_reminders',
					'label'       => __( 'Push reminders (24h & 1h before)', 'stb-core' ),
					'default'     => false,
				),
				array(
					'type'        => 'select',
					'name'        => 'preferred_notification_language',
					'label'       => __( 'Notification Language', 'stb-core' ),
					'required'    => true,
					'options'     => array(
						'en' => __( 'English', 'stb-core' ),
						'no' => __( 'Norwegian', 'stb-core' ),
					),
					'default'     => 'en',
				),
			),
			'actions'  => array(
				array(
					'type'       => 'update_user_meta',
					'fields_map' => self::build_field_mapping(
						array(
							'notify_email_assignments'      => 'notify_email_assignments',
							'notify_email_reminders'        => 'notify_email_reminders',
							'notify_push_assignments'       => 'notify_push_assignments',
							'notify_push_reminders'         => 'notify_push_reminders',
							'preferred_notification_language' => 'preferred_notification_language',
						)
					),
				),
			),
			'settings' => array(
				'submit_button_text' => __( 'Save Preferences', 'stb-core' ),
			),
		);
	}

	/**
	 * Copy day form.
	 *
	 * @return array
	 */
	protected static function copy_day_form(): array {
		return array(
			'slug'        => 'copy_day_action',
			'title'       => __( 'Copy Day Schedule', 'stb-core' ),
			'description' => __( 'Copy shifts from one day to another, optionally including assignments.', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'date',
					'name'        => 'source_date',
					'label'       => __( 'Source Date', 'stb-core' ),
					'required'    => true,
				),
				array(
					'type'        => 'date',
					'name'        => 'target_date',
					'label'       => __( 'Target Date', 'stb-core' ),
					'required'    => true,
				),
				array(
					'type'        => 'select',
					'name'        => 'locations',
					'label'       => __( 'Locations (leave empty for all)', 'stb-core' ),
					'multiple'    => true,
					'required'    => false,
					'options'     => self::get_location_options(),
				),
				array(
					'type'        => 'switch',
					'name'        => 'copy_assignments',
					'label'       => __( 'Copy Assignments', 'stb-core' ),
					'default'     => false,
				),
				array(
					'type'        => 'select',
					'name'        => 'assignment_status',
					'label'       => __( 'Assignment Status', 'stb-core' ),
					'required'    => false,
					'options'     => array(
						'confirmed' => __( 'Confirmed', 'stb-core' ),
						'pending'   => __( 'Pending', 'stb-core' ),
					),
					'default'     => 'pending',
				),
			),
			'actions'  => array(),
			'settings' => array(
				'submit_button_text' => __( 'Copy Day', 'stb-core' ),
				'require_capability' => 'manage_options',
			),
		);
	}

	/**
	 * Shift template builder form.
	 *
	 * @return array
	 */
	protected static function shift_template_form(): array {
		return array(
			'slug'        => 'shift_template_builder',
			'title'       => __( 'Create Shift Template', 'stb-core' ),
			'description' => __( 'Create a recurring shift template that generates daily shifts automatically.', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'select',
					'name'        => 'location_id',
					'label'       => __( 'Location', 'stb-core' ),
					'required'    => true,
					'options'     => self::get_location_options(),
				),
				array(
					'type'        => 'text',
					'name'        => 'slot_label',
					'label'       => __( 'Time Slot Label', 'stb-core' ),
					'required'    => true,
					'placeholder' => __( 'e.g., Morning, Afternoon, Evening', 'stb-core' ),
				),
				array(
					'type'        => 'time',
					'name'        => 'start_time',
					'label'       => __( 'Start Time', 'stb-core' ),
					'required'    => true,
				),
				array(
					'type'        => 'time',
					'name'        => 'end_time',
					'label'       => __( 'End Time', 'stb-core' ),
					'required'    => true,
				),
				array(
					'type'        => 'number',
					'name'        => 'capacity',
					'label'       => __( 'Capacity', 'stb-core' ),
					'required'    => true,
					'min'         => 1,
					'default'     => 1,
				),
				array(
					'type'        => 'checkbox',
					'name'        => 'weekdays',
					'label'       => __( 'Active Days', 'stb-core' ),
					'required'    => true,
					'options'     => array(
						'monday'    => __( 'Monday', 'stb-core' ),
						'tuesday'   => __( 'Tuesday', 'stb-core' ),
						'wednesday' => __( 'Wednesday', 'stb-core' ),
						'thursday'  => __( 'Thursday', 'stb-core' ),
						'friday'    => __( 'Friday', 'stb-core' ),
						'saturday'  => __( 'Saturday', 'stb-core' ),
						'sunday'    => __( 'Sunday', 'stb-core' ),
					),
				),
				array(
					'type'        => 'number',
					'name'        => 'auto_generate_horizon',
					'label'       => __( 'Auto-generate Horizon (days)', 'stb-core' ),
					'required'    => false,
					'min'         => 0,
					'default'     => 30,
					'placeholder' => __( 'Days ahead to auto-generate shifts', 'stb-core' ),
				),
				array(
					'type'        => 'switch',
					'name'        => 'run_generator_now',
					'label'       => __( 'Generate Shifts Now', 'stb-core' ),
					'default'     => false,
				),
			),
			'actions'  => array(
				array(
					'type' => 'insert_post',
					'post_type' => 'stb_shift_template',
				),
			),
			'settings' => array(
				'submit_button_text' => __( 'Create Template', 'stb-core' ),
				'require_capability' => 'manage_options',
			),
		);
	}

	/**
	 * Audit log note form.
	 *
	 * @return array
	 */
	protected static function audit_note_form(): array {
		return array(
			'slug'        => 'audit_log_note',
			'title'       => __( 'Add Admin Note', 'stb-core' ),
			'description' => __( 'Add a manual note to the audit log for a publisher, location, or shift.', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'select',
					'name'        => 'subject_type',
					'label'       => __( 'Subject Type', 'stb-core' ),
					'required'    => true,
					'options'     => array(
						'publisher' => __( 'Publisher', 'stb-core' ),
						'location'  => __( 'Location', 'stb-core' ),
						'shift'     => __( 'Shift', 'stb-core' ),
					),
				),
				array(
					'type'        => 'number',
					'name'        => 'subject_id',
					'label'       => __( 'Subject ID', 'stb-core' ),
					'required'    => true,
					'min'         => 1,
				),
				array(
					'type'        => 'textarea',
					'name'        => 'note_body',
					'label'       => __( 'Note', 'stb-core' ),
					'required'    => true,
					'placeholder' => __( 'Enter your note here...', 'stb-core' ),
				),
				array(
					'type'        => 'select',
					'name'        => 'visibility',
					'label'       => __( 'Visibility', 'stb-core' ),
					'required'    => true,
					'options'     => array(
						'admin-only' => __( 'Admin Only', 'stb-core' ),
						'shift-leads' => __( 'Shift Leads', 'stb-core' ),
					),
					'default'     => 'admin-only',
				),
				array(
					'type'        => 'switch',
					'name'        => 'pinned',
					'label'       => __( 'Pin Note', 'stb-core' ),
					'default'     => false,
				),
			),
			'actions'  => array(
				array(
					'type'       => 'insert_cct',
					'cct'        => 'stb_admin_notes',
					'fields_map' => self::build_field_mapping(
						array(
							'subject_type' => 'subject_type',
							'subject_id'   => 'subject_id',
							'note_body'    => 'note_body',
							'visibility'   => 'visibility',
							'pinned'       => 'pinned',
						)
					),
				),
			),
			'settings' => array(
				'submit_button_text' => __( 'Add Note', 'stb-core' ),
				'require_capability' => 'manage_options',
			),
		);
	}

	/**
	 * Bulk notification broadcast form.
	 *
	 * @return array
	 */
	protected static function bulk_broadcast_form(): array {
		return array(
			'slug'        => 'bulk_notification_broadcast',
			'title'       => __( 'Bulk Notification Broadcast', 'stb-core' ),
			'description' => __( 'Send a notification to multiple publishers based on filters (location, slot, date).', 'stb-core' ),
			'fields'   => array(
				array(
					'type'        => 'select',
					'name'        => 'location_filter',
					'label'       => __( 'Location Filter (optional)', 'stb-core' ),
					'multiple'    => true,
					'required'    => false,
					'options'     => self::get_location_options(),
				),
				array(
					'type'        => 'date',
					'name'        => 'date_from',
					'label'       => __( 'Date From (optional)', 'stb-core' ),
					'required'    => false,
				),
				array(
					'type'        => 'date',
					'name'        => 'date_to',
					'label'       => __( 'Date To (optional)', 'stb-core' ),
					'required'    => false,
				),
				array(
					'type'        => 'textarea',
					'name'        => 'message_body',
					'label'       => __( 'Message', 'stb-core' ),
					'required'    => true,
					'placeholder' => __( 'Enter your message here...', 'stb-core' ),
				),
				array(
					'type'        => 'checkbox',
					'name'        => 'channels',
					'label'       => __( 'Channels', 'stb-core' ),
					'required'    => true,
					'options'     => array(
						'email' => __( 'Email', 'stb-core' ),
						'push'  => __( 'Push Notification', 'stb-core' ),
					),
				),
				array(
					'type'        => 'select',
					'name'        => 'language',
					'label'       => __( 'Language', 'stb-core' ),
					'required'    => true,
					'options'     => array(
						'en' => __( 'English', 'stb-core' ),
						'no' => __( 'Norwegian', 'stb-core' ),
					),
					'default'     => 'en',
				),
			),
			'actions'  => array(),
			'settings' => array(
				'submit_button_text' => __( 'Send Broadcast', 'stb-core' ),
				'require_capability' => 'manage_options',
			),
		);
	}

	/**
	 * Get location options for select fields.
	 *
	 * @return array
	 */
	protected static function get_location_options(): array {
		$locations = get_posts(
			array(
				'post_type'      => 'stb_location',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'is_active',
						'value' => '1',
					),
				),
			)
		);

		$options = array();
		foreach ( $locations as $location_id ) {
			$title = get_the_title( $location_id );
			if ( $title ) {
				$options[ $location_id ] = $title;
			}
		}

		return $options;
	}

	/**
	 * Get shift options for select fields.
	 *
	 * @return array
	 */
	protected static function get_shift_options(): array {
		// This would need to query the Shifts CCT
		// For now, return empty - will be populated dynamically
		return array();
	}

	/**
	 * Get publisher options for select fields.
	 *
	 * @return array
	 */
	protected static function get_publisher_options(): array {
		$users = get_users(
			array(
				'role'   => 'publisher',
				'fields' => array( 'ID', 'display_name' ),
			)
		);

		$options = array();
		foreach ( $users as $user ) {
			$options[ $user->ID ] = $user->display_name;
		}

		return $options;
	}

	/**
	 * Get CCT DB instance.
	 *
	 * @param string $slug CCT slug.
	 * @return \Jet_Engine\Modules\Custom_Content_Types\DB|null
	 */
	protected static function get_cct_db( string $slug ) {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\DB' ) ) {
			require_once jet_engine()->modules->modules_path( 'custom-content-types/inc/db.php' );
		}

		return new \Jet_Engine\Modules\Custom_Content_Types\DB( $slug, array() );
	}

	/**
	 * Check if shift has available capacity.
	 *
	 * @param int $shift_id Shift ID.
	 * @return array{available: bool, current: int, capacity: int}
	 */
	protected static function check_shift_capacity( int $shift_id ): array {
		$shift_db = self::get_cct_db( 'stb_shifts' );
		if ( ! $shift_db ) {
			return array( 'available' => false, 'current' => 0, 'capacity' => 0 );
		}

		$shift = $shift_db->get_item( $shift_id );
		if ( ! $shift ) {
			return array( 'available' => false, 'current' => 0, 'capacity' => 0 );
		}

		$capacity = isset( $shift['capacity'] ) ? intval( $shift['capacity'] ) : 0;

		// Count current assignments (confirmed or requested) using JetEngine's native count method.
		$assign_db = self::get_cct_db( 'stb_assignments' );
		if ( ! $assign_db ) {
			return array( 'available' => false, 'current' => 0, 'capacity' => $capacity );
		}

		$current = (int) $assign_db->count(
			array(
				'shift_id' => $shift_id,
				'state'    => array( 'confirmed', 'requested' ),
			),
			'AND'
		);

		return array(
			'available' => $current < $capacity,
			'current'   => $current,
			'capacity'  => $capacity,
		);
	}

	/**
	 * Create assignment.
	 *
	 * @param array $data Assignment data.
	 * @return int|false Assignment ID or false on failure.
	 */
	protected static function create_assignment( array $data ): int|false {
		$db = self::get_cct_db( 'stb_assignments' );
		if ( ! $db ) {
			return false;
		}

		$data['cct_author_id'] = get_current_user_id();
		$data['cct_created']   = current_time( 'mysql' );
		$data['cct_modified']  = current_time( 'mysql' );

		$result = $db->insert( $data );
		return $result ? (int) $result : false;
	}

	/**
	 * Update assignment.
	 *
	 * @param int   $assignment_id Assignment ID.
	 * @param array $data          Update data.
	 * @return bool
	 */
	protected static function update_assignment( int $assignment_id, array $data ): bool {
		$db = self::get_cct_db( 'stb_assignments' );
		if ( ! $db ) {
			return false;
		}

		$data['cct_modified'] = current_time( 'mysql' );

		return (bool) $db->update( $data, array( '_ID' => $assignment_id ) );
	}

	/**
	 * Get assignment by ID.
	 *
	 * @param int $assignment_id Assignment ID.
	 * @return array|null
	 */
	protected static function get_assignment( int $assignment_id ): ?array {
		$db = self::get_cct_db( 'stb_assignments' );
		if ( ! $db ) {
			return null;
		}

		$assignment = $db->get_item( $assignment_id );
		return $assignment ?: null;
	}

	/**
	 * Create availability window.
	 *
	 * @param array $data Availability data.
	 * @return int|false Availability ID or false on failure.
	 */
	protected static function create_availability( array $data ): int|false {
		$db = self::get_cct_db( 'stb_availability' );
		if ( ! $db ) {
			return false;
		}

		$data['cct_author_id'] = get_current_user_id();
		$data['cct_created']   = current_time( 'mysql' );
		$data['cct_modified']  = current_time( 'mysql' );

		$result = $db->insert( $data );
		return $result ? (int) $result : false;
	}

	/**
	 * Log audit entry.
	 *
	 * @param array $data Audit data.
	 * @return int|false Audit ID or false on failure.
	 */
	protected static function log_audit( array $data ): int|false {
		$db = self::get_cct_db( 'stb_audit_log' );
		if ( ! $db ) {
			return false;
		}

		$data['cct_author_id'] = $data['actor_user_id'] ?? get_current_user_id();
		$data['cct_created']   = current_time( 'mysql' );
		$data['cct_modified']  = current_time( 'mysql' );
		$data['timestamp']     = current_time( 'mysql' );
		$data['actor_role']    = $data['actor_role'] ?? ( current_user_can( 'manage_options' ) ? 'admin' : 'publisher' );

		$result = $db->insert( $data );
		return $result ? (int) $result : false;
	}
}

