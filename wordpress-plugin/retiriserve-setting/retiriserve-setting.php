<?php
/**
 * Plugin Name: Reti Riserve Setting
 * Description: Logo default e sticky, pagina di accesso personalizzata, disattivazione ed eliminazione dei commenti.
 * Version: 1.4.0
 * Author: area051.com
 * Author URI: https://area051.com
 * Update URI: https://github.com/studioblq/rete-riserve-export-import-drupal
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: reti-riserve-setting
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/lib/class-area051-github-updater.php';
new Area051_GitHub_Updater_V1(__FILE__, 'retiriserve-setting');

/**
 * TRUE when the old copy of this plugin (folder "setting", file setting.php) is active.
 *
 * The old file declares the same class and functions without guards, so if it
 * loads after this one the site dies with a fatal error. In that case this copy
 * must not declare anything: everything below is conditional for that reason.
 */
if (!function_exists('rrs_setting_old_copy_active')) {
    function rrs_setting_old_copy_active() {
        $active = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $own = plugin_basename(__FILE__);
        foreach ($active as $plugin) {
            if ($plugin !== $own && basename((string) $plugin) === 'setting.php' && file_exists(WP_PLUGIN_DIR . '/' . $plugin)) {
                return true;
            }
        }
        return false;
    }
}

// Another copy is already loaded (or will be): stop here instead of a fatal error.
if (defined('RRS_SETTING_PLUGIN_FILE') || function_exists('reti_riserve_setting_bootstrap') || rrs_setting_old_copy_active()) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Reti Riserve Setting è installato due volte (cartelle "setting" e "retiriserve-setting"). Disattiva ed elimina la vecchia cartella "setting".', 'reti-riserve-setting')
            . '</p></div>';
    });
    return;
}

define('RRS_SETTING_PLUGIN_FILE', __FILE__);

// Conditional declaration (not hoisted at compile time): see rrs_setting_old_copy_active().
if (!class_exists('RetiRiserveSetting', false)) {
class RetiRiserveSetting {
    const PARENT_MENU_SLUG = 'reti-riserve-suite';
    const MENU_SLUG = 'reti-riserve-setting';
    const OPTION_KEY = 'reti_riserve_setting_options';
    const NONCE_SAVE = 'reti_riserve_setting_save';
    const FRONT_HANDLE = 'rrs-setting-front';
    const FRONT_STYLE_HANDLE = 'rrs-setting-front-style';
    const NONCE_BULK_ACF = 'rrs_bulk_acf_mostra_figli';
    const OPTION_BULK_ACF_DONE = 'rrs_bulk_acf_mostra_figli_done';
    const NONCE_DELETE_COMMENTS = 'rrs_delete_comments';
    /** Comment types used internally by plugins (WooCommerce order notes...): never deleted. */
    const PROTECTED_COMMENT_TYPES = array('order_note', 'webhook_delivery', 'action_log');
    protected $front_debug_printed = false;

    public function init() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_post_reti_riserve_setting_save', array($this, 'handle_save'));
        add_action('admin_post_rrs_bulk_acf_mostra_figli', array($this, 'handle_bulk_acf_mostra_figli'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_styles'));
        add_filter('post_thumbnail_html', array($this, 'filter_post_thumbnail_html'), 10, 5);
        add_filter('get_the_post_thumbnail_url', array($this, 'filter_post_thumbnail_url'), 10, 3);
        add_filter('has_post_thumbnail', array($this, 'filter_has_post_thumbnail'), 10, 3);
        add_action('wp_head', array($this, 'print_front_debug_marker_head'), 1);
        add_action('wp_footer', array($this, 'print_front_debug_marker_footer'), 98);
        add_action('wp_footer', array($this, 'print_front_script_fallback'), 99);
        add_action('login_enqueue_scripts', array($this, 'print_login_styles'));
        add_filter('login_headerurl', array($this, 'filter_login_header_url'));
        add_filter('login_headertext', array($this, 'filter_login_header_text'));
        add_filter('login_message', array($this, 'filter_login_message'));
        add_action('admin_post_rrs_delete_comments', array($this, 'handle_delete_comments'));

        if ($this->comments_disabled()) {
            $this->register_comments_disabled_hooks();
        }
    }

    public function register_admin_menu() {
        if (!isset($GLOBALS['admin_page_hooks'][self::PARENT_MENU_SLUG])) {
            add_menu_page(
                __('Area051 WP', 'reti-riserve-setting'),
                __('Area051 WP', 'reti-riserve-setting'),
                'manage_options',
                self::PARENT_MENU_SLUG,
                array($this, 'render_hub_page'),
                'dashicons-admin-tools',
                58
            );
        }

        add_submenu_page(
            self::PARENT_MENU_SLUG,
            __('Setting', 'reti-riserve-setting'),
            __('Setting', 'reti-riserve-setting'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function render_hub_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Area051 WP', 'reti-riserve-setting') . '</h1>';
        echo '<p>' . esc_html__('Suite strumenti utili per il progetto Reti Riserve.', 'reti-riserve-setting') . '</p>';
        echo '</div>';
    }

    public function enqueue_admin_assets($hook) {
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $expected_hook = self::PARENT_MENU_SLUG . '_page_' . self::MENU_SLUG;

        if ($current_page !== self::MENU_SLUG && $hook !== $expected_hook) {
            return;
        }

        wp_enqueue_media();
        wp_register_script('rrs-setting-admin', false, array('jquery'), '1.0.1', true);
        wp_enqueue_script('rrs-setting-admin');

        $script = <<<'JS'
jQuery(function($){
    function bindMediaButton(buttonSelector, inputSelector, previewSelector) {
        let frame;

        $(document).on('click', buttonSelector, function(e) {
            e.preventDefault();

            if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                return;
            }

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Seleziona logo',
                button: {
                    text: 'Usa questo logo'
                },
                library: {
                    type: 'image'
                },
                multiple: false
            });

            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                const imageUrl = attachment.url || '';
                $(inputSelector).val(imageUrl);
                $(previewSelector).attr('src', imageUrl).show();
            });

            frame.open();
        });

        $(document).on('click', buttonSelector + '-clear', function(e) {
            e.preventDefault();
            $(inputSelector).val('');
            $(previewSelector).attr('src', '').hide();
        });
    }

    bindMediaButton('#rrs-logo-default-btn', '#rrs_logo_default', '#rrs-logo-default-preview');
    bindMediaButton('#rrs-logo-sticky-btn', '#rrs_logo_sticky', '#rrs-logo-sticky-preview');
    bindMediaButton('#rrs-preview-default-btn', '#rrs_preview_default_image', '#rrs-preview-default-image-preview');
    bindMediaButton('#rrs-testata-default-btn', '#rrs_testata_default_image', '#rrs-testata-default-image-preview');
    bindMediaButton('#rrs-login-logo-btn', '#rrs_login_logo_custom', '#rrs-login-logo-preview');
    bindMediaButton('#rrs-login-bg-btn', '#rrs_login_background', '#rrs-login-bg-preview');

    function toggleLoginLogoRow() {
        $('#rrs-login-logo-custom-row').toggle($('#rrs_login_logo_source').val() === 'custom');
    }
    $(document).on('change', '#rrs_login_logo_source', toggleLoginLogoRow);
    toggleLoginLogoRow();

    $(document).on('input', '#rrs_login_overlay_opacity', function() {
        $('#rrs-login-overlay-opacity-value').text($(this).val() + '%');
    });
});
JS;

        wp_add_inline_script('rrs-setting-admin', $script);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $default_logo = isset($settings['logo_default']) ? (string) $settings['logo_default'] : '';
        $sticky_logo = isset($settings['logo_sticky']) ? (string) $settings['logo_sticky'] : '';
        $default_preview_image = isset($settings['preview_default_image']) ? (string) $settings['preview_default_image'] : '';
        $default_testata_image = isset($settings['testata_default_image']) ? (string) $settings['testata_default_image'] : '';
        $notice = isset($_GET['rrs_notice']) ? sanitize_key(wp_unslash($_GET['rrs_notice'])) : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Setting', 'reti-riserve-setting') . '</h1>';

        if ($notice === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Impostazioni salvate.', 'reti-riserve-setting') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px;">';
        echo '<input type="hidden" name="action" value="reti_riserve_setting_save">';
        wp_nonce_field(self::NONCE_SAVE, 'rrs_nonce');

        echo '<table class="form-table" role="presentation">';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_logo_default">' . esc_html__('Logo Default', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="url" id="rrs_logo_default" name="rrs_logo_default" value="' . esc_attr($default_logo) . '" class="regular-text" readonly>';
        echo '<p>';
        echo '<button type="button" class="button" id="rrs-logo-default-btn">' . esc_html__('Scegli da Media', 'reti-riserve-setting') . '</button> ';
        echo '<button type="button" class="button" id="rrs-logo-default-btn-clear">' . esc_html__('Rimuovi', 'reti-riserve-setting') . '</button>';
        echo '</p>';
        echo '<img id="rrs-logo-default-preview" src="' . esc_url($default_logo) . '" alt="" style="max-width:180px;height:auto;' . ($default_logo === '' ? 'display:none;' : '') . '">';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_logo_sticky">' . esc_html__('Logo Sticky', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="url" id="rrs_logo_sticky" name="rrs_logo_sticky" value="' . esc_attr($sticky_logo) . '" class="regular-text" readonly>';
        echo '<p>';
        echo '<button type="button" class="button" id="rrs-logo-sticky-btn">' . esc_html__('Scegli da Media', 'reti-riserve-setting') . '</button> ';
        echo '<button type="button" class="button" id="rrs-logo-sticky-btn-clear">' . esc_html__('Rimuovi', 'reti-riserve-setting') . '</button>';
        echo '</p>';
        echo '<img id="rrs-logo-sticky-preview" src="' . esc_url($sticky_logo) . '" alt="" style="max-width:180px;height:auto;' . ($sticky_logo === '' ? 'display:none;' : '') . '">';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_preview_default_image">' . esc_html__('Immagine anteprima default (Post/Page)', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="url" id="rrs_preview_default_image" name="rrs_preview_default_image" value="' . esc_attr($default_preview_image) . '" class="regular-text" readonly>';
        echo '<p>';
        echo '<button type="button" class="button" id="rrs-preview-default-btn">' . esc_html__('Scegli da Media', 'reti-riserve-setting') . '</button> ';
        echo '<button type="button" class="button" id="rrs-preview-default-btn-clear">' . esc_html__('Rimuovi', 'reti-riserve-setting') . '</button>';
        echo '</p>';
        echo '<img id="rrs-preview-default-image-preview" src="' . esc_url($default_preview_image) . '" alt="" style="max-width:180px;height:auto;' . ($default_preview_image === '' ? 'display:none;' : '') . '">';
        echo '<p class="description">' . esc_html__('Viene usata come fallback quando un post o una pagina non ha immagine in evidenza.', 'reti-riserve-setting') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_testata_default_image">' . esc_html__('Frame immagine default per testata', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="url" id="rrs_testata_default_image" name="rrs_testata_default_image" value="' . esc_attr($default_testata_image) . '" class="regular-text" readonly>';
        echo '<p>';
        echo '<button type="button" class="button" id="rrs-testata-default-btn">' . esc_html__('Scegli da Media', 'reti-riserve-setting') . '</button> ';
        echo '<button type="button" class="button" id="rrs-testata-default-btn-clear">' . esc_html__('Rimuovi', 'reti-riserve-setting') . '</button>';
        echo '</p>';
        echo '<img id="rrs-testata-default-image-preview" src="' . esc_url($default_testata_image) . '" alt="" style="max-width:180px;height:auto;' . ($default_testata_image === '' ? 'display:none;' : '') . '">';
        echo '<p class="description">' . esc_html__('Usata dallo shortcode galleria_acf quando campo="testata" e il campo ACF e vuoto.', 'reti-riserve-setting') . '</p>';
        echo '</td>';
        echo '</tr>';

        $login_logo_custom = (string) $settings['login_logo_custom'];
        $login_background = (string) $settings['login_background'];

        echo '<tr><th colspan="2"><h2 style="margin:10px 0 0;">' . esc_html__('Commenti', 'reti-riserve-setting') . '</h2></th></tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Disattiva commenti', 'reti-riserve-setting') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="rrs_comments_disabled" value="1" ' . checked(1, (int) $settings['comments_disabled'], false) . '> ' . esc_html__('Disattiva i commenti e i pingback su tutto il sito', 'reti-riserve-setting') . '</label>';
        echo '<p class="description">' . esc_html__('Chiude commenti e pingback su tutti i contenuti, nasconde quelli esistenti nel sito, blocca l\'invio (anche via REST e XML-RPC) e toglie la voce Commenti dalla bacheca. I commenti già presenti restano nel database: per eliminarli usa il pulsante "Elimina commenti" più in basso.', 'reti-riserve-setting') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr><th colspan="2"><h2 style="margin:10px 0 0;">' . esc_html__('Pagina di accesso', 'reti-riserve-setting') . '</h2></th></tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Personalizza login', 'reti-riserve-setting') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="rrs_login_custom" value="1" ' . checked(1, (int) $settings['login_custom'], false) . '> ' . esc_html__('Usa logo, sfondo e titolo personalizzati nella pagina wp-login.php', 'reti-riserve-setting') . '</label>';
        echo ' <a href="' . esc_url(wp_login_url()) . '" target="_blank" rel="noopener">' . esc_html__('Apri anteprima login', 'reti-riserve-setting') . '</a>';
        echo '<p class="description">' . esc_html__('Per vedere la pagina usa una finestra in incognito, altrimenti WordPress ti riporta in bacheca.', 'reti-riserve-setting') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_login_title">' . esc_html__('Titolo sotto il logo', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="rrs_login_title" name="rrs_login_title" value="' . esc_attr($settings['login_title']) . '" class="regular-text" placeholder="Area di gestione">';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_login_subtitle">' . esc_html__('Sottotitolo', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="rrs_login_subtitle" name="rrs_login_subtitle" value="' . esc_attr($settings['login_subtitle']) . '" class="regular-text" placeholder="Accedi per gestire i contenuti del sito">';
        echo '<p class="description">' . esc_html__('Lascia vuoti titolo e sottotitolo per non mostrare testo sotto il logo.', 'reti-riserve-setting') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_login_logo_source">' . esc_html__('Logo login', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<select id="rrs_login_logo_source" name="rrs_login_logo_source">';
        $logo_sources = array(
            'sticky' => __('Logo Sticky', 'reti-riserve-setting'),
            'default' => __('Logo Default', 'reti-riserve-setting'),
            'custom' => __('Altro logo (scegli da Media)', 'reti-riserve-setting'),
            'none' => __('Nessun logo (solo titolo)', 'reti-riserve-setting'),
        );
        foreach ($logo_sources as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['login_logo_source'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Il logo sta su una scheda bianca: usa una versione scura o a colori.', 'reti-riserve-setting') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr id="rrs-login-logo-custom-row">';
        echo '<th scope="row"><label for="rrs_login_logo_custom">' . esc_html__('Altro logo', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="url" id="rrs_login_logo_custom" name="rrs_login_logo_custom" value="' . esc_attr($login_logo_custom) . '" class="regular-text" readonly>';
        echo '<p>';
        echo '<button type="button" class="button" id="rrs-login-logo-btn">' . esc_html__('Scegli da Media', 'reti-riserve-setting') . '</button> ';
        echo '<button type="button" class="button" id="rrs-login-logo-btn-clear">' . esc_html__('Rimuovi', 'reti-riserve-setting') . '</button>';
        echo '</p>';
        echo '<img id="rrs-login-logo-preview" src="' . esc_url($login_logo_custom) . '" alt="" style="max-width:180px;height:auto;' . ($login_logo_custom === '' ? 'display:none;' : '') . '">';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_login_background">' . esc_html__('Immagine di sfondo', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="url" id="rrs_login_background" name="rrs_login_background" value="' . esc_attr($login_background) . '" class="regular-text" readonly>';
        echo '<p>';
        echo '<button type="button" class="button" id="rrs-login-bg-btn">' . esc_html__('Scegli da Media', 'reti-riserve-setting') . '</button> ';
        echo '<button type="button" class="button" id="rrs-login-bg-btn-clear">' . esc_html__('Rimuovi', 'reti-riserve-setting') . '</button>';
        echo '</p>';
        echo '<img id="rrs-login-bg-preview" src="' . esc_url($login_background) . '" alt="" style="max-width:240px;height:auto;' . ($login_background === '' ? 'display:none;' : '') . '">';
        echo '<p class="description">' . esc_html__('Foto orizzontale, almeno 1920px di larghezza (es. panorama del Monte Bondone). Senza immagine si usa una sfumatura del colore overlay.', 'reti-riserve-setting') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_login_overlay_color">' . esc_html__('Overlay sullo sfondo', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="color" id="rrs_login_overlay_color" name="rrs_login_overlay_color" value="' . esc_attr($settings['login_overlay_color']) . '"> ';
        echo '<input type="range" id="rrs_login_overlay_opacity" name="rrs_login_overlay_opacity" min="0" max="95" step="5" value="' . esc_attr((string) (int) $settings['login_overlay_opacity']) . '" style="vertical-align:middle;width:200px;"> ';
        echo '<span id="rrs-login-overlay-opacity-value">' . esc_html((int) $settings['login_overlay_opacity'] . '%') . '</span>';
        echo '<p class="description">' . esc_html__('Colore e opacità del velo sopra la foto: più alto = foto meno visibile, testo più leggibile.', 'reti-riserve-setting') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="rrs_login_accent">' . esc_html__('Colore pulsante e link', 'reti-riserve-setting') . '</label></th>';
        echo '<td>';
        echo '<input type="color" id="rrs_login_accent" name="rrs_login_accent" value="' . esc_attr($settings['login_accent']) . '">';
        echo '<p class="description">' . esc_html__('Pulsante "Accedi", bordo dei campi attivi e link.', 'reti-riserve-setting') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        submit_button(__('Salva', 'reti-riserve-setting'));

        echo '</form>';

        $this->render_delete_comments_section($notice);

        echo '<hr>';
        echo '<h2>' . esc_html__('Azioni bulk ACF', 'reti-riserve-setting') . '</h2>';

        $bulk_done = get_option(self::OPTION_BULK_ACF_DONE, false);
        if ($bulk_done) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Campo "mostra_figli" è già stato impostato su tutte le pagine.', 'reti-riserve-setting')
                . '</p></div>';
        } else {
            echo '<p>' . esc_html__('Clicca il pulsante sottostante per impostare il campo ACF "mostra_figli" a TRUE su tutte le page. Questa azione può essere eseguita una sola volta.', 'reti-riserve-setting') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="rrs_bulk_acf_mostra_figli">';
            wp_nonce_field(self::NONCE_BULK_ACF, 'rrs_bulk_nonce');
            submit_button(__('Imposta "mostra_figli" TRUE su tutte le page', 'reti-riserve-setting'), 'secondary');
            echo '</form>';
        }

        echo '</div>';
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'reti-riserve-setting'));
        }

        check_admin_referer(self::NONCE_SAVE, 'rrs_nonce');

        $settings = array(
            'logo_default' => isset($_POST['rrs_logo_default']) ? esc_url_raw(trim(wp_unslash($_POST['rrs_logo_default']))) : '',
            'logo_sticky' => isset($_POST['rrs_logo_sticky']) ? esc_url_raw(trim(wp_unslash($_POST['rrs_logo_sticky']))) : '',
            'preview_default_image' => isset($_POST['rrs_preview_default_image']) ? esc_url_raw(trim(wp_unslash($_POST['rrs_preview_default_image']))) : '',
            'testata_default_image' => isset($_POST['rrs_testata_default_image']) ? esc_url_raw(trim(wp_unslash($_POST['rrs_testata_default_image']))) : '',
            'comments_disabled' => !empty($_POST['rrs_comments_disabled']) ? 1 : 0,
            'login_custom' => !empty($_POST['rrs_login_custom']) ? 1 : 0,
            'login_title' => isset($_POST['rrs_login_title']) ? sanitize_text_field(wp_unslash($_POST['rrs_login_title'])) : '',
            'login_subtitle' => isset($_POST['rrs_login_subtitle']) ? sanitize_text_field(wp_unslash($_POST['rrs_login_subtitle'])) : '',
            'login_logo_source' => isset($_POST['rrs_login_logo_source']) ? sanitize_key(wp_unslash($_POST['rrs_login_logo_source'])) : 'sticky',
            'login_logo_custom' => isset($_POST['rrs_login_logo_custom']) ? esc_url_raw(trim(wp_unslash($_POST['rrs_login_logo_custom']))) : '',
            'login_background' => isset($_POST['rrs_login_background']) ? esc_url_raw(trim(wp_unslash($_POST['rrs_login_background']))) : '',
            'login_overlay_color' => isset($_POST['rrs_login_overlay_color']) ? (string) sanitize_hex_color(wp_unslash($_POST['rrs_login_overlay_color'])) : '',
            'login_overlay_opacity' => isset($_POST['rrs_login_overlay_opacity']) ? min(95, max(0, absint($_POST['rrs_login_overlay_opacity']))) : 60,
            'login_accent' => isset($_POST['rrs_login_accent']) ? (string) sanitize_hex_color(wp_unslash($_POST['rrs_login_accent'])) : '',
        );

        if (!in_array($settings['login_logo_source'], array('sticky', 'default', 'custom', 'none'), true)) {
            $settings['login_logo_source'] = 'sticky';
        }
        if ($settings['login_overlay_color'] === '') {
            $settings['login_overlay_color'] = '#1f4d2e';
        }
        if ($settings['login_accent'] === '') {
            $settings['login_accent'] = '#2e7d4f';
        }

        update_option(self::OPTION_KEY, $settings);

        if (!empty($settings['comments_disabled'])) {
            // New content is created with comments closed too.
            update_option('default_comment_status', 'closed');
            update_option('default_ping_status', 'closed');
        }

        $redirect = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'rrs_notice' => 'saved',
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    protected function comments_disabled() {
        $settings = $this->get_settings();
        return !empty($settings['comments_disabled']);
    }

    /**
     * Turns comments off everywhere: front end, admin, REST, XML-RPC.
     */
    protected function register_comments_disabled_hooks() {
        // Front end: closed and hidden.
        add_filter('comments_open', '__return_false', 20);
        add_filter('pings_open', '__return_false', 20);
        add_filter('comments_array', '__return_empty_array', 20);
        add_filter('get_comments_number', '__return_zero', 20);
        add_filter('feed_links_show_comments_feed', '__return_false');

        // Submissions: wp-comments-post.php, REST API, XML-RPC pingbacks.
        add_action('pre_comment_on_post', array($this, 'block_comment_submission'));
        add_filter('rest_endpoints', array($this, 'filter_rest_endpoints_comments'));
        add_filter('xmlrpc_methods', array($this, 'filter_xmlrpc_methods_comments'));
        add_filter('wp_headers', array($this, 'filter_headers_pingback'));

        // Post types: no comments/trackbacks support, so editors hide the panels.
        add_action('init', array($this, 'remove_comments_post_type_support'), 100);

        // Admin: menu, admin bar, dashboard widget, comments screens.
        add_action('admin_menu', array($this, 'remove_comments_admin_menu'), 999);
        add_action('admin_bar_menu', array($this, 'remove_comments_admin_bar'), 999);
        add_action('wp_dashboard_setup', array($this, 'remove_comments_dashboard_widget'));
        add_action('admin_init', array($this, 'redirect_comments_admin_pages'));
    }

    public function block_comment_submission() {
        wp_die(esc_html__('I commenti sono disattivati su questo sito.', 'reti-riserve-setting'), '', array('response' => 403));
    }

    public function filter_rest_endpoints_comments($endpoints) {
        foreach (array_keys($endpoints) as $route) {
            if (strpos($route, '/wp/v2/comments') === 0) {
                unset($endpoints[$route]);
            }
        }
        return $endpoints;
    }

    public function filter_xmlrpc_methods_comments($methods) {
        foreach (array('pingback.ping', 'pingback.extensions.getPingbacks', 'wp.newComment', 'wp.editComment', 'wp.deleteComment') as $method) {
            unset($methods[$method]);
        }
        return $methods;
    }

    public function filter_headers_pingback($headers) {
        unset($headers['X-Pingback']);
        return $headers;
    }

    public function remove_comments_post_type_support() {
        foreach (get_post_types() as $post_type) {
            if (post_type_supports($post_type, 'comments')) {
                remove_post_type_support($post_type, 'comments');
            }
            if (post_type_supports($post_type, 'trackbacks')) {
                remove_post_type_support($post_type, 'trackbacks');
            }
        }
    }

    public function remove_comments_admin_menu() {
        remove_menu_page('edit-comments.php');
    }

    public function remove_comments_admin_bar($wp_admin_bar) {
        $wp_admin_bar->remove_node('comments');
    }

    public function remove_comments_dashboard_widget() {
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
    }

    public function redirect_comments_admin_pages() {
        global $pagenow;
        if ($pagenow === 'edit-comments.php' || $pagenow === 'comment.php') {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    /**
     * Comment counts that "Elimina commenti" would delete, by status.
     */
    protected function count_deletable_comments() {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(self::PROTECTED_COMMENT_TYPES), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT comment_approved AS status, COUNT(*) AS total FROM {$wpdb->comments} WHERE comment_type NOT IN ($placeholders) GROUP BY comment_approved",
            self::PROTECTED_COMMENT_TYPES
        ));

        $counts = array('total' => 0, 'approved' => 0, 'pending' => 0, 'spam' => 0, 'trash' => 0);
        foreach ((array) $rows as $row) {
            $total = (int) $row->total;
            $counts['total'] += $total;
            if ($row->status === '1') {
                $counts['approved'] += $total;
            } elseif ($row->status === '0') {
                $counts['pending'] += $total;
            } elseif ($row->status === 'spam') {
                $counts['spam'] += $total;
            } elseif ($row->status === 'trash' || $row->status === 'post-trashed') {
                $counts['trash'] += $total;
            }
        }

        return $counts;
    }

    protected function render_delete_comments_section($notice) {
        echo '<hr>';
        echo '<h2>' . esc_html__('Elimina commenti', 'reti-riserve-setting') . '</h2>';

        if ($notice === 'comments_deleted') {
            $deleted = isset($_GET['deleted']) ? absint($_GET['deleted']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Commenti eliminati: %d.', 'reti-riserve-setting'), $deleted)) . '</p></div>';
        }

        $counts = $this->count_deletable_comments();

        if ($counts['total'] === 0) {
            echo '<p>' . esc_html__('Nessun commento presente nel database.', 'reti-riserve-setting') . '</p>';
            return;
        }

        echo '<p>' . esc_html(sprintf(
            __('Commenti presenti: %1$d (approvati %2$d, in attesa %3$d, spam %4$d, cestino %5$d), compresi pingback e trackback.', 'reti-riserve-setting'),
            $counts['total'],
            $counts['approved'],
            $counts['pending'],
            $counts['spam'],
            $counts['trash']
        )) . '</p>';
        echo '<p class="description">' . esc_html__('L\'eliminazione è definitiva e non passa dal cestino. Le note degli ordini WooCommerce non vengono toccate. Fai un backup del database prima di procedere.', 'reti-riserve-setting') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(sprintf(__('Eliminare definitivamente %d commenti? L\'operazione non si può annullare.', 'reti-riserve-setting'), $counts['total'])) . '\');">';
        echo '<input type="hidden" name="action" value="rrs_delete_comments">';
        wp_nonce_field(self::NONCE_DELETE_COMMENTS, 'rrs_delete_comments_nonce');
        submit_button(sprintf(__('Elimina tutti i %d commenti', 'reti-riserve-setting'), $counts['total']), 'delete', 'submit', false);
        echo '</form>';
    }

    public function handle_delete_comments() {
        if (!current_user_can('manage_options') || !current_user_can('moderate_comments')) {
            wp_die(esc_html__('Non autorizzato.', 'reti-riserve-setting'));
        }

        check_admin_referer(self::NONCE_DELETE_COMMENTS, 'rrs_delete_comments_nonce');

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count(self::PROTECTED_COMMENT_TYPES), '%s'));
        $deleted = 0;

        // Batches keep each query small on big spam tables.
        do {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type NOT IN ($placeholders) LIMIT 1000",
                self::PROTECTED_COMMENT_TYPES
            ));
            if (empty($ids)) {
                break;
            }

            $ids = array_map('absint', $ids);
            $id_list = implode(',', $ids);
            $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($id_list)");
            $deleted += (int) $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_ID IN ($id_list)");

            foreach ($ids as $id) {
                clean_comment_cache($id);
            }
        } while (count($ids) === 1000);

        // Recount approved comments per post (protected types stay counted as before).
        $wpdb->query("UPDATE {$wpdb->posts} p SET p.comment_count = (SELECT COUNT(*) FROM {$wpdb->comments} c WHERE c.comment_post_ID = p.ID AND c.comment_approved = '1')");
        delete_transient('wc_count_comments');
        wp_cache_delete('comments-0', 'counts');
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('comment');
        }

        wp_safe_redirect(add_query_arg(
            array('page' => self::MENU_SLUG, 'rrs_notice' => 'comments_deleted', 'deleted' => $deleted),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_bulk_acf_mostra_figli() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non autorizzato.', 'reti-riserve-setting'));
        }

        check_admin_referer(self::NONCE_BULK_ACF, 'rrs_bulk_nonce');

        if (get_option(self::OPTION_BULK_ACF_DONE, false)) {
            wp_safe_redirect(add_query_arg(
                array('page' => self::MENU_SLUG, 'rrs_notice' => 'bulk_already_done'),
                admin_url('admin.php')
            ));
            exit;
        }

        $pages = get_posts(array(
            'post_type' => 'page',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ));

        $updated_count = 0;
        foreach ($pages as $page) {
            update_field('mostra_figli', true, $page->ID);
            $updated_count++;
        }

        update_option(self::OPTION_BULK_ACF_DONE, true);

        wp_safe_redirect(add_query_arg(
            array('page' => self::MENU_SLUG, 'rrs_notice' => 'bulk_done', 'updated' => $updated_count),
            admin_url('admin.php')
        ));
        exit;
    }

    public function enqueue_front_assets() {
        if (is_admin()) {
            return;
        }

        $settings = $this->get_settings();
        $default_logo = isset($settings['logo_default']) ? esc_url_raw($settings['logo_default']) : '';
        $sticky_logo = isset($settings['logo_sticky']) ? esc_url_raw($settings['logo_sticky']) : '';

        if ($default_logo === '' || $sticky_logo === '') {
            return;
        }

        wp_register_script(self::FRONT_HANDLE, false, array('jquery'), '1.0.1', true);
        wp_enqueue_script(self::FRONT_HANDLE);

        wp_add_inline_script(self::FRONT_HANDLE, $this->build_front_script($default_logo, $sticky_logo));
    }

    public function enqueue_front_styles() {
        if (is_admin()) {
            return;
        }

        wp_register_style(self::FRONT_STYLE_HANDLE, false, array(), '1.0.0');
        wp_enqueue_style(self::FRONT_STYLE_HANDLE);

        wp_add_inline_style(
            self::FRONT_STYLE_HANDLE,
            '.gallery-grid img, .gallery-carousel img, img.rrs-default-post-thumbnail, .et_pb_menu__logo img {'
            . 'max-height: 500px;'
            . 'height: auto;'
            . 'width: 100vw;'
            . 'object-fit: cover;'
            . '}'
        );
    }

    public function print_front_script_fallback() {
        if (is_admin()) {
            return;
        }

        $settings = $this->get_settings();
        $default_logo = isset($settings['logo_default']) ? esc_url_raw($settings['logo_default']) : '';
        $sticky_logo = isset($settings['logo_sticky']) ? esc_url_raw($settings['logo_sticky']) : '';

        if ($default_logo === '' || $sticky_logo === '') {
            return;
        }

        if (wp_script_is(self::FRONT_HANDLE, 'done')) {
            return;
        }

        echo '<script id="rrs-setting-inline-fallback">' . $this->build_front_script($default_logo, $sticky_logo) . '</script>';
    }

    public function print_front_debug_marker_head() {
        $this->print_front_debug_marker('head');
    }

    public function print_front_debug_marker_footer() {
        $this->print_front_debug_marker('footer');
    }

    protected function print_front_debug_marker($location) {
        if (is_admin() || $this->front_debug_printed) {
            return;
        }

        $settings = $this->get_settings();
        $default_logo = isset($settings['logo_default']) ? (string) $settings['logo_default'] : '';
        $sticky_logo = isset($settings['logo_sticky']) ? (string) $settings['logo_sticky'] : '';
        $preview_default_image = isset($settings['preview_default_image']) ? (string) $settings['preview_default_image'] : '';
        $testata_default_image = isset($settings['testata_default_image']) ? (string) $settings['testata_default_image'] : '';

        $payload = array(
            'location' => (string) $location,
            'defaultConfigured' => $default_logo !== '',
            'stickyConfigured' => $sticky_logo !== '',
            'previewDefaultConfigured' => $preview_default_image !== '',
            'defaultLogo' => $default_logo,
            'stickyLogo' => $sticky_logo,
            'previewDefaultImage' => $preview_default_image,
            'testataDefaultImage' => $testata_default_image,
            'frontHandleEnqueued' => wp_script_is(self::FRONT_HANDLE, 'enqueued'),
            'frontHandleDone' => wp_script_is(self::FRONT_HANDLE, 'done'),
        );

        echo '<script id="rrs-setting-debug-marker">'
            . 'console.log("[RRS-SETTING] Front debug marker", ' . wp_json_encode($payload) . ');'
            . '</script>';

        $this->front_debug_printed = true;
    }

    protected function build_front_script($default_logo, $sticky_logo) {
        $script = <<<'JS'
(function(){
    if (window.__rrsLogoSwapInit) { return; }
    window.__rrsLogoSwapInit = true;

    function rrsDebug() {
        if (typeof console === 'undefined' || typeof console.log !== 'function') {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[RRS-SETTING]');
        console.log.apply(console, args);
    }

    rrsDebug('Init logo swap script');

    function runWhenJqueryReady() {
        if (typeof window.jQuery === 'undefined') {
            rrsDebug('jQuery non disponibile, nuovo tentativo...');
            window.setTimeout(runWhenJqueryReady, 120);
            return;
        }

        jQuery(function($){
            const fixedLogo = __FIXED_LOGO__;
            const defaultLogo = __DEFAULT_LOGO__;
            const $header = $('#menutoppe');
            const $logoImg = $('.et_pb_menu__logo img');

            let lastMode = null;
            let loggedMissingLogo = false;
            let updateCalls = 0;
            let scrollCalls = 0;
            let lastScrollTop = -1;

            rrsDebug('jQuery ready', {
                headerFound: $header.length,
                logoFound: $logoImg.length,
                defaultLogo: defaultLogo,
                fixedLogo: fixedLogo
            });

            function updateLogo(trigger) {
                console.log('updateLogo called');
                updateCalls++;
                const source = trigger || 'unknown';

                if (source === 'scroll' || source === 'init' || source === 'scroll-position-change') {
                    rrsDebug('updateLogo chiamata', {source: source, call: updateCalls});
                }

                if (!$logoImg.length) {
                    if (!loggedMissingLogo) {
                        rrsDebug('Nessun logo trovato con selettore .et_pb_menu__logo img');
                        loggedMissingLogo = true;
                    }
                    return;
                }

                const isSticky = $header.hasClass('et_pb_sticky--top');
                const mode = isSticky ? 'sticky' : 'default';

                if (mode !== lastMode) {
                    rrsDebug('Cambio stato header', {mode: mode, hasHeader: $header.length > 0});
                    lastMode = mode;
                }

                if (isSticky) {
                    $logoImg.attr('src', fixedLogo);
                } else {
                    $logoImg.attr('src', defaultLogo);
                }
            }

            updateLogo('init');

            function onAnyScroll(source) {
                scrollCalls++;
                rrsDebug('Evento scroll rilevato', {scrollCall: scrollCalls, source: source, y: window.pageYOffset || 0});
                updateLogo('scroll');
            }

            $(window).on('scroll', function(){ onAnyScroll('window'); });
            $(document).on('scroll', function(){ onAnyScroll('document-jquery'); });

            if (document && document.addEventListener) {
                document.addEventListener('scroll', function(){ onAnyScroll('document-capture'); }, true);
            }

            $('html, body').on('scroll', function(){ onAnyScroll('html-body'); });

            rrsDebug('Scroll listeners agganciati', {window: true, document: true, htmlBody: true});

            window.setInterval(function(){
                var currentTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
                if (currentTop !== lastScrollTop) {
                    if (lastScrollTop !== -1) {
                        rrsDebug('Variazione scrollTop rilevata', {from: lastScrollTop, to: currentTop});
                        updateLogo('scroll-position-change');
                    }
                    lastScrollTop = currentTop;
                }
            }, 250);

            window.setInterval(function(){ updateLogo('interval'); }, 500);
        });
    }

    runWhenJqueryReady();
})();
JS;

        return str_replace(
            array('__FIXED_LOGO__', '__DEFAULT_LOGO__'),
            array(wp_json_encode($sticky_logo), wp_json_encode($default_logo)),
            $script
        );
    }

    protected function get_settings() {
        $settings = get_option(self::OPTION_KEY, array());

        if (!is_array($settings)) {
            $settings = array();
        }

        return wp_parse_args(
            $settings,
            array(
                'logo_default' => '',
                'logo_sticky' => '',
                'preview_default_image' => '',
                'testata_default_image' => '',
                'comments_disabled' => 0,
                'login_custom' => 1,
                'login_title' => 'Area di gestione',
                'login_subtitle' => 'Accedi per gestire i contenuti del sito',
                'login_logo_source' => 'sticky',
                'login_logo_custom' => '',
                'login_background' => '',
                'login_overlay_color' => '#1f4d2e',
                'login_overlay_opacity' => 60,
                'login_accent' => '#2e7d4f',
            )
        );
    }

    protected function login_is_custom() {
        $settings = $this->get_settings();
        return !empty($settings['login_custom']);
    }

    protected function get_login_logo_url() {
        $settings = $this->get_settings();
        $source = (string) $settings['login_logo_source'];

        if ($source === 'none') {
            return '';
        }

        $order = array(
            'sticky' => array('logo_sticky', 'logo_default'),
            'default' => array('logo_default', 'logo_sticky'),
            'custom' => array('login_logo_custom', 'logo_sticky', 'logo_default'),
        );
        $keys = isset($order[$source]) ? $order[$source] : $order['sticky'];

        foreach ($keys as $key) {
            if (!empty($settings[$key])) {
                return esc_url_raw($settings[$key]);
            }
        }

        $custom_logo_id = (int) get_theme_mod('custom_logo');
        if ($custom_logo_id > 0) {
            $url = wp_get_attachment_image_url($custom_logo_id, 'medium');
            if ($url) {
                return $url;
            }
        }

        return '';
    }

    protected function hex_to_rgba($hex, $alpha) {
        $hex = ltrim((string) sanitize_hex_color($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            $hex = '1f4d2e';
        }
        return sprintf(
            'rgba(%d,%d,%d,%s)',
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
            rtrim(rtrim(number_format(max(0, min(1, (float) $alpha)), 2, '.', ''), '0'), '.')
        );
    }

    public function filter_login_header_url($url) {
        return $this->login_is_custom() ? home_url('/') : $url;
    }

    public function filter_login_header_text($text) {
        return $this->login_is_custom() ? get_bloginfo('name') : $text;
    }

    public function filter_login_message($message) {
        if (!$this->login_is_custom()) {
            return $message;
        }

        $settings = $this->get_settings();
        $title = trim((string) $settings['login_title']);
        $subtitle = trim((string) $settings['login_subtitle']);

        $html = '';
        if ($title !== '') {
            $html .= '<p class="rrs-login-title">' . esc_html($title) . '</p>';
        }
        if ($subtitle !== '') {
            $html .= '<p class="rrs-login-subtitle">' . esc_html($subtitle) . '</p>';
        }

        return $html !== '' ? '<div class="rrs-login-heading">' . $html . '</div>' . $message : $message;
    }

    public function print_login_styles() {
        if (!$this->login_is_custom()) {
            return;
        }

        $settings = $this->get_settings();
        $accent = sanitize_hex_color($settings['login_accent']);
        if (!$accent) {
            $accent = '#2e7d4f';
        }
        $overlay_hex = sanitize_hex_color($settings['login_overlay_color']);
        if (!$overlay_hex) {
            $overlay_hex = '#1f4d2e';
        }
        $opacity = max(0, min(95, (int) $settings['login_overlay_opacity'])) / 100;
        $overlay = $this->hex_to_rgba($overlay_hex, $opacity);
        $overlay_strong = $this->hex_to_rgba($overlay_hex, min(1, $opacity + 0.2));
        $background = esc_url_raw((string) $settings['login_background']);
        $logo = $this->get_login_logo_url();

        $body_bg = $background !== ''
            ? 'background:#1d2b22 url("' . esc_url($background) . '") center/cover no-repeat fixed;'
            : 'background:linear-gradient(160deg,' . $this->hex_to_rgba($overlay_hex, 1) . ' 0%,#0f1f15 100%);';

        $logo_css = $logo !== ''
            ? '.login h1 a{background-image:url("' . esc_url($logo) . '");background-size:contain;background-position:center;background-repeat:no-repeat;width:100%;max-width:240px;height:88px;margin:0 auto 12px;}'
            : '.login h1{display:none;}';

        $css = ':root{--rrs-accent:' . $accent . ';}'
            // Page + overlay.
            . 'html,body.login{min-height:100%;}'
            . 'body.login{' . $body_bg . 'display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 16px;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}'
            . 'body.login::before{content:"";position:fixed;inset:0;background:linear-gradient(180deg,' . $overlay . ' 0%,' . $overlay_strong . ' 100%);z-index:0;pointer-events:none;}'
            . 'body.login > *{position:relative;z-index:1;}'
            // Card.
            . '.login #login{width:380px;max-width:100%;padding:36px 32px 24px;margin:0;background:rgba(255,255,255,.97);border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.35),0 2px 8px rgba(0,0,0,.15);box-sizing:border-box;}'
            . $logo_css
            . '.login .rrs-login-heading{text-align:center;margin:0 0 24px;}'
            . '.login .rrs-login-title{font-size:20px;font-weight:650;line-height:1.3;margin:0;color:#1d2327;letter-spacing:.01em;}'
            . '.login .rrs-login-subtitle{font-size:14px;line-height:1.4;margin:4px 0 0;color:#646970;}'
            . '.login form{background:transparent;border:0;box-shadow:none;padding:0;margin:0;overflow:visible;}'
            . '.login label{font-weight:500;color:#2c3338;}'
            . '.login form .input,.login input[type=text],.login input[type=password],.login input[type=email]{border-radius:10px;border:1px solid #cfd6cc;background:#fbfcfa;padding:8px 12px;font-size:16px;box-shadow:none;min-height:44px;}'
            . '.login form .input:focus,.login input[type=text]:focus,.login input[type=password]:focus,.login input[type=email]:focus{border-color:var(--rrs-accent);background:#fff;box-shadow:0 0 0 3px ' . $this->hex_to_rgba($accent, 0.2) . ';outline:0;}'
            . '.login .button.wp-hide-pw{min-height:44px;}'
            . '.login .button.wp-hide-pw .dashicons{color:var(--rrs-accent);}'
            . '.login .forgetmenot{float:none;margin-top:6px;}'
            . '.login input[type=checkbox]:focus{border-color:var(--rrs-accent);box-shadow:0 0 0 1px var(--rrs-accent);}'
            . '.login .submit{margin-top:16px;}'
            . '.login .button-primary,.wp-core-ui.login .button-primary{float:none;width:100%;min-height:46px;margin-top:12px;background:var(--rrs-accent);border-color:var(--rrs-accent);border-radius:10px;box-shadow:none;text-shadow:none;font-size:15px;font-weight:600;color:#fff;}'
            . '.login .button-primary:hover,.wp-core-ui.login .button-primary:hover{filter:brightness(.9);background:var(--rrs-accent);border-color:var(--rrs-accent);color:#fff;}'
            . '.login .button-primary:focus,.wp-core-ui.login .button-primary:focus{box-shadow:0 0 0 2px #fff,0 0 0 4px var(--rrs-accent);background:var(--rrs-accent);color:#fff;}'
            // Messages.
            . '.login .message,.login .notice,.login .success,.login #login_error{border-radius:10px;border-left-color:var(--rrs-accent);box-shadow:none;background:#f3f6f2;margin:0 0 20px;}'
            . '.login #login_error{border-left-color:#d63638;background:#fcf0f1;}'
            // Links inside the card.
            . '.login #nav,.login #backtoblog{text-align:center;padding:0;margin:16px 0 0;}'
            . '.login #backtoblog{margin-top:8px;}'
            . '.login #nav a,.login #backtoblog a{color:#50575e;text-decoration:none;}'
            . '.login #nav a:hover,.login #backtoblog a:hover{color:var(--rrs-accent);text-decoration:underline;}'
            // Outside the card, on the overlay.
            . '.login .privacy-policy-page-link{margin:16px 0 0;}'
            . '.login .privacy-policy-page-link a{color:#fff;opacity:.85;}'
            . '.login .language-switcher{margin-top:20px;color:#fff;}'
            . '.login .language-switcher label,.login .language-switcher .dashicons{color:#fff;}'
            . '.login .language-switcher select{border-radius:8px;}'
            . '.login .language-switcher .button{border-radius:8px;background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.6);color:#fff;}'
            . '.login .language-switcher .button:hover{background:rgba(255,255,255,.25);color:#fff;}'
            . '@media (max-width:480px){body.login{padding:16px;justify-content:flex-start;}.login #login{padding:28px 20px 20px;margin-top:4vh;}body.login{background-attachment:scroll;}}';

        // Added to the core "login" stylesheet so it is printed after it and wins.
        if (wp_style_is('login', 'enqueued') || wp_style_is('login', 'registered')) {
            wp_add_inline_style('login', $css);
        } else {
            echo '<style id="rrs-login-style">' . $css . '</style>';
        }
    }

    protected function is_supported_preview_post_type($post) {
        $post_obj = get_post($post);

        if (!$post_obj instanceof WP_Post) {
            return false;
        }

        return in_array($post_obj->post_type, array('post', 'page'), true);
    }

    protected function get_preview_default_image() {
        $settings = $this->get_settings();
        return isset($settings['preview_default_image']) ? esc_url_raw((string) $settings['preview_default_image']) : '';
    }

    public function filter_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if ($html !== '') {
            return $html;
        }

        if (!$this->is_supported_preview_post_type($post_id)) {
            return $html;
        }

        $fallback_url = $this->get_preview_default_image();
        if ($fallback_url === '') {
            return $html;
        }

        return '<img src="' . esc_url($fallback_url) . '" class="rrs-default-post-thumbnail" alt="">';
    }

    public function filter_post_thumbnail_url($thumbnail_url, $post, $size) {
        if (!empty($thumbnail_url)) {
            return $thumbnail_url;
        }

        if (!$this->is_supported_preview_post_type($post)) {
            return $thumbnail_url;
        }

        $fallback_url = $this->get_preview_default_image();
        if ($fallback_url === '') {
            return $thumbnail_url;
        }

        return $fallback_url;
    }

    public function filter_has_post_thumbnail($has_thumbnail, $post, $thumbnail_id) {
        if ($has_thumbnail) {
            return $has_thumbnail;
        }

        if (!$this->is_supported_preview_post_type($post)) {
            return $has_thumbnail;
        }

        return $this->get_preview_default_image() !== '';
    }
}
} // class_exists RetiRiserveSetting

if (!function_exists('reti_riserve_setting_bootstrap')) {
    function reti_riserve_setting_bootstrap() {
        $plugin = new RetiRiserveSetting();
        $plugin->init();
    }
}

reti_riserve_setting_bootstrap();

if (!function_exists('mostra_galleria_acf')) {
    function mostra_galleria_acf($atts) {
        $atts = shortcode_atts(array(
            'campo' => 'gallery',
            'tipo' => 'grid',
            'lightbox' => 'true',
            'elementi' => 3,
            'formato' => 'thumbnail',
            'loop' => 'false',
            'autoplay' => 'false',
        ), $atts);

        $loop = filter_var($atts['loop'], FILTER_VALIDATE_BOOLEAN);
        $autoplay = filter_var($atts['autoplay'], FILTER_VALIDATE_BOOLEAN);
        $campo = isset($atts['campo']) ? (string) $atts['campo'] : 'gallery';
        $images = get_field($campo, get_the_ID());

        if (!empty($images) && isset($images['url'])) {
            $images = array($images);
        }

        if ((empty($images) || !is_array($images)) && $campo === 'testata') {
            $settings = get_option('reti_riserve_setting_options', array());
            $fallback = '';

            if (is_array($settings) && !empty($settings['testata_default_image'])) {
                $fallback = esc_url_raw((string) $settings['testata_default_image']);
            }

            if ($fallback !== '') {
                $images = array(
                    array(
                        'url' => $fallback,
                        'alt' => '',
                        'sizes' => array(
                            sanitize_key($atts['formato']) => $fallback,
                        ),
                    ),
                );
            }
        }

        if ($images) {
            $tipo = $atts['tipo'];
            $lightbox = filter_var($atts['lightbox'], FILTER_VALIDATE_BOOLEAN);
            $elementi = intval($atts['elementi']);
            $formato = sanitize_key($atts['formato']);

            $unique_id = uniqid('gallery_');
            $carousel_class = 'gallery-carousel-' . $unique_id;
            $grid_class = 'gallery-grid-' . $unique_id;
            $lightbox_group = 'gallery_' . $unique_id;

            $output = '';

            $divi_class_map = array(
                1 => 'et_pb_column_1_1',
                2 => 'et_pb_column_1_2',
                3 => 'et_pb_column_1_3',
                4 => 'et_pb_column_1_4',
                6 => 'et_pb_column_1_6',
            );
            $divi_col_class = isset($divi_class_map[$elementi]) ? $divi_class_map[$elementi] : 'et_pb_column_1_3';

            if ($tipo === 'grid') {
                $output .= '<div class="et_pb_row gallery-grid ' . esc_attr($grid_class) . '">';
                foreach ($images as $image) {
                    $img_url = $image['sizes'][$formato] ?? $image['url'];
                    $img_alt = isset($image['alt']) ? $image['alt'] : '';
                    $output .= '<div class="' . esc_attr($divi_col_class) . '">';
                    if ($lightbox) {
                        $output .= sprintf(
                            '<a href="%s" data-lightbox="%s"><img src="%s" alt="%s"></a>',
                            esc_url($image['url']),
                            esc_attr($lightbox_group),
                            esc_url($img_url),
                            esc_attr($img_alt)
                        );
                    } else {
                        $output .= sprintf(
                            '<img src="%s" alt="%s">',
                            esc_url($img_url),
                            esc_attr($img_alt)
                        );
                    }
                    $output .= '</div>';
                }
                $output .= '</div>';
            } elseif ($tipo === 'carousel') {
                $output .= '<div class="gallery-carousel owl-carousel ' . esc_attr($carousel_class) . '">';
                foreach ($images as $image) {
                    $img_url = $image['sizes'][$formato] ?? $image['url'];
                    $img_alt = isset($image['alt']) ? $image['alt'] : '';
                    $output .= '<div class="carousel-item">';
                    if ($lightbox) {
                        $output .= sprintf(
                            '<a href="%s" data-lightbox="%s"><img src="%s" alt="%s"></a>',
                            esc_url($image['url']),
                            esc_attr($lightbox_group),
                            esc_url($img_url),
                            esc_attr($img_alt)
                        );
                    } else {
                        $output .= sprintf(
                            '<img src="%s" alt="%s">',
                            esc_url($img_url),
                            esc_attr($img_alt)
                        );
                    }
                    $output .= '</div>';
                }
                $output .= '</div>';

                static $owl_loaded = false;
                if (!$owl_loaded) {
                    wp_enqueue_style('owl-carousel', 'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css');
                    wp_enqueue_style('owl-theme', 'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css');
                    wp_enqueue_script('owl-carousel', 'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js', array('jquery'), null, true);
                    $owl_loaded = true;
                }

                wp_add_inline_script('owl-carousel', "
                    jQuery(document).ready(function($){
                        $('.$carousel_class').owlCarousel({
                            loop: " . ($loop ? 'true' : 'false') . ",
                            autoplay: " . ($autoplay ? 'true' : 'false') . ",
                            margin: 10,
                            nav: false,
                            responsive: {
                                0: { items: 1 },
                                600: { items: 2 },
                                1000: { items: $elementi }
                            }
                        });
                    });
                ");
            }

            static $lightbox_loaded = false;
            if ($lightbox && !$lightbox_loaded) {
                wp_enqueue_style('lightbox-css', 'https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/css/lightbox.min.css');
                wp_enqueue_script('lightbox-js', 'https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/js/lightbox.min.js', array('jquery'), null, true);
                $lightbox_loaded = true;
            }

            return $output;
        }

        return '';
    }
}

if (!function_exists('mostra_acf_testo')) {
    function mostra_acf_testo($atts) {
        $atts = shortcode_atts(array(
            'campo' => '',
        ), $atts);

        if (empty($atts['campo'])) {
            return '';
        }

        $valore = get_field($atts['campo'], get_the_ID());

        if (!empty($valore)) {
            $valore = str_replace('\\"', "'", $valore);
            $valore = preg_replace('/\s+src="\//', ' src="/', $valore);
            return $valore;
        }

        return '';
    }
}

add_shortcode('galleria_acf', 'mostra_galleria_acf');
add_shortcode('acf_testo', 'mostra_acf_testo');
