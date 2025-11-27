<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_Core {

	/**
	 * Singleton instance.
	 *
	 * @var Stb_Core|null
	 */
	protected static $instance = null;

	/**
	 * Accessor.
	 *
	 * @return Stb_Core
	 */
	public static function instance(): Stb_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bootstraps hooks.
	 */
	protected function __construct() {
		require_once STB_CORE_PATH . 'includes/class-stb-post-types.php';
		require_once STB_CORE_PATH . 'includes/class-stb-taxonomies.php';
		require_once STB_CORE_PATH . 'includes/class-stb-cli.php';

		add_action(
			'init',
			static function () {
				Stb_Post_Types::register_post_types();
				Stb_Taxonomies::register_taxonomies();
			},
			5
		);

		add_action(
			'init',
			static function () {
				Stb_Post_Types::register_meta_fields();
			},
			20
		);

		add_action(
			'cli_init',
			static function () {
				Stb_CLI::register_commands();
			}
		);
	}
}

