<?php
/**
 * Plugin Name: Area051 Orphan Media Cleaner
 * Description: Scansiona ed elimina i media orfani (non collegati ad alcun contenuto) in sicurezza.
 * Version: 1.2.1
 * Author: Area051
 * Author URI: https://area051.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: area051-orphan-media-cleaner
 */

if (!defined('ABSPATH')) {
    exit;
}

class Area051_Orphan_Media_Cleaner {
    const MENU_SLUG = 'area051-orphan-media-cleaner';
    const PARENT_MENU_SLUG = 'reti-riserve-suite';
    const TRANSIENT_SCAN = 'area051_orphan_media_scan_result';
    const OPTION_SCAN_RESULT = 'area051_orphan_media_scan_result_persistent';
    const OPTION_UPLOAD_AUDIT_RESULT = 'area051_upload_audit_result_persistent';
    const UPLOAD_AUDIT_RULE_VERSION = 2;
    const TRANSIENT_SCAN_STATE = 'area051_orphan_media_scan_state';
    const TRANSIENT_DELETE_STATE = 'area051_orphan_media_delete_state';
    const TRANSIENT_UPLOAD_AUDIT_STATE = 'area051_upload_audit_state';
    const TRANSIENT_UPLOAD_AUDIT_DELETE_STATE = 'area051_upload_audit_delete_state';
    const TRANSIENT_SCAN_LOCK = 'area051_orphan_media_scan_lock';
    const TRANSIENT_DELETE_LOCK = 'area051_orphan_media_delete_lock';
    const TRANSIENT_UPLOAD_AUDIT_LOCK = 'area051_upload_audit_lock';
    const TRANSIENT_UPLOAD_AUDIT_DELETE_LOCK = 'area051_upload_audit_delete_lock';
    const CRON_SCAN_HOOK = 'area051_orphan_media_scan_worker';
    const CRON_DELETE_HOOK = 'area051_orphan_media_delete_worker';
    const CRON_UPLOAD_AUDIT_HOOK = 'area051_upload_audit_worker';
    const CRON_UPLOAD_AUDIT_DELETE_HOOK = 'area051_upload_audit_delete_worker';
    const NONCE_SCAN = 'area051_orphan_media_scan';
    const NONCE_DELETE = 'area051_orphan_media_delete';
    const NONCE_EXCLUDE = 'area051_orphan_media_exclude';
    const NONCE_UPLOAD_AUDIT = 'area051_upload_audit';
    const NONCE_UPLOAD_AUDIT_DELETE = 'area051_upload_audit_delete';

    public function init() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_post_area051_delete_orphan_media', array($this, 'handle_delete'));
        add_action('admin_post_area051_exclude_orphan_media', array($this, 'handle_exclude'));
        add_action('admin_post_area051_scan_upload_audit', array($this, 'handle_upload_audit'));
        add_action('wp_ajax_area051_scan_orphan_media_start', array($this, 'ajax_scan_start'));
        add_action('wp_ajax_area051_scan_orphan_media_process', array($this, 'ajax_scan_process'));
        add_action('wp_ajax_area051_stop_orphan_media_process', array($this, 'ajax_stop_process'));
        add_action('wp_ajax_area051_delete_orphan_media_start', array($this, 'ajax_delete_start'));
        add_action('wp_ajax_area051_delete_orphan_media_process', array($this, 'ajax_delete_process'));
        add_action('wp_ajax_area051_upload_audit_start', array($this, 'ajax_upload_audit_start'));
        add_action('wp_ajax_area051_upload_audit_process', array($this, 'ajax_upload_audit_process'));
        add_action('wp_ajax_area051_upload_audit_delete_start', array($this, 'ajax_upload_audit_delete_start'));
        add_action('wp_ajax_area051_upload_audit_delete_process', array($this, 'ajax_upload_audit_delete_process'));
        add_action(self::CRON_SCAN_HOOK, array($this, 'cron_scan_worker'), 10, 1);
        add_action(self::CRON_DELETE_HOOK, array($this, 'cron_delete_worker'), 10, 1);
        add_action(self::CRON_UPLOAD_AUDIT_HOOK, array($this, 'cron_upload_audit_worker'), 10, 1);
        add_action(self::CRON_UPLOAD_AUDIT_DELETE_HOOK, array($this, 'cron_upload_audit_delete_worker'), 10, 1);
    }

    public function register_admin_menu() {
        if (!isset($GLOBALS['admin_page_hooks'][self::PARENT_MENU_SLUG])) {
            add_menu_page(
                __('Reti Riserve', 'area051-orphan-media-cleaner'),
                __('Reti Riserve', 'area051-orphan-media-cleaner'),
                'manage_options',
                self::PARENT_MENU_SLUG,
                array($this, 'render_area051_hub_page'),
                'dashicons-admin-tools',
                58
            );
        }

        add_submenu_page(
            self::PARENT_MENU_SLUG,
            __('Orphan Media Cleaner', 'area051-orphan-media-cleaner'),
            __('Orphan Media Cleaner', 'area051-orphan-media-cleaner'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function render_area051_hub_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Reti Riserve', 'area051-orphan-media-cleaner') . '</h1>';
        echo '<p>' . esc_html__('Suite strumenti utili per il passaggio da Drupal a WordPress.', 'area051-orphan-media-cleaner') . '</p>';
        echo '<ul style="list-style:disc;padding-left:20px;max-width:900px;">';
        echo '<li><strong>' . esc_html__('Site JSON Importer', 'area051-orphan-media-cleaner') . '</strong>: '
            . esc_html__('importa contenuti da endpoint Drupal JSON verso WordPress con mapping campi, immagini, tassonomie e parent linking.', 'area051-orphan-media-cleaner')
            . '</li>';
        echo '<li><strong>' . esc_html__('Orphan Media Cleaner', 'area051-orphan-media-cleaner') . '</strong>: '
            . esc_html__('aiuta a ripulire i media orfani dopo migrazioni/reimport, liberando spazio su hosting.', 'area051-orphan-media-cleaner')
            . '</li>';
        echo '</ul>';
        echo '</div>';
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $scan_result = $this->get_scan_result();
        $upload_audit_result = $this->get_upload_audit_result();

        $scan_state = get_transient($this->get_scan_state_key(get_current_user_id()));
        $scan_state_payload = is_array($scan_state) ? $this->build_scan_progress_payload($scan_state) : null;
        $upload_audit_state = get_transient($this->get_upload_audit_state_key(get_current_user_id()));
        $upload_audit_state_payload = is_array($upload_audit_state) ? $this->build_upload_audit_progress_payload($upload_audit_state) : null;

        $notice = isset($_GET['a51_notice']) ? sanitize_key(wp_unslash($_GET['a51_notice'])) : '';
        $deleted_count = isset($_GET['deleted']) ? absint($_GET['deleted']) : 0;
        $excluded_count = isset($_GET['excluded']) ? absint($_GET['excluded']) : 0;
        $search_term = isset($_GET['a51_orphan_search']) ? sanitize_text_field(wp_unslash($_GET['a51_orphan_search'])) : '';
        $upload_search_term = isset($_GET['a51_upload_search']) ? sanitize_text_field(wp_unslash($_GET['a51_upload_search'])) : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Area051 Orphan Media Cleaner', 'area051-orphan-media-cleaner') . '</h1>';

        if ($notice === 'scan_done') {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(sprintf(__('Scansione completata. Allegati controllati: %d. Orfani trovati: %d.', 'area051-orphan-media-cleaner'), (int) $scan_result['scanned'], count($scan_result['orphans'])))
                . '</p></div>';
        } elseif ($notice === 'delete_done') {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(sprintf(__('Eliminazione completata. Media eliminati: %d.', 'area051-orphan-media-cleaner'), $deleted_count))
                . '</p></div>';
        } elseif ($notice === 'exclude_done') {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(sprintf(__('Elementi rimossi dalla lista orfani: %d. I file non sono stati eliminati.', 'area051-orphan-media-cleaner'), $excluded_count))
                . '</p></div>';
        } elseif ($notice === 'exclude_none') {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html__('Nessuna immagine selezionata da rimuovere dalla lista orfani.', 'area051-orphan-media-cleaner')
                . '</p></div>';
        } elseif ($notice === 'upload_audit_done') {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Controllo uploads completato.', 'area051-orphan-media-cleaner')
                . '</p></div>';
        } elseif ($notice === 'upload_audit_error') {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('Controllo uploads non riuscito.', 'area051-orphan-media-cleaner')
                . '</p></div>';
        } elseif ($notice === 'upload_audit_delete_done') {
            $deleted_upload_files = isset($_GET['deleted_files']) ? absint($_GET['deleted_files']) : 0;
            $failed_upload_files = isset($_GET['failed_files']) ? absint($_GET['failed_files']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(sprintf(__('Eliminazione file uploads completata. Eliminati: %d. Non eliminati: %d.', 'area051-orphan-media-cleaner'), $deleted_upload_files, $failed_upload_files))
                . '</p></div>';
        }

        echo '<p>' . esc_html__('Definizione usata: allegato non collegato a un post parent, non usato come immagine in evidenza, non referenziato in meta/options principali e non presente nel contenuto dei post tramite URL.', 'area051-orphan-media-cleaner') . '</p>';

        $base_query_args = array('page' => self::MENU_SLUG);
        if ($search_term !== '') {
            $base_query_args['a51_orphan_search'] = $search_term;
        }

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:16px 0;max-width:1100px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '">';
        echo '<div style="min-width:280px;flex:1;max-width:420px;">';
        echo '<label for="area051-orphan-search" style="display:block;margin:0 0 6px;font-weight:600;">' . esc_html__('Cerca tra gli orfani', 'area051-orphan-media-cleaner') . '</label>';
        echo '<input type="search" id="area051-orphan-search" name="a51_orphan_search" value="' . esc_attr($search_term) . '" placeholder="ID, titolo, URL o file" style="width:100%;">';
        echo '</div>';
        echo '<div style="display:flex;gap:8px;align-items:center;">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Cerca', 'area051-orphan-media-cleaner') . '</button>';
        if ($search_term !== '') {
            echo '<a class="button" href="' . esc_url(add_query_arg($base_query_args, admin_url('admin.php'))) . '">' . esc_html__('Reset', 'area051-orphan-media-cleaner') . '</a>';
        }
        echo '</div>';
        echo '</form>';

        wp_nonce_field(self::NONCE_SCAN, 'area051_scan_nonce_field');
        echo '<p style="margin:16px 0;">';
        echo '<button type="button" class="button button-primary" id="area051-start-scan-btn">' . esc_html__('Analizza media orfani (background)', 'area051-orphan-media-cleaner') . '</button>';
        echo ' <button type="button" class="button" id="area051-stop-process-btn" style="margin-left:8px;">' . esc_html__('Ferma processo in corso', 'area051-orphan-media-cleaner') . '</button>';
        echo '</p>';

        echo '<div id="area051-scan-progress" style="display:none;max-width:900px;border:1px solid #ccd0d4;padding:12px;background:#fff;">';
        echo '<p id="area051-scan-progress-text" style="margin:0 0 8px;font-weight:600;"></p>';
        echo '<div style="height:20px;background:#f0f0f1;border-radius:4px;overflow:hidden;">';
        echo '<div id="area051-scan-progress-bar" style="height:20px;width:0;background:#2271b1;color:#fff;font-size:12px;line-height:20px;text-align:center;"></div>';
        echo '</div>';
        echo '<p id="area051-scan-progress-meta" style="margin:8px 0 0;color:#50575e;"></p>';
        echo '</div>';

        echo '<script>';
        echo '(function(){';
        echo 'var btn=document.getElementById("area051-start-scan-btn");';
        echo 'var stopBtn=document.getElementById("area051-stop-process-btn");';
        echo 'var wrap=document.getElementById("area051-scan-progress");';
        echo 'var txt=document.getElementById("area051-scan-progress-text");';
        echo 'var meta=document.getElementById("area051-scan-progress-meta");';
        echo 'var bar=document.getElementById("area051-scan-progress-bar");';
        echo 'var nonceField=document.getElementById("area051_scan_nonce_field");';
        echo 'if(!btn||!stopBtn||!wrap||!txt||!meta||!bar||!nonceField){return;}';
        echo 'var ajaxUrl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';';
        echo 'var pageUrl=' . wp_json_encode(add_query_arg(array('page' => self::MENU_SLUG, 'a51_notice' => 'scan_done'), admin_url('admin.php'))) . ';';
        echo 'var resumeState=' . wp_json_encode($scan_state_payload) . ';';
        echo 'var stopRequested=false;';
        echo 'var lastProgressAt=Date.now();';
        echo 'var lastProgressKey="";';
        echo 'var watchdogTimer=null;';
        echo 'function refreshProgressHeartbeat(data){';
        echo 'var key=[data&&data.phase?data.phase:"index",data&&data.html_processed?data.html_processed:0,data&&data.scan_processed?data.scan_processed:0,data&&data.orphans?data.orphans:0].join("|");';
        echo 'if(key!==lastProgressKey){lastProgressKey=key;lastProgressAt=Date.now();}';
        echo '}';
        echo 'function startWatchdog(){';
        echo 'if(watchdogTimer){return;}';
        echo 'watchdogTimer=setInterval(function(){';
        echo 'if(stopRequested){clearInterval(watchdogTimer);watchdogTimer=null;return;}';
        echo 'if((Date.now()-lastProgressAt)>25000){window.location.reload();}';
        echo '},5000);';
        echo '}';
        echo 'function stopWatchdog(){if(watchdogTimer){clearInterval(watchdogTimer);watchdogTimer=null;}}';
        echo 'function setProgress(data){';
        echo 'var phase=(data&&data.phase)?data.phase:"index";';
        echo 'var htmlProcessed=(data&&data.html_processed)?parseInt(data.html_processed,10):0;';
        echo 'var htmlTotal=(data&&data.html_total)?parseInt(data.html_total,10):0;';
        echo 'var htmlUniqueFiles=(data&&data.html_unique_files)?parseInt(data.html_unique_files,10):0;';
        echo 'var scanProcessed=(data&&data.scan_processed)?parseInt(data.scan_processed,10):0;';
        echo 'var scanTotal=(data&&data.scan_total)?parseInt(data.scan_total,10):0;';
        echo 'var orphans=(data&&data.orphans)?parseInt(data.orphans,10):0;';
        echo 'var overallTotal=(htmlTotal+scanTotal);';
        echo 'var overallProcessed=(htmlProcessed+scanProcessed);';
        echo 'var pct=overallTotal>0?Math.floor((overallProcessed/overallTotal)*100):0;';
        echo 'if(pct<0){pct=0;}if(pct>100){pct=100;}';
        echo 'bar.style.width=pct+"%";bar.textContent=pct+"%";';
        echo 'if(phase==="index"){txt.textContent="Fase 1/2: indicizzazione HTML in corso...";}else{txt.textContent="Fase 2/2: controllo allegati in corso...";}';
        echo 'meta.textContent="HTML: "+htmlProcessed+" / "+htmlTotal+" | Filename unici: "+htmlUniqueFiles+" | Allegati: "+scanProcessed+" / "+scanTotal+" | Orfani: "+orphans;';
        echo 'refreshProgressHeartbeat(data||{});';
        echo '}';
        echo 'function processBatch(){';
        echo 'if(stopRequested){return;}';
        echo 'var xhr=new XMLHttpRequest();';
        echo 'xhr.open("POST",ajaxUrl);';
        echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo 'xhr.timeout=60000;';
        echo 'xhr.onload=function(){';
        echo 'try{var resp=JSON.parse(xhr.responseText);}catch(e){txt.textContent="Errore risposta scan batch";btn.disabled=false;return;}';
        echo 'if(!resp||!resp.success||!resp.data){txt.textContent="Errore durante la scansione";btn.disabled=false;return;}';
        echo 'setProgress(resp.data||{});';
        echo 'if(resp.data.done){stopWatchdog();window.location.href=pageUrl;return;}';
        echo 'if(!stopRequested){setTimeout(processBatch,75);}';
        echo '};';
        echo 'xhr.onerror=function(){if(stopRequested){return;}txt.textContent="Errore rete durante la scansione";btn.disabled=false;};';
        echo 'xhr.ontimeout=function(){if(stopRequested){return;}txt.textContent="Timeout batch, riprovo...";setTimeout(processBatch,1000);};';
        echo 'xhr.send("action=area051_scan_orphan_media_process&nonce="+encodeURIComponent(nonceField.value));';
        echo '}';
        echo 'btn.addEventListener("click",function(){';
        echo 'btn.disabled=true;wrap.style.display="block";txt.textContent="Inizializzazione scansione...";meta.textContent="";bar.style.width="0%";bar.textContent="0%";';
        echo 'var xhr=new XMLHttpRequest();';
        echo 'xhr.open("POST",ajaxUrl);';
        echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo 'xhr.timeout=30000;';
        echo 'xhr.onload=function(){';
        echo 'try{var resp=JSON.parse(xhr.responseText);}catch(e){txt.textContent="Errore avvio scansione";btn.disabled=false;return;}';
        echo 'if(!resp||!resp.success||!resp.data){txt.textContent="Errore avvio scansione";btn.disabled=false;return;}';
        echo 'setProgress(resp.data||{});';
        echo 'processBatch();';
        echo '};';
        echo 'xhr.onerror=function(){txt.textContent="Errore rete avvio scansione";btn.disabled=false;};';
        echo 'xhr.ontimeout=function(){txt.textContent="Timeout avvio scansione";btn.disabled=false;};';
        echo 'xhr.send("action=area051_scan_orphan_media_start&nonce="+encodeURIComponent(nonceField.value));';
        echo '});';
        echo 'stopBtn.addEventListener("click",function(){';
        echo 'if(!confirm("Confermi di fermare il processo in corso?")){return;}';
        echo 'stopRequested=true;';
        echo 'stopWatchdog();';
        echo 'stopBtn.disabled=true;';
        echo 'var xhr=new XMLHttpRequest();';
        echo 'xhr.open("POST",ajaxUrl);';
        echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo 'xhr.timeout=30000;';
        echo 'xhr.onload=function(){';
        echo 'btn.disabled=false;';
        echo 'try{var resp=JSON.parse(xhr.responseText);}catch(e){txt.textContent="Processo fermato (risposta non valida)";meta.textContent="";return;}';
        echo 'if(resp&&resp.success&&resp.data&&resp.data.message){txt.textContent=resp.data.message;}else{txt.textContent="Processo fermato.";}';
        echo 'meta.textContent="";';
        echo '};';
        echo 'xhr.onerror=function(){btn.disabled=false;txt.textContent="Errore rete durante stop processo";};';
        echo 'xhr.ontimeout=function(){btn.disabled=false;txt.textContent="Timeout stop processo";};';
        echo 'xhr.send("action=area051_stop_orphan_media_process&nonce="+encodeURIComponent(nonceField.value));';
        echo '});';
        echo 'if(resumeState&&resumeState.done!==true){';
        echo 'btn.disabled=true;stopRequested=false;stopBtn.disabled=false;wrap.style.display="block";setProgress(resumeState);startWatchdog();processBatch();';
        echo '}';
        echo '})();';
        echo '</script>';

        $orphans = isset($scan_result['orphans']) && is_array($scan_result['orphans']) ? $scan_result['orphans'] : array();
        $filtered_orphans = $search_term !== '' ? $this->filter_orphan_ids($orphans, $search_term) : $orphans;
        if (!empty($orphans)) {
            wp_nonce_field(self::NONCE_DELETE, 'area051_delete_nonce_field');
            echo '<p style="margin:0 0 16px;">';
            echo '<button type="button" class="button button-secondary" id="area051-start-delete-btn">' . esc_html__('Elimina tutti gli orfani trovati (background)', 'area051-orphan-media-cleaner') . '</button>';
            echo '</p>';
            echo '<div id="area051-delete-progress" style="display:none;max-width:900px;border:1px solid #ccd0d4;padding:12px;background:#fff;margin:0 0 16px;">';
            echo '<p id="area051-delete-progress-text" style="margin:0 0 8px;font-weight:600;"></p>';
            echo '<div style="height:20px;background:#f0f0f1;border-radius:4px;overflow:hidden;">';
            echo '<div id="area051-delete-progress-bar" style="height:20px;width:0;background:#d63638;color:#fff;font-size:12px;line-height:20px;text-align:center;"></div>';
            echo '</div>';
            echo '<p id="area051-delete-progress-meta" style="margin:8px 0 0;color:#50575e;"></p>';
            echo '</div>';

            echo '<script>';
            echo '(function(){';
            echo 'var btn=document.getElementById("area051-start-delete-btn");';
            echo 'var wrap=document.getElementById("area051-delete-progress");';
            echo 'var txt=document.getElementById("area051-delete-progress-text");';
            echo 'var meta=document.getElementById("area051-delete-progress-meta");';
            echo 'var bar=document.getElementById("area051-delete-progress-bar");';
            echo 'var nonceField=document.getElementById("area051_delete_nonce_field");';
            echo 'if(!btn||!wrap||!txt||!meta||!bar||!nonceField){return;}';
            echo 'var ajaxUrl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';';
            echo 'var doneBase=' . wp_json_encode(add_query_arg(array('page' => self::MENU_SLUG, 'a51_notice' => 'delete_done'), admin_url('admin.php'))) . ';';
            echo 'function setProgress(processed,total,deleted){';
            echo 'var pct=total>0?Math.floor((processed/total)*100):0;';
            echo 'if(pct<0){pct=0;}if(pct>100){pct=100;}';
            echo 'bar.style.width=pct+"%";bar.textContent=pct+"%";';
            echo 'txt.textContent="Eliminazione media orfani in corso...";';
            echo 'meta.textContent="Processati: "+processed+" / "+total+" | Eliminati: "+deleted;';
            echo '}';
            echo 'function processDeleteBatch(){';
            echo 'var xhr=new XMLHttpRequest();';
            echo 'xhr.open("POST",ajaxUrl);';
            echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
            echo 'xhr.timeout=60000;';
            echo 'xhr.onload=function(){';
            echo 'try{var resp=JSON.parse(xhr.responseText);}catch(e){txt.textContent="Errore risposta delete batch";btn.disabled=false;return;}';
            echo 'if(!resp||!resp.success||!resp.data){txt.textContent="Errore durante eliminazione";btn.disabled=false;return;}';
            echo 'setProgress(resp.data.processed||0,resp.data.total||0,resp.data.deleted||0);';
            echo 'if(resp.data.done){window.location.href=doneBase+"&deleted="+(resp.data.deleted||0);return;}';
            echo 'setTimeout(processDeleteBatch,75);';
            echo '};';
            echo 'xhr.onerror=function(){txt.textContent="Errore rete durante eliminazione";btn.disabled=false;};';
            echo 'xhr.ontimeout=function(){txt.textContent="Timeout batch delete, riprovo...";setTimeout(processDeleteBatch,1000);};';
            echo 'xhr.send("action=area051_delete_orphan_media_process&nonce="+encodeURIComponent(nonceField.value));';
            echo '}';
            echo 'btn.addEventListener("click",function(){';
            echo 'if(!confirm("Confermi eliminazione definitiva dei media orfani trovati?")){return;}';
            echo 'btn.disabled=true;wrap.style.display="block";txt.textContent="Inizializzazione eliminazione...";meta.textContent="";bar.style.width="0%";bar.textContent="0%";';
            echo 'var xhr=new XMLHttpRequest();';
            echo 'xhr.open("POST",ajaxUrl);';
            echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
            echo 'xhr.timeout=30000;';
            echo 'xhr.onload=function(){';
            echo 'try{var resp=JSON.parse(xhr.responseText);}catch(e){txt.textContent="Errore avvio eliminazione";btn.disabled=false;return;}';
            echo 'if(!resp||!resp.success||!resp.data){txt.textContent="Errore avvio eliminazione";btn.disabled=false;return;}';
            echo 'setProgress(0,resp.data.total||0,0);';
            echo 'processDeleteBatch();';
            echo '};';
            echo 'xhr.onerror=function(){txt.textContent="Errore rete avvio eliminazione";btn.disabled=false;};';
            echo 'xhr.ontimeout=function(){txt.textContent="Timeout avvio eliminazione";btn.disabled=false;};';
            echo 'xhr.send("action=area051_delete_orphan_media_start&nonce="+encodeURIComponent(nonceField.value));';
            echo '});';
            echo '})();';
            echo '</script>';
        }

        $generated_at = !empty($scan_result['generated_at']) ? (int) $scan_result['generated_at'] : 0;
        $indexed_posts = !empty($scan_result['indexed_posts']) ? (int) $scan_result['indexed_posts'] : 0;
        $html_unique_files = !empty($scan_result['html_unique_files']) ? (int) $scan_result['html_unique_files'] : 0;
        echo '<p><strong>' . esc_html__('Ultima scansione:', 'area051-orphan-media-cleaner') . '</strong> ';
        echo $generated_at > 0 ? esc_html(wp_date('Y-m-d H:i:s', $generated_at)) : esc_html__('mai', 'area051-orphan-media-cleaner');
        echo '</p>';

        if ($generated_at > 0) {
            echo '<p><strong>' . esc_html__('Statistiche indice HTML:', 'area051-orphan-media-cleaner') . '</strong> '
                . esc_html(sprintf(__('post indicizzati: %d, filename unici: %d', 'area051-orphan-media-cleaner'), $indexed_posts, $html_unique_files))
                . '</p>';
        }

        echo '<p><strong>' . esc_html__('Orfani trovati:', 'area051-orphan-media-cleaner') . '</strong> ' . esc_html((string) count($orphans)) . '</p>';
        if ($search_term !== '') {
            echo '<p><strong>' . esc_html__('Risultati filtrati:', 'area051-orphan-media-cleaner') . '</strong> ' . esc_html((string) count($filtered_orphans)) . ' / ' . esc_html((string) count($orphans)) . '</p>';
        }

        if (!empty($filtered_orphans)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:1100px;">';
            echo '<input type="hidden" name="action" value="area051_exclude_orphan_media">';
            wp_nonce_field(self::NONCE_EXCLUDE);
            echo '<table class="widefat striped" style="max-width:1100px">';
            echo '<thead><tr><th style="width:36px;"><input type="checkbox" id="area051-check-all-orphans" aria-label="' . esc_attr__('Seleziona tutti', 'area051-orphan-media-cleaner') . '"></th><th>ID</th><th>' . esc_html__('Titolo', 'area051-orphan-media-cleaner') . '</th><th>URL</th></tr></thead><tbody>';
            $preview_ids = array_slice($filtered_orphans, 0, 100);
            foreach ($preview_ids as $attachment_id) {
                $attachment_id = (int) $attachment_id;
                $title = get_the_title($attachment_id);
                $url = wp_get_attachment_url($attachment_id);
                $exists = get_post($attachment_id);
                $title_display = $title ? $title : ($exists ? '-' : __('Media eliminato', 'area051-orphan-media-cleaner'));
                $url_display = $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a>' : ($exists ? '-' : esc_html__('Non disponibile', 'area051-orphan-media-cleaner'));
                echo '<tr>';
                echo '<td><input type="checkbox" class="area051-orphan-row-check" name="a51_orphan_keep_ids[]" value="' . esc_attr((string) $attachment_id) . '"></td>';
                echo '<td>' . esc_html((string) $attachment_id) . '</td>';
                echo '<td>' . esc_html($title_display) . '</td>';
                echo '<td>' . $url_display . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            echo '<p style="margin:10px 0 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
            echo '<button type="submit" class="button">' . esc_html__('Rimuovi selezionati dalla tabella orfani', 'area051-orphan-media-cleaner') . '</button>';
            echo '<span style="color:#50575e;">' . esc_html__('Questa azione non elimina i file media: li toglie solo dalla lista orfani corrente.', 'area051-orphan-media-cleaner') . '</span>';
            echo '</p>';
            echo '</form>';

            echo '<script>';
            echo '(function(){';
            echo 'var all=document.getElementById("area051-check-all-orphans");';
            echo 'if(!all){return;}';
            echo 'all.addEventListener("change",function(){';
            echo 'var rows=document.querySelectorAll(".area051-orphan-row-check");';
            echo 'for(var i=0;i<rows.length;i++){rows[i].checked=!!all.checked;}';
            echo '});';
            echo '})();';
            echo '</script>';

            if (count($filtered_orphans) > 100) {
                echo '<p><em>' . esc_html__('Anteprima limitata ai primi 100 risultati.', 'area051-orphan-media-cleaner') . '</em></p>';
            }
        } elseif ($search_term !== '' && !empty($orphans)) {
            echo '<p><em>' . esc_html__('Nessun orfano corrisponde alla ricerca corrente.', 'area051-orphan-media-cleaner') . '</em></p>';
        }

        echo '<hr style="margin:24px 0;">';
        echo '<h2 style="margin:0 0 10px;">' . esc_html__('Controllo file fisici in uploads', 'area051-orphan-media-cleaner') . '</h2>';
        echo '<p style="max-width:1000px;">' . esc_html__('Confronta i file presenti in wp-content/uploads con i file registrati nelle attachment meta di WordPress (_wp_attached_file e _wp_attachment_metadata). I risultati sono indicativi: verifica prima di eliminare.', 'area051-orphan-media-cleaner') . '</p>';

        wp_nonce_field(self::NONCE_UPLOAD_AUDIT, 'area051_upload_audit_nonce_field');
        echo '<p style="margin:10px 0 14px;">';
        echo '<button type="button" class="button button-secondary" id="area051-start-upload-audit-btn">' . esc_html__('Controlla file non più richiamati in uploads (background)', 'area051-orphan-media-cleaner') . '</button>';
        echo '</p>';

        echo '<div id="area051-upload-audit-progress" style="display:none;max-width:900px;border:1px solid #ccd0d4;padding:12px;background:#fff;margin:0 0 16px;">';
        echo '<p id="area051-upload-audit-progress-text" style="margin:0 0 8px;font-weight:600;"></p>';
        echo '<div style="height:20px;background:#f0f0f1;border-radius:4px;overflow:hidden;">';
        echo '<div id="area051-upload-audit-progress-bar" style="height:20px;width:0;background:#1d7f4e;color:#fff;font-size:12px;line-height:20px;text-align:center;"></div>';
        echo '</div>';
        echo '<p id="area051-upload-audit-progress-meta" style="margin:8px 0 0;color:#50575e;"></p>';
        echo '</div>';

        echo '<script>';
        echo '(function(){';
        echo 'var btn=document.getElementById("area051-start-upload-audit-btn");';
        echo 'var wrap=document.getElementById("area051-upload-audit-progress");';
        echo 'var txt=document.getElementById("area051-upload-audit-progress-text");';
        echo 'var meta=document.getElementById("area051-upload-audit-progress-meta");';
        echo 'var bar=document.getElementById("area051-upload-audit-progress-bar");';
        echo 'var nonceField=document.getElementById("area051_upload_audit_nonce_field");';
        echo 'if(!btn||!wrap||!txt||!meta||!bar||!nonceField){return;}';
        echo 'var ajaxUrl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';';
        echo 'var doneUrl=' . wp_json_encode(add_query_arg(array('page' => self::MENU_SLUG, 'a51_notice' => 'upload_audit_done'), admin_url('admin.php'))) . ';';
        echo 'var resumeState=' . wp_json_encode($upload_audit_state_payload) . ';';
        echo 'var stopRequested=false;';
        echo 'function setProgress(data){';
        echo 'var files=(data&&data.scanned_files)?parseInt(data.scanned_files,10):0;';
        echo 'var registered=(data&&data.registered_files)?parseInt(data.registered_files,10):0;';
        echo 'var unref=(data&&data.unreferenced_files)?parseInt(data.unreferenced_files,10):0;';
        echo 'var dirsRemaining=(data&&data.directories_remaining)?parseInt(data.directories_remaining,10):0;';
        echo 'var dirsProcessed=(data&&data.directories_processed)?parseInt(data.directories_processed,10):0;';
        echo 'var totalDirs=dirsProcessed+dirsRemaining;';
        echo 'var pct=totalDirs>0?Math.floor((dirsProcessed/totalDirs)*100):0;';
        echo 'if(pct<0){pct=0;}if(pct>100){pct=100;}';
        echo 'bar.style.width=pct+"%";bar.textContent=pct+"%";';
        echo 'txt.textContent="Controllo uploads in corso...";';
        echo 'meta.textContent="Cartelle: "+dirsProcessed+" processate, "+dirsRemaining+" rimanenti | Immagini analizzate: "+files+" | Immagini registrate: "+registered+" | Possibili non referenziate: "+unref;';
        echo '}';
        echo 'function processBatch(){';
        echo 'if(stopRequested){return;}';
        echo 'var xhr=new XMLHttpRequest();';
        echo 'xhr.open("POST",ajaxUrl);';
        echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo 'xhr.timeout=60000;';
        echo 'xhr.onload=function(){';
        echo 'try{var resp=JSON.parse(xhr.responseText);}catch(e){txt.textContent="Errore risposta upload audit";btn.disabled=false;return;}';
        echo 'if(!resp||!resp.success||!resp.data){txt.textContent="Errore durante controllo uploads";btn.disabled=false;return;}';
        echo 'setProgress(resp.data||{});';
        echo 'if(resp.data.done){window.location.href=doneUrl;return;}';
        echo 'if(!stopRequested){setTimeout(processBatch,350);}';
        echo '};';
        echo 'xhr.onerror=function(){if(stopRequested){return;}txt.textContent="Errore rete controllo uploads";btn.disabled=false;};';
        echo 'xhr.ontimeout=function(){if(stopRequested){return;}txt.textContent="Timeout batch uploads, riprovo...";setTimeout(processBatch,1000);};';
        echo 'xhr.send("action=area051_upload_audit_process&nonce="+encodeURIComponent(nonceField.value));';
        echo '}';
        echo 'btn.addEventListener("click",function(){';
        echo 'btn.disabled=true;wrap.style.display="block";txt.textContent="Inizializzazione controllo uploads...";meta.textContent="";bar.style.width="0%";bar.textContent="0%";';
        echo 'var xhr=new XMLHttpRequest();';
        echo 'xhr.open("POST",ajaxUrl);';
        echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo 'xhr.timeout=30000;';
        echo 'xhr.onload=function(){';
        echo 'try{var resp=JSON.parse(xhr.responseText);}catch(e){txt.textContent="Errore avvio controllo uploads";btn.disabled=false;return;}';
        echo 'if(!resp||!resp.success||!resp.data){txt.textContent="Errore avvio controllo uploads";btn.disabled=false;return;}';
        echo 'setProgress(resp.data||{});';
        echo 'processBatch();';
        echo '};';
        echo 'xhr.onerror=function(){txt.textContent="Errore rete avvio uploads";btn.disabled=false;};';
        echo 'xhr.ontimeout=function(){txt.textContent="Timeout avvio uploads";btn.disabled=false;};';
        echo 'xhr.send("action=area051_upload_audit_start&nonce="+encodeURIComponent(nonceField.value));';
        echo '});';
        echo 'if(resumeState&&resumeState.done!==true){';
        echo 'btn.disabled=true;stopRequested=false;wrap.style.display="block";setProgress(resumeState);processBatch();';
        echo '}';
        echo '})();';
        echo '</script>';

        if (!empty($upload_audit_result['generated_at'])) {
            $upload_files = isset($upload_audit_result['unreferenced_files']) && is_array($upload_audit_result['unreferenced_files'])
                ? array_values($upload_audit_result['unreferenced_files'])
                : array();
            $filtered_upload_files = $upload_search_term !== ''
                ? $this->filter_upload_audit_files($upload_files, $upload_search_term)
                : $upload_files;

            echo '<p><strong>' . esc_html__('Ultimo controllo uploads:', 'area051-orphan-media-cleaner') . '</strong> ' . esc_html(wp_date('Y-m-d H:i:s', (int) $upload_audit_result['generated_at'])) . '</p>';
            echo '<p><strong>' . esc_html__('Immagini analizzate:', 'area051-orphan-media-cleaner') . '</strong> ' . esc_html((string) (int) $upload_audit_result['total_files'])
                . ' | <strong>' . esc_html__('Immagini registrate:', 'area051-orphan-media-cleaner') . '</strong> ' . esc_html((string) (int) $upload_audit_result['registered_files'])
                . ' | <strong>' . esc_html__('Possibili non referenziati:', 'area051-orphan-media-cleaner') . '</strong> ' . esc_html((string) count($upload_audit_result['unreferenced_files']))
                . ' | <strong>' . esc_html__('Durata:', 'area051-orphan-media-cleaner') . '</strong> ' . esc_html(number_format_i18n((float) $upload_audit_result['duration_seconds'], 2)) . 's'
                . '</p>';

            echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:10px 0 12px;max-width:1100px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">';
            echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '">';
            echo '<div style="min-width:280px;flex:1;max-width:420px;">';
            echo '<label for="area051-upload-search" style="display:block;margin:0 0 6px;font-weight:600;">' . esc_html__('Cerca nei file uploads trovati', 'area051-orphan-media-cleaner') . '</label>';
            echo '<input type="search" id="area051-upload-search" name="a51_upload_search" value="' . esc_attr($upload_search_term) . '" placeholder="Percorso, nome file, URL" style="width:100%;">';
            echo '</div>';
            echo '<div style="display:flex;gap:8px;align-items:center;">';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Cerca', 'area051-orphan-media-cleaner') . '</button>';
            if ($upload_search_term !== '') {
                echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => self::MENU_SLUG), admin_url('admin.php'))) . '">' . esc_html__('Cancella ricerca', 'area051-orphan-media-cleaner') . '</a>';
            }
            echo '</div>';
            echo '</form>';

            if ($upload_search_term !== '') {
                echo '<p><strong>' . esc_html__('Risultati filtrati:', 'area051-orphan-media-cleaner') . '</strong> ' . esc_html((string) count($filtered_upload_files)) . ' / ' . esc_html((string) count($upload_files)) . '</p>';
            }

            if (!empty($filtered_upload_files)) {
                wp_nonce_field(self::NONCE_UPLOAD_AUDIT_DELETE, 'area051_upload_audit_delete_nonce_field');
                echo '<p style="margin:0 0 12px;">';
                echo '<button type="button" class="button button-secondary" id="area051-start-upload-audit-delete-btn">' . esc_html__('Elimina tutti i file trovati (background)', 'area051-orphan-media-cleaner') . '</button>';
                echo ' <button type="button" class="button" id="area051-start-upload-audit-delete-selected-btn" style="margin-left:8px;">' . esc_html__('Elimina selezionati', 'area051-orphan-media-cleaner') . '</button>';
                echo '</p>';
                echo '<div id="area051-upload-audit-delete-progress" style="display:none;max-width:900px;border:1px solid #ccd0d4;padding:12px;background:#fff;margin:0 0 16px;">';
                echo '<p id="area051-upload-audit-delete-progress-text" style="margin:0 0 8px;font-weight:600;"></p>';
                echo '<div style="height:20px;background:#f0f0f1;border-radius:4px;overflow:hidden;">';
                echo '<div id="area051-upload-audit-delete-progress-bar" style="height:20px;width:0;background:#d63638;color:#fff;font-size:12px;line-height:20px;text-align:center;"></div>';
                echo '</div>';
                echo '<p id="area051-upload-audit-delete-progress-meta" style="margin:8px 0 0;color:#50575e;"></p>';
                echo '</div>';

                echo '<script>';
                echo '(function(){';
                echo 'var btn=document.getElementById("area051-start-upload-audit-delete-btn");';
                echo 'var wrap=document.getElementById("area051-upload-audit-delete-progress");';
                echo 'var txt=document.getElementById("area051-upload-audit-delete-progress-text");';
                echo 'var meta=document.getElementById("area051-upload-audit-delete-progress-meta");';
                echo 'var bar=document.getElementById("area051-upload-audit-delete-progress-bar");';
                echo 'var nonceField=document.getElementById("area051_upload_audit_delete_nonce_field");';
                echo 'var selectedBtn=document.getElementById("area051-start-upload-audit-delete-selected-btn");';
                echo 'if(!btn||!selectedBtn||!wrap||!txt||!meta||!bar||!nonceField){return;}';
                echo 'var ajaxUrl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';';
                echo 'var doneUrl=' . wp_json_encode(add_query_arg(array('page' => self::MENU_SLUG, 'a51_notice' => 'upload_audit_delete_done'), admin_url('admin.php'))) . ';';
                echo 'var displayedFiles=' . wp_json_encode(array_values($filtered_upload_files)) . ';';
                echo 'var currentSearch=' . wp_json_encode($upload_search_term) . ';';
                echo 'function setProgress(processed,total,deleted,failed){';
                echo 'var pct=total>0?Math.floor((processed/total)*100):0;';
                echo 'if(pct<0){pct=0;}if(pct>100){pct=100;}';
                echo 'bar.style.width=pct+"%";bar.textContent=pct+"%";';
                echo 'txt.textContent="Eliminazione file uploads in corso...";';
                echo 'meta.textContent="Processati: "+processed+" / "+total+" | Eliminati: "+deleted+" | Non eliminati: "+failed;';
                echo '}';
                echo 'function processDeleteBatch(){';
                echo 'var xhr=new XMLHttpRequest();';
                echo 'xhr.open("POST",ajaxUrl);';
                echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
                echo 'xhr.timeout=60000;';
                echo 'xhr.onload=function(){';
                echo 'try{var resp=JSON.parse(xhr.responseText);}catch(e){txt.textContent="Errore risposta delete uploads";btn.disabled=false;return;}';
                echo 'if(!resp||!resp.success||!resp.data){txt.textContent="Errore durante eliminazione uploads";btn.disabled=false;return;}';
                echo 'setProgress(resp.data.processed||0,resp.data.total||0,resp.data.deleted||0,resp.data.failed||0);';
                echo 'if(resp.data.done){window.location.href=doneUrl+"&deleted_files="+(resp.data.deleted||0)+"&failed_files="+(resp.data.failed||0);return;}';
                echo 'setTimeout(processDeleteBatch,1000);';
                echo '};';
                echo 'xhr.onerror=function(){txt.textContent="Errore rete durante eliminazione uploads";btn.disabled=false;};';
                echo 'xhr.ontimeout=function(){txt.textContent="Timeout batch delete uploads, riprovo...";setTimeout(processDeleteBatch,1200);};';
                echo 'xhr.send("action=area051_upload_audit_delete_process&nonce="+encodeURIComponent(nonceField.value));';
                echo '}';
                echo 'function startDelete(selectedFiles,confirmText,useSearchScope){';
                echo 'if(!confirm(confirmText)){return;}';
                echo 'btn.disabled=true;selectedBtn.disabled=true;wrap.style.display="block";txt.textContent="Inizializzazione eliminazione uploads...";meta.textContent="";bar.style.width="0%";bar.textContent="0%";';
                echo 'var xhr=new XMLHttpRequest();';
                echo 'xhr.open("POST",ajaxUrl);';
                echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
                echo 'xhr.timeout=30000;';
                echo 'xhr.onload=function(){';
                echo 'try{var resp=JSON.parse(xhr.responseText);}catch(e){txt.textContent="Errore avvio eliminazione uploads";btn.disabled=false;selectedBtn.disabled=false;return;}';
                echo 'if(!resp||!resp.success||!resp.data){txt.textContent="Errore avvio eliminazione uploads";btn.disabled=false;selectedBtn.disabled=false;return;}';
                echo 'setProgress(0,resp.data.total||0,0,0);';
                echo 'processDeleteBatch();';
                echo '};';
                echo 'xhr.onerror=function(){txt.textContent="Errore rete avvio delete uploads";btn.disabled=false;selectedBtn.disabled=false;};';
                echo 'xhr.ontimeout=function(){txt.textContent="Timeout avvio delete uploads";btn.disabled=false;selectedBtn.disabled=false;};';
                echo 'var payload="action=area051_upload_audit_delete_start&nonce="+encodeURIComponent(nonceField.value);';
                echo 'if(useSearchScope){payload+="&search_term="+encodeURIComponent(currentSearch||"");}';
                echo 'if(selectedFiles&&selectedFiles.length){payload+="&selected_files_json="+encodeURIComponent(JSON.stringify(selectedFiles));}';
                echo 'xhr.send(payload);';
                echo '}';
                echo 'btn.addEventListener("click",function(){startDelete([],"Confermi eliminazione definitiva di tutti i file mostrati?\n(La ricerca corrente viene rispettata)",true);});';
                echo 'selectedBtn.addEventListener("click",function(){';
                echo 'var checks=document.querySelectorAll(".area051-upload-row-check:checked");';
                echo 'var files=[];';
                echo 'for(var i=0;i<checks.length;i++){var file=checks[i].getAttribute("data-file");if(file){files.push(file);}}';
                echo 'if(!files.length){alert("Nessun file selezionato.");return;}';
                echo 'startDelete(files,"Confermi eliminazione definitiva dei file selezionati?",false);';
                echo '});';
                echo 'var all=document.getElementById("area051-check-all-upload-files");';
                echo 'if(all){all.addEventListener("change",function(){var rows=document.querySelectorAll(".area051-upload-row-check");for(var i=0;i<rows.length;i++){rows[i].checked=!!all.checked;}});}';
                echo '})();';
                echo '</script>';

                $uploads = wp_get_upload_dir();
                $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
                echo '<table class="widefat striped" style="max-width:1100px">';
                echo '<thead><tr><th style="width:36px;"><input type="checkbox" id="area051-check-all-upload-files" aria-label="' . esc_attr__('Seleziona tutti i file uploads', 'area051-orphan-media-cleaner') . '"></th><th style="width:80px;">#</th><th>' . esc_html__('Percorso relativo', 'area051-orphan-media-cleaner') . '</th><th>' . esc_html__('URL', 'area051-orphan-media-cleaner') . '</th></tr></thead><tbody>';
                $preview_files = array_slice($filtered_upload_files, 0, 100);
                $index = 0;
                foreach ($preview_files as $relative_file) {
                    $index++;
                    $relative_file = ltrim((string) $relative_file, '/');
                    $file_url = $baseurl !== '' ? trailingslashit($baseurl) . str_replace('%2F', '/', rawurlencode($relative_file)) : '';
                    echo '<tr>';
                    echo '<td><input type="checkbox" class="area051-upload-row-check" data-file="' . esc_attr($relative_file) . '"></td>';
                    echo '<td>' . esc_html((string) $index) . '</td>';
                    echo '<td>' . esc_html($relative_file) . '</td>';
                    echo '<td>' . ($file_url !== '' ? '<a href="' . esc_url($file_url) . '" target="_blank" rel="noopener">' . esc_html($file_url) . '</a>' : '-') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';

                if (count($filtered_upload_files) > 100) {
                    echo '<p><em>' . esc_html__('Anteprima limitata ai primi 100 file.', 'area051-orphan-media-cleaner') . '</em></p>';
                }
            } elseif ($upload_search_term !== '' && !empty($upload_files)) {
                echo '<p><em>' . esc_html__('Nessun file uploads corrisponde alla ricerca corrente.', 'area051-orphan-media-cleaner') . '</em></p>';
            } else {
                echo '<p><em>' . esc_html__('Nessun file non referenziato rilevato nel controllo uploads.', 'area051-orphan-media-cleaner') . '</em></p>';
            }
        }

        echo '</div>';
    }

    public function ajax_scan_start() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato.');
        }

        if (!check_ajax_referer(self::NONCE_SCAN, 'nonce', false)) {
            wp_send_json_error('Nonce non valido.');
        }

        $user_id = get_current_user_id();
        $state_key = $this->get_scan_state_key($user_id);
        $existing_state = get_transient($state_key);
        if (is_array($existing_state)) {
            wp_send_json_success($this->build_scan_progress_payload($existing_state));
        }

        $total_posts = $this->count_total_posts_for_html_index();
        $total_attachments = $this->count_total_attachments();

        set_transient($state_key, array(
            'phase' => 'index',
            'html_total' => $total_posts,
            'html_processed' => 0,
            'html_last_id' => 0,
            'html_index' => array(
                'file' => array(),
                'base' => array(),
                'id' => array(),
            ),
            'scan_total' => $total_attachments,
            'scan_processed' => 0,
            'scan_last_id' => 0,
            'orphans' => array(),
            'started_at' => time(),
        ), HOUR_IN_SECONDS * 2);

        $this->schedule_scan_worker($user_id, 3);

        wp_send_json_success(array(
            'phase' => 'index',
            'html_total' => $total_posts,
            'html_processed' => 0,
            'html_unique_files' => 0,
            'scan_total' => $total_attachments,
            'scan_processed' => 0,
            'orphans' => 0,
        ));
    }

    protected function build_scan_progress_payload($state) {
        if (!is_array($state)) {
            return array(
                'phase' => 'index',
                'html_total' => 0,
                'html_processed' => 0,
                'html_unique_files' => 0,
                'scan_total' => 0,
                'scan_processed' => 0,
                'orphans' => 0,
                'done' => false,
            );
        }

        $html_index = isset($state['html_index']) && is_array($state['html_index']) ? $state['html_index'] : array('file' => array(), 'base' => array(), 'id' => array());
        $html_unique_files = isset($html_index['file']) && is_array($html_index['file']) ? count($html_index['file']) : 0;
        $orphans = isset($state['orphans']) && is_array($state['orphans']) ? $state['orphans'] : array();

        return array(
            'phase' => isset($state['phase']) ? (string) $state['phase'] : 'index',
            'html_total' => isset($state['html_total']) ? (int) $state['html_total'] : 0,
            'html_processed' => isset($state['html_processed']) ? (int) $state['html_processed'] : 0,
            'html_unique_files' => $html_unique_files,
            'scan_total' => isset($state['scan_total']) ? (int) $state['scan_total'] : 0,
            'scan_processed' => isset($state['scan_processed']) ? (int) $state['scan_processed'] : 0,
            'orphans' => count(array_unique(array_map('absint', $orphans))),
            'done' => false,
        );
    }

    protected function build_upload_audit_progress_payload($state) {
        if (!is_array($state)) {
            return array(
                'directories_processed' => 0,
                'directories_remaining' => 0,
                'scanned_files' => 0,
                'registered_files' => 0,
                'unreferenced_files' => 0,
                'done' => false,
            );
        }

        $directories_queue = isset($state['directories_queue']) && is_array($state['directories_queue']) ? $state['directories_queue'] : array();
        $unreferenced_files = isset($state['unreferenced_files']) && is_array($state['unreferenced_files']) ? $state['unreferenced_files'] : array();

        return array(
            'directories_processed' => isset($state['directories_processed']) ? (int) $state['directories_processed'] : 0,
            'directories_remaining' => count($directories_queue),
            'scanned_files' => isset($state['scanned_files']) ? (int) $state['scanned_files'] : 0,
            'registered_files' => isset($state['registered_count']) ? (int) $state['registered_count'] : 0,
            'unreferenced_files' => count($unreferenced_files),
            'done' => false,
        );
    }

    public function ajax_scan_process() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato.');
        }

        if (!check_ajax_referer(self::NONCE_SCAN, 'nonce', false)) {
            wp_send_json_error('Nonce non valido.');
        }

        $result = $this->process_scan_state_batch(get_current_user_id());
        if (!is_array($result) || empty($result['ok'])) {
            wp_send_json_error(isset($result['message']) ? $result['message'] : 'Errore scansione.');
        }

        unset($result['ok']);
        wp_send_json_success($result);
    }

    public function ajax_stop_process() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato.');
        }

        if (!check_ajax_referer(self::NONCE_SCAN, 'nonce', false)) {
            wp_send_json_error('Nonce non valido.');
        }

        $this->stop_user_process(get_current_user_id());

        wp_send_json_success(array(
            'stopped' => true,
            'message' => 'Processo fermato. Puoi riavviare quando vuoi.',
        ));
    }

    public function ajax_delete_start() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato.');
        }

        if (!check_ajax_referer(self::NONCE_DELETE, 'nonce', false)) {
            wp_send_json_error('Nonce non valido.');
        }

        $scan_result = $this->get_scan_result();
        $orphans = is_array($scan_result) && isset($scan_result['orphans']) && is_array($scan_result['orphans'])
            ? array_values(array_unique(array_map('absint', $scan_result['orphans'])))
            : array();

        $user_id = get_current_user_id();
        $state_key = $this->get_delete_state_key($user_id);

        set_transient($state_key, array(
            'orphans' => $orphans,
            'total' => count($orphans),
            'processed' => 0,
            'deleted' => 0,
            'cursor' => 0,
            'started_at' => time(),
        ), HOUR_IN_SECONDS * 2);

        $this->schedule_delete_worker($user_id, 3);

        wp_send_json_success(array(
            'total' => count($orphans),
        ));
    }

    public function ajax_delete_process() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato.');
        }

        if (!check_ajax_referer(self::NONCE_DELETE, 'nonce', false)) {
            wp_send_json_error('Nonce non valido.');
        }

        $result = $this->process_delete_state_batch(get_current_user_id());
        if (!is_array($result) || empty($result['ok'])) {
            wp_send_json_error(isset($result['message']) ? $result['message'] : 'Errore eliminazione.');
        }

        unset($result['ok']);
        wp_send_json_success($result);
    }

    public function ajax_upload_audit_start() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato.');
        }

        if (!check_ajax_referer(self::NONCE_UPLOAD_AUDIT, 'nonce', false)) {
            wp_send_json_error('Nonce non valido.');
        }

        $user_id = get_current_user_id();
        $state_key = $this->get_upload_audit_state_key($user_id);
        $existing_state = get_transient($state_key);
        if (is_array($existing_state)) {
            wp_send_json_success($this->build_upload_audit_progress_payload($existing_state));
        }

        $uploads = wp_get_upload_dir();
        $base_dir = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        if ($base_dir === '' || !is_dir($base_dir)) {
            wp_send_json_error('Cartella uploads non disponibile.');
        }

        $registered = $this->collect_registered_upload_files();

        set_transient($state_key, array(
            'base_dir' => $base_dir,
            'directories_queue' => array(''),
            'directories_processed' => 0,
            'scanned_files' => 0,
            'registered_files' => $registered,
            'registered_count' => count($registered),
            'unreferenced_files' => array(),
            'started_at' => time(),
            'started_at_micro' => microtime(true),
        ), HOUR_IN_SECONDS * 6);

        $this->schedule_upload_audit_worker($user_id, 5);

        wp_send_json_success(array(
            'directories_processed' => 0,
            'directories_remaining' => 1,
            'scanned_files' => 0,
            'registered_files' => count($registered),
            'unreferenced_files' => 0,
            'done' => false,
        ));
    }

    public function ajax_upload_audit_process() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato.');
        }

        if (!check_ajax_referer(self::NONCE_UPLOAD_AUDIT, 'nonce', false)) {
            wp_send_json_error('Nonce non valido.');
        }

        $result = $this->process_upload_audit_state_batch(get_current_user_id());
        if (!is_array($result) || empty($result['ok'])) {
            wp_send_json_error(isset($result['message']) ? $result['message'] : 'Errore controllo uploads.');
        }

        unset($result['ok']);
        wp_send_json_success($result);
    }

    public function ajax_upload_audit_delete_start() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato.');
        }

        if (!check_ajax_referer(self::NONCE_UPLOAD_AUDIT_DELETE, 'nonce', false)) {
            wp_send_json_error('Nonce non valido.');
        }

        $audit_result = $this->get_upload_audit_result();
        $all_files = isset($audit_result['unreferenced_files']) && is_array($audit_result['unreferenced_files'])
            ? array_values(array_unique(array_filter(array_map(array($this, 'normalize_relative_upload_path'), $audit_result['unreferenced_files']))))
            : array();

        $selected_files = array();

        if (isset($_POST['selected_files_json']) && is_string($_POST['selected_files_json'])) {
            $selected_json = wp_unslash($_POST['selected_files_json']);
            $decoded = json_decode($selected_json, true);
            if (is_array($decoded)) {
                $selected_files = array_values(array_unique(array_filter(array_map(array($this, 'normalize_relative_upload_path'), $decoded))));
            }
        }

        if (empty($selected_files) && isset($_POST['selected_files']) && is_array($_POST['selected_files'])) {
            $selected_files = array_values(array_unique(array_filter(array_map(array($this, 'normalize_relative_upload_path'), wp_unslash($_POST['selected_files'])))));
        }

        $search_term = isset($_POST['search_term']) ? sanitize_text_field(wp_unslash($_POST['search_term'])) : '';
        $search_term = trim($search_term);

        if (!empty($selected_files)) {
            $files = array_values(array_intersect($all_files, $selected_files));
        } elseif ($search_term !== '') {
            $files = $this->filter_upload_audit_files($all_files, $search_term);
        } else {
            $files = $all_files;
        }

        if (empty($files)) {
            wp_send_json_success(array(
                'total' => 0,
                'processed' => 0,
                'deleted' => 0,
                'failed' => 0,
                'done' => true,
            ));
        }

        $user_id = get_current_user_id();
        $state_key = $this->get_upload_audit_delete_state_key($user_id);
        set_transient($state_key, array(
            'files' => $files,
            'total' => count($files),
            'processed' => 0,
            'deleted' => 0,
            'failed' => 0,
            'cursor' => 0,
            'started_at' => time(),
        ), HOUR_IN_SECONDS * 6);

        $this->schedule_upload_audit_delete_worker($user_id, 4);

        wp_send_json_success(array(
            'total' => count($files),
            'processed' => 0,
            'deleted' => 0,
            'failed' => 0,
            'done' => false,
        ));
    }

    public function ajax_upload_audit_delete_process() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Non autorizzato.');
        }

        if (!check_ajax_referer(self::NONCE_UPLOAD_AUDIT_DELETE, 'nonce', false)) {
            wp_send_json_error('Nonce non valido.');
        }

        $result = $this->process_upload_audit_delete_state_batch(get_current_user_id());
        if (!is_array($result) || empty($result['ok'])) {
            wp_send_json_error(isset($result['message']) ? $result['message'] : 'Errore eliminazione file uploads.');
        }

        unset($result['ok']);
        wp_send_json_success($result);
    }

    public function handle_delete() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'area051-orphan-media-cleaner'));
        }

        check_admin_referer(self::NONCE_DELETE);

        $scan_result = $this->get_scan_result();
        $orphans = is_array($scan_result) && isset($scan_result['orphans']) && is_array($scan_result['orphans'])
            ? $scan_result['orphans']
            : array();

        $deleted = 0;
        foreach ($orphans as $attachment_id) {
            $attachment_id = (int) $attachment_id;
            if ($attachment_id <= 0) {
                continue;
            }

            $result = wp_delete_attachment($attachment_id, true);
            if ($result) {
                $deleted++;
            }
        }

        $this->clear_scan_result();

        $redirect = add_query_arg(array(
            'page' => self::MENU_SLUG,
            'a51_notice' => 'delete_done',
            'deleted' => $deleted,
        ), admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_exclude() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'area051-orphan-media-cleaner'));
        }

        check_admin_referer(self::NONCE_EXCLUDE);

        $scan_result = $this->get_scan_result();
        $orphans = is_array($scan_result) && isset($scan_result['orphans']) && is_array($scan_result['orphans'])
            ? array_values(array_unique(array_map('absint', $scan_result['orphans'])))
            : array();

        $selected_ids = isset($_POST['a51_orphan_keep_ids']) && is_array($_POST['a51_orphan_keep_ids'])
            ? array_values(array_unique(array_filter(array_map('absint', wp_unslash($_POST['a51_orphan_keep_ids'])))))
            : array();

        if (empty($selected_ids)) {
            $redirect_none = add_query_arg(array(
                'page' => self::MENU_SLUG,
                'a51_notice' => 'exclude_none',
            ), admin_url('admin.php'));

            wp_safe_redirect($redirect_none);
            exit;
        }

        $updated_orphans = array_values(array_diff($orphans, $selected_ids));
        $excluded = max(0, count($orphans) - count($updated_orphans));

        if (!is_array($scan_result)) {
            $scan_result = array();
        }
        $scan_result['orphans'] = $updated_orphans;
        if (!isset($scan_result['generated_at'])) {
            $scan_result['generated_at'] = time();
        }
        $this->save_scan_result($scan_result);

        $redirect = add_query_arg(array(
            'page' => self::MENU_SLUG,
            'a51_notice' => 'exclude_done',
            'excluded' => $excluded,
        ), admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_upload_audit() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'area051-orphan-media-cleaner'));
        }

        check_admin_referer(self::NONCE_UPLOAD_AUDIT);

        $result = $this->scan_unreferenced_upload_files();
        if (!is_array($result) || !empty($result['error'])) {
            $redirect_error = add_query_arg(array(
                'page' => self::MENU_SLUG,
                'a51_notice' => 'upload_audit_error',
            ), admin_url('admin.php'));

            wp_safe_redirect($redirect_error);
            exit;
        }

        $this->save_upload_audit_result($result);

        $redirect_ok = add_query_arg(array(
            'page' => self::MENU_SLUG,
            'a51_notice' => 'upload_audit_done',
        ), admin_url('admin.php'));

        wp_safe_redirect($redirect_ok);
        exit;
    }

    public function cron_scan_worker($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return;
        }

        $last_result = array('ok' => false, 'done' => true);
        $started = microtime(true);
        while ((microtime(true) - $started) < 20) {
            $last_result = $this->process_scan_state_batch($user_id);
            if (!is_array($last_result) || empty($last_result['ok']) || !empty($last_result['done'])) {
                break;
            }
        }

        if (is_array($last_result) && !empty($last_result['ok']) && empty($last_result['done'])) {
            $this->schedule_scan_worker($user_id, 4);
        }
    }

    public function cron_delete_worker($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return;
        }

        $last_result = array('ok' => false, 'done' => true);
        $started = microtime(true);
        while ((microtime(true) - $started) < 20) {
            $last_result = $this->process_delete_state_batch($user_id);
            if (!is_array($last_result) || empty($last_result['ok']) || !empty($last_result['done'])) {
                break;
            }
        }

        if (is_array($last_result) && !empty($last_result['ok']) && empty($last_result['done'])) {
            $this->schedule_delete_worker($user_id, 4);
        }
    }

    public function cron_upload_audit_worker($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return;
        }

        $last_result = array('ok' => false, 'done' => true);
        $started = microtime(true);
        while ((microtime(true) - $started) < 8) {
            $last_result = $this->process_upload_audit_state_batch($user_id);
            if (!is_array($last_result) || empty($last_result['ok']) || !empty($last_result['done'])) {
                break;
            }
        }

        if (is_array($last_result) && !empty($last_result['ok']) && empty($last_result['done'])) {
            $this->schedule_upload_audit_worker($user_id, 8);
        }
    }

    public function cron_upload_audit_delete_worker($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return;
        }

        $last_result = array('ok' => false, 'done' => true);
        $started = microtime(true);
        while ((microtime(true) - $started) < 8) {
            $last_result = $this->process_upload_audit_delete_state_batch($user_id);
            if (!is_array($last_result) || empty($last_result['ok']) || !empty($last_result['done'])) {
                break;
            }
        }

        if (is_array($last_result) && !empty($last_result['ok']) && empty($last_result['done'])) {
            $this->schedule_upload_audit_delete_worker($user_id, 8);
        }
    }

    protected function process_scan_state_batch($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array('ok' => false, 'message' => 'Utente non valido.');
        }

        $lock_key = self::TRANSIENT_SCAN_LOCK . '_' . $user_id;
        if (get_transient($lock_key)) {
            $state = get_transient($this->get_scan_state_key($user_id));
            $payload = $this->build_scan_progress_payload($state);
            $payload['ok'] = true;
            $payload['done'] = false;
            return $payload;
        }
        set_transient($lock_key, 1, 30);

        try {
            $state_key = $this->get_scan_state_key($user_id);
            $state = get_transient($state_key);
            if (!is_array($state)) {
                return array('ok' => false, 'message' => 'Stato scansione non trovato. Avvia nuovamente la scansione.');
            }

            $phase = isset($state['phase']) ? (string) $state['phase'] : 'index';
            $html_total = isset($state['html_total']) ? (int) $state['html_total'] : 0;
            $html_processed = isset($state['html_processed']) ? (int) $state['html_processed'] : 0;
            $html_last_id = isset($state['html_last_id']) ? (int) $state['html_last_id'] : 0;
            $scan_total = isset($state['scan_total']) ? (int) $state['scan_total'] : 0;
            $scan_processed = isset($state['scan_processed']) ? (int) $state['scan_processed'] : 0;
            $scan_last_id = isset($state['scan_last_id']) ? (int) $state['scan_last_id'] : 0;
            $orphans = isset($state['orphans']) && is_array($state['orphans']) ? $state['orphans'] : array();
            $html_index = isset($state['html_index']) && is_array($state['html_index']) ? $state['html_index'] : array('file' => array(), 'base' => array(), 'id' => array());
            $html_unique_files = isset($html_index['file']) && is_array($html_index['file']) ? count($html_index['file']) : 0;

            if ($phase === 'index') {
                $post_batch = $this->get_posts_batch_for_html_index($html_last_id, 40);
                if (empty($post_batch)) {
                    $state['phase'] = 'scan';
                    $state['html_index'] = $html_index;
                    set_transient($state_key, $state, HOUR_IN_SECONDS * 2);

                    return array(
                        'ok' => true,
                        'done' => false,
                        'phase' => 'scan',
                        'html_total' => $html_total,
                        'html_processed' => $html_processed,
                        'html_unique_files' => $html_unique_files,
                        'scan_total' => $scan_total,
                        'scan_processed' => $scan_processed,
                        'orphans' => count(array_unique($orphans)),
                    );
                }

                foreach ($post_batch as $row) {
                    $post_id = isset($row['ID']) ? (int) $row['ID'] : 0;
                    $content = isset($row['post_content']) ? (string) $row['post_content'] : '';
                    if ($post_id <= 0) {
                        continue;
                    }

                    $tokens = $this->extract_file_tokens_from_html($content);
                    foreach ($tokens as $token) {
                        if (!isset($html_index['file'][$token])) {
                            $html_index['file'][$token] = 1;
                        }

                        $base = $this->normalize_size_variant_filename($token);
                        if ($base !== '' && !isset($html_index['base'][$base])) {
                            $html_index['base'][$base] = 1;
                        }
                    }

                    $attachment_ids = $this->extract_attachment_ids_from_content($content);
                    foreach ($attachment_ids as $attachment_id) {
                        $attachment_id = (int) $attachment_id;
                        if ($attachment_id > 0) {
                            $html_index['id'][$attachment_id] = 1;
                        }
                    }

                    $html_last_id = $post_id;
                    $html_processed++;
                }

                $state['phase'] = 'index';
                $state['html_total'] = $html_total;
                $state['html_processed'] = $html_processed;
                $state['html_last_id'] = $html_last_id;
                $state['html_index'] = $html_index;
                set_transient($state_key, $state, HOUR_IN_SECONDS * 2);
                $html_unique_files = isset($html_index['file']) && is_array($html_index['file']) ? count($html_index['file']) : 0;

                return array(
                    'ok' => true,
                    'done' => false,
                    'phase' => 'index',
                    'html_total' => $html_total,
                    'html_processed' => $html_processed,
                    'html_unique_files' => $html_unique_files,
                    'scan_total' => $scan_total,
                    'scan_processed' => $scan_processed,
                    'orphans' => count(array_unique($orphans)),
                );
            }

            $batch_ids = $this->get_attachment_batch($scan_last_id, 30);
            if (empty($batch_ids)) {
                $orphans = array_values(array_unique(array_map('absint', $orphans)));
                $this->save_scan_result(array(
                    'scanned' => $scan_processed,
                    'indexed_posts' => $html_processed,
                    'html_unique_files' => $html_unique_files,
                    'orphans' => $orphans,
                    'generated_at' => time(),
                ));

                delete_transient($state_key);

                return array(
                    'ok' => true,
                    'done' => true,
                    'phase' => 'scan',
                    'html_total' => $html_total,
                    'html_processed' => $html_processed,
                    'html_unique_files' => $html_unique_files,
                    'scan_total' => $scan_total,
                    'scan_processed' => $scan_processed,
                    'orphans' => count($orphans),
                );
            }

            foreach ($batch_ids as $attachment_id) {
                $attachment_id = (int) $attachment_id;
                if ($attachment_id <= 0) {
                    continue;
                }

                if ($this->is_orphan_attachment($attachment_id, $html_index)) {
                    $orphans[] = $attachment_id;
                }
                $scan_last_id = $attachment_id;
                $scan_processed++;
            }

            $state['phase'] = 'scan';
            $state['scan_total'] = $scan_total;
            $state['scan_processed'] = $scan_processed;
            $state['scan_last_id'] = $scan_last_id;
            $state['orphans'] = $orphans;
            set_transient($state_key, $state, HOUR_IN_SECONDS * 2);

            return array(
                'ok' => true,
                'done' => false,
                'phase' => 'scan',
                'html_total' => $html_total,
                'html_processed' => $html_processed,
                'html_unique_files' => $html_unique_files,
                'scan_total' => $scan_total,
                'scan_processed' => $scan_processed,
                'orphans' => count(array_unique($orphans)),
            );
        } finally {
            delete_transient($lock_key);
        }
    }

    protected function process_delete_state_batch($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array('ok' => false, 'message' => 'Utente non valido.');
        }

        $lock_key = self::TRANSIENT_DELETE_LOCK . '_' . $user_id;
        if (get_transient($lock_key)) {
            return array('ok' => true, 'done' => false);
        }
        set_transient($lock_key, 1, 30);

        try {
            $state_key = $this->get_delete_state_key($user_id);
            $state = get_transient($state_key);
            if (!is_array($state)) {
                return array('ok' => false, 'message' => 'Stato eliminazione non trovato. Avvia nuovamente la cancellazione.');
            }

            $orphans = isset($state['orphans']) && is_array($state['orphans']) ? $state['orphans'] : array();
            $total = isset($state['total']) ? (int) $state['total'] : count($orphans);
            $processed = isset($state['processed']) ? (int) $state['processed'] : 0;
            $deleted = isset($state['deleted']) ? (int) $state['deleted'] : 0;
            $cursor = isset($state['cursor']) ? (int) $state['cursor'] : 0;

            $batch_size = 15;
            $batch = array_slice($orphans, $cursor, $batch_size);
            if (empty($batch)) {
                delete_transient($state_key);
                $this->clear_scan_result();

                return array(
                    'ok' => true,
                    'done' => true,
                    'processed' => $processed,
                    'total' => $total,
                    'deleted' => $deleted,
                );
            }

            foreach ($batch as $attachment_id) {
                $attachment_id = (int) $attachment_id;
                if ($attachment_id > 0) {
                    $result = wp_delete_attachment($attachment_id, true);
                    if ($result) {
                        $deleted++;
                        $orphans = array_values(array_diff($orphans, array($attachment_id)));
                    }
                }
                $processed++;
                $cursor++;
            }

            set_transient($state_key, array(
                'orphans' => $orphans,
                'total' => $total,
                'processed' => $processed,
                'deleted' => $deleted,
                'cursor' => $cursor,
                'started_at' => isset($state['started_at']) ? (int) $state['started_at'] : time(),
            ), HOUR_IN_SECONDS * 2);

            $this->save_scan_result(array(
                'scanned' => $processed,
                'indexed_posts' => isset($state['indexed_posts']) ? (int) $state['indexed_posts'] : 0,
                'html_unique_files' => isset($state['html_unique_files']) ? (int) $state['html_unique_files'] : 0,
                'orphans' => $orphans,
                'generated_at' => isset($state['started_at']) ? (int) $state['started_at'] : time(),
            ));

            return array(
                'ok' => true,
                'done' => false,
                'processed' => $processed,
                'total' => $total,
                'deleted' => $deleted,
            );
        } finally {
            delete_transient($lock_key);
        }
    }

    protected function process_upload_audit_state_batch($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array('ok' => false, 'message' => 'Utente non valido.');
        }

        $lock_key = self::TRANSIENT_UPLOAD_AUDIT_LOCK . '_' . $user_id;
        if (get_transient($lock_key)) {
            $state = get_transient($this->get_upload_audit_state_key($user_id));
            $payload = $this->build_upload_audit_progress_payload($state);
            $payload['ok'] = true;
            $payload['done'] = false;
            return $payload;
        }
        set_transient($lock_key, 1, 30);

        try {
            $state_key = $this->get_upload_audit_state_key($user_id);
            $state = get_transient($state_key);
            if (!is_array($state)) {
                return array('ok' => false, 'message' => 'Stato controllo uploads non trovato. Avvia nuovamente il controllo.');
            }

            $base_dir = isset($state['base_dir']) ? wp_normalize_path((string) $state['base_dir']) : '';
            if ($base_dir === '' || !is_dir($base_dir)) {
                return array('ok' => false, 'message' => 'Cartella uploads non disponibile.');
            }

            $directories_queue = isset($state['directories_queue']) && is_array($state['directories_queue']) ? array_values($state['directories_queue']) : array();
            $directories_processed = isset($state['directories_processed']) ? (int) $state['directories_processed'] : 0;
            $scanned_files = isset($state['scanned_files']) ? (int) $state['scanned_files'] : 0;
            $registered_files = isset($state['registered_files']) && is_array($state['registered_files']) ? $state['registered_files'] : array();
            $registered_count = isset($state['registered_count']) ? (int) $state['registered_count'] : count($registered_files);
            $unreferenced_files = isset($state['unreferenced_files']) && is_array($state['unreferenced_files']) ? $state['unreferenced_files'] : array();
            $started_at = isset($state['started_at']) ? (int) $state['started_at'] : time();
            $started_at_micro = isset($state['started_at_micro']) ? (float) $state['started_at_micro'] : (float) $started_at;

            $batch_dirs = 5;
            for ($i = 0; $i < $batch_dirs && !empty($directories_queue); $i++) {
                $current_relative = (string) array_shift($directories_queue);
                $current_relative = $this->normalize_relative_upload_path($current_relative);
                $current_abs = $base_dir . ($current_relative !== '' ? '/' . $current_relative : '');
                if (!is_dir($current_abs)) {
                    $directories_processed++;
                    continue;
                }

                $entries = @scandir($current_abs);
                if (!is_array($entries)) {
                    $directories_processed++;
                    continue;
                }

                foreach ($entries as $entry) {
                    $entry = (string) $entry;
                    if ($entry === '' || $entry === '.' || $entry === '..') {
                        continue;
                    }

                    $entry_relative = $current_relative !== '' ? $current_relative . '/' . $entry : $entry;
                    $entry_relative = $this->normalize_relative_upload_path($entry_relative);
                    if ($entry_relative === '') {
                        continue;
                    }

                    $entry_abs = $base_dir . '/' . $entry_relative;
                    if (is_dir($entry_abs)) {
                        $directories_queue[] = $entry_relative;
                        continue;
                    }

                    if (!is_file($entry_abs)) {
                        continue;
                    }

                    if (!$this->is_supported_upload_image_path($entry_relative)) {
                        continue;
                    }

                    if (in_array($entry, array('index.php', '.htaccess', 'web.config'), true)) {
                        continue;
                    }

                    $scanned_files++;
                    if (!isset($registered_files[$entry_relative])) {
                        $unreferenced_files[$entry_relative] = 1;
                    }
                }

                $directories_processed++;
            }

            if (empty($directories_queue)) {
                $result = array(
                    'generated_at' => time(),
                    'total_files' => $scanned_files,
                    'registered_files' => $registered_count,
                    'duration_seconds' => max(0, microtime(true) - $started_at_micro),
                    'unreferenced_files' => array_keys($unreferenced_files),
                );
                $this->save_upload_audit_result($result);
                delete_transient($state_key);

                return array(
                    'ok' => true,
                    'done' => true,
                    'directories_processed' => $directories_processed,
                    'directories_remaining' => 0,
                    'scanned_files' => $scanned_files,
                    'registered_files' => $registered_count,
                    'unreferenced_files' => count($unreferenced_files),
                );
            }

            $state['directories_queue'] = $directories_queue;
            $state['directories_processed'] = $directories_processed;
            $state['scanned_files'] = $scanned_files;
            $state['registered_files'] = $registered_files;
            $state['registered_count'] = $registered_count;
            $state['unreferenced_files'] = $unreferenced_files;
            $state['started_at'] = $started_at;
            $state['started_at_micro'] = $started_at_micro;
            set_transient($state_key, $state, HOUR_IN_SECONDS * 6);

            return array(
                'ok' => true,
                'done' => false,
                'directories_processed' => $directories_processed,
                'directories_remaining' => count($directories_queue),
                'scanned_files' => $scanned_files,
                'registered_files' => $registered_count,
                'unreferenced_files' => count($unreferenced_files),
            );
        } finally {
            delete_transient($lock_key);
        }
    }

    protected function process_upload_audit_delete_state_batch($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array('ok' => false, 'message' => 'Utente non valido.');
        }

        $lock_key = self::TRANSIENT_UPLOAD_AUDIT_DELETE_LOCK . '_' . $user_id;
        if (get_transient($lock_key)) {
            return array('ok' => true, 'done' => false);
        }
        set_transient($lock_key, 1, 30);

        try {
            $state_key = $this->get_upload_audit_delete_state_key($user_id);
            $state = get_transient($state_key);
            if (!is_array($state)) {
                return array('ok' => false, 'message' => 'Stato eliminazione uploads non trovato.');
            }

            $files = isset($state['files']) && is_array($state['files']) ? array_values($state['files']) : array();
            $total = isset($state['total']) ? (int) $state['total'] : count($files);
            $processed = isset($state['processed']) ? (int) $state['processed'] : 0;
            $deleted = isset($state['deleted']) ? (int) $state['deleted'] : 0;
            $failed = isset($state['failed']) ? (int) $state['failed'] : 0;
            $cursor = isset($state['cursor']) ? (int) $state['cursor'] : 0;

            $uploads = wp_get_upload_dir();
            $base_dir = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
            if ($base_dir === '' || !is_dir($base_dir)) {
                return array('ok' => false, 'message' => 'Cartella uploads non disponibile.');
            }

            $batch_size = 6;
            $batch = array_slice($files, $cursor, $batch_size);
            if (empty($batch)) {
                $this->clear_upload_audit_result();
                delete_transient($state_key);

                return array(
                    'ok' => true,
                    'done' => true,
                    'processed' => $processed,
                    'total' => $total,
                    'deleted' => $deleted,
                    'failed' => $failed,
                );
            }

            foreach ($batch as $relative_file) {
                $relative_file = $this->normalize_relative_upload_path($relative_file);
                if ($relative_file === '' || !$this->is_supported_upload_image_path($relative_file)) {
                    $failed++;
                    $processed++;
                    $cursor++;
                    continue;
                }

                $absolute_path = wp_normalize_path($base_dir . '/' . $relative_file);
                if ($absolute_path === '' || strpos($absolute_path, $base_dir . '/') !== 0) {
                    $failed++;
                    $processed++;
                    $cursor++;
                    continue;
                }

                if (!file_exists($absolute_path)) {
                    $deleted++;
                    $processed++;
                    $cursor++;
                    continue;
                }

                if (!is_file($absolute_path)) {
                    $failed++;
                    $processed++;
                    $cursor++;
                    continue;
                }

                $ok = @unlink($absolute_path);
                if ($ok) {
                    $deleted++;
                } else {
                    $failed++;
                }

                $processed++;
                $cursor++;
            }

            set_transient($state_key, array(
                'files' => $files,
                'total' => $total,
                'processed' => $processed,
                'deleted' => $deleted,
                'failed' => $failed,
                'cursor' => $cursor,
                'started_at' => isset($state['started_at']) ? (int) $state['started_at'] : time(),
            ), HOUR_IN_SECONDS * 6);

            return array(
                'ok' => true,
                'done' => false,
                'processed' => $processed,
                'total' => $total,
                'deleted' => $deleted,
                'failed' => $failed,
            );
        } finally {
            delete_transient($lock_key);
        }
    }

    protected function schedule_scan_worker($user_id, $delay = 5) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_SCAN_HOOK, array($user_id))) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::CRON_SCAN_HOOK, array($user_id));
        }
    }

    protected function schedule_delete_worker($user_id, $delay = 5) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_DELETE_HOOK, array($user_id))) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::CRON_DELETE_HOOK, array($user_id));
        }
    }

    protected function schedule_upload_audit_worker($user_id, $delay = 5) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_UPLOAD_AUDIT_HOOK, array($user_id))) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::CRON_UPLOAD_AUDIT_HOOK, array($user_id));
        }
    }

    protected function schedule_upload_audit_delete_worker($user_id, $delay = 5) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_UPLOAD_AUDIT_DELETE_HOOK, array($user_id))) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::CRON_UPLOAD_AUDIT_DELETE_HOOK, array($user_id));
        }
    }

    protected function stop_user_process($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }

        delete_transient($this->get_scan_state_key($user_id));
        delete_transient($this->get_delete_state_key($user_id));
        delete_transient($this->get_upload_audit_state_key($user_id));
        delete_transient($this->get_upload_audit_delete_state_key($user_id));
        delete_transient(self::TRANSIENT_SCAN_LOCK . '_' . $user_id);
        delete_transient(self::TRANSIENT_DELETE_LOCK . '_' . $user_id);
        delete_transient(self::TRANSIENT_UPLOAD_AUDIT_LOCK . '_' . $user_id);
        delete_transient(self::TRANSIENT_UPLOAD_AUDIT_DELETE_LOCK . '_' . $user_id);

        while ($timestamp = wp_next_scheduled(self::CRON_SCAN_HOOK, array($user_id))) {
            wp_unschedule_event($timestamp, self::CRON_SCAN_HOOK, array($user_id));
        }

        while ($timestamp = wp_next_scheduled(self::CRON_DELETE_HOOK, array($user_id))) {
            wp_unschedule_event($timestamp, self::CRON_DELETE_HOOK, array($user_id));
        }

        while ($timestamp = wp_next_scheduled(self::CRON_UPLOAD_AUDIT_HOOK, array($user_id))) {
            wp_unschedule_event($timestamp, self::CRON_UPLOAD_AUDIT_HOOK, array($user_id));
        }

        while ($timestamp = wp_next_scheduled(self::CRON_UPLOAD_AUDIT_DELETE_HOOK, array($user_id))) {
            wp_unschedule_event($timestamp, self::CRON_UPLOAD_AUDIT_DELETE_HOOK, array($user_id));
        }
    }

    protected function get_scan_result() {
        $scan_result = get_transient(self::TRANSIENT_SCAN);
        if (is_array($scan_result)) {
            return $this->normalize_scan_result($scan_result);
        }

        $stored = get_option(self::OPTION_SCAN_RESULT, array());
        if (is_array($stored)) {
            $stored = $this->normalize_scan_result($stored);
            set_transient(self::TRANSIENT_SCAN, $stored, HOUR_IN_SECONDS * 6);
            return $stored;
        }

        return $this->normalize_scan_result(array());
    }

    protected function save_scan_result($scan_result) {
        $scan_result = $this->normalize_scan_result($scan_result);
        set_transient(self::TRANSIENT_SCAN, $scan_result, HOUR_IN_SECONDS * 6);
        update_option(self::OPTION_SCAN_RESULT, $scan_result, false);
    }

    protected function clear_scan_result() {
        delete_transient(self::TRANSIENT_SCAN);
        delete_option(self::OPTION_SCAN_RESULT);
    }

    protected function normalize_scan_result($scan_result) {
        if (!is_array($scan_result)) {
            $scan_result = array();
        }

        $orphans = isset($scan_result['orphans']) && is_array($scan_result['orphans'])
            ? array_values(array_unique(array_map('absint', $scan_result['orphans'])))
            : array();

        return array(
            'scanned' => isset($scan_result['scanned']) ? (int) $scan_result['scanned'] : 0,
            'indexed_posts' => isset($scan_result['indexed_posts']) ? (int) $scan_result['indexed_posts'] : 0,
            'html_unique_files' => isset($scan_result['html_unique_files']) ? (int) $scan_result['html_unique_files'] : 0,
            'orphans' => $orphans,
            'generated_at' => isset($scan_result['generated_at']) ? (int) $scan_result['generated_at'] : 0,
        );
    }

    protected function get_upload_audit_result() {
        $result = get_option(self::OPTION_UPLOAD_AUDIT_RESULT, array());
        $normalized = $this->normalize_upload_audit_result($result);
        if ((int) $normalized['rule_version'] !== (int) self::UPLOAD_AUDIT_RULE_VERSION) {
            $this->clear_upload_audit_result();
            return $this->normalize_upload_audit_result(array());
        }

        return $normalized;
    }

    protected function save_upload_audit_result($result) {
        if (!is_array($result)) {
            $result = array();
        }
        $result['rule_version'] = self::UPLOAD_AUDIT_RULE_VERSION;
        $normalized = $this->normalize_upload_audit_result($result);
        update_option(self::OPTION_UPLOAD_AUDIT_RESULT, $normalized, false);
    }

    protected function clear_upload_audit_result() {
        delete_option(self::OPTION_UPLOAD_AUDIT_RESULT);
    }

    protected function remove_files_from_upload_audit_result($removed_files) {
        if (!is_array($removed_files) || empty($removed_files)) {
            return;
        }

        $removed_map = array();
        foreach ($removed_files as $file) {
            $normalized = $this->normalize_relative_upload_path($file);
            if ($normalized !== '') {
                $removed_map[$normalized] = 1;
            }
        }

        if (empty($removed_map)) {
            return;
        }

        $result = $this->get_upload_audit_result();
        $current = isset($result['unreferenced_files']) && is_array($result['unreferenced_files']) ? $result['unreferenced_files'] : array();
        $updated = array();
        foreach ($current as $file) {
            $normalized = $this->normalize_relative_upload_path($file);
            if ($normalized !== '' && !isset($removed_map[$normalized])) {
                $updated[] = $normalized;
            }
        }

        $result['unreferenced_files'] = array_values(array_unique($updated));
        $result['generated_at'] = time();
        $this->save_upload_audit_result($result);
    }

    protected function normalize_upload_audit_result($result) {
        if (!is_array($result)) {
            $result = array();
        }

        $files = isset($result['unreferenced_files']) && is_array($result['unreferenced_files'])
            ? array_values(array_unique(array_map(array($this, 'normalize_relative_upload_path'), $result['unreferenced_files'])))
            : array();
        $files = array_values(array_filter($files));

        return array(
            'generated_at' => isset($result['generated_at']) ? (int) $result['generated_at'] : 0,
            'total_files' => isset($result['total_files']) ? (int) $result['total_files'] : 0,
            'registered_files' => isset($result['registered_files']) ? (int) $result['registered_files'] : 0,
            'duration_seconds' => isset($result['duration_seconds']) ? (float) $result['duration_seconds'] : 0.0,
            'unreferenced_files' => $files,
            'rule_version' => isset($result['rule_version']) ? (int) $result['rule_version'] : 0,
        );
    }

    protected function scan_unreferenced_upload_files() {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $uploads = wp_get_upload_dir();
        $base_dir = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        if ($base_dir === '' || !is_dir($base_dir)) {
            return array('error' => true);
        }

        $started = microtime(true);
        $registered = $this->collect_registered_upload_files();
        $total_files = 0;
        $unreferenced = array();

        $skip_names = array('index.php', '.htaccess', 'web.config');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file_info) {
            if (!$file_info->isFile()) {
                continue;
            }

            $filename = (string) $file_info->getFilename();
            if (in_array($filename, $skip_names, true)) {
                continue;
            }

            $absolute_path = wp_normalize_path((string) $file_info->getPathname());
            if ($absolute_path === '' || strpos($absolute_path, $base_dir . '/') !== 0) {
                continue;
            }

            $relative_path = $this->normalize_relative_upload_path(substr($absolute_path, strlen($base_dir)));
            if ($relative_path === '') {
                continue;
            }

            if (!$this->is_supported_upload_image_path($relative_path)) {
                continue;
            }

            $total_files++;
            if (!isset($registered[$relative_path])) {
                $unreferenced[] = $relative_path;
            }
        }

        return array(
            'generated_at' => time(),
            'total_files' => $total_files,
            'registered_files' => count($registered),
            'duration_seconds' => max(0, microtime(true) - $started),
            'unreferenced_files' => array_values(array_unique($unreferenced)),
        );
    }

    protected function collect_registered_upload_files() {
        global $wpdb;

        $registered = array();

        $attached_rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value != ''"
        );
        if (is_array($attached_rows)) {
            foreach ($attached_rows as $path) {
                $normalized = $this->normalize_relative_upload_path($path);
                if ($normalized !== '' && $this->is_supported_upload_image_path($normalized)) {
                    $registered[$normalized] = 1;
                }
            }
        }

        $meta_rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value != ''"
        );
        if (is_array($meta_rows)) {
            foreach ($meta_rows as $meta_value) {
                $meta = maybe_unserialize($meta_value);
                if (!is_array($meta)) {
                    continue;
                }

                $root_file = isset($meta['file']) && is_string($meta['file']) ? $meta['file'] : '';
                $root_file = $this->normalize_relative_upload_path($root_file);
                $root_dir = $root_file !== '' ? trim((string) wp_normalize_path(dirname($root_file)), './') : '';

                $this->collect_files_from_attachment_metadata($meta, $root_dir, $registered);
            }
        }

        return $registered;
    }

    protected function collect_files_from_attachment_metadata($node, $root_dir, &$registered) {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->collect_files_from_attachment_metadata($value, $root_dir, $registered);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            if ($key !== 'file' && $key !== 'original_image') {
                continue;
            }

            $candidate = $this->normalize_relative_upload_path($value);
            if ($candidate === '') {
                continue;
            }

            if (strpos($candidate, '/') === false && $root_dir !== '' && $root_dir !== '.') {
                $candidate = $this->normalize_relative_upload_path($root_dir . '/' . $candidate);
            }

            if ($candidate !== '' && $this->is_supported_upload_image_path($candidate)) {
                $registered[$candidate] = 1;
            }
        }
    }

    protected function normalize_relative_upload_path($path) {
        $path = wp_normalize_path((string) $path);
        $path = ltrim($path, '/');
        if ($path === '' || strpos($path, '://') !== false) {
            return '';
        }

        if (strpos($path, '../') !== false) {
            return '';
        }

        return $path;
    }

    protected function is_supported_upload_image_path($path) {
        $path = strtolower((string) $path);
        if ($path === '') {
            return false;
        }

        // Consider only files inside dated uploads folders (e.g. 2024/.. or 2024/05/..).
        if (!preg_match('/^[12][0-9]{3}\//', $path)) {
            return false;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (!is_string($extension) || $extension === '') {
            return false;
        }

        return in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'svg'), true);
    }

    protected function is_orphan_attachment($attachment_id, $html_index = array()) {
        if ((int) get_post_field('post_parent', $attachment_id) > 0) {
            return false;
        }

        if ((int) get_option('site_icon') === (int) $attachment_id) {
            return false;
        }

        if ((int) get_theme_mod('custom_logo') === (int) $attachment_id) {
            return false;
        }

        if ($this->is_used_as_featured_image($attachment_id)) {
            return false;
        }

        if ($this->is_used_in_postmeta($attachment_id)) {
            return false;
        }

        if ($this->is_used_in_html_id_index($attachment_id, $html_index)) {
            return false;
        }

        $url = wp_get_attachment_url($attachment_id);
        if ($this->is_used_in_html_index($url, $html_index)) {
            return false;
        }

        if (is_string($url) && $url !== '' && $this->is_used_in_post_content($url)) {
            return false;
        }

        return true;
    }

    protected function is_used_as_featured_image($attachment_id) {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1",
            (string) (int) $attachment_id
        ));

        return !empty($found);
    }

    protected function is_used_in_postmeta($attachment_id) {
        global $wpdb;

        $id = (int) $attachment_id;
        $needle_exact = (string) $id;
        $needle_serialized_int = '%i:' . $id . ';%';
        $needle_serialized_string = '%:"' . $id . '";%';

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta}
             WHERE meta_key != '_thumbnail_id'
               AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s)
             LIMIT 1",
            $needle_exact,
            $needle_serialized_int,
            $needle_serialized_string
        ));

                if (!empty($found)) {
                        return true;
                }

                $id_boundary_pattern = '(^|[^0-9])' . $id . '([^0-9]|$)';
                $found_boundary = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_id FROM {$wpdb->postmeta}
                         WHERE meta_key != '_thumbnail_id'
                             AND meta_value REGEXP %s
                         LIMIT 1",
                        $id_boundary_pattern
                ));

                return !empty($found_boundary);
    }

    protected function is_used_in_post_content($attachment_url) {
        global $wpdb;

        $search_values = array();
        if (is_string($attachment_url) && $attachment_url !== '') {
            $search_values[] = $attachment_url;

            $path = parse_url($attachment_url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $filename = wp_basename($path);
                if ($filename !== '') {
                    $search_values[] = $filename;
                    $search_values[] = rawurlencode($filename);
                }
            }
        }

        foreach (array_values(array_unique(array_filter($search_values))) as $needle) {
            $like = '%' . $wpdb->esc_like($needle) . '%';
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type != 'attachment' AND post_content LIKE %s
                 LIMIT 1",
                $like
            ));

            if (!empty($found)) {
                return true;
            }
        }

        // Also protect resized variants like filename-300x200.jpg used in srcset.
        if (!empty($filename) && strpos($filename, '.') !== false) {
            $dot = strrpos($filename, '.');
            $name = $dot !== false ? substr($filename, 0, $dot) : '';
            $ext = $dot !== false ? substr($filename, $dot + 1) : '';
            if ($name !== '' && $ext !== '') {
                $like_name_prefix = '%' . $wpdb->esc_like($name . '-') . '%';
                $like_ext = '%.' . $wpdb->esc_like($ext) . '%';
                $found_variant = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type != 'attachment'
                       AND post_content LIKE %s
                       AND post_content LIKE %s
                     LIMIT 1",
                    $like_name_prefix,
                    $like_ext
                ));

                if (!empty($found_variant)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function is_used_in_html_index($attachment_url, $html_index) {
        if (!is_array($html_index) || !isset($html_index['file']) || !is_array($html_index['file'])) {
            return false;
        }

        $tokens = $this->extract_attachment_filename_tokens((string) $attachment_url);
        foreach ($tokens as $token) {
            if (isset($html_index['file'][$token])) {
                return true;
            }

            $base = $this->normalize_size_variant_filename($token);
            if ($base !== '' && isset($html_index['base']) && is_array($html_index['base']) && isset($html_index['base'][$base])) {
                return true;
            }
        }

        return false;
    }

    protected function is_used_in_html_id_index($attachment_id, $html_index) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || !is_array($html_index) || !isset($html_index['id']) || !is_array($html_index['id'])) {
            return false;
        }

        return isset($html_index['id'][$attachment_id]);
    }

    protected function filter_orphan_ids($orphans, $search_term) {
        if (!is_array($orphans)) {
            return array();
        }

        $search_term = trim((string) $search_term);
        if ($search_term === '') {
            return array_values(array_map('absint', $orphans));
        }

        $filtered = array();
        foreach ($orphans as $attachment_id) {
            $attachment_id = (int) $attachment_id;
            if ($attachment_id > 0 && $this->orphan_matches_search($attachment_id, $search_term)) {
                $filtered[] = $attachment_id;
            }
        }

        return array_values(array_unique($filtered));
    }

    protected function orphan_matches_search($attachment_id, $search_term) {
        $search_term = strtolower(trim((string) $search_term));
        if ($search_term === '') {
            return true;
        }

        $attachment_id_string = (string) (int) $attachment_id;
        if ($attachment_id_string !== '' && strpos($attachment_id_string, $search_term) !== false) {
            return true;
        }

        $title = strtolower((string) get_the_title($attachment_id));
        if ($title !== '' && strpos($title, $search_term) !== false) {
            return true;
        }

        $url = (string) wp_get_attachment_url($attachment_id);
        if ($url !== '') {
            $url_lower = strtolower($url);
            if (strpos($url_lower, $search_term) !== false) {
                return true;
            }

            $filename = wp_basename((string) parse_url($url, PHP_URL_PATH));
            $filename = strtolower(rawurldecode((string) $filename));
            if ($filename !== '' && strpos($filename, $search_term) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function filter_upload_audit_files($files, $search_term) {
        if (!is_array($files)) {
            return array();
        }

        $search_term = trim((string) $search_term);
        if ($search_term === '') {
            return array_values(array_filter(array_map(array($this, 'normalize_relative_upload_path'), $files)));
        }

        $filtered = array();
        foreach ($files as $file) {
            $file = $this->normalize_relative_upload_path($file);
            if ($file !== '' && $this->upload_audit_file_matches_search($file, $search_term)) {
                $filtered[] = $file;
            }
        }

        return array_values(array_unique($filtered));
    }

    protected function upload_audit_file_matches_search($relative_file, $search_term) {
        $relative_file = strtolower((string) $relative_file);
        $search_term = strtolower(trim((string) $search_term));
        if ($relative_file === '' || $search_term === '') {
            return false;
        }

        if (strpos($relative_file, $search_term) !== false) {
            return true;
        }

        $filename = strtolower((string) wp_basename($relative_file));
        if ($filename !== '' && strpos($filename, $search_term) !== false) {
            return true;
        }

        $uploads = wp_get_upload_dir();
        $baseurl = isset($uploads['baseurl']) ? strtolower((string) $uploads['baseurl']) : '';
        if ($baseurl !== '') {
            $url = $baseurl . '/' . rawurlencode($relative_file);
            $url = str_replace('%2f', '/', $url);
            if (strpos($url, $search_term) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function get_scan_state_key($user_id) {
        return self::TRANSIENT_SCAN_STATE . '_' . (int) $user_id;
    }

    protected function get_delete_state_key($user_id) {
        return self::TRANSIENT_DELETE_STATE . '_' . (int) $user_id;
    }

    protected function get_upload_audit_state_key($user_id) {
        return self::TRANSIENT_UPLOAD_AUDIT_STATE . '_' . (int) $user_id;
    }

    protected function get_upload_audit_delete_state_key($user_id) {
        return self::TRANSIENT_UPLOAD_AUDIT_DELETE_STATE . '_' . (int) $user_id;
    }

    protected function count_total_attachments() {
        global $wpdb;

        $sql = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'";
        return (int) $wpdb->get_var($sql);
    }

    protected function get_attachment_batch($last_id, $limit) {
        global $wpdb;

        $last_id = (int) $last_id;
        $limit = max(1, (int) $limit);

        $sql = $wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_status = 'inherit' AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $last_id,
            $limit
        );

        $ids = $wpdb->get_col($sql);
        return is_array($ids) ? array_map('absint', $ids) : array();
    }

    protected function count_total_posts_for_html_index() {
        global $wpdb;

        $sql = "SELECT COUNT(ID)
                FROM {$wpdb->posts}
                WHERE post_type != 'attachment'
                  AND post_type != 'revision'
                  AND post_status NOT IN ('auto-draft','trash')
                  AND post_content != ''";
        return (int) $wpdb->get_var($sql);
    }

    protected function get_posts_batch_for_html_index($last_id, $limit) {
        global $wpdb;

        $last_id = (int) $last_id;
        $limit = max(1, (int) $limit);

        $sql = $wpdb->prepare(
            "SELECT ID, post_content
             FROM {$wpdb->posts}
             WHERE post_type != 'attachment'
               AND post_type != 'revision'
               AND post_status NOT IN ('auto-draft','trash')
               AND post_content != ''
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $last_id,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    protected function extract_file_tokens_from_html($text) {
        $text = is_string($text) ? $text : '';
        if ($text === '') {
            return array();
        }

        $tokens = array();
        if (preg_match_all('/[A-Za-z0-9_\-\.\%\(\)]+\.[A-Za-z0-9]{2,6}/u', $text, $matches) && isset($matches[0]) && is_array($matches[0])) {
            foreach ($matches[0] as $candidate) {
                $normalized = $this->normalize_filename_token($candidate);
                if ($normalized !== '' && $this->is_probable_media_filename($normalized)) {
                    $tokens[] = $normalized;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    protected function extract_attachment_ids_from_content($text) {
        $text = is_string($text) ? $text : '';
        if ($text === '') {
            return array();
        }

        $ids = array();
        $patterns = array(
            '/(?:gallery_ids|ids|image_ids|image_id|attachment_id|img_id|media_id|photo_id)\s*=\s*"([^"]+)"/i',
            "/(?:gallery_ids|ids|image_ids|image_id|attachment_id|img_id|media_id|photo_id)\s*=\s*'([^']+)'/i",
            '/"(?:gallery_ids|ids|image_ids|image_id|attachment_id|img_id|media_id|photo_id)"\s*:\s*"([^"]+)"/i',
            '/"(?:gallery_ids|ids|image_ids|image_id|attachment_id|img_id|media_id|photo_id)"\s*:\s*\[([^\]]+)\]/i',
        );

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $text, $matches) || !isset($matches[1]) || !is_array($matches[1])) {
                continue;
            }

            foreach ($matches[1] as $raw_list) {
                if (!is_string($raw_list) || $raw_list === '') {
                    continue;
                }

                if (preg_match_all('/\d+/', $raw_list, $id_matches) && isset($id_matches[0]) && is_array($id_matches[0])) {
                    foreach ($id_matches[0] as $match_id) {
                        $match_id = absint($match_id);
                        if ($match_id > 0) {
                            $ids[] = $match_id;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    protected function extract_attachment_filename_tokens($attachment_url) {
        $attachment_url = is_string($attachment_url) ? $attachment_url : '';
        if ($attachment_url === '') {
            return array();
        }

        $tokens = array();
        $path = parse_url($attachment_url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $filename = wp_basename($path);
            $normalized = $this->normalize_filename_token($filename);
            if ($normalized !== '' && $this->is_probable_media_filename($normalized)) {
                $tokens[] = $normalized;
            }

            $encoded = $this->normalize_filename_token(rawurlencode($filename));
            if ($encoded !== '' && $this->is_probable_media_filename($encoded)) {
                $tokens[] = $encoded;
            }
        }

        return array_values(array_unique($tokens));
    }

    protected function normalize_filename_token($candidate) {
        if (!is_string($candidate) || $candidate === '') {
            return '';
        }

        $candidate = wp_basename($candidate);
        $candidate = preg_replace('/[?#].*$/', '', $candidate);
        $candidate = rawurldecode((string) $candidate);
        $candidate = trim(strtolower((string) $candidate));

        return $candidate;
    }

    protected function normalize_size_variant_filename($filename) {
        $filename = $this->normalize_filename_token($filename);
        if ($filename === '') {
            return '';
        }

        $dot = strrpos($filename, '.');
        if ($dot === false) {
            return $filename;
        }

        $name = substr($filename, 0, $dot);
        $ext = substr($filename, $dot);
        $name = preg_replace('/-\d+x\d+$/', '', (string) $name);

        return $name . $ext;
    }

    protected function is_probable_media_filename($filename) {
        $filename = $this->normalize_filename_token($filename);
        if ($filename === '') {
            return false;
        }

        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }

        $allowed = array(
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp', 'tif', 'tiff',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'zip', 'rar', '7z', 'txt', 'csv',
            'mp3', 'wav', 'ogg', 'm4a',
            'mp4', 'mov', 'avi', 'webm', 'mkv'
        );

        return in_array($ext, $allowed, true);
    }
}

function area051_orphan_media_cleaner_bootstrap() {
    $plugin = new Area051_Orphan_Media_Cleaner();
    $plugin->init();
}

area051_orphan_media_cleaner_bootstrap();
