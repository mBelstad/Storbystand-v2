<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_Query_Loop_Shortcode {

	/**
	 * Bootstrap shortcode + assets.
	 */
	public static function init(): void {
		add_shortcode( 'stb_query_loop', array( __CLASS__, 'handle_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Load dashboard styles when shortcode is present.
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style(
			'stb-dashboard',
			trailingslashit( STB_CORE_URL ) . 'assets/css/dashboard.css',
			array(),
			STB_CORE_VERSION
		);
	}

	/**
	 * Render shortcode output.
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public static function handle_shortcode( $atts ): string {
		if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
			return '<div class="stb-query-loop__notice">' . esc_html__( 'JetEngine Query Builder is not available.', 'stb-core' ) . '</div>';
		}

		$atts = shortcode_atts(
			array(
				'query'  => '',
				'layout' => 'cards',
				'title'  => '',
			),
			$atts,
			'stb_query_loop'
		);

		if ( empty( $atts['query'] ) ) {
			return '<div class="stb-query-loop__notice">' . esc_html__( 'Query attribute missing.', 'stb-core' ) . '</div>';
		}

		$manager = \Jet_Engine\Query_Builder\Manager::instance();
		$query   = $manager->get_query_by_id( $atts['query'] );

		if ( ! $query ) {
			return '<div class="stb-query-loop__notice">' . esc_html__( 'Unknown query.', 'stb-core' ) . '</div>';
		}

		$items = $query->get_items();

		if ( empty( $items ) ) {
			return '<div class="stb-query-loop__notice">' . esc_html__( 'No records yet.', 'stb-core' ) . '</div>';
		}

		switch ( $atts['layout'] ) {
			case 'roster':
				return self::render_roster_table( $items );
			case 'table':
				return self::render_generic_table( $items );
			case 'timeline':
				return self::render_timeline( $items );
			case 'cards':
			default:
				return self::render_cards( $items );
		}
	}

	protected static function render_cards( array $items ): string {
		ob_start();
		?>
		<div class="stb-query-loop stb-query-loop--cards">
			<?php foreach ( $items as $item ) : ?>
				<?php
				$date        = ! empty( $item->shift_date ) ? self::format_date( $item->shift_date ) : '';
				$status      = ! empty( $item->status ) ? sanitize_text_field( $item->status ) : '';
				$location_id = isset( $item->location_id ) ? intval( $item->location_id ) : 0;
				$open_slots  = isset( $item->open_slots ) ? intval( $item->open_slots ) : null;
				?>
				<article class="stb-card">
					<header class="stb-card__header">
						<span class="stb-card__date"><?php echo esc_html( $date ); ?></span>
						<?php if ( $status ) : ?>
							<span class="stb-badge"><?php echo esc_html( ucfirst( $status ) ); ?></span>
						<?php endif; ?>
					</header>
					<div class="stb-card__body">
						<p>
							<strong><?php esc_html_e( 'Slot:', 'stb-core' ); ?></strong>
							<?php echo esc_html( $item->slot_id ?? __( 'TBD', 'stb-core' ) ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'Location:', 'stb-core' ); ?></strong>
							<?php echo esc_html( $location_id ? sprintf( '#%d', $location_id ) : __( 'Unassigned', 'stb-core' ) ); ?>
						</p>
						<?php if ( null !== $open_slots ) : ?>
							<p class="stb-card__metric">
								<strong><?php esc_html_e( 'Open Slots', 'stb-core' ); ?></strong>
								<span><?php echo esc_html( max( $open_slots, 0 ) ); ?></span>
							</p>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	protected static function render_roster_table( array $items ): string {
		ob_start();
		?>
		<div class="stb-query-loop stb-query-loop--table">
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Slot', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Location', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Capacity', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Assigned', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Open', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Status', 'stb-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<tr>
							<td><?php echo esc_html( self::format_date( $item->shift_date ?? '' ) ); ?></td>
							<td><?php echo esc_html( $item->slot_id ?? '-' ); ?></td>
							<td><?php echo esc_html( isset( $item->location_id ) ? sprintf( '#%d', intval( $item->location_id ) ) : '-' ); ?></td>
							<td><?php echo esc_html( intval( $item->capacity ?? 0 ) ); ?></td>
							<td><?php echo esc_html( intval( $item->active_assignments ?? 0 ) ); ?></td>
							<td><?php echo esc_html( intval( $item->open_slots ?? 0 ) ); ?></td>
							<td><?php echo esc_html( ucfirst( $item->status ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	protected static function render_generic_table( array $items ): string {
		ob_start();
		?>
		<div class="stb-query-loop stb-query-loop--table">
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Created', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Recipient', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Channel', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Event', 'stb-core' ); ?></th>
						<th><?php esc_html_e( 'Status', 'stb-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<tr>
							<td><?php echo esc_html( self::format_datetime( $item->created_at ?? '' ) ); ?></td>
							<td><?php echo esc_html( intval( $item->recipient_id ?? 0 ) ); ?></td>
							<td><?php echo esc_html( ucfirst( $item->channel ?? '' ) ); ?></td>
							<td><?php echo esc_html( $item->event_type ?? '' ); ?></td>
							<td><?php echo esc_html( ucfirst( $item->status ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	protected static function render_timeline( array $items ): string {
		ob_start();
		?>
		<div class="stb-query-loop stb-query-loop--timeline">
			<ul>
				<?php foreach ( $items as $item ) : ?>
					<li>
						<div class="stb-timeline__timestamp"><?php echo esc_html( self::format_datetime( $item->recorded_at ?? '' ) ); ?></div>
						<div class="stb-timeline__body">
							<strong><?php echo esc_html( $item->action ?? '' ); ?></strong>
							<span>
								<?php echo esc_html( sprintf( '%s #%s', strtoupper( $item->entity_type ?? '' ), intval( $item->entity_id ?? 0 ) ) ); ?>
							</span>
							<?php if ( ! empty( $item->metadata_json ) ) : ?>
								<pre><?php echo esc_html( $item->metadata_json ); ?></pre>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	protected static function format_date( string $date ): string {
		if ( empty( $date ) ) {
			return '';
		}

		$timestamp = strtotime( $date );

		return $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : $date;
	}

	protected static function format_datetime( string $date ): string {
		if ( empty( $date ) ) {
			return '';
		}

		$timestamp = strtotime( $date );
		return $timestamp ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : $date;
	}
}

