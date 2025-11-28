<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_CLI {

	public static function register_commands(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'stb jetengine sync',
			function () {
				Stb_JetEngine::cli_sync();
				Stb_JetEngine_Queries::force_sync( true );
				Stb_Elementor_Templates::force_sync( true );
				\WP_CLI::success( 'JetEngine queries and Elementor templates synchronized.' );
			}
		);

		\WP_CLI::add_command(
			'stb seed mock',
			function () {
				Stb_Data_Seeder::seed();
			}
		);

		\WP_CLI::add_command(
			'stb notifications run',
			function ( $args, $assoc_args ) {
				$limit     = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : Stb_Notification_Service::DEFAULT_LIMIT;
				$processed = Stb_Notification_Service::process_queue( max( 1, $limit ) );

				\WP_CLI::success(
					sprintf(
						'Processed %d notification%s.',
						$processed,
						1 === $processed ? '' : 's'
					)
				);
			}
		);

		\WP_CLI::add_command(
			'stb notifications queue',
			function ( $args, $assoc_args ) {
				$event = $assoc_args['event'] ?? '';

				if ( ! $event ) {
					\WP_CLI::error( 'Please provide an --event=<slug> argument.' );
				}

				$context = array(
					'recipient_id'  => isset( $assoc_args['recipient'] ) ? absint( $assoc_args['recipient'] ) : null,
					'assignment_id' => isset( $assoc_args['assignment'] ) ? absint( $assoc_args['assignment'] ) : null,
					'shift_id'      => isset( $assoc_args['shift'] ) ? absint( $assoc_args['shift'] ) : null,
					'schedule_at'   => $assoc_args['schedule'] ?? null,
				);

				if ( empty( $context['recipient_id'] ) && empty( $context['assignment_id'] ) ) {
					\WP_CLI::error( 'Please provide either --recipient=<id> or --assignment=<id> so the service can resolve recipients.' );
				}

				Stb_Notification_Service::handle_event(
					sanitize_key( $event ),
					array_filter(
						$context,
						static function ( $value ) {
							return null !== $value && '' !== $value;
						}
					)
				);

				\WP_CLI::success( 'Notification event queued.' );
			}
		);

		\WP_CLI::add_command(
			'stb stats run',
			function ( $args, $assoc_args ) {
				$period = isset( $assoc_args['period'] ) ? sanitize_key( $assoc_args['period'] ) : 'day';
				$date   = isset( $assoc_args['date'] ) ? sanitize_text_field( $assoc_args['date'] ) : '';

				$ids = Stb_Stats::generate_for_period( $period, $date );

				\WP_CLI::success(
					sprintf(
						'Generated %d snapshot(s) for %s.',
						count( $ids ),
						$period
					)
				);
			}
		);

		\WP_CLI::add_command(
			'stb forms sync',
			function () {
				Stb_JetFormBuilder::force_sync( true );
				\WP_CLI::success( 'JetFormBuilder forms synchronized.' );
			}
		);
	}
}

