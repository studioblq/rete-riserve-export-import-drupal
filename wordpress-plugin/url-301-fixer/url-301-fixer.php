<?php
/**
 * Plugin Name: URL 301 Fixer
 * Description: Search and replace old URLs in WordPress content and meta, with per-page and bulk actions.
 * Version: 1.2.0
 * Author: Custom
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: url-301-fixer
 */

if (!defined('ABSPATH')) {
    exit;
}

class URL301Fixer {
    const MENU_SLUG = 'url-301-fixer';
    const CAPABILITY = 'manage_options';

    const NONCE_SEARCH = 'url301f_search';
    const NONCE_REPLACE_SELECTED = 'url301f_replace_selected';
    const NONCE_REPLACE_ALL = 'url301f_replace_all';

    const AJAX_NONCE_SEARCH = 'url301f_search_ajax';
    const TRANSIENT_PREFIX = 'url301f_results_';
    const TRANSIENT_SCAN_PREFIX = 'url301f_scan_';
    const USERMETA_SCAN_PREFIX = 'url301f_scan_';
    const USERMETA_RESULTS_PREFIX = 'url301f_results_';
    const SCAN_BATCH_LIMIT = 10;

    public function init() {
        add_action('admin_menu', array($this, 'register_admin_page'));

        add_action('admin_post_url301f_replace_selected', array($this, 'handle_replace_selected'));
        add_action('admin_post_url301f_replace_all', array($this, 'handle_replace_all'));

        add_action('wp_ajax_url301f_search_start', array($this, 'ajax_search_start'));
        add_action('wp_ajax_url301f_search_process', array($this, 'ajax_search_process'));
    }

    public function register_admin_page() {
        add_management_page(
            __('URL 301 Fixer', 'url-301-fixer'),
            __('URL 301 Fixer', 'url-301-fixer'),
            self::CAPABILITY,
            self::MENU_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $notice = isset($_GET['url301f_notice']) ? sanitize_key(wp_unslash($_GET['url301f_notice'])) : '';
        $token = isset($_GET['url301f_token']) ? sanitize_key(wp_unslash($_GET['url301f_token'])) : '';
        $search_data = $this->get_search_data($token);

        $old_url = isset($search_data['old_url']) ? (string) $search_data['old_url'] : '';
        $new_url = isset($search_data['new_url']) ? (string) $search_data['new_url'] : '';
        $results = isset($search_data['results']) && is_array($search_data['results']) ? $search_data['results'] : array();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('URL 301 Fixer', 'url-301-fixer') . '</h1>';
        echo '<p>' . esc_html__('Search runs in background across all post types. First inspect matched pages, then replace selected pages or replace all.', 'url-301-fixer') . '</p>';

        if ($notice === 'search_done') {
            $pages = count($results);
            $occurrences = $this->count_total_occurrences($results);
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(sprintf(__('Found %1$d pages and %2$d occurrences.', 'url-301-fixer'), $pages, $occurrences))
                . '</p></div>';
        } elseif ($notice === 'replace_done') {
            $updated = isset($_GET['updated']) ? absint($_GET['updated']) : 0;
            $occ = isset($_GET['occ']) ? absint($_GET['occ']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(sprintf(__('Updated %1$d pages and replaced %2$d occurrences.', 'url-301-fixer'), $updated, $occ))
                . '</p></div>';
        } elseif ($notice === 'error') {
            $msg = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : __('Unknown error.', 'url-301-fixer');
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }

        echo '<form id="url301f-search-form" method="post" action="#" style="max-width:980px;">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr>';
        echo '<th scope="row"><label for="url301f-old-url">' . esc_html__('Old URL to find', 'url-301-fixer') . '</label></th>';
        echo '<td><input type="text" id="url301f-old-url" name="old_url" value="' . esc_attr($old_url) . '" class="regular-text" required></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="url301f-new-url">' . esc_html__('New URL to use', 'url-301-fixer') . '</label></th>';
        echo '<td><input type="text" id="url301f-new-url" name="new_url" value="' . esc_attr($new_url) . '" class="regular-text"></td>';
        echo '</tr>';
        echo '</table>';

        echo '<p>';
        echo '<button type="button" class="button button-primary" id="url301f-start-search">' . esc_html__('Search matches in background', 'url-301-fixer') . '</button>';
        echo '</p>';
        echo '</form>';

        echo '<div id="url301f-search-progress" style="display:none;max-width:980px;border:1px solid #ccd0d4;background:#fff;padding:12px;margin:12px 0 18px;">';
        echo '<p id="url301f-search-progress-text" style="margin:0 0 8px;font-weight:600;"></p>';
        echo '<div style="height:20px;background:#f0f0f1;border-radius:4px;overflow:hidden;">';
        echo '<div id="url301f-search-progress-bar" style="height:20px;width:0;background:#2271b1;color:#fff;font-size:12px;line-height:20px;text-align:center;">0%</div>';
        echo '</div>';
        echo '<p id="url301f-search-progress-meta" style="margin:8px 0 0;color:#50575e;"></p>';
        echo '</div>';

        echo '<script>';
        echo '(function(){';
        echo 'var btn=document.getElementById("url301f-start-search");';
        echo 'var oldInput=document.getElementById("url301f-old-url");';
        echo 'var newInput=document.getElementById("url301f-new-url");';
        echo 'var wrap=document.getElementById("url301f-search-progress");';
        echo 'var text=document.getElementById("url301f-search-progress-text");';
        echo 'var meta=document.getElementById("url301f-search-progress-meta");';
        echo 'var bar=document.getElementById("url301f-search-progress-bar");';
        echo 'if(!btn||!oldInput||!newInput||!wrap||!text||!meta||!bar){return;}';
        echo 'var ajaxUrl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';';
        echo 'var ajaxNonce=' . wp_json_encode(wp_create_nonce(self::AJAX_NONCE_SEARCH)) . ';';
        echo 'var doneBase=' . wp_json_encode(add_query_arg(array('page' => self::MENU_SLUG, 'url301f_notice' => 'search_done'), admin_url('tools.php'))) . ';';
        echo 'var scanToken="";';

        echo 'function setProgress(payload){';
        echo 'var processed=(payload&&payload.processed)?parseInt(payload.processed,10):0;';
        echo 'var total=(payload&&payload.total)?parseInt(payload.total,10):0;';
        echo 'var matchedPages=(payload&&payload.matched_pages)?parseInt(payload.matched_pages,10):0;';
        echo 'var matchedOccurrences=(payload&&payload.matched_occurrences)?parseInt(payload.matched_occurrences,10):0;';
        echo 'var pct=total>0?Math.floor((processed/total)*100):0;';
        echo 'if(pct<0){pct=0;}if(pct>100){pct=100;}';
        echo 'bar.style.width=pct+"%";bar.textContent=pct+"%";';
        echo 'text.textContent="Background scan in progress...";';
        echo 'meta.textContent="Processed: "+processed+" / "+total+" | Matched pages: "+matchedPages+" | Occurrences: "+matchedOccurrences;';
        echo '}';

        echo 'function processBatch(){';
        echo 'var xhr=new XMLHttpRequest();';
        echo 'xhr.open("POST",ajaxUrl);';
        echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo 'xhr.timeout=60000;';
        echo 'xhr.onload=function(){';
        echo 'var resp=null;';
        echo 'try{resp=JSON.parse(xhr.responseText);}catch(e){}';
        echo 'if(!resp||!resp.success||!resp.data){';
        echo 'var raw=(xhr.responseText||"").replace(/\s+/g," ").trim().slice(0,220);';
        echo 'var msg=(resp&&resp.data&&resp.data.message)?resp.data.message:"Search failed during processing.";';
        echo 'text.textContent=msg+(raw?" Details: "+raw:"");btn.disabled=false;return;';
        echo '}';
        echo 'setProgress(resp.data);';
        echo 'if(resp.data.done){window.location.href=doneBase+"&url301f_token="+encodeURIComponent(scanToken);return;}';
        echo 'setTimeout(processBatch,120);';
        echo '};';
        echo 'xhr.onerror=function(){text.textContent="Network error during scan.";btn.disabled=false;};';
        echo 'xhr.ontimeout=function(){text.textContent="Batch timeout, retrying...";setTimeout(processBatch,1000);};';
        echo 'xhr.send("action=url301f_search_process&nonce="+encodeURIComponent(ajaxNonce)+"&token="+encodeURIComponent(scanToken));';
        echo '}';

        echo 'btn.addEventListener("click",function(){';
        echo 'var oldUrl=oldInput.value?oldInput.value.trim():"";';
        echo 'if(!oldUrl){alert("Old URL is required.");return;}';
        echo 'btn.disabled=true;';
        echo 'wrap.style.display="block";';
        echo 'text.textContent="Starting background scan...";';
        echo 'meta.textContent="";';
        echo 'bar.style.width="0%";bar.textContent="0%";';

        echo 'var xhr=new XMLHttpRequest();';
        echo 'xhr.open("POST",ajaxUrl);';
        echo 'xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo 'xhr.timeout=30000;';
        echo 'xhr.onload=function(){';
        echo 'var resp=null;';
        echo 'try{resp=JSON.parse(xhr.responseText);}catch(e){}';
        echo 'if(!resp||!resp.success||!resp.data||!resp.data.token){';
        echo 'var raw=(xhr.responseText||"").replace(/\s+/g," ").trim().slice(0,220);';
        echo 'var msg=(resp&&resp.data&&resp.data.message)?resp.data.message:"Search start failed.";';
        echo 'text.textContent=msg+(raw?" Details: "+raw:"");btn.disabled=false;return;';
        echo '}';
        echo 'scanToken=resp.data.token;';
        echo 'setProgress(resp.data);';
        echo 'processBatch();';
        echo '};';
        echo 'xhr.onerror=function(){text.textContent="Network error during search start.";btn.disabled=false;};';
        echo 'xhr.ontimeout=function(){text.textContent="Timeout during search start.";btn.disabled=false;};';
        echo 'xhr.send("action=url301f_search_start&nonce="+encodeURIComponent(ajaxNonce)+"&old_url="+encodeURIComponent(oldUrl)+"&new_url="+encodeURIComponent(newInput.value||""));';
        echo '});';

        echo '})();';
        echo '</script>';

        if (!empty($results)) {
            $this->render_results_table($results, $old_url, $new_url, $token);
        }

        echo '</div>';
    }

    protected function render_results_table(array $results, $old_url, $new_url, $token) {
        echo '<hr>';
        echo '<h2>' . esc_html__('Matched pages', 'url-301-fixer') . '</h2>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="url301f_replace_selected">';
        echo '<input type="hidden" name="url301f_token" value="' . esc_attr($token) . '">';
        echo '<input type="hidden" name="old_url" value="' . esc_attr($old_url) . '">';
        echo '<input type="hidden" name="new_url" value="' . esc_attr($new_url) . '">';
        wp_nonce_field(self::NONCE_REPLACE_SELECTED, 'url301f_nonce');

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="url301f-check-all"></td>';
        echo '<th>' . esc_html__('Title', 'url-301-fixer') . '</th>';
        echo '<th>' . esc_html__('Type', 'url-301-fixer') . '</th>';
        echo '<th>' . esc_html__('Status', 'url-301-fixer') . '</th>';
        echo '<th>' . esc_html__('Permalink', 'url-301-fixer') . '</th>';
        echo '<th>' . esc_html__('Content matches', 'url-301-fixer') . '</th>';
        echo '<th>' . esc_html__('Meta matches', 'url-301-fixer') . '</th>';
        echo '<th>' . esc_html__('Rendered matches', 'url-301-fixer') . '</th>';
        echo '<th>' . esc_html__('Total', 'url-301-fixer') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($results as $post_id => $row) {
            $title = get_the_title($post_id);
            if ($title === '') {
                $title = __('(no title)', 'url-301-fixer');
            }

            $permalink = get_permalink($post_id);
            $type_obj = get_post_type_object($row['post_type']);
            $type_label = $type_obj ? $type_obj->labels->singular_name : $row['post_type'];

            echo '<tr>';
            echo '<th scope="row" class="check-column"><input type="checkbox" name="selected_posts[]" value="' . esc_attr($post_id) . '"></th>';
            echo '<td><strong>' . esc_html($title) . '</strong><br><span style="color:#646970;">ID: ' . esc_html((string) $post_id) . '</span></td>';
            echo '<td>' . esc_html($type_label) . '</td>';
            echo '<td>' . esc_html($row['post_status']) . '</td>';
            echo '<td>';
            if (!empty($permalink)) {
                echo '<a href="' . esc_url($permalink) . '" target="_blank" rel="noopener noreferrer">' . esc_html($permalink) . '</a>';
            } else {
                echo '&mdash;';
            }
            echo '</td>';
            echo '<td>' . esc_html((string) $row['content_matches']) . '</td>';
            echo '<td>' . esc_html((string) $row['meta_matches']) . '</td>';
            echo '<td>' . esc_html((string) ($row['rendered_matches'] ?? 0)) . '</td>';
            echo '<td><strong>' . esc_html((string) $row['total_matches']) . '</strong></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin-top:12px;">';
        submit_button(__('Replace on selected pages', 'url-301-fixer'), 'primary', 'submit', false);
        echo '</p>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . wp_json_encode(__('Confirm replace on all found pages?', 'url-301-fixer')) . ');">';
        echo '<input type="hidden" name="action" value="url301f_replace_all">';
        echo '<input type="hidden" name="url301f_token" value="' . esc_attr($token) . '">';
        echo '<input type="hidden" name="old_url" value="' . esc_attr($old_url) . '">';
        echo '<input type="hidden" name="new_url" value="' . esc_attr($new_url) . '">';
        wp_nonce_field(self::NONCE_REPLACE_ALL, 'url301f_nonce');
        submit_button(__('Replace on all found pages', 'url-301-fixer'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<script>(function(){var checkAll=document.getElementById("url301f-check-all");if(!checkAll){return;}checkAll.addEventListener("change",function(){var boxes=document.querySelectorAll("input[name=\"selected_posts[]\"]");for(var i=0;i<boxes.length;i++){boxes[i].checked=checkAll.checked;}});})();</script>';
    }

    public function ajax_search_start() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => __('Not allowed.', 'url-301-fixer')));
        }

        if (!check_ajax_referer(self::AJAX_NONCE_SEARCH, 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security token expired. Reload this page and try again.', 'url-301-fixer')));
        }

        $old_url = isset($_POST['old_url']) ? trim(sanitize_text_field(wp_unslash($_POST['old_url']))) : '';
        $new_url = isset($_POST['new_url']) ? trim(sanitize_text_field(wp_unslash($_POST['new_url']))) : '';

        if ($old_url === '') {
            wp_send_json_error(array('message' => __('Old URL is required.', 'url-301-fixer')));
        }

        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_status NOT IN ('auto-draft', 'trash', 'inherit')"
        );

        $token = wp_generate_password(12, false, false);

        $scan_state = array(
            'token' => $token,
            'old_url' => $old_url,
            'new_url' => $new_url,
            'last_id' => 0,
            'processed' => 0,
            'total' => max(0, $total),
            'results' => array(),
            'matched_pages' => 0,
            'matched_occurrences' => 0,
        );

        $this->set_scan_state($token, $scan_state);

        wp_send_json_success(array(
            'token' => $token,
            'processed' => 0,
            'total' => $scan_state['total'],
            'matched_pages' => 0,
            'matched_occurrences' => 0,
            'done' => ($scan_state['total'] === 0),
        ));
    }

    public function ajax_search_process() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => __('Not allowed.', 'url-301-fixer')));
        }

        if (!check_ajax_referer(self::AJAX_NONCE_SEARCH, 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security token expired. Reload this page and start a new scan.', 'url-301-fixer')));
        }

        $token = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';
        if ($token === '') {
            wp_send_json_error(array('message' => __('Missing scan token.', 'url-301-fixer')));
        }

        $scan_state = $this->get_scan_state($token);
        if (!is_array($scan_state) || empty($scan_state['old_url'])) {
            wp_send_json_error(array('message' => __('Scan state not found or expired.', 'url-301-fixer')));
        }

        $old_url = (string) $scan_state['old_url'];
        $search_variants = $this->build_search_variants($old_url);

        $rows = $this->load_post_rows_after((int) $scan_state['last_id'], self::SCAN_BATCH_LIMIT);

        if (empty($rows)) {
            $results = isset($scan_state['results']) && is_array($scan_state['results']) ? $scan_state['results'] : array();
            ksort($results);
            $this->store_search_data($scan_state['old_url'], $scan_state['new_url'], $results, $token);
            $this->delete_scan_state($token);

            wp_send_json_success(array(
                'token' => $token,
                'processed' => (int) $scan_state['processed'],
                'total' => (int) $scan_state['total'],
                'matched_pages' => count($results),
                'matched_occurrences' => $this->count_total_occurrences($results),
                'done' => true,
            ));
        }

        $batch_ids = array();
        foreach ($rows as $row) {
            $batch_ids[] = (int) $row['ID'];
        }

        $meta_by_post = $this->load_meta_values_by_post_ids($batch_ids, $search_variants);

        foreach ($rows as $row) {
            $post_id = (int) $row['ID'];
            $content_matches = 0;
            $meta_matches = 0;
            $rendered_matches = 0;

            $content_matches += $this->count_occurrences_in_string((string) $row['post_content'], $search_variants);
            $content_matches += $this->count_occurrences_in_string((string) $row['post_excerpt'], $search_variants);

            if (isset($meta_by_post[$post_id])) {
                foreach ($meta_by_post[$post_id] as $meta_value) {
                    $meta_matches += $this->count_occurrences_in_meta_raw($meta_value, $search_variants);
                }
            }

            $rendered_matches = $this->count_occurrences_in_rendered_post(
                $post_id,
                $search_variants,
                (string) $row['post_status'],
                (string) $row['post_type']
            );

            $total_matches = $content_matches + $meta_matches + $rendered_matches;
            if ($total_matches > 0) {
                if (!isset($scan_state['results'][$post_id])) {
                    $scan_state['results'][$post_id] = array(
                        'post_type' => (string) $row['post_type'],
                        'post_status' => (string) $row['post_status'],
                        'content_matches' => 0,
                        'meta_matches' => 0,
                        'rendered_matches' => 0,
                        'total_matches' => 0,
                    );
                }

                $scan_state['results'][$post_id]['content_matches'] += $content_matches;
                $scan_state['results'][$post_id]['meta_matches'] += $meta_matches;
                $scan_state['results'][$post_id]['rendered_matches'] += $rendered_matches;
                $scan_state['results'][$post_id]['total_matches'] += $total_matches;
            }

            if ($post_id > (int) $scan_state['last_id']) {
                $scan_state['last_id'] = $post_id;
            }
        }

        $scan_state['processed'] = min(
            (int) $scan_state['total'],
            (int) $scan_state['processed'] + count($rows)
        );

        $scan_state['matched_pages'] = count($scan_state['results']);
        $scan_state['matched_occurrences'] = $this->count_total_occurrences($scan_state['results']);

        $this->set_scan_state($token, $scan_state);

        wp_send_json_success(array(
            'token' => $token,
            'processed' => (int) $scan_state['processed'],
            'total' => (int) $scan_state['total'],
            'matched_pages' => (int) $scan_state['matched_pages'],
            'matched_occurrences' => (int) $scan_state['matched_occurrences'],
            'done' => false,
        ));
    }

    public function handle_replace_selected() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Not allowed.', 'url-301-fixer'));
        }

        check_admin_referer(self::NONCE_REPLACE_SELECTED, 'url301f_nonce');

        $token = isset($_POST['url301f_token']) ? sanitize_key(wp_unslash($_POST['url301f_token'])) : '';
        $old_url = isset($_POST['old_url']) ? trim(sanitize_text_field(wp_unslash($_POST['old_url']))) : '';
        $new_url = isset($_POST['new_url']) ? trim(sanitize_text_field(wp_unslash($_POST['new_url']))) : '';

        if ($old_url === '' || $new_url === '') {
            $this->redirect_with_error(__('Old URL and new URL are required for replacement.', 'url-301-fixer'));
        }
        if ($old_url === $new_url) {
            $this->redirect_with_error(__('New URL must be different from old URL.', 'url-301-fixer'));
        }

        $selected_posts = isset($_POST['selected_posts']) && is_array($_POST['selected_posts'])
            ? array_map('absint', wp_unslash($_POST['selected_posts']))
            : array();
        $selected_posts = array_values(array_filter($selected_posts));

        if (empty($selected_posts)) {
            $this->redirect_with_error(__('Select at least one page.', 'url-301-fixer'), $token);
        }

        $stats = $this->replace_on_posts($selected_posts, $old_url, $new_url);
        $fresh_results = $this->find_matches($old_url);
        $fresh_token = $this->store_search_data($old_url, $new_url, $fresh_results, $token);

        $redirect = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'url301f_notice' => 'replace_done',
                'url301f_token' => $fresh_token,
                'updated' => $stats['updated_posts'],
                'occ' => $stats['replaced_occurrences'],
            ),
            admin_url('tools.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_replace_all() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Not allowed.', 'url-301-fixer'));
        }

        check_admin_referer(self::NONCE_REPLACE_ALL, 'url301f_nonce');

        $token = isset($_POST['url301f_token']) ? sanitize_key(wp_unslash($_POST['url301f_token'])) : '';
        $old_url = isset($_POST['old_url']) ? trim(sanitize_text_field(wp_unslash($_POST['old_url']))) : '';
        $new_url = isset($_POST['new_url']) ? trim(sanitize_text_field(wp_unslash($_POST['new_url']))) : '';

        if ($old_url === '' || $new_url === '') {
            $this->redirect_with_error(__('Old URL and new URL are required for replacement.', 'url-301-fixer'), $token);
        }
        if ($old_url === $new_url) {
            $this->redirect_with_error(__('New URL must be different from old URL.', 'url-301-fixer'), $token);
        }

        $search_data = $this->get_search_data($token);
        $results = isset($search_data['results']) && is_array($search_data['results']) ? $search_data['results'] : array();
        $post_ids = array_map('absint', array_keys($results));
        $post_ids = array_values(array_filter($post_ids));

        if (empty($post_ids)) {
            $this->redirect_with_error(__('No pages found for bulk replacement.', 'url-301-fixer'), $token);
        }

        $stats = $this->replace_on_posts($post_ids, $old_url, $new_url);
        $fresh_results = $this->find_matches($old_url);
        $fresh_token = $this->store_search_data($old_url, $new_url, $fresh_results, $token);

        $redirect = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'url301f_notice' => 'replace_done',
                'url301f_token' => $fresh_token,
                'updated' => $stats['updated_posts'],
                'occ' => $stats['replaced_occurrences'],
            ),
            admin_url('tools.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    protected function find_matches($old_url) {
        $search_variants = $this->build_search_variants((string) $old_url);
        if (empty($search_variants)) {
            return array();
        }

        $results = array();
        $last_id = 0;
        $batch_limit = 50;

        while (true) {
            $rows = $this->load_post_rows_after($last_id, $batch_limit);
            if (empty($rows)) {
                break;
            }

            $batch_ids = array();
            foreach ($rows as $row) {
                $batch_ids[] = (int) $row['ID'];
            }
            $meta_by_post = $this->load_meta_values_by_post_ids($batch_ids, $search_variants);

            foreach ($rows as $row) {
                $post_id = (int) $row['ID'];
                $content_matches = 0;
                $meta_matches = 0;

                $content_matches += $this->count_occurrences_in_string((string) $row['post_content'], $search_variants);
                $content_matches += $this->count_occurrences_in_string((string) $row['post_excerpt'], $search_variants);

                if (isset($meta_by_post[$post_id])) {
                    foreach ($meta_by_post[$post_id] as $meta_value) {
                        $meta_matches += $this->count_occurrences_in_meta_raw($meta_value, $search_variants);
                    }
                }

                $total_matches = $content_matches + $meta_matches;
                if ($total_matches > 0) {
                    $results[$post_id] = array(
                        'post_type' => (string) $row['post_type'],
                        'post_status' => (string) $row['post_status'],
                        'content_matches' => (int) $content_matches,
                        'meta_matches' => (int) $meta_matches,
                        'rendered_matches' => 0,
                        'total_matches' => (int) $total_matches,
                    );
                }

                $last_id = max($last_id, $post_id);
            }
        }

        ksort($results);
        return $results;
    }

    protected function replace_on_posts(array $post_ids, $old_url, $new_url) {
        $stats = array(
            'updated_posts' => 0,
            'replaced_occurrences' => 0,
        );

        $search_variants = $this->build_search_variants((string) $old_url);
        if (empty($search_variants)) {
            return $stats;
        }

        foreach ($post_ids as $post_id) {
            $post_id = absint($post_id);
            if ($post_id <= 0) {
                continue;
            }

            $post = get_post($post_id);
            if (!$post || in_array($post->post_status, array('auto-draft', 'trash', 'inherit'), true)) {
                continue;
            }

            $post_changes = array();
            $post_occurrences = 0;

            $content = (string) $post->post_content;
            $count = 0;
            $new_content = $this->replace_occurrences_in_string($content, $search_variants, $new_url, $count);
            if ($count > 0) {
                $post_changes['post_content'] = $new_content;
                $post_occurrences += $count;
            }

            $excerpt = (string) $post->post_excerpt;
            $count = 0;
            $new_excerpt = $this->replace_occurrences_in_string($excerpt, $search_variants, $new_url, $count);
            if ($count > 0) {
                $post_changes['post_excerpt'] = $new_excerpt;
                $post_occurrences += $count;
            }

            if (!empty($post_changes)) {
                $post_changes['ID'] = $post_id;
                wp_update_post(wp_slash($post_changes));
            }

            $meta_occurrences = $this->replace_post_meta_values($post_id, $search_variants, $new_url);
            $post_occurrences += $meta_occurrences;

            if ($post_occurrences > 0) {
                $stats['updated_posts']++;
                $stats['replaced_occurrences'] += $post_occurrences;
            }
        }

        return $stats;
    }

    protected function replace_post_meta_values($post_id, array $search_variants, $new_url) {
        global $wpdb;

        $total = 0;

        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
                $post_id
            ),
            ARRAY_A
        );

        foreach ($meta_rows as $meta_row) {
            $meta_id = (int) $meta_row['meta_id'];
            if ($meta_id <= 0) {
                continue;
            }

            $parsed = maybe_unserialize($meta_row['meta_value']);
            $count = 0;
            $replaced = $this->replace_occurrences_in_mixed($parsed, $search_variants, $new_url, $count);

            if ($count > 0) {
                update_metadata_by_mid('post', $meta_id, $replaced);
                $total += $count;
            }
        }

        return $total;
    }

    protected function replace_occurrences_in_mixed($value, array $search_variants, $new_url, &$count) {
        if (is_string($value)) {
            return $this->replace_occurrences_in_string($value, $search_variants, $new_url, $count);
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $sub_count = 0;
                $value[$k] = $this->replace_occurrences_in_mixed($v, $search_variants, $new_url, $sub_count);
                $count += $sub_count;
            }
            return $value;
        }

        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $sub_count = 0;
                $value->{$k} = $this->replace_occurrences_in_mixed($v, $search_variants, $new_url, $sub_count);
                $count += $sub_count;
            }
            return $value;
        }

        return $value;
    }

    protected function replace_occurrences_in_string($value, array $search_variants, $new_url, &$count) {
        if (!is_string($value) || $value === '' || empty($search_variants)) {
            return $value;
        }

        $replaced = str_replace($search_variants, (string) $new_url, $value, $count_here);
        $count += (int) $count_here;

        return $replaced;
    }

    protected function count_occurrences_in_mixed($value, array $search_variants) {
        if (is_string($value)) {
            $direct = $this->count_occurrences_in_string($value, $search_variants);
            if ($direct > 0) {
                return $direct;
            }

            if (strlen($value) > 200000 || !is_serialized($value)) {
                return 0;
            }

            $parsed = maybe_unserialize($value);
            if ($parsed === $value) {
                return 0;
            }

            return $this->count_occurrences_recursive($parsed, $search_variants);
        }

        return $this->count_occurrences_recursive($value, $search_variants);
    }

    protected function count_occurrences_in_meta_raw($value, array $search_variants) {
        if (!is_scalar($value) && $value !== null) {
            return 0;
        }

        return $this->count_occurrences_in_string((string) $value, $search_variants);
    }

    protected function count_occurrences_in_rendered_post($post_id, array $search_variants, $post_status, $post_type) {
        if ((string) $post_status !== 'publish') {
            return 0;
        }

        if (!is_post_type_viewable($post_type)) {
            return 0;
        }

        $url = get_permalink((int) $post_id);
        if (!is_string($url) || $url === '') {
            return 0;
        }

        $response = wp_remote_get($url, array(
            'timeout' => 2,
            'redirection' => 2,
            'sslverify' => false,
            'user-agent' => 'URL301Fixer/1.2.0',
        ));

        if (is_wp_error($response)) {
            return 0;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 400) {
            return 0;
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            return 0;
        }

        if (strlen($body) > 250000) {
            $body = substr($body, 0, 250000);
        }

        return $this->count_occurrences_in_string($body, $search_variants);
    }

    protected function count_occurrences_recursive($value, array $search_variants) {
        if (is_string($value)) {
            return $this->count_occurrences_in_string($value, $search_variants);
        }

        if (is_array($value)) {
            $total = 0;
            foreach ($value as $item) {
                $total += $this->count_occurrences_recursive($item, $search_variants);
            }
            return $total;
        }

        if (is_object($value)) {
            $total = 0;
            foreach ($value as $item) {
                $total += $this->count_occurrences_recursive($item, $search_variants);
            }
            return $total;
        }

        return 0;
    }

    protected function count_occurrences_in_string($value, array $search_variants) {
        if (!is_string($value) || $value === '' || empty($search_variants)) {
            return 0;
        }

        $total = 0;
        $value_lower = strtolower($value);
        foreach ($search_variants as $variant) {
            if (!is_string($variant) || $variant === '') {
                continue;
            }

            $variant_lower = strtolower($variant);
            if (strpos($value_lower, $variant_lower) !== false) {
                $total += substr_count($value_lower, $variant_lower);
            }
        }

        return $total;
    }

    protected function build_search_variants($old_url) {
        $old_url = trim((string) $old_url);
        if ($old_url === '') {
            return array();
        }

        $variants = array();
        $variants[] = $old_url;
        $variants[] = html_entity_decode($old_url, ENT_QUOTES, 'UTF-8');

        $decoded = rawurldecode($old_url);
        if ($decoded !== $old_url) {
            $variants[] = $decoded;
        }

        foreach (array_values(array_unique($variants)) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            $parts = wp_parse_url($candidate);
            if (is_array($parts) && !empty($parts['path'])) {
                $path = (string) $parts['path'];
                if ($path !== '') {
                    $variants[] = $path;
                    $variants[] = untrailingslashit($path);
                    $variants[] = trailingslashit(untrailingslashit($path));
                }
            }

            if (strpos($candidate, '/') === 0) {
                $home = home_url($candidate);
                $variants[] = $home;
                $variants[] = home_url(untrailingslashit($candidate));
                $variants[] = home_url(trailingslashit(untrailingslashit($candidate)));
            }

            $variants[] = str_replace('/', '\\/', $candidate);
        }

        $clean = array();
        foreach ($variants as $variant) {
            $variant = trim((string) $variant);
            if ($variant === '' || strlen($variant) < 5) {
                continue;
            }
            $clean[$variant] = true;
        }

        $out = array_keys($clean);
        usort($out, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        return $out;
    }

    protected function count_option_matches($post_id, array $search_variants) {
        global $wpdb;

        $count = 0;

        $option_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options}
                 WHERE option_name LIKE %s",
                '%' . $wpdb->esc_like('_post_' . $post_id . '%') . '%'
            ),
            ARRAY_A
        );

        foreach ($option_rows as $row) {
            $count += $this->count_occurrences_in_mixed($row['option_value'], $search_variants);
        }

        return $count;
    }

    protected function load_post_rows_after($last_id, $limit) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_status, post_content, post_excerpt
                 FROM {$wpdb->posts}
                 WHERE ID > %d
                 AND post_status NOT IN ('auto-draft', 'trash', 'inherit')
                 ORDER BY ID ASC
                 LIMIT %d",
                (int) $last_id,
                (int) $limit
            ),
            ARRAY_A
        );
    }

    protected function load_meta_values_by_post_ids(array $post_ids, array $search_variants = array()) {
        global $wpdb;

        $post_ids = array_values(array_filter(array_map('absint', $post_ids)));
        if (empty($post_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $query = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)";
        $params = $post_ids;

        $like_candidates = array();
        foreach ($search_variants as $variant) {
            if (!is_string($variant) || strlen($variant) < 8) {
                continue;
            }
            $like_candidates[$variant] = true;
            if (count($like_candidates) >= 6) {
                break;
            }
        }

        if (!empty($like_candidates)) {
            $like_sql = array();
            foreach (array_keys($like_candidates) as $candidate) {
                $like_sql[] = 'meta_value LIKE %s';
                $params[] = '%' . $wpdb->esc_like($candidate) . '%';
            }
            $query .= ' AND (' . implode(' OR ', $like_sql) . ')';
        }

        $rows = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

        $by_post = array();
        foreach ($rows as $row) {
            $post_id = (int) $row['post_id'];
            if (!isset($by_post[$post_id])) {
                $by_post[$post_id] = array();
            }
            $by_post[$post_id][] = isset($row['meta_value']) ? $row['meta_value'] : '';
        }

        return $by_post;
    }

    protected function count_total_occurrences(array $results) {
        $total = 0;
        foreach ($results as $row) {
            $total += isset($row['total_matches']) ? (int) $row['total_matches'] : 0;
        }
        return $total;
    }

    protected function store_search_data($old_url, $new_url, array $results, $existing_token = '') {
        $token = $existing_token !== '' ? $existing_token : wp_generate_password(12, false, false);

        $data = array(
            'old_url' => (string) $old_url,
            'new_url' => (string) $new_url,
            'results' => $results,
            'created_at' => time(),
        );

        set_transient($this->get_transient_key($token), $data, HOUR_IN_SECONDS);
        update_user_meta(get_current_user_id(), $this->get_search_data_user_meta_key($token), $data);

        return $token;
    }

    protected function get_search_data($token) {
        if ($token === '') {
            return array();
        }

        $data = get_transient($this->get_transient_key($token));
        if (is_array($data)) {
            return $data;
        }

        $fallback = get_user_meta(get_current_user_id(), $this->get_search_data_user_meta_key($token), true);
        if (!is_array($fallback)) {
            return array();
        }

        $created_at = isset($fallback['created_at']) ? (int) $fallback['created_at'] : 0;
        if ($created_at > 0 && (time() - $created_at) > HOUR_IN_SECONDS) {
            delete_user_meta(get_current_user_id(), $this->get_search_data_user_meta_key($token));
            return array();
        }

        return $fallback;
    }

    protected function get_transient_key($token) {
        return self::TRANSIENT_PREFIX . get_current_user_id() . '_' . sanitize_key($token);
    }

    protected function get_search_data_user_meta_key($token) {
        return self::USERMETA_RESULTS_PREFIX . sanitize_key($token);
    }

    protected function get_scan_state_key($token) {
        return self::TRANSIENT_SCAN_PREFIX . get_current_user_id() . '_' . sanitize_key($token);
    }

    protected function get_scan_state_user_meta_key($token) {
        return self::USERMETA_SCAN_PREFIX . sanitize_key($token);
    }

    protected function set_scan_state($token, array $scan_state) {
        set_transient($this->get_scan_state_key($token), $scan_state, HOUR_IN_SECONDS);

        $scan_state['__saved_at'] = time();
        update_user_meta(get_current_user_id(), $this->get_scan_state_user_meta_key($token), $scan_state);
    }

    protected function get_scan_state($token) {
        $state = get_transient($this->get_scan_state_key($token));
        if (is_array($state)) {
            return $state;
        }

        $fallback = get_user_meta(get_current_user_id(), $this->get_scan_state_user_meta_key($token), true);
        if (!is_array($fallback)) {
            return null;
        }

        $saved_at = isset($fallback['__saved_at']) ? (int) $fallback['__saved_at'] : 0;
        if ($saved_at > 0 && (time() - $saved_at) > HOUR_IN_SECONDS) {
            $this->delete_scan_state($token);
            return null;
        }

        unset($fallback['__saved_at']);
        return $fallback;
    }

    protected function delete_scan_state($token) {
        delete_transient($this->get_scan_state_key($token));
        delete_user_meta(get_current_user_id(), $this->get_scan_state_user_meta_key($token));
    }

    protected function redirect_with_error($message, $token = '') {
        $args = array(
            'page' => self::MENU_SLUG,
            'url301f_notice' => 'error',
            'message' => (string) $message,
        );

        if ($token !== '') {
            $args['url301f_token'] = sanitize_key($token);
        }

        $redirect = add_query_arg($args, admin_url('tools.php'));
        wp_safe_redirect($redirect);
        exit;
    }
}

function url301f_bootstrap() {
    $plugin = new URL301Fixer();
    $plugin->init();
}

url301f_bootstrap();
