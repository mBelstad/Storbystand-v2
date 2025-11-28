<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_Elementor_Templates {

	const VERSION_OPTION = 'stb_core_elementor_templates_version';
	const VERSION        = '2025-11-28-elementor-3';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_sync' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_dashboard_styles' ) );
	}

	/**
	 * Enqueue dashboard styles for Elementor templates.
	 */
	public static function enqueue_dashboard_styles(): void {
		// Enqueue on all pages to ensure styles are available for Elementor templates.
		wp_enqueue_style(
			'stb-dashboard',
			trailingslashit( STB_CORE_URL ) . 'assets/css/dashboard.css',
			array(),
			STB_CORE_VERSION
		);
	}

	public static function maybe_sync(): void {
		if ( ! post_type_exists( 'elementor_library' ) ) {
			return;
		}

		$current = get_option( self::VERSION_OPTION );

		if ( self::VERSION === $current ) {
			return;
		}

		self::force_sync();
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	public static function force_sync( bool $force = false ): void {
		if ( ! post_type_exists( 'elementor_library' ) ) {
			return;
		}

		foreach ( self::definitions() as $definition ) {
			self::upsert_template( $definition );
		}

		if ( $force ) {
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}

	protected static function upsert_template( array $definition ): void {
		$template_id = self::get_template_id( $definition['slug'] );

		$postarr = array(
			'post_title'   => $definition['title'],
			'post_status'  => 'publish',
			'post_type'    => 'elementor_library',
			'post_content' => '',
			'post_excerpt' => '',
		);

		if ( $template_id ) {
			$postarr['ID']  = $template_id;
			wp_update_post( $postarr );
		} else {
			$postarr['post_name'] = $definition['slug'];
			$template_id          = wp_insert_post( $postarr );
		}

		if ( ! $template_id || is_wp_error( $template_id ) ) {
			return;
		}

		$elementor_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.20.0';

		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $template_id, '_elementor_version', $elementor_version );
		update_post_meta( $template_id, '_elementor_template_type', 'page' );
		update_post_meta( $template_id, '_stb_template_slug', $definition['slug'] );
		update_post_meta( $template_id, '_elementor_data', wp_json_encode( $definition['structure'] ) );
	}

	protected static function get_template_id( string $slug ): ?int {
		$existing = get_posts(
			array(
				'post_type'  => 'elementor_library',
				'post_status'=> 'any',
				'numberposts'=> 1,
				'meta_key'   => '_stb_template_slug',
				'meta_value' => $slug,
				'fields'     => 'ids',
			)
		);

		return $existing ? intval( $existing[0] ) : null;
	}

	/**
	 * Get JetFormBuilder form ID by slug.
	 *
	 * @param string $slug Form slug.
	 * @return int Form ID or 0 if not found.
	 */
	protected static function get_form_id( string $slug ): int {
		if ( class_exists( 'Stb_JetFormBuilder' ) ) {
			$form_id = Stb_JetFormBuilder::get_form_id_by_slug( $slug );
			return $form_id ?: 0;
		}

		// Fallback: query directly
		$existing = get_posts(
			array(
				'post_type'   => 'jet-form-builder',
				'post_status' => 'publish',
				'numberposts' => 1,
				'meta_key'    => '_stb_form_slug',
				'meta_value'  => $slug,
				'fields'      => 'ids',
			)
		);

		return $existing ? intval( $existing[0] ) : 0;
	}

	protected static function definitions(): array {
		return array(
			array(
				'slug'      => 'stb_publisher_dashboard',
				'title'     => __( 'Storbystand Publisher Dashboard', 'stb-core' ),
				'structure' => self::publisher_structure(),
			),
			array(
				'slug'      => 'stb_admin_dashboard',
				'title'     => __( 'Storbystand Admin Dashboard', 'stb-core' ),
				'structure' => self::admin_structure(),
			),
		);
	}

	protected static function publisher_structure(): array {
		return array(
			self::custom_section(
				__( 'Welcome back!', 'stb-core' ),
				array(
					self::text_widget(
						__( 'Review your next assignment, update availability, or reach out to the coordinating team using the quick links below.', 'stb-core' )
					),
					self::button_group_widget(
						array(
							array(
								'text' => __( 'View Calendar', 'stb-core' ),
								'url'  => '/calendar',
							),
							array(
								'text' => __( 'Submit Availability', 'stb-core' ),
								'url'  => '/availability',
							),
							array(
								'text' => __( 'Contact Team', 'stb-core' ),
								'url'  => '/contacts',
							),
						)
					),
				)
			),
			self::section(
				__( 'Upcoming Shifts', 'stb-core' ),
				'[stb_query_loop query="my_upcoming_shifts" layout="cards"]'
			),
			self::two_column_section(
				__( 'Availability Planner', 'stb-core' ),
				array(
					self::text_widget(
						__( 'Use the Availability form to set recurring preferences and let coordinators know when you are free.', 'stb-core' )
					),
					self::shortcode_widget( '[jet_fb_form form_id="' . self::get_form_id( 'availability_submit' ) . '"]' ),
				),
				__( 'Unavailability / Vacation', 'stb-core' ),
				array(
					self::text_widget(
						__( 'Log full-day unavailability or vacations so planners can reroute shifts proactively.', 'stb-core' )
					),
					self::shortcode_widget( '[jet_fb_form form_id="' . self::get_form_id( 'unavailability_full_day' ) . '"]' ),
				)
			),
			self::custom_section(
				__( 'Notification Preferences & PWA', 'stb-core' ),
				array(
					self::text_widget(
						__( 'Toggle email/push reminders from your profile. Installing the Storbystand app (PWA) ensures instant access and offline viewing of your roster.', 'stb-core' )
					),
					self::shortcode_widget( '[jet_fb_form form_id="' . self::get_form_id( 'notification_preferences' ) . '"]' ),
					self::html_widget(
						'<ul><li>' . esc_html__( 'Email reminders: always on for confirmed shifts.', 'stb-core' ) . '</li>' .
						'<li>' . esc_html__( 'Push notifications: enable from the browser prompt to receive 24h/1h reminders.', 'stb-core' ) . '</li>' .
						'<li>' . esc_html__( 'SMS: coming soon.', 'stb-core' ) . '</li></ul>'
					),
				)
			),
			self::section(
				__( 'Open Shifts', 'stb-core' ),
				'[stb_query_loop query="alerts_unassigned_shifts" layout="table"]'
			),
			self::custom_section(
				__( 'Contacts & Support', 'stb-core' ),
				array(
					self::text_widget(
						__( 'Need help? Use the Contacts directory page to find coordinators for each location or message the admin team directly.', 'stb-core' )
					),
					self::button_group_widget(
						array(
							array(
								'text' => __( 'Open Contacts Directory', 'stb-core' ),
								'url'  => '/contacts',
							),
						)
					),
				)
			),
		);
	}

	protected static function admin_structure(): array {
		return array(
			self::custom_section(
				__( 'Shift Control Center', 'stb-core' ),
				array(
					self::text_widget(
						__( 'Monitor today’s open slots, copy forward shifts, or trigger notifications using the quick links.', 'stb-core' )
					),
					self::button_group_widget(
						array(
							array(
								'text' => __( 'Create Shift', 'stb-core' ),
								'url'  => '/wp-admin/post-new.php?post_type=stb_shift_template',
							),
							array(
								'text' => __( 'Copy Previous Day', 'stb-core' ),
								'url'  => '#copy-day',
							),
							array(
								'text' => __( 'Send Broadcast', 'stb-core' ),
								'url'  => '#broadcast',
							),
						)
					),
				)
			),
			self::section(
				__( 'Roster Overview', 'stb-core' ),
				'[stb_query_loop query="shift_roster_manage" layout="roster"]'
			),
			self::two_column_section(
				__( 'Notification Queue', 'stb-core' ),
				array(
					self::shortcode_widget( '[stb_query_loop query="notification_queue_admin" layout="table"]' ),
				),
				__( 'Recent Audit Trail', 'stb-core' ),
				array(
					self::shortcode_widget( '[stb_query_loop query="audit_log_recent" layout="timeline"]' ),
				)
			),
			self::custom_section(
				__( 'Statistics & Snapshot Jobs', 'stb-core' ),
				array(
					self::text_widget(
						__( 'Snapshots capture per-user and per-location KPIs nightly. Use the CLI (`wp stb stats run --period=day`) to regenerate during QA.', 'stb-core' )
					),
					self::html_widget(
						'<p>' . esc_html__( 'Charts and JetEngine Listing widgets can be dropped in here once the Stats Snapshot listing is finalized.', 'stb-core' ) . '</p>'
					),
				)
			),
			self::custom_section(
				__( 'Copy-Day & Automation Notes', 'stb-core' ),
				array(
					self::text_widget(
						__( 'The copy-day JetFormBuilder flows live in the Automation tools page. Link buttons or popups here when those forms are published.', 'stb-core' )
					),
				)
			),
		);
	}

	protected static function section( string $heading, string $shortcode ): array {
		$section_id = self::uid();
		$column_id  = self::uid();

		return array(
			'id'       => $section_id,
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array(
				'_css_classes' => 'stb-dashboard-section',
			),
			'elements' => array(
				array(
					'id'       => $column_id,
					'elType'   => 'column',
					'isInner'  => false,
					'settings' => array(
						'_column_size' => 100,
						'_css_classes' => 'stb-dashboard-column',
					),
					'elements' => array(
						self::heading_widget( $heading ),
						self::shortcode_widget( $shortcode ),
					),
				),
			),
		);
	}

	protected static function heading_widget( string $text ): array {
		return array(
			'id'         => self::uid(),
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array(
				'title' => $text,
				'size'  => 'large',
			),
			'elements'   => array(),
			'isInner'    => false,
		);
	}

	protected static function shortcode_widget( string $shortcode ): array {
		return array(
			'id'         => self::uid(),
			'elType'     => 'widget',
			'widgetType' => 'shortcode',
			'settings'   => array(
				'shortcode' => $shortcode,
			),
			'elements'   => array(),
			'isInner'    => false,
		);
	}

	protected static function text_widget( string $text ): array {
		return array(
			'id'         => self::uid(),
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => array(
				'editor' => wp_kses_post( $text ),
			),
			'elements'   => array(),
			'isInner'    => false,
		);
	}

	protected static function button_group_widget( array $buttons ): array {
		$html = '<div class="stb-button-group">';

		foreach ( $buttons as $button ) {
			$text = esc_html( $button['text'] ?? __( 'Open', 'stb-core' ) );
			$url  = esc_url( $button['url'] ?? '#' );
			$html .= sprintf( '<a class="elementor-button elementor-button--stb" href="%s">%s</a>', $url, $text );
		}

		$html .= '</div>';

		return self::html_widget( $html );
	}

	protected static function html_widget( string $html ): array {
		return array(
			'id'         => self::uid(),
			'elType'     => 'widget',
			'widgetType' => 'html',
			'settings'   => array(
				'html' => $html,
			),
			'elements'   => array(),
			'isInner'    => false,
		);
	}

	protected static function custom_section( string $heading, array $widgets ): array {
		$section_id = self::uid();
		$column_id  = self::uid();

		return array(
			'id'       => $section_id,
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array(
				'_css_classes' => 'stb-dashboard-section',
			),
			'elements' => array(
				array(
					'id'       => $column_id,
					'elType'   => 'column',
					'isInner'  => false,
					'settings' => array(
						'_column_size' => 100,
						'_css_classes' => 'stb-dashboard-column',
					),
					'elements' => array_merge(
						array( self::heading_widget( $heading ) ),
						$widgets
					),
				),
			),
		);
	}

	protected static function two_column_section( string $left_heading, array $left_widgets, string $right_heading, array $right_widgets ): array {
		$section_id = self::uid();

		return array(
			'id'       => $section_id,
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array(
				'_css_classes' => 'stb-dashboard-section',
			),
			'elements' => array(
				self::column_with_widgets( $left_heading, $left_widgets ),
				self::column_with_widgets( $right_heading, $right_widgets ),
			),
		);
	}

	protected static function column_with_widgets( string $heading, array $widgets ): array {
		return array(
			'id'       => self::uid(),
			'elType'   => 'column',
			'isInner'  => false,
			'settings' => array(
				'_column_size' => 50,
				'_css_classes' => 'stb-dashboard-column',
			),
			'elements' => array_merge(
				array( self::heading_widget( $heading ) ),
				$widgets
			),
		);
	}

	protected static function uid(): string {
		return substr( wp_generate_uuid4(), 0, 8 );
	}
}

