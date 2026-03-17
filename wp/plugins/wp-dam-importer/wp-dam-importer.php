<?php
/**
 * Plugin Name:       Universal DAM Importer
 * Plugin URI:        https://marius.uk/plugins/dam-importer
 * Description:       Import assets from your DAMs to your WordPress library (3rd party Digital Asset Manangement platforms).
 * Version:           0.0.1
 * Requires at least: 6.4
 * Requires PHP:      8.4
 * Author:            Marius Cucuruz
 * License:           GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define Constants for pathing
define( 'MY_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Composer Autoloader
if ( file_exists( MY_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
    require_once MY_PLUGIN_PATH . 'vendor/autoload.php';
}

/** Activation Logic */
register_activation_hook( __FILE__, function() {
    // Check requirements or set up DB tables
    flush_rewrite_rules();
});

/** Initialize Plugin */
add_action( 'plugins_loaded', function() {
    // Example of calling a class-based hook
    if ( class_exists( 'MariusCucuruz\DAMImporter\Admin\Settings' ) ) {
        (new MyPlugin\Admin\Settings())->init();
    }
});
