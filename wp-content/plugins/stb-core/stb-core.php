<?php
/**
 * Plugin Name: Storbystand Core
 * Description: Core data structures, custom tables, and helpers for the Storbystand shift-management project.
 * Author: Storbystand Team
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STB_CORE_VERSION', '0.1.0' );
define( 'STB_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'STB_CORE_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'STB_SIMULATE_EMAILS' ) ) {
	define( 'STB_SIMULATE_EMAILS', true );
}

require_once STB_CORE_PATH . 'includes/class-stb-core.php';
require_once STB_CORE_PATH . 'includes/class-stb-jetengine.php';
require_once STB_CORE_PATH . 'includes/class-stb-jetengine-queries.php';
require_once STB_CORE_PATH . 'includes/class-stb-query-loop-shortcode.php';
require_once STB_CORE_PATH . 'includes/class-stb-elementor-templates.php';
require_once STB_CORE_PATH . 'includes/class-stb-stats.php';
require_once STB_CORE_PATH . 'includes/class-stb-notifications.php';
require_once STB_CORE_PATH . 'includes/class-stb-data-seeder.php';
require_once STB_CORE_PATH . 'includes/class-stb-jetformbuilder.php';

Stb_Core::instance();
Stb_JetEngine::init();
Stb_JetEngine_Queries::init();
Stb_Query_Loop_Shortcode::init();
Stb_Elementor_Templates::init();
Stb_Stats::init();
Stb_Notification_Service::init();
Stb_JetFormBuilder::init();

