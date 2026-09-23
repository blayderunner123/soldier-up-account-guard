<?php
/**
 * Plugin Name: Soldier-up Account Guard
 * Plugin URI: https://soldierupdesigns.com/
 * Description: Configurable account verification, OTP onboarding, login protection, role-aware redirects, and account administration for WordPress and WooCommerce.
 * Version: 1.0.1
 * Author: Soldier-up Designs
 * Author URI: https://soldierupdesigns.com/
 * Text Domain: soldier-up-account-guard
 * Requires at least: 6.1
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'SUAG_VERSION', '1.0.1' );
define( 'SUAG_FILE', __FILE__ );
define( 'SUAG_DIR', plugin_dir_path( __FILE__ ) );
define( 'SUAG_URL', plugin_dir_url( __FILE__ ) );

require_once SUAG_DIR . 'includes/class-suag-core.php';
require_once SUAG_DIR . 'includes/class-suag-admin.php';

register_activation_hook( __FILE__, array( 'SUAG_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SUAG_Core', 'deactivate' ) );

add_action( 'plugins_loaded', function() {
    SUAG_Core::instance();
    if ( is_admin() ) {
        SUAG_Admin::instance();
    }
} );
