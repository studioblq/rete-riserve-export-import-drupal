<?php
/**
 * Plugin Name: Reti Riserve Setting
 * Description: Configura logo default e logo sticky con selezione media, poi aggiorna il logo al cambio sticky dell'header.
 * Version: 1.0.0
 * Author: Custom
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: reti-riserve-setting
 */

if (!defined('ABSPATH')) {
    exit;
}

class RetiRiserveSetting {
    const PARENT_MENU_SLUG = 'reti-riserve-suite';
    const MENU_SLUG = 'reti-riserve-setting';
    const OPTION_KEY = 'reti_riserve_setting_options';
    const NONCE_SAVE = 'reti_riserve_setting_save';
    const FRONT_HANDLE = 'rrs-setting-front';
    const FRONT_STYLE_HANDLE = 'rrs-setting-front-style';
    const NONCE_BULK_ACF = 'rrs_bulk_acf_mostra_figli';
    const OPTION_BULK_ACF_DONE = 'rrs_bulk_acf_mostra_figli_done';
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

        echo '</table>';

        submit_button(__('Salva', 'reti-riserve-setting'));

        echo '</form>';
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
        );

        update_option(self::OPTION_KEY, $settings);

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
            )
        );
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

function reti_riserve_setting_bootstrap() {
    $plugin = new RetiRiserveSetting();
    $plugin->init();
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
