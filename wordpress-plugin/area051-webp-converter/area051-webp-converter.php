<?php
/**
 * Plugin Name: Area051 WebP Converter
 * Description: Converte in WebP compresso le immagini JPEG e PNG della libreria media, con backup opzionale e redirect dai vecchi indirizzi.
 * Version: 1.0.0
 * Author: area051.com
 * Author URI: https://area051.com
 * Update URI: https://github.com/studioblq/rete-riserve-export-import-drupal
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: area051-webp-converter
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/lib/class-area051-github-updater.php';
new Area051_GitHub_Updater_V2(__FILE__, 'area051-webp-converter');

class Area051_WebP_Converter {
    const MENU_SLUG = 'area051-webp-converter';
    const PARENT_MENU_SLUG = 'reti-riserve-suite';
    const OPTION_SETTINGS = 'area051_webp_settings';
    const META_INFO = '_area051_webp';
    const META_SKIPPED = '_area051_webp_skipped';
    const NONCE = 'area051_webp';
    const BACKUP_DIR = 'area051-webp-backup';
    const STATE_PREFIX = 'area051_webp_state_';
    const BATCH_SECONDS = 8;
    const PER_PAGE = 50;

    protected static $source_mimes = array('image/jpeg', 'image/png');

    public function init() {
        add_action('admin_menu', array($this, 'register_admin_menu'), 20);
        add_action('admin_post_area051_webp_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_area051_webp_convert_one', array($this, 'handle_convert_one'));
        add_action('admin_post_area051_webp_restore_one', array($this, 'handle_restore_one'));
        add_action('wp_ajax_area051_webp_start', array($this, 'ajax_start'));
        add_action('wp_ajax_area051_webp_process', array($this, 'ajax_process'));
        // Old .jpg/.png URLs: when the web server passes a missing upload to WordPress, redirect to the WebP.
        add_action('init', array($this, 'maybe_redirect_old_upload'), 1);
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    public function get_settings() {
        $defaults = array(
            'quality' => 80,
            'backup' => 1,
            'keep_original' => 0,
            'replace_in_content' => 1,
            'skip_larger' => 1,
            'redirect' => 1,
        );
        $settings = get_option(self::OPTION_SETTINGS, array());
        $settings = is_array($settings) ? array_merge($defaults, $settings) : $defaults;
        $settings['quality'] = max(1, min(100, (int) $settings['quality']));
        return $settings;
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'area051-webp-converter'));
        }
        check_admin_referer(self::NONCE);

        $settings = array(
            'quality' => isset($_POST['quality']) ? max(1, min(100, absint($_POST['quality']))) : 80,
            'backup' => !empty($_POST['backup']) ? 1 : 0,
            'keep_original' => !empty($_POST['keep_original']) ? 1 : 0,
            'replace_in_content' => !empty($_POST['replace_in_content']) ? 1 : 0,
            'skip_larger' => !empty($_POST['skip_larger']) ? 1 : 0,
            'redirect' => !empty($_POST['redirect']) ? 1 : 0,
        );
        update_option(self::OPTION_SETTINGS, $settings, false);

        wp_safe_redirect(add_query_arg(array('page' => self::MENU_SLUG, 'a51w_notice' => 'saved'), admin_url('admin.php')));
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Menu and page                                                       */
    /* ------------------------------------------------------------------ */

    public function register_admin_menu() {
        if (!isset($GLOBALS['admin_page_hooks'][self::PARENT_MENU_SLUG])) {
            add_menu_page(
                __('Area051 WP', 'area051-webp-converter'),
                __('Area051 WP', 'area051-webp-converter'),
                'manage_options',
                self::PARENT_MENU_SLUG,
                array($this, 'render_admin_page'),
                'dashicons-admin-tools',
                58
            );
        }

        add_submenu_page(
            self::PARENT_MENU_SLUG,
            __('WebP Converter', 'area051-webp-converter'),
            __('WebP Converter', 'area051-webp-converter'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_admin_page')
        );
    }

    protected function webp_supported() {
        return function_exists('wp_image_editor_supports') && wp_image_editor_supports(array('mime_type' => 'image/webp'));
    }

    protected function get_stats() {
        global $wpdb;

        $counts = $wpdb->get_results(
            "SELECT post_mime_type, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp') GROUP BY post_mime_type",
            OBJECT_K
        );

        $stats = array(
            'jpeg' => isset($counts['image/jpeg']) ? (int) $counts['image/jpeg']->n : 0,
            'png' => isset($counts['image/png']) ? (int) $counts['image/png']->n : 0,
            'webp' => isset($counts['image/webp']) ? (int) $counts['image/webp']->n : 0,
            'converted' => 0,
            'skipped' => 0,
            'bytes_before' => 0,
            'bytes_after' => 0,
        );

        $metas = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_INFO));
        foreach ((array) $metas as $raw) {
            $info = maybe_unserialize($raw);
            if (!is_array($info)) {
                continue;
            }
            $stats['converted']++;
            $stats['bytes_before'] += isset($info['bytes_before']) ? (int) $info['bytes_before'] : 0;
            $stats['bytes_after'] += isset($info['bytes_after']) ? (int) $info['bytes_after'] : 0;
        }

        $stats['skipped'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_SKIPPED));

        return $stats;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $stats = $this->get_stats();
        $notice = isset($_GET['a51w_notice']) ? sanitize_key(wp_unslash($_GET['a51w_notice'])) : '';
        $message = isset($_GET['a51w_msg']) ? sanitize_text_field(wp_unslash($_GET['a51w_msg'])) : '';
        $filter = isset($_GET['a51w_filter']) ? sanitize_key(wp_unslash($_GET['a51w_filter'])) : 'pending';
        if (!in_array($filter, array('pending', 'converted', 'skipped', 'all'), true)) {
            $filter = 'pending';
        }
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WebP Converter', 'area051-webp-converter') . '</h1>';

        if ($notice === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Impostazioni salvate.', 'area051-webp-converter') . '</p></div>';
        } elseif ($notice === 'done' || $notice === 'error') {
            echo '<div class="notice notice-' . ($notice === 'done' ? 'success' : 'error') . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        if (!$this->webp_supported()) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Il server non supporta la creazione di immagini WebP (servono GD o Imagick con supporto WebP). Chiedi all\'hosting di attivarlo.', 'area051-webp-converter') . '</p></div>';
        }

        // Summary.
        $saved = max(0, $stats['bytes_before'] - $stats['bytes_after']);
        echo '<div style="max-width:1100px;border:1px solid #dcdcde;background:#fff;padding:12px 16px;border-radius:4px;margin:12px 0;">';
        echo '<p style="margin:0;"><strong>' . esc_html__('Da convertire:', 'area051-webp-converter') . '</strong> '
            . esc_html(sprintf(__('%1$d JPEG, %2$d PNG', 'area051-webp-converter'), $stats['jpeg'], $stats['png']))
            . ' &nbsp;|&nbsp; <strong>' . esc_html__('Convertite:', 'area051-webp-converter') . '</strong> ' . esc_html((string) $stats['converted'])
            . ' &nbsp;|&nbsp; <strong>' . esc_html__('Saltate:', 'area051-webp-converter') . '</strong> ' . esc_html((string) $stats['skipped'])
            . ' &nbsp;|&nbsp; <strong>' . esc_html__('Spazio risparmiato:', 'area051-webp-converter') . '</strong> ' . esc_html(size_format($saved, 1))
            . ($stats['bytes_before'] > 0 ? ' (' . esc_html(sprintf('-%d%%', round($saved * 100 / $stats['bytes_before']))) . ')' : '')
            . '</p>';
        echo '</div>';

        // Settings.
        echo '<h2>' . esc_html__('Impostazioni conversione', 'area051-webp-converter') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:1100px;border:1px solid #dcdcde;background:#fff;padding:12px 16px;border-radius:4px;">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="area051_webp_save_settings">';
        echo '<p><label><strong>' . esc_html__('Qualità WebP (1-100)', 'area051-webp-converter') . '</strong> <input type="number" name="quality" min="1" max="100" value="' . esc_attr((string) $settings['quality']) . '" style="width:80px;"></label> '
            . '<span style="color:#50575e;">' . esc_html__('80 è un buon compromesso; più basso = file più leggeri, qualità minore.', 'area051-webp-converter') . '</span></p>';
        $checkboxes = array(
            'backup' => sprintf(__('Fai un backup degli originali in wp-content/%s/ (stessa struttura di cartelle di uploads)', 'area051-webp-converter'), self::BACKUP_DIR),
            'keep_original' => __('Mantieni i file originali JPEG/PNG in uploads accanto ai WebP (occupa più spazio, ma i vecchi indirizzi continuano a funzionare senza redirect)', 'area051-webp-converter'),
            'replace_in_content' => __('Aggiorna gli indirizzi delle immagini dentro i contenuti (pagine, articoli, Divi)', 'area051-webp-converter'),
            'skip_larger' => __('Salta l\'immagine se il WebP risulta più pesante dell\'originale', 'area051-webp-converter'),
            'redirect' => __('Reindirizza (301) i vecchi indirizzi .jpg/.png al WebP quando l\'originale non esiste più', 'area051-webp-converter'),
        );
        foreach ($checkboxes as $key => $label) {
            echo '<p style="margin:6px 0;"><label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked(1, (int) $settings[$key], false) . '> ' . esc_html($label) . '</label></p>';
        }
        if (empty($settings['backup']) && empty($settings['keep_original'])) {
            echo '<p style="color:#b32d2e;margin:8px 0 0;"><strong>' . esc_html__('Attenzione: senza backup e senza mantenere gli originali, la conversione non si può annullare.', 'area051-webp-converter') . '</strong></p>';
        }
        submit_button(__('Salva impostazioni', 'area051-webp-converter'), 'secondary', 'submit', false);
        echo '</form>';

        // Bulk.
        echo '<h2 style="margin-top:24px;">' . esc_html__('Conversione', 'area051-webp-converter') . '</h2>';
        echo '<p style="max-width:1100px;">' . esc_html__('Converte l\'immagine originale e tutte le sue miniature, aggiorna la libreria media e, se attivo, gli indirizzi nei contenuti. Le immagini vengono elaborate a blocchi: tieni aperta la pagina fino alla fine. Salva le impostazioni prima di avviare.', 'area051-webp-converter') . '</p>';
        wp_nonce_field('area051_webp_ajax', 'area051_webp_ajax_nonce');
        echo '<p>';
        echo '<button type="button" class="button button-primary" id="a51w-bulk-all"' . ($this->webp_supported() ? '' : ' disabled') . '>' . esc_html__('Converti tutte le immagini JPEG/PNG', 'area051-webp-converter') . '</button> ';
        echo '<button type="button" class="button" id="a51w-bulk-selected"' . ($this->webp_supported() ? '' : ' disabled') . '>' . esc_html__('Converti selezionate', 'area051-webp-converter') . '</button> ';
        echo '<button type="button" class="button" id="a51w-stop" style="display:none;">' . esc_html__('Ferma', 'area051-webp-converter') . '</button>';
        echo '</p>';
        echo '<div id="a51w-progress" style="display:none;max-width:900px;border:1px solid #ccd0d4;padding:12px;background:#fff;margin:0 0 16px;">';
        echo '<p id="a51w-progress-text" style="margin:0 0 8px;font-weight:600;"></p>';
        echo '<div style="height:20px;background:#f0f0f1;border-radius:4px;overflow:hidden;"><div id="a51w-progress-bar" style="height:20px;width:0;background:#1d7f4e;color:#fff;font-size:12px;line-height:20px;text-align:center;"></div></div>';
        echo '<p id="a51w-progress-meta" style="margin:8px 0 0;color:#50575e;"></p>';
        echo '<ul id="a51w-progress-errors" style="margin:8px 0 0 18px;list-style:disc;color:#b32d2e;"></ul>';
        echo '</div>';

        $this->render_list($filter, $paged, $stats);

        $this->render_script();
        echo '</div>';
    }

    protected function render_list($filter, $paged, $stats) {
        $tabs = array(
            'pending' => sprintf(__('Da convertire (%d)', 'area051-webp-converter'), $stats['jpeg'] + $stats['png']),
            'converted' => sprintf(__('Convertite (%d)', 'area051-webp-converter'), $stats['converted']),
            'skipped' => sprintf(__('Saltate (%d)', 'area051-webp-converter'), $stats['skipped']),
            'all' => __('Tutte le immagini', 'area051-webp-converter'),
        );
        echo '<h2 style="margin-top:24px;">' . esc_html__('Immagini rilevate in libreria', 'area051-webp-converter') . '</h2>';
        echo '<ul class="subsubsub" style="float:none;margin:0 0 8px;">';
        $links = array();
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(array('page' => self::MENU_SLUG, 'a51w_filter' => $key), admin_url('admin.php'));
            $links[] = '<li><a href="' . esc_url($url) . '"' . ($filter === $key ? ' class="current"' : '') . '>' . esc_html($label) . '</a></li>';
        }
        echo implode(' | ', $links);
        echo '</ul>';

        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => self::PER_PAGE,
            'paged' => $paged,
            'orderby' => 'ID',
            'order' => 'DESC',
        );
        if ($filter === 'pending') {
            $args['post_mime_type'] = self::$source_mimes;
        } elseif ($filter === 'converted') {
            $args['meta_query'] = array(array('key' => self::META_INFO, 'compare' => 'EXISTS'));
        } elseif ($filter === 'skipped') {
            $args['meta_query'] = array(array('key' => self::META_SKIPPED, 'compare' => 'EXISTS'));
        } else {
            $args['post_mime_type'] = array('image/jpeg', 'image/png', 'image/webp', 'image/gif');
        }
        $query = new WP_Query($args);

        if (!$query->have_posts()) {
            echo '<p><em>' . esc_html__('Nessuna immagine in questo elenco.', 'area051-webp-converter') . '</em></p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1100px;">';
        echo '<thead><tr><th style="width:30px;"><input type="checkbox" id="a51w-check-all"></th><th style="width:70px;">' . esc_html__('Anteprima', 'area051-webp-converter') . '</th><th>' . esc_html__('File', 'area051-webp-converter') . '</th><th>' . esc_html__('Tipo', 'area051-webp-converter') . '</th><th>' . esc_html__('Peso', 'area051-webp-converter') . '</th><th>' . esc_html__('Stato', 'area051-webp-converter') . '</th><th>' . esc_html__('Azioni', 'area051-webp-converter') . '</th></tr></thead><tbody>';

        foreach ($query->posts as $post) {
            $id = (int) $post->ID;
            $file = get_attached_file($id);
            $relative = (string) get_post_meta($id, '_wp_attached_file', true);
            $mime = (string) $post->post_mime_type;
            $info = get_post_meta($id, self::META_INFO, true);
            $skipped = (string) get_post_meta($id, self::META_SKIPPED, true);
            $size = ($file && file_exists($file)) ? filesize($file) : 0;
            $convertible = in_array($mime, self::$source_mimes, true);

            echo '<tr>';
            echo '<td>' . ($convertible ? '<input type="checkbox" class="a51w-row" value="' . esc_attr((string) $id) . '">' : '') . '</td>';
            echo '<td>' . wp_get_attachment_image($id, array(60, 60), false, array('style' => 'width:60px;height:60px;object-fit:cover;')) . '</td>';
            echo '<td><a href="' . esc_url(get_edit_post_link($id)) . '">' . esc_html($relative !== '' ? $relative : get_the_title($id)) . '</a><br><span style="color:#50575e;">ID ' . esc_html((string) $id) . '</span></td>';
            echo '<td>' . esc_html($mime) . '</td>';
            echo '<td>' . esc_html($size ? size_format($size, 1) : '-') . '</td>';
            echo '<td>';
            if (is_array($info)) {
                $before = isset($info['bytes_before']) ? (int) $info['bytes_before'] : 0;
                $after = isset($info['bytes_after']) ? (int) $info['bytes_after'] : 0;
                echo esc_html(sprintf(__('Convertita: %1$s → %2$s', 'area051-webp-converter'), size_format($before, 1), size_format($after, 1)));
                if ($before > 0) {
                    echo ' <strong>' . esc_html(sprintf('-%d%%', round(($before - $after) * 100 / $before))) . '</strong>';
                }
                echo '<br><span style="color:#50575e;">' . esc_html(sprintf(__('Qualità %1$d, %2$s', 'area051-webp-converter'), isset($info['quality']) ? (int) $info['quality'] : 0, !empty($info['backup']) ? __('backup presente', 'area051-webp-converter') : (!empty($info['kept_original']) ? __('originale mantenuto', 'area051-webp-converter') : __('senza backup', 'area051-webp-converter')))) . '</span>';
            } elseif ($skipped !== '') {
                echo esc_html(sprintf(__('Saltata: %s', 'area051-webp-converter'), $skipped));
            } elseif ($convertible) {
                echo esc_html__('Da convertire', 'area051-webp-converter');
            } else {
                echo '-';
            }
            echo '</td>';
            echo '<td>';
            if ($convertible && $this->webp_supported()) {
                $url = wp_nonce_url(add_query_arg(array('action' => 'area051_webp_convert_one', 'id' => $id), admin_url('admin-post.php')), self::NONCE . '_one_' . $id);
                echo '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Converti', 'area051-webp-converter') . '</a>';
            }
            if (is_array($info) && (!empty($info['backup']) || !empty($info['kept_original']))) {
                $url = wp_nonce_url(add_query_arg(array('action' => 'area051_webp_restore_one', 'id' => $id), admin_url('admin-post.php')), self::NONCE . '_restore_' . $id);
                echo '<a class="button button-small" href="' . esc_url($url) . '" onclick="return confirm(\'' . esc_js(__('Ripristinare l\'immagine originale al posto del WebP?', 'area051-webp-converter')) . '\');">' . esc_html__('Ripristina originale', 'area051-webp-converter') . '</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        $total_pages = (int) $query->max_num_pages;
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages" style="float:none;margin:10px 0;">';
            echo paginate_links(array(
                'base' => add_query_arg(array('page' => self::MENU_SLUG, 'a51w_filter' => $filter, 'paged' => '%#%'), admin_url('admin.php')),
                'format' => '',
                'current' => $paged,
                'total' => $total_pages,
            ));
            echo '</div></div>';
        }
    }

    protected function render_script() {
        echo '<script>var a51wAjax = ' . wp_json_encode(admin_url('admin-ajax.php')) . ';</script>';
        echo <<<'JS'
<script>
(function(){
    var allBtn = document.getElementById("a51w-bulk-all");
    var selBtn = document.getElementById("a51w-bulk-selected");
    var stopBtn = document.getElementById("a51w-stop");
    var wrap = document.getElementById("a51w-progress");
    var txt = document.getElementById("a51w-progress-text");
    var meta = document.getElementById("a51w-progress-meta");
    var bar = document.getElementById("a51w-progress-bar");
    var errors = document.getElementById("a51w-progress-errors");
    var nonce = document.getElementById("area051_webp_ajax_nonce");
    var checkAll = document.getElementById("a51w-check-all");
    if (checkAll) {
        checkAll.addEventListener("change", function(){
            var rows = document.querySelectorAll(".a51w-row");
            for (var i = 0; i < rows.length; i++) { rows[i].checked = checkAll.checked; }
        });
    }
    if (!allBtn || !nonce) { return; }
    var stopped = false;

    function fmt(bytes){
        if (!bytes) { return "0 KB"; }
        if (bytes > 1048576) { return (bytes / 1048576).toFixed(1) + " MB"; }
        return Math.round(bytes / 1024) + " KB";
    }
    function show(d){
        var pct = d.total > 0 ? Math.floor(d.processed * 100 / d.total) : 100;
        bar.style.width = pct + "%";
        bar.textContent = pct + "%";
        txt.textContent = "Elaborate " + d.processed + " di " + d.total;
        var saved = (d.bytes_before || 0) - (d.bytes_after || 0);
        meta.textContent = "Convertite: " + d.converted + " | Saltate: " + d.skipped + " | Errori: " + d.failed + " | Risparmiati: " + fmt(saved);
        if (d.new_errors && d.new_errors.length) {
            for (var i = 0; i < d.new_errors.length; i++) {
                var li = document.createElement("li");
                li.textContent = d.new_errors[i];
                errors.appendChild(li);
            }
        }
    }
    function finish(text){
        txt.textContent = text;
        allBtn.disabled = false;
        selBtn.disabled = false;
        stopBtn.style.display = "none";
    }
    function post(data, onOk, onFail){
        var xhr = new XMLHttpRequest();
        xhr.open("POST", a51wAjax);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.timeout = 120000;
        xhr.onload = function(){
            var resp = null;
            try { resp = JSON.parse(xhr.responseText); } catch (e) { onFail(xhr.status || 0, true); return; }
            if (!resp || !resp.success) { finish("Errore: " + (resp && resp.data ? (resp.data.message || resp.data) : "risposta non valida")); return; }
            onOk(resp.data);
        };
        xhr.onerror = function(){ onFail(0, false); };
        xhr.ontimeout = function(){ onFail(0, false); };
        var body = [];
        for (var k in data) { body.push(encodeURIComponent(k) + "=" + encodeURIComponent(data[k])); }
        xhr.send(body.join("&"));
    }
    function processBatch(attempt){
        if (stopped) { finish("Conversione fermata: riavviala per continuare con le immagini rimaste."); return; }
        post({action: "area051_webp_process", nonce: nonce.value}, function(d){
            show(d);
            if (d.done) {
                finish("Conversione completata: " + d.converted + " convertite, " + d.skipped + " saltate, " + d.failed + " errori. Ricarica la pagina per aggiornare l'elenco.");
                return;
            }
            setTimeout(function(){ processBatch(0); }, 1500);
        }, function(status){
            // Shared hosting may answer 503/508 when busy: wait longer each time.
            var overloaded = [429, 502, 503, 504, 508].indexOf(status) !== -1;
            var max = overloaded ? 12 : 5;
            if (attempt < max) {
                var wait = overloaded ? Math.min(30000 * (attempt + 1), 120000) : 5000 * (attempt + 1);
                txt.textContent = "Server occupato" + (status ? " (HTTP " + status + ")" : "") + ", nuovo tentativo " + (attempt + 1) + " di " + max + " tra " + (wait / 1000) + "s...";
                setTimeout(function(){ processBatch(attempt + 1); }, wait);
            } else {
                finish("Il server non risponde" + (status ? " (HTTP " + status + ")" : "") + ". Ricarica la pagina e riavvia: le immagini già convertite non vengono rifatte.");
            }
        });
    }
    function start(ids){
        if (!confirm(ids.length ? "Convertire in WebP le " + ids.length + " immagini selezionate?" : "Convertire in WebP tutte le immagini JPEG/PNG della libreria con le impostazioni salvate?")) { return; }
        stopped = false;
        allBtn.disabled = true;
        selBtn.disabled = true;
        stopBtn.style.display = "";
        wrap.style.display = "block";
        errors.innerHTML = "";
        txt.textContent = "Preparazione...";
        post({action: "area051_webp_start", nonce: nonce.value, ids: ids.join(",")}, function(d){
            show(d);
            if (d.done) { finish("Nessuna immagine da convertire."); return; }
            processBatch(0);
        }, function(status){ finish("Avvio non riuscito" + (status ? " (HTTP " + status + ")" : "") + ". Riprova."); });
    }
    allBtn.addEventListener("click", function(){ start([]); });
    selBtn.addEventListener("click", function(){
        var ids = [];
        var rows = document.querySelectorAll(".a51w-row:checked");
        for (var i = 0; i < rows.length; i++) { ids.push(rows[i].value); }
        if (!ids.length) { alert("Nessuna immagine selezionata."); return; }
        start(ids);
    });
    stopBtn.addEventListener("click", function(){ stopped = true; stopBtn.disabled = true; txt.textContent = "Arresto dopo il blocco in corso..."; });
})();
</script>
JS;
    }

    /* ------------------------------------------------------------------ */
    /* Bulk conversion (AJAX)                                              */
    /* ------------------------------------------------------------------ */

    protected function state_key() {
        return self::STATE_PREFIX . get_current_user_id();
    }

    protected function progress_payload($state, $new_errors = array()) {
        return array(
            'total' => (int) $state['total'],
            'processed' => (int) $state['cursor'],
            'converted' => (int) $state['converted'],
            'skipped' => (int) $state['skipped'],
            'failed' => (int) $state['failed'],
            'bytes_before' => (int) $state['bytes_before'],
            'bytes_after' => (int) $state['bytes_after'],
            'new_errors' => $new_errors,
            'done' => (int) $state['cursor'] >= (int) $state['total'],
        );
    }

    public function ajax_start() {
        if (!current_user_can('manage_options') || !check_ajax_referer('area051_webp_ajax', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Non autorizzato.'));
        }
        if (!$this->webp_supported()) {
            wp_send_json_error(array('message' => 'Il server non supporta WebP.'));
        }

        $ids = array();
        $raw = isset($_POST['ids']) ? sanitize_text_field(wp_unslash($_POST['ids'])) : '';
        if ($raw !== '') {
            $ids = array_values(array_filter(array_map('absint', explode(',', $raw))));
        } else {
            // Bulk: every JPEG/PNG not already skipped (a selection can retry skipped ones).
            $ids = get_posts(array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => self::$source_mimes,
                'posts_per_page' => -1,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'suppress_filters' => true,
                'meta_query' => array(array('key' => self::META_SKIPPED, 'compare' => 'NOT EXISTS')),
            ));
            $ids = array_map('intval', (array) $ids);
        }

        $state = array(
            'ids' => $ids,
            'total' => count($ids),
            'cursor' => 0,
            'converted' => 0,
            'skipped' => 0,
            'failed' => 0,
            'bytes_before' => 0,
            'bytes_after' => 0,
            'settings' => $this->get_settings(),
        );
        set_transient($this->state_key(), $state, DAY_IN_SECONDS);

        wp_send_json_success($this->progress_payload($state));
    }

    public function ajax_process() {
        if (!current_user_can('manage_options') || !check_ajax_referer('area051_webp_ajax', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Non autorizzato.'));
        }

        $state = get_transient($this->state_key());
        if (!is_array($state)) {
            wp_send_json_error(array('message' => 'Conversione non trovata: riavviala.'));
        }

        @set_time_limit(120);
        $started = microtime(true);
        $new_errors = array();

        while ($state['cursor'] < $state['total'] && (microtime(true) - $started) < self::BATCH_SECONDS) {
            $id = (int) $state['ids'][$state['cursor']];
            // Advance first: an image that crashes PHP is not retried forever.
            $state['cursor']++;
            set_transient($this->state_key(), $state, DAY_IN_SECONDS);

            $result = $this->convert_attachment($id, $state['settings']);
            if ($result['status'] === 'converted') {
                $state['converted']++;
                $state['bytes_before'] += $result['bytes_before'];
                $state['bytes_after'] += $result['bytes_after'];
            } elseif ($result['status'] === 'skipped') {
                $state['skipped']++;
            } else {
                $state['failed']++;
                $new_errors[] = sprintf('ID %d: %s', $id, $result['message']);
            }
        }

        set_transient($this->state_key(), $state, DAY_IN_SECONDS);
        $payload = $this->progress_payload($state, $new_errors);
        if ($payload['done']) {
            delete_transient($this->state_key());
        }
        wp_send_json_success($payload);
    }

    /* ------------------------------------------------------------------ */
    /* Single actions                                                      */
    /* ------------------------------------------------------------------ */

    protected function redirect_with_message($ok, $message) {
        wp_safe_redirect(add_query_arg(array(
            'page' => self::MENU_SLUG,
            'a51w_notice' => $ok ? 'done' : 'error',
            'a51w_msg' => rawurlencode($message),
            'a51w_filter' => isset($_GET['a51w_filter']) ? sanitize_key(wp_unslash($_GET['a51w_filter'])) : 'pending',
        ), admin_url('admin.php')));
        exit;
    }

    public function handle_convert_one() {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'area051-webp-converter'));
        }
        check_admin_referer(self::NONCE . '_one_' . $id);

        $result = $this->convert_attachment($id, $this->get_settings());
        if ($result['status'] === 'converted') {
            $this->redirect_with_message(true, sprintf('ID %d convertita: %s → %s.', $id, size_format($result['bytes_before'], 1), size_format($result['bytes_after'], 1)));
        }
        $this->redirect_with_message($result['status'] === 'skipped', sprintf('ID %d: %s', $id, $result['message']));
    }

    public function handle_restore_one() {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'area051-webp-converter'));
        }
        check_admin_referer(self::NONCE . '_restore_' . $id);

        $result = $this->restore_attachment($id);
        $this->redirect_with_message($result['ok'], sprintf('ID %d: %s', $id, $result['message']));
    }

    /* ------------------------------------------------------------------ */
    /* Conversion                                                          */
    /* ------------------------------------------------------------------ */

    protected function uploads() {
        $uploads = wp_get_upload_dir();
        return array(
            'basedir' => wp_normalize_path(untrailingslashit($uploads['basedir'])),
            'baseurl' => untrailingslashit($uploads['baseurl']),
        );
    }

    protected function backup_root() {
        return wp_normalize_path(WP_CONTENT_DIR) . '/' . self::BACKUP_DIR;
    }

    /**
     * WebP name for an upload: foto.jpg => foto.webp; foto-jpg.webp if foto.webp already exists.
     */
    protected function webp_relative_path($relative, $basedir, $taken) {
        $info = pathinfo($relative);
        $dir = (isset($info['dirname']) && $info['dirname'] !== '.') ? $info['dirname'] . '/' : '';
        $candidate = $dir . $info['filename'] . '.webp';
        if (!isset($taken[$candidate]) && !file_exists($basedir . '/' . $candidate)) {
            return $candidate;
        }
        return $dir . $info['filename'] . '-' . strtolower($info['extension']) . '.webp';
    }

    /**
     * Relative paths of the original, the -scaled copy and every thumbnail of an attachment.
     *
     * @return array<string,string> relative path => role (main, original_image, size name)
     */
    protected function collect_attachment_files($id, $meta) {
        $main = (string) get_post_meta($id, '_wp_attached_file', true);
        $files = array();
        if ($main === '') {
            return $files;
        }
        $main = ltrim(wp_normalize_path($main), '/');
        $dir = dirname($main);
        $dir = ($dir === '.' || $dir === '') ? '' : $dir . '/';

        $files[$main] = 'main';
        if (is_array($meta)) {
            if (!empty($meta['original_image']) && is_string($meta['original_image'])) {
                $files[$dir . $meta['original_image']] = 'original_image';
            }
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size_name => $size) {
                    if (is_array($size) && !empty($size['file']) && !isset($files[$dir . $size['file']])) {
                        $files[$dir . $size['file']] = (string) $size_name;
                    }
                }
            }
        }
        return $files;
    }

    /**
     * @return array{status:string,message:string,bytes_before:int,bytes_after:int}
     */
    public function convert_attachment($id, $settings) {
        $out = array('status' => 'error', 'message' => '', 'bytes_before' => 0, 'bytes_after' => 0);
        $id = (int) $id;
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            $out['message'] = 'allegato non trovato';
            return $out;
        }
        if (!in_array($post->post_mime_type, self::$source_mimes, true)) {
            $out['status'] = 'skipped';
            $out['message'] = 'non è un JPEG o PNG';
            return $out;
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }

        $up = $this->uploads();
        $meta = wp_get_attachment_metadata($id);
        $files = $this->collect_attachment_files($id, $meta);
        if (empty($files)) {
            $out['message'] = 'percorso del file mancante';
            return $out;
        }

        $quality = isset($settings['quality']) ? (int) $settings['quality'] : 80;
        $map = array();          // old relative => new relative
        $created = array();      // absolute paths written by this run
        $taken = array();

        foreach ($files as $relative => $role) {
            $old_abs = $up['basedir'] . '/' . $relative;
            if (!file_exists($old_abs)) {
                if ($role === 'main') {
                    $out['message'] = 'file originale non trovato su disco: ' . $relative;
                    return $out;
                }
                continue; // A missing thumbnail is not a reason to stop.
            }

            $new_rel = $this->webp_relative_path($relative, $up['basedir'], $taken);
            $new_abs = $up['basedir'] . '/' . $new_rel;

            $editor = wp_get_image_editor($old_abs);
            if (is_wp_error($editor)) {
                $this->delete_files($created);
                $out['message'] = 'impossibile aprire ' . $relative . ': ' . $editor->get_error_message();
                return $out;
            }
            $editor->set_quality($quality);
            $saved = $editor->save($new_abs, 'image/webp');
            unset($editor);
            if (is_wp_error($saved) || !file_exists($new_abs)) {
                $this->delete_files($created);
                $out['message'] = 'conversione non riuscita per ' . $relative . (is_wp_error($saved) ? ': ' . $saved->get_error_message() : '');
                return $out;
            }

            $created[] = $new_abs;
            $taken[$new_rel] = 1;
            $map[$relative] = $new_rel;
            $out['bytes_before'] += (int) filesize($old_abs);
            $out['bytes_after'] += (int) filesize($new_abs);

            if ($role === 'main' && !empty($settings['skip_larger']) && filesize($new_abs) >= filesize($old_abs)) {
                $this->delete_files($created);
                update_post_meta($id, self::META_SKIPPED, 'il WebP pesa più dell\'originale');
                $out['status'] = 'skipped';
                $out['message'] = 'il WebP pesa più dell\'originale';
                $out['bytes_before'] = 0;
                $out['bytes_after'] = 0;
                return $out;
            }
        }

        // Backup before anything is changed or deleted.
        $backup_done = false;
        if (!empty($settings['backup'])) {
            foreach (array_keys($map) as $relative) {
                $target = $this->backup_root() . '/' . $relative;
                if (!wp_mkdir_p(dirname($target)) || !@copy($up['basedir'] . '/' . $relative, $target)) {
                    $this->delete_files($created);
                    $out['message'] = 'backup non riuscito per ' . $relative . ' (permessi di wp-content?)';
                    return $out;
                }
            }
            $backup_done = true;
        }

        // Library: file path, metadata and mime type now point to the WebP files.
        $old_meta = $meta;
        $old_attached = (string) get_post_meta($id, '_wp_attached_file', true);
        $main_rel = array_search('main', $files, true);
        $new_meta = is_array($meta) ? $meta : array();
        $new_meta['file'] = $map[$main_rel];
        $new_meta['filesize'] = (int) filesize($up['basedir'] . '/' . $map[$main_rel]);
        if (!empty($new_meta['original_image'])) {
            $orig_rel = array_search('original_image', $files, true);
            if ($orig_rel !== false && isset($map[$orig_rel])) {
                $new_meta['original_image'] = wp_basename($map[$orig_rel]);
            } else {
                unset($new_meta['original_image']);
            }
        }
        if (!empty($new_meta['sizes']) && is_array($new_meta['sizes'])) {
            $dir = dirname($main_rel);
            $dir = ($dir === '.' || $dir === '') ? '' : $dir . '/';
            foreach ($new_meta['sizes'] as $size_name => $size) {
                if (!is_array($size) || empty($size['file'])) {
                    continue;
                }
                $size_rel = $dir . $size['file'];
                if (!isset($map[$size_rel])) {
                    unset($new_meta['sizes'][$size_name]); // Thumbnail missing on disk.
                    continue;
                }
                $new_meta['sizes'][$size_name]['file'] = wp_basename($map[$size_rel]);
                $new_meta['sizes'][$size_name]['mime-type'] = 'image/webp';
                $new_meta['sizes'][$size_name]['filesize'] = (int) filesize($up['basedir'] . '/' . $map[$size_rel]);
            }
        }
        unset($new_meta['sources']);

        update_attached_file($id, $up['basedir'] . '/' . $map[$main_rel]);
        wp_update_attachment_metadata($id, $new_meta);
        global $wpdb;
        $wpdb->update($wpdb->posts, array('post_mime_type' => 'image/webp'), array('ID' => $id));
        clean_post_cache($id);

        update_post_meta($id, self::META_INFO, array(
            'converted_at' => time(),
            'quality' => $quality,
            'old_mime' => $post->post_mime_type,
            'old_attached' => $old_attached,
            'old_meta' => $old_meta,
            'files' => $map,
            'backup' => $backup_done ? 1 : 0,
            'kept_original' => !empty($settings['keep_original']) ? 1 : 0,
            'bytes_before' => $out['bytes_before'],
            'bytes_after' => $out['bytes_after'],
        ));
        delete_post_meta($id, self::META_SKIPPED);

        if (!empty($settings['replace_in_content'])) {
            $this->replace_urls_in_content($map, $up['baseurl']);
        }

        if (empty($settings['keep_original'])) {
            foreach (array_keys($map) as $relative) {
                wp_delete_file($up['basedir'] . '/' . $relative);
            }
        }

        $out['status'] = 'converted';
        return $out;
    }

    protected function delete_files($absolute_paths) {
        foreach ((array) $absolute_paths as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                wp_delete_file($path);
            }
        }
    }

    /**
     * Replaces upload paths in post_content. The path from /wp-content/uploads/ on matches both
     * absolute and relative URLs; the JSON-escaped form covers builders that store attributes as JSON.
     *
     * @param array<string,string> $map old relative => new relative
     */
    protected function replace_urls_in_content($map, $baseurl) {
        global $wpdb;

        $base_path = (string) wp_parse_url($baseurl, PHP_URL_PATH);
        $base_path = untrailingslashit($base_path);

        foreach ($map as $old_rel => $new_rel) {
            $pairs = array(
                $base_path . '/' . $old_rel => $base_path . '/' . $new_rel,
            );
            $pairs[str_replace('/', '\\/', $base_path . '/' . $old_rel)] = str_replace('/', '\\/', $base_path . '/' . $new_rel);

            foreach ($pairs as $old => $new) {
                if ($old === '' || $old === $new) {
                    continue;
                }
                $like = '%' . $wpdb->esc_like($old) . '%';
                $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_content LIKE %s", $like));
                if (empty($ids)) {
                    continue;
                }
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_type <> 'revision' AND post_content LIKE %s",
                    $old,
                    $new,
                    $like
                ));
                foreach ($ids as $post_id) {
                    clean_post_cache((int) $post_id);
                }
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Restore                                                             */
    /* ------------------------------------------------------------------ */

    public function restore_attachment($id) {
        $id = (int) $id;
        $info = get_post_meta($id, self::META_INFO, true);
        if (!is_array($info) || empty($info['files']) || !is_array($info['files'])) {
            return array('ok' => false, 'message' => 'nessuna conversione da annullare');
        }

        $up = $this->uploads();

        // Originals come back from uploads (kept) or from the backup folder.
        foreach ($info['files'] as $old_rel => $new_rel) {
            $target = $up['basedir'] . '/' . $old_rel;
            if (file_exists($target)) {
                continue;
            }
            $source = $this->backup_root() . '/' . $old_rel;
            if (!file_exists($source)) {
                return array('ok' => false, 'message' => 'originale non trovato né in uploads né nel backup: ' . $old_rel);
            }
            if (!wp_mkdir_p(dirname($target)) || !@copy($source, $target)) {
                return array('ok' => false, 'message' => 'impossibile ripristinare ' . $old_rel);
            }
        }

        $main_old = isset($info['old_attached']) ? (string) $info['old_attached'] : '';
        if ($main_old !== '') {
            update_attached_file($id, $up['basedir'] . '/' . ltrim($main_old, '/'));
        }
        if (isset($info['old_meta']) && is_array($info['old_meta'])) {
            wp_update_attachment_metadata($id, $info['old_meta']);
        }
        global $wpdb;
        $wpdb->update($wpdb->posts, array('post_mime_type' => isset($info['old_mime']) ? (string) $info['old_mime'] : 'image/jpeg'), array('ID' => $id));
        clean_post_cache($id);

        $this->replace_urls_in_content(array_flip($info['files']), $up['baseurl']);

        foreach ($info['files'] as $new_rel) {
            wp_delete_file($up['basedir'] . '/' . $new_rel);
        }

        delete_post_meta($id, self::META_INFO);
        return array('ok' => true, 'message' => 'originale ripristinato');
    }

    /* ------------------------------------------------------------------ */
    /* Redirect of old URLs                                                */
    /* ------------------------------------------------------------------ */

    public function maybe_redirect_old_upload() {
        if (is_admin() || wp_doing_ajax() || empty($_SERVER['REQUEST_URI'])) {
            return;
        }

        $path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        if (!preg_match('/\.(jpe?g|png)$/i', $path, $ext_match)) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['redirect'])) {
            return;
        }

        $up = $this->uploads();
        $base_path = untrailingslashit((string) wp_parse_url($up['baseurl'], PHP_URL_PATH));
        $path = rawurldecode($path);
        if ($base_path === '' || strpos($path, $base_path . '/') !== 0) {
            return;
        }

        $relative = ltrim(substr($path, strlen($base_path)), '/');
        if ($relative === '' || strpos($relative, '..') !== false || file_exists($up['basedir'] . '/' . $relative)) {
            return;
        }

        $info = pathinfo($relative);
        $dir = (isset($info['dirname']) && $info['dirname'] !== '.') ? $info['dirname'] . '/' : '';
        // "foto-jpg.webp" exists only when this very file collided with another one: check it first.
        $candidates = array(
            $dir . $info['filename'] . '-' . strtolower($ext_match[1]) . '.webp',
            $dir . $info['filename'] . '.webp',
        );
        foreach ($candidates as $candidate) {
            if (file_exists($up['basedir'] . '/' . $candidate)) {
                $target = $up['baseurl'] . '/' . str_replace('%2F', '/', rawurlencode($candidate));
                wp_redirect($target, 301, 'Area051 WebP Converter');
                exit;
            }
        }
    }
}

add_action('plugins_loaded', function () {
    $converter = new Area051_WebP_Converter();
    $converter->init();
});
