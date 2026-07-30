<?php
/**
 * Plugin Name: WooCommerce Order Importer
 * Description: Importa ordini WooCommerce da un sito remoto tramite REST API nel sito corrente.
 * Version: 1.5.1
 * Author: Area051
 * Author URI: https://www.area051.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: woocommerce-order-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCOI_VERSION', '1.5.1');
define('WCOI_PLUGIN_FILE', __FILE__);
define('WCOI_PLUGIN_DIR', plugin_dir_path(__FILE__));

$wcoi_importer_class_file = WCOI_PLUGIN_DIR . 'includes/class-wcoi-importer.php';

if (!file_exists($wcoi_importer_class_file)) {
    add_action('admin_notices', function () use ($wcoi_importer_class_file) {
        echo '<div class="notice notice-error"><p>'
            . esc_html(sprintf('WooCommerce Order Importer non installato correttamente. File mancante: %s', $wcoi_importer_class_file))
            . '</p></div>';
    });

    return;
}

require_once $wcoi_importer_class_file;

register_activation_hook(__FILE__, array('WCOI_Importer', 'activate'));
register_deactivation_hook(__FILE__, array('WCOI_Importer', 'deactivate'));

function wcoi_bootstrap() {
    $plugin = new WCOI_Importer();
    $plugin->init();
}

wcoi_bootstrap();