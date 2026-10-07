<?php
/**
 * Plugin Name: Area051 WP Shortcodes
 * Description: Raccolta di shortcode utili con builder interattivo.
 * Version: 1.7.1
 * Author: Area051
 * Author URI: https://www.area051.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: reti-riserve-shortcodes
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-rrs-shortcodes.php';

function reti_riserve_shortcodes_bootstrap() {
    $plugin = new RRS_Shortcodes();
    $plugin->init();
}
add_action('plugins_loaded', 'reti_riserve_shortcodes_bootstrap');
