<?php
/**
 * Plugin Name: Storbystand PWA
 * Description: Adds the manifest, service worker, and offline shell required for the Storbystand dashboards.
 * Author: Storbystand Team
 * Version: 0.1.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STB_PWA_VERSION', '0.1.0' );
define( 'STB_PWA_PATH', plugin_dir_path( __FILE__ ) );
define( 'STB_PWA_URL', plugin_dir_url( __FILE__ ) );

require_once STB_PWA_PATH . 'includes/class-stb-pwa.php';

Stb_PWA::init();

register_activation_hook(
	__FILE__,
	array( 'Stb_PWA', 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( 'Stb_PWA', 'deactivate' )
);

