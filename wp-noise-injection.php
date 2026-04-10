<?php
/**
 * Plugin Name:  WP Noise Injection
 * Plugin URI:   https://github.com/yourusername/wp-noise-injection
 * Description:  Generates varied draft posts across configurable topic buckets to broaden a site's content fingerprint. Posts are always created as drafts — you review and publish manually.
 * Version:      1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:       Your Name
 * Author URI:   https://yoursite.com
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  wp-noise-injection
 */

defined( 'ABSPATH' ) || exit;

define( 'WNI_VERSION',    '1.1.0' );
define( 'WNI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WNI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WNI_OPTION_KEY', 'wni_settings' );

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

require_once WNI_PLUGIN_DIR . 'includes/class-wni-settings.php';
require_once WNI_PLUGIN_DIR . 'includes/class-wni-topics.php';
require_once WNI_PLUGIN_DIR . 'includes/class-wni-generator.php';
require_once WNI_PLUGIN_DIR . 'includes/class-wni-diversity.php';
require_once WNI_PLUGIN_DIR . 'includes/class-wni-widget.php';
require_once WNI_PLUGIN_DIR . 'includes/class-wni-admin.php';

add_action( 'plugins_loaded', array( 'WNI_Admin', 'init' ) );
add_action( 'plugins_loaded', array( 'WNI_Widget', 'register' ) );

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'wni_activate' );
register_deactivation_hook( __FILE__, 'wni_deactivate' );

function wni_activate() {
    WNI_Settings::install_defaults();
    WNI_Topics::install_defaults();
    wni_schedule_cron();
}

function wni_deactivate() {
    wp_clear_scheduled_hook( 'wni_scheduled_generate' );
}

// ---------------------------------------------------------------------------
// Cron
// ---------------------------------------------------------------------------

add_filter( 'cron_schedules', 'wni_add_cron_intervals' );

function wni_add_cron_intervals( $schedules ) {
    $schedules['wni_weekly'] = array(
        'interval' => 7 * DAY_IN_SECONDS,
        'display'  => __( 'Once Weekly', 'wp-noise-injection' ),
    );
    $schedules['wni_biweekly'] = array(
        'interval' => 14 * DAY_IN_SECONDS,
        'display'  => __( 'Every Two Weeks', 'wp-noise-injection' ),
    );
    return $schedules;
}

function wni_schedule_cron() {
    $settings = WNI_Settings::get();
    if ( ! empty( $settings['auto_generate'] ) ) {
        $frequency = isset( $settings['frequency'] ) ? $settings['frequency'] : 'wni_weekly';
        if ( ! wp_next_scheduled( 'wni_scheduled_generate' ) ) {
            wp_schedule_event( time(), $frequency, 'wni_scheduled_generate' );
        }
    }
}

add_action( 'wni_scheduled_generate', 'wni_run_scheduled_generation' );

function wni_run_scheduled_generation() {
    $settings = WNI_Settings::get();
    $count    = isset( $settings['batch_size'] ) ? (int) $settings['batch_size'] : 1;
    WNI_Generator::generate_batch( $count );
}
