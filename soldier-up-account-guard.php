<?php
/**
 * Plugin Name: Soldier-up Account Guard
 * Plugin URI: https://soldierupdesigns.com/
 * Description: Configurable account verification, OTP onboarding, login protection, role-aware redirects, and account administration for WordPress and WooCommerce.
 * Version: 1.0.2
 * Author: Soldier-up Designs
 * Author URI: https://soldierupdesigns.com/
 * Text Domain: soldier-up-account-guard
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright © 2026 Jonathan R. Adcox
 * Original author: Jonathan R. Adcox (Blayderunner123)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'SUAG_VERSION', '1.0.2' );
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
