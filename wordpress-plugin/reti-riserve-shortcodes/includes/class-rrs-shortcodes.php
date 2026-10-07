<?php

if (!defined('ABSPATH')) {
    exit;
}

class RRS_Shortcodes {

    const PARENT_MENU_SLUG = 'reti-riserve-suite';
    const MENU_SLUG        = 'rrs-shortcodes';

    public function init() {
        add_shortcode('categorie_prodotti', array($this, 'shortcode_categorie_prodotti'));
        add_shortcode('griglia_prodotti',    array($this, 'shortcode_griglia_prodotti'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_styles'));
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

    public function admin_enqueue_styles() {
        global $pagenow;
        if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === self::MENU_SLUG) {
            // Carica Font Awesome in admin
            wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', array(), '5.15.4');
        }
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
                .rrs-icon-picker {
                    position: relative;
                    width: 100%;
                    max-width: 240px;
                }
                .rrs-icon-picker__button {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    width: 100%;
                    padding: 6px 10px;
                    border: 1px solid #8c8f94;
                    border-radius: 3px;
                    background: #fff;
                    cursor: pointer;
                    font-size: 13px;
                    user-select: none;
                    transition: border-color 0.2s;
                }
                .rrs-icon-picker__button:hover {
                    border-color: #2271b1;
                }
                .rrs-icon-picker__button i {
                    font-size: 16px;
                    color: #2271b1;
                    min-width: 16px;
                }
                .rrs-icon-picker__button span[class*="et-pb-icon"] {
                    font-family: ETmodules !important;
                    font-size: 18px;
                    color: #2271b1;
                    min-width: 16px;
                }
                .rrs-icon-picker__label {
                    flex: 1;
                    min-width: 0;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
                .rrs-icon-picker__toggle {
                    font-size: 10px;
                    color: #666;
                    transition: transform 0.2s;
                }
                .rrs-icon-picker.open .rrs-icon-picker__toggle {
                    transform: rotate(180deg);
                }
                .rrs-icon-picker__menu {
                    position: absolute;
                    top: calc(100% + 4px);
                    left: 0;
                    right: 0;
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-radius: 4px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
                    display: none;
                    max-height: 240px;
                    overflow-y: auto;
                    z-index: 10000;
                }
                .rrs-icon-picker.open .rrs-icon-picker__menu {
                    display: block;
                }
                .rrs-icon-picker__option {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    padding: 8px 12px;
                    font-size: 13px;
                    cursor: pointer;
                    transition: background 0.2s;
                    border-left: 3px solid transparent;
                }
                .rrs-icon-picker__option:hover {
                    background: #f0f0f1;
                    border-left-color: #2271b1;
                }
                .rrs-icon-picker__option i {
                    font-size: 16px;
                    color: #2271b1;
                    min-width: 16px;
                    text-align: center;
                }
                .rrs-icon-picker__option span[class*="et-pb-icon"] {
                    font-family: ETmodules !important;
                    font-size: 18px;
                    color: #2271b1;
                    min-width: 16px;
                    text-align: center;
                }
                .rrs-icon-picker__option.selected {
                    background: #e8f1f9;
                    border-left-color: #2271b1;
                    font-weight: 600;
                }
                .rrs-shortcode-output { 
                    font-family: monospace; 
                    font-size: 13px; 
                    background: #1d2327; 
                    color: #a6e22e; 
                    padding: 12px; 
                    border-radius: 4px; 
                    word-break: break-all; 
                    min-height: 40px;
                    line-height: 1.5;
                    white-space: pre-wrap;
                    word-wrap: break-word;
                }
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

                        <label class="rrs-check-row">
                            <input type="checkbox" data-param="icone" data-type="bool">
                            <?php esc_html_e('Mostra icone (Font Awesome)', 'reti-riserve-shortcodes'); ?>
                        </label>

                        <label>
                            <?php esc_html_e('Tipo icona', 'reti-riserve-shortcodes'); ?>
                            <div class="rrs-icon-picker" data-param="tipo_icone">
                                <input type="hidden" value="fa-folder">
                                <div class="rrs-icon-picker__button">
                                    <i class="fa fa-folder" aria-hidden="true"></i>
                                    <span class="rrs-icon-picker__label">Cartella</span>
                                    <span class="rrs-icon-picker__toggle">▼</span>
                                </div>
                                <div class="rrs-icon-picker__menu">
                                    <div class="rrs-icon-picker__option" data-value="fa-folder"><i class="fa fa-folder"></i> Cartella</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-tag"><i class="fa fa-tag"></i> Tag</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-bookmark"><i class="fa fa-bookmark"></i> Segnalibro</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-shopping-bag"><i class="fa fa-shopping-bag"></i> Borsa shopping</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-leaf"><i class="fa fa-leaf"></i> Foglia</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-heart"><i class="fa fa-heart"></i> Cuore</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-star"><i class="fa fa-star"></i> Stella</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-gift"><i class="fa fa-gift"></i> Regalo</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-bell"><i class="fa fa-bell"></i> Campanello</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-check"><i class="fa fa-check"></i> Spunta</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-home"><i class="fa fa-home"></i> Casa</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-user"><i class="fa fa-user"></i> Utente</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-cog"><i class="fa fa-cog"></i> Impostazioni</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-search"><i class="fa fa-search"></i> Ricerca</div>
                                    <hr style="margin: 4px 0; border: none; border-top: 1px solid #c3c4c7;">
                                    <div class="rrs-icon-picker__option" data-value="fa-arrow-up"><i class="fa fa-arrow-up"></i> Freccia su</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-arrow-down"><i class="fa fa-arrow-down"></i> Freccia giù</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-arrow-left"><i class="fa fa-arrow-left"></i> Freccia sinistra</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-arrow-right"><i class="fa fa-arrow-right"></i> Freccia destra</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-arrows-alt-v"><i class="fa fa-arrows-alt-v"></i> Frecce su/giù</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-arrows-alt-h"><i class="fa fa-arrows-alt-h"></i> Frecce sx/dx</div>
                                    <div class="rrs-icon-picker__option" data-value="fa-exchange-alt"><i class="fa fa-exchange-alt"></i> Scambio</div>
                                    <hr style="margin: 4px 0; border: none; border-top: 1px solid #c3c4c7;">
                                    <div class="rrs-icon-picker__option" data-value="minus"><span style="font-size: 18px; color: #2271b1;">−</span> Meno</div>
                                    <div class="rrs-icon-picker__option" data-value="dash"><span style="font-size: 18px; color: #2271b1;">–</span> Trattino</div>
                                    <div class="rrs-icon-picker__option" data-value="hyphen"><span style="font-size: 18px; color: #2271b1;">-</span> Trattino corto</div>
                                    <div class="rrs-icon-picker__option" data-value="equals"><span style="font-size: 18px; color: #2271b1;">≈</span> Circa</div>
                                    <div class="rrs-icon-picker__option" data-value="dot"><span style="font-size: 18px; color: #2271b1;">•</span> Punto</div>
                                    <div class="rrs-icon-picker__option" data-value="ellipsis"><span style="font-size: 18px; color: #2271b1;">…</span> Puntini</div>
                                    <div class="rrs-icon-picker__option" data-value="bar"><span style="font-size: 18px; color: #2271b1;">|</span> Barra</div>
                                    <div class="rrs-icon-picker__option" data-value="double-bar"><span style="font-size: 18px; color: #2271b1;">∥</span> Doppia barra</div>
                                </div>
                            </div>
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

            <!-- ============================================================ -->
            <!-- [griglia_prodotti] -->
            <!-- ============================================================ -->
            <div class="rrs-builder" data-shortcode="griglia_prodotti">
                <div class="rrs-builder__head">
                    <h2><code>[griglia_prodotti]</code></h2>
                    <span class="rrs-builder__desc"><?php esc_html_e('Griglia prodotti WooCommerce', 'reti-riserve-shortcodes'); ?></span>
                </div>
                <div class="rrs-builder__body">
                    <div class="rrs-builder__controls">

                        <label>
                            <?php esc_html_e('Ordinamento', 'reti-riserve-shortcodes'); ?>
                            <select data-param="ordinamento">
                                <option value="piu_visti"><?php esc_html_e('Più visti', 'reti-riserve-shortcodes'); ?></option>
                                <option value="recenti"><?php esc_html_e('Più recenti', 'reti-riserve-shortcodes'); ?></option>
                                <option value="prezzo_asc"><?php esc_html_e('Prezzo crescente', 'reti-riserve-shortcodes'); ?></option>
                                <option value="prezzo_desc"><?php esc_html_e('Prezzo decrescente', 'reti-riserve-shortcodes'); ?></option>
                                <option value="nome"><?php esc_html_e('Nome A→Z', 'reti-riserve-shortcodes'); ?></option>
                                <option value="casuali"><?php esc_html_e('Casuali', 'reti-riserve-shortcodes'); ?></option>
                            </select>
                        </label>

                        <label>
                            <?php esc_html_e('Colonne per riga', 'reti-riserve-shortcodes'); ?>
                            <input type="number" data-param="colonne" value="3" min="1" max="6">
                        </label>

                        <label>
                            <?php esc_html_e('Elementi totali', 'reti-riserve-shortcodes'); ?>
                            <input type="number" data-param="elementi" value="12" min="1" max="100">
                        </label>

                        <label class="rrs-check-row">
                            <input type="checkbox" data-param="immagine" data-type="bool" checked>
                            <?php esc_html_e('Mostra immagine prodotto', 'reti-riserve-shortcodes'); ?>
                        </label>

                        <label>
                            <?php esc_html_e('Posizione immagine', 'reti-riserve-shortcodes'); ?>
                            <select data-param="posizione_immagine">
                                <option value="sopra"><?php esc_html_e('Sopra il testo', 'reti-riserve-shortcodes'); ?></option>
                                <option value="sinistra"><?php esc_html_e('A sinistra', 'reti-riserve-shortcodes'); ?></option>
                                <option value="destra"><?php esc_html_e('A destra', 'reti-riserve-shortcodes'); ?></option>
                            </select>
                        </label>

                        <label class="rrs-check-row">
                            <input type="checkbox" data-param="paginazione" data-type="bool">
                            <?php esc_html_e('Abilita paginazione', 'reti-riserve-shortcodes'); ?>
                        </label>

                        <label>
                            <?php esc_html_e('Filtra per categoria (slug)', 'reti-riserve-shortcodes'); ?>
                            <input type="text" data-param="categoria" value="" placeholder="es. abbigliamento">
                        </label>

                        <label>
                            <?php esc_html_e('Classe CSS aggiuntiva', 'reti-riserve-shortcodes'); ?>
                            <input type="text" data-param="classe" value="" placeholder="es. my-grid">
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
                griglia_prodotti: {
                    ordinamento:        'piu_visti',
                    colonne:            '3',
                    elementi:           '12',
                    immagine:           'true',
                    posizione_immagine: 'sopra',
                    paginazione:        'false',
                    categoria:          '',
                    classe:             ''
                },
                categorie_prodotti: {
                    layout:        'vertical',
                    conteggio:     'true',
                    vuote:         'false',
                    icone:         'false',
                    tipo_icone:    'fa-folder',
                    profondita:    '1',
                    genitore:      '0',
                    classe:        ''
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
                    var val  = '';
                    
                    if (el.type === 'checkbox') {
                        val = el.checked ? 'true' : 'false';
                    } else if (el.classList.contains('rrs-icon-picker')) {
                        // Icon picker: leggi dal hidden input
                        val = el.querySelector('input[type=hidden]').value.trim();
                    } else {
                        val = el.value.trim();
                    }
                    
                    params[key] = val;
                });
                return params;
            }

            function updatePreview(builder) {
                var tag    = builder.getAttribute('data-shortcode');
                var params = collectParams(builder);
                var sc     = buildShortcode(tag, params);
                builder.querySelector('.rrs-shortcode-output').textContent = sc;
            }

            // Icon picker functionality
            document.querySelectorAll('.rrs-icon-picker').forEach(function(picker){
                var button  = picker.querySelector('.rrs-icon-picker__button');
                var menu    = picker.querySelector('.rrs-icon-picker__menu');
                var input   = picker.querySelector('input[type=hidden]');
                var options = picker.querySelectorAll('.rrs-icon-picker__option');

                button.addEventListener('click', function(e){
                    e.stopPropagation();
                    picker.classList.toggle('open');
                });

                options.forEach(function(opt){
                    opt.addEventListener('click', function(e){
                        e.stopPropagation();
                        var value = this.getAttribute('data-value');
                        var label = this.textContent.trim();
                        var iconEl = this.querySelector('i');
                        var iconSpan = this.querySelector('span[style*="font-size"]');
                        var iconHTML = '';

                        if (iconEl) {
                            // Font Awesome icon
                            var faClass = iconEl.className.match(/fa-[a-z0-9-]+/);
                            if (faClass) {
                                iconHTML = '<i class="fa ' + faClass[0] + '" aria-hidden="true"></i>';
                            }
                        } else if (iconSpan) {
                            // Special character icon
                            iconHTML = iconSpan.outerHTML;
                        }

                        input.value = value;
                        button.innerHTML = iconHTML
                            + '<span class="rrs-icon-picker__label">' + label + '</span>'
                            + '<span class="rrs-icon-picker__toggle">▼</span>';

                        options.forEach(function(o){ o.classList.remove('selected'); });
                        this.classList.add('selected');

                        picker.classList.remove('open');

                        // Trigger change per aggiornare preview
                        var evt = new Event('change', { bubbles: true });
                        input.dispatchEvent(evt);
                    });
                });

                // Chiudi il menu se clicchi fuori
                document.addEventListener('click', function(e){
                    if (!picker.contains(e.target)) {
                        picker.classList.remove('open');
                    }
                });
            });

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

        <!-- Sezione icone Font Awesome -->
        <div style="margin-top: 40px; padding-top: 24px; border-top: 2px solid #c3c4c7;">
            <h2><?php esc_html_e('Icone Font Awesome disponibili', 'reti-riserve-shortcodes'); ?></h2>
            <p><?php esc_html_e('Clicca su un\'icona per copiare il suo codice da usare nel parametro tipo_icone', 'reti-riserve-shortcodes'); ?></p>
            <style>
                .rrs-icon-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                    gap: 12px;
                    margin-top: 16px;
                }
                .rrs-icon-item {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    padding: 12px;
                    border: 1px solid #e0e0e0;
                    border-radius: 4px;
                    cursor: pointer;
                    background: #fff;
                    transition: all 0.2s;
                    user-select: none;
                }
                .rrs-icon-item:hover {
                    background: #f5f5f5;
                    border-color: #2271b1;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .rrs-icon-item i {
                    font-size: 24px;
                    color: #2271b1;
                }
                .rrs-icon-item-code {
                    font-size: 11px;
                    font-family: monospace;
                    color: #666;
                    text-align: center;
                    word-break: break-word;
                }
                .rrs-icon-item.copied {
                    background: #d4edda;
                    border-color: #00a32a;
                }
                .rrs-icon-item.copied i { color: #00a32a; }
            </style>
            <div class="rrs-icon-grid" id="rrs-icon-grid"></div>
        </div>

        <script>
        (function(){
            var icons = [
                'fa-folder', 'fa-tag', 'fa-bookmark', 'fa-shopping-bag',
                'fa-leaf', 'fa-heart', 'fa-star', 'fa-gift',
                'fa-bell', 'fa-check', 'fa-times', 'fa-plus',
                'fa-minus', 'fa-arrow-right', 'fa-arrow-left', 'fa-arrow-up',
                'fa-arrow-down', 'fa-home', 'fa-user', 'fa-cog',
                'fa-search', 'fa-file', 'fa-image', 'fa-music',
                'fa-video', 'fa-phone', 'fa-envelope', 'fa-map-marker',
                'fa-calendar', 'fa-clock', 'fa-lock', 'fa-unlock',
                'fa-thumbs-up', 'fa-thumbs-down', 'fa-share', 'fa-link',
                'fa-download', 'fa-upload', 'fa-edit', 'fa-trash',
                'fa-comment', 'fa-lightbulb', 'fa-flask', 'fa-rocket',
                'fa-car', 'fa-apple', 'fa-anchor', 'fa-adn'
            ];

            var grid = document.getElementById('rrs-icon-grid');
            if (!grid) return;

            icons.forEach(function(iconCode){
                var item = document.createElement('div');
                item.className = 'rrs-icon-item';
                item.title = 'Clicca per copiare: ' + iconCode;
                item.innerHTML = '<i class="fa ' + iconCode + '\" aria-hidden=\"true\"></i>'
                    + '<span class=\"rrs-icon-item-code\">' + iconCode + '</span>';

                item.addEventListener('click', function(){
                    var text = iconCode;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function(){
                            showCopied(item);
                        });
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                        showCopied(item);
                    }
                });

                grid.appendChild(item);
            });

            function showCopied(item){
                item.classList.add('copied');
                setTimeout(function(){
                    item.classList.remove('copied');
                }, 1500);
            }
        })();
        </script>
        <?php
    }

    public function enqueue_styles() {
        // Carica Font Awesome nel frontend
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', array(), '5.15.4');
        
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
            .rrs-cat-menu__item--has-icon {
                list-style: none;
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
            .rrs-cat-menu__item a i {
                margin-right: 6px;
                opacity: 0.8;
            }
        ');

        wp_add_inline_style('rrs-shortcodes', '
            .rrs-product-grid {
                display: grid;
                grid-template-columns: repeat(var(--rrs-cols, 3), 1fr);
                gap: 20px;
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .rrs-product-grid__item {
                display: flex;
                flex-direction: column;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                overflow: hidden;
                background: #fff;
            }
            .rrs-product-grid__img {
                width: 100%;
                aspect-ratio: 4/3;
                object-fit: cover;
                display: block;
            }
            .rrs-product-grid__img-placeholder {
                width: 100%;
                aspect-ratio: 4/3;
                background: #f0f0f1;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #aaa;
                font-size: 12px;
            }
            .rrs-product-grid__body {
                padding: 12px;
                display: flex;
                flex-direction: column;
                gap: 6px;
                flex: 1;
            }
            .rrs-product-grid__price { margin-top: auto; }
            .rrs-product-grid__pagination { margin-top: 20px; text-align: center; }
            .rrs-product-grid__pagination .page-numbers {
                display: inline-block;
                padding: 4px 10px;
                border: 1px solid #c3c4c7;
                border-radius: 3px;
                margin: 0 2px;
                text-decoration: none;
            }
            .rrs-product-grid__pagination .page-numbers.current {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
            }
            @media (max-width: 600px) { .rrs-product-grid { --rrs-cols: 1 !important; } }
            .rrs-product-grid__img-wrap {
                display: block;
            }
            .rrs-product-grid__item--sinistra,
            .rrs-product-grid__item--destra {
                flex-direction: row;
                align-items: center;
            }
            .rrs-product-grid__item--sinistra .rrs-product-grid__img-wrap,
            .rrs-product-grid__item--destra   .rrs-product-grid__img-wrap {
                width: 40%;
                flex-shrink: 0;
            }
            .rrs-product-grid__item--sinistra .rrs-product-grid__img,
            .rrs-product-grid__item--destra   .rrs-product-grid__img,
            .rrs-product-grid__item--sinistra .rrs-product-grid__img-placeholder,
            .rrs-product-grid__item--destra   .rrs-product-grid__img-placeholder {
                width: 100%;
                aspect-ratio: 1/1;
            }
            .rrs-product-grid__item--sinistra .rrs-product-grid__body,
            .rrs-product-grid__item--destra   .rrs-product-grid__body { flex: 1; }
            @media (max-width: 480px) {
                .rrs-product-grid__item--sinistra,
                .rrs-product-grid__item--destra { flex-direction: column; }
                .rrs-product-grid__item--sinistra .rrs-product-grid__img-wrap,
                .rrs-product-grid__item--destra   .rrs-product-grid__img-wrap { width: 100%; }
            }
        ');
    }

    /**
     * [griglia_prodotti colonne="3" elementi="12" ordinamento="piu_visti" immagine="true" posizione_immagine="sopra" paginazione="false" categoria="" classe=""]
     */
    public function shortcode_griglia_prodotti($atts) {
        if (!class_exists('WooCommerce')) {
            return '<p>' . esc_html__('WooCommerce non è attivo.', 'reti-riserve-shortcodes') . '</p>';
        }

        $atts = shortcode_atts(array(
            'ordinamento'        => 'piu_visti',
            'colonne'            => 3,
            'elementi'           => 12,
            'immagine'           => 'true',
            'posizione_immagine' => 'sopra',
            'paginazione'        => 'false',
            'categoria'          => '',
            'classe'             => '',
        ), $atts, 'griglia_prodotti');

        $show_image  = filter_var($atts['immagine'],    FILTER_VALIDATE_BOOLEAN);
        $pagination  = filter_var($atts['paginazione'], FILTER_VALIDATE_BOOLEAN);
        $colonne     = max(1, min(6, intval($atts['colonne'])));
        $elementi    = max(1, min(100, intval($atts['elementi'])));
        $categoria   = sanitize_text_field($atts['categoria']);
        $extra_class = sanitize_html_class($atts['classe']);
        $posizione   = in_array($atts['posizione_immagine'], array('sopra', 'sinistra', 'destra'), true)
                        ? $atts['posizione_immagine'] : 'sopra';

        $paged = $pagination ? max(1, get_query_var('paged', 1)) : 1;

        $order_map = array(
            'piu_visti'   => array('orderby' => 'meta_value_num', 'meta_key' => 'total_sales', 'order' => 'DESC'),
            'recenti'     => array('orderby' => 'date',                                         'order' => 'DESC'),
            'prezzo_asc'  => array('orderby' => 'meta_value_num', 'meta_key' => '_price',       'order' => 'ASC'),
            'prezzo_desc' => array('orderby' => 'meta_value_num', 'meta_key' => '_price',       'order' => 'DESC'),
            'nome'        => array('orderby' => 'title',                                        'order' => 'ASC'),
            'casuali'     => array('orderby' => 'rand'),
        );
        $ord_key  = isset($order_map[$atts['ordinamento']]) ? $atts['ordinamento'] : 'piu_visti';
        $ord_args = $order_map[$ord_key];

        $query_args = array_merge(
            array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $elementi,
                'paged'          => $paged,
            ),
            $ord_args
        );

        if ($categoria !== '') {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => $categoria,
                ),
            );
        }

        $query = new WP_Query($query_args);

        if (!$query->have_posts()) {
            return '';
        }

        $css_class  = trim('rrs-product-grid ' . $extra_class);
        $item_class = 'rrs-product-grid__item rrs-product-grid__item--' . $posizione;
        $output     = '<ul class="' . esc_attr($css_class) . '" style="--rrs-cols:' . $colonne . '">';

        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            if (!$product) {
                continue;
            }

            // Con immagine a destra: testo prima, poi immagine
            $text_block = '<div class="rrs-product-grid__body">'
                . '<div class="rrs-product-grid__title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></div>'
                . '<div class="rrs-product-grid__price">' . wp_kses_post($product->get_price_html()) . '</div>'
                . '</div>';

            $img_block = '';
            if ($show_image) {
                $thumb = get_the_post_thumbnail(get_the_ID(), 'medium', array(
                    'class' => 'rrs-product-grid__img',
                    'alt'   => esc_attr(get_the_title()),
                ));
                $inner = $thumb
                    ? $thumb
                    : '<div class="rrs-product-grid__img-placeholder">' . esc_html__('Nessuna immagine', 'reti-riserve-shortcodes') . '</div>';
                $img_block = '<div class="rrs-product-grid__img-wrap">' . $inner . '</div>';
            }

            $output .= '<li class="' . esc_attr($item_class) . '">';
            if ($posizione === 'destra') {
                $output .= $text_block . $img_block;
            } else {
                $output .= $img_block . $text_block;
            }
            $output .= '</li>';
        }

        wp_reset_postdata();

        $output .= '</ul>';

        if ($pagination && $query->max_num_pages > 1) {
            $output .= '<div class="rrs-product-grid__pagination">';
            $output .= paginate_links(array(
                'total'   => $query->max_num_pages,
                'current' => $paged,
            ));
            $output .= '</div>';
        }

        return $output;
    }

    /**
     * [categorie_prodotti layout="vertical|horizontal" conteggio="true|false" profondita="1" genitore="0"]
     */
    public function shortcode_categorie_prodotti($atts) {
        if (!class_exists('WooCommerce')) {
            return '<p>' . esc_html__('WooCommerce non è attivo.', 'reti-riserve-shortcodes') . '</p>';
        }

        $atts = shortcode_atts(array(
            'layout'        => 'vertical',   // vertical | horizontal
            'conteggio'     => 'true',        // mostra numero prodotti
            'vuote'         => 'false',       // includi categorie senza prodotti
            'icone'         => 'false',       // mostra icone
            'tipo_icone'    => 'fa-folder',   // classe icona Font Awesome
            'profondita'    => 1,             // livelli di profondità (1 = solo top-level)
            'genitore'      => 0,             // ID categoria genitore (0 = radice)
            'classe'        => '',            // classe CSS aggiuntiva
        ), $atts, 'categorie_prodotti');

        $show_count  = filter_var($atts['conteggio'], FILTER_VALIDATE_BOOLEAN);
        $show_icons  = filter_var($atts['icone'], FILTER_VALIDATE_BOOLEAN);
        $hide_empty  = !filter_var($atts['vuote'], FILTER_VALIDATE_BOOLEAN);
        $depth       = max(1, intval($atts['profondita']));
        $parent_id   = intval($atts['genitore']);
        $layout      = in_array($atts['layout'], array('horizontal', 'vertical'), true) ? $atts['layout'] : 'vertical';
        $icon_class  = sanitize_html_class($atts['tipo_icone']);
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

        $current_term_id = is_product_category() ? get_queried_object_id() : 0;

        return '<ul class="' . esc_attr($css_class) . '">'
            . $this->render_category_items($terms, $show_count, $show_icons, $icon_class, $depth, $hide_empty, $layout, 1, $current_term_id)
            . '</ul>';
    }

    protected function render_category_items($terms, $show_count, $show_icons, $icon_class, $depth, $hide_empty, $layout, $current_depth = 1, $current_term_id = 0) {
        $output = '';

        foreach ($terms as $term) {
            $link  = get_term_link($term, 'product_cat');
            $count = '';
            $icon  = '';

            if ($show_count) {
                $count = ' <span class="rrs-cat-menu__count">(' . intval($term->count) . ')</span>';
            }

            if ($show_icons) {
                $icon = '<i class="fa ' . esc_attr($icon_class) . '" aria-hidden="true"></i> ';
            }

            $is_active = ($current_term_id && $current_term_id === $term->term_id);
            $li_class  = 'rrs-cat-menu__item'
                . ($show_icons  ? ' rrs-cat-menu__item--has-icon' : '')
                . ($is_active   ? ' rrs-cat-menu__item--active'   : '');

            $output .= '<li class="' . esc_attr($li_class) . '">';
            $output .= '<a href="' . esc_url($link) . '">' . $icon . esc_html($term->name) . $count . '</a>';

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
                        . $this->render_category_items($children, $show_count, $show_icons, $icon_class, $depth, $hide_empty, $layout, $current_depth + 1, $current_term_id)
                        . '</ul>';
                }
            }

            $output .= '</li>';
        }

        return $output;
    }
}
