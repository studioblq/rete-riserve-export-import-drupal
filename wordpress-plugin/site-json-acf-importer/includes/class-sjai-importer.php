<?php

if (!defined('ABSPATH')) {
    exit;
}

class SJAI_Importer {
    const AREA051_MENU_SLUG = 'reti-riserve-suite';
    const OPTION_KEY = 'sjai_settings';
    const OPTION_SCHEMA_KEY = 'sjai_discovered_schema';
    const OPTION_PREVIEW_KEY = 'sjai_preview_samples';

    const NONCE_ACTION_SAVE = 'sjai_save_settings';
    const NONCE_ACTION_IMPORT_RAW = 'sjai_import_raw';
    const NONCE_ACTION_SAVE_MAPPING = 'sjai_save_mapping';
    const NONCE_ACTION_APPLY_MAPPING = 'sjai_apply_mapping';
    const NONCE_ACTION_CLEAN_DUPLICATES = 'sjai_cleanup_duplicates';

    // Guards to keep async batch requests under hosting limits.
    protected $sideload_deadline_ts = 0.0;
    protected $sideload_attempts_in_request = 0;
    protected $sideload_max_attempts_in_request = 0;
    protected $disable_sideload_in_request = false;
    protected $request_image_stats = array(
        'attempted' => 0,
        'success' => 0,
        'failed' => 0,
        'blocked' => 0,
        'reused' => 0,
    );

    protected function log_parent_debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[SJAI-PARENT] ' . $message);
        }
    }

    protected function log_import_debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[SJAI] ' . $message);
        }
    }

    public function init() {
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'maybe_save_settings'));
        add_action('admin_post_sjai_import_raw', array($this, 'handle_raw_import'));
        add_action('admin_post_sjai_save_mapping', array($this, 'handle_save_mapping'));
        add_action('admin_post_sjai_apply_mapping', array($this, 'handle_apply_mapping'));
        add_action('admin_post_sjai_cleanup_duplicates', array($this, 'handle_cleanup_duplicates'));
        add_action('wp_ajax_sjai_start_async_import', array($this, 'ajax_start_async_import'));
        add_action('wp_ajax_sjai_process_batch', array($this, 'ajax_process_batch'));
    }

    public function register_admin_page() {
        if (!isset($GLOBALS['admin_page_hooks'][self::AREA051_MENU_SLUG])) {
            add_menu_page(
                __('Area051 WP', 'site-json-acf-importer'),
                __('Area051 WP', 'site-json-acf-importer'),
                'manage_options',
                self::AREA051_MENU_SLUG,
                array($this, 'render_area051_hub_page'),
                'dashicons-admin-tools',
                58
            );
        }

        add_submenu_page(
            self::AREA051_MENU_SLUG,
            __('Site JSON Importer', 'site-json-acf-importer'),
            __('Site JSON Importer', 'site-json-acf-importer'),
            'manage_options',
            'site-json-acf-importer',
            array($this, 'render_admin_page')
        );
    }

    public function render_area051_hub_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Area051 WP', 'site-json-acf-importer') . '</h1>';
        echo '<p>' . esc_html__('Suite strumenti utili per il passaggio da Drupal a WordPress.', 'site-json-acf-importer') . '</p>';
        echo '<ul style="list-style:disc;padding-left:20px;max-width:900px;">';
        echo '<li><strong>' . esc_html__('Site JSON Importer', 'site-json-acf-importer') . '</strong>: '
            . esc_html__('importa contenuti da endpoint Drupal JSON verso WordPress con mapping campi, immagini, tassonomie e parent linking.', 'site-json-acf-importer')
            . '</li>';
        echo '<li><strong>' . esc_html__('Orphan Media Cleaner', 'site-json-acf-importer') . '</strong>: '
            . esc_html__('aiuta a ripulire i media orfani dopo migrazioni/reimport, liberando spazio su hosting.', 'site-json-acf-importer')
            . '</li>';
        echo '</ul>';
        echo '</div>';
    }

    public function maybe_save_settings() {
        if (!is_admin()) {
            return;
        }

        if (!isset($_POST['sjai_action']) || $_POST['sjai_action'] !== 'save_settings') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'site-json-acf-importer'));
        }

        check_admin_referer(self::NONCE_ACTION_SAVE);

        $settings = $this->get_settings();
        $settings['endpoint_url'] = isset($_POST['endpoint_url']) ? esc_url_raw(trim(wp_unslash($_POST['endpoint_url']))) : '';
        $settings['default_post_type'] = isset($_POST['default_post_type']) ? sanitize_key(wp_unslash($_POST['default_post_type'])) : 'post';
        $settings['default_status'] = isset($_POST['default_status']) ? sanitize_key(wp_unslash($_POST['default_status'])) : 'draft';
        $settings['import_images'] = !empty($_POST['import_images']) ? 1 : 0;
        $settings['auth_bearer_token'] = isset($_POST['auth_bearer_token']) ? sanitize_text_field(trim(wp_unslash($_POST['auth_bearer_token']))) : '';
        $settings['request_user_agent'] = isset($_POST['request_user_agent']) ? sanitize_text_field(trim(wp_unslash($_POST['request_user_agent']))) : 'Mozilla/5.0 (compatible; SiteJSONImporter/1.0; +WordPress)';
        $settings['enable_parent_linking'] = !empty($_POST['enable_parent_linking']) ? 1 : 0;
        $settings['parent_source_field'] = isset($_POST['parent_source_field']) ? trim(sanitize_text_field(wp_unslash($_POST['parent_source_field']))) : '';
        $settings['parent_match_field'] = isset($_POST['parent_match_field']) ? sanitize_key(trim(wp_unslash($_POST['parent_match_field']))) : 'nid';
        $settings['merge_reference_field'] = isset($_POST['merge_reference_field']) ? sanitize_key(trim(wp_unslash($_POST['merge_reference_field']))) : 'uuid';
        $settings['import_start_record'] = isset($_POST['import_start_record']) ? max(1, absint($_POST['import_start_record'])) : 1;
        $settings['import_end_record'] = isset($_POST['import_end_record']) ? absint($_POST['import_end_record']) : 0;
        $settings['import_max_items'] = isset($_POST['import_max_items']) ? absint($_POST['import_max_items']) : 0;

        if ($settings['import_end_record'] > 0 && $settings['import_end_record'] < $settings['import_start_record']) {
            $settings['import_end_record'] = $settings['import_start_record'];
        }

        if (!post_type_exists($settings['default_post_type'])) {
            $settings['default_post_type'] = 'post';
        }

        update_option(self::OPTION_KEY, $settings);

        $redirect = add_query_arg(
            array(
                'page' => 'site-json-acf-importer',
                'sjai_notice' => 'settings_saved',
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_raw_import() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'site-json-acf-importer'));
        }

        check_admin_referer(self::NONCE_ACTION_IMPORT_RAW);

        $result = $this->run_raw_import();
        set_transient('sjai_last_import_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS * 10);

        $redirect = add_query_arg(
            array(
                'page' => 'site-json-acf-importer',
                'sjai_notice' => 'raw_import_done',
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_save_mapping() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'site-json-acf-importer'));
        }

        check_admin_referer(self::NONCE_ACTION_SAVE_MAPPING);

        $settings = $this->get_settings();
        $post_types = get_post_types(array(), 'names');

        $raw_type_map = isset($_POST['type_map_ui']) && is_array($_POST['type_map_ui']) ? wp_unslash($_POST['type_map_ui']) : array();
        $type_map_ui = array();
        foreach ($raw_type_map as $source_type => $wp_post_type) {
            $source_key = sanitize_key($source_type);
            $target_key = sanitize_key($wp_post_type);
            if ($source_key === '') {
                continue;
            }
            if (!in_array($target_key, $post_types, true)) {
                $target_key = $settings['default_post_type'];
            }
            $type_map_ui[$source_key] = $target_key;
        }

        $raw_acf_map = isset($_POST['acf_map_ui']) && is_array($_POST['acf_map_ui']) ? wp_unslash($_POST['acf_map_ui']) : array();
        $acf_map_ui = array();
        foreach ($raw_acf_map as $source_type => $map) {
            $source_key = sanitize_key($source_type);
            if ($source_key === '' || !is_array($map)) {
                continue;
            }

            foreach ($map as $source_field => $acf_field_name) {
                $field_key = sanitize_key($source_field);
                $acf_name = sanitize_text_field($acf_field_name);
                if ($field_key === '' || $acf_name === '') {
                    continue;
                }
                if (!isset($acf_map_ui[$source_key])) {
                    $acf_map_ui[$source_key] = array();
                }
                $acf_map_ui[$source_key][$field_key] = $acf_name;
            }
        }

        $raw_acf_path_map = isset($_POST['acf_map_path_ui']) && is_array($_POST['acf_map_path_ui']) ? wp_unslash($_POST['acf_map_path_ui']) : array();
        $acf_map_path_ui = array();
        foreach ($raw_acf_path_map as $source_type => $map) {
            $source_key = sanitize_key($source_type);
            if ($source_key === '' || !is_array($map)) {
                continue;
            }

            foreach ($map as $source_field => $path) {
                $field_key = sanitize_key($source_field);
                if ($field_key === '') {
                    continue;
                }

                $path_value = is_string($path) ? trim(sanitize_text_field($path)) : '';
                if ($path_value !== '' && !preg_match('/^[A-Za-z0-9_\.\[\]\*]+$/', $path_value)) {
                    continue;
                }

                if (!isset($acf_map_path_ui[$source_key])) {
                    $acf_map_path_ui[$source_key] = array();
                }
                $acf_map_path_ui[$source_key][$field_key] = $path_value;
            }
        }

        $raw_field_map = isset($_POST['field_map_v2']) && is_array($_POST['field_map_v2']) ? wp_unslash($_POST['field_map_v2']) : array();
        $field_map_v2 = array();
        foreach ($raw_field_map as $source_type => $rows) {
            $source_key = sanitize_key($source_type);
            if ($source_key === '' || !is_array($rows)) {
                continue;
            }
            $clean_rows = array();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $wp_target = isset($row['wp']) ? sanitize_text_field(trim($row['wp'])) : '';
                if ($wp_target === '' || !preg_match('/^(wp:|yoast:|acf:)/', $wp_target)) {
                    continue;
                }
                $entry = array(
                    'wp'            => $wp_target,
                    'src'           => isset($row['src']) ? sanitize_key($row['src']) : '',
                    'path'          => '',
                    'img_alt_src'   => isset($row['img_alt_src']) ? sanitize_key($row['img_alt_src']) : '',
                    'img_alt_path'  => '',
                    'img_title_src' => isset($row['img_title_src']) ? sanitize_key($row['img_title_src']) : '',
                    'img_title_path'=> '',
                );
                foreach (array('path', 'img_alt_path', 'img_title_path') as $path_key) {
                    $p = isset($row[$path_key]) ? trim(sanitize_text_field($row[$path_key])) : '';
                    if ($p !== '' && preg_match('/^[A-Za-z0-9_\.\[\]\*]+$/', $p)) {
                        $entry[$path_key] = $p;
                    }
                }
                $clean_rows[] = $entry;
            }
            if (!empty($clean_rows)) {
                $field_map_v2[$source_key] = array_values($clean_rows);
            }
        }

        $settings['type_map_ui']    = $type_map_ui;
        $settings['acf_map_ui']     = $acf_map_ui;
        $settings['acf_map_path_ui']= $acf_map_path_ui;
        $settings['field_map_v2']   = $field_map_v2;
        update_option(self::OPTION_KEY, $settings);

        $redirect = add_query_arg(
            array(
                'page' => 'site-json-acf-importer',
                'sjai_notice' => 'mapping_saved',
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_apply_mapping() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'site-json-acf-importer'));
        }

        check_admin_referer(self::NONCE_ACTION_APPLY_MAPPING);

        $result = $this->run_apply_mapping();
        set_transient('sjai_last_mapping_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS * 10);

        $redirect = add_query_arg(
            array(
                'page' => 'site-json-acf-importer',
                'sjai_notice' => 'mapping_applied',
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_cleanup_duplicates() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'site-json-acf-importer'));
        }

        check_admin_referer(self::NONCE_ACTION_CLEAN_DUPLICATES);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $settings = $this->get_settings();
        $post_types = $this->get_import_target_post_types($settings);
        $duplicate_groups = $this->find_duplicate_groups_for_cleanup($post_types);

        if (empty($duplicate_groups)) {
            $redirect_none = add_query_arg(
                array(
                    'page' => 'site-json-acf-importer',
                    'sjai_notice' => 'duplicate_cleanup_none',
                ),
                admin_url('admin.php')
            );

            wp_safe_redirect($redirect_none);
            exit;
        }

        $deleted_posts = 0;
        $deleted_attachments = 0;
        $duplicate_groups_count = count($duplicate_groups);

        foreach ($duplicate_groups as $group) {
            if (!is_array($group) || empty($group['delete_ids']) || !is_array($group['delete_ids'])) {
                continue;
            }

            foreach ($group['delete_ids'] as $delete_id) {
                $delete_id = (int) $delete_id;
                if ($delete_id <= 0 || get_post($delete_id) === null) {
                    continue;
                }

                $attachment_ids = get_children(array(
                    'post_parent' => $delete_id,
                    'post_type' => 'attachment',
                    'post_status' => 'any',
                    'fields' => 'ids',
                    'numberposts' => -1,
                ));

                if (!empty($attachment_ids) && is_array($attachment_ids)) {
                    foreach ($attachment_ids as $attachment_id) {
                        $attachment_id = (int) $attachment_id;
                        if ($attachment_id <= 0) {
                            continue;
                        }

                        $attachment_deleted = wp_delete_attachment($attachment_id, true);
                        if ($attachment_deleted) {
                            $deleted_attachments++;
                        }
                    }
                }

                $post_deleted = wp_delete_post($delete_id, true);
                if ($post_deleted) {
                    $deleted_posts++;
                }
            }
        }

        $redirect = add_query_arg(
            array(
                'page' => 'site-json-acf-importer',
                'sjai_notice' => 'duplicate_cleanup_done',
                'duplicate_groups' => $duplicate_groups_count,
                'deleted_posts' => $deleted_posts,
                'deleted_attachments' => $deleted_attachments,
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $post_type_objects = get_post_types(array('public' => true), 'objects');
        $statuses = array(
            'draft' => __('Bozza', 'site-json-acf-importer'),
            'publish' => __('Pubblicato', 'site-json-acf-importer'),
            'pending' => __('In attesa', 'site-json-acf-importer'),
            'private' => __('Privato', 'site-json-acf-importer'),
        );
        $schema = $this->get_discovered_schema();
        $preview_samples = $this->get_preview_samples();
        $target_options = $this->get_target_field_options();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Site JSON Importer', 'site-json-acf-importer') . '</h1>';

        $this->render_notice();

        echo '<h2>' . esc_html__('Configurazione base', 'site-json-acf-importer') . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field(self::NONCE_ACTION_SAVE);
        echo '<input type="hidden" name="sjai_action" value="save_settings" />';

        echo '<table class="form-table" role="presentation">';

        echo '<tr>';
        echo '<th scope="row"><label for="endpoint_url">' . esc_html__('URL endpoint JSON', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<input name="endpoint_url" id="endpoint_url" type="url" class="regular-text" value="' . esc_attr($settings['endpoint_url']) . '" placeholder="https://example.com/it/site-json-export" required />';
        echo '<p class="description">' . esc_html__('Endpoint con struttura { meta, data[] }.', 'site-json-acf-importer') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="auth_bearer_token">' . esc_html__('Bearer token (opzionale)', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<input name="auth_bearer_token" id="auth_bearer_token" type="text" class="regular-text" value="' . esc_attr($settings['auth_bearer_token']) . '" />';
        echo '<p class="description">' . esc_html__('Se valorizzato, il plugin invia header Authorization: Bearer <token>.', 'site-json-acf-importer') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="request_user_agent">' . esc_html__('User-Agent richiesta', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<input name="request_user_agent" id="request_user_agent" type="text" class="regular-text" value="' . esc_attr($settings['request_user_agent']) . '" />';
        echo '<p class="description">' . esc_html__('Utile se firewall o CDN bloccano lo user-agent di default.', 'site-json-acf-importer') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="default_post_type">' . esc_html__('Post type di default', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<select name="default_post_type" id="default_post_type">';
        foreach ($post_type_objects as $post_type => $obj) {
            echo '<option value="' . esc_attr($post_type) . '" ' . selected($settings['default_post_type'], $post_type, false) . '>' . esc_html($obj->labels->singular_name) . ' (' . esc_html($post_type) . ')</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="default_status">' . esc_html__('Stato post', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<select name="default_status" id="default_status">';
        foreach ($statuses as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($settings['default_status'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Importa immagini', 'site-json-acf-importer') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="import_images" value="1" ' . checked(1, $settings['import_images'], false) . ' /> ' . esc_html__('Scarica immagini remote e salva anche ALT e TITLE negli attachment.', 'site-json-acf-importer') . '</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Assegna parent in secondo giro', 'site-json-acf-importer') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="enable_parent_linking" value="1" ' . checked(1, $settings['enable_parent_linking'], false) . ' /> ' . esc_html__('Se attivo, dopo l\'import il plugin esegue un secondo passaggio e valorizza post_parent.', 'site-json-acf-importer') . '</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="parent_source_field">' . esc_html__('Campo sorgente che contiene il parent', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<input name="parent_source_field" id="parent_source_field" type="text" class="regular-text" value="' . esc_attr($settings['parent_source_field']) . '" placeholder="field_contenuto_padre" />';
        echo '<p class="description">' . esc_html__('Nome del campo JSON sul contenuto figlio che contiene il valore identificativo del parent.', 'site-json-acf-importer') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="parent_match_field">' . esc_html__('Campo di merge per trovare il parent', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<input name="parent_match_field" id="parent_match_field" type="text" class="regular-text" value="' . esc_attr($settings['parent_match_field']) . '" placeholder="nid / uuid / field_custom_id" />';
        echo '<p class="description">' . esc_html__('Il valore del campo parent del figlio viene confrontato con questo campo sui contenuti importati (es: nid, uuid, o un campo custom).', 'site-json-acf-importer') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="merge_reference_field">' . esc_html__('Campo riferimento per update reimport', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<input name="merge_reference_field" id="merge_reference_field" type="text" class="regular-text" value="' . esc_attr($settings['merge_reference_field']) . '" placeholder="uuid / nid / field_custom_id" />';
        echo '<p class="description">' . esc_html__('Nel reimport il contenuto viene aggiornato (non aggiunto) se trova un match su questo campo.', 'site-json-acf-importer') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="import_start_record">' . esc_html__('Import: record da', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<input name="import_start_record" id="import_start_record" type="number" min="1" step="1" class="small-text" value="' . esc_attr((string) $settings['import_start_record']) . '" />';
        echo '<p class="description">' . esc_html__('Indice record iniziale (1 = primo elemento del data[]).', 'site-json-acf-importer') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="import_end_record">' . esc_html__('Import: record a', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<input name="import_end_record" id="import_end_record" type="number" min="0" step="1" class="small-text" value="' . esc_attr((string) $settings['import_end_record']) . '" />';
        echo '<p class="description">' . esc_html__('Indice record finale incluso (0 = fino all\'ultimo).', 'site-json-acf-importer') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="import_max_items">' . esc_html__('Import: massimo contenuti', 'site-json-acf-importer') . '</label></th>';
        echo '<td>';
        echo '<input name="import_max_items" id="import_max_items" type="number" min="0" step="1" class="small-text" value="' . esc_attr((string) $settings['import_max_items']) . '" />';
        echo '<p class="description">' . esc_html__('Numero massimo record da processare (0 = nessun limite).', 'site-json-acf-importer') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';
        submit_button(__('Salva impostazioni', 'site-json-acf-importer'));
        echo '</form>';

        echo '<hr />';
        echo '<h2>' . esc_html__('Step 1: Analisi struttura JSON', 'site-json-acf-importer') . '</h2>';
        echo '<p>' . esc_html__('Legge solo il JSON e rileva tipi/campi disponibili per il mapping UI. Non crea o aggiorna contenuti WordPress.', 'site-json-acf-importer') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION_IMPORT_RAW);
        echo '<input type="hidden" name="action" value="sjai_import_raw" />';
        submit_button(__('Analizza JSON', 'site-json-acf-importer'), 'primary', 'submit', false);
        echo '</form>';

        echo '<hr />';
        echo '<h2>' . esc_html__('Step 2: Mapping UI e import reale', 'site-json-acf-importer') . '</h2>';

        if (empty($schema['types'])) {
            echo '<p><em>' . esc_html__('Nessuno schema rilevato. Esegui prima lo Step 1.', 'site-json-acf-importer') . '</em></p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::NONCE_ACTION_SAVE_MAPPING);
            echo '<input type="hidden" name="action" value="sjai_save_mapping" />';

            echo '<h3>' . esc_html__('Mapping tipi sorgente -> post type WordPress', 'site-json-acf-importer') . '</h3>';
            echo '<table class="widefat striped" style="max-width:1000px">';
            echo '<thead><tr><th>' . esc_html__('Tipo sorgente', 'site-json-acf-importer') . '</th><th>' . esc_html__('Post type WordPress', 'site-json-acf-importer') . '</th></tr></thead><tbody>';

            foreach ($schema['types'] as $source_type => $fields) {
                $selected_post_type = isset($settings['type_map_ui'][$source_type]) ? $settings['type_map_ui'][$source_type] : $settings['default_post_type'];
                echo '<tr>';
                echo '<td><strong>' . esc_html($source_type) . '</strong></td>';
                echo '<td><select name="type_map_ui[' . esc_attr($source_type) . ']">';
                foreach ($post_type_objects as $post_type => $obj) {
                    echo '<option value="' . esc_attr($post_type) . '" ' . selected($selected_post_type, $post_type, false) . '>' . esc_html($obj->labels->singular_name) . ' (' . esc_html($post_type) . ')</option>';
                }
                echo '</select></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            $this->render_field_map_section($schema, $settings, $target_options);

            submit_button(__('Salva mapping UI', 'site-json-acf-importer'));
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
            wp_nonce_field(self::NONCE_ACTION_APPLY_MAPPING);
            echo '<input type="hidden" name="action" value="sjai_apply_mapping" />';
            submit_button(__('Importa dati (sync)', 'site-json-acf-importer'), 'secondary', 'submit', false);
            echo '</form>';

            echo '<div style="margin-top:10px;">';
            wp_nonce_field('sjai_async_import_nonce', 'sjai_async_import_nonce_field');
            echo '<button type="button" class="button button-primary" id="sjai-async-import-btn" style="margin-top:10px;">' . esc_html__('Importa dati (asincrono)', 'site-json-acf-importer') . '</button>';
            echo '</div>';

            echo '<div style="margin-top:12px;max-width:1100px;border:1px solid #dcdcde;background:#fff;padding:12px;border-radius:4px;">';
            echo '<h3 style="margin-top:0;">' . esc_html__('Pulizia duplicati importati', 'site-json-acf-importer') . '</h3>';
            echo '<p style="margin:0 0 10px;">' . esc_html__('Rimuove i contenuti importati duplicati trovati tramite UUID/NID o identità sorgente e cancella anche gli allegati figli del duplicato.', 'site-json-acf-importer') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('Confermi la rimozione dei duplicati importati e dei loro allegati? Questa operazione è distruttiva.', 'site-json-acf-importer')) . '\');">';
            wp_nonce_field(self::NONCE_ACTION_CLEAN_DUPLICATES);
            echo '<input type="hidden" name="action" value="sjai_cleanup_duplicates" />';
            echo '<button type="submit" class="button button-secondary">' . esc_html__('Elimina doppi importati', 'site-json-acf-importer') . '</button>';
            echo '</form>';
            echo '</div>';

            echo '<div id="sjai-progress-container" style="margin-top:15px;border:1px solid #ccd0d4;background:#f9f9f9;padding:15px;max-width:1100px;display:none;border-radius:4px;">';
            echo '<h3 style="margin-top:0;margin-bottom:15px;color:#333;">' . esc_html__('Progresso Import', 'site-json-acf-importer') . '</h3>';
            echo '<p id="sjai-progress-text" style="margin:0 0 10px;font-weight:bold;color:#333;"></p>';
            echo '<div style="width:100%;height:32px;border:1px solid #ddd;background:#fff;position:relative;border-radius:3px;overflow:hidden;">';
            echo '<div id="sjai-progress-bar" style="height:100%;background:linear-gradient(90deg, #4CAF50, #45a049);width:0%;transition:width 0.3s ease;display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:13px;box-shadow:inset 0 1px 0 rgba(255,255,255,0.3);"></div>';
            echo '</div>';
            echo '<p id="sjai-progress-stats" style="margin:10px 0;font-size:12px;color:#666;"></p>';
            echo '<div id="sjai-progress-errors" style="margin:10px 0;padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:3px;color:#856404;font-size:12px;display:none;"></div>';
            echo '<div id="sjai-progress-result" style="margin-top:12px;padding:12px;background:#d4edda;border:1px solid #28a745;border-radius:3px;color:#155724;font-size:12px;line-height:1.6;display:none;"></div>';
            echo '<button type="button" class="button" style="margin-top:12px;font-size:12px;" onclick="clearImportSession()">Cancella sessione salvata</button>';
            echo '</div>';

            if (!empty($preview_samples['by_type'])) {
                $preview_samples_json = wp_json_encode($preview_samples['by_type'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($preview_samples_json)) {
                    $preview_samples_json = '{}';
                }
                // Prevent accidental </script> breaks from data payload.
                $preview_samples_json = str_replace('</', '<\\/', $preview_samples_json);

                echo '<div id="sjai-preview-panel" style="margin-top:15px;border:1px solid #ccd0d4;background:#fff;padding:12px;max-width:1100px;display:none;">';
                echo '<h3 style="margin-top:0;">' . esc_html__('Anteprima valori da JSON di test', 'site-json-acf-importer') . '</h3>';
                echo '<p id="sjai-preview-meta" class="description" style="margin-top:0;"></p>';
                echo '<p>';
                echo '<button type="button" class="button" id="sjai-preview-prev">' . esc_html__('Precedente', 'site-json-acf-importer') . '</button> ';
                echo '<button type="button" class="button" id="sjai-preview-next">' . esc_html__('Successivo', 'site-json-acf-importer') . '</button> ';
                echo '<label for="sjai-preview-record-index" style="margin-left:10px;">' . esc_html__('Record', 'site-json-acf-importer') . '</label> ';
                echo '<input type="number" min="1" step="1" id="sjai-preview-record-index" style="width:90px;vertical-align:middle;" /> ';
                echo '<button type="button" class="button" id="sjai-preview-go">' . esc_html__('Vai', 'site-json-acf-importer') . '</button> ';
                echo '<button type="button" class="button" id="sjai-preview-close">' . esc_html__('Chiudi', 'site-json-acf-importer') . '</button>';
                echo '</p>';
                echo '<pre id="sjai-preview-value" style="background:#f8f9fa;border:1px solid #e0e0e0;padding:12px;overflow:auto;max-height:360px;"></pre>';
                echo '</div>';

                echo '<script>';
                echo 'window.sjaiPreviewSamples = ' . $preview_samples_json . ';';
                echo '(function(){';
                echo 'var panel=document.getElementById("sjai-preview-panel");';
                echo 'var meta=document.getElementById("sjai-preview-meta");';
                echo 'var valueBox=document.getElementById("sjai-preview-value");';
                echo 'var prevBtn=document.getElementById("sjai-preview-prev");';
                echo 'var nextBtn=document.getElementById("sjai-preview-next");';
                echo 'var recordInput=document.getElementById("sjai-preview-record-index");';
                echo 'var goBtn=document.getElementById("sjai-preview-go");';
                echo 'var closeBtn=document.getElementById("sjai-preview-close");';
                echo 'var state={type:"",field:"",pathSelectId:"",index:0};';
                echo 'function tokens(path){var m=path.match(/(\\[\\*\\]|\\[\\d+\\]|[^\\.\\[\\]]+)/g);return m||[];}';
                echo 'function extractBySegments(value,segments,idx){if(idx>=segments.length){return value;}var seg=segments[idx];if(seg==="[*]"){if(!Array.isArray(value)){return null;}var out=[];for(var i=0;i<value.length;i++){var ex=extractBySegments(value[i],segments,idx+1);if(ex===null||ex===""||(Array.isArray(ex)&&ex.length===0)){continue;}out.push(ex);}return out;}var m=seg.match(/^\\[(\\d+)\\]$/);if(m){var pos=parseInt(m[1],10);if(!Array.isArray(value)||!(pos in value)){return null;}return extractBySegments(value[pos],segments,idx+1);}if(!value||typeof value!=="object"||!(seg in value)){return null;}return extractBySegments(value[seg],segments,idx+1);}';
                echo 'function extractByPath(value,path){if(!path){return value;}var segs=tokens(path);if(!segs.length){return value;}return extractBySegments(value,segs,0);}';
                echo 'function currentPath(){var el=document.getElementById(state.pathSelectId);return el?el.value:"";}';
                echo 'function render(){var all=(window.sjaiPreviewSamples&&window.sjaiPreviewSamples[state.type])?window.sjaiPreviewSamples[state.type]:[];if(!all.length){meta.textContent="Nessun record di test disponibile per questo tipo.";valueBox.textContent="";if(recordInput){recordInput.value="";}prevBtn.disabled=true;nextBtn.disabled=true;return;}if(state.index<0){state.index=0;}if(state.index>=all.length){state.index=all.length-1;}var item=all[state.index]||{};var shown=(item===null||typeof item==="undefined")?"(nessun valore)":JSON.stringify(item,null,2);meta.textContent="Tipo: "+state.type+" | Campo: "+state.field+" | Record "+(state.index+1)+" di "+all.length;valueBox.textContent=shown;if(recordInput){recordInput.value=String(state.index+1);}prevBtn.disabled=(state.index<=0);nextBtn.disabled=(state.index>=all.length-1);}';
                echo 'function openPreview(type,field,pathSelectId){state.type=type;state.field=field;state.pathSelectId=pathSelectId;state.index=0;panel.style.display="block";render();}';
                echo 'window.sjaiOpenPreview=openPreview;';
                echo 'function goToRecord(){var all=(window.sjaiPreviewSamples&&window.sjaiPreviewSamples[state.type])?window.sjaiPreviewSamples[state.type]:[];if(!all.length||!recordInput){return;}var wanted=parseInt(recordInput.value,10);if(!Number.isFinite(wanted)){wanted=1;}if(wanted<1){wanted=1;}if(wanted>all.length){wanted=all.length;}state.index=wanted-1;render();}';
                echo 'document.querySelectorAll(".sjai-preview-open").forEach(function(btn){btn.addEventListener("click",function(){openPreview(btn.getAttribute("data-sjai-type")||"",btn.getAttribute("data-sjai-field")||"",btn.getAttribute("data-sjai-path-select-id")||"");});});';
                echo 'document.querySelectorAll("select[name^=\"acf_map_path_ui[\"]").forEach(function(sel){sel.addEventListener("change",function(){if(panel.style.display!=="none"){render();}});});';
                echo 'prevBtn.addEventListener("click",function(){state.index--;render();});';
                echo 'nextBtn.addEventListener("click",function(){state.index++;render();});';
                echo 'if(goBtn){goBtn.addEventListener("click",goToRecord);}';
                echo 'if(recordInput){recordInput.addEventListener("keydown",function(e){if(e.key==="Enter"){goToRecord();}});}';
                echo 'closeBtn.addEventListener("click",function(){panel.style.display="none";});';
                echo '})();';
                echo '</script>';
            }

            $this->render_field_map_js();
            $this->render_async_import_js();
        }

        $last_result = get_transient('sjai_last_import_result_' . get_current_user_id());
        if (is_array($last_result)) {
            echo '<h3>' . esc_html__('Ultimo risultato analisi JSON', 'site-json-acf-importer') . '</h3>';
            echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1100px;overflow:auto;">' . esc_html(wp_json_encode($last_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }

        $last_mapping_result = get_transient('sjai_last_mapping_result_' . get_current_user_id());
        if (is_array($last_mapping_result)) {
            echo '<h3>' . esc_html__('Ultimo risultato import reale', 'site-json-acf-importer') . '</h3>';
            echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1100px;overflow:auto;">' . esc_html(wp_json_encode($last_mapping_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }

        echo '</div>';
    }

    protected function render_notice() {
        if (empty($_GET['sjai_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['sjai_notice']));
        if ($notice === 'settings_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Impostazioni salvate.', 'site-json-acf-importer') . '</p></div>';
        }

        if ($notice === 'raw_import_done') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Step 1 completato: analisi struttura JSON eseguita.', 'site-json-acf-importer') . '</p></div>';
        }

        if ($notice === 'mapping_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Mapping UI salvato.', 'site-json-acf-importer') . '</p></div>';
        }

        if ($notice === 'mapping_applied') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Step 2 completato: import reale con mapping eseguito.', 'site-json-acf-importer') . '</p></div>';
        }

        if ($notice === 'duplicate_cleanup_done') {
            $deleted_posts = isset($_GET['deleted_posts']) ? absint($_GET['deleted_posts']) : 0;
            $deleted_attachments = isset($_GET['deleted_attachments']) ? absint($_GET['deleted_attachments']) : 0;
            $duplicate_groups = isset($_GET['duplicate_groups']) ? absint($_GET['duplicate_groups']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Pulizia duplicati completata. Gruppi: %d. Post eliminati: %d. Allegati eliminati: %d.', 'site-json-acf-importer'), $duplicate_groups, $deleted_posts, $deleted_attachments)) . '</p></div>';
        }

        if ($notice === 'duplicate_cleanup_none') {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Nessun duplicato importato trovato.', 'site-json-acf-importer') . '</p></div>';
        }
    }

    protected function get_settings() {
        $defaults = array(
            'endpoint_url' => '',
            'default_post_type' => 'post',
            'default_status' => 'draft',
            'import_images' => 0,
            'auth_bearer_token' => '',
            'request_user_agent' => 'Mozilla/5.0 (compatible; SiteJSONImporter/1.0; +WordPress)',
            'enable_parent_linking' => 0,
            'parent_source_field' => '',
            'parent_match_field' => 'nid',
            'merge_reference_field' => 'uuid',
            'import_start_record' => 1,
            'import_end_record' => 0,
            'import_max_items' => 0,
            'type_map_ui' => array(),
            'acf_map_ui' => array(),
            'acf_map_path_ui' => array(),
            'field_map_v2' => array(),
        );

        $saved = get_option(self::OPTION_KEY, array());
        $settings = wp_parse_args($saved, $defaults);

        if (!is_array($settings['type_map_ui'])) {
            $settings['type_map_ui'] = array();
        }

        if (!is_array($settings['acf_map_ui'])) {
            $settings['acf_map_ui'] = array();
        }

        if (!is_array($settings['acf_map_path_ui'])) {
            $settings['acf_map_path_ui'] = array();
        }

        if (!is_array($settings['field_map_v2'])) {
            $settings['field_map_v2'] = array();
        }

        return $settings;
    }

    protected function get_discovered_schema() {
        $schema = get_option(self::OPTION_SCHEMA_KEY, array());
        if (!is_array($schema) || !isset($schema['types']) || !is_array($schema['types'])) {
            return array('types' => array(), 'field_paths' => array());
        }

        foreach ($schema['types'] as $type => $fields) {
            if (!is_array($fields)) {
                unset($schema['types'][$type]);
                continue;
            }
            $fields = array_values(array_unique(array_map('sanitize_key', $fields)));
            $fields = array_values(array_filter($fields));
            sort($fields);
            $schema['types'][$type] = $fields;
        }

        $field_paths = array();
        if (isset($schema['field_paths']) && is_array($schema['field_paths'])) {
            foreach ($schema['field_paths'] as $type => $fields_map) {
                $type_key = sanitize_key($type);
                if ($type_key === '' || !is_array($fields_map)) {
                    continue;
                }

                foreach ($fields_map as $field => $paths) {
                    $field_key = sanitize_key($field);
                    if ($field_key === '' || !is_array($paths)) {
                        continue;
                    }

                    $valid_paths = array();
                    foreach ($paths as $path) {
                        $path_value = is_string($path) ? trim($path) : '';
                        if ($path_value === '' || !preg_match('/^[A-Za-z0-9_\.\[\]\*]+$/', $path_value)) {
                            continue;
                        }
                        $valid_paths[] = $path_value;
                    }

                    $valid_paths = array_values(array_unique($valid_paths));
                    sort($valid_paths);

                    if (!isset($field_paths[$type_key])) {
                        $field_paths[$type_key] = array();
                    }
                    $field_paths[$type_key][$field_key] = $valid_paths;
                }
            }
        }

        $schema['field_paths'] = $field_paths;

        return $schema;
    }

    protected function get_preview_samples() {
        $preview = get_option(self::OPTION_PREVIEW_KEY, array());
        if (!is_array($preview)) {
            return array('by_type' => array(), 'updated_at' => 0);
        }

        $by_type = array();
        if (isset($preview['by_type']) && is_array($preview['by_type'])) {
            foreach ($preview['by_type'] as $type => $items) {
                $type_key = sanitize_key($type);
                if ($type_key === '' || !is_array($items)) {
                    continue;
                }

                $clean_items = array();
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $clean_items[] = $item;
                    }
                }
                $by_type[$type_key] = $clean_items;
            }
        }

        return array(
            'by_type' => $by_type,
            'updated_at' => isset($preview['updated_at']) ? (int) $preview['updated_at'] : 0,
        );
    }

    protected function run_raw_import() {
        $settings = $this->get_settings();
        $payload = $this->fetch_endpoint_data($settings['endpoint_url']);

        if (!$payload['ok']) {
            return $payload;
        }

        $data = $payload['data'];
        $skipped = 0;
        $schema_types = array();
        $schema_field_paths = array();
        $preview_by_type = array();
        $preview_limit_per_type = 0;
        $analyzed = 0;

        foreach ($data as $index => $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $analyzed++;
            $source_type = isset($item['type']) ? sanitize_key($item['type']) : 'unknown';
            if ($source_type === '') {
                $source_type = 'unknown';
            }

            if (!isset($schema_types[$source_type])) {
                $schema_types[$source_type] = array();
            }

            if (!isset($preview_by_type[$source_type])) {
                $preview_by_type[$source_type] = array();
            }
            if ($preview_limit_per_type <= 0 || count($preview_by_type[$source_type]) < $preview_limit_per_type) {
                $preview_by_type[$source_type][] = $item;
            }

            foreach ($item as $key => $value) {
                $field_key = sanitize_key($key);
                if ($field_key === '') {
                    continue;
                }
                if (!in_array($field_key, $schema_types[$source_type], true)) {
                    $schema_types[$source_type][] = $field_key;
                }

                $paths = $this->discover_value_paths($value);
                if (!isset($schema_field_paths[$source_type])) {
                    $schema_field_paths[$source_type] = array();
                }
                if (!isset($schema_field_paths[$source_type][$field_key])) {
                    $schema_field_paths[$source_type][$field_key] = array();
                }
                foreach ($paths as $path) {
                    if (!in_array($path, $schema_field_paths[$source_type][$field_key], true)) {
                        $schema_field_paths[$source_type][$field_key][] = $path;
                    }
                }
            }

        }


        foreach ($schema_field_paths as $type => $fields_map) {
            foreach ($fields_map as $field => $paths) {
                $paths = array_values(array_unique($paths));
                sort($paths);
                $schema_field_paths[$type][$field] = $paths;
            }
        }
        update_option(self::OPTION_SCHEMA_KEY, array(
            'types' => $schema_types,
            'field_paths' => $schema_field_paths,
        ));
        update_option(self::OPTION_PREVIEW_KEY, array(
            'by_type' => $preview_by_type,
            'updated_at' => time(),
        ));
        return array(
            'ok' => true,
            'mode' => 'schema_discovery',
            'analyzed' => $analyzed,
            'skipped' => $skipped,
            'types_detected' => array_keys($schema_types),
        );
    }

    protected function assign_parents_second_pass($post_ids, $settings) {
        $result = array(
            'enabled' => true,
            'linked' => 0,
            'skipped' => 0,
            'errors' => array(),
            'multi_parent' => array(),
        );

        $source_field = isset($settings['parent_source_field']) ? trim((string) $settings['parent_source_field']) : '';
        $match_field = isset($settings['parent_match_field']) ? sanitize_key($settings['parent_match_field']) : 'nid';

        $this->log_parent_debug('Second pass started. source_field=' . $source_field . ' match_field=' . $match_field . ' total_post_ids=' . count((array) $post_ids));

        if ($source_field === '') {
            $result['errors'][] = 'Campo sorgente parent non configurato.';
            return $result;
        }

        // Build a nid->item map by fetching fresh data from the Drupal endpoint.
        // This avoids relying on _sjai_raw_item which may be missing or corrupt.
        $drupal_nid_map = array();
        $endpoint_url = isset($settings['endpoint_url']) ? trim((string) $settings['endpoint_url']) : '';
        if ($endpoint_url !== '') {
            $payload = $this->fetch_endpoint_data($endpoint_url);
            if (is_array($payload) && !empty($payload['ok']) && is_array($payload['data'])) {
                foreach ($payload['data'] as $drupal_item) {
                    if (!is_array($drupal_item)) {
                        continue;
                    }
                    $item_nid = isset($drupal_item['nid']) ? (string) absint($drupal_item['nid']) : '';
                    if ($item_nid !== '' && $item_nid !== '0') {
                        $drupal_nid_map[$item_nid] = $drupal_item;
                    }
                }
                $this->log_parent_debug('Fetched endpoint: ' . count($drupal_nid_map) . ' items in nid map');
            } else {
                $this->log_parent_debug('Endpoint fetch failed: ' . (isset($payload['message']) ? $payload['message'] : 'unknown'));
            }
        }

        foreach ((array) $post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                $result['skipped']++;
                continue;
            }

            // Look up the Drupal item via the stored nid meta.
            $item = null;
            $post_nid = get_post_meta($post_id, '_sjai_source_nid', true);
            $post_nid_str = $post_nid !== '' && $post_nid !== false ? (string) absint($post_nid) : '';

            if ($post_nid_str !== '' && isset($drupal_nid_map[$post_nid_str])) {
                $item = $drupal_nid_map[$post_nid_str];
            }

            // Fallback: try to decode _sjai_raw_item if endpoint lookup failed.
            if (!is_array($item)) {
                $raw = get_post_meta($post_id, '_sjai_raw_item', true);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $item = $decoded;
                    }
                }
            }

            if (!is_array($item)) {
                $result['skipped']++;
                $this->log_parent_debug('Post ' . $post_id . ' (nid=' . $post_nid_str . ') skipped: item not found in endpoint or raw_item');
                continue;
            }

            $parent_value = array_key_exists($source_field, $item) ? $item[$source_field] : $this->extract_value_by_path($item, $source_field);
            $parent_references = $this->extract_all_parent_references($parent_value);
            $this->log_parent_debug('Post ' . $post_id . ' (nid=' . $post_nid_str . ') parent_value=' . wp_json_encode($parent_value) . ' refs=' . wp_json_encode($parent_references));

            if (empty($parent_references)) {
                $result['skipped']++;
                continue;
            }

            $first_parent_post_id = 0;
            $all_parent_post_ids = array();

            foreach ($parent_references as $ref) {
                $found = $this->find_parent_post_id_by_merge_field($match_field, $ref);
                $this->log_parent_debug('Post ' . $post_id . ' ref=' . (string) $ref . ' found=' . (int) $found);
                if ($found > 0 && $found !== $post_id) {
                    $all_parent_post_ids[] = $found;
                    if ($first_parent_post_id === 0) {
                        $first_parent_post_id = $found;
                    }
                }
            }

            if ($first_parent_post_id <= 0) {
                $result['errors'][] = sprintf('Post %d (nid=%s): parent non trovato per riferimenti %s', $post_id, $post_nid_str, implode(', ', $parent_references));
                $result['skipped']++;
                $this->log_parent_debug('Post ' . $post_id . ' parent not found for refs=' . implode(',', $parent_references));
                continue;
            }

            $update = wp_update_post(array(
                'ID' => $post_id,
                'post_parent' => $first_parent_post_id,
            ), true);

            if (is_wp_error($update)) {
                $result['errors'][] = sprintf('Post %d: %s', $post_id, $update->get_error_message());
                $this->log_parent_debug('Post ' . $post_id . ' wp_update_post error: ' . $update->get_error_message());
                continue;
            }

            if (function_exists('update_field')) {
                update_field($source_field, $first_parent_post_id, $post_id);
            } else {
                update_post_meta($post_id, $source_field, $first_parent_post_id);
            }

            update_post_meta($post_id, '_sjai_parent_wp_id', $first_parent_post_id);
            update_post_meta($post_id, '_sjai_parent_ids', $all_parent_post_ids);
            $this->log_parent_debug('Post ' . $post_id . ' linked to parent_wp_id=' . $first_parent_post_id);

            if (count($all_parent_post_ids) > 1) {
                $result['multi_parent'][] = array(
                    'post_id' => $post_id,
                    'parents' => $all_parent_post_ids,
                );
            }

            $result['linked']++;
        }

        return $result;
    }

    protected function find_parent_post_id_by_merge_field($merge_field, $merge_value) {
        $merge_field = sanitize_key((string) $merge_field);
        $raw_value = trim((string) $merge_value);
        if ($merge_field === '' || $raw_value === '') {
            return 0;
        }

        $meta_keys = array();
        if ($merge_field === 'uuid') {
            $meta_keys = array('_sjai_source_uuid', '_sjai_src_uuid');
        } elseif ($merge_field === 'nid') {
            $meta_keys = array('_sjai_source_nid', '_sjai_src_nid');
        } else {
            $meta_keys = array('_sjai_src_' . $merge_field, '_sjai_source_' . $merge_field);
        }

        $meta_values = array($raw_value);
        if (is_numeric($raw_value)) {
            $abs = (string) absint($raw_value);
            if ($abs !== '' && !in_array($abs, $meta_values, true)) {
                $meta_values[] = $abs;
            }
        }

        foreach ($meta_keys as $meta_key) {
            if (!is_string($meta_key) || $meta_key === '') {
                continue;
            }

            foreach ($meta_values as $meta_value) {
                $posts = get_posts(array(
                    'post_type' => 'any',
                    'post_status' => 'any',
                    'fields' => 'ids',
                    'posts_per_page' => 1,
                    'meta_key' => $meta_key,
                    'meta_value' => $meta_value,
                ));

                if (!empty($posts)) {
                    $this->log_parent_debug('Parent match hit meta_key=' . $meta_key . ' value=' . (string) $meta_value . ' -> post_id=' . (int) $posts[0]);
                    return (int) $posts[0];
                }
            }
        }

        $this->log_parent_debug('Parent match miss merge_field=' . $merge_field . ' value=' . $raw_value . ' meta_keys=' . implode(',', $meta_keys));

        return 0;
    }

    protected function run_apply_mapping() {
        $settings = $this->get_settings();
        $acf_map      = is_array($settings['acf_map_ui'])      ? $settings['acf_map_ui']      : array();
        $acf_path_map = is_array($settings['acf_map_path_ui']) ? $settings['acf_map_path_ui'] : array();
        $field_map_v2 = is_array($settings['field_map_v2'])    ? $settings['field_map_v2']    : array();
        $type_map     = is_array($settings['type_map_ui'])     ? $settings['type_map_ui']     : array();

        $payload = $this->fetch_endpoint_data($settings['endpoint_url']);
        if (!$payload['ok']) {
            return $payload;
        }

        $data = $payload['data'];

        $start_record = isset($settings['import_start_record']) ? max(1, (int) $settings['import_start_record']) : 1;
        $end_record = isset($settings['import_end_record']) ? max(0, (int) $settings['import_end_record']) : 0;
        $max_items = isset($settings['import_max_items']) ? max(0, (int) $settings['import_max_items']) : 0;

        if ($end_record > 0 && $end_record < $start_record) {
            $end_record = $start_record;
        }

        $processed = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();
        $imported_post_ids = array();

        foreach ($data as $index => $item) {
            $record_number = (int) $index + 1;

            if ($record_number < $start_record) {
                continue;
            }

            if ($end_record > 0 && $record_number > $end_record) {
                continue;
            }

            if ($max_items > 0 && $processed >= $max_items) {
                break;
            }

            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $processed++;

            $result = $this->import_single_item($item, $settings, $type_map, $acf_map, $acf_path_map, $field_map_v2, true);
            if ($result['status'] === 'created') {
                $created++;
                if (!empty($result['post_id'])) {
                    $imported_post_ids[] = (int) $result['post_id'];
                }
            } elseif ($result['status'] === 'updated') {
                $updated++;
                if (!empty($result['post_id'])) {
                    $imported_post_ids[] = (int) $result['post_id'];
                }
            } elseif ($result['status'] === 'skipped') {
                $skipped++;
            } else {
                $errors[] = array(
                    'index' => $index,
                    'message' => isset($result['message']) ? $result['message'] : 'Errore import.',
                );
                $skipped++;
            }
        }

        $parent_result = array(
            'enabled' => false,
            'linked' => 0,
            'skipped' => 0,
            'errors' => array(),
            'multi_parent' => array(),
        );

        if (!empty($settings['enable_parent_linking'])) {
            $parent_result = $this->assign_parents_second_pass(array_values(array_unique($imported_post_ids)), $settings);
        }

        return array(
            'ok' => true,
            'mode' => 'real_import',
            'range' => array(
                'start_record' => $start_record,
                'end_record' => $end_record,
                'max_items' => $max_items,
            ),
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'parent_pass' => $parent_result,
            'errors' => $errors,
        );
    }

    protected function fetch_endpoint_data($endpoint_url) {
        if (empty($endpoint_url)) {
            return array(
                'ok' => false,
                'message' => 'Endpoint URL mancante.',
            );
        }

        $settings = $this->get_settings();
        $headers = array(
            'Accept' => 'application/json',
        );

        if (!empty($settings['auth_bearer_token'])) {
            $headers['Authorization'] = 'Bearer ' . $settings['auth_bearer_token'];
        }

        $response = wp_remote_get($endpoint_url, array(
            'timeout' => 20,
            'headers' => $headers,
            'user-agent' => !empty($settings['request_user_agent']) ? $settings['request_user_agent'] : 'Mozilla/5.0 (compatible; SiteJSONImporter/1.0; +WordPress)',
        ));

        if (is_wp_error($response)) {
            return array(
                'ok' => false,
                'message' => $response->get_error_message(),
            );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            return array(
                'ok' => false,
                'message' => 'HTTP status non valido: ' . $status,
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return array(
                'ok' => false,
                'message' => 'Formato JSON non valido: atteso oggetto con chiave data[].',
            );
        }

        return array(
            'ok' => true,
            'data' => $decoded['data'],
            'meta' => isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : array(),
        );
    }

    protected function import_single_item($item, $settings, $type_map, $acf_map, $acf_path_map, $field_map_v2, $apply_mapping) {
        $source_type = isset($item['type']) ? sanitize_key($item['type']) : '';
        $post_type = $settings['default_post_type'];

        if ($source_type !== '' && isset($type_map[$source_type])) {
            $candidate = sanitize_key($type_map[$source_type]);
            if (post_type_exists($candidate)) {
                $post_type = $candidate;
            }
        }

        if (!post_type_exists($post_type)) {
            return array(
                'status' => 'error',
                'message' => 'Post type non esistente: ' . $post_type,
            );
        }

        $uuid = isset($item['uuid']) ? sanitize_text_field((string) $item['uuid']) : '';
        $nid = isset($item['nid']) ? absint($item['nid']) : 0;
        $langcode = isset($item['langcode']) ? sanitize_key($item['langcode']) : '';

        $reference_field = isset($settings['merge_reference_field']) ? sanitize_key($settings['merge_reference_field']) : 'uuid';
        $existing_post_id = $this->find_existing_post_by_reference($item, $reference_field, $post_type);
        if (!$existing_post_id) {
            $existing_post_id = $this->find_existing_post($uuid, $nid, $post_type);
        }
        if (!$existing_post_id) {
            $existing_post_id = $this->find_existing_post_by_source_identity($item, $post_type);
        }

        $title = $this->extract_scalar_value(isset($item['title']) ? $item['title'] : '');
        if ($title === '') {
            $title = sprintf(__('Import item %d', 'site-json-acf-importer'), $nid ?: 0);
        }

        $content = '';
        if (isset($item['body'])) {
            $content = $this->extract_body_value($item['body']);
        }

        $excerpt = '';
        if (isset($item['summary'])) {
            $excerpt = $this->extract_scalar_value($item['summary']);
        }

        $postarr = array(
            'post_type' => $post_type,
            'post_status' => $settings['default_status'],
            'post_title' => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_excerpt' => $excerpt,
        );

        if ($existing_post_id) {
            $postarr['ID'] = $existing_post_id;
            $post_id = wp_update_post($postarr, true);
            $action = 'updated';
        } else {
            $post_id = wp_insert_post($postarr, true);
            $action = 'created';
        }

        if (is_wp_error($post_id)) {
            return array(
                'status' => 'error',
                'message' => $post_id->get_error_message(),
            );
        }

        if ($uuid !== '') {
            update_post_meta($post_id, '_sjai_source_uuid', $uuid);
        }
        if ($nid > 0) {
            update_post_meta($post_id, '_sjai_source_nid', $nid);
        }
        if ($source_type !== '') {
            update_post_meta($post_id, '_sjai_source_type', $source_type);
        }
        if ($langcode !== '') {
            update_post_meta($post_id, '_sjai_source_langcode', $langcode);
        }

        $this->store_source_index_meta($post_id, $item);

        update_post_meta($post_id, '_sjai_raw_item', wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($apply_mapping) {
            if (!empty($field_map_v2[$source_type])) {
                $this->map_fields_v2($post_id, $item, $source_type, $field_map_v2, !empty($settings['import_images']));
            } else {
                $this->map_acf_fields($post_id, $item, $source_type, $acf_map, $acf_path_map, !empty($settings['import_images']));
            }
        }

        return array(
            'status' => $action,
            'post_id' => $post_id,
        );
    }

    protected function store_source_index_meta($post_id, $item) {
        if (!is_array($item)) {
            return;
        }

        foreach ($item as $field_name => $value) {
            $field_key = sanitize_key($field_name);
            if ($field_key === '') {
                continue;
            }

            if (is_string($value) || is_numeric($value)) {
                update_post_meta($post_id, '_sjai_src_' . $field_key, (string) $value);
                continue;
            }

            $scalar = $this->extract_scalar_value($value);
            if ($scalar !== '') {
                update_post_meta($post_id, '_sjai_src_' . $field_key, $scalar);
            }
        }
    }

    protected function find_existing_post($uuid, $nid, $post_type) {
        $search_types = array($post_type, 'any');

        if ($uuid !== '') {
            foreach ($search_types as $search_type) {
                $posts = get_posts(array(
                    'post_type' => $search_type,
                    'post_status' => 'any',
                    'fields' => 'ids',
                    'posts_per_page' => 1,
                    'meta_key' => '_sjai_source_uuid',
                    'meta_value' => $uuid,
                ));
                if (!empty($posts)) {
                    return (int) $posts[0];
                }
            }
        }

        if ($nid > 0) {
            foreach ($search_types as $search_type) {
                $posts = get_posts(array(
                    'post_type' => $search_type,
                    'post_status' => 'any',
                    'fields' => 'ids',
                    'posts_per_page' => 1,
                    'meta_key' => '_sjai_source_nid',
                    'meta_value' => $nid,
                ));
                if (!empty($posts)) {
                    return (int) $posts[0];
                }
            }
        }

        return 0;
    }

    protected function normalize_source_identity_value($value) {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        $raw = preg_replace('/\s+/', ' ', $raw);
        return sanitize_text_field($raw);
    }

    protected function find_existing_post_by_source_identity($item, $post_type) {
        if (!is_array($item)) {
            return 0;
        }

        $identity_fields = array('canonical', 'path', 'url', 'uri', 'alias', 'slug');
        $search_types = array($post_type, 'any');

        foreach ($identity_fields as $identity_field) {
            if (!array_key_exists($identity_field, $item)) {
                continue;
            }

            $identity_value = $this->normalize_source_identity_value($this->extract_scalar_value($item[$identity_field]));
            if ($identity_value === '') {
                continue;
            }

            $meta_key = '_sjai_src_' . sanitize_key($identity_field);
            foreach ($search_types as $search_type) {
                $posts = get_posts(array(
                    'post_type' => $search_type,
                    'post_status' => 'any',
                    'fields' => 'ids',
                    'posts_per_page' => 1,
                    'meta_key' => $meta_key,
                    'meta_value' => $identity_value,
                ));
                if (!empty($posts)) {
                    return (int) $posts[0];
                }
            }
        }

        return 0;
    }

    protected function get_import_target_post_types($settings) {
        $post_types = array();
        $default_post_type = isset($settings['default_post_type']) ? sanitize_key($settings['default_post_type']) : 'post';
        if ($default_post_type !== '' && post_type_exists($default_post_type)) {
            $post_types[] = $default_post_type;
        }

        if (!empty($settings['type_map_ui']) && is_array($settings['type_map_ui'])) {
            foreach ($settings['type_map_ui'] as $source_type => $target_post_type) {
                $target_post_type = sanitize_key($target_post_type);
                if ($target_post_type !== '' && post_type_exists($target_post_type)) {
                    $post_types[] = $target_post_type;
                }
            }
        }

        $post_types = array_values(array_unique(array_filter($post_types)));
        return !empty($post_types) ? $post_types : array('post');
    }

    protected function get_duplicate_identity_value_for_post($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return '';
        }

        $meta_keys = array(
            '_sjai_source_uuid',
            '_sjai_source_nid',
            '_sjai_src_canonical',
            '_sjai_src_path',
            '_sjai_src_alias',
            '_sjai_src_slug',
        );

        foreach ($meta_keys as $meta_key) {
            $value = get_post_meta($post_id, $meta_key, true);
            if (is_string($value) || is_numeric($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $meta_key . ':' . $value;
                }
            }
        }

        return '';
    }

    protected function find_duplicate_groups_for_cleanup($post_types) {
        $post_types = is_array($post_types) ? array_values(array_filter($post_types)) : array();
        if (empty($post_types)) {
            return array();
        }

        $post_ids = get_posts(array(
            'post_type' => $post_types,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ));

        if (empty($post_ids) || !is_array($post_ids)) {
            return array();
        }

        $groups = array();
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }

            $identity = $this->get_duplicate_identity_value_for_post($post_id);
            if ($identity === '') {
                continue;
            }

            $post_type = get_post_type($post_id);
            if (!$post_type) {
                continue;
            }

            $group_key = $post_type . '|' . $identity;
            if (!isset($groups[$group_key])) {
                $groups[$group_key] = array(
                    'keep_id' => $post_id,
                    'delete_ids' => array(),
                );
                continue;
            }

            if ($post_id > (int) $groups[$group_key]['keep_id']) {
                $groups[$group_key]['delete_ids'][] = (int) $groups[$group_key]['keep_id'];
                $groups[$group_key]['keep_id'] = $post_id;
            } else {
                $groups[$group_key]['delete_ids'][] = $post_id;
            }
        }

        $duplicate_groups = array();
        foreach ($groups as $group) {
            $delete_ids = isset($group['delete_ids']) && is_array($group['delete_ids']) ? array_values(array_unique(array_filter(array_map('absint', $group['delete_ids'])))) : array();
            if (empty($delete_ids)) {
                continue;
            }

            sort($delete_ids, SORT_NUMERIC);
            $duplicate_groups[] = array(
                'keep_id' => (int) $group['keep_id'],
                'delete_ids' => $delete_ids,
            );
        }

        return $duplicate_groups;
    }

    protected function find_existing_post_by_reference($item, $reference_field, $post_type) {
        if (!is_array($item)) {
            return 0;
        }

        $reference_field = sanitize_key($reference_field);
        if ($reference_field === '') {
            return 0;
        }

        if (!array_key_exists($reference_field, $item)) {
            return 0;
        }

        $reference_value = $this->extract_scalar_value($item[$reference_field]);
        if ($reference_value === '') {
            return 0;
        }

        if ($reference_field === 'uuid') {
            $meta_key = '_sjai_source_uuid';
            $meta_value = sanitize_text_field($reference_value);
        } elseif ($reference_field === 'nid') {
            $meta_key = '_sjai_source_nid';
            $meta_value = (string) absint($reference_value);
        } else {
            $meta_key = '_sjai_src_' . $reference_field;
            $meta_value = (string) $reference_value;
        }

        $search_types = array($post_type, 'any');
        foreach ($search_types as $search_type) {
            $posts = get_posts(array(
                'post_type' => $search_type,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value,
            ));
            if (!empty($posts)) {
                return (int) $posts[0];
            }
        }

        return 0;
    }

    protected function extract_term_names($value) {
        $terms = array();

        if (is_string($value)) {
            $raw = trim($value);
            if ($raw === '') {
                return $terms;
            }

            $pieces = preg_split('/[,;|]/', $raw);
            if (is_array($pieces) && count($pieces) > 1) {
                foreach ($pieces as $piece) {
                    $piece = trim((string) $piece);
                    if ($piece !== '') {
                        $terms[] = $piece;
                    }
                }
            } else {
                $terms[] = $raw;
            }

            return array_values(array_unique($terms));
        }

        if (is_numeric($value)) {
            $terms[] = (string) absint($value);
            return array_values(array_unique($terms));
        }

        if (!is_array($value)) {
            return $terms;
        }

        if ($this->is_assoc_array($value)) {
            foreach (array('name', 'label', 'title', 'value') as $key) {
                if (isset($value[$key]) && (is_string($value[$key]) || is_numeric($value[$key]))) {
                    $candidate = trim((string) $value[$key]);
                    if ($candidate !== '') {
                        $terms[] = $candidate;
                        return array_values(array_unique($terms));
                    }
                }
            }

            foreach ($value as $child) {
                $terms = array_merge($terms, $this->extract_term_names($child));
            }

            return array_values(array_unique(array_filter($terms)));
        }

        foreach ($value as $child) {
            $terms = array_merge($terms, $this->extract_term_names($child));
        }

        return array_values(array_unique(array_filter($terms)));
    }

    protected function map_terms_to_taxonomy($post_id, $taxonomy, $raw_value) {
        $post_id = (int) $post_id;
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($post_id <= 0 || $taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return false;
        }

        $post_type = get_post_type($post_id);
        if (!$post_type || !is_object_in_taxonomy($post_type, $taxonomy)) {
            return false;
        }

        $term_candidates = $this->extract_term_names($raw_value);
        if (empty($term_candidates)) {
            return false;
        }

        $term_ids = array();
        foreach ($term_candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (ctype_digit($candidate)) {
                $term_obj = get_term((int) $candidate, $taxonomy);
                if ($term_obj && !is_wp_error($term_obj)) {
                    $term_ids[] = (int) $term_obj->term_id;
                    continue;
                }
            }

            $existing = term_exists($candidate, $taxonomy);
            if (!$existing) {
                $created = wp_insert_term($candidate, $taxonomy);
                if (is_wp_error($created) || !isset($created['term_id'])) {
                    continue;
                }
                $term_ids[] = (int) $created['term_id'];
                continue;
            }

            if (is_array($existing) && isset($existing['term_id'])) {
                $term_ids[] = (int) $existing['term_id'];
            } elseif (is_int($existing) || ctype_digit((string) $existing)) {
                $term_ids[] = (int) $existing;
            }
        }

        $term_ids = array_values(array_unique(array_filter($term_ids)));
        if (empty($term_ids)) {
            return false;
        }

        $assigned = wp_set_object_terms($post_id, $term_ids, $taxonomy, false);
        return !is_wp_error($assigned);
    }

    protected function normalize_wp_post_date($value) {
        $raw = trim($this->extract_scalar_value($value));
        if ($raw === '') {
            return array();
        }

        $timestamp = 0;
        if (ctype_digit($raw)) {
            $timestamp = (int) $raw;
            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }
        } else {
            $parsed = strtotime($raw);
            if ($parsed !== false) {
                $timestamp = (int) $parsed;
            }
        }

        if ($timestamp <= 0) {
            return array();
        }

        $post_date_gmt = gmdate('Y-m-d H:i:s', $timestamp);
        $post_date = get_date_from_gmt($post_date_gmt, 'Y-m-d H:i:s');
        if (!is_string($post_date) || $post_date === '') {
            $post_date = $post_date_gmt;
        }

        return array(
            'post_date' => $post_date,
            'post_date_gmt' => $post_date_gmt,
        );
    }

    protected function resolve_post_date_with_fallback($value, $item, $post_id = 0) {
        $parsed = $this->normalize_wp_post_date($value);
        if (!empty($parsed['post_date'])) {
            return $parsed;
        }

        if (!is_array($item)) {
            return array();
        }

        $fallback_fields = array(
            'field_data_pubblicazione_news',
            'data_pubblicazione_news',
            'field_data_news',
            'data_news',
            'field_data_pubblicazione',
            'data_pubblicazione',
            'created',
            'created_at',
            'published_at',
            'publish_date',
        );

        foreach ($fallback_fields as $field) {
            if (!array_key_exists($field, $item)) {
                continue;
            }

            $candidate = $this->normalize_wp_post_date($item[$field]);
            if (!empty($candidate['post_date'])) {
                if ((int) $post_id > 0) {
                    $this->log_import_debug('Post date fallback used post_id=' . (int) $post_id . ' source=' . $field . ' value=' . $candidate['post_date']);
                }
                return $candidate;
            }
        }

        return array();
    }

    protected function map_acf_fields($post_id, $item, $source_type, $acf_map, $acf_path_map, $import_images) {
        if (empty($source_type) || empty($acf_map[$source_type]) || !is_array($acf_map[$source_type])) {
            return false;
        }

        $post_update = array('ID' => (int) $post_id);
        $has_post_update = false;

        foreach ($acf_map[$source_type] as $source_field => $acf_field_name) {
            if (!array_key_exists($source_field, $item)) {
                continue;
            }

            $value = $item[$source_field];
            $path = '';
            if (isset($acf_path_map[$source_type]) && isset($acf_path_map[$source_type][$source_field]) && is_string($acf_path_map[$source_type][$source_field])) {
                $path = trim($acf_path_map[$source_type][$source_field]);
            }
            if ($path !== '') {
                $value = $this->extract_value_by_path($value, $path);
            }

            $target = is_string($acf_field_name) ? trim($acf_field_name) : '';
            if ($target === '') {
                continue;
            }

            if (strpos($target, 'wp:') === 0) {
                $wp_target = substr($target, 3);
                if ($wp_target === 'post_title') {
                    $post_update['post_title'] = wp_strip_all_tags($this->extract_scalar_value($value));
                    $has_post_update = true;
                } elseif ($wp_target === 'post_content') {
                    $post_update['post_content'] = $this->extract_body_value($value);
                    $has_post_update = true;
                } elseif ($wp_target === 'post_excerpt') {
                    $post_update['post_excerpt'] = $this->extract_scalar_value($value);
                    $has_post_update = true;
                } elseif ($wp_target === 'post_name') {
                    $post_update['post_name'] = sanitize_title($this->extract_scalar_value($value));
                    $has_post_update = true;
                } elseif ($wp_target === 'menu_order') {
                    $post_update['menu_order'] = (int) $this->extract_scalar_value($value);
                    $has_post_update = true;
                } elseif ($wp_target === 'post_date') {
                    $parsed_date = $this->resolve_post_date_with_fallback($value, $item, $post_id);
                    if (!empty($parsed_date['post_date'])) {
                        $post_update['post_date'] = $parsed_date['post_date'];
                        $post_update['post_date_gmt'] = $parsed_date['post_date_gmt'];
                        $has_post_update = true;
                    }
                } elseif ($wp_target === 'featured_image') {
                    $featured_value = $this->normalize_for_acf($value, $import_images, $post_id);
                    if (is_numeric($featured_value) && (int) $featured_value > 0) {
                        set_post_thumbnail($post_id, (int) $featured_value);
                    }
                } elseif ($wp_target === 'category' || $wp_target === 'categories') {
                    $this->map_terms_to_taxonomy($post_id, 'category', $value);
                } elseif ($wp_target === 'tag' || $wp_target === 'tags' || $wp_target === 'post_tag') {
                    $this->map_terms_to_taxonomy($post_id, 'post_tag', $value);
                } elseif (strpos($wp_target, 'taxonomy:') === 0) {
                    $taxonomy = sanitize_key(substr($wp_target, 9));
                    if ($taxonomy !== '') {
                        $this->map_terms_to_taxonomy($post_id, $taxonomy, $value);
                    }
                }
                continue;
            }

            if (strpos($target, 'yoast:') === 0) {
                $yoast_target = substr($target, 6);
                $yoast_key = $this->get_yoast_meta_key($yoast_target);
                if ($yoast_key !== '') {
                    update_post_meta($post_id, $yoast_key, $this->extract_scalar_value($value));
                }
                continue;
            }

            if (strpos($target, 'acf:') === 0) {
                $acf_field = substr($target, 4);
            } else {
                $acf_field = $target;
            }

            $normalized = $this->normalize_for_acf($value, $import_images, $post_id);
            if (function_exists('update_field')) {
                update_field($acf_field, $normalized, $post_id);
            } else {
                update_post_meta($post_id, $acf_field, $normalized);
            }
        }

        if ($has_post_update) {
            wp_update_post($post_update);
        }

        return true;
    }

    protected function is_assoc_array($value) {
        if (!is_array($value)) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    protected function discover_value_paths($value, $prefix = '', $depth = 0) {
        if (!is_array($value) || $depth > 4) {
            return array();
        }

        $paths = array();

        if ($this->is_assoc_array($value)) {
            foreach ($value as $key => $child) {
                $key = sanitize_key((string) $key);
                if ($key === '') {
                    continue;
                }

                $path = ($prefix === '') ? $key : ($prefix . '.' . $key);
                $paths[] = $path;

                if (is_array($child)) {
                    $paths = array_merge($paths, $this->discover_value_paths($child, $path, $depth + 1));
                }
            }

            return array_values(array_unique($paths));
        }

        if (!array_key_exists(0, $value)) {
            return array();
        }

        $first = $value[0];
        $first_path = ($prefix === '') ? '[0]' : ($prefix . '.[0]');
        $paths[] = $first_path;

        $wildcard_path = ($prefix === '') ? '[*]' : ($prefix . '.[*]');
        $paths[] = $wildcard_path;

        if (is_array($first)) {
            $paths = array_merge($paths, $this->discover_value_paths($first, $first_path, $depth + 1));

            if ($this->is_assoc_array($first)) {
                foreach ($first as $key => $child) {
                    $key = sanitize_key((string) $key);
                    if ($key === '') {
                        continue;
                    }

                    $wildcard_child = $wildcard_path . '.' . $key;
                    $paths[] = $wildcard_child;

                    if (is_array($child)) {
                        $paths = array_merge($paths, $this->discover_value_paths($child, $wildcard_child, $depth + 1));
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    protected function extract_value_by_path($value, $path) {
        $path = is_string($path) ? trim($path) : '';
        if ($path === '') {
            return $value;
        }

        preg_match_all('/(\[\*\]|\[\d+\]|[^\.\[\]]+)/', $path, $matches);
        $segments = isset($matches[0]) && is_array($matches[0]) ? array_values(array_filter($matches[0])) : array();

        if (empty($segments)) {
            return $value;
        }

        return $this->extract_value_by_segments($value, $segments, 0);
    }

    protected function extract_value_by_segments($value, $segments, $index) {
        if (!is_array($segments) || $index >= count($segments)) {
            return $value;
        }

        $segment = $segments[$index];

        if ($segment === '[*]') {
            if (!is_array($value)) {
                return null;
            }

            $out = array();
            foreach ($value as $entry) {
                $extracted = $this->extract_value_by_segments($entry, $segments, $index + 1);
                if ($extracted === null || $extracted === '' || $extracted === array()) {
                    continue;
                }
                $out[] = $extracted;
            }

            return $out;
        }

        if (preg_match('/^\[(\d+)\]$/', $segment, $m)) {
            $offset = (int) $m[1];
            if (!is_array($value) || !array_key_exists($offset, $value)) {
                return null;
            }

            return $this->extract_value_by_segments($value[$offset], $segments, $index + 1);
        }

        $key = sanitize_key($segment);
        if ($key === '' || !is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }

        return $this->extract_value_by_segments($value[$key], $segments, $index + 1);
    }

    protected function get_yoast_meta_key($target) {
        $map = array(
            'seo_title' => '_yoast_wpseo_title',
            'meta_description' => '_yoast_wpseo_metadesc',
            'focus_keyword' => '_yoast_wpseo_focuskw',
            'canonical' => '_yoast_wpseo_canonical',
            'og_title' => '_yoast_wpseo_opengraph-title',
            'og_description' => '_yoast_wpseo_opengraph-description',
            'twitter_title' => '_yoast_wpseo_twitter-title',
            'twitter_description' => '_yoast_wpseo_twitter-description',
        );

        return isset($map[$target]) ? $map[$target] : '';
    }

        protected function render_async_import_js() {
                $ajax_url = admin_url('admin-ajax.php');
                $ajax_url_js = wp_json_encode($ajax_url);

                echo '<script type="text/javascript">';
                echo "(function(){\n";
                echo "var ajaxUrl = " . $ajax_url_js . ";\n";
                echo <<<'JS'
var btn = document.getElementById("sjai-async-import-btn");
var container = document.getElementById("sjai-progress-container");
var progressBar = document.getElementById("sjai-progress-bar");
var progressText = document.getElementById("sjai-progress-text");
var progressStats = document.getElementById("sjai-progress-stats");
var progressErrors = document.getElementById("sjai-progress-errors");
var progressResult = document.getElementById("sjai-progress-result");
if(!btn) return;
var sessionKey = "sjai_import_session_" + document.location.pathname;
var startTime = null;

function checkExistingSession(){
    var session = localStorage.getItem(sessionKey);
    if(session){
        try { session = JSON.parse(session); } catch(e) { return false; }
        if(confirm("Import precedente non terminato. Continuare da record " + session.processed + "?")){
            return session;
        }
        localStorage.removeItem(sessionKey);
    }
    return false;
}

btn.addEventListener("click", function(){
    btn.disabled = true;
    container.style.display = "block";
    progressBar.style.width = "0%";
    progressText.textContent = "Caricamento...";
    progressStats.textContent = "";
    progressErrors.style.display = "none";
    progressResult.style.display = "none";
    startTime = new Date().getTime();

    var existing = checkExistingSession();
    var nonce = document.querySelector("input[name=sjai_async_import_nonce_field]");
    var nonceValue = nonce ? nonce.value : "";

    if(existing){
        resumeAsyncImport(nonceValue, existing);
    } else {
        startAsyncImport(nonceValue);
    }
});

function startAsyncImport(nonce){
    var xhr = new XMLHttpRequest();
    xhr.open("POST", ajaxUrl);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.timeout = 30000;
    xhr.onload = function(){
        try {
            if(!xhr.responseText) throw new Error("Risposta vuota");
            var resp = JSON.parse(xhr.responseText);
            if(resp.success && resp.data && resp.data.total){
                var session = {total: resp.data.total, processed: 0, created: 0, updated: 0, skipped: 0};
                localStorage.setItem(sessionKey, JSON.stringify(session));
                processBatch(nonce, resp.data.total);
            } else {
                showError((resp.data && resp.data.message) || "Errore inizializzazione");
            }
        } catch(e) {
            showError("Errore: " + e.message);
        }
    };
    xhr.onerror = function(){ showError("Errore rete inizializzazione"); };
    xhr.ontimeout = function(){ showError("Timeout inizializzazione"); };
    xhr.send("action=sjai_start_async_import&nonce=" + encodeURIComponent(nonce));
}

function resumeAsyncImport(nonce, session){
    progressText.textContent = "Ripresa da record " + session.processed + " / " + session.total;
    localStorage.setItem(sessionKey, JSON.stringify(session));
    setTimeout(function(){ processBatch(nonce, session.total); }, 1000);
}

var maxRetries = 3;
function processBatch(nonce, total, attempt){
    if(!attempt) attempt = 0;
    var timeout = null;
    var xhr = new XMLHttpRequest();
    xhr.open("POST", ajaxUrl);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.timeout = 60000;
    timeout = setTimeout(function(){ xhr.abort(); }, 60000);

    xhr.onload = function(){
        clearTimeout(timeout);
        try {
            var resp = JSON.parse(xhr.responseText);
            if(resp.success){
                updateProgress(resp.data, total);
                if(!resp.data.done){
                    setTimeout(function(){ processBatch(nonce, total, 0); }, 500);
                } else {
                    showResult(resp.data);
                    btn.disabled = false;
                }
            } else {
                showError(resp.data || "Errore batch");
                btn.disabled = false;
            }
        } catch(e) {
            showError("Errore parsing risposta: " + e.message);
            btn.disabled = false;
        }
    };

    xhr.onerror = function(){
        clearTimeout(timeout);
        if(attempt < maxRetries){
            progressText.textContent = "Connessione persa, tentativo " + (attempt + 1) + " di " + maxRetries + "...";
            setTimeout(function(){ processBatch(nonce, total, attempt + 1); }, 2000);
        } else {
            showError("Errore di rete dopo " + maxRetries + " tentativi");
            btn.disabled = false;
        }
    };

    xhr.ontimeout = function(){
        clearTimeout(timeout);
        if(attempt < maxRetries){
            progressText.textContent = "Timeout, tentativo " + (attempt + 1) + " di " + maxRetries + "...";
            setTimeout(function(){ processBatch(nonce, total, attempt + 1); }, 2000);
        } else {
            showError("Timeout dopo " + maxRetries + " tentativi");
            btn.disabled = false;
        }
    };

    xhr.send("action=sjai_process_batch&nonce=" + encodeURIComponent(nonce));
}

function updateProgress(data, total){
    var pct = total > 0 ? Math.floor((data.processed / total) * 100) : 0;
    var elapsed = (new Date().getTime() - startTime) / 1000;
    var speed = elapsed > 0 ? (data.processed / elapsed).toFixed(1) : 0;
    var remaining = data.processed > 0 ? ((total - data.processed) * elapsed / data.processed).toFixed(0) : "?";

    progressBar.style.width = pct + "%";
    progressBar.textContent = pct + "%";

    var timeStr = elapsed > 60 ? (elapsed / 60).toFixed(1) + "m" : elapsed.toFixed(0) + "s";
    var remainStr = remaining === "?" ? "?" : (remaining > 60 ? (remaining / 60).toFixed(1) + "m" : remaining + "s");
    progressText.textContent = "Processati: " + data.processed + " / " + total + " (" + speed + " rec/s) | Tempo: " + timeStr + " | Rimanente: " + remainStr;
    var imageStats = data.image_stats || {attempted:0, success:0, failed:0, blocked:0, reused:0};
    progressStats.textContent = "Creati: " + data.created + " | Aggiornati: " + data.updated + " | Saltati: " + data.skipped + " | Immagini scaricate: " + imageStats.success + "/" + imageStats.attempted + " | Riusate: " + (imageStats.reused || 0) + " | Fail: " + imageStats.failed;

    var session = {total: total, processed: data.processed, created: data.created, updated: data.updated, skipped: data.skipped};
    localStorage.setItem(sessionKey, JSON.stringify(session));

    if(data.errors && data.errors.length > 0){
        var errMsg = "Errori: " + data.errors.slice(0,3).map(function(e){ return (e.message || e); }).join("; ");
        progressErrors.textContent = errMsg + (data.errors.length > 3 ? " (+" + (data.errors.length - 3) + " altri)" : "");
        progressErrors.style.display = "block";
    }
}

function showError(msg){
    var elapsed = startTime ? ((new Date().getTime() - startTime) / 1000).toFixed(0) : "?";
    progressText.textContent = "Errore dopo " + elapsed + "s: " + msg;
    progressText.style.color = "red";
    localStorage.removeItem(sessionKey);
    btn.disabled = false;
}

function showResult(data){
    var elapsed = ((new Date().getTime() - startTime) / 1000).toFixed(1);
    progressText.textContent = "Import completato in " + elapsed + "s!";
    progressText.style.color = "green";
    localStorage.removeItem(sessionKey);

    var html = "<strong>Risultati finali:</strong><br>";
    html += "Creati: " + data.created + "<br>";
    html += "Aggiornati: " + data.updated + "<br>";
    html += "Saltati: " + data.skipped + "<br>";
    html += "Tempo totale: " + elapsed + "s<br>";
    var imageStats = data.image_stats || {attempted:0, success:0, failed:0, blocked:0, reused:0};
    var imageStatus = imageStats.failed > 0 ? "PROBLEMI" : "OK";
    if(imageStats.attempted > 0 || imageStats.blocked > 0 || (imageStats.reused || 0) > 0){
        html += "Import immagini: <strong>" + imageStatus + "</strong> (scaricate " + imageStats.success + "/" + imageStats.attempted + ", riusate " + (imageStats.reused || 0) + ", fail " + imageStats.failed + ", bloccate " + imageStats.blocked + ")<br>";
    } else {
        html += "Import immagini: nessuna immagine processata<br>";
    }
    if(data.parent_pass && data.parent_pass.linked){
        html += "Parent collegati: " + data.parent_pass.linked + "<br>";
    }
    if(data.errors && data.errors.length > 0){
        html += "<strong style=\"color:red;\">Errori: " + data.errors.length + "</strong>";
    }
    progressResult.innerHTML = html;
    progressResult.style.display = "block";
}

window.clearImportSession = function(){
    if(confirm("Cancellare la sessione salvata?")){
        localStorage.removeItem(sessionKey);
        progressText.textContent = "";
        progressBar.style.width = "0%";
        progressBar.textContent = "";
        progressStats.textContent = "";
        progressErrors.style.display = "none";
        progressResult.style.display = "none";
        container.style.display = "none";
        btn.disabled = false;
    }
};
})();
JS;
                echo '</script>';
        }

    // -------------------------------------------------------------------------
    // INVERTED MAP IMPORT LOGIC (field_map_v2)
    // -------------------------------------------------------------------------

    protected function get_acf_target_field_type($wp_target) {
        if (strpos($wp_target, 'acf:') !== 0) {
            return '';
        }

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return '';
        }

        $field_name = substr($wp_target, 4);
        $groups = acf_get_field_groups();
        foreach ($groups as $group) {
            $fields = acf_get_fields($group);
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $field) {
                if (isset($field['name']) && $field['name'] === $field_name && isset($field['type'])) {
                    $type = (string) $field['type'];
                    if (in_array($type, array('image', 'gallery'), true)) {
                        return $type;
                    }
                    return '';
                }
            }
        }

        return '';
    }

    protected function is_acf_image_target($wp_target) {
        $type = $this->get_acf_target_field_type($wp_target);
        if ($type === 'image' || $type === 'gallery') {
            return true;
        }

        return false;
    }

    protected function post_has_gallery_value($acf_field, $post_id) {
        $acf_field = is_string($acf_field) ? trim($acf_field) : '';
        $post_id = (int) $post_id;
        if ($acf_field === '' || $post_id <= 0) {
            return false;
        }

        $meta = get_post_meta($post_id, $acf_field, true);
        if (is_array($meta)) {
            return !empty($meta);
        }

        if (is_numeric($meta)) {
            return ((int) $meta) > 0;
        }

        return is_string($meta) && trim($meta) !== '';
    }

    protected function post_has_valid_thumbnail($post_id) {
        $thumb_id = (int) get_post_thumbnail_id($post_id);
        if ($thumb_id <= 0) {
            return false;
        }

        $attachment = get_post($thumb_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            // Stale reference to a deleted attachment: clear it so a fresh image can be imported.
            delete_post_thumbnail($post_id);
            return false;
        }

        return true;
    }

    protected function attachment_id_exists($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return false;
        }
        $attachment = get_post($attachment_id);
        return $attachment && $attachment->post_type === 'attachment';
    }

    protected function get_gallery_value_count($acf_field, $post_id) {
        $acf_field = is_string($acf_field) ? trim($acf_field) : '';
        $post_id = (int) $post_id;
        if ($acf_field === '' || $post_id <= 0) {
            return 0;
        }

        $meta = get_post_meta($post_id, $acf_field, true);
        if (is_array($meta)) {
            $count = 0;
            foreach ($meta as $v) {
                if (is_numeric($v)) {
                    if ($this->attachment_id_exists($v)) {
                        $count++;
                    }
                } elseif (is_string($v) && trim($v) !== '') {
                    $count++;
                }
            }
            return $count;
        }

        if (is_numeric($meta)) {
            return $this->attachment_id_exists($meta) ? 1 : 0;
        }

        return (is_string($meta) && trim($meta) !== '') ? 1 : 0;
    }

    protected function map_fields_v2($post_id, $item, $source_type, $field_map_v2, $import_images) {
        if (empty($field_map_v2[$source_type]) || !is_array($field_map_v2[$source_type])) {
            return false;
        }

        $post_update     = array('ID' => (int) $post_id);
        $has_post_update = false;

        foreach ($field_map_v2[$source_type] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $wp_target = isset($entry['wp'])  ? trim((string) $entry['wp'])  : '';
            $src_field = isset($entry['src'])  ? sanitize_key($entry['src'])  : '';
            $path      = isset($entry['path']) ? trim((string) $entry['path']) : '';

            if ($wp_target === '' || $src_field === '' || !array_key_exists($src_field, $item)) {
                continue;
            }

            $value = $item[$src_field];
            if ($path !== '') {
                $value = $this->extract_value_by_path($value, $path);
            }

            // IMAGE TARGETS
            $acf_media_type = $this->get_acf_target_field_type($wp_target);
            if ($wp_target === 'wp:featured_image' || $acf_media_type === 'image' || $acf_media_type === 'gallery') {
                // Gallery target: import every image entry and save array of attachment IDs.
                if ($acf_media_type === 'gallery') {
                    $gallery_entries = array();

                    if (is_array($value) && isset($value['url']) && is_string($value['url'])) {
                        $gallery_entries[] = $value;
                    } elseif (is_array($value)) {
                        foreach ($value as $entry) {
                            if (is_array($entry) && isset($entry['url']) && is_string($entry['url'])) {
                                $gallery_entries[] = $entry;
                            } elseif (is_string($entry) && filter_var($entry, FILTER_VALIDATE_URL)) {
                                $gallery_entries[] = array('url' => $entry);
                            }
                        }
                    } elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                        $gallery_entries[] = array('url' => $value);
                    }

                    $global_alt = '';
                    $alt_src = isset($entry['img_alt_src']) ? sanitize_key($entry['img_alt_src']) : '';
                    $alt_path = isset($entry['img_alt_path']) ? trim((string) $entry['img_alt_path']) : '';
                    if ($alt_src !== '' && array_key_exists($alt_src, $item)) {
                        $alt_raw = $item[$alt_src];
                        if ($alt_path !== '') {
                            $alt_raw = $this->extract_value_by_path($alt_raw, $alt_path);
                        }
                        $global_alt = sanitize_text_field($this->extract_scalar_value($alt_raw));
                    }

                    $global_title = '';
                    $ttl_src = isset($entry['img_title_src']) ? sanitize_key($entry['img_title_src']) : '';
                    $ttl_path = isset($entry['img_title_path']) ? trim((string) $entry['img_title_path']) : '';
                    if ($ttl_src !== '' && array_key_exists($ttl_src, $item)) {
                        $ttl_raw = $item[$ttl_src];
                        if ($ttl_path !== '') {
                            $ttl_raw = $this->extract_value_by_path($ttl_raw, $ttl_path);
                        }
                        $global_title = sanitize_text_field($this->extract_scalar_value($ttl_raw));
                    }

                    $acf_f = substr($wp_target, 4);
                    $existing_gallery_count = $this->get_gallery_value_count($acf_f, $post_id);
                    if ($existing_gallery_count > 0 && $existing_gallery_count >= count($gallery_entries)) {
                        $this->log_import_debug('Gallery skipped: already complete post_id=' . (int) $post_id . ' field=' . $acf_f . ' count=' . $existing_gallery_count . '/' . count($gallery_entries));
                        continue;
                    }

                    if ($import_images) {
                        $gallery_ids = array();
                        foreach ($gallery_entries as $gallery_entry) {
                            $img_url = isset($gallery_entry['url']) && is_string($gallery_entry['url']) ? $gallery_entry['url'] : '';
                            if ($img_url === '') {
                                continue;
                            }

                            $entry_meta = is_array($gallery_entry) ? $gallery_entry : array();
                            if ($global_alt !== '') {
                                $entry_meta['alt'] = $global_alt;
                            }
                            if ($global_title !== '') {
                                $entry_meta['title'] = $global_title;
                            }

                            $att_id = $this->sideload_image($img_url, $entry_meta, $post_id);
                            if ($att_id > 0) {
                                $gallery_ids[] = $att_id;
                            }
                        }

                        if (!empty($gallery_ids)) {
                            if (function_exists('update_field')) {
                                update_field($acf_f, $gallery_ids, $post_id);
                            } else {
                                update_post_meta($post_id, $acf_f, $gallery_ids);
                            }
                            continue;
                        }
                    }

                    $gallery_urls = array();
                    foreach ($gallery_entries as $gallery_entry) {
                        if (is_array($gallery_entry) && isset($gallery_entry['url']) && is_string($gallery_entry['url'])) {
                            $gallery_urls[] = esc_url_raw($gallery_entry['url']);
                        }
                    }

                    if (!empty($gallery_urls)) {
                        if (function_exists('update_field')) {
                            update_field($acf_f, $gallery_urls, $post_id);
                        } else {
                            update_post_meta($post_id, $acf_f, $gallery_urls);
                        }
                    }
                    continue;
                }

                $image_meta = is_array($value) ? $value : array();

                // Override ALT
                $alt_src  = isset($entry['img_alt_src'])    ? sanitize_key($entry['img_alt_src'])        : '';
                $alt_path = isset($entry['img_alt_path'])   ? trim((string) $entry['img_alt_path'])       : '';
                if ($alt_src !== '' && array_key_exists($alt_src, $item)) {
                    $alt_raw = $item[$alt_src];
                    if ($alt_path !== '') {
                        $alt_raw = $this->extract_value_by_path($alt_raw, $alt_path);
                    }
                    $alt_str = $this->extract_scalar_value($alt_raw);
                    if ($alt_str !== '') {
                        $image_meta['alt'] = sanitize_text_field($alt_str);
                    }
                }

                // Override TITLE
                $ttl_src  = isset($entry['img_title_src'])  ? sanitize_key($entry['img_title_src'])      : '';
                $ttl_path = isset($entry['img_title_path']) ? trim((string) $entry['img_title_path'])     : '';
                if ($ttl_src !== '' && array_key_exists($ttl_src, $item)) {
                    $ttl_raw = $item[$ttl_src];
                    if ($ttl_path !== '') {
                        $ttl_raw = $this->extract_value_by_path($ttl_raw, $ttl_path);
                    }
                    $ttl_str = $this->extract_scalar_value($ttl_raw);
                    if ($ttl_str !== '') {
                        $image_meta['title'] = sanitize_text_field($ttl_str);
                    }
                }

                $img_url = '';
                if (is_array($value) && isset($value['url']) && is_string($value['url'])) {
                    $img_url = $value['url'];
                } elseif (is_array($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['url']) && is_string($value[0]['url'])) {
                    $img_url = $value[0]['url'];
                    if (empty($image_meta['alt']) && !empty($value[0]['alt']) && is_string($value[0]['alt'])) {
                        $image_meta['alt'] = $value[0]['alt'];
                    }
                    if (empty($image_meta['title']) && !empty($value[0]['title']) && is_string($value[0]['title'])) {
                        $image_meta['title'] = $value[0]['title'];
                    }
                } elseif (is_array($value) && isset($value[0]) && is_string($value[0]) && filter_var($value[0], FILTER_VALIDATE_URL)) {
                    $img_url = $value[0];
                } elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                    $img_url = $value;
                }

                if ($img_url === '' && $wp_target === 'wp:featured_image') {
                    $img_url = $this->find_featured_image_fallback_url($item);
                    if ($img_url !== '') {
                        $this->log_import_debug('Featured image fallback URL used post_id=' . (int) $post_id . ' url=' . $img_url);
                    }
                }

                if ($wp_target === 'wp:featured_image' && $this->post_has_valid_thumbnail($post_id)) {
                    $this->log_import_debug('Featured image skipped: existing thumbnail kept post_id=' . (int) $post_id);
                    continue;
                }

                if ($img_url !== '' && $import_images) {
                    if ($wp_target === 'wp:featured_image') {
                        $this->log_import_debug('Featured image attempt post_id=' . (int) $post_id . ' url=' . $img_url);
                    }
                    $att_id = $this->sideload_image($img_url, $image_meta, $post_id);
                    if ($att_id > 0) {
                        if ($wp_target === 'wp:featured_image') {
                            set_post_thumbnail($post_id, $att_id);
                            $this->log_import_debug('Featured image assigned post_id=' . (int) $post_id . ' attachment_id=' . (int) $att_id);
                        } else {
                            $acf_f = substr($wp_target, 4);
                            if (function_exists('update_field')) {
                                update_field($acf_f, $att_id, $post_id);
                            } else {
                                update_post_meta($post_id, $acf_f, $att_id);
                            }
                        }
                        continue;
                    }

                    if ($wp_target === 'wp:featured_image') {
                        $this->log_import_debug('Featured image sideload failed post_id=' . (int) $post_id . ' url=' . $img_url);
                    }
                } elseif ($img_url === '' && $wp_target === 'wp:featured_image') {
                    $this->log_import_debug('Featured image skipped: no valid URL post_id=' . (int) $post_id . ' raw=' . wp_json_encode($value));
                } elseif (!$import_images && $wp_target === 'wp:featured_image') {
                    $this->log_import_debug('Featured image skipped: import_images disabled post_id=' . (int) $post_id);
                }

                // Fallback: save URL as text for ACF fields
                if ($img_url !== '' && $wp_target !== 'wp:featured_image') {
                    $acf_f = substr($wp_target, 4);
                    if (function_exists('update_field')) {
                        update_field($acf_f, esc_url_raw($img_url), $post_id);
                    } else {
                        update_post_meta($post_id, $acf_f, esc_url_raw($img_url));
                    }
                }
                continue;
            }

            // WP CORE TARGETS
            if (strpos($wp_target, 'wp:') === 0) {
                $wp_sub = substr($wp_target, 3);
                if ($wp_sub === 'post_title') {
                    $post_update['post_title'] = wp_strip_all_tags($this->extract_scalar_value($value));
                    $has_post_update = true;
                } elseif ($wp_sub === 'post_content') {
                    $post_update['post_content'] = $this->extract_body_value($value);
                    $has_post_update = true;
                } elseif ($wp_sub === 'post_excerpt') {
                    $post_update['post_excerpt'] = $this->extract_scalar_value($value);
                    $has_post_update = true;
                } elseif ($wp_sub === 'post_name') {
                    $post_update['post_name'] = sanitize_title($this->extract_scalar_value($value));
                    $has_post_update = true;
                } elseif ($wp_sub === 'menu_order') {
                    $post_update['menu_order'] = (int) $this->extract_scalar_value($value);
                    $has_post_update = true;
                } elseif ($wp_sub === 'post_date') {
                    $parsed_date = $this->resolve_post_date_with_fallback($value, $item, $post_id);
                    if (!empty($parsed_date['post_date'])) {
                        $post_update['post_date'] = $parsed_date['post_date'];
                        $post_update['post_date_gmt'] = $parsed_date['post_date_gmt'];
                        $has_post_update = true;
                    }
                } elseif ($wp_sub === 'category' || $wp_sub === 'categories') {
                    $this->map_terms_to_taxonomy($post_id, 'category', $value);
                } elseif ($wp_sub === 'tag' || $wp_sub === 'tags' || $wp_sub === 'post_tag') {
                    $this->map_terms_to_taxonomy($post_id, 'post_tag', $value);
                } elseif (strpos($wp_sub, 'taxonomy:') === 0) {
                    $taxonomy = sanitize_key(substr($wp_sub, 9));
                    if ($taxonomy !== '') {
                        $this->map_terms_to_taxonomy($post_id, $taxonomy, $value);
                    }
                }
                continue;
            }

            // YOAST TARGETS
            if (strpos($wp_target, 'yoast:') === 0) {
                $yoast_key = $this->get_yoast_meta_key(substr($wp_target, 6));
                if ($yoast_key !== '') {
                    update_post_meta($post_id, $yoast_key, $this->extract_scalar_value($value));
                }
                continue;
            }

            // ACF / META TARGETS
            $acf_field = strpos($wp_target, 'acf:') === 0 ? substr($wp_target, 4) : $wp_target;
            $normalized = $this->normalize_for_acf($value, $import_images, $post_id);
            if (function_exists('update_field')) {
                update_field($acf_field, $normalized, $post_id);
            } else {
                update_post_meta($post_id, $acf_field, $normalized);
            }
        }

        if ($has_post_update) {
            wp_update_post($post_update);
        }

        return true;
    }

    protected function normalize_for_acf($value, $import_images, $post_id = 0) {
        if (is_array($value) && isset($value['url']) && is_string($value['url'])) {
            if ($import_images) {
                $attachment_id = $this->sideload_image($value['url'], $value, $post_id);
                if ($attachment_id > 0) {
                    return $attachment_id;
                }
            }
            return esc_url_raw($value['url']);
        }

        if (is_array($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['url'])) {
            if ($import_images) {
                $ids = array();
                foreach ($value as $entry) {
                    if (!isset($entry['url']) || !is_string($entry['url'])) {
                        continue;
                    }
                    $attachment_id = $this->sideload_image($entry['url'], $entry, $post_id);
                    if ($attachment_id > 0) {
                        $ids[] = $attachment_id;
                    }
                }
                if (!empty($ids)) {
                    return $ids;
                }
            }

            $urls = array();
            foreach ($value as $entry) {
                if (isset($entry['url']) && is_string($entry['url'])) {
                    $urls[] = esc_url_raw($entry['url']);
                }
            }
            return $urls;
        }

        if (is_array($value)) {
            if (isset($value['value']) && count($value) === 1) {
                return $value['value'];
            }

            return $value;
        }

        return $value;
    }

    protected function normalize_image_source_url($url) {
        if (!is_string($url)) {
            return '';
        }

        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        return esc_url_raw($normalized);
    }

    protected function build_image_source_url_variants($url) {
        $variants = array();
        $normalized = $this->normalize_image_source_url($url);
        if ($normalized === '') {
            return $variants;
        }

        $variants[] = $normalized;

        $no_query = $normalized;
        $q_pos = strpos($no_query, '?');
        if ($q_pos !== false) {
            $no_query = substr($no_query, 0, $q_pos);
        }

        $hash_pos = strpos($no_query, '#');
        if ($hash_pos !== false) {
            $no_query = substr($no_query, 0, $hash_pos);
        }

        if ($no_query !== '') {
            $variants[] = $no_query;
            $decoded = rawurldecode($no_query);
            if ($decoded !== '') {
                $variants[] = $decoded;
            }
        }

        return array_values(array_unique(array_filter($variants, function ($entry) {
            return is_string($entry) && $entry !== '';
        })));
    }

    protected function string_ends_with_case_insensitive($haystack, $needle) {
        if (!is_string($haystack) || !is_string($needle)) {
            return false;
        }

        if ($needle === '') {
            return true;
        }

        if (strlen($needle) > strlen($haystack)) {
            return false;
        }

        return strtolower(substr($haystack, -strlen($needle))) === strtolower($needle);
    }

    protected function find_existing_attachment_by_source_url($url) {
        $variants = $this->build_image_source_url_variants($url);
        if (empty($variants)) {
            return 0;
        }

        foreach ($variants as $candidate_url) {
            $existing = get_posts(array(
                'post_type' => 'attachment',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'no_found_rows' => true,
                'meta_key' => '_sjai_source_image_url',
                'meta_value' => $candidate_url,
            ));

            if (!empty($existing[0])) {
                return (int) $existing[0];
            }
        }

        foreach ($variants as $candidate_url) {
            if (function_exists('attachment_url_to_postid')) {
                $attachment_id = (int) attachment_url_to_postid($candidate_url);
                if ($attachment_id > 0) {
                    return $attachment_id;
                }
            }
        }

        $path = parse_url($variants[0], PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return 0;
        }

        $basename = wp_basename($path);
        if (!is_string($basename) || $basename === '') {
            return 0;
        }

        $path_no_leading = ltrim($path, '/');
        $path_candidates = array($path_no_leading);
        $decoded_path = rawurldecode($path_no_leading);
        if ($decoded_path !== '') {
            $path_candidates[] = $decoded_path;
        }

        $potential = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 25,
            'no_found_rows' => true,
            'meta_query' => array(
                array(
                    'key' => '_wp_attached_file',
                    'value' => $basename,
                    'compare' => 'LIKE',
                ),
            ),
        ));

        foreach ($potential as $attachment_id) {
            $attachment_id = (int) $attachment_id;
            if ($attachment_id <= 0) {
                continue;
            }

            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            if (is_string($attached_file) && $attached_file !== '') {
                foreach ($path_candidates as $candidate_path) {
                    if ($candidate_path !== '' && $this->string_ends_with_case_insensitive($attached_file, $candidate_path)) {
                        return $attachment_id;
                    }
                }

                if ($this->string_ends_with_case_insensitive($attached_file, $basename)) {
                    return $attachment_id;
                }
            }

            $guid = get_post_field('guid', $attachment_id);
            if (is_string($guid) && $guid !== '') {
                foreach ($variants as $candidate_url) {
                    if ($guid === $candidate_url) {
                        return $attachment_id;
                    }
                }
            }
        }

        return 0;
    }

    protected function sideload_image($url, $image_meta = array(), $post_id = 0) {
        $normalized_url = $this->normalize_image_source_url($url);
        if ($normalized_url === '') {
            $this->log_import_debug('Sideload skipped: empty URL post_id=' . (int) $post_id);
            return 0;
        }

        $existing_attachment_id = $this->find_existing_attachment_by_source_url($normalized_url);
        if ($existing_attachment_id > 0) {
            $this->request_image_stats['reused']++;
            $this->log_import_debug('Sideload reused existing attachment post_id=' . (int) $post_id . ' url=' . $normalized_url . ' attachment_id=' . (int) $existing_attachment_id);

            if (is_array($image_meta)) {
                if (!empty($image_meta['alt']) && is_string($image_meta['alt'])) {
                    update_post_meta($existing_attachment_id, '_wp_attachment_image_alt', sanitize_text_field($image_meta['alt']));
                }

                if (!empty($image_meta['title']) && is_string($image_meta['title'])) {
                    wp_update_post(array(
                        'ID' => (int) $existing_attachment_id,
                        'post_title' => sanitize_text_field($image_meta['title']),
                    ));
                }
            }

            return (int) $existing_attachment_id;
        }

        if ($this->disable_sideload_in_request) {
            $this->request_image_stats['blocked']++;
            $this->log_import_debug('Sideload blocked: disable_sideload_in_request post_id=' . (int) $post_id . ' url=' . $normalized_url);
            return 0;
        }

        if ($this->sideload_deadline_ts > 0 && microtime(true) >= $this->sideload_deadline_ts) {
            $this->request_image_stats['blocked']++;
            $this->log_import_debug('Sideload blocked: deadline exceeded post_id=' . (int) $post_id . ' url=' . $normalized_url);
            return 0;
        }

        if ($this->sideload_max_attempts_in_request > 0 && $this->sideload_attempts_in_request >= $this->sideload_max_attempts_in_request) {
            $this->request_image_stats['blocked']++;
            $this->log_import_debug('Sideload blocked: max attempts reached post_id=' . (int) $post_id . ' url=' . $normalized_url);
            return 0;
        }

        $this->sideload_attempts_in_request++;
        $this->request_image_stats['attempted']++;

        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $temp_file = download_url($normalized_url, 10);
        if (is_wp_error($temp_file)) {
            $this->request_image_stats['failed']++;
            $this->log_import_debug('Sideload download_url failed post_id=' . (int) $post_id . ' url=' . $normalized_url . ' error=' . $temp_file->get_error_message());
            return 0;
        }

        $parsed_url = parse_url($normalized_url, PHP_URL_PATH);
        if (!is_string($parsed_url) || $parsed_url === '') {
            $parsed_url = 'imported-image.jpg';
        }

        $file_array = array(
            'name' => wp_basename($parsed_url),
            'tmp_name' => $temp_file,
        );

        $attachment_id = media_handle_sideload($file_array, (int) $post_id);

        if (is_wp_error($attachment_id)) {
            $this->request_image_stats['failed']++;
            $this->log_import_debug('Sideload media_handle_sideload failed post_id=' . (int) $post_id . ' url=' . $normalized_url . ' error=' . $attachment_id->get_error_message());
            @unlink($temp_file);
            return 0;
        }

        $this->request_image_stats['success']++;

        update_post_meta((int) $attachment_id, '_sjai_source_image_url', $normalized_url);

        $this->log_import_debug('Sideload success post_id=' . (int) $post_id . ' url=' . $normalized_url . ' attachment_id=' . (int) $attachment_id);

        if (is_array($image_meta)) {
            if (!empty($image_meta['alt']) && is_string($image_meta['alt'])) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($image_meta['alt']));
            }

            if (!empty($image_meta['title']) && is_string($image_meta['title'])) {
                wp_update_post(array(
                    'ID' => (int) $attachment_id,
                    'post_title' => sanitize_text_field($image_meta['title']),
                ));
            }
        }

        return (int) $attachment_id;
    }

    protected function reset_request_image_stats() {
        $this->request_image_stats = array(
            'attempted' => 0,
            'success' => 0,
            'failed' => 0,
            'blocked' => 0,
            'reused' => 0,
        );
    }

    protected function get_request_image_stats() {
        return array(
            'attempted' => isset($this->request_image_stats['attempted']) ? (int) $this->request_image_stats['attempted'] : 0,
            'success' => isset($this->request_image_stats['success']) ? (int) $this->request_image_stats['success'] : 0,
            'failed' => isset($this->request_image_stats['failed']) ? (int) $this->request_image_stats['failed'] : 0,
            'blocked' => isset($this->request_image_stats['blocked']) ? (int) $this->request_image_stats['blocked'] : 0,
            'reused' => isset($this->request_image_stats['reused']) ? (int) $this->request_image_stats['reused'] : 0,
        );
    }

    protected function accumulate_image_stats($current, $delta) {
        $base = array(
            'attempted' => 0,
            'success' => 0,
            'failed' => 0,
            'blocked' => 0,
            'reused' => 0,
        );
        $current = is_array($current) ? $current : array();
        $delta = is_array($delta) ? $delta : array();

        return array(
            'attempted' => (int) (isset($current['attempted']) ? $current['attempted'] : $base['attempted']) + (int) (isset($delta['attempted']) ? $delta['attempted'] : $base['attempted']),
            'success' => (int) (isset($current['success']) ? $current['success'] : $base['success']) + (int) (isset($delta['success']) ? $delta['success'] : $base['success']),
            'failed' => (int) (isset($current['failed']) ? $current['failed'] : $base['failed']) + (int) (isset($delta['failed']) ? $delta['failed'] : $base['failed']),
            'blocked' => (int) (isset($current['blocked']) ? $current['blocked'] : $base['blocked']) + (int) (isset($delta['blocked']) ? $delta['blocked'] : $base['blocked']),
            'reused' => (int) (isset($current['reused']) ? $current['reused'] : $base['reused']) + (int) (isset($delta['reused']) ? $delta['reused'] : $base['reused']),
        );
    }

    protected function get_target_field_options() {
        $options = array(
            'wp:post_title' => 'WordPress: Titolo post (post_title)',
            'wp:post_content' => 'WordPress: Contenuto post (post_content)',
            'wp:post_excerpt' => 'WordPress: Riassunto (post_excerpt)',
            'wp:post_name' => 'WordPress: Slug (post_name)',
            'wp:post_date' => 'WordPress: Data pubblicazione (post_date)',
            'wp:menu_order' => 'WordPress: Ordinamento menu (menu_order)',
            'wp:featured_image' => 'WordPress: Immagine in evidenza',
            'wp:categories' => 'WordPress: Categorie (crea se mancanti)',
            'wp:post_tag' => 'WordPress: Tag (crea se mancanti)',
            'yoast:seo_title' => 'Yoast: SEO Title',
            'yoast:meta_description' => 'Yoast: Meta Description',
            'yoast:focus_keyword' => 'Yoast: Focus Keyword',
            'yoast:canonical' => 'Yoast: Canonical URL',
            'yoast:og_title' => 'Yoast: OpenGraph Title',
            'yoast:og_description' => 'Yoast: OpenGraph Description',
            'yoast:twitter_title' => 'Yoast: Twitter Title',
            'yoast:twitter_description' => 'Yoast: Twitter Description',
        );

        $acf_fields_found = false;

        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            $groups = acf_get_field_groups();
            foreach ($groups as $group) {
                $fields = acf_get_fields($group);
                if (!is_array($fields)) {
                    continue;
                }

                foreach ($fields as $field) {
                    if (empty($field['name'])) {
                        continue;
                    }

                    $name = (string) $field['name'];
                    $label = !empty($field['label']) ? (string) $field['label'] : $name;
                    $type = !empty($field['type']) ? (string) $field['type'] : 'unknown';
                    $options['acf:' . $name] = 'ACF: ' . $label . ' (' . $name . ' - ' . $type . ')';
                    $acf_fields_found = true;
                }
            }
        }

        if (!$acf_fields_found) {
            $options['acf:manual_field_name'] = __('ACF: nessun campo rilevato (controlla ACF Pro attivo)', 'site-json-acf-importer');
        }

        asort($options);

        return $options;
    }

    // -------------------------------------------------------------------------
    // RENDERING METHODS FOR INVERTED FIELD MAP UI
    // -------------------------------------------------------------------------

    protected function get_acf_image_target_keys() {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return array();
        }

        $image_keys = array();
        $groups = acf_get_field_groups();
        foreach ($groups as $group) {
            $fields = acf_get_fields($group);
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $field) {
                if (empty($field['name']) || empty($field['type'])) {
                    continue;
                }
                if (in_array($field['type'], array('image', 'gallery'), true)) {
                    $image_keys[] = 'acf:' . $field['name'];
                }
            }
        }

        return $image_keys;
    }

    protected function render_field_map_section($schema, $settings, $target_options) {
        $acf_image_keys = $this->get_acf_image_target_keys();

        // Export JS data for dynamic rows
        $schema_fields_js = array();
        foreach ($schema['types'] as $type => $fields) {
            $schema_fields_js[sanitize_key($type)] = $fields;
        }

        $target_opts_js = array();
        foreach ($target_options as $k => $v) {
            $is_img = ($k === 'wp:featured_image') || in_array($k, $acf_image_keys, true);
            $target_opts_js[] = array('v' => $k, 'l' => $v, 'img' => $is_img);
        }

        echo '<script>';
        echo 'window.sjaiSchemaFields=' . wp_json_encode($schema_fields_js, JSON_UNESCAPED_UNICODE) . ';';
        echo 'window.sjaiFieldPaths=' . wp_json_encode(isset($schema['field_paths']) ? $schema['field_paths'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
        echo 'window.sjaiTargetOptions=' . wp_json_encode($target_opts_js, JSON_UNESCAPED_UNICODE) . ';';
        echo 'window.sjaiImageTargets=' . wp_json_encode(array_merge(array('wp:featured_image'), $acf_image_keys)) . ';';
        echo '</script>';

        echo '<h3 style="margin-top:20px">' . esc_html__('Mapping campi WordPress &rarr; Drupal', 'site-json-acf-importer') . '</h3>';
        echo '<p class="description">' . esc_html__('Scegli quale campo WordPress popolare e da quale campo Drupal prenderlo. Per i campi immagine puoi configurare anche ALT e TITLE.', 'site-json-acf-importer') . '</p>';

        foreach ($schema['types'] as $source_type => $fields) {
            $type_rows = isset($settings['field_map_v2'][$source_type]) && is_array($settings['field_map_v2'][$source_type])
                ? array_values($settings['field_map_v2'][$source_type])
                : array();

            echo '<h4>' . esc_html(sprintf(__('Tipo: %s', 'site-json-acf-importer'), $source_type)) . '</h4>';
            echo '<table class="widefat sjai-map-table" id="sjai-map-table-' . esc_attr($source_type) . '" data-type="' . esc_attr($source_type) . '" style="max-width:1100px;border-collapse:collapse">';
            echo '<thead><tr>'
                . '<th style="width:30%">' . esc_html__('Campo WordPress (destinazione)', 'site-json-acf-importer') . '</th>'
                . '<th style="width:22%">' . esc_html__('Campo Drupal (sorgente)', 'site-json-acf-importer') . '</th>'
                . '<th style="width:22%">' . esc_html__('Elemento', 'site-json-acf-importer') . '</th>'
                . '<th style="width:15%">' . esc_html__('Anteprima', 'site-json-acf-importer') . '</th>'
                . '<th style="width:11%"></th>'
                . '</tr></thead>';
            echo '<tbody id="sjai-map-tbody-' . esc_attr($source_type) . '">';

            foreach ($type_rows as $row_idx => $row_data) {
                $this->render_field_map_row($source_type, $row_idx, $row_data, $fields, $schema, $target_options, $acf_image_keys);
            }

            echo '</tbody></table>';
            echo '<p><button type="button" class="button sjai-add-row" data-type="' . esc_attr($source_type) . '">+ ' . esc_html__('Aggiungi campo', 'site-json-acf-importer') . '</button></p>';
        }
    }

    protected function render_field_map_row($source_type, $row_idx, $row_data, $fields, $schema, $target_options, $acf_image_keys) {
        $wp_val       = isset($row_data['wp'])             ? (string) $row_data['wp']             : '';
        $src_val      = isset($row_data['src'])            ? (string) $row_data['src']            : '';
        $path_val     = isset($row_data['path'])           ? (string) $row_data['path']           : '';
        $alt_src_val  = isset($row_data['img_alt_src'])    ? (string) $row_data['img_alt_src']    : '';
        $alt_path_val = isset($row_data['img_alt_path'])   ? (string) $row_data['img_alt_path']   : '';
        $ttl_src_val  = isset($row_data['img_title_src'])  ? (string) $row_data['img_title_src']  : '';
        $ttl_path_val = isset($row_data['img_title_path']) ? (string) $row_data['img_title_path'] : '';

        $is_image    = ($wp_val === 'wp:featured_image') || in_array($wp_val, $acf_image_keys, true);
        $field_paths = isset($schema['field_paths'][$source_type][$src_val])     ? $schema['field_paths'][$source_type][$src_val]     : array();
        $alt_paths   = isset($schema['field_paths'][$source_type][$alt_src_val]) ? $schema['field_paths'][$source_type][$alt_src_val] : array();
        $ttl_paths   = isset($schema['field_paths'][$source_type][$ttl_src_val]) ? $schema['field_paths'][$source_type][$ttl_src_val] : array();

        $bn         = 'field_map_v2[' . $source_type . '][' . $row_idx . ']';
        $preview_id = 'sjai-rpath-' . sanitize_html_class($source_type) . '-' . (int) $row_idx;

        // Main row
        echo '<tr class="sjai-map-row" data-row-idx="' . (int) $row_idx . '" data-sjai-type="' . esc_attr($source_type) . '">';

        // WP destination
        echo '<td style="padding:6px 8px"><select name="' . esc_attr($bn . '[wp]') . '" class="sjai-wp-sel" style="width:100%">';
        echo '<option value="">-- ' . esc_html__('Nessuno', 'site-json-acf-importer') . ' --</option>';
        foreach ($target_options as $k => $l) {
            echo '<option value="' . esc_attr($k) . '" ' . selected($wp_val, $k, false) . '>' . esc_html($l) . '</option>';
        }
        echo '</select></td>';

        // Drupal source
        echo '<td style="padding:6px 8px"><select name="' . esc_attr($bn . '[src]') . '" class="sjai-src-sel" style="width:100%">';
        echo '<option value="">--</option>';
        foreach ($fields as $f) {
            echo '<option value="' . esc_attr($f) . '" ' . selected($src_val, $f, false) . '>' . esc_html($f) . '</option>';
        }
        echo '</select></td>';

        // Path
        echo '<td style="padding:6px 8px"><select name="' . esc_attr($bn . '[path]') . '" id="' . esc_attr($preview_id) . '" class="sjai-path-sel" style="width:100%">';
        echo '<option value="">' . esc_html__('Valore completo', 'site-json-acf-importer') . '</option>';
        foreach ($field_paths as $p) {
            echo '<option value="' . esc_attr($p) . '" ' . selected($path_val, $p, false) . '>' . esc_html($p) . '</option>';
        }
        echo '</select></td>';

        // Preview
        echo '<td style="padding:6px 8px">';
        echo '<button type="button" class="button button-small sjai-preview-open-row"'
            . ' data-sjai-type="' . esc_attr($source_type) . '"'
            . ' data-sjai-path-select-id="' . esc_attr($preview_id) . '">'
            . esc_html__('Mostra', 'site-json-acf-importer') . '</button>';
        echo '</td>';

        // Remove
        echo '<td style="padding:6px 8px">';
        echo '<button type="button" class="button button-small sjai-remove-row" style="color:#b32d2e">&times; ' . esc_html__('Rimuovi', 'site-json-acf-importer') . '</button>';
        echo '</td>';

        echo '</tr>';

        // Image sub-row
        echo '<tr class="sjai-img-sub-row" style="' . ($is_image ? '' : 'display:none;') . 'background:#f8f9fa;">';
        echo '<td style="padding:6px 12px;color:#555;font-size:12px;font-style:italic">&#8627; ' . esc_html__('Immagine: ALT e TITLE', 'site-json-acf-importer') . '</td>';
        echo '<td colspan="4" style="padding:6px 8px"><table style="width:100%;font-size:12px;border-collapse:collapse">';
        echo '<tr style="background:#ececec">'
            . '<th style="padding:4px 6px;text-align:left">' . esc_html__('ALT: campo Drupal', 'site-json-acf-importer') . '</th>'
            . '<th style="padding:4px 6px;text-align:left">' . esc_html__('ALT: elemento', 'site-json-acf-importer') . '</th>'
            . '<th style="padding:4px 6px;text-align:left">' . esc_html__('TITLE: campo Drupal', 'site-json-acf-importer') . '</th>'
            . '<th style="padding:4px 6px;text-align:left">' . esc_html__('TITLE: elemento', 'site-json-acf-importer') . '</th>'
            . '</tr><tr>';

        // ALT src
        echo '<td style="padding:4px 6px"><select name="' . esc_attr($bn . '[img_alt_src]') . '" class="sjai-img-alt-src-sel" style="width:100%">';
        echo '<option value="">--</option>';
        foreach ($fields as $f) {
            echo '<option value="' . esc_attr($f) . '" ' . selected($alt_src_val, $f, false) . '>' . esc_html($f) . '</option>';
        }
        echo '</select></td>';

        // ALT path
        echo '<td style="padding:4px 6px"><select name="' . esc_attr($bn . '[img_alt_path]') . '" class="sjai-img-alt-path-sel" style="width:100%">';
        echo '<option value="">' . esc_html__('Valore completo', 'site-json-acf-importer') . '</option>';
        foreach ($alt_paths as $p) {
            echo '<option value="' . esc_attr($p) . '" ' . selected($alt_path_val, $p, false) . '>' . esc_html($p) . '</option>';
        }
        echo '</select></td>';

        // TITLE src
        echo '<td style="padding:4px 6px"><select name="' . esc_attr($bn . '[img_title_src]') . '" class="sjai-img-title-src-sel" style="width:100%">';
        echo '<option value="">--</option>';
        foreach ($fields as $f) {
            echo '<option value="' . esc_attr($f) . '" ' . selected($ttl_src_val, $f, false) . '>' . esc_html($f) . '</option>';
        }
        echo '</select></td>';

        // TITLE path
        echo '<td style="padding:4px 6px"><select name="' . esc_attr($bn . '[img_title_path]') . '" class="sjai-img-title-path-sel" style="width:100%">';
        echo '<option value="">' . esc_html__('Valore completo', 'site-json-acf-importer') . '</option>';
        foreach ($ttl_paths as $p) {
            echo '<option value="' . esc_attr($p) . '" ' . selected($ttl_path_val, $p, false) . '>' . esc_html($p) . '</option>';
        }
        echo '</select></td>';

        echo '</tr></table></td></tr>';
    }

    protected function render_field_map_js() {
        echo '<script>';
        echo '(function(){';
        echo 'var schemaFields = window.sjaiSchemaFields || {};';
        echo 'var fieldPaths = window.sjaiFieldPaths || {};';
        echo 'var targetOpts = window.sjaiTargetOptions || [];';
        echo 'var imageTargets = window.sjaiImageTargets || ["wp:featured_image"];';

        echo 'function isImg(v){ return imageTargets.indexOf(v) !== -1; }';
        echo 'function clearSelect(sel){ while(sel.firstChild){ sel.removeChild(sel.firstChild); } }';
        echo 'function addOpt(sel,val,label,selected){ var o=document.createElement("option"); o.value=val; o.textContent=label; if(selected){ o.selected=true; } sel.appendChild(o); }';
        echo 'function fillWpSelect(sel,selected){ clearSelect(sel); addOpt(sel,"","-- Nessuno --",selected===""); targetOpts.forEach(function(o){ addOpt(sel,o.v,o.l,o.v===selected); }); }';
        echo 'function fillSrcSelect(sel,type,selected){ clearSelect(sel); addOpt(sel,"","--",selected===""); (schemaFields[type]||[]).forEach(function(f){ addOpt(sel,f,f,f===selected); }); }';
        echo 'function fillPathSelect(sel,type,src,selected){ clearSelect(sel); addOpt(sel,"","Valore completo",selected===""); var p=(fieldPaths[type]&&fieldPaths[type][src])?fieldPaths[type][src]:[]; p.forEach(function(v){ addOpt(sel,v,v,v===selected); }); }';
        echo 'function nextIdx(type){ var tb=document.getElementById("sjai-map-tbody-"+type); if(!tb){ return 0; } var max=-1; tb.querySelectorAll("tr.sjai-map-row").forEach(function(r){ var i=parseInt(r.getAttribute("data-row-idx"),10); if(!isNaN(i)&&i>max){ max=i; } }); return max+1; }';

        echo 'function createMainRow(type,idx){';
        echo 'var tr=document.createElement("tr"); tr.className="sjai-map-row"; tr.setAttribute("data-row-idx",String(idx)); tr.setAttribute("data-sjai-type",type);';
        echo 'var bn="field_map_v2["+type+"]["+idx+"]";';
        echo 'var pid="sjai-rpath-"+type+"-"+idx;';

        echo 'var tdWp=document.createElement("td"); tdWp.style.padding="6px 8px";';
        echo 'var wpSel=document.createElement("select"); wpSel.name=bn+"[wp]"; wpSel.className="sjai-wp-sel"; wpSel.style.width="100%"; fillWpSelect(wpSel,""); tdWp.appendChild(wpSel);';

        echo 'var tdSrc=document.createElement("td"); tdSrc.style.padding="6px 8px";';
        echo 'var srcSel=document.createElement("select"); srcSel.name=bn+"[src]"; srcSel.className="sjai-src-sel"; srcSel.style.width="100%"; fillSrcSelect(srcSel,type,""); tdSrc.appendChild(srcSel);';

        echo 'var tdPath=document.createElement("td"); tdPath.style.padding="6px 8px";';
        echo 'var pathSel=document.createElement("select"); pathSel.name=bn+"[path]"; pathSel.id=pid; pathSel.className="sjai-path-sel"; pathSel.style.width="100%"; fillPathSelect(pathSel,type,"",""); tdPath.appendChild(pathSel);';

        echo 'var tdPrev=document.createElement("td"); tdPrev.style.padding="6px 8px";';
        echo 'var pv=document.createElement("button"); pv.type="button"; pv.className="button button-small sjai-preview-open-row"; pv.textContent="Mostra"; pv.setAttribute("data-sjai-type",type); pv.setAttribute("data-sjai-path-select-id",pid); tdPrev.appendChild(pv);';

        echo 'var tdRm=document.createElement("td"); tdRm.style.padding="6px 8px";';
        echo 'var rm=document.createElement("button"); rm.type="button"; rm.className="button button-small sjai-remove-row"; rm.style.color="#b32d2e"; rm.textContent="x Rimuovi"; tdRm.appendChild(rm);';

        echo 'tr.appendChild(tdWp); tr.appendChild(tdSrc); tr.appendChild(tdPath); tr.appendChild(tdPrev); tr.appendChild(tdRm);';
        echo 'return tr;';
        echo '}';

        echo 'function createImgSubRow(type,idx){';
        echo 'var tr=document.createElement("tr"); tr.className="sjai-img-sub-row"; tr.style.display="none"; tr.style.background="#f8f9fa";';
        echo 'var bn="field_map_v2["+type+"]["+idx+"]";';
        echo 'var tdL=document.createElement("td"); tdL.style.padding="6px 12px"; tdL.style.color="#555"; tdL.style.fontSize="12px"; tdL.style.fontStyle="italic"; tdL.textContent="-> Immagine: ALT e TITLE";';
        echo 'var tdC=document.createElement("td"); tdC.colSpan=4; tdC.style.padding="6px 8px";';
        echo 'var wrap=document.createElement("div"); wrap.style.display="grid"; wrap.style.gridTemplateColumns="1fr 1fr 1fr 1fr"; wrap.style.gap="6px";';

        echo 'var as=document.createElement("select"); as.name=bn+"[img_alt_src]"; as.className="sjai-img-alt-src-sel"; as.style.width="100%"; fillSrcSelect(as,type,"");';
        echo 'var ap=document.createElement("select"); ap.name=bn+"[img_alt_path]"; ap.className="sjai-img-alt-path-sel"; ap.style.width="100%"; fillPathSelect(ap,type,"","");';
        echo 'var ts=document.createElement("select"); ts.name=bn+"[img_title_src]"; ts.className="sjai-img-title-src-sel"; ts.style.width="100%"; fillSrcSelect(ts,type,"");';
        echo 'var tp=document.createElement("select"); tp.name=bn+"[img_title_path]"; tp.className="sjai-img-title-path-sel"; tp.style.width="100%"; fillPathSelect(tp,type,"","");';
        echo 'wrap.appendChild(as); wrap.appendChild(ap); wrap.appendChild(ts); wrap.appendChild(tp); tdC.appendChild(wrap);';

        echo 'tr.appendChild(tdL); tr.appendChild(tdC); return tr;';
        echo '}';

        echo 'function bindRow(mainRow,type){';
        echo 'var imgSub=mainRow.nextElementSibling;';
        echo 'var wpSel=mainRow.querySelector(".sjai-wp-sel");';
        echo 'var srcSel=mainRow.querySelector(".sjai-src-sel");';
        echo 'var pathSel=mainRow.querySelector(".sjai-path-sel");';
        echo 'var rmBtn=mainRow.querySelector(".sjai-remove-row");';
        echo 'var pvBtn=mainRow.querySelector(".sjai-preview-open-row");';
        echo 'if(wpSel&&imgSub){ wpSel.addEventListener("change",function(){ imgSub.style.display=isImg(wpSel.value)?"":"none"; }); }';
        echo 'if(srcSel&&pathSel){ srcSel.addEventListener("change",function(){ fillPathSelect(pathSel,type,srcSel.value,""); }); }';
        echo 'if(imgSub){ var as=imgSub.querySelector(".sjai-img-alt-src-sel"); var ap=imgSub.querySelector(".sjai-img-alt-path-sel"); var ts=imgSub.querySelector(".sjai-img-title-src-sel"); var tp=imgSub.querySelector(".sjai-img-title-path-sel");';
        echo 'if(as&&ap){ as.addEventListener("change",function(){ fillPathSelect(ap,type,as.value,""); }); }';
        echo 'if(ts&&tp){ ts.addEventListener("change",function(){ fillPathSelect(tp,type,ts.value,""); }); }';
        echo '}';
        echo 'if(rmBtn){ rmBtn.addEventListener("click",function(){ if(imgSub){ imgSub.remove(); } mainRow.remove(); }); }';
        echo 'if(pvBtn){ pvBtn.addEventListener("click",function(){ var src=srcSel?srcSel.value:""; var pid=pvBtn.getAttribute("data-sjai-path-select-id")||""; if(window.sjaiOpenPreview){ window.sjaiOpenPreview(type,src,pid); } }); }';
        echo '}';

        echo 'document.querySelectorAll("table.sjai-map-table").forEach(function(tbl){ var type=tbl.getAttribute("data-type"); tbl.querySelectorAll("tr.sjai-map-row").forEach(function(r){ bindRow(r,type); }); });';

        echo 'document.querySelectorAll(".sjai-add-row").forEach(function(btn){';
        echo 'btn.addEventListener("click",function(){';
        echo 'var type=btn.getAttribute("data-type");';
        echo 'var tb=document.getElementById("sjai-map-tbody-"+type);';
        echo 'if(!tb){ return; }';
        echo 'var idx=nextIdx(type);';
        echo 'var main=createMainRow(type,idx);';
        echo 'var sub=createImgSubRow(type,idx);';
        echo 'tb.appendChild(main); tb.appendChild(sub);';
        echo 'bindRow(main,type);';
        echo '});';
        echo '});';

        echo '})();';
        echo '</script>';
    }

    protected function extract_body_value($body) {
        if (is_array($body)) {
            if (isset($body['value']) && is_string($body['value'])) {
                return $body['value'];
            }

            if (isset($body[0]) && is_array($body[0]) && isset($body[0]['value']) && is_string($body[0]['value'])) {
                return $body[0]['value'];
            }
        }

        if (is_string($body)) {
            return $body;
        }

        return '';
    }

    protected function looks_like_image_url($url) {
        if (!is_string($url)) {
            return false;
        }

        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        if (preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)(\?.*)?$/i', $url)) {
            return true;
        }

        if (strpos($url, '/sites/default/files/') !== false) {
            return true;
        }

        return false;
    }

    protected function extract_first_image_url_from_value($value, $depth = 0) {
        if ($depth > 6) {
            return '';
        }

        if (is_string($value)) {
            $raw = trim($value);
            if ($raw === '') {
                return '';
            }

            if ($this->looks_like_image_url($raw)) {
                return esc_url_raw($raw);
            }

            if (strpos($raw, '<img') !== false && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $raw, $m) && !empty($m[1]) && $this->looks_like_image_url($m[1])) {
                return esc_url_raw($m[1]);
            }

            return '';
        }

        if (!is_array($value)) {
            return '';
        }

        $priority_keys = array('url', 'uri', 'src', 'href', 'image', 'original', 'download');
        foreach ($priority_keys as $k) {
            if (isset($value[$k])) {
                $found = $this->extract_first_image_url_from_value($value[$k], $depth + 1);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        foreach ($value as $entry) {
            $found = $this->extract_first_image_url_from_value($entry, $depth + 1);
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    protected function find_featured_image_fallback_url($item) {
        if (!is_array($item)) {
            return '';
        }

        $preferred_fields = array(
            'field_image',
            'field_images',
            'field_media_image',
            'field_cover',
            'field_immagine',
            'field_immagine_copertina',
            'image',
            'images',
            'featured_image',
            'thumbnail',
        );

        foreach ($preferred_fields as $field) {
            if (!array_key_exists($field, $item)) {
                continue;
            }

            $found = $this->extract_first_image_url_from_value($item[$field], 0);
            if ($found !== '') {
                return $found;
            }
        }

        if (isset($item['body'])) {
            $body = $this->extract_body_value($item['body']);
            $found = $this->extract_first_image_url_from_value($body, 0);
            if ($found !== '') {
                return $found;
            }
        }

        foreach ($item as $value) {
            $found = $this->extract_first_image_url_from_value($value, 0);
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    protected function extract_all_parent_references($value) {
        $refs = array();

        if (is_null($value) || $value === '' || $value === array()) {
            return $refs;
        }

        // Scalar: single value directly
        if (is_string($value) || is_numeric($value)) {
            $v = trim((string) $value);
            if ($v !== '') {
                $refs[] = $v;
            }
            return $refs;
        }

        if (!is_array($value)) {
            return $refs;
        }

        // Single object: {target_id:X} or {value:X}
        if (isset($value['target_id'])) {
            $refs[] = (string) absint($value['target_id']);
            return $refs;
        }

        if (isset($value['value'])) {
            $v = trim((string) $value['value']);
            if ($v !== '') {
                $refs[] = $v;
            }
            return $refs;
        }

        // Indexed array: [{target_id:X}, {target_id:Y}, ...] or [X, Y, ...]
        foreach ($value as $entry) {
            if (is_array($entry)) {
                if (isset($entry['target_id'])) {
                    $refs[] = (string) absint($entry['target_id']);
                } elseif (isset($entry['value'])) {
                    $v = trim((string) $entry['value']);
                    if ($v !== '') {
                        $refs[] = $v;
                    }
                }
            } elseif (is_string($entry) || is_numeric($entry)) {
                $v = trim((string) $entry);
                if ($v !== '') {
                    $refs[] = $v;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    protected function extract_scalar_value($value) {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            if (isset($value['value']) && (is_string($value['value']) || is_numeric($value['value']))) {
                return (string) $value['value'];
            }
            if (isset($value[0]['value']) && (is_string($value[0]['value']) || is_numeric($value[0]['value']))) {
                return (string) $value[0]['value'];
            }
        }

        return '';
    }

    protected function prepare_ajax_json_response($action_name) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }

        @ini_set('display_errors', '0');

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        ob_start();

        register_shutdown_function(array($this, 'handle_ajax_fatal_shutdown'), $action_name);
    }

    public function handle_ajax_fatal_shutdown($action_name) {
        $error = error_get_last();
        if (!is_array($error) || !isset($error['type'])) {
            return;
        }

        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        if (!in_array($error['type'], $fatal_types, true)) {
            return;
        }

        $current_action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($current_action !== $action_name) {
            return;
        }

        if (function_exists('error_log')) {
            error_log('[SJAI] Fatal in action ' . $action_name . ': ' . (isset($error['message']) ? $error['message'] : 'unknown') . ' in ' . (isset($error['file']) ? $error['file'] : 'unknown') . ':' . (isset($error['line']) ? (int) $error['line'] : 0));
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            status_header(500);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo wp_json_encode(array(
            'success' => false,
            'data' => 'Fatal error durante import batch. Controlla debug.log per dettagli.',
        ));
        exit;
    }

    public function ajax_start_async_import() {
        $this->prepare_ajax_json_response('sjai_start_async_import');

        try {
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error('Non autorizzato.');
            }

            if (!check_ajax_referer('sjai_async_import_nonce', 'nonce', false)) {
                ob_end_clean();
                wp_send_json_error('Nonce non valido.');
            }

            $settings = $this->get_settings();
            $payload = @$this->fetch_endpoint_data($settings['endpoint_url']);

            if (!is_array($payload) || !$payload['ok']) {
                ob_end_clean();
                wp_send_json_error($payload['message'] ?? 'Errore fetch dati.');
            }

            $data = $payload['data'];
            $start_record = isset($settings['import_start_record']) ? max(1, (int) $settings['import_start_record']) : 1;
            $end_record = isset($settings['import_end_record']) ? max(0, (int) $settings['import_end_record']) : 0;
            $max_items = isset($settings['import_max_items']) ? max(0, (int) $settings['import_max_items']) : 0;

            if ($end_record > 0 && $end_record < $start_record) {
                $end_record = $start_record;
            }

            $total = 0;
            foreach ($data as $index => $item) {
                $record_number = (int) $index + 1;
                if ($record_number < $start_record) {
                    continue;
                }
                if ($end_record > 0 && $record_number > $end_record) {
                    break;
                }
                if ($max_items > 0 && $total >= $max_items) {
                    break;
                }
                if (is_array($item)) {
                    $total++;
                }
            }

            $state = array(
                'total' => $total,
                'processed' => 0,
                'cursor' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => array(),
                'image_stats' => array(
                    'attempted' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'blocked' => 0,
                    'reused' => 0,
                ),
                'settings' => $settings,
                'start_record' => $start_record,
                'end_record' => $end_record,
                'max_items' => $max_items,
            );

            if (!set_transient('sjai_import_state_' . get_current_user_id(), $state, HOUR_IN_SECONDS)) {
                ob_end_clean();
                wp_send_json_error('Errore salvataggio stato.');
            }

            ob_end_clean();
            wp_send_json_success(array(
                'total' => $total,
                'message' => 'Import iniziato.',
            ));
        } catch (\Throwable $e) {
            ob_end_clean();
            wp_send_json_error('Eccezione: ' . $e->getMessage());
        }
    }

    public function ajax_process_batch() {
        $this->prepare_ajax_json_response('sjai_process_batch');

        try {
            if (!current_user_can('manage_options')) {
                ob_end_clean();
                wp_send_json_error('Non autorizzato.');
            }

            if (!check_ajax_referer('sjai_async_import_nonce', 'nonce', false)) {
                ob_end_clean();
                wp_send_json_error('Nonce non valido.');
            }

            $user_id = get_current_user_id();
            $state = get_transient('sjai_import_state_' . $user_id);

            if (!is_array($state)) {
                ob_end_clean();
                wp_send_json_error('Stato import non trovato.');
            }

            $settings = isset($state['settings']) && is_array($state['settings']) ? $state['settings'] : array();
            if (empty($settings)) {
                ob_end_clean();
                wp_send_json_error('Impostazioni non trovate nello stato.');
            }

            $payload = @$this->fetch_endpoint_data($settings['endpoint_url']);
            if (!is_array($payload) || !$payload['ok']) {
                ob_end_clean();
                wp_send_json_error('Errore fetch dati: ' . (isset($payload['message']) ? $payload['message'] : 'Sconosciuto'));
            }

            $data = $payload['data'];
            if (!is_array($data)) {
                ob_end_clean();
                wp_send_json_error('Dati non validi.');
            }
        } catch (\Throwable $e) {
            ob_end_clean();
            wp_send_json_error('Eccezione: ' . $e->getMessage());
        }

        @set_time_limit(0);
        $batch_size = 1;
        $request_started_at = microtime(true);
        $max_request_seconds = 50;
        // One post per batch: allow its whole gallery to download in a single request.
        $this->sideload_deadline_ts = $request_started_at + 45;
        $this->sideload_attempts_in_request = 0;
        $this->sideload_max_attempts_in_request = 0;
        $this->disable_sideload_in_request = false;
        $this->reset_request_image_stats();
        $type_map = is_array($settings['type_map_ui']) ? $settings['type_map_ui'] : array();
        $acf_map = is_array($settings['acf_map_ui']) ? $settings['acf_map_ui'] : array();
        $acf_path_map = is_array($settings['acf_map_path_ui']) ? $settings['acf_map_path_ui'] : array();
        $field_map_v2 = is_array($settings['field_map_v2']) ? $settings['field_map_v2'] : array();

        $processed_in_batch = 0;
        $processed_overall = isset($state['processed']) ? (int) $state['processed'] : 0;
        $cursor = isset($state['cursor']) ? max(0, (int) $state['cursor']) : 0;
        $data_count = count($data);
        $start_record = $state['start_record'];
        $end_record = $state['end_record'];
        $max_items = $state['max_items'];

        $imported_post_ids = array();

        try {
            for ($index = $cursor; $index < $data_count; $index++) {
            if ((microtime(true) - $request_started_at) >= $max_request_seconds) {
                break;
            }

            $item = $data[$index];
            $record_number = (int) $index + 1;

            if ($record_number < $start_record) {
                $cursor = $index + 1;
                continue;
            }

            if ($end_record > 0 && $record_number > $end_record) {
                $cursor = $data_count;
                break;
            }

            if ($max_items > 0 && $processed_overall >= $max_items) {
                break;
            }

            if ($processed_in_batch >= $batch_size) {
                break;
            }

            if (!is_array($item)) {
                $state['skipped']++;
                $cursor = $index + 1;
                continue;
            }

            $result = $this->import_single_item($item, $settings, $type_map, $acf_map, $acf_path_map, $field_map_v2, true);

            if ($result['status'] === 'created') {
                $state['created']++;
                if (!empty($result['post_id'])) {
                    $imported_post_ids[] = (int) $result['post_id'];
                }
            } elseif ($result['status'] === 'updated') {
                $state['updated']++;
                if (!empty($result['post_id'])) {
                    $imported_post_ids[] = (int) $result['post_id'];
                }
            } elseif ($result['status'] === 'skipped') {
                $state['skipped']++;
            } else {
                $state['errors'][] = array(
                    'index' => $index,
                    'message' => isset($result['message']) ? $result['message'] : 'Errore import.',
                );
                $state['skipped']++;
            }

            $processed_overall++;
            $processed_in_batch++;
            $cursor = $index + 1;
        }
        } catch (\Throwable $e) {
            ob_end_clean();
            wp_send_json_error('Errore durante import batch: ' . $e->getMessage());
        }

        $state['processed'] = $processed_overall;
        $state['cursor'] = $cursor;
        $batch_image_stats = $this->get_request_image_stats();
        $state['image_stats'] = $this->accumulate_image_stats(
            isset($state['image_stats']) && is_array($state['image_stats']) ? $state['image_stats'] : array(),
            $batch_image_stats
        );

        if (!empty($imported_post_ids)) {
            $stored = get_transient('sjai_imported_post_ids_' . $user_id);
            if (!is_array($stored)) {
                $stored = array();
            }
            $stored = array_merge($stored, $imported_post_ids);
            set_transient('sjai_imported_post_ids_' . $user_id, $stored, HOUR_IN_SECONDS);
        }

        set_transient('sjai_import_state_' . $user_id, $state, HOUR_IN_SECONDS);

        $done = (
            $processed_overall >= $state['total'] ||
            $cursor >= $data_count ||
            ($end_record > 0 && $cursor >= $end_record) ||
            ($max_items > 0 && $processed_overall >= $max_items)
        );

        if ($done && !empty($settings['enable_parent_linking'])) {
            $stored_ids = get_transient('sjai_imported_post_ids_' . $user_id);
            $parent_result = $this->assign_parents_second_pass(is_array($stored_ids) ? $stored_ids : array(), $settings);
            $state['parent_pass'] = $parent_result;
        }

        if ($done) {
            $final_image_stats = isset($state['image_stats']) && is_array($state['image_stats']) ? $state['image_stats'] : array();
            $attempted = isset($final_image_stats['attempted']) ? (int) $final_image_stats['attempted'] : 0;
            $success = isset($final_image_stats['success']) ? (int) $final_image_stats['success'] : 0;
            $failed = isset($final_image_stats['failed']) ? (int) $final_image_stats['failed'] : 0;
            $blocked = isset($final_image_stats['blocked']) ? (int) $final_image_stats['blocked'] : 0;
            $reused = isset($final_image_stats['reused']) ? (int) $final_image_stats['reused'] : 0;
            if ($attempted > 0 || $blocked > 0 || $reused > 0) {
                $status = $failed > 0 ? 'PROBLEMS' : 'OK';
                $this->log_import_debug('Image import summary status=' . $status . ' attempted=' . $attempted . ' success=' . $success . ' failed=' . $failed . ' blocked=' . $blocked . ' reused=' . $reused);
            } else {
                $this->log_import_debug('Image import summary status=NO_IMAGES attempted=0 success=0 failed=0 blocked=0 reused=0');
            }
        }

        ob_end_clean();
        wp_send_json_success(array(
            'processed' => $processed_overall,
            'total' => $state['total'],
            'created' => $state['created'],
            'updated' => $state['updated'],
            'skipped' => $state['skipped'],
            'done' => $done,
            'errors' => array_slice($state['errors'], 0, 10),
            'parent_pass' => isset($state['parent_pass']) ? $state['parent_pass'] : null,
            'image_stats' => isset($state['image_stats']) ? $state['image_stats'] : array('attempted' => 0, 'success' => 0, 'failed' => 0, 'blocked' => 0, 'reused' => 0),
        ));
    }
}
