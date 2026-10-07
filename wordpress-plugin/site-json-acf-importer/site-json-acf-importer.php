<?php
/**
 * Plugin Name: Site JSON ACF Importer
 * Description: Importa contenuti JSON (es. export Drupal) in WordPress con supporto ACF Pro.
 * Version: 1.5.21
 * Author: Custom
 * Author URI: https://area051.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: site-json-acf-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SJAI_VERSION', '1.5.21');
define('SJAI_PLUGIN_FILE', __FILE__);
define('SJAI_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once SJAI_PLUGIN_DIR . 'includes/class-sjai-importer.php';

function sjai_bootstrap() {
    $plugin = new SJAI_Importer();
    $plugin->init();
}

sjai_bootstrap();
