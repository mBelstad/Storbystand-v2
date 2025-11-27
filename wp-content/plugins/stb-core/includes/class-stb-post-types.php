<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_Post_Types {

	/**
	 * Register custom post types.
	 */
	public static function register_post_types(): void {
		register_post_type(
			'stb_location',
			array(
				'labels'       => array(
					'name'          => __( 'Locations', 'stb-core' ),
					'singular_name' => __( 'Location', 'stb-core' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-location-alt',
				'supports'     => array( 'title', 'editor', 'excerpt' ),
				'has_archive'  => false,
			)
		);

		register_post_type(
			'stb_shift_template',
			array(
				'labels'       => array(
					'name'          => __( 'Shift Templates', 'stb-core' ),
					'singular_name' => __( 'Shift Template', 'stb-core' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-schedule',
				'supports'     => array( 'title', 'editor' ),
			)
		);

		register_post_type(
			'stb_stats_snapshot',
			array(
				'labels'       => array(
					'name'          => __( 'Stats Snapshots', 'stb-core' ),
					'singular_name' => __( 'Stats Snapshot', 'stb-core' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-chart-bar',
				'supports'     => array( 'title' ),
			)
		);
	}

	/**
	 * Register meta fields for CPTs.
	 */
	public static function register_meta_fields(): void {
		$meta_fields = array(
			'stb_location'       => array(
				'stb_address'       => 'string',
				'stb_geo_lat'       => 'number',
				'stb_geo_lng'       => 'number',
				'stb_contact_phone' => 'string',
				'stb_contact_email' => 'string',
				'stb_color_token'   => 'string',
				'stb_is_active'     => 'boolean',
			),
			'stb_shift_template' => array(
				'stb_location_ref'          => 'integer',
				'stb_slot_id'               => 'string',
				'stb_weekday_mask'          => 'string',
				'stb_default_capacity'      => 'integer',
				'stb_start_time'            => 'string',
				'stb_end_time'              => 'string',
				'stb_notification_lead'     => 'integer',
				'stb_auto_generate_horizon' => 'integer',
			),
			'stb_stats_snapshot'  => array(
				'stb_scope_type'     => 'string',
				'stb_scope_ref'      => 'integer',
				'stb_scope_label'    => 'string',
				'stb_period_key'     => 'string',
				'stb_period_start'   => 'string',
				'stb_period_end'     => 'string',
				'stb_total_shifts'   => 'integer',
				'stb_total_hours'    => 'number',
				'stb_cancellations'  => 'integer',
				'stb_late_cancels'   => 'integer',
				'stb_substitutions'  => 'integer',
				'stb_notifications_sent'   => 'integer',
				'stb_notifications_failed' => 'integer',
				'stb_generated_at'   => 'string',
				'stb_generator_ver'  => 'string',
				'stb_payload'        => 'string',
			),
		);

		foreach ( $meta_fields as $post_type => $fields ) {
			foreach ( $fields as $key => $type ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'         => $type,
						'show_in_rest' => true,
						'single'       => true,
						'auth_callback'=> '__return_true',
					)
				);
			}
		}
	}
}

