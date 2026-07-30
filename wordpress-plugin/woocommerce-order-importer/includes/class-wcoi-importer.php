<?php

if (!defined('ABSPATH')) {
    exit;
}

class WCOI_Importer {
    const MENU_SLUG = 'woocommerce-order-importer';
    const OPTION_KEY = 'wcoi_settings';
    const RESULT_TRANSIENT_KEY = 'wcoi_last_import_result_';
    const LAST_RESULT_OPTION_KEY = 'wcoi_last_import_result';
    const CRON_HOOK = 'wcoi_cron_import_orders';

    const NONCE_SAVE = 'wcoi_save_settings';
    const NONCE_IMPORT = 'wcoi_import_orders';
    const NONCE_IMPORT_PRODUCTS = 'wcoi_import_products';
    const NONCE_TEST_CONNECTION = 'wcoi_test_connection';
    const NONCE_SEND_CUSTOMER_NOTICE = 'wcoi_send_customer_notice';
    const CUSTOMER_NOTICE_TRANSIENT_KEY = 'wcoi_customer_notice_result_';

    const META_SOURCE_ORDER_ID = '_wcoi_source_order_id';
    const META_SOURCE_ORDER_NUMBER = '_wcoi_source_order_number';
    const META_SOURCE_STORE = '_wcoi_source_store';
    const META_IMPORTED_AT = '_wcoi_imported_at_gmt';
    const META_SOURCE_DATE_MODIFIED_GMT = '_wcoi_source_date_modified_gmt';
    const META_SOURCE_PRODUCT_ID = '_wcoi_source_product_id';
    const META_SOURCE_VARIATION_ID = '_wcoi_source_variation_id';
    const META_SOURCE_PRODUCT_SKU = '_wcoi_source_product_sku';
    const META_SOURCE_IMAGE_URL = '_wcoi_source_image_url';

    protected $suppress_outgoing_mail_in_request = false;

    public static function activate() {
        $instance = new self();
        $instance->sync_cron_schedule();
    }

    public static function deactivate() {
        self::clear_cron_schedule();
    }

    public function init() {
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'maybe_save_settings'));
        add_action('admin_post_wcoi_import_products', array($this, 'handle_import_products'));
        add_action('admin_post_wcoi_import_orders', array($this, 'handle_import_orders'));
        add_action('admin_post_wcoi_send_customer_notice', array($this, 'handle_send_customer_notice'));
        add_action('admin_post_wcoi_test_connection', array($this, 'handle_test_connection'));
        add_action(self::CRON_HOOK, array($this, 'handle_cron_import'));
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        add_filter('pre_wp_mail', array($this, 'filter_pre_wp_mail'), 10, 2);
        add_filter('woocommerce_hidden_order_itemmeta', array($this, 'filter_hidden_order_item_meta'));
        add_action('init', array($this, 'maybe_bootstrap_cron_schedule'));
    }

    public function filter_pre_wp_mail($return, $atts) {
        $settings = $this->get_settings();

        if (!empty($settings['disable_all_outgoing_mail'])) {
            return true;
        }

        if ($this->suppress_outgoing_mail_in_request) {
            return true;
        }

        return $return;
    }

    public function filter_hidden_order_item_meta($hidden_meta) {
        $hidden_meta[] = '_wcoi_source_sku';
        $hidden_meta[] = self::META_SOURCE_PRODUCT_ID;
        $hidden_meta[] = self::META_SOURCE_VARIATION_ID;

        return array_values(array_unique($hidden_meta));
    }

    public function register_admin_page() {
        if ($this->is_woocommerce_active()) {
            add_submenu_page(
                'woocommerce',
                __('Order Importer', 'woocommerce-order-importer'),
                __('Order Importer', 'woocommerce-order-importer'),
                'manage_woocommerce',
                self::MENU_SLUG,
                array($this, 'render_admin_page')
            );

            return;
        }

        add_management_page(
            __('Order Importer', 'woocommerce-order-importer'),
            __('Order Importer', 'woocommerce-order-importer'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function maybe_save_settings() {
        if (!is_admin()) {
            return;
        }

        if (!isset($_POST['wcoi_action']) || $_POST['wcoi_action'] !== 'save_settings') {
            return;
        }

        if (!$this->current_user_can_manage()) {
            wp_die(esc_html__('Non autorizzato.', 'woocommerce-order-importer'));
        }

        check_admin_referer(self::NONCE_SAVE);

        $settings = $this->get_settings();
        $settings['source_url'] = isset($_POST['source_url']) ? esc_url_raw(trim(wp_unslash($_POST['source_url']))) : '';
        $settings['consumer_key'] = isset($_POST['consumer_key']) ? sanitize_text_field(trim(wp_unslash($_POST['consumer_key']))) : '';
        $settings['consumer_secret'] = isset($_POST['consumer_secret']) ? sanitize_text_field(trim(wp_unslash($_POST['consumer_secret']))) : '';
        $settings['per_page'] = isset($_POST['per_page']) ? max(1, min(100, absint($_POST['per_page']))) : 20;
        $settings['max_pages'] = isset($_POST['max_pages']) ? max(1, absint($_POST['max_pages'])) : 1;
        $settings['statuses'] = isset($_POST['statuses']) ? sanitize_text_field(trim(wp_unslash($_POST['statuses']))) : '';
        $settings['modified_after_gmt'] = isset($_POST['modified_after_gmt']) ? sanitize_text_field(trim(wp_unslash($_POST['modified_after_gmt']))) : '';
        $settings['timeout'] = isset($_POST['timeout']) ? max(5, min(120, absint($_POST['timeout']))) : 30;
        $settings['product_per_page'] = isset($_POST['product_per_page']) ? max(1, min(100, absint($_POST['product_per_page']))) : 20;
        $settings['product_max_pages'] = isset($_POST['product_max_pages']) ? max(1, absint($_POST['product_max_pages'])) : 1;
        $settings['product_statuses'] = isset($_POST['product_statuses']) ? sanitize_text_field(trim(wp_unslash($_POST['product_statuses']))) : '';
        $settings['product_modified_after_gmt'] = isset($_POST['product_modified_after_gmt']) ? sanitize_text_field(trim(wp_unslash($_POST['product_modified_after_gmt']))) : '';
        $settings['product_match_mode'] = isset($_POST['product_match_mode']) ? sanitize_key(wp_unslash($_POST['product_match_mode'])) : 'sku_or_source';
        $settings['product_create_missing'] = !empty($_POST['product_create_missing']) ? 1 : 0;
        $settings['import_product_images'] = !empty($_POST['import_product_images']) ? 1 : 0;
        $settings['replace_product_images'] = !empty($_POST['replace_product_images']) ? 1 : 0;
        $settings['disable_all_outgoing_mail'] = !empty($_POST['disable_all_outgoing_mail']) ? 1 : 0;
        $settings['suppress_emails_during_import'] = !empty($_POST['suppress_emails_during_import']) ? 1 : 0;
        $settings['enable_cron'] = !empty($_POST['enable_cron']) ? 1 : 0;
        $settings['cron_interval'] = isset($_POST['cron_interval']) ? sanitize_key(wp_unslash($_POST['cron_interval'])) : 'hourly';
        $settings['incremental_mode'] = !empty($_POST['incremental_mode']) ? 1 : 0;

        update_option(self::OPTION_KEY, $settings);
        $this->sync_cron_schedule();

        $redirect = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'wcoi_notice' => 'settings_saved',
            ),
            $this->get_admin_page_url()
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_import_products() {
        if (!$this->current_user_can_manage()) {
            wp_die(esc_html__('Non autorizzato.', 'woocommerce-order-importer'));
        }

        if (!$this->is_woocommerce_active()) {
            wp_die(esc_html__('WooCommerce non risulta attivo su questo sito.', 'woocommerce-order-importer'));
        }

        check_admin_referer(self::NONCE_IMPORT_PRODUCTS);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $previous_mail_state = $this->suppress_outgoing_mail_in_request;
        if ($this->should_suppress_emails_during_import()) {
            $this->suppress_outgoing_mail_in_request = true;
        }

        $result = $this->import_products(array('context' => 'manual_products'));
        $this->suppress_outgoing_mail_in_request = $previous_mail_state;
        $this->store_last_result($result, get_current_user_id());

        $redirect = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'wcoi_notice' => 'product_import_done',
            ),
            $this->get_admin_page_url()
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_import_orders() {
        if (!$this->current_user_can_manage()) {
            wp_die(esc_html__('Non autorizzato.', 'woocommerce-order-importer'));
        }

        if (!$this->is_woocommerce_active()) {
            wp_die(esc_html__('WooCommerce non risulta attivo su questo sito.', 'woocommerce-order-importer'));
        }

        check_admin_referer(self::NONCE_IMPORT);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $previous_mail_state = $this->suppress_outgoing_mail_in_request;
        if ($this->should_suppress_emails_during_import()) {
            $this->suppress_outgoing_mail_in_request = true;
        }

        $result = $this->import_orders(array('context' => 'manual'));
        if ($this->should_suppress_emails_during_import()) {
            $result['messages'][] = __('Mail in uscita soppresse durante import ordini.', 'woocommerce-order-importer');
        } elseif ($this->will_order_import_send_notifications()) {
            $result['messages'][] = __('Attenzione: durante questo import le mail di notifica ordine erano attive.', 'woocommerce-order-importer');
        }
        $this->suppress_outgoing_mail_in_request = $previous_mail_state;
        $this->store_last_result($result, get_current_user_id());

        $redirect = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'wcoi_notice' => 'import_done',
            ),
            $this->get_admin_page_url()
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_test_connection() {
        if (!$this->current_user_can_manage()) {
            wp_die(esc_html__('Non autorizzato.', 'woocommerce-order-importer'));
        }

        check_admin_referer(self::NONCE_TEST_CONNECTION);

        $settings = $this->get_settings();
        $test_settings = $settings;
        $test_settings['per_page'] = 1;
        $response = $this->fetch_orders_page($test_settings, 1);

        if (is_wp_error($response)) {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'wcoi_notice' => 'connection_error',
                    'message' => rawurlencode($response->get_error_message()),
                ),
                $this->get_admin_page_url()
            );

            wp_safe_redirect($redirect);
            exit;
        }

        $redirect = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'wcoi_notice' => 'connection_ok',
                'orders' => isset($response['orders']) && is_array($response['orders']) ? count($response['orders']) : 0,
                'pages' => isset($response['total_pages']) ? (int) $response['total_pages'] : 1,
            ),
            $this->get_admin_page_url()
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_send_customer_notice() {
        if (!$this->current_user_can_manage()) {
            wp_die(esc_html__('Non autorizzato.', 'woocommerce-order-importer'));
        }

        check_admin_referer(self::NONCE_SEND_CUSTOMER_NOTICE);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if ($this->are_site_emails_globally_disabled()) {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'wcoi_notice' => 'customer_notice_error',
                    'message' => rawurlencode(__('Le mail del sito sono disattivate nelle impostazioni del plugin.', 'woocommerce-order-importer')),
                ),
                $this->get_admin_page_url()
            );
            wp_safe_redirect($redirect);
            exit;
        }

        $subject = isset($_POST['wcoi_notice_subject']) ? sanitize_text_field(trim(wp_unslash($_POST['wcoi_notice_subject']))) : '';
        $message = isset($_POST['wcoi_notice_message']) ? trim(wp_unslash($_POST['wcoi_notice_message'])) : '';
        $send_mode = isset($_POST['wcoi_send_test']) ? 'test' : 'bulk';

        if ($subject === '' || $message === '') {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'wcoi_notice' => 'customer_notice_error',
                    'message' => rawurlencode(__('Oggetto o messaggio mancanti.', 'woocommerce-order-importer')),
                ),
                $this->get_admin_page_url()
            );
            wp_safe_redirect($redirect);
            exit;
        }

        if ($send_mode === 'test') {
            $test_email = isset($_POST['wcoi_notice_test_email']) ? sanitize_email(trim(wp_unslash($_POST['wcoi_notice_test_email']))) : '';

            if ($test_email === '' || !is_email($test_email)) {
                $redirect = add_query_arg(
                    array(
                        'page' => self::MENU_SLUG,
                        'wcoi_notice' => 'customer_notice_error',
                        'message' => rawurlencode(__('Email di test non valida.', 'woocommerce-order-importer')),
                    ),
                    $this->get_admin_page_url()
                );
                wp_safe_redirect($redirect);
                exit;
            }

            $headers = array('Content-Type: text/plain; charset=UTF-8');
            $ok = wp_mail($test_email, $subject, $message, $headers);

            if (!$ok) {
                $redirect = add_query_arg(
                    array(
                        'page' => self::MENU_SLUG,
                        'wcoi_notice' => 'customer_notice_error',
                        'message' => rawurlencode(__('Invio email di test fallito. Verifica configurazione SMTP/wp_mail del sito.', 'woocommerce-order-importer')),
                    ),
                    $this->get_admin_page_url()
                );
                wp_safe_redirect($redirect);
                exit;
            }

            $result = array(
                'mode' => 'test',
                'test_email' => $test_email,
                'subject' => $subject,
                'sent_at_gmt' => gmdate('c'),
            );
            set_transient(self::CUSTOMER_NOTICE_TRANSIENT_KEY . get_current_user_id(), $result, MINUTE_IN_SECONDS * 30);

            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'wcoi_notice' => 'customer_notice_test_sent',
                ),
                $this->get_admin_page_url()
            );
            wp_safe_redirect($redirect);
            exit;
        }

        $emails = $this->get_imported_order_customer_emails();
        if (empty($emails)) {
            $redirect = add_query_arg(
                array(
                    'page' => self::MENU_SLUG,
                    'wcoi_notice' => 'customer_notice_error',
                    'message' => rawurlencode(__('Nessun destinatario trovato negli ordini importati.', 'woocommerce-order-importer')),
                ),
                $this->get_admin_page_url()
            );
            wp_safe_redirect($redirect);
            exit;
        }

        $sent = 0;
        $failed = 0;
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        foreach ($emails as $email) {
            $ok = wp_mail($email, $subject, $message, $headers);
            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $result = array(
            'total' => count($emails),
            'sent' => $sent,
            'failed' => $failed,
            'subject' => $subject,
            'sent_at_gmt' => gmdate('c'),
        );

        set_transient(self::CUSTOMER_NOTICE_TRANSIENT_KEY . get_current_user_id(), $result, MINUTE_IN_SECONDS * 30);

        $redirect = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'wcoi_notice' => 'customer_notice_sent',
            ),
            $this->get_admin_page_url()
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_cron_import() {
        if (!$this->is_woocommerce_active()) {
            return;
        }

        $previous_mail_state = $this->suppress_outgoing_mail_in_request;
        if ($this->should_suppress_emails_during_import()) {
            $this->suppress_outgoing_mail_in_request = true;
        }

        $product_result = $this->import_products(array('context' => 'cron_products'));
        $order_result = $this->import_orders(array('context' => 'cron_orders'));
        $this->suppress_outgoing_mail_in_request = $previous_mail_state;

        $combined_result = array(
            'context' => 'cron_products_orders',
            'started_at_gmt' => isset($product_result['started_at_gmt']) ? $product_result['started_at_gmt'] : gmdate('c'),
            'finished_at_gmt' => isset($order_result['finished_at_gmt']) ? $order_result['finished_at_gmt'] : gmdate('c'),
            'messages' => array_merge(
                array(
                    sprintf(
                        __('Prodotti: creati %1$d, aggiornati %2$d, errori %3$d.', 'woocommerce-order-importer'),
                        isset($product_result['created']) ? (int) $product_result['created'] : 0,
                        isset($product_result['updated']) ? (int) $product_result['updated'] : 0,
                        isset($product_result['errors']) ? (int) $product_result['errors'] : 0
                    ),
                    sprintf(
                        __('Ordini: creati %1$d, aggiornati %2$d, errori %3$d.', 'woocommerce-order-importer'),
                        isset($order_result['created']) ? (int) $order_result['created'] : 0,
                        isset($order_result['updated']) ? (int) $order_result['updated'] : 0,
                        isset($order_result['errors']) ? (int) $order_result['errors'] : 0
                    ),
                ),
                isset($product_result['messages']) && is_array($product_result['messages']) ? $product_result['messages'] : array(),
                isset($order_result['messages']) && is_array($order_result['messages']) ? $order_result['messages'] : array()
            ),
            'products_created' => isset($product_result['created']) ? (int) $product_result['created'] : 0,
            'products_updated' => isset($product_result['updated']) ? (int) $product_result['updated'] : 0,
            'products_skipped' => isset($product_result['skipped']) ? (int) $product_result['skipped'] : 0,
            'products_errors' => isset($product_result['errors']) ? (int) $product_result['errors'] : 0,
            'products_seen' => isset($product_result['products_seen']) ? (int) $product_result['products_seen'] : 0,
            'created' => isset($order_result['created']) ? (int) $order_result['created'] : 0,
            'updated' => isset($order_result['updated']) ? (int) $order_result['updated'] : 0,
            'skipped' => isset($order_result['skipped']) ? (int) $order_result['skipped'] : 0,
            'errors' => isset($order_result['errors']) ? (int) $order_result['errors'] : 0,
            'orders_seen' => isset($order_result['orders_seen']) ? (int) $order_result['orders_seen'] : 0,
            'pages_processed' => isset($order_result['pages_processed']) ? (int) $order_result['pages_processed'] : 0,
            'watermark_saved' => isset($order_result['watermark_saved']) ? (string) $order_result['watermark_saved'] : '',
            'product_watermark_saved' => isset($product_result['watermark_saved']) ? (string) $product_result['watermark_saved'] : '',
        );

        $this->store_last_result($combined_result, 0);
    }

    public function register_cron_schedules($schedules) {
        $schedules['wcoi_fifteen_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Ogni 15 minuti', 'woocommerce-order-importer'),
        );

        $schedules['wcoi_thirty_minutes'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Ogni 30 minuti', 'woocommerce-order-importer'),
        );

        return $schedules;
    }

    public function maybe_bootstrap_cron_schedule() {
        $settings = $this->get_settings();
        if (!empty($settings['enable_cron']) && !wp_next_scheduled(self::CRON_HOOK)) {
            $this->sync_cron_schedule();
        }
    }

    public function render_admin_page() {
        if (!$this->current_user_can_manage()) {
            return;
        }

        $settings = $this->get_settings();
        $notice = isset($_GET['wcoi_notice']) ? sanitize_key(wp_unslash($_GET['wcoi_notice'])) : '';
        $result = $this->get_last_result(get_current_user_id());
        $cron_next = wp_next_scheduled(self::CRON_HOOK);
        $customer_notice_result = get_transient(self::CUSTOMER_NOTICE_TRANSIENT_KEY . get_current_user_id());
        $order_import_notifications_active = $this->will_order_import_send_notifications();

        $default_notice_subject = __('Comunicazione importante su una notifica ordine ricevuta per errore', 'woocommerce-order-importer');
        $default_notice_message = implode("\n", array(
            __('Gentile cliente,', 'woocommerce-order-importer'),
            '',
            __('potresti aver ricevuto una notifica ordine generata per errore durante una procedura tecnica di importazione dello storico ordini.', 'woocommerce-order-importer'),
            __('L\'ordine in questione era stato effettuato sul sito tempo fa ed era gia stato evaso.', 'woocommerce-order-importer'),
            __('Confermiamo che non e stato effettuato alcun nuovo addebito e non verra spedito nulla.', 'woocommerce-order-importer'),
            '',
            __('Ci scusiamo per il disagio e restiamo a disposizione per qualsiasi chiarimento.', 'woocommerce-order-importer'),
            '',
            __('Grazie per la comprensione.', 'woocommerce-order-importer'),
        ));

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WooCommerce Order Importer', 'woocommerce-order-importer') . '</h1>';
        echo '<p>' . esc_html__('Installa questo plugin sul sito B, inserisci URL e API key del sito A, poi lancia l\'import degli ordini tramite REST API WooCommerce.', 'woocommerce-order-importer') . '</p>';

        if (!$this->is_woocommerce_active()) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('WooCommerce deve essere attivo sul sito B prima di importare gli ordini.', 'woocommerce-order-importer')
                . '</p></div>';
        }

        if ($notice === 'settings_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Impostazioni salvate.', 'woocommerce-order-importer')
                . '</p></div>';
        }

        if ($this->are_site_emails_globally_disabled()) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('Le mail in uscita del sito sono attualmente disattivate da questo plugin.', 'woocommerce-order-importer')
                . '</p></div>';
        } elseif ($order_import_notifications_active) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Attenzione: le mail di notifica sono attive. Se importi ordini adesso, WooCommerce puo inviare email a clienti e amministratori.', 'woocommerce-order-importer')
                . '</p></div>';
        }

        if ($notice === 'customer_notice_test_sent' && is_array($customer_notice_result)) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(
                    sprintf(
                        __('Email di test inviata con successo a %s.', 'woocommerce-order-importer'),
                        isset($customer_notice_result['test_email']) ? (string) $customer_notice_result['test_email'] : __('destinatario test', 'woocommerce-order-importer')
                    )
                )
                . '</p></div>';
        } elseif ($notice === 'customer_notice_sent' && is_array($customer_notice_result)) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(
                    sprintf(
                        __('Comunicazione inviata. Totale destinatari: %1$d, inviate: %2$d, fallite: %3$d.', 'woocommerce-order-importer'),
                        isset($customer_notice_result['total']) ? (int) $customer_notice_result['total'] : 0,
                        isset($customer_notice_result['sent']) ? (int) $customer_notice_result['sent'] : 0,
                        isset($customer_notice_result['failed']) ? (int) $customer_notice_result['failed'] : 0
                    )
                )
                . '</p></div>';
        } elseif ($notice === 'customer_notice_error') {
            $message = isset($_GET['message']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['message']))) : __('Errore invio comunicazione.', 'woocommerce-order-importer');
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        } elseif ($notice === 'product_import_done' && is_array($result)) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(
                    sprintf(
                        __('Import prodotti completato. Creati: %1$d, aggiornati: %2$d, saltati: %3$d, errori: %4$d.', 'woocommerce-order-importer'),
                        isset($result['created']) ? (int) $result['created'] : 0,
                        isset($result['updated']) ? (int) $result['updated'] : 0,
                        isset($result['skipped']) ? (int) $result['skipped'] : 0,
                        isset($result['errors']) ? (int) $result['errors'] : 0
                    )
                )
                . '</p></div>';
        } elseif ($notice === 'connection_ok') {
            $orders = isset($_GET['orders']) ? absint($_GET['orders']) : 0;
            $pages = isset($_GET['pages']) ? absint($_GET['pages']) : 1;
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(sprintf(__('Connessione API riuscita. Pagina test letta correttamente. Ordini ricevuti: %1$d. Pagine totali segnalate: %2$d.', 'woocommerce-order-importer'), $orders, $pages))
                . '</p></div>';
        } elseif ($notice === 'connection_error') {
            $message = isset($_GET['message']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['message']))) : __('Errore di connessione sconosciuto.', 'woocommerce-order-importer');
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html(sprintf(__('Test connessione fallito: %s', 'woocommerce-order-importer'), $message))
                . '</p></div>';
        } elseif ($notice === 'import_done' && is_array($result)) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(
                    sprintf(
                        __('Import completato. Creati: %1$d, aggiornati: %2$d, saltati: %3$d, errori: %4$d.', 'woocommerce-order-importer'),
                        isset($result['created']) ? (int) $result['created'] : 0,
                        isset($result['updated']) ? (int) $result['updated'] : 0,
                        isset($result['skipped']) ? (int) $result['skipped'] : 0,
                        isset($result['errors']) ? (int) $result['errors'] : 0
                    )
                )
                . '</p></div>';
        }

        echo '<form method="post" action="">';
        wp_nonce_field(self::NONCE_SAVE);
        echo '<input type="hidden" name="wcoi_action" value="save_settings">';
        echo '<table class="form-table" role="presentation" style="max-width:900px;">';
        echo '<tr><th scope="row"><label for="wcoi-source-url">' . esc_html__('URL sito A', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="url" class="regular-text" id="wcoi-source-url" name="source_url" value="' . esc_attr($settings['source_url']) . '" placeholder="https://example.com" required></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-consumer-key">' . esc_html__('Consumer Key', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="wcoi-consumer-key" name="consumer_key" value="' . esc_attr($settings['consumer_key']) . '" required></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-consumer-secret">' . esc_html__('Consumer Secret', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="password" class="regular-text" id="wcoi-consumer-secret" name="consumer_secret" value="' . esc_attr($settings['consumer_secret']) . '" autocomplete="new-password" required></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-per-page">' . esc_html__('Ordini per pagina', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="number" min="1" max="100" id="wcoi-per-page" name="per_page" value="' . esc_attr((string) $settings['per_page']) . '"> <p class="description">' . esc_html__('Quanti ordini chiedere per ogni chiamata API.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-max-pages">' . esc_html__('Pagine massime per esecuzione', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="number" min="1" id="wcoi-max-pages" name="max_pages" value="' . esc_attr((string) $settings['max_pages']) . '"> <p class="description">' . esc_html__('Usa un numero piccolo per test iniziali, poi aumentalo per import più estesi.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-statuses">' . esc_html__('Status ordini', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="wcoi-statuses" name="statuses" value="' . esc_attr($settings['statuses']) . '" placeholder="processing,completed,on-hold"> <p class="description">' . esc_html__('Facoltativo. Lista separata da virgole.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-modified-after">' . esc_html__('Modified after GMT', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="wcoi-modified-after" name="modified_after_gmt" value="' . esc_attr($settings['modified_after_gmt']) . '" placeholder="2026-07-01T00:00:00"> <p class="description">' . esc_html__('Facoltativo. Importa solo ordini modificati dopo questa data GMT (formato ISO8601 senza timezone).', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-timeout">' . esc_html__('Timeout HTTP', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="number" min="5" max="120" id="wcoi-timeout" name="timeout" value="' . esc_attr((string) $settings['timeout']) . '"> <p class="description">' . esc_html__('Secondi massimi per chiamata verso il sito A.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-product-per-page">' . esc_html__('Prodotti per pagina', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="number" min="1" max="100" id="wcoi-product-per-page" name="product_per_page" value="' . esc_attr((string) $settings['product_per_page']) . '"> <p class="description">' . esc_html__('Quanti prodotti chiedere per ogni chiamata API prodotti.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-product-max-pages">' . esc_html__('Pagine prodotti per esecuzione', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="number" min="1" id="wcoi-product-max-pages" name="product_max_pages" value="' . esc_attr((string) $settings['product_max_pages']) . '"> <p class="description">' . esc_html__('Limite pagine per import prodotti in una singola esecuzione.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-product-statuses">' . esc_html__('Status prodotti', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="wcoi-product-statuses" name="product_statuses" value="' . esc_attr($settings['product_statuses']) . '" placeholder="publish,draft,private"> <p class="description">' . esc_html__('Facoltativo. Status prodotti da importare, separati da virgole.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-product-modified-after">' . esc_html__('Prodotti modified after GMT', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="wcoi-product-modified-after" name="product_modified_after_gmt" value="' . esc_attr($settings['product_modified_after_gmt']) . '" placeholder="2026-07-01T00:00:00"> <p class="description">' . esc_html__('Facoltativo. Importa solo prodotti modificati dopo questa data GMT.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-product-match-mode">' . esc_html__('Matching prodotti', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><select id="wcoi-product-match-mode" name="product_match_mode">';
        $product_match_modes = array(
            'sku_or_source' => __('Prima SKU, poi ID sorgente', 'woocommerce-order-importer'),
            'sku_only' => __('Solo SKU', 'woocommerce-order-importer'),
            'source_only' => __('Solo ID sorgente', 'woocommerce-order-importer'),
        );
        foreach ($product_match_modes as $mode_key => $mode_label) {
            echo '<option value="' . esc_attr($mode_key) . '" ' . selected($settings['product_match_mode'], $mode_key, false) . '>' . esc_html($mode_label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Consigliato: Prima SKU, poi ID sorgente. Se hai prodotti XML già presenti, evita duplicati allineando SKU.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Creazione prodotti mancanti', 'woocommerce-order-importer') . '</th>';
        echo '<td><label><input type="checkbox" name="product_create_missing" value="1" ' . checked(!empty($settings['product_create_missing']), true, false) . '> ' . esc_html__('Crea nuovi prodotti se non trova match', 'woocommerce-order-importer') . '</label>';
        echo '<p class="description">' . esc_html__('Disattiva per modalità sicura: aggiorna solo prodotti già esistenti.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Immagini prodotto', 'woocommerce-order-importer') . '</th>';
        echo '<td><label><input type="checkbox" name="import_product_images" value="1" ' . checked(!empty($settings['import_product_images']), true, false) . '> ' . esc_html__('Importa immagini prodotto da WooCommerce remoto', 'woocommerce-order-importer') . '</label><br>';
        echo '<label><input type="checkbox" name="replace_product_images" value="1" ' . checked(!empty($settings['replace_product_images']), true, false) . '> ' . esc_html__('Sostituisci immagini esistenti con quelle remote', 'woocommerce-order-importer') . '</label>';
        echo '<p class="description">' . esc_html__('Se non attivi sostituzione, mantiene le immagini già presenti sul prodotto locale.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Gestione mail', 'woocommerce-order-importer') . '</th>';
        echo '<td><label><input type="checkbox" name="disable_all_outgoing_mail" value="1" ' . checked(!empty($settings['disable_all_outgoing_mail']), true, false) . '> ' . esc_html__('Disattiva tutte le mail in uscita del sito', 'woocommerce-order-importer') . '</label><br>';
        echo '<label><input type="checkbox" name="suppress_emails_during_import" value="1" ' . checked(!empty($settings['suppress_emails_during_import']), true, false) . '> ' . esc_html__('Sopprimi tutte le mail solo durante gli import', 'woocommerce-order-importer') . '</label>';
        echo '<p class="description">' . esc_html__('Consigliato: lascia attiva almeno la soppressione durante import ordini.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Import incrementale automatico', 'woocommerce-order-importer') . '</th>';
        echo '<td><label><input type="checkbox" name="enable_cron" value="1" ' . checked(!empty($settings['enable_cron']), true, false) . '> ' . esc_html__('Attiva il cron importer', 'woocommerce-order-importer') . '</label><br>';
        echo '<label><input type="checkbox" name="incremental_mode" value="1" ' . checked(!empty($settings['incremental_mode']), true, false) . '> ' . esc_html__('Usa import incrementale basato sull\'ultima modifica remota processata', 'woocommerce-order-importer') . '</label>';
        echo '<p class="description">' . esc_html__('Quando attivo, il cron importa prima prodotti e poi ordini, limitandosi agli elementi modificati dopo l\'ultimo sync riuscito.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-cron-interval">' . esc_html__('Frequenza cron', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><select id="wcoi-cron-interval" name="cron_interval">';
        foreach ($this->get_available_cron_intervals() as $interval_key => $interval_label) {
            echo '<option value="' . esc_attr($interval_key) . '" ' . selected($settings['cron_interval'], $interval_key, false) . '>' . esc_html($interval_label) . '</option>';
        }
        echo '</select>';
        if ($cron_next) {
            echo ' <p class="description">' . esc_html(sprintf(__('Prossima esecuzione cron prevista: %s', 'woocommerce-order-importer'), wp_date('Y-m-d H:i:s', $cron_next))) . '</p>';
        } else {
            echo ' <p class="description">' . esc_html__('Nessun cron pianificato al momento.', 'woocommerce-order-importer') . '</p>';
        }
        echo '</td></tr>';
        echo '</table>';
        submit_button(__('Salva impostazioni', 'woocommerce-order-importer'));
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
        echo '<input type="hidden" name="action" value="wcoi_test_connection">';
        wp_nonce_field(self::NONCE_TEST_CONNECTION);
        submit_button(__('Test connessione API', 'woocommerce-order-importer'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<hr>';
        echo '<h2>' . esc_html__('Comunicazione clienti ordini importati', 'woocommerce-order-importer') . '</h2>';
        echo '<p>' . esc_html__('Invia un messaggio a tutte le email uniche trovate negli ordini importati (meta _wcoi_source_order_id).', 'woocommerce-order-importer') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:960px;">';
        echo '<input type="hidden" name="action" value="wcoi_send_customer_notice">';
        wp_nonce_field(self::NONCE_SEND_CUSTOMER_NOTICE);
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="wcoi-notice-subject">' . esc_html__('Oggetto email', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="wcoi-notice-subject" name="wcoi_notice_subject" value="' . esc_attr($default_notice_subject) . '" required></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-notice-message">' . esc_html__('Messaggio', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><textarea id="wcoi-notice-message" name="wcoi_notice_message" rows="10" class="large-text" required>' . esc_textarea($default_notice_message) . '</textarea>';
        echo '<p class="description">' . esc_html__('Puoi modificare il testo prima dell\'invio. Il sistema invia una email per ogni destinatario.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="wcoi-notice-test-email">' . esc_html__('Email test', 'woocommerce-order-importer') . '</label></th>';
        echo '<td><input type="email" class="regular-text" id="wcoi-notice-test-email" name="wcoi_notice_test_email" value="' . esc_attr(wp_get_current_user()->user_email) . '" placeholder="nome@dominio.it">';
        echo '<p class="description">' . esc_html__('Usata solo dal pulsante Invia test.', 'woocommerce-order-importer') . '</p></td></tr>';
        echo '</table>';
        submit_button(__('Invia test', 'woocommerce-order-importer'), 'secondary', 'wcoi_send_test', false);
        echo ' ';
        submit_button(__('Invia comunicazione ai clienti degli ordini importati', 'woocommerce-order-importer'), 'primary', 'wcoi_send_bulk', false);
        echo '</form>';

        echo '<hr>';
        echo '<h2>' . esc_html__('Import prodotti', 'woocommerce-order-importer') . '</h2>';
        echo '<p>' . esc_html__('Importa o aggiorna i prodotti del sito A nel sito B (matching per SKU, oppure per ID prodotto remoto salvato in meta).', 'woocommerce-order-importer') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wcoi_import_products">';
        wp_nonce_field(self::NONCE_IMPORT_PRODUCTS);
        submit_button(__('Importa prodotti adesso', 'woocommerce-order-importer'), 'secondary', 'submit', false, $this->is_woocommerce_active() ? array() : array('disabled' => 'disabled'));
        echo '</form>';

        echo '<hr>';
        echo '<h2>' . esc_html__('Import ordini', 'woocommerce-order-importer') . '</h2>';
        echo '<p>' . esc_html__('Il plugin crea nuovi ordini oppure aggiorna quelli già importati usando l\'ID ordine del sito A come riferimento univoco.', 'woocommerce-order-importer') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wcoi_import_orders">';
        wp_nonce_field(self::NONCE_IMPORT);
        submit_button(__('Importa ordini adesso', 'woocommerce-order-importer'), 'primary', 'submit', false, $this->is_woocommerce_active() ? array() : array('disabled' => 'disabled'));
        echo '</form>';

        if (is_array($result)) {
            echo '<h2>' . esc_html__('Log ultimo import', 'woocommerce-order-importer') . '</h2>';
            echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1100px;">';
            echo '<p><strong>' . esc_html__('Contesto', 'woocommerce-order-importer') . ':</strong> ' . esc_html(isset($result['context']) ? $result['context'] : 'manual') . '<br>';
            echo '<strong>' . esc_html__('Avvio', 'woocommerce-order-importer') . ':</strong> ' . esc_html(isset($result['started_at_gmt']) ? $result['started_at_gmt'] : '-') . '<br>';
            echo '<strong>' . esc_html__('Fine', 'woocommerce-order-importer') . ':</strong> ' . esc_html(isset($result['finished_at_gmt']) ? $result['finished_at_gmt'] : '-') . '<br>';
            echo '<strong>' . esc_html__('Pagine elaborate', 'woocommerce-order-importer') . ':</strong> ' . esc_html(isset($result['pages_processed']) ? (string) $result['pages_processed'] : '0') . '<br>';
            echo '<strong>' . esc_html__('Prodotti visti', 'woocommerce-order-importer') . ':</strong> ' . esc_html(isset($result['products_seen']) ? (string) $result['products_seen'] : '0') . '<br>';
            echo '<strong>' . esc_html__('Ordini visti', 'woocommerce-order-importer') . ':</strong> ' . esc_html(isset($result['orders_seen']) ? (string) $result['orders_seen'] : '0') . '<br>';
            echo '<strong>' . esc_html__('Watermark prodotti', 'woocommerce-order-importer') . ':</strong> ' . esc_html(isset($result['product_watermark_saved']) ? (string) $result['product_watermark_saved'] : '-') . '<br>';
            echo '<strong>' . esc_html__('Watermark incrementale', 'woocommerce-order-importer') . ':</strong> ' . esc_html(isset($result['watermark_saved']) ? (string) $result['watermark_saved'] : '-') . '</p>';
            if (!empty($result['messages'])) {
                echo '<ul style="margin:0;padding-left:18px;">';
                foreach ($result['messages'] as $message) {
                    echo '<li>' . esc_html($message) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    protected function import_products(array $args = array()) {
        $settings = $this->get_settings();
        $context = isset($args['context']) ? sanitize_key($args['context']) : 'manual_products';
        $started_at_gmt = gmdate('c');
        $result = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => array(),
            'context' => $context,
            'pages_processed' => 0,
            'products_seen' => 0,
            'started_at_gmt' => $started_at_gmt,
            'finished_at_gmt' => '',
            'watermark_saved' => '',
        );

        if (strpos($context, 'cron') === 0 && !empty($settings['incremental_mode'])) {
            $last_successful_sync_gmt = get_option('wcoi_last_successful_product_sync_gmt', '');
            if ($last_successful_sync_gmt !== '') {
                $settings['product_modified_after_gmt'] = $last_successful_sync_gmt;
                $result['messages'][] = sprintf(__('Import prodotti incrementale attivo da watermark %s.', 'woocommerce-order-importer'), $last_successful_sync_gmt);
            }
        }

        $page = 1;
        $pages_processed = 0;
        $latest_modified_gmt = '';

        while ($page > 0 && $pages_processed < (int) $settings['product_max_pages']) {
            $remote = $this->fetch_products_page($settings, $page);

            if (is_wp_error($remote)) {
                $result['errors']++;
                $result['messages'][] = sprintf(
                    __('Prodotti pagina %1$d: %2$s', 'woocommerce-order-importer'),
                    $page,
                    $remote->get_error_message()
                );
                break;
            }

            $products = isset($remote['products']) && is_array($remote['products']) ? $remote['products'] : array();
            $result['products_seen'] += count($products);

            if (empty($products)) {
                $result['messages'][] = sprintf(
                    __('Prodotti pagina %d: nessun elemento da importare.', 'woocommerce-order-importer'),
                    $page
                );
                break;
            }

            foreach ($products as $source_product) {
                if (!empty($source_product['date_modified_gmt'])) {
                    $latest_modified_gmt = $this->max_iso8601_value($latest_modified_gmt, (string) $source_product['date_modified_gmt']);
                }

                $sync_result = $this->sync_single_product($source_product, $settings);

                if (is_wp_error($sync_result)) {
                    $result['errors']++;
                    $result['messages'][] = sprintf(
                        __('Prodotto %1$s: %2$s', 'woocommerce-order-importer'),
                        isset($source_product['id']) ? (string) $source_product['id'] : __('sconosciuto', 'woocommerce-order-importer'),
                        $sync_result->get_error_message()
                    );
                    continue;
                }

                $status = isset($sync_result['status']) ? $sync_result['status'] : 'skipped';
                if (!isset($result[$status])) {
                    $result[$status] = 0;
                }
                $result[$status]++;
                $result['messages'][] = isset($sync_result['message']) ? $sync_result['message'] : __('Prodotto sincronizzato.', 'woocommerce-order-importer');
            }

            $pages_processed++;
            $result['pages_processed'] = $pages_processed;

            if ($page >= (int) $remote['total_pages']) {
                break;
            }

            $page++;
        }

        $result['finished_at_gmt'] = gmdate('c');

        if ($result['errors'] === 0 && !empty($settings['incremental_mode'])) {
            $watermark = $latest_modified_gmt !== '' ? $latest_modified_gmt : $result['finished_at_gmt'];
            update_option('wcoi_last_successful_product_sync_gmt', $watermark, false);
            $result['watermark_saved'] = $watermark;
        }

        return $result;
    }

    protected function import_orders(array $args = array()) {
        $settings = $this->get_settings();
        $context = isset($args['context']) ? sanitize_key($args['context']) : 'manual';
        $started_at_gmt = gmdate('c');
        $result = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => array(),
            'context' => $context,
            'pages_processed' => 0,
            'orders_seen' => 0,
            'started_at_gmt' => $started_at_gmt,
            'finished_at_gmt' => '',
            'watermark_saved' => '',
        );

        if (strpos($context, 'cron') === 0 && !empty($settings['incremental_mode'])) {
            $last_successful_sync_gmt = get_option('wcoi_last_successful_sync_gmt', '');
            if ($last_successful_sync_gmt !== '') {
                $settings['modified_after_gmt'] = $last_successful_sync_gmt;
                $result['messages'][] = sprintf(__('Import incrementale attivo da watermark %s.', 'woocommerce-order-importer'), $last_successful_sync_gmt);
            }
        }

        $page = 1;
        $pages_processed = 0;
        $latest_modified_gmt = '';

        while ($page > 0 && $pages_processed < (int) $settings['max_pages']) {
            $remote = $this->fetch_orders_page($settings, $page);

            if (is_wp_error($remote)) {
                $result['errors']++;
                $result['messages'][] = sprintf(
                    __('Pagina %1$d: %2$s', 'woocommerce-order-importer'),
                    $page,
                    $remote->get_error_message()
                );
                break;
            }

            $orders = isset($remote['orders']) && is_array($remote['orders']) ? $remote['orders'] : array();
            $result['orders_seen'] += count($orders);

            if (empty($orders)) {
                $result['messages'][] = sprintf(
                    __('Pagina %d: nessun ordine da importare.', 'woocommerce-order-importer'),
                    $page
                );
                break;
            }

            foreach ($orders as $source_order) {
                if (!empty($source_order['date_modified_gmt'])) {
                    $latest_modified_gmt = $this->max_iso8601_value($latest_modified_gmt, (string) $source_order['date_modified_gmt']);
                }

                $sync_result = $this->sync_single_order($source_order, $settings);

                if (is_wp_error($sync_result)) {
                    $result['errors']++;
                    $result['messages'][] = sprintf(
                        __('Ordine %1$s: %2$s', 'woocommerce-order-importer'),
                        isset($source_order['id']) ? (string) $source_order['id'] : __('sconosciuto', 'woocommerce-order-importer'),
                        $sync_result->get_error_message()
                    );
                    continue;
                }

                $status = isset($sync_result['status']) ? $sync_result['status'] : 'skipped';
                if (!isset($result[$status])) {
                    $result[$status] = 0;
                }
                $result[$status]++;
                $result['messages'][] = isset($sync_result['message']) ? $sync_result['message'] : __('Ordine sincronizzato.', 'woocommerce-order-importer');
            }

            $pages_processed++;
            $result['pages_processed'] = $pages_processed;

            if ($page >= (int) $remote['total_pages']) {
                break;
            }

            $page++;
        }

        $result['finished_at_gmt'] = gmdate('c');

        if ($result['errors'] === 0 && !empty($settings['incremental_mode'])) {
            $watermark = $latest_modified_gmt !== '' ? $latest_modified_gmt : $result['finished_at_gmt'];
            update_option('wcoi_last_successful_sync_gmt', $watermark, false);
            $result['watermark_saved'] = $watermark;
        }

        return $result;
    }

    protected function fetch_products_page(array $settings, $page) {
        $source_url = trailingslashit($settings['source_url']);
        if ($source_url === '') {
            return new WP_Error('wcoi_missing_url', __('URL del sito A mancante.', 'woocommerce-order-importer'));
        }

        if ($settings['consumer_key'] === '' || $settings['consumer_secret'] === '') {
            return new WP_Error('wcoi_missing_credentials', __('Consumer key o consumer secret mancanti.', 'woocommerce-order-importer'));
        }

        $query_args = array(
            'per_page' => (int) $settings['product_per_page'],
            'page' => max(1, (int) $page),
            'orderby' => 'date',
            'order' => 'asc',
        );

        if ($settings['product_statuses'] !== '') {
            $query_args['status'] = implode(',', $this->parse_csv_list($settings['product_statuses']));
        }

        if ($settings['product_modified_after_gmt'] !== '') {
            $query_args['modified_after'] = $this->normalize_iso8601_utc($settings['product_modified_after_gmt']);
        }

        $endpoint = add_query_arg($query_args, $source_url . 'wp-json/wc/v3/products');

        $request_args = array(
            'timeout' => (int) $settings['timeout'],
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings['consumer_key'] . ':' . $settings['consumer_secret']),
                'Accept' => 'application/json',
            ),
        );

        $response = wp_remote_get($endpoint, $request_args);

        if (!is_wp_error($response)) {
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 401 || $code === 403) {
                $fallback_endpoint = add_query_arg(
                    array(
                        'consumer_key' => $settings['consumer_key'],
                        'consumer_secret' => $settings['consumer_secret'],
                    ),
                    $endpoint
                );
                $response = wp_remote_get(
                    $fallback_endpoint,
                    array(
                        'timeout' => (int) $settings['timeout'],
                        'headers' => array(
                            'Accept' => 'application/json',
                        ),
                    )
                );
            }
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            $decoded_error = json_decode($body, true);
            $message = isset($decoded_error['message']) ? $decoded_error['message'] : __('Risposta HTTP non valida dal sito A.', 'woocommerce-order-importer');
            return new WP_Error('wcoi_http_error_products', sprintf(__('HTTP %1$d: %2$s', 'woocommerce-order-importer'), $code, $message));
        }

        $products = json_decode($body, true);
        if (!is_array($products)) {
            return new WP_Error('wcoi_invalid_json_products', __('Risposta JSON prodotti non valida dal sito A.', 'woocommerce-order-importer'));
        }

        $headers = wp_remote_retrieve_headers($response);
        $total_pages = isset($headers['x-wp-totalpages']) ? absint($headers['x-wp-totalpages']) : 1;

        return array(
            'products' => $products,
            'total_pages' => max(1, $total_pages),
        );
    }

    protected function sync_single_product(array $source_product, array $settings) {
        if (empty($source_product['id'])) {
            return new WP_Error('wcoi_missing_product_id', __('Prodotto remoto senza ID.', 'woocommerce-order-importer'));
        }

        $existing_product_id = $this->find_existing_product_id($source_product, $settings['source_url'], isset($settings['product_match_mode']) ? $settings['product_match_mode'] : 'sku_or_source');
        $is_update = $existing_product_id > 0;
        $type = isset($source_product['type']) ? sanitize_key($source_product['type']) : 'simple';

        if (!in_array($type, array('simple', 'external', 'grouped', 'variable'), true)) {
            $type = 'simple';
        }

        if ($is_update) {
            $product = $this->instantiate_product_by_type($type, $existing_product_id);
        } else {
            if (empty($settings['product_create_missing'])) {
                return array(
                    'status' => 'skipped',
                    'message' => sprintf(
                        __('Prodotto %1$s saltato: nessun match trovato e creazione disattivata.', 'woocommerce-order-importer'),
                        (string) $source_product['id']
                    ),
                );
            }

            $product = $this->instantiate_product_by_type($type, 0);
        }

        if (!$product) {
            return new WP_Error('wcoi_product_instance_failed', __('Impossibile istanziare il prodotto WooCommerce.', 'woocommerce-order-importer'));
        }

        if (isset($source_product['name'])) {
            $product->set_name(wp_strip_all_tags((string) $source_product['name']));
        }

        if (isset($source_product['slug'])) {
            $product->set_slug(sanitize_title((string) $source_product['slug']));
        }

        if (isset($source_product['description'])) {
            $product->set_description(wp_kses_post((string) $source_product['description']));
        }

        if (isset($source_product['short_description'])) {
            $product->set_short_description(wp_kses_post((string) $source_product['short_description']));
        }

        if (isset($source_product['status'])) {
            $product->set_status(sanitize_key((string) $source_product['status']));
        }

        if (isset($source_product['catalog_visibility'])) {
            $product->set_catalog_visibility(sanitize_key((string) $source_product['catalog_visibility']));
        }

        if (isset($source_product['sku']) && $source_product['sku'] !== '') {
            try {
                $product->set_sku(wc_clean((string) $source_product['sku']));
            } catch (Exception $exception) {
            }
        }

        if (isset($source_product['regular_price'])) {
            $product->set_regular_price(wc_format_decimal($source_product['regular_price']));
        }

        if (isset($source_product['sale_price'])) {
            $product->set_sale_price(wc_format_decimal($source_product['sale_price']));
        }

        if (isset($source_product['price'])) {
            $product->set_price(wc_format_decimal($source_product['price']));
        }

        if (isset($source_product['stock_status'])) {
            $product->set_stock_status(sanitize_key((string) $source_product['stock_status']));
        }

        if (isset($source_product['manage_stock'])) {
            $product->set_manage_stock(!empty($source_product['manage_stock']));
        }

        if (isset($source_product['stock_quantity']) && $source_product['stock_quantity'] !== null) {
            $product->set_stock_quantity((int) $source_product['stock_quantity']);
        }

        if (isset($source_product['weight'])) {
            $product->set_weight(wc_format_decimal($source_product['weight']));
        }

        if (isset($source_product['dimensions']) && is_array($source_product['dimensions'])) {
            if (isset($source_product['dimensions']['length'])) {
                $product->set_length(wc_format_decimal($source_product['dimensions']['length']));
            }
            if (isset($source_product['dimensions']['width'])) {
                $product->set_width(wc_format_decimal($source_product['dimensions']['width']));
            }
            if (isset($source_product['dimensions']['height'])) {
                $product->set_height(wc_format_decimal($source_product['dimensions']['height']));
            }
        }

        if (isset($source_product['virtual'])) {
            $product->set_virtual(!empty($source_product['virtual']));
        }

        if (isset($source_product['downloadable'])) {
            $product->set_downloadable(!empty($source_product['downloadable']));
        }

        if (isset($source_product['tax_status'])) {
            $product->set_tax_status(sanitize_key((string) $source_product['tax_status']));
        }

        if (isset($source_product['tax_class'])) {
            $product->set_tax_class(sanitize_text_field((string) $source_product['tax_class']));
        }

        if (!empty($source_product['categories']) && is_array($source_product['categories'])) {
            $product->set_category_ids($this->resolve_or_create_term_ids('product_cat', $source_product['categories']));
        }

        if (!empty($source_product['tags']) && is_array($source_product['tags'])) {
            $product->set_tag_ids($this->resolve_or_create_term_ids('product_tag', $source_product['tags']));
        }

        if (!empty($source_product['attributes']) && is_array($source_product['attributes'])) {
            $product->set_attributes($this->build_product_attributes($source_product['attributes']));
        }

        $product->update_meta_data(self::META_SOURCE_PRODUCT_ID, (string) $source_product['id']);
        $product->update_meta_data(self::META_SOURCE_STORE, untrailingslashit($settings['source_url']));
        $product->update_meta_data(self::META_IMPORTED_AT, gmdate('c'));
        $product->update_meta_data(self::META_SOURCE_DATE_MODIFIED_GMT, isset($source_product['date_modified_gmt']) ? (string) $source_product['date_modified_gmt'] : '');
        if (isset($source_product['sku'])) {
            $product->update_meta_data(self::META_SOURCE_PRODUCT_SKU, sanitize_text_field((string) $source_product['sku']));
        }

        $product_id = $product->save();

        if (!$product_id) {
            return new WP_Error('wcoi_product_save_failed', __('Salvataggio prodotto fallito.', 'woocommerce-order-importer'));
        }

        if (!empty($settings['import_product_images']) && !empty($source_product['images']) && is_array($source_product['images'])) {
            $this->sync_product_images($product_id, $source_product['images'], !empty($settings['replace_product_images']), $settings['source_url']);
        }

        if ($type === 'variable') {
            $this->sync_product_variations($product_id, (string) $source_product['id'], $settings);
        }

        return array(
            'status' => $is_update ? 'updated' : 'created',
            'message' => sprintf(
                $is_update
                    ? __('Prodotto %1$s aggiornato nel sito B come #%2$s.', 'woocommerce-order-importer')
                    : __('Prodotto %1$s creato nel sito B come #%2$s.', 'woocommerce-order-importer'),
                (string) $source_product['id'],
                (string) $product_id
            ),
        );
    }

    protected function fetch_orders_page(array $settings, $page) {
        $source_url = trailingslashit($settings['source_url']);
        if ($source_url === '') {
            return new WP_Error('wcoi_missing_url', __('URL del sito A mancante.', 'woocommerce-order-importer'));
        }

        if ($settings['consumer_key'] === '' || $settings['consumer_secret'] === '') {
            return new WP_Error('wcoi_missing_credentials', __('Consumer key o consumer secret mancanti.', 'woocommerce-order-importer'));
        }

        $query_args = array(
            'per_page' => (int) $settings['per_page'],
            'page' => max(1, (int) $page),
            'orderby' => 'date',
            'order' => 'asc',
        );

        if ($settings['statuses'] !== '') {
            $query_args['status'] = implode(',', $this->parse_csv_list($settings['statuses']));
        }

        if ($settings['modified_after_gmt'] !== '') {
            $query_args['modified_after'] = $this->normalize_iso8601_utc($settings['modified_after_gmt']);
        }

        $endpoint = add_query_arg($query_args, $source_url . 'wp-json/wc/v3/orders');

        $request_args = array(
            'timeout' => (int) $settings['timeout'],
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings['consumer_key'] . ':' . $settings['consumer_secret']),
                'Accept' => 'application/json',
            ),
        );

        $response = wp_remote_get($endpoint, $request_args);

        if (!is_wp_error($response)) {
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 401 || $code === 403) {
                $fallback_endpoint = add_query_arg(
                    array(
                        'consumer_key' => $settings['consumer_key'],
                        'consumer_secret' => $settings['consumer_secret'],
                    ),
                    $endpoint
                );
                $response = wp_remote_get(
                    $fallback_endpoint,
                    array(
                        'timeout' => (int) $settings['timeout'],
                        'headers' => array(
                            'Accept' => 'application/json',
                        ),
                    )
                );
            }
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            $decoded_error = json_decode($body, true);
            $message = isset($decoded_error['message']) ? $decoded_error['message'] : __('Risposta HTTP non valida dal sito A.', 'woocommerce-order-importer');
            return new WP_Error('wcoi_http_error', sprintf(__('HTTP %1$d: %2$s', 'woocommerce-order-importer'), $code, $message));
        }

        $orders = json_decode($body, true);
        if (!is_array($orders)) {
            return new WP_Error('wcoi_invalid_json', __('Risposta JSON non valida dal sito A.', 'woocommerce-order-importer'));
        }

        $headers = wp_remote_retrieve_headers($response);
        $total_pages = isset($headers['x-wp-totalpages']) ? absint($headers['x-wp-totalpages']) : 1;

        return array(
            'orders' => $orders,
            'total_pages' => max(1, $total_pages),
        );
    }

    protected function sync_single_order(array $source_order, array $settings) {
        if (empty($source_order['id'])) {
            return new WP_Error('wcoi_missing_order_id', __('Ordine remoto senza ID.', 'woocommerce-order-importer'));
        }

        $existing_order_id = $this->find_existing_order_id((string) $source_order['id'], $settings['source_url']);
        $is_update = $existing_order_id > 0;

        if ($is_update) {
            $order = wc_get_order($existing_order_id);
            if (!$order) {
                return new WP_Error('wcoi_load_order_failed', __('Impossibile caricare l\'ordine locale esistente.', 'woocommerce-order-importer'));
            }

            $this->clear_order_items($order);
        } else {
            $order = wc_create_order();
            if (is_wp_error($order)) {
                return $order;
            }
        }

        $this->apply_order_core_fields($order, $source_order, $settings);
        $this->apply_addresses($order, $source_order);
        $this->apply_line_items($order, $source_order, $settings);
        $this->apply_shipping_lines($order, $source_order);
        $this->apply_fee_lines($order, $source_order);
        $this->apply_coupon_lines($order, $source_order);
        $this->apply_order_totals($order, $source_order);

        $order->update_meta_data(self::META_SOURCE_ORDER_ID, (string) $source_order['id']);
        $order->update_meta_data(self::META_SOURCE_ORDER_NUMBER, isset($source_order['number']) ? (string) $source_order['number'] : (string) $source_order['id']);
        $order->update_meta_data(self::META_SOURCE_STORE, untrailingslashit($settings['source_url']));
        $order->update_meta_data(self::META_IMPORTED_AT, gmdate('c'));
        $order->update_meta_data(self::META_SOURCE_DATE_MODIFIED_GMT, isset($source_order['date_modified_gmt']) ? (string) $source_order['date_modified_gmt'] : '');
        $order->save();

        return array(
            'status' => $is_update ? 'updated' : 'created',
            'message' => sprintf(
                $is_update
                    ? __('Ordine %1$s aggiornato nel sito B come #%2$s.', 'woocommerce-order-importer')
                    : __('Ordine %1$s creato nel sito B come #%2$s.', 'woocommerce-order-importer'),
                (string) $source_order['id'],
                (string) $order->get_id()
            ),
        );
    }

    protected function apply_order_core_fields(WC_Order $order, array $source_order, array $settings) {
        $status = isset($source_order['status']) ? sanitize_key($source_order['status']) : 'pending';
        if ($status !== '') {
            $order->set_status($status);
        }

        if (isset($source_order['currency'])) {
            $order->set_currency(wc_clean($source_order['currency']));
        }

        $order->set_customer_id($this->resolve_local_customer_id($source_order));

        if (!empty($source_order['customer_note'])) {
            $order->set_customer_note(wp_kses_post($source_order['customer_note']));
        }

        if (isset($source_order['payment_method'])) {
            $order->set_payment_method(sanitize_text_field($source_order['payment_method']));
        }

        if (isset($source_order['payment_method_title'])) {
            $order->set_payment_method_title(sanitize_text_field($source_order['payment_method_title']));
        }

        if (!empty($source_order['transaction_id'])) {
            $order->set_transaction_id(sanitize_text_field($source_order['transaction_id']));
        }

        if (!empty($source_order['date_created_gmt'])) {
            try {
                $order->set_date_created(new WC_DateTime($source_order['date_created_gmt']));
            } catch (Exception $exception) {
            }
        }

        if (!empty($source_order['date_paid_gmt'])) {
            try {
                $order->set_date_paid(new WC_DateTime($source_order['date_paid_gmt']));
            } catch (Exception $exception) {
            }
        }

        if (!empty($source_order['date_completed_gmt'])) {
            try {
                $order->set_date_completed(new WC_DateTime($source_order['date_completed_gmt']));
            } catch (Exception $exception) {
            }
        }

        if (!empty($source_order['created_via'])) {
            $order->set_created_via(sanitize_text_field($source_order['created_via']));
        } else {
            $order->set_created_via('wcoi_importer');
        }

        $order->update_meta_data('_wcoi_source_payload', wp_json_encode($source_order));
    }

    protected function apply_addresses(WC_Order $order, array $source_order) {
        if (!empty($source_order['billing']) && is_array($source_order['billing'])) {
            $order->set_address($this->map_address_data($source_order['billing']), 'billing');
        }

        if (!empty($source_order['shipping']) && is_array($source_order['shipping'])) {
            $order->set_address($this->map_address_data($source_order['shipping']), 'shipping');
        }
    }

    protected function apply_line_items(WC_Order $order, array $source_order, array $settings) {
        $line_items = isset($source_order['line_items']) && is_array($source_order['line_items']) ? $source_order['line_items'] : array();

        foreach ($line_items as $line_item) {
            $item = new WC_Order_Item_Product();
            $source_store = isset($settings['source_url']) ? $settings['source_url'] : '';
            $product_match = $this->match_local_product_ids($line_item, $source_store);

            if (!empty($product_match['variation_id'])) {
                $variation_product = wc_get_product((int) $product_match['variation_id']);
                if ($variation_product instanceof WC_Product_Variation) {
                    $item->set_product($variation_product);
                    $item->set_variation_id((int) $product_match['variation_id']);
                }
            } elseif (!empty($product_match['product_id'])) {
                $product = wc_get_product((int) $product_match['product_id']);
                if ($product) {
                    $item->set_product($product);
                }
            }

            if (!empty($line_item['name'])) {
                $item->set_name(wp_strip_all_tags((string) $line_item['name']));
            }

            if (isset($line_item['quantity'])) {
                $item->set_quantity((int) $line_item['quantity']);
            }

            $item->set_subtotal(isset($line_item['subtotal']) ? wc_format_decimal($line_item['subtotal']) : 0);
            $item->set_total(isset($line_item['total']) ? wc_format_decimal($line_item['total']) : 0);
            $item->set_subtotal_tax(isset($line_item['subtotal_tax']) ? wc_format_decimal($line_item['subtotal_tax']) : 0);
            $item->set_total_tax(isset($line_item['total_tax']) ? wc_format_decimal($line_item['total_tax']) : 0);

            if (!empty($line_item['sku'])) {
                $item->add_meta_data('_wcoi_source_sku', sanitize_text_field($line_item['sku']), true);
            }

            if (!empty($line_item['product_id'])) {
                $item->add_meta_data('_wcoi_source_product_id', absint($line_item['product_id']), true);
            }

            if (!empty($line_item['variation_id'])) {
                $item->add_meta_data('_wcoi_source_variation_id', absint($line_item['variation_id']), true);
            }

            $taxes = $this->normalize_taxes_for_item($line_item);
            if (!empty($taxes)) {
                $item->set_taxes($taxes);
            }

            $order->add_item($item);
        }
    }

    protected function apply_shipping_lines(WC_Order $order, array $source_order) {
        $shipping_lines = isset($source_order['shipping_lines']) && is_array($source_order['shipping_lines']) ? $source_order['shipping_lines'] : array();

        foreach ($shipping_lines as $shipping_line) {
            $item = new WC_Order_Item_Shipping();
            $item->set_method_title(isset($shipping_line['method_title']) ? sanitize_text_field($shipping_line['method_title']) : __('Spedizione importata', 'woocommerce-order-importer'));
            $item->set_method_id(isset($shipping_line['method_id']) ? sanitize_key($shipping_line['method_id']) : 'wcoi_imported');
            $item->set_total(isset($shipping_line['total']) ? wc_format_decimal($shipping_line['total']) : 0);

            $taxes = $this->normalize_taxes_for_item($shipping_line);
            if (!empty($taxes)) {
                $item->set_taxes($taxes);
            }

            $order->add_item($item);
        }
    }

    protected function apply_fee_lines(WC_Order $order, array $source_order) {
        $fee_lines = isset($source_order['fee_lines']) && is_array($source_order['fee_lines']) ? $source_order['fee_lines'] : array();

        foreach ($fee_lines as $fee_line) {
            $item = new WC_Order_Item_Fee();
            $item->set_name(isset($fee_line['name']) ? sanitize_text_field($fee_line['name']) : __('Commissione importata', 'woocommerce-order-importer'));
            $item->set_total(isset($fee_line['total']) ? wc_format_decimal($fee_line['total']) : 0);
            $item->set_total_tax(isset($fee_line['total_tax']) ? wc_format_decimal($fee_line['total_tax']) : 0);

            $taxes = $this->normalize_taxes_for_item($fee_line);
            if (!empty($taxes)) {
                $item->set_taxes($taxes);
            }

            $order->add_item($item);
        }
    }

    protected function apply_coupon_lines(WC_Order $order, array $source_order) {
        $coupon_lines = isset($source_order['coupon_lines']) && is_array($source_order['coupon_lines']) ? $source_order['coupon_lines'] : array();

        foreach ($coupon_lines as $coupon_line) {
            $item = new WC_Order_Item_Coupon();
            $item->set_code(isset($coupon_line['code']) ? sanitize_text_field($coupon_line['code']) : __('imported-coupon', 'woocommerce-order-importer'));
            $item->set_discount(isset($coupon_line['discount']) ? wc_format_decimal($coupon_line['discount']) : 0);
            $item->set_discount_tax(isset($coupon_line['discount_tax']) ? wc_format_decimal($coupon_line['discount_tax']) : 0);
            $order->add_item($item);
        }
    }

    protected function apply_order_totals(WC_Order $order, array $source_order) {
        $public_totals = array(
            'shipping_total' => 'set_shipping_total',
            'discount_total' => 'set_discount_total',
            'discount_tax' => 'set_discount_tax',
        );

        foreach ($public_totals as $source_key => $setter) {
            if (isset($source_order[$source_key])) {
                $order->{$setter}(wc_format_decimal($source_order[$source_key]));
            }
        }

        $order->calculate_totals(false);

        if (isset($source_order['total'])) {
            $order->set_total(wc_format_decimal($source_order['total']));
        }

        foreach (array('cart_tax', 'shipping_tax', 'total_tax') as $tax_key) {
            if (isset($source_order[$tax_key])) {
                $order->update_meta_data('_wcoi_source_' . $tax_key, wc_format_decimal($source_order[$tax_key]));
            }
        }
    }

    protected function map_address_data(array $source) {
        return array(
            'first_name' => isset($source['first_name']) ? sanitize_text_field($source['first_name']) : '',
            'last_name' => isset($source['last_name']) ? sanitize_text_field($source['last_name']) : '',
            'company' => isset($source['company']) ? sanitize_text_field($source['company']) : '',
            'email' => isset($source['email']) ? sanitize_email($source['email']) : '',
            'phone' => isset($source['phone']) ? sanitize_text_field($source['phone']) : '',
            'address_1' => isset($source['address_1']) ? sanitize_text_field($source['address_1']) : '',
            'address_2' => isset($source['address_2']) ? sanitize_text_field($source['address_2']) : '',
            'city' => isset($source['city']) ? sanitize_text_field($source['city']) : '',
            'state' => isset($source['state']) ? sanitize_text_field($source['state']) : '',
            'postcode' => isset($source['postcode']) ? sanitize_text_field($source['postcode']) : '',
            'country' => isset($source['country']) ? sanitize_text_field($source['country']) : '',
        );
    }

    protected function normalize_taxes_for_item(array $source_item) {
        if (empty($source_item['taxes']) || !is_array($source_item['taxes'])) {
            return array();
        }

        $taxes = array(
            'total' => array(),
            'subtotal' => array(),
        );

        foreach ($source_item['taxes'] as $tax_row) {
            if (empty($tax_row['id'])) {
                continue;
            }

            $tax_id = absint($tax_row['id']);
            $taxes['total'][$tax_id] = isset($tax_row['total']) ? wc_format_decimal($tax_row['total']) : 0;

            if (isset($tax_row['subtotal'])) {
                $taxes['subtotal'][$tax_id] = wc_format_decimal($tax_row['subtotal']);
            }
        }

        return $taxes;
    }

    protected function clear_order_items(WC_Order $order) {
        foreach ($order->get_items(array('line_item', 'shipping', 'fee', 'coupon', 'tax')) as $item_id => $item) {
            $order->remove_item($item_id);
        }
    }

    protected function match_local_product_ids(array $line_item, $source_store = '') {
        $result = array(
            'product_id' => 0,
            'variation_id' => 0,
        );

        if (!empty($line_item['variation_id'])) {
            $variation_id = $this->find_variation_id_by_source_id((string) $line_item['variation_id'], $source_store);
            if ($variation_id > 0) {
                $result['variation_id'] = $variation_id;
                $parent_id = wp_get_post_parent_id($variation_id);
                $result['product_id'] = $parent_id > 0 ? (int) $parent_id : $variation_id;
                return $result;
            }
        }

        if (!empty($line_item['sku'])) {
            $product_id = wc_get_product_id_by_sku(wc_clean($line_item['sku']));
            if ($product_id > 0) {
                $product = wc_get_product($product_id);
                if ($product instanceof WC_Product_Variation) {
                    $result['variation_id'] = (int) $product_id;
                    $parent_id = wp_get_post_parent_id($product_id);
                    $result['product_id'] = $parent_id > 0 ? (int) $parent_id : (int) $product_id;
                    return $result;
                }

                $result['product_id'] = (int) $product_id;
                return $result;
            }
        }

        if (!empty($line_item['product_id'])) {
            $product_id = $this->find_product_id_by_source_id((string) $line_item['product_id'], $source_store);
            if ($product_id > 0) {
                $result['product_id'] = $product_id;
                return $result;
            }
        }

        return $result;
    }

    protected function instantiate_product_by_type($type, $product_id = 0) {
        switch ($type) {
            case 'external':
                return $product_id > 0 ? new WC_Product_External($product_id) : new WC_Product_External();
            case 'grouped':
                return $product_id > 0 ? new WC_Product_Grouped($product_id) : new WC_Product_Grouped();
            case 'variable':
                return $product_id > 0 ? new WC_Product_Variable($product_id) : new WC_Product_Variable();
            default:
                return $product_id > 0 ? new WC_Product_Simple($product_id) : new WC_Product_Simple();
        }
    }

    protected function sync_product_variations($local_parent_product_id, $remote_parent_product_id, array $settings) {
        $source_variations = $this->fetch_all_remote_variations($remote_parent_product_id, $settings);
        if (is_wp_error($source_variations) || empty($source_variations)) {
            return;
        }

        foreach ($source_variations as $source_variation) {
            if (!is_array($source_variation) || empty($source_variation['id'])) {
                continue;
            }

            $this->sync_single_variation($local_parent_product_id, $source_variation, $settings);
        }
    }

    protected function fetch_all_remote_variations($remote_parent_product_id, array $settings) {
        $all = array();
        $page = 1;
        $max_pages = 20;

        while ($page <= $max_pages) {
            $batch = $this->fetch_remote_variations_page($remote_parent_product_id, $page, $settings);
            if (is_wp_error($batch)) {
                return $batch;
            }

            if (empty($batch['variations'])) {
                break;
            }

            $all = array_merge($all, $batch['variations']);
            if ($page >= (int) $batch['total_pages']) {
                break;
            }

            $page++;
        }

        return $all;
    }

    protected function fetch_remote_variations_page($remote_parent_product_id, $page, array $settings) {
        $source_url = trailingslashit($settings['source_url']);
        $query_args = array(
            'per_page' => 100,
            'page' => max(1, (int) $page),
            'orderby' => 'id',
            'order' => 'asc',
        );
        $endpoint = add_query_arg($query_args, $source_url . 'wp-json/wc/v3/products/' . absint($remote_parent_product_id) . '/variations');

        $response = wp_remote_get(
            $endpoint,
            array(
                'timeout' => (int) $settings['timeout'],
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($settings['consumer_key'] . ':' . $settings['consumer_secret']),
                    'Accept' => 'application/json',
                ),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('wcoi_variations_http_error', sprintf(__('HTTP %1$d durante fetch variazioni.', 'woocommerce-order-importer'), $code));
        }

        $variations = json_decode($body, true);
        if (!is_array($variations)) {
            return new WP_Error('wcoi_variations_json_error', __('JSON variazioni non valido.', 'woocommerce-order-importer'));
        }

        $headers = wp_remote_retrieve_headers($response);
        $total_pages = isset($headers['x-wp-totalpages']) ? absint($headers['x-wp-totalpages']) : 1;

        return array(
            'variations' => $variations,
            'total_pages' => max(1, $total_pages),
        );
    }

    protected function sync_single_variation($local_parent_product_id, array $source_variation, array $settings) {
        $local_variation_id = $this->find_variation_id_by_source_id((string) $source_variation['id'], $settings['source_url']);
        if ($local_variation_id <= 0 && !empty($source_variation['sku'])) {
            $candidate = wc_get_product_id_by_sku(wc_clean((string) $source_variation['sku']));
            if ($candidate > 0) {
                $candidate_product = wc_get_product($candidate);
                if ($candidate_product instanceof WC_Product_Variation) {
                    $local_variation_id = (int) $candidate;
                }
            }
        }

        $variation = $local_variation_id > 0 ? new WC_Product_Variation($local_variation_id) : new WC_Product_Variation();
        $variation->set_parent_id((int) $local_parent_product_id);

        if (isset($source_variation['status'])) {
            $variation->set_status(sanitize_key((string) $source_variation['status']));
        }
        if (isset($source_variation['sku']) && $source_variation['sku'] !== '') {
            try {
                $variation->set_sku(wc_clean((string) $source_variation['sku']));
            } catch (Exception $exception) {
            }
        }
        if (isset($source_variation['regular_price'])) {
            $variation->set_regular_price(wc_format_decimal($source_variation['regular_price']));
        }
        if (isset($source_variation['sale_price'])) {
            $variation->set_sale_price(wc_format_decimal($source_variation['sale_price']));
        }
        if (isset($source_variation['price'])) {
            $variation->set_price(wc_format_decimal($source_variation['price']));
        }
        if (isset($source_variation['stock_status'])) {
            $variation->set_stock_status(sanitize_key((string) $source_variation['stock_status']));
        }
        if (isset($source_variation['manage_stock'])) {
            $variation->set_manage_stock(!empty($source_variation['manage_stock']));
        }
        if (isset($source_variation['stock_quantity']) && $source_variation['stock_quantity'] !== null) {
            $variation->set_stock_quantity((int) $source_variation['stock_quantity']);
        }

        if (!empty($source_variation['attributes']) && is_array($source_variation['attributes'])) {
            $variation->set_attributes($this->map_variation_attributes($source_variation['attributes']));
        }

        $variation->update_meta_data(self::META_SOURCE_VARIATION_ID, (string) $source_variation['id']);
        $variation->update_meta_data(self::META_SOURCE_STORE, untrailingslashit($settings['source_url']));
        $variation->update_meta_data(self::META_IMPORTED_AT, gmdate('c'));
        if (!empty($source_variation['date_modified_gmt'])) {
            $variation->update_meta_data(self::META_SOURCE_DATE_MODIFIED_GMT, (string) $source_variation['date_modified_gmt']);
        }

        $variation_id = $variation->save();
        if (!$variation_id) {
            return;
        }

        if (!empty($settings['import_product_images']) && !empty($source_variation['image']) && is_array($source_variation['image']) && !empty($source_variation['image']['src'])) {
            $attachment_id = $this->find_attachment_by_source_image((string) $source_variation['image']['src'], $settings['source_url']);
            if ($attachment_id <= 0) {
                if (!function_exists('media_sideload_image')) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                $attachment_id = media_sideload_image((string) $source_variation['image']['src'], $variation_id, null, 'id');
                if (!is_wp_error($attachment_id)) {
                    update_post_meta($attachment_id, self::META_SOURCE_IMAGE_URL, esc_url_raw((string) $source_variation['image']['src']));
                    update_post_meta($attachment_id, self::META_SOURCE_STORE, untrailingslashit((string) $settings['source_url']));
                }
            }
            if (!is_wp_error($attachment_id) && $attachment_id > 0) {
                $variation->set_image_id((int) $attachment_id);
                $variation->save();
            }
        }
    }

    protected function map_variation_attributes(array $attributes) {
        $mapped = array();

        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || empty($attribute['option'])) {
                continue;
            }

            $key = '';
            if (!empty($attribute['id'])) {
                $tax_name = wc_attribute_taxonomy_name_by_id((int) $attribute['id']);
                if (is_string($tax_name) && $tax_name !== '') {
                    $key = $tax_name;
                }
            }
            if ($key === '' && !empty($attribute['name'])) {
                $key = sanitize_title((string) $attribute['name']);
            }
            if ($key === '') {
                continue;
            }

            $mapped[$key] = $this->normalize_variation_attribute_option($key, (string) $attribute['option']);
        }

        return $mapped;
    }

    protected function build_product_attributes(array $attributes) {
        $prepared = array();

        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || empty($attribute['name'])) {
                continue;
            }

            $product_attribute = new WC_Product_Attribute();
            $attribute_name = (string) $attribute['name'];
            $attribute_id = !empty($attribute['id']) ? absint($attribute['id']) : 0;
            $options = isset($attribute['options']) && is_array($attribute['options']) ? $attribute['options'] : array();

            if ($attribute_id > 0) {
                $taxonomy = wc_attribute_taxonomy_name_by_id($attribute_id);
                if (!is_string($taxonomy) || $taxonomy === '') {
                    continue;
                }

                $term_ids = $this->resolve_or_create_attribute_term_ids($taxonomy, $options);
                if (empty($term_ids)) {
                    continue;
                }

                $product_attribute->set_id($attribute_id);
                $product_attribute->set_name($taxonomy);
                $product_attribute->set_options($term_ids);
            } else {
                $clean_options = array();
                foreach ($options as $option) {
                    $value = sanitize_text_field((string) $option);
                    if ($value !== '') {
                        $clean_options[] = $value;
                    }
                }

                if (empty($clean_options)) {
                    continue;
                }

                $product_attribute->set_id(0);
                $product_attribute->set_name($attribute_name);
                $product_attribute->set_options(array_values(array_unique($clean_options)));
            }

            $product_attribute->set_position(isset($attribute['position']) ? absint($attribute['position']) : 0);
            $product_attribute->set_visible(isset($attribute['visible']) ? (bool) $attribute['visible'] : true);
            $product_attribute->set_variation(!empty($attribute['variation']));
            $prepared[] = $product_attribute;
        }

        return $prepared;
    }

    protected function resolve_or_create_attribute_term_ids($taxonomy, array $options) {
        $term_ids = array();

        foreach ($options as $option) {
            $name = sanitize_text_field((string) $option);
            if ($name === '') {
                continue;
            }

            $slug = sanitize_title($name);
            $existing = get_term_by('slug', $slug, $taxonomy);
            if (!$existing) {
                $existing = get_term_by('name', $name, $taxonomy);
            }

            if (!$existing) {
                $inserted = wp_insert_term($name, $taxonomy, array('slug' => $slug));
                if (is_wp_error($inserted) || empty($inserted['term_id'])) {
                    continue;
                }
                $term_ids[] = (int) $inserted['term_id'];
                continue;
            }

            $term_ids[] = (int) $existing->term_id;
        }

        return array_values(array_unique($term_ids));
    }

    protected function normalize_variation_attribute_option($attribute_key, $option) {
        $option = sanitize_text_field((string) $option);
        if ($option === '') {
            return '';
        }

        if (taxonomy_exists($attribute_key)) {
            $term = get_term_by('name', $option, $attribute_key);
            if (!$term) {
                $term = get_term_by('slug', sanitize_title($option), $attribute_key);
            }
            if ($term && !is_wp_error($term)) {
                return (string) $term->slug;
            }

            return sanitize_title($option);
        }

        return $option;
    }

    protected function find_variation_id_by_source_id($source_variation_id, $source_store) {
        if ($source_variation_id === '') {
            return 0;
        }

        $query = new WP_Query(
            array(
                'post_type' => 'product_variation',
                'post_status' => array('publish', 'private', 'draft', 'pending'),
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => self::META_SOURCE_VARIATION_ID,
                        'value' => (string) $source_variation_id,
                    ),
                    array(
                        'key' => self::META_SOURCE_STORE,
                        'value' => untrailingslashit((string) $source_store),
                    ),
                ),
            )
        );

        if (empty($query->posts)) {
            return 0;
        }

        return (int) $query->posts[0];
    }

    protected function find_existing_product_id(array $source_product, $source_store, $mode = 'sku_or_source') {
        $source_id = isset($source_product['id']) ? (string) $source_product['id'] : '';
        $sku = isset($source_product['sku']) ? (string) $source_product['sku'] : '';

        if ($mode === 'source_only') {
            return $this->find_product_id_by_source_id($source_id, $source_store);
        }

        if ($mode === 'sku_only') {
            if ($sku === '') {
                return 0;
            }
            $by_sku = wc_get_product_id_by_sku(wc_clean($sku));
            return $by_sku > 0 ? (int) $by_sku : 0;
        }

        if ($sku !== '') {
            $by_sku = wc_get_product_id_by_sku(wc_clean($sku));
            if ($by_sku > 0) {
                return (int) $by_sku;
            }
        }

        return $this->find_product_id_by_source_id($source_id, $source_store);
    }

    protected function sync_product_images($product_id, array $images, $replace_existing, $source_store) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        if (!$replace_existing && ($product->get_image_id() || !empty($product->get_gallery_image_ids()))) {
            return;
        }

        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_ids = array();

        foreach ($images as $index => $image_data) {
            if (empty($image_data['src'])) {
                continue;
            }

            $src = esc_url_raw((string) $image_data['src']);
            if ($src === '') {
                continue;
            }

            $attachment_id = $this->find_attachment_by_source_image($src, $source_store);

            if ($attachment_id <= 0) {
                $attachment_id = media_sideload_image($src, $product_id, null, 'id');
                if (is_wp_error($attachment_id)) {
                    continue;
                }

                update_post_meta($attachment_id, self::META_SOURCE_IMAGE_URL, $src);
                update_post_meta($attachment_id, self::META_SOURCE_STORE, untrailingslashit((string) $source_store));
            }

            $attachment_ids[] = (int) $attachment_id;

            if ($index === 0) {
                set_post_thumbnail($product_id, (int) $attachment_id);
            }
        }

        if (!empty($attachment_ids)) {
            $gallery = array_slice($attachment_ids, 1);
            $product->set_gallery_image_ids($gallery);
            $product->save();
        }
    }

    protected function find_attachment_by_source_image($image_url, $source_store) {
        $query = new WP_Query(
            array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => self::META_SOURCE_IMAGE_URL,
                        'value' => (string) $image_url,
                    ),
                    array(
                        'key' => self::META_SOURCE_STORE,
                        'value' => untrailingslashit((string) $source_store),
                    ),
                ),
            )
        );

        if (empty($query->posts)) {
            return 0;
        }

        return (int) $query->posts[0];
    }

    protected function find_product_id_by_source_id($source_product_id, $source_store) {
        if ($source_product_id === '') {
            return 0;
        }

        $query = new WP_Query(
            array(
                'post_type' => 'product',
                'post_status' => array('publish', 'private', 'draft', 'pending'),
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => self::META_SOURCE_PRODUCT_ID,
                        'value' => (string) $source_product_id,
                    ),
                    array(
                        'key' => self::META_SOURCE_STORE,
                        'value' => untrailingslashit((string) $source_store),
                    ),
                ),
            )
        );

        if (empty($query->posts)) {
            return 0;
        }

        return (int) $query->posts[0];
    }

    protected function resolve_or_create_term_ids($taxonomy, array $terms) {
        $term_ids = array();

        foreach ($terms as $term_data) {
            if (!is_array($term_data)) {
                continue;
            }

            $name = isset($term_data['name']) ? sanitize_text_field((string) $term_data['name']) : '';
            if ($name === '') {
                continue;
            }

            $slug = isset($term_data['slug']) ? sanitize_title((string) $term_data['slug']) : '';
            $existing = null;

            if ($slug !== '') {
                $existing = get_term_by('slug', $slug, $taxonomy);
            }

            if (!$existing) {
                $existing = get_term_by('name', $name, $taxonomy);
            }

            if (!$existing) {
                $inserted = wp_insert_term($name, $taxonomy, $slug !== '' ? array('slug' => $slug) : array());
                if (is_wp_error($inserted) || empty($inserted['term_id'])) {
                    continue;
                }
                $term_ids[] = (int) $inserted['term_id'];
                continue;
            }

            $term_ids[] = (int) $existing->term_id;
        }

        return array_values(array_unique($term_ids));
    }

    protected function get_imported_order_customer_emails() {
        $emails = array();
        $page = 1;
        $limit = 200;

        while (true) {
            $query = new WC_Order_Query(
                array(
                    'type' => 'shop_order',
                    'return' => 'ids',
                    'limit' => $limit,
                    'page' => $page,
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'meta_query' => array(
                        array(
                            'key' => self::META_SOURCE_ORDER_ID,
                            'compare' => 'EXISTS',
                        ),
                    ),
                )
            );

            $order_ids = $query->get_orders();
            if (empty($order_ids) || !is_array($order_ids)) {
                break;
            }

            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    continue;
                }

                $email = sanitize_email((string) $order->get_billing_email());
                if ($email === '' || !is_email($email)) {
                    continue;
                }

                $emails[$email] = true;
            }

            if (count($order_ids) < $limit) {
                break;
            }

            $page++;
            if ($page > 200) {
                break;
            }
        }

        return array_keys($emails);
    }

    protected function find_existing_order_id($source_order_id, $source_url) {
        $query = new WC_Order_Query(
            array(
                'limit' => 1,
                'return' => 'ids',
                'type' => 'shop_order',
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => self::META_SOURCE_ORDER_ID,
                        'value' => (string) $source_order_id,
                    ),
                    array(
                        'key' => self::META_SOURCE_STORE,
                        'value' => untrailingslashit($source_url),
                    ),
                ),
            )
        );

        $orders = $query->get_orders();
        if (empty($orders)) {
            return 0;
        }

        return (int) $orders[0];
    }

    protected function parse_csv_list($value) {
        $parts = array_map('trim', explode(',', (string) $value));
        $parts = array_filter($parts, static function ($part) {
            return $part !== '';
        });

        return array_values(array_unique($parts));
    }

    protected function normalize_iso8601_utc($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/(Z|[+\-][0-9]{2}:[0-9]{2})$/', $value)) {
            return $value;
        }

        return $value . 'Z';
    }

    protected function resolve_local_customer_id(array $source_order) {
        $billing_email = '';
        if (!empty($source_order['billing']) && is_array($source_order['billing']) && !empty($source_order['billing']['email'])) {
            $billing_email = sanitize_email($source_order['billing']['email']);
        }

        if ($billing_email === '') {
            return 0;
        }

        $user = get_user_by('email', $billing_email);
        if (!$user) {
            return 0;
        }

        return (int) $user->ID;
    }

    protected function max_iso8601_value($left, $right) {
        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        return strcmp($left, $right) >= 0 ? $left : $right;
    }

    protected function store_last_result(array $result, $user_id) {
        set_transient(self::RESULT_TRANSIENT_KEY . (int) $user_id, $result, MINUTE_IN_SECONDS * 10);
        update_option(self::LAST_RESULT_OPTION_KEY, $result, false);
    }

    protected function get_last_result($user_id) {
        $result = get_transient(self::RESULT_TRANSIENT_KEY . (int) $user_id);
        if (is_array($result)) {
            return $result;
        }

        $result = get_option(self::LAST_RESULT_OPTION_KEY, array());
        return is_array($result) ? $result : array();
    }

    protected function get_available_cron_intervals() {
        return array(
            'wcoi_fifteen_minutes' => __('Ogni 15 minuti', 'woocommerce-order-importer'),
            'wcoi_thirty_minutes' => __('Ogni 30 minuti', 'woocommerce-order-importer'),
            'hourly' => __('Ogni ora', 'woocommerce-order-importer'),
            'twicedaily' => __('Due volte al giorno', 'woocommerce-order-importer'),
            'daily' => __('Una volta al giorno', 'woocommerce-order-importer'),
        );
    }

    protected function sync_cron_schedule() {
        $settings = $this->get_settings();
        self::clear_cron_schedule();

        if (empty($settings['enable_cron'])) {
            return;
        }

        $interval = isset($settings['cron_interval']) ? $settings['cron_interval'] : 'hourly';
        $available_intervals = $this->get_available_cron_intervals();
        if (!isset($available_intervals[$interval])) {
            $interval = 'hourly';
        }

        wp_schedule_event(time() + MINUTE_IN_SECONDS, $interval, self::CRON_HOOK);
    }

    protected static function clear_cron_schedule() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    protected function are_site_emails_globally_disabled() {
        $settings = $this->get_settings();

        return !empty($settings['disable_all_outgoing_mail']);
    }

    protected function should_suppress_emails_during_import() {
        $settings = $this->get_settings();

        return !empty($settings['suppress_emails_during_import']) || !empty($settings['disable_all_outgoing_mail']);
    }

    protected function will_order_import_send_notifications() {
        return !$this->are_site_emails_globally_disabled() && !$this->should_suppress_emails_during_import();
    }

    protected function current_user_can_manage() {
        return current_user_can($this->is_woocommerce_active() ? 'manage_woocommerce' : 'manage_options');
    }

    protected function is_woocommerce_active() {
        return class_exists('WooCommerce') && function_exists('wc_create_order');
    }

    protected function get_admin_page_url() {
        if ($this->is_woocommerce_active()) {
            return admin_url('admin.php');
        }

        return admin_url('tools.php');
    }

    protected function get_settings() {
        $defaults = array(
            'source_url' => '',
            'consumer_key' => '',
            'consumer_secret' => '',
            'per_page' => 20,
            'max_pages' => 1,
            'statuses' => '',
            'modified_after_gmt' => '',
            'timeout' => 30,
            'product_per_page' => 20,
            'product_max_pages' => 1,
            'product_statuses' => '',
            'product_modified_after_gmt' => '',
            'product_match_mode' => 'sku_or_source',
            'product_create_missing' => 0,
            'import_product_images' => 1,
            'replace_product_images' => 0,
            'disable_all_outgoing_mail' => 0,
            'suppress_emails_during_import' => 1,
            'enable_cron' => 0,
            'cron_interval' => 'hourly',
            'incremental_mode' => 1,
        );

        $settings = get_option(self::OPTION_KEY, array());
        if (!is_array($settings)) {
            $settings = array();
        }

        return wp_parse_args($settings, $defaults);
    }
}