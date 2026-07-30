<?php
/**
 * Plugin Name: Rete di Riserve Owl Carousel
 * Description: Gestione template item e shortcode per creare carousel Owl con campi WordPress/ACF.
 * Version: 1.0.0
 * Author: Custom
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: rete-riserve-owl-carousel
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReteRiserveOwlCarousel {
    const PARENT_MENU_SLUG = 'reti-riserve-suite';
    const CPT_TEMPLATE = 'rr_carousel_tpl';
    const TEMPLATE_META_KEY = '_rr_item_template_html';
    const TEMPLATE_SLUG_META_KEY = '_rr_template_slug';
    const TEMPLATE_NONCE = 'rr_item_template_nonce';
    const STYLE_HANDLE = 'rr-owl-carousel';
    const THEME_STYLE_HANDLE = 'rr-owl-carousel-theme';
    const SCRIPT_HANDLE = 'rr-owl-carousel';
    const INIT_HANDLE = 'rr-owl-carousel-init';

    protected $assets_enqueued = false;

    public function init() {
        add_action('admin_menu', array($this, 'register_parent_menu'), 5);
        add_action('init', array($this, 'register_template_post_type'));

        add_action('add_meta_boxes', array($this, 'register_template_metabox'));
        add_action('save_post_' . self::CPT_TEMPLATE, array($this, 'save_template_metabox'));

        add_shortcode('rr_owl_carousel', array($this, 'render_shortcode'));
        add_shortcode('rr_post_grid', array($this, 'render_grid_shortcode'));
        add_shortcode('rr_owl_grid', array($this, 'render_grid_shortcode'));
    }

    public function render_grid_shortcode($atts) {
        if (!is_array($atts)) {
            $atts = array();
        }

        $atts['layout'] = 'grid';
        if (!isset($atts['pagination'])) {
            $atts['pagination'] = '1';
        }

        return $this->render_shortcode($atts);
    }

    public function register_parent_menu() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($GLOBALS['admin_page_hooks'][self::PARENT_MENU_SLUG])) {
            add_menu_page(
                __('Area051 WP', 'rete-riserve-owl-carousel'),
                __('Area051 WP', 'rete-riserve-owl-carousel'),
                'manage_options',
                self::PARENT_MENU_SLUG,
                array($this, 'render_hub_page'),
                'dashicons-admin-tools',
                58
            );
        }
    }

    public function render_hub_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Area051 WP', 'rete-riserve-owl-carousel') . '</h1>';
        echo '<p>' . esc_html__('Suite strumenti utili per il progetto Rete di Riserve.', 'rete-riserve-owl-carousel') . '</p>';

        echo '<hr>';
        echo '<h2>' . esc_html__('Guida rapida shortcode', 'rete-riserve-owl-carousel') . '</h2>';
        echo '<p>' . esc_html__('Usa sempre un template creato in "Template Carousel" e richiamalo con template="slug-template".', 'rete-riserve-owl-carousel') . '</p>';

        echo '<h3 style="margin-top:20px;">' . esc_html__('Carousel', 'rete-riserve-owl-carousel') . '</h3>';
        echo '<p><code>[rr_owl_carousel template="prova-percorsi" post_type="post" items_desktop="3" items_tablet="2" items_mobile="1"]</code></p>';

        echo '<h3 style="margin-top:20px;">' . esc_html__('Griglia paginata', 'rete-riserve-owl-carousel') . '</h3>';
        echo '<p><code>[rr_post_grid template="prova-percorsi" post_type="post" posts_per_page="9" columns_desktop="3" columns_tablet="2" columns_mobile="1" pagination="1"]</code></p>';

        echo '<h3 style="margin-top:20px;">' . esc_html__('Filtri utili', 'rete-riserve-owl-carousel') . '</h3>';
        echo '<ul style="list-style:disc;padding-left:20px;">';
        echo '<li><code>category="escursioni"</code> ' . esc_html__('filtra per categoria.', 'rete-riserve-owl-carousel') . '</li>';
        echo '<li><code>tag="nome-tag"</code> ' . esc_html__('filtra per tag.', 'rete-riserve-owl-carousel') . '</li>';
        echo '<li><code>date_field="data_evento" date_from="now"</code> ' . esc_html__('mostra solo eventi da oggi in poi.', 'rete-riserve-owl-carousel') . '</li>';
        echo '<li><code>filter="campo>=valore"</code> ' . esc_html__('filtro avanzato su meta/ACF (se il modulo testo altera i simboli, preferisci date_field/date_from/date_to).', 'rete-riserve-owl-carousel') . '</li>';
        echo '</ul>';

        echo '<h3 style="margin-top:20px;">' . esc_html__('Ordinamento', 'rete-riserve-owl-carousel') . '</h3>';
        echo '<p><code>orderby="date" order="DESC"</code> ' . esc_html__('oppure', 'rete-riserve-owl-carousel') . ' <code>orderby="meta_value_num" meta_key="data_evento" order="ASC"</code></p>';

        echo '<h3 style="margin-top:20px;">' . esc_html__('Esempio completo (griglia eventi futuri)', 'rete-riserve-owl-carousel') . '</h3>';
        echo '<p><code>[rr_post_grid template="prova-percorsi" post_type="post" category="escursioni" date_field="data_evento" date_from="now" orderby="meta_value_num" meta_key="data_evento" order="ASC" posts_per_page="6" columns_desktop="3" columns_tablet="2" columns_mobile="1" pagination="1"]</code></p>';
        echo '</div>';
    }

    public function register_template_post_type() {
        $labels = array(
            'name' => __('Template Carousel', 'rete-riserve-owl-carousel'),
            'singular_name' => __('Template Carousel', 'rete-riserve-owl-carousel'),
            'menu_name' => __('Template Carousel', 'rete-riserve-owl-carousel'),
            'add_new' => __('Aggiungi Nuovo', 'rete-riserve-owl-carousel'),
            'add_new_item' => __('Aggiungi Nuovo Template', 'rete-riserve-owl-carousel'),
            'edit_item' => __('Modifica Template', 'rete-riserve-owl-carousel'),
            'new_item' => __('Nuovo Template', 'rete-riserve-owl-carousel'),
            'view_item' => __('Visualizza Template', 'rete-riserve-owl-carousel'),
            'search_items' => __('Cerca Template', 'rete-riserve-owl-carousel'),
            'not_found' => __('Nessun template trovato', 'rete-riserve-owl-carousel'),
            'not_found_in_trash' => __('Nessun template nel cestino', 'rete-riserve-owl-carousel'),
        );

        register_post_type(self::CPT_TEMPLATE, array(
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => self::PARENT_MENU_SLUG,
            'menu_position' => 30,
            'supports' => array('title'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'rewrite' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
        ));
    }

    public function register_template_metabox() {
        add_meta_box(
            'rr_item_template_html',
            __('Template HTML Item', 'rete-riserve-owl-carousel'),
            array($this, 'render_template_metabox'),
            self::CPT_TEMPLATE,
            'normal',
            'high'
        );
    }

    public function render_template_metabox($post) {
        $template_html = get_post_meta($post->ID, self::TEMPLATE_META_KEY, true);
        $template_slug = (string) get_post_meta($post->ID, self::TEMPLATE_SLUG_META_KEY, true);
        if ($template_slug === '') {
            $template_slug = (string) $post->post_name;
        }

        wp_nonce_field(self::TEMPLATE_NONCE, self::TEMPLATE_NONCE);

        echo '<p><strong>' . esc_html__('Slug template esplicito', 'rete-riserve-owl-carousel') . '</strong></p>';
        echo '<input type="text" name="rr_template_slug" value="' . esc_attr($template_slug) . '" class="regular-text" style="max-width:420px;">';
        echo '<p class="description">' . esc_html__('Usa questo valore nello shortcode: [rr_owl_carousel template="slug-template" ...]. Se duplicato, il plugin lo rende univoco automaticamente.', 'rete-riserve-owl-carousel') . '</p>';

        echo '<p>' . esc_html__('Inserisci HTML del singolo item. Usa i placeholder nel formato [campo]. Esempio: [title], [permalink], [thumbnail], [field_prova_123].', 'rete-riserve-owl-carousel') . '</p>';
        echo '<textarea name="rr_item_template_html" rows="14" style="width:100%;font-family:monospace;">' . esc_textarea((string) $template_html) . '</textarea>';
        echo '<p class="description">' . esc_html__('I placeholder personalizzati leggono prima ACF (se presente), poi i meta standard di WordPress.', 'rete-riserve-owl-carousel') . '</p>';

        echo '<hr>';
        echo '<p><strong>' . esc_html__('Placeholder supportati', 'rete-riserve-owl-carousel') . '</strong></p>';
        echo '<code>[title]</code> <code>[excerpt]</code> <code>[content]</code> <code>[permalink]</code> <code>[thumbnail]</code> <code>[id]</code> <code>[post_date]</code> <code>[post_type]</code>';

        $acf_placeholders = $this->get_detected_acf_placeholders();
        if (!empty($acf_placeholders)) {
            echo '<p style="margin-top:12px;"><strong>' . esc_html__('Campi ACF rilevati (placeholder utilizzabili)', 'rete-riserve-owl-carousel') . '</strong></p>';
            echo '<div style="line-height:2;">';
            foreach ($acf_placeholders as $acf_field_name) {
                echo '<code>[' . esc_html($acf_field_name) . ']</code> ';
            }
            echo '</div>';
        } else {
            echo '<p class="description" style="margin-top:10px;">' . esc_html__('Nessun campo ACF rilevato automaticamente. Se ACF e attivo, salva/ricarica la pagina dopo aver creato i field group.', 'rete-riserve-owl-carousel') . '</p>';
        }

        echo '<hr>';
        echo '<p><strong>' . esc_html__('Indicazioni d\'uso', 'rete-riserve-owl-carousel') . '</strong></p>';
        echo '<ol style="margin-left:18px;">';
        echo '<li>' . esc_html__('Crea un template item e pubblicalo, poi usa lo slug o l\'ID nel parametro template dello shortcode.', 'rete-riserve-owl-carousel') . '</li>';
        echo '<li>' . esc_html__('Per filtrare usa filter/filters con campi ACF/meta o tassonomie. Esempi: filters="field_prova_123:XYZ;category:news" oppure filter="[data_evento>=now];[prezzo<50]".', 'rete-riserve-owl-carousel') . '</li>';
        echo '</ol>';
        echo '<p><strong>' . esc_html__('Esempio shortcode', 'rete-riserve-owl-carousel') . '</strong><br><code>[rr_owl_carousel template="mio-template" post_type="post" items_desktop="3" items_tablet="2" items_mobile="1"]</code><br><code>[rr_post_grid template="mio-template" post_type="post" posts_per_page="9" columns_desktop="3" columns_tablet="2" columns_mobile="1" pagination="1"]</code></p>';

    }

    public function save_template_metabox($post_id) {
        if (!isset($_POST[self::TEMPLATE_NONCE])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::TEMPLATE_NONCE])), self::TEMPLATE_NONCE)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $raw_slug = isset($_POST['rr_template_slug']) ? wp_unslash($_POST['rr_template_slug']) : '';
        $template_slug = sanitize_title((string) $raw_slug);
        if ($template_slug === '') {
            $template_slug = sanitize_title((string) get_the_title($post_id));
        }
        if ($template_slug === '') {
            $template_slug = 'template-' . (string) absint($post_id);
        }
        $template_slug = $this->ensure_unique_template_slug($template_slug, $post_id);
        update_post_meta($post_id, self::TEMPLATE_SLUG_META_KEY, $template_slug);

        $template_html = isset($_POST['rr_item_template_html']) ? wp_unslash($_POST['rr_item_template_html']) : '';
        $template_html = wp_kses_post($template_html);

        update_post_meta($post_id, self::TEMPLATE_META_KEY, $template_html);
    }

    protected function enqueue_assets() {
        if ($this->assets_enqueued) {
            return;
        }

        // Self-contained carousel: no external CDN, no jQuery, no Owl.
        // This avoids width-calculation bugs and conflicts with Divi's own scripts.
        wp_register_style(self::STYLE_HANDLE, false, array(), '2.0.0');
        wp_register_script(self::INIT_HANDLE, false, array(), '2.0.0', true);

        wp_enqueue_style(self::STYLE_HANDLE);
        wp_enqueue_script(self::INIT_HANDLE);

        $init_js = <<<'JS'
(function(){
    'use strict';

    function toInt(value, fallback) {
        var n = parseInt(value, 10);
        return isNaN(n) ? fallback : n;
    }

    function getSlides(track) {
        return Array.prototype.filter.call(track.children, function (node) {
            return node.classList && node.classList.contains('rr-owl-item');
        });
    }

    function build(root) {
        if (root.__rrInit) {
            return;
        }
        root.__rrInit = true;

        var viewport = root.querySelector('.rr-carousel__viewport');
        var track = root.querySelector('.rr-carousel__track');
        if (!viewport || !track) {
            return;
        }

        var slides = getSlides(track);
        if (!slides.length) {
            return;
        }

        var prevBtn = root.querySelector('[data-rr-prev]');
        var nextBtn = root.querySelector('[data-rr-next]');
        var dotsWrap = root.querySelector('[data-rr-dots]');

        var margin = toInt(root.getAttribute('data-margin'), 16);
        var loop = root.getAttribute('data-loop') === '1';
        var dItems = Math.max(1, toInt(root.getAttribute('data-items-desktop'), 3));
        var tItems = Math.max(1, toInt(root.getAttribute('data-items-tablet'), 2));
        var mItems = Math.max(1, toInt(root.getAttribute('data-items-mobile'), 1));

        var page = 0;
        var perView = dItems;
        var slideWidth = 0;

        function computePerView() {
            var w = window.innerWidth || document.documentElement.clientWidth;
            if (w < 768) { return mItems; }
            if (w < 1024) { return tItems; }
            return dItems;
        }

        function pageCount() {
            return Math.max(1, Math.ceil(slides.length / perView));
        }

        function applySizes() {
            perView = Math.min(computePerView(), slides.length);
            if (perView < 1) { perView = 1; }

            track.style.display = 'flex';
            track.style.flexWrap = 'nowrap';
            track.style.gap = margin + 'px';

            var candidates = [];
            var viewportRect = viewport.getBoundingClientRect();
            var rootRect = root.getBoundingClientRect();
            var parentRect = root.parentElement ? root.parentElement.getBoundingClientRect() : null;

            if (viewportRect && viewportRect.width > 0) { candidates.push(viewportRect.width); }
            if (rootRect && rootRect.width > 0) { candidates.push(rootRect.width); }
            if (parentRect && parentRect.width > 0) { candidates.push(parentRect.width); }

            var winW = window.innerWidth || document.documentElement.clientWidth || 0;
            if (winW > 0) { candidates.push(winW); }

            var viewWidth = 0;
            for (var ci = 0; ci < candidates.length; ci++) {
                var cw = candidates[ci];
                if (!viewWidth || cw < viewWidth) {
                    viewWidth = cw;
                }
            }

            if (!viewWidth || viewWidth <= 0) {
                viewWidth = 1;
            }

            // Safety clamp against broken layout metrics (observed in Divi Text).
            if (winW > 0) {
                viewWidth = Math.min(viewWidth, winW);
            }

            slideWidth = Math.max(1, (viewWidth - (margin * (perView - 1))) / perView);
            var basis = slideWidth + 'px';
            slides.forEach(function (s) {
                s.style.flex = '0 0 ' + basis;
                s.style.width = basis;
                s.style.maxWidth = basis;
                s.style.scrollSnapAlign = 'start';
            });

            if (page > pageCount() - 1) { page = pageCount() - 1; }
            if (page < 0) { page = 0; }
        }

        function pageOffset(p) {
            var step = (slideWidth + margin) * perView;
            return Math.max(0, p * step);
        }

        function buildDots() {
            if (!dotsWrap) { return; }
            var pc = pageCount();
            dotsWrap.innerHTML = '';
            if (pc <= 1) {
                dotsWrap.style.display = 'none';
                return;
            }
            dotsWrap.style.display = '';
            for (var i = 0; i < pc; i++) {
                (function (idx) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'rr-carousel__dot';
                    b.setAttribute('aria-label', 'Vai alla pagina ' + (idx + 1));
                    b.addEventListener('click', function () { go(idx); });
                    dotsWrap.appendChild(b);
                })(i);
            }
        }

        function render() {
            var pc = pageCount();
            if (prevBtn) { prevBtn.disabled = (!loop && page <= 0); }
            if (nextBtn) { nextBtn.disabled = (!loop && page >= pc - 1); }

            if (dotsWrap) {
                var dots = dotsWrap.children;
                for (var i = 0; i < dots.length; i++) {
                    if (i === page) { dots[i].classList.add('is-active'); }
                    else { dots[i].classList.remove('is-active'); }
                }
            }
        }

        function scrollToPage(targetPage, smooth) {
            var pc = pageCount();
            if (loop) {
                if (targetPage < 0) { targetPage = pc - 1; }
                else if (targetPage > pc - 1) { targetPage = 0; }
            } else {
                targetPage = Math.max(0, Math.min(targetPage, pc - 1));
            }

            page = targetPage;

            viewport.scrollTo({
                left: pageOffset(page),
                behavior: smooth ? 'smooth' : 'auto'
            });

            render();
        }

        function go(p) {
            scrollToPage(p, true);
        }

        function syncPageFromScroll() {
            var step = (slideWidth + margin) * perView;
            if (!step || step <= 0) {
                return;
            }

            var nextPage = Math.round(viewport.scrollLeft / step);
            var maxPage = pageCount() - 1;
            if (nextPage < 0) { nextPage = 0; }
            if (nextPage > maxPage) { nextPage = maxPage; }

            if (nextPage !== page) {
                page = nextPage;
                render();
            }
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                go(page - 1);
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                go(page + 1);
            });
        }

        var resizeTimer = null;
        function onResize() {
            if (resizeTimer) { clearTimeout(resizeTimer); }
            resizeTimer = setTimeout(function () {
                applySizes();
                buildDots();
                scrollToPage(page, false);
            }, 150);
        }
        window.addEventListener('resize', onResize);
        window.addEventListener('orientationchange', onResize);
        viewport.addEventListener('scroll', syncPageFromScroll, { passive: true });

        var startX = null, curX = null;
        viewport.addEventListener('touchstart', function (e) {
            startX = e.touches[0].clientX;
            curX = startX;
        }, { passive: true });
        viewport.addEventListener('touchmove', function (e) {
            curX = e.touches[0].clientX;
        }, { passive: true });
        viewport.addEventListener('touchend', function () {
            if (startX === null || curX === null) { return; }
            var dx = curX - startX;
            if (Math.abs(dx) > 40) { go(page + (dx < 0 ? 1 : -1)); }
            startX = null;
            curX = null;
        });

        applySizes();
        buildDots();
        scrollToPage(0, false);

        // Recalculate once images have loaded (their size can shift layout).
        window.addEventListener('load', function () {
            applySizes();
            scrollToPage(page, false);
        });
    }

    function init() {
        var list = document.querySelectorAll('.rr-carousel');
        for (var i = 0; i < list.length; i++) {
            build(list[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;

        wp_add_inline_script(self::INIT_HANDLE, $init_js);

        $inline_css = <<<'CSS'
.rr-carousel {
    position: relative;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.rr-carousel *,
.rr-carousel *::before,
.rr-carousel *::after {
    box-sizing: border-box;
}
.rr-carousel__stage {
    position: relative;
    min-width: 0;
}
.rr-carousel__viewport {
    overflow: hidden;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.rr-carousel__viewport::-webkit-scrollbar {
    display: none;
}
.rr-carousel__track {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    will-change: transform;
    min-width: 0;
}
.rr-carousel__track > .rr-owl-item {
    flex: 0 0 auto;
    min-width: 0;
    max-width: 100%;
    height: auto;
    display: block;
}
.rr-carousel img {
    max-width: 100%;
    height: auto;
    display: block;
}
.rr-carousel .rr-card {
    height: 100%;
}
.rr-carousel__btn {
    cursor: pointer;
    border: 0;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    font-size: 22px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    position: relative;
    z-index: 20;
    pointer-events: auto;
}
.rr-carousel__btn:disabled {
    opacity: 0.35;
    cursor: default;
}
.rr-carousel__btn--prev.is-inside,
.rr-carousel__btn--next.is-inside {
    position: absolute;
    top: 30%;
    transform: translateY(-50%);
    z-index: 30;
}
.rr-carousel__btn--prev.is-inside {
    left: -30px;
}
.rr-carousel__btn--next.is-inside {
    right: -30px;
}
.rr-carousel__dots {
    display: flex;
    gap: 8px;
    justify-content: center;
    align-items: center;
}
.rr-carousel__dots.is-inside {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 10px;
    z-index: 30;
    pointer-events: auto;
}
.rr-carousel__dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 0;
    background: rgba(0, 0, 0, 0.3);
    cursor: pointer;
    padding: 0;
}
.rr-carousel__dot.is-active {
    background: rgba(0, 0, 0, 0.8);
}
.rr-carousel__controls-below {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin-top: 14px;
    flex-wrap: wrap;
}

.rr-post-grid {
    width: 100%;
    max-width: 100%;
}
.rr-post-grid__items {
    display: grid;
    grid-template-columns: repeat(var(--rr-grid-cols-desktop, 3), minmax(0, 1fr));
    gap: var(--rr-grid-gap, 16px);
}
.rr-post-grid__item {
    min-width: 0;
}
.rr-post-grid img {
    max-width: 100%;
    height: auto;
    display: block;
}
.rr-post-grid__pagination {
    margin-top: 16px;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: center;
}
.rr-post-grid__pagination .page-numbers {
    display: inline-block;
    min-width: 34px;
    padding: 6px 10px;
    text-align: center;
    text-decoration: none;
    border: 1px solid rgba(0, 0, 0, 0.2);
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.7);
}
.rr-post-grid__pagination .page-numbers.current {
    font-weight: 700;
    border-color: rgba(0, 0, 0, 0.45);
}
@media (max-width: 1023px) {
    .rr-post-grid__items {
        grid-template-columns: repeat(var(--rr-grid-cols-tablet, 2), minmax(0, 1fr));
    }
}
@media (max-width: 767px) {
    .rr-post-grid__items {
        grid-template-columns: repeat(var(--rr-grid-cols-mobile, 1), minmax(0, 1fr));
    }
}

/* Divi Text module often lives in CSS Grid columns: force shrinkability. */
.et_pb_column,
.et_pb_module,
.et_pb_text,
.et_pb_text_inner,
.et_pb_text_inner > .rr-carousel,
.et_pb_text_inner .rr-carousel__stage,
.et_pb_text_inner .rr-carousel__viewport,
.et_pb_text_inner .rr-carousel__track,
.et_pb_text_inner .rr-carousel__track > .rr-owl-item {
    min-width: 0 !important;
    max-width: 100% !important;
}

.et_pb_text_inner > .rr-carousel {
    width: 100% !important;
    justify-self: stretch;
    align-self: stretch;
}
CSS;

        wp_add_inline_style(self::STYLE_HANDLE, $inline_css);

        $this->assets_enqueued = true;
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'template' => '',
            'post_type' => 'post',
            'posts_per_page' => '10',
            'orderby' => 'date',
            'order' => 'DESC',
            'category' => '',
            'tag' => '',
            'meta_key' => '',
            'meta_value' => '',
            'meta_compare' => '=',
            'filter' => '',
            'filters' => '',
            'date_field' => '',
            'date_from' => '',
            'date_to' => '',
            'layout' => 'carousel',
            'pagination' => '0',
            'page_var' => 'rr_page',
            'columns_desktop' => '3',
            'columns_tablet' => '2',
            'columns_mobile' => '1',
            'items_desktop' => '3',
            'items_tablet' => '2',
            'items_mobile' => '1',
            'loop' => '0',
            'nav' => '1',
            'dots' => '1',
            'nav_position' => 'inside',
            'dots_position' => 'inside',
            'margin' => '16',
            'class' => '',
            'debug' => '0',
        ), $atts, 'rr_owl_carousel');

        $template_post = $this->find_template_post($atts['template']);
        if (!$template_post) {
            return '<!-- rr_owl_carousel: template non trovato -->';
        }

        $template_html = (string) get_post_meta($template_post->ID, self::TEMPLATE_META_KEY, true);
        if ($template_html === '') {
            $template_html = '<div class="item"><h3>[title]</h3></div>';
        }

        $allowed_orderby = array('none', 'ID', 'author', 'title', 'name', 'date', 'modified', 'parent', 'rand', 'comment_count', 'menu_order', 'meta_value', 'meta_value_num');
        $orderby = in_array($atts['orderby'], $allowed_orderby, true) ? $atts['orderby'] : 'date';
        $order = strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC';

        $post_type = sanitize_key($atts['post_type']);
        if (post_type_exists($post_type) === false) {
            $post_type = 'post';
        }

        $posts_per_page = (int) $atts['posts_per_page'];
        if ($posts_per_page === 0) {
            $posts_per_page = 10;
        }

        $layout = strtolower(trim((string) $atts['layout']));
        $is_grid_layout = ($layout === 'grid');

        $query_args = array(
            'post_type' => $post_type,
            'posts_per_page' => $posts_per_page,
            'post_status' => 'publish',
            'orderby' => $orderby,
            'order' => $order,
            'ignore_sticky_posts' => true,
        );

        $meta_query = array();
        $tax_query = array();

        if ($atts['category'] !== '') {
            $category_terms = $this->resolve_tax_terms('category', $atts['category']);
            if (!empty($category_terms)) {
                $tax_query[] = array(
                    'taxonomy' => 'category',
                    'field' => 'slug',
                    'terms' => $category_terms,
                );
            }
        }

        if ($atts['tag'] !== '') {
            $tag_terms = $this->resolve_tax_terms('post_tag', $atts['tag']);
            if (!empty($tag_terms)) {
                $tax_query[] = array(
                    'taxonomy' => 'post_tag',
                    'field' => 'slug',
                    'terms' => $tag_terms,
                );
            }
        }

        if ($atts['meta_key'] !== '') {
            $meta_key = sanitize_key($atts['meta_key']);
            $meta_value = (string) $atts['meta_value'];
            $meta_compare = $this->sanitize_compare($atts['meta_compare']);

            // meta_key is often used only for orderby=meta_value(_num).
            // In that case do not add an implicit "= ''" filter.
            if ($meta_value !== '' || in_array($meta_compare, array('EXISTS', 'NOT EXISTS'), true)) {
                $meta_clause = array(
                    'key' => $meta_key,
                    'compare' => $meta_compare,
                );

                if ($meta_compare !== 'EXISTS' && $meta_compare !== 'NOT EXISTS') {
                    $meta_clause['value'] = sanitize_text_field($meta_value);
                }

                $meta_query[] = $meta_clause;
            }
        }

        $filters_raw = trim((string) $atts['filters']);
        $filter_alias = trim((string) $atts['filter']);
        if ($filters_raw === '') {
            $filters_raw = $filter_alias;
        } elseif ($filter_alias !== '') {
            $filters_raw .= ';' . $filter_alias;
        }

        $filters = $this->parse_filters($filters_raw);
        foreach ($filters['meta'] as $filter) {
            $meta_query[] = $filter;
        }

        foreach ($filters['tax'] as $filter) {
            $tax_query[] = $filter;
        }

        $runtime_filters = isset($filters['runtime']) && is_array($filters['runtime']) ? $filters['runtime'] : array();

        // Dedicated, operator-free date filtering (robust inside Divi Text module).
        $date_field = sanitize_key((string) $atts['date_field']);
        if ($date_field !== '') {
            $date_from = trim((string) $atts['date_from']);
            $date_to = trim((string) $atts['date_to']);

            if ($date_from !== '') {
                $runtime_filters[] = array(
                    'key' => $date_field,
                    'compare' => '>=',
                    'value' => $date_from,
                    'mode' => 'date_compare',
                );
            }

            if ($date_to !== '') {
                $runtime_filters[] = array(
                    'key' => $date_field,
                    'compare' => '<=',
                    'value' => $date_to,
                    'mode' => 'date_compare',
                );
            }
        }

        if ($is_grid_layout) {
            // Grid pagination is handled after runtime/date filters to keep totals accurate.
            $query_args['posts_per_page'] = -1;
        }

        if (!empty($meta_query)) {
            if (count($meta_query) > 1) {
                $meta_query['relation'] = 'AND';
            }
            $query_args['meta_query'] = $meta_query;
        }

        if (!empty($tax_query)) {
            if (count($tax_query) > 1) {
                $tax_query['relation'] = 'AND';
            }
            $query_args['tax_query'] = $tax_query;
        }

        if ($orderby === 'meta_value' || $orderby === 'meta_value_num') {
            if (!empty($atts['meta_key'])) {
                $query_args['meta_key'] = sanitize_key($atts['meta_key']);
            } elseif (!empty($meta_query[0]['key'])) {
                $query_args['meta_key'] = $meta_query[0]['key'];
            }
        }

        $query = new WP_Query($query_args);
        $debug_enabled = ((string) $atts['debug'] === '1');
        $debug_payload = array();

        $query_posts = is_array($query->posts) ? $query->posts : array();
        if ($debug_enabled) {
            $debug_payload['query_args'] = $query_args;
            $debug_payload['count_before_runtime'] = count($query_posts);
            $debug_payload['runtime_filters'] = $runtime_filters;

            $debug_meta_key = isset($query_args['meta_key']) ? sanitize_key((string) $query_args['meta_key']) : '';
            if ($debug_meta_key !== '' && !empty($query_posts)) {
                $meta_samples = array();
                $meta_sample_count = min(12, count($query_posts));
                for ($mi = 0; $mi < $meta_sample_count; $mi++) {
                    $meta_post = $query_posts[$mi];
                    if (!($meta_post instanceof WP_Post)) {
                        continue;
                    }

                    $meta_samples[] = array(
                        'post_id' => (int) $meta_post->ID,
                        'meta_key' => $debug_meta_key,
                        'meta_value' => get_post_meta((int) $meta_post->ID, $debug_meta_key, true),
                    );
                }
                $debug_payload['meta_samples'] = $meta_samples;
            }
        }

        if (!empty($runtime_filters) && !empty($query_posts)) {
            if ($debug_enabled) {
                $samples = array();
                $sample_count = min(8, count($query_posts));
                for ($i = 0; $i < $sample_count; $i++) {
                    $post_obj = $query_posts[$i];
                    if (!($post_obj instanceof WP_Post)) {
                        continue;
                    }

                    $sample = array(
                        'post_id' => (int) $post_obj->ID,
                    );

                    foreach ($runtime_filters as $rf) {
                        if (!isset($rf['key'])) {
                            continue;
                        }
                        $rf_key = sanitize_key((string) $rf['key']);
                        if ($rf_key === '') {
                            continue;
                        }
                        $sample[$rf_key] = get_post_meta((int) $post_obj->ID, $rf_key, true);
                    }

                    $samples[] = $sample;
                }
                $debug_payload['runtime_samples'] = $samples;
            }

            $query_posts = $this->apply_runtime_filters($query_posts, $runtime_filters);
        }

        if ($debug_enabled) {
            $debug_payload['count_after_runtime'] = count($query_posts);
        }

        if (empty($query_posts)) {
            if ($debug_enabled) {
                return '<!-- rr_owl_carousel debug: ' . esc_html((string) wp_json_encode($debug_payload)) . ' -->';
            }
            return '<!-- rr_owl_carousel: nessun risultato -->';
        }

        if ($is_grid_layout) {
            $this->enqueue_assets();

            $pagination_enabled = ((string) $atts['pagination'] === '1');
            $page_var = sanitize_key((string) $atts['page_var']);
            if ($page_var === '') {
                $page_var = 'rr_page';
            }

            $current_page = 1;
            if ($pagination_enabled && isset($_GET[$page_var])) {
                $current_page = max(1, absint($_GET[$page_var]));
            }

            $total_items = count($query_posts);
            $total_pages = max(1, (int) ceil($total_items / max(1, $posts_per_page)));

            if ($pagination_enabled) {
                $offset = ($current_page - 1) * $posts_per_page;
                $query_posts = array_slice($query_posts, $offset, $posts_per_page);
            } else {
                $query_posts = array_slice($query_posts, 0, $posts_per_page);
            }

            $grid_uid = 'rr-grid-' . wp_rand(1000, 99999);
            $custom_classes = $this->sanitize_classes((string) $atts['class']);
            $grid_classes = trim('rr-post-grid ' . $custom_classes);
            $grid_gap = max(0, (int) $atts['margin']);
            $grid_cols_desktop = max(1, (int) $atts['columns_desktop']);
            $grid_cols_tablet = max(1, (int) $atts['columns_tablet']);
            $grid_cols_mobile = max(1, (int) $atts['columns_mobile']);

            ob_start();
            echo '<div id="' . esc_attr($grid_uid) . '" class="' . esc_attr($grid_classes) . '"';
            echo ' style="--rr-grid-cols-desktop:' . esc_attr($grid_cols_desktop) . ';--rr-grid-cols-tablet:' . esc_attr($grid_cols_tablet) . ';--rr-grid-cols-mobile:' . esc_attr($grid_cols_mobile) . ';--rr-grid-gap:' . esc_attr($grid_gap) . 'px;"';
            echo '>';

            echo '<div class="rr-post-grid__items">';
            foreach ($query_posts as $query_post) {
                if (!($query_post instanceof WP_Post)) {
                    continue;
                }

                setup_postdata($query_post);
                $post_id = (int) $query_post->ID;
                echo '<div class="rr-post-grid__item">' . $this->render_item_template($template_html, $post_id) . '</div>';
            }
            echo '</div>';

            if ($pagination_enabled && $total_pages > 1) {
                $base_url = remove_query_arg($page_var);
                $page_links = paginate_links(array(
                    'base' => add_query_arg($page_var, '%#%', $base_url),
                    'format' => '',
                    'current' => min($current_page, $total_pages),
                    'total' => $total_pages,
                    'type' => 'array',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ));

                if (is_array($page_links) && !empty($page_links)) {
                    echo '<nav class="rr-post-grid__pagination" aria-label="Paginazione griglia">';
                    foreach ($page_links as $page_link) {
                        echo wp_kses_post($page_link);
                    }
                    echo '</nav>';
                }
            }

            echo '</div>';
            wp_reset_postdata();

            $output = $this->harden_output((string) ob_get_clean());
            if ($debug_enabled) {
                $debug_payload['grid_total_items'] = $total_items;
                $debug_payload['grid_total_pages'] = $total_pages;
                $debug_payload['grid_current_page'] = $current_page;
                $output .= '<!-- rr_owl_carousel debug: ' . esc_html((string) wp_json_encode($debug_payload)) . ' -->';
            }

            return $output;
        }

        $this->enqueue_assets();

        $uid = 'rr-owl-' . wp_rand(1000, 99999);
        $custom_classes = $this->sanitize_classes((string) $atts['class']);
        $carousel_classes = trim('rr-carousel ' . $custom_classes);
        $nav_position = $this->sanitize_control_position($atts['nav_position']);
        $dots_position = $this->sanitize_control_position($atts['dots_position']);
        $nav_enabled = $atts['nav'] === '1';
        $dots_enabled = $atts['dots'] === '1';

        $prev_button = '<button type="button" class="rr-carousel__btn rr-carousel__btn--prev%CLASS%" data-rr-prev aria-label="Precedente">&#8249;</button>';
        $next_button = '<button type="button" class="rr-carousel__btn rr-carousel__btn--next%CLASS%" data-rr-next aria-label="Successivo">&#8250;</button>';
        $dots_box = '<div class="rr-carousel__dots%CLASS%" data-rr-dots></div>';

        ob_start();
        echo '<div id="' . esc_attr($uid) . '" class="' . esc_attr($carousel_classes) . '"';
        echo ' data-items-desktop="' . esc_attr((int) $atts['items_desktop']) . '"';
        echo ' data-items-tablet="' . esc_attr((int) $atts['items_tablet']) . '"';
        echo ' data-items-mobile="' . esc_attr((int) $atts['items_mobile']) . '"';
        echo ' data-loop="' . esc_attr($atts['loop'] === '1' ? '1' : '0') . '"';
        echo ' data-nav="' . esc_attr($nav_enabled ? '1' : '0') . '"';
        echo ' data-dots="' . esc_attr($dots_enabled ? '1' : '0') . '"';
        echo ' data-nav-position="' . esc_attr($nav_position) . '"';
        echo ' data-dots-position="' . esc_attr($dots_position) . '"';
        echo ' data-margin="' . esc_attr((int) $atts['margin']) . '"';
        echo '>';

        echo '<div class="rr-carousel__stage">';

        if ($nav_enabled && $nav_position === 'inside') {
            echo str_replace('%CLASS%', ' is-inside', $prev_button);
        }

        echo '<div class="rr-carousel__viewport"><div class="rr-carousel__track">';
        foreach ($query_posts as $query_post) {
            if (!($query_post instanceof WP_Post)) {
                continue;
            }

            setup_postdata($query_post);
            $post_id = (int) $query_post->ID;
            echo '<div class="rr-owl-item">' . $this->render_item_template($template_html, $post_id) . '</div>';
        }
        echo '</div></div>';

        if ($nav_enabled && $nav_position === 'inside') {
            echo str_replace('%CLASS%', ' is-inside', $next_button);
        }

        if ($dots_enabled && $dots_position === 'inside') {
            echo str_replace('%CLASS%', ' is-inside', $dots_box);
        }

        echo '</div>';

        $nav_below = $nav_enabled && $nav_position === 'below';
        $dots_below = $dots_enabled && $dots_position === 'below';

        if ($nav_below || $dots_below) {
            echo '<div class="rr-carousel__controls-below">';
            if ($nav_below) {
                echo str_replace('%CLASS%', '', $prev_button);
            }
            if ($dots_below) {
                echo str_replace('%CLASS%', '', $dots_box);
            }
            if ($nav_below) {
                echo str_replace('%CLASS%', '', $next_button);
            }
            echo '</div>';
        }

        echo '</div>';
        wp_reset_postdata();

        $output = $this->harden_output((string) ob_get_clean());
        if ($debug_enabled) {
            $output .= '<!-- rr_owl_carousel debug: ' . esc_html((string) wp_json_encode($debug_payload)) . ' -->';
        }

        return $output;
    }

    /**
     * Make the markup resistant to auto-formatting (e.g. Divi/WordPress wpautop)
     * which would otherwise inject <p>/<br> tags and break the Owl structure.
     */
    protected function harden_output($html) {
        // Remove newlines/tabs/whitespace that sit BETWEEN tags. This is what
        // triggers wpautop to insert spurious <p> and <br> elements when the
        // shortcode is used inside a Divi Text module.
        $html = preg_replace('~>\s+<~', '><', $html);

        // Collapse any remaining line breaks so no automatic <br> is generated.
        $html = str_replace(array("\r\n", "\r", "\n"), ' ', $html);

        return trim((string) $html);
    }

    protected function find_template_post($template_ref) {
        $template_ref = trim((string) $template_ref);
        if ($template_ref === '') {
            return null;
        }

        if (is_numeric($template_ref)) {
            $post = get_post((int) $template_ref);
            if ($post && $post->post_type === self::CPT_TEMPLATE) {
                return $post;
            }
        }

        $by_explicit_slug = get_posts(array(
            'post_type' => self::CPT_TEMPLATE,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => self::TEMPLATE_SLUG_META_KEY,
                    'value' => sanitize_title($template_ref),
                    'compare' => '=',
                ),
            ),
        ));

        if (!empty($by_explicit_slug)) {
            return $by_explicit_slug[0];
        }

        $by_slug = get_posts(array(
            'post_type' => self::CPT_TEMPLATE,
            'name' => sanitize_title($template_ref),
            'post_status' => 'publish',
            'posts_per_page' => 1,
        ));

        if (!empty($by_slug)) {
            return $by_slug[0];
        }

        $by_title = get_posts(array(
            'post_type' => self::CPT_TEMPLATE,
            's' => $template_ref,
            'post_status' => 'publish',
            'posts_per_page' => 10,
        ));

        if (!empty($by_title)) {
            foreach ($by_title as $post) {
                if (strcasecmp($post->post_title, $template_ref) === 0) {
                    return $post;
                }
            }
        }

        return null;
    }

    protected function sanitize_compare($compare) {
        $allowed = array('=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE');
        $compare = strtoupper(trim((string) $compare));
        return in_array($compare, $allowed, true) ? $compare : '=';
    }

    protected function parse_filters($filters_raw) {
        $filters_raw = trim((string) $filters_raw);
        $filters_raw = html_entity_decode($filters_raw, ENT_QUOTES, 'UTF-8');
        $filters_raw = trim($filters_raw, " \t\n\r\0\x0B[]");
        if ($filters_raw === '') {
            return array(
                'meta' => array(),
                'tax' => array(),
                'runtime' => array(),
            );
        }

        $chunks = preg_split('/\s*;\s*|[\r\n]+/', $filters_raw);
        if (!is_array($chunks)) {
            return array(
                'meta' => array(),
                'tax' => array(),
                'runtime' => array(),
            );
        }

        $filters = array(
            'meta' => array(),
            'tax' => array(),
            'runtime' => array(),
        );

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk, " \t\n\r\0\x0B[]");
            if ($chunk === '') {
                continue;
            }

            $parsed = $this->parse_filter_chunk($chunk);
            if ($parsed === null) {
                continue;
            }

            $key = sanitize_key($parsed['key']);
            $value = trim((string) $parsed['value'], " \t\n\r\0\x0B\"'");
            $compare = $this->sanitize_compare($parsed['compare']);

            if ($key === '') {
                continue;
            }

            $is_date_like_key = (strpos($key, 'date') !== false || strpos($key, 'data') !== false);
            if ($is_date_like_key && in_array($compare, array('>', '>=', '<', '<=', '=', '!='), true)) {
                $filters['runtime'][] = array(
                    'key' => $key,
                    'compare' => $compare,
                    'value' => $value,
                    'mode' => 'date_compare',
                );
                continue;
            }

            if (in_array($key, array('category', 'categoria', 'cat'), true)) {
                $terms = $this->resolve_tax_terms('category', $value);
                if (!empty($terms)) {
                    $filters['tax'][] = array(
                        'taxonomy' => 'category',
                        'field' => 'slug',
                        'terms' => $terms,
                    );
                }
                continue;
            }

            if (in_array($key, array('tag', 'post_tag'), true)) {
                $terms = $this->resolve_tax_terms('post_tag', $value);
                if (!empty($terms)) {
                    $filters['tax'][] = array(
                        'taxonomy' => 'post_tag',
                        'field' => 'slug',
                        'terms' => $terms,
                    );
                }
                continue;
            }

            if (taxonomy_exists($key)) {
                $terms = $this->resolve_tax_terms($key, $value);
                if (!empty($terms)) {
                    $filters['tax'][] = array(
                        'taxonomy' => $key,
                        'field' => 'slug',
                        'terms' => $terms,
                    );
                }
                continue;
            }

            $filters['meta'][] = $this->build_meta_filter_clause($key, $value, $compare);
        }

        return $filters;
    }

    protected function parse_filter_chunk($chunk) {
        $chunk = trim((string) $chunk);
        $chunk = html_entity_decode($chunk, ENT_QUOTES, 'UTF-8');
        if ($chunk === '') {
            return null;
        }

        if (preg_match('/^([a-zA-Z0-9_\-]+)\s*(>=|<=|!=|=>|=<|=|>|<|~|!~)\s*(.+)$/', $chunk, $m)) {
            $op = isset($m[2]) ? trim((string) $m[2]) : '=';
            if ($op === '=>') {
                $op = '=';
            } elseif ($op === '=<') {
                $op = '<=';
            } elseif ($op === '~') {
                $op = 'LIKE';
            } elseif ($op === '!~') {
                $op = 'NOT LIKE';
            }

            return array(
                'key' => isset($m[1]) ? $m[1] : '',
                'compare' => $op,
                'value' => isset($m[3]) ? $m[3] : '',
            );
        }

        $parts = preg_split('/\s*[:]\s*/', $chunk, 2);
        if (is_array($parts) && count($parts) === 2) {
            return array(
                'key' => $parts[0],
                'compare' => '=',
                'value' => $parts[1],
            );
        }

        $parts = preg_split('/\s*[=]\s*/', $chunk, 2);
        if (is_array($parts) && count($parts) === 2) {
            return array(
                'key' => $parts[0],
                'compare' => '=',
                'value' => $parts[1],
            );
        }

        return null;
    }

    protected function build_meta_filter_clause($key, $value, $compare) {
        $raw_value = trim((string) $value, " \t\n\r\0\x0B\"'");
        $lower_value = strtolower($raw_value);
        $compare = $this->sanitize_compare($compare);

        // Support "now" against both ACF date storage styles:
        // - Ymd (numeric, e.g. 20260630)
        // - Y-m-d (date string)
        if ($lower_value === 'now' && (strpos($key, 'date') !== false || strpos($key, 'data') !== false)) {
            $timestamp = (int) current_time('timestamp');
            $offset = (int) (get_option('gmt_offset') * HOUR_IN_SECONDS);
            $ymd = gmdate('Ymd', $timestamp + $offset);
            $date_iso = gmdate('Y-m-d', $timestamp + $offset);

            return array(
                'relation' => 'OR',
                array(
                    'key' => $key,
                    'value' => $ymd,
                    'compare' => $compare,
                    'type' => 'NUMERIC',
                ),
                array(
                    'key' => $key,
                    'value' => $date_iso,
                    'compare' => $compare,
                    'type' => 'DATE',
                ),
                array(
                    'key' => $key,
                    'value' => (string) $timestamp,
                    'compare' => $compare,
                    'type' => 'NUMERIC',
                ),
            );
        }

        return array(
            'key' => $key,
            'value' => $this->normalize_filter_value($raw_value, $key, $compare),
            'compare' => $compare,
            'type' => $this->detect_meta_type($raw_value, $compare),
        );
    }

    protected function normalize_filter_value($value, $key = '', $compare = '=') {
        $value = trim((string) $value, " \t\n\r\0\x0B\"'");
        if (strtolower($value) === 'now') {
            $timestamp = (int) current_time('timestamp');
            if (strpos($key, 'date') !== false || strpos($key, 'data') !== false || in_array($compare, array('>', '>=', '<', '<='), true)) {
                return gmdate('Y-m-d', $timestamp + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS));
            }
            return (string) $timestamp;
        }

        return sanitize_text_field($value);
    }

    protected function detect_meta_type($value, $compare = '=') {
        $value = trim((string) $value, " \t\n\r\0\x0B\"'");
        if (strtolower($value) === 'now') {
            return 'DATE';
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return 'NUMERIC';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return 'DATE';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return 'DATETIME';
        }

        if (in_array($compare, array('LIKE', 'NOT LIKE', 'REGEXP', 'NOT REGEXP', 'RLIKE'), true)) {
            return 'CHAR';
        }

        return 'CHAR';
    }

    protected function apply_runtime_filters(array $posts, array $runtime_filters) {
        if (empty($runtime_filters)) {
            return $posts;
        }

        $result = array();
        foreach ($posts as $post) {
            if (!($post instanceof WP_Post)) {
                continue;
            }

            $matches = true;
            foreach ($runtime_filters as $runtime_filter) {
                if (!$this->runtime_filter_matches((int) $post->ID, $runtime_filter)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $result[] = $post;
            }
        }

        return $result;
    }

    protected function runtime_filter_matches($post_id, array $runtime_filter) {
        $key = isset($runtime_filter['key']) ? sanitize_key($runtime_filter['key']) : '';
        $compare = isset($runtime_filter['compare']) ? $this->sanitize_compare($runtime_filter['compare']) : '=';
        $mode = isset($runtime_filter['mode']) ? (string) $runtime_filter['mode'] : '';
        $raw_right_value = isset($runtime_filter['value']) ? (string) $runtime_filter['value'] : '';

        if ($key === '' || $mode !== 'date_compare') {
            return true;
        }

        $raw_value = get_post_meta($post_id, $key, true);
        if ($raw_value === '' && function_exists('get_field')) {
            $acf_value = get_field($key, $post_id);
            if ($acf_value !== null && $acf_value !== false) {
                $raw_value = $acf_value;
            }
        }

        $left = $this->parse_date_to_timestamp($raw_value);
        if ($left === false) {
            return false;
        }

        $right_value = trim($raw_right_value, " \t\n\r\0\x0B\"'");
        if (strtolower($right_value) === 'now') {
            $right = strtotime((string) current_time('Y-m-d') . ' 00:00:00');
            if ($right === false) {
                $right = (int) current_time('timestamp');
            }
        } else {
            $right = $this->parse_date_to_timestamp($right_value);
            if ($right === false) {
                return false;
            }
        }

        switch ($compare) {
            case '>':
                return $left > $right;
            case '>=':
                return $left >= $right;
            case '<':
                return $left < $right;
            case '<=':
                return $left <= $right;
            case '!=':
                return $left !== $right;
            case '=':
            default:
                return $left === $right;
        }
    }

    protected function render_item_template($template_html, $post_id) {
        return preg_replace_callback('/\[([a-zA-Z0-9_\-]+)([^\]]*)\]/', function ($matches) use ($post_id) {
            $field = isset($matches[1]) ? $matches[1] : '';
            $atts_raw = isset($matches[2]) ? trim((string) $matches[2]) : '';
            $atts = array();

            if ($atts_raw !== '') {
                $parsed_atts = shortcode_parse_atts($atts_raw);
                if (is_array($parsed_atts)) {
                    $atts = $parsed_atts;
                }
            }

            return $this->resolve_field($field, $post_id, $atts);
        }, $template_html);
    }

    protected function resolve_field($field, $post_id, $atts = array()) {
        $field = strtolower(trim((string) $field));
        if ($field === '') {
            return '';
        }

        $size = isset($atts['size']) ? sanitize_key((string) $atts['size']) : 'large';
        if ($size === '') {
            $size = 'large';
        }

        $format = isset($atts['format']) ? sanitize_text_field((string) $atts['format']) : '';

        switch ($field) {
            case 'title':
                return esc_html(get_the_title($post_id));

            case 'excerpt':
                return esc_html($this->get_safe_excerpt($post_id, $atts));

            case 'content':
                $post = get_post($post_id);
                if (!$post) {
                    return '';
                }
                return apply_filters('the_content', (string) $post->post_content);

            case 'permalink':
                return esc_url(get_permalink($post_id));

            case 'thumbnail':
                return (string) get_the_post_thumbnail($post_id, $size);

            case 'id':
                return (string) absint($post_id);

            case 'post_date':
                return esc_html($format !== '' ? get_the_date($format, $post_id) : get_the_date('', $post_id));

            case 'post_type':
                return esc_html(get_post_type($post_id));
        }

        $value = null;

        if (function_exists('get_field')) {
            $acf_value = get_field($field, $post_id);
            if ($acf_value !== null && $acf_value !== false) {
                $value = $acf_value;
            }
        }

        if ($value === null) {
            $meta_value = get_post_meta($post_id, $field, true);
            if ($meta_value !== '') {
                $value = $meta_value;
            }
        }

        if ($format !== '' && $this->looks_like_date_value($value)) {
            return esc_html($this->format_date_value($value, $format));
        }

        if (isset($atts['size']) && $this->looks_like_image_value($value)) {
            return $this->render_image_value($value, $size);
        }

        if (is_array($value)) {
            $flat = $this->flatten_array_values($value);
            return esc_html(implode(', ', $flat));
        }

        if (is_object($value)) {
            return esc_html(wp_json_encode($value));
        }

        if ($value === null) {
            return '';
        }

        return esc_html((string) $value);
    }

    protected function get_safe_excerpt($post_id, $atts = array()) {
        $words = 24;
        if (isset($atts['words'])) {
            $requested_words = absint($atts['words']);
            if ($requested_words > 0) {
                $words = $requested_words;
            }
        }

        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $raw_excerpt = trim((string) $post->post_excerpt);
        if ($raw_excerpt !== '') {
            return wp_trim_words(wp_strip_all_tags($raw_excerpt), $words, '...');
        }

        $raw_content = (string) $post->post_content;
        if ($raw_content === '') {
            return '';
        }

        return wp_trim_words(wp_strip_all_tags($raw_content), $words, '...');
    }

    protected function looks_like_date_value($value) {
        return $this->parse_date_to_timestamp($value) !== false;
    }

    protected function format_date_value($value, $format) {
        $timestamp = $this->parse_date_to_timestamp($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        return date_i18n($format, $timestamp);
    }

    protected function parse_date_to_timestamp($value) {
        if (is_numeric($value)) {
            $numeric = trim((string) $value);

            // ACF date pickers are often stored as Ymd (e.g. 20260715).
            if (preg_match('/^\d{8}$/', $numeric)) {
                $dt = \DateTime::createFromFormat('Ymd', $numeric);
                if ($dt instanceof \DateTime) {
                    return $dt->getTimestamp();
                }
            }

            // Milliseconds timestamp support.
            if (preg_match('/^\d{13}$/', $numeric)) {
                return (int) floor(((int) $numeric) / 1000);
            }

            // Seconds timestamp support.
            if (preg_match('/^\d{10}$/', $numeric)) {
                return (int) $numeric;
            }

            return (int) $numeric;
        }

        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $formats = array(
            'Ymd',
            'd/m/Y',
            'd-m-Y',
            'Y-m-d',
            'Y-m-d H:i:s',
            'd/m/Y H:i',
            'd-m-Y H:i',
        );

        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $value);
            if ($dt instanceof \DateTime) {
                return $dt->getTimestamp();
            }
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? $timestamp : false;
    }

    protected function looks_like_image_value($value) {
        if (is_numeric($value)) {
            return true;
        }

        if (is_array($value)) {
            return isset($value['ID']) || isset($value['id']) || isset($value['url']);
        }

        return is_string($value) && $value !== '';
    }

    protected function render_image_value($value, $size) {
        if (is_numeric($value)) {
            return (string) wp_get_attachment_image((int) $value, $size);
        }

        if (is_array($value)) {
            $attachment_id = 0;

            if (!empty($value['ID'])) {
                $attachment_id = absint($value['ID']);
            } elseif (!empty($value['id'])) {
                $attachment_id = absint($value['id']);
            }

            if ($attachment_id > 0) {
                return (string) wp_get_attachment_image($attachment_id, $size);
            }

            if (!empty($value['url'])) {
                return '<img src="' . esc_url($value['url']) . '" alt="">';
            }
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return '<img src="' . esc_url($value) . '" alt="">';
        }

        return esc_html($value);
    }

    protected function flatten_array_values(array $value) {
        $flat = array();

        foreach ($value as $item) {
            if (is_array($item)) {
                $flat = array_merge($flat, $this->flatten_array_values($item));
                continue;
            }

            if (is_object($item)) {
                $flat[] = wp_json_encode($item);
                continue;
            }

            $flat[] = (string) $item;
        }

        return $flat;
    }

    protected function sanitize_classes($classes_raw) {
        $classes_raw = trim((string) $classes_raw);
        if ($classes_raw === '') {
            return '';
        }

        $chunks = preg_split('/\s+/', $classes_raw);
        if (!is_array($chunks)) {
            return '';
        }

        $classes = array();
        foreach ($chunks as $chunk) {
            $class = sanitize_html_class($chunk);
            if ($class !== '') {
                $classes[] = $class;
            }
        }

        return implode(' ', array_unique($classes));
    }

    protected function get_detected_acf_placeholders() {
        if (did_action('init') === 0) {
            return array();
        }

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return array();
        }

        $groups = acf_get_field_groups();
        if (!is_array($groups) || empty($groups)) {
            return array();
        }

        $names = array();

        foreach ($groups as $group) {
            $fields = acf_get_fields($group);
            if (!is_array($fields) || empty($fields)) {
                continue;
            }

            foreach ($fields as $field) {
                if (!is_array($field) || empty($field['name'])) {
                    continue;
                }

                $name = trim((string) $field['name']);
                if ($name === '') {
                    continue;
                }

                $names[] = $name;
            }
        }

        if (empty($names)) {
            return array();
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return array_slice($names, 0, 80);
    }

    protected function sanitize_terms_list($terms_raw) {
        $parts = preg_split('/[|,]+/', (string) $terms_raw);
        if (!is_array($parts)) {
            return array();
        }

        $terms = array();
        foreach ($parts as $part) {
            $term = sanitize_title(trim((string) $part));
            if ($term !== '') {
                $terms[] = $term;
            }
        }

        return array_values(array_unique($terms));
    }

    protected function resolve_tax_terms($taxonomy, $terms_raw) {
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return array();
        }

        $parts = preg_split('/[|,]+/', (string) $terms_raw);
        if (!is_array($parts)) {
            return array();
        }

        $resolved = array();

        foreach ($parts as $part) {
            $raw = trim((string) $part);
            if ($raw === '') {
                continue;
            }

            if (ctype_digit($raw)) {
                $term_by_id = get_term_by('id', (int) $raw, $taxonomy);
                if ($term_by_id && !is_wp_error($term_by_id)) {
                    $resolved[] = $term_by_id->slug;
                    continue;
                }
            }

            $slug = sanitize_title($raw);
            if ($slug !== '') {
                $term_by_slug = get_term_by('slug', $slug, $taxonomy);
                if ($term_by_slug && !is_wp_error($term_by_slug)) {
                    $resolved[] = $term_by_slug->slug;
                    continue;
                }
            }

            $term_by_name = get_term_by('name', $raw, $taxonomy);
            if ($term_by_name && !is_wp_error($term_by_name)) {
                $resolved[] = $term_by_name->slug;
                continue;
            }

            // Last-resort fallback for existing behavior.
            if ($slug !== '') {
                $resolved[] = $slug;
            }
        }

        return array_values(array_unique($resolved));
    }

    protected function sanitize_control_position($position) {
        $position = strtolower(trim((string) $position));
        return in_array($position, array('inside', 'below'), true) ? $position : 'inside';
    }

    protected function ensure_unique_template_slug($slug, $post_id) {
        $base_slug = sanitize_title((string) $slug);
        if ($base_slug === '') {
            $base_slug = 'template';
        }

        $candidate = $base_slug;
        $index = 2;

        while ($this->template_slug_exists($candidate, $post_id)) {
            $candidate = $base_slug . '-' . $index;
            $index++;
        }

        return $candidate;
    }

    protected function template_slug_exists($slug, $post_id) {
        $match = get_posts(array(
            'post_type' => self::CPT_TEMPLATE,
            'post_status' => array('publish', 'future', 'draft', 'pending', 'private'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post__not_in' => array((int) $post_id),
            'meta_query' => array(
                array(
                    'key' => self::TEMPLATE_SLUG_META_KEY,
                    'value' => (string) $slug,
                    'compare' => '=',
                ),
            ),
        ));

        return !empty($match);
    }
}

$rete_riserve_owl_carousel = new ReteRiserveOwlCarousel();
$rete_riserve_owl_carousel->init();
