<?php

if (!defined('ABSPATH')) {
    exit;
}

class RRS_Shortcodes {

    const PARENT_MENU_SLUG = 'reti-riserve-suite';
    const MENU_SLUG        = 'rrs-shortcodes';

    public function init() {
        add_shortcode('categorie_prodotti', array($this, 'shortcode_categorie_prodotti'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
    }

    public function register_admin_menu() {
        // Crea il menu padre solo se nessun altro plugin lo ha già creato
        if (!isset($GLOBALS['admin_page_hooks'][self::PARENT_MENU_SLUG])) {
            add_menu_page(
                __('Area051 WP', 'reti-riserve-shortcodes'),
                __('Area051 WP', 'reti-riserve-shortcodes'),
                'manage_options',
                self::PARENT_MENU_SLUG,
                '__return_empty_string',
                'dashicons-admin-tools',
                58
            );
        }

        add_submenu_page(
            self::PARENT_MENU_SLUG,
            __('Shortcodes', 'reti-riserve-shortcodes'),
            __('Shortcodes', 'reti-riserve-shortcodes'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Shortcodes disponibili', 'reti-riserve-shortcodes'); ?></h1>
            <p><?php esc_html_e('Configura i parametri e copia lo shortcode generato.', 'reti-riserve-shortcodes'); ?></p>

            <style>
                .rrs-builder { border: 1px solid #c3c4c7; border-radius: 4px; background: #fff; margin-bottom: 24px; }
                .rrs-builder__head { background: #f0f0f1; padding: 10px 16px; border-bottom: 1px solid #c3c4c7; display: flex; align-items: center; gap: 10px; }
                .rrs-builder__head h2 { margin: 0; font-size: 14px; }
                .rrs-builder__desc { color: #646970; font-size: 13px; }
                .rrs-builder__body { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
                .rrs-builder__controls { padding: 16px; border-right: 1px solid #c3c4c7; }
                .rrs-builder__preview { padding: 16px; background: #f6f7f7; display: flex; flex-direction: column; gap: 10px; }
                .rrs-builder__controls label { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; font-size: 13px; font-weight: 600; }
                .rrs-builder__controls label span { font-weight: 400; color: #646970; font-size: 12px; }
                .rrs-builder__controls .rrs-check-row { flex-direction: row; align-items: center; gap: 8px; font-weight: 400; }
                .rrs-builder__controls select,
                .rrs-builder__controls input[type=number] { width: 100%; max-width: 240px; }
                .rrs-shortcode-output { font-family: monospace; font-size: 13px; background: #1d2327; color: #a6e22e; padding: 12px; border-radius: 4px; word-break: break-all; min-height: 40px; }
                .rrs-copy-btn { align-self: flex-start; }
                .rrs-copy-btn.copied { background: #00a32a; border-color: #00a32a; color: #fff; }
                @media (max-width: 782px) { .rrs-builder__body { grid-template-columns: 1fr; } .rrs-builder__controls { border-right: none; border-bottom: 1px solid #c3c4c7; } }
            </style>

            <!-- ============================================================ -->
            <!-- [categorie_prodotti] -->
            <!-- ============================================================ -->
            <div class="rrs-builder" data-shortcode="categorie_prodotti">
                <div class="rrs-builder__head">
                    <h2><code>[categorie_prodotti]</code></h2>
                    <span class="rrs-builder__desc"><?php esc_html_e('Elenco categorie prodotto WooCommerce', 'reti-riserve-shortcodes'); ?></span>
                </div>
                <div class="rrs-builder__body">
                    <div class="rrs-builder__controls">

                        <label>
                            <?php esc_html_e('Layout', 'reti-riserve-shortcodes'); ?>
                            <select data-param="layout">
                                <option value="vertical"><?php esc_html_e('Verticale', 'reti-riserve-shortcodes'); ?></option>
                                <option value="horizontal"><?php esc_html_e('Orizzontale', 'reti-riserve-shortcodes'); ?></option>
                            </select>
                        </label>

                        <label class="rrs-check-row">
                            <input type="checkbox" data-param="conteggio" data-type="bool" checked>
                            <?php esc_html_e('Mostra numero prodotti per categoria', 'reti-riserve-shortcodes'); ?>
                        </label>

                        <label class="rrs-check-row">
                            <input type="checkbox" data-param="vuote" data-type="bool">
                            <?php esc_html_e('Includi categorie senza prodotti', 'reti-riserve-shortcodes'); ?>
                        </label>

                        <label>
                            <?php esc_html_e('Profondità (livelli)', 'reti-riserve-shortcodes'); ?>
                            <span><?php esc_html_e('1 = solo categorie radice', 'reti-riserve-shortcodes'); ?></span>
                            <input type="number" data-param="profondita" value="1" min="1" max="5">
                        </label>

                        <label>
                            <?php esc_html_e('ID categoria genitore', 'reti-riserve-shortcodes'); ?>
                            <span><?php esc_html_e('0 = tutte le radici', 'reti-riserve-shortcodes'); ?></span>
                            <input type="number" data-param="genitore" value="0" min="0">
                        </label>

                        <label>
                            <?php esc_html_e('Classe CSS aggiuntiva', 'reti-riserve-shortcodes'); ?>
                            <input type="text" data-param="classe" value="" placeholder="es. my-menu">
                        </label>

                    </div>
                    <div class="rrs-builder__preview">
                        <strong style="font-size:13px;"><?php esc_html_e('Shortcode generato:', 'reti-riserve-shortcodes'); ?></strong>
                        <div class="rrs-shortcode-output"></div>
                        <button type="button" class="button rrs-copy-btn"><?php esc_html_e('Copia', 'reti-riserve-shortcodes'); ?></button>
                    </div>
                </div>
            </div>

        </div>

        <script>
        (function(){
            // Defaults: parametri con valori di default che NON vanno emessi nello shortcode
            var DEFAULTS = {
                categorie_prodotti: {
                    layout:     'vertical',
                    conteggio:  'true',
                    vuote:      'false',
                    profondita: '1',
                    genitore:   '0',
                    classe:     ''
                }
            };

            function buildShortcode(tag, params) {
                var defaults = DEFAULTS[tag] || {};
                var attrs = '';
                Object.keys(params).forEach(function(key){
                    var val = params[key];
                    // Ometti il parametro se coincide con il default
                    if (val === defaults[key] || val === '') { return; }
                    attrs += ' ' + key + '="' + val + '"';
                });
                return '[' + tag + attrs + ']';
            }

            function collectParams(builder) {
                var params = {};
                builder.querySelectorAll('[data-param]').forEach(function(el){
                    var key  = el.getAttribute('data-param');
                    var type = el.getAttribute('data-type');
                    if (el.type === 'checkbox') {
                        params[key] = el.checked ? 'true' : 'false';
                    } else {
                        params[key] = el.value.trim();
                    }
                });
                return params;
            }

            function updatePreview(builder) {
                var tag    = builder.getAttribute('data-shortcode');
                var params = collectParams(builder);
                var sc     = buildShortcode(tag, params);
                builder.querySelector('.rrs-shortcode-output').textContent = sc;
            }

            document.querySelectorAll('.rrs-builder').forEach(function(builder){
                // Aggiorna al cambio di qualsiasi controllo
                builder.querySelectorAll('[data-param]').forEach(function(el){
                    el.addEventListener('change', function(){ updatePreview(builder); });
                    el.addEventListener('input',  function(){ updatePreview(builder); });
                });

                // Pulsante copia
                builder.querySelector('.rrs-copy-btn').addEventListener('click', function(){
                    var btn  = this;
                    var text = builder.querySelector('.rrs-shortcode-output').textContent;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text);
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.opacity  = '0';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                    }
                    btn.textContent = '<?php echo esc_js(__('Copiato!', 'reti-riserve-shortcodes')); ?>';
                    btn.classList.add('copied');
                    setTimeout(function(){
                        btn.textContent = '<?php echo esc_js(__('Copia', 'reti-riserve-shortcodes')); ?>';
                        btn.classList.remove('copied');
                    }, 2000);
                });

                // Render iniziale
                updatePreview(builder);
            });
        })();
        </script>
        <?php
    }

    public function enqueue_styles() {
        wp_register_style('rrs-shortcodes', false, array(), '1.0.0');
        wp_enqueue_style('rrs-shortcodes');

        wp_add_inline_style('rrs-shortcodes', '
            .rrs-cat-menu {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .rrs-cat-menu--horizontal {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .rrs-cat-menu--vertical {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .rrs-cat-menu__item a {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                text-decoration: none;
            }
            .rrs-cat-menu--horizontal .rrs-cat-menu__item a {
                padding: 6px 14px;
                border: 1px solid currentColor;
                border-radius: 4px;
            }
            .rrs-cat-menu__count {
                font-size: 0.85em;
                opacity: 0.7;
            }
        ');
    }

    /**
     * [categorie_prodotti layout="vertical|horizontal" conteggio="true|false" profondita="1" genitore="0"]
     */
    public function shortcode_categorie_prodotti($atts) {
        if (!class_exists('WooCommerce')) {
            return '<p>' . esc_html__('WooCommerce non è attivo.', 'reti-riserve-shortcodes') . '</p>';
        }

        $atts = shortcode_atts(array(
            'layout'     => 'vertical',   // vertical | horizontal
            'conteggio'  => 'true',        // mostra numero prodotti
            'profondita' => 1,             // livelli di profondità (1 = solo top-level)
            'genitore'   => 0,             // ID categoria genitore (0 = radice)
            'vuote'      => 'false',       // includi categorie senza prodotti
            'classe'     => '',            // classe CSS aggiuntiva
        ), $atts, 'categorie_prodotti');

        $show_count  = filter_var($atts['conteggio'], FILTER_VALIDATE_BOOLEAN);
        $hide_empty  = !filter_var($atts['vuote'], FILTER_VALIDATE_BOOLEAN);
        $depth       = max(1, intval($atts['profondita']));
        $parent_id   = intval($atts['genitore']);
        $layout      = in_array($atts['layout'], array('horizontal', 'vertical'), true) ? $atts['layout'] : 'vertical';
        $extra_class = sanitize_html_class($atts['classe']);

        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => $hide_empty,
            'parent'     => $parent_id,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));

        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }

        $css_class = trim('rrs-cat-menu rrs-cat-menu--' . $layout . ' ' . $extra_class);

        return '<ul class="' . esc_attr($css_class) . '">'
            . $this->render_category_items($terms, $show_count, $depth, $hide_empty, $layout)
            . '</ul>';
    }

    protected function render_category_items($terms, $show_count, $depth, $hide_empty, $layout, $current_depth = 1) {
        $output = '';

        foreach ($terms as $term) {
            $link  = get_term_link($term, 'product_cat');
            $count = '';

            if ($show_count) {
                $count = ' <span class="rrs-cat-menu__count">(' . intval($term->count) . ')</span>';
            }

            $output .= '<li class="rrs-cat-menu__item">';
            $output .= '<a href="' . esc_url($link) . '">' . esc_html($term->name) . $count . '</a>';

            if ($depth > $current_depth) {
                $children = get_terms(array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => $hide_empty,
                    'parent'     => $term->term_id,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                ));

                if (!is_wp_error($children) && !empty($children)) {
                    $child_class = 'rrs-cat-menu rrs-cat-menu--' . $layout . ' rrs-cat-menu--child';
                    $output .= '<ul class="' . esc_attr($child_class) . '">'
                        . $this->render_category_items($children, $show_count, $depth, $hide_empty, $layout, $current_depth + 1)
                        . '</ul>';
                }
            }

            $output .= '</li>';
        }

        return $output;
    }
}
