<?php
/**
 * Plugin Name: Site JSON ACF Importer
 * Description: Importa contenuti JSON (es. export Drupal) in WordPress con supporto ACF Pro.
 * Version: 1.6.12
 * Author: area051.com
 * Author URI: https://area051.com
 * Update URI: https://github.com/studioblq/rete-riserve-export-import-drupal
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: site-json-acf-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/lib/class-area051-github-updater.php';
new Area051_GitHub_Updater_V1(__FILE__, 'site-json-acf-importer');

define('SJAI_VERSION', '1.6.12');
define('SJAI_PLUGIN_FILE', __FILE__);
define('SJAI_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once SJAI_PLUGIN_DIR . 'includes/class-sjai-multilang.php';
require_once SJAI_PLUGIN_DIR . 'includes/class-sjai-importer.php';

function sjai_bootstrap() {
    $plugin = new SJAI_Importer();
    $plugin->init();
}

sjai_bootstrap();
