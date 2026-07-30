<?php
/**
 * Plugin Name: Rete di Riserve YouTube Feed
 * Description: Mostra gli ultimi video di un canale YouTube tramite shortcode. L'ID canale si imposta dalla pagina del plugin, il layout (griglia o in linea) e il numero di video si scelgono nello shortcode.
 * Version: 1.0.0
 * Author: Custom
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: rete-riserve-youtube-feed
 */

if (!defined('ABSPATH')) {
    exit;
}

class ReteRiserveYouTubeFeed {
    const MENU_SLUG = 'rete-riserve-youtube-feed';
    const CAPABILITY = 'manage_options';
    const OPTION_GROUP = 'rryf_settings_group';
    const OPTION_CHANNEL_ID = 'rryf_channel_id';
    const OPTION_DEFAULT_LAYOUT = 'rryf_default_layout';
    const OPTION_DEFAULT_COUNT = 'rryf_default_count';
    const OPTION_DEFAULT_COLUMNS = 'rryf_default_columns';
    const OPTION_CACHE_MINUTES = 'rryf_cache_minutes';
    const CACHE_PREFIX = 'rryf_feed_';
    const STYLE_HANDLE = 'rryf-styles';
    const SHORTCODE = 'rr_youtube';

    protected $styles_printed = false;

    public function init() {
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
    }

    /* ---------------------------------------------------------------------
     * Admin: pagina impostazioni
     * ------------------------------------------------------------------- */

    public function register_admin_page() {
        add_menu_page(
            __('YouTube Feed', 'rete-riserve-youtube-feed'),
            __('YouTube Feed', 'rete-riserve-youtube-feed'),
            self::CAPABILITY,
            self::MENU_SLUG,
            array($this, 'render_admin_page'),
            'dashicons-video-alt3',
            58
        );
    }

    public function register_settings() {
        register_setting(self::OPTION_GROUP, self::OPTION_CHANNEL_ID, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_channel_id'),
            'default' => '',
        ));

        register_setting(self::OPTION_GROUP, self::OPTION_DEFAULT_LAYOUT, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_layout'),
            'default' => 'grid',
        ));

        register_setting(self::OPTION_GROUP, self::OPTION_DEFAULT_COUNT, array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_count'),
            'default' => 6,
        ));

        register_setting(self::OPTION_GROUP, self::OPTION_DEFAULT_COLUMNS, array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_columns'),
            'default' => 3,
        ));

        register_setting(self::OPTION_GROUP, self::OPTION_CACHE_MINUTES, array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_cache_minutes'),
            'default' => 30,
        ));
    }

    public function sanitize_channel_id($value) {
        $value = is_string($value) ? trim($value) : '';

        // Accetta anche l'URL completo del feed e ne estrae il channel_id.
        if (preg_match('/channel_id=([A-Za-z0-9_\-]+)/', $value, $m)) {
            return $m[1];
        }

        // Accetta un URL /channel/UC... del canale.
        if (preg_match('#/channel/([A-Za-z0-9_\-]+)#', $value, $m)) {
            return $m[1];
        }

        return preg_replace('/[^A-Za-z0-9_\-]/', '', $value);
    }

    public function sanitize_layout($value) {
        return in_array($value, array('grid', 'inline'), true) ? $value : 'grid';
    }

    public function sanitize_count($value) {
        $value = (int) $value;
        if ($value < 1) {
            $value = 1;
        }
        if ($value > 50) {
            $value = 50;
        }
        return $value;
    }

    public function sanitize_columns($value) {
        $value = (int) $value;
        if ($value < 1) {
            $value = 1;
        }
        if ($value > 6) {
            $value = 6;
        }
        return $value;
    }

    public function sanitize_cache_minutes($value) {
        $value = (int) $value;
        if ($value < 0) {
            $value = 0;
        }
        if ($value > 1440) {
            $value = 1440;
        }
        return $value;
    }

    public function render_admin_page() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $channel_id = (string) get_option(self::OPTION_CHANNEL_ID, '');
        $default_layout = (string) get_option(self::OPTION_DEFAULT_LAYOUT, 'grid');
        $default_count = (int) get_option(self::OPTION_DEFAULT_COUNT, 6);
        $default_columns = (int) get_option(self::OPTION_DEFAULT_COLUMNS, 3);
        $cache_minutes = (int) get_option(self::OPTION_CACHE_MINUTES, 30);

        // Pulizia cache su richiesta.
        if (isset($_POST['rryf_clear_cache']) && check_admin_referer('rryf_clear_cache_action', 'rryf_clear_cache_nonce')) {
            $this->clear_cache();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cache svuotata.', 'rete-riserve-youtube-feed') . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Rete di Riserve YouTube Feed', 'rete-riserve-youtube-feed'); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="rryf_channel_id"><?php echo esc_html__('ID canale YouTube', 'rete-riserve-youtube-feed'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="rryf_channel_id" name="<?php echo esc_attr(self::OPTION_CHANNEL_ID); ?>" value="<?php echo esc_attr($channel_id); ?>" class="regular-text" placeholder="UCo-h_n8X_EzoVsTk9jLYNvg" />
                            <p class="description">
                                <?php echo esc_html__('Incolla l\'ID del canale (es. UCo-h_n8X_EzoVsTk9jLYNvg) oppure l\'URL completo del feed. Trovi l\'ID nel link del feed:', 'rete-riserve-youtube-feed'); ?>
                                <br />
                                <code>https://www.youtube.com/feeds/videos.xml?channel_id=<strong>ID_CANALE</strong></code>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="rryf_default_layout"><?php echo esc_html__('Layout predefinito', 'rete-riserve-youtube-feed'); ?></label>
                        </th>
                        <td>
                            <select id="rryf_default_layout" name="<?php echo esc_attr(self::OPTION_DEFAULT_LAYOUT); ?>">
                                <option value="grid" <?php selected($default_layout, 'grid'); ?>><?php echo esc_html__('Griglia', 'rete-riserve-youtube-feed'); ?></option>
                                <option value="inline" <?php selected($default_layout, 'inline'); ?>><?php echo esc_html__('In linea', 'rete-riserve-youtube-feed'); ?></option>
                            </select>
                            <p class="description"><?php echo esc_html__('Usato quando lo shortcode non specifica il parametro layout.', 'rete-riserve-youtube-feed'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="rryf_default_count"><?php echo esc_html__('Numero video predefinito', 'rete-riserve-youtube-feed'); ?></label>
                        </th>
                        <td>
                            <input type="number" min="1" max="50" id="rryf_default_count" name="<?php echo esc_attr(self::OPTION_DEFAULT_COUNT); ?>" value="<?php echo esc_attr($default_count); ?>" class="small-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="rryf_default_columns"><?php echo esc_html__('Colonne predefinite (griglia)', 'rete-riserve-youtube-feed'); ?></label>
                        </th>
                        <td>
                            <input type="number" min="1" max="6" id="rryf_default_columns" name="<?php echo esc_attr(self::OPTION_DEFAULT_COLUMNS); ?>" value="<?php echo esc_attr($default_columns); ?>" class="small-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="rryf_cache_minutes"><?php echo esc_html__('Durata cache (minuti)', 'rete-riserve-youtube-feed'); ?></label>
                        </th>
                        <td>
                            <input type="number" min="0" max="1440" id="rryf_cache_minutes" name="<?php echo esc_attr(self::OPTION_CACHE_MINUTES); ?>" value="<?php echo esc_attr($cache_minutes); ?>" class="small-text" />
                            <p class="description"><?php echo esc_html__('Per quanto tempo conservare i risultati del feed prima di riscaricarli. 0 = nessuna cache.', 'rete-riserve-youtube-feed'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Salva impostazioni', 'rete-riserve-youtube-feed')); ?>
            </form>

            <hr />

            <h2><?php echo esc_html__('Come usare lo shortcode', 'rete-riserve-youtube-feed'); ?></h2>
            <p><?php echo esc_html__('Incolla uno di questi shortcode in un modulo Testo di Divi (o in qualsiasi contenuto):', 'rete-riserve-youtube-feed'); ?></p>
            <ul style="list-style: disc; padding-left: 20px;">
                <li><code>[<?php echo esc_html(self::SHORTCODE); ?>]</code> &mdash; <?php echo esc_html__('usa i valori predefiniti impostati sopra.', 'rete-riserve-youtube-feed'); ?></li>
                <li><code>[<?php echo esc_html(self::SHORTCODE); ?> count="6" layout="grid" columns="3"]</code> &mdash; <?php echo esc_html__('6 video in griglia su 3 colonne.', 'rete-riserve-youtube-feed'); ?></li>
                <li><code>[<?php echo esc_html(self::SHORTCODE); ?> count="4" layout="inline"]</code> &mdash; <?php echo esc_html__('4 video disposti in linea.', 'rete-riserve-youtube-feed'); ?></li>
                <li><code>[<?php echo esc_html(self::SHORTCODE); ?> count="3" channel="UCxxxx"]</code> &mdash; <?php echo esc_html__('sovrascrive l\'ID canale solo per questo shortcode.', 'rete-riserve-youtube-feed'); ?></li>
            </ul>

            <table class="widefat striped" style="max-width: 720px; margin-top: 12px;">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Parametro', 'rete-riserve-youtube-feed'); ?></th>
                        <th><?php echo esc_html__('Valori', 'rete-riserve-youtube-feed'); ?></th>
                        <th><?php echo esc_html__('Descrizione', 'rete-riserve-youtube-feed'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>count</code></td><td>1&ndash;50</td><td><?php echo esc_html__('Quanti video mostrare.', 'rete-riserve-youtube-feed'); ?></td></tr>
                    <tr><td><code>layout</code></td><td>grid, inline</td><td><?php echo esc_html__('Griglia oppure in linea (scorrimento orizzontale).', 'rete-riserve-youtube-feed'); ?></td></tr>
                    <tr><td><code>columns</code></td><td>1&ndash;6</td><td><?php echo esc_html__('Numero di colonne (solo layout griglia).', 'rete-riserve-youtube-feed'); ?></td></tr>
                    <tr><td><code>channel</code></td><td>UC...</td><td><?php echo esc_html__('ID canale alternativo (facoltativo).', 'rete-riserve-youtube-feed'); ?></td></tr>
                    <tr><td><code>date</code></td><td>1, 0</td><td><?php echo esc_html__('Mostra o nasconde la data del video (default 1).', 'rete-riserve-youtube-feed'); ?></td></tr>
                </tbody>
            </table>

            <hr />

            <form method="post">
                <?php wp_nonce_field('rryf_clear_cache_action', 'rryf_clear_cache_nonce'); ?>
                <?php submit_button(__('Svuota cache feed', 'rete-riserve-youtube-feed'), 'secondary', 'rryf_clear_cache', false); ?>
            </form>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Front-end: shortcode
     * ------------------------------------------------------------------- */

    public function render_shortcode($atts) {
        $defaults = array(
            'count' => (int) get_option(self::OPTION_DEFAULT_COUNT, 6),
            'layout' => (string) get_option(self::OPTION_DEFAULT_LAYOUT, 'grid'),
            'columns' => (int) get_option(self::OPTION_DEFAULT_COLUMNS, 3),
            'channel' => '',
            'date' => '1',
        );

        $atts = shortcode_atts($defaults, is_array($atts) ? $atts : array(), self::SHORTCODE);

        $count = $this->sanitize_count($atts['count']);
        $layout = $this->sanitize_layout($atts['layout']);
        $columns = $this->sanitize_columns($atts['columns']);
        $show_date = !in_array((string) $atts['date'], array('0', 'no', 'false', ''), true);

        $channel_id = $atts['channel'] !== ''
            ? $this->sanitize_channel_id($atts['channel'])
            : (string) get_option(self::OPTION_CHANNEL_ID, '');

        if ($channel_id === '') {
            if (current_user_can(self::CAPABILITY)) {
                return '<p>' . esc_html__('Rete di Riserve YouTube Feed: nessun ID canale configurato.', 'rete-riserve-youtube-feed') . '</p>';
            }
            return '';
        }

        $videos = $this->get_videos($channel_id, $count);

        if (is_wp_error($videos)) {
            if (current_user_can(self::CAPABILITY)) {
                return '<p>' . esc_html($videos->get_error_message()) . '</p>';
            }
            return '';
        }

        if (empty($videos)) {
            return '<p>' . esc_html__('Nessun video disponibile.', 'rete-riserve-youtube-feed') . '</p>';
        }

        return $this->render_videos($videos, $layout, $columns, $show_date);
    }

    protected function render_videos($videos, $layout, $columns, $show_date) {
        $this->print_styles();

        $wrap_class = 'rryf-feed rryf-layout-' . esc_attr($layout);
        $style_attr = '';
        if ($layout === 'grid') {
            $wrap_class .= ' rryf-cols-' . (int) $columns;
            $style_attr = ' style="--rryf-columns:' . (int) $columns . ';"';
        }

        $html = '<div class="' . $wrap_class . '"' . $style_attr . '>';

        foreach ($videos as $video) {
            $html .= $this->render_video_item($video, $show_date);
        }

        $html .= '</div>';

        return $html;
    }

    protected function render_video_item($video, $show_date) {
        $url = 'https://www.youtube.com/watch?v=' . rawurlencode($video['id']);
        $thumb = 'https://i.ytimg.com/vi/' . rawurlencode($video['id']) . '/hqdefault.jpg';

        $html = '<article class="rryf-item">';
        $html .= '<a class="rryf-link" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">';
        $html .= '<span class="rryf-thumb">';
        $html .= '<img src="' . esc_url($thumb) . '" alt="' . esc_attr($video['title']) . '" loading="lazy" />';
        $html .= '<span class="rryf-play" aria-hidden="true"></span>';
        $html .= '</span>';
        $html .= '<span class="rryf-title">' . esc_html($video['title']) . '</span>';

        if ($show_date && !empty($video['published'])) {
            $html .= '<span class="rryf-date">' . esc_html($this->human_date($video['published'])) . '</span>';
        }

        $html .= '</a>';
        $html .= '</article>';

        return $html;
    }

    protected function human_date($iso) {
        $ts = strtotime($iso);
        if (!$ts) {
            return '';
        }

        $diff = current_time('timestamp') - $ts;
        if ($diff >= 0 && $diff < YEAR_IN_SECONDS) {
            /* translators: %s: human readable time difference, e.g. "2 giorni". */
            return sprintf(__('%s fa', 'rete-riserve-youtube-feed'), human_time_diff($ts, current_time('timestamp')));
        }

        return date_i18n(get_option('date_format'), $ts);
    }

    /* ---------------------------------------------------------------------
     * Feed: recupero e parsing
     * ------------------------------------------------------------------- */

    protected function get_videos($channel_id, $count) {
        $cache_minutes = (int) get_option(self::OPTION_CACHE_MINUTES, 30);
        $cache_key = self::CACHE_PREFIX . md5($channel_id);

        if ($cache_minutes > 0) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return array_slice($cached, 0, $count);
            }
        }

        $feed_url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channel_id);

        $response = wp_remote_get($feed_url, array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/atom+xml'),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('rryf_http', __('Impossibile contattare YouTube: ', 'rete-riserve-youtube-feed') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('rryf_http_code', sprintf(
                /* translators: %d: HTTP status code. */
                __('YouTube ha risposto con codice %d. Verifica l\'ID canale.', 'rete-riserve-youtube-feed'),
                $code
            ));
        }

        $body = wp_remote_retrieve_body($response);
        $videos = $this->parse_feed($body);

        if (is_wp_error($videos)) {
            return $videos;
        }

        if ($cache_minutes > 0 && !empty($videos)) {
            set_transient($cache_key, $videos, $cache_minutes * MINUTE_IN_SECONDS);
        }

        return array_slice($videos, 0, $count);
    }

    protected function parse_feed($body) {
        if (!is_string($body) || $body === '') {
            return new WP_Error('rryf_empty', __('Feed YouTube vuoto.', 'rete-riserve-youtube-feed'));
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return new WP_Error('rryf_parse', __('Impossibile leggere il feed YouTube.', 'rete-riserve-youtube-feed'));
        }

        $namespaces = $xml->getNamespaces(true);
        $videos = array();

        foreach ($xml->entry as $entry) {
            $yt = isset($namespaces['yt']) ? $entry->children($namespaces['yt']) : null;
            $media = isset($namespaces['media']) ? $entry->children($namespaces['media']) : null;

            $video_id = '';
            if ($yt && isset($yt->videoId)) {
                $video_id = (string) $yt->videoId;
            }

            if ($video_id === '') {
                continue;
            }

            $title = (string) $entry->title;
            if ($title === '' && $media && isset($media->group->title)) {
                $title = (string) $media->group->title;
            }

            $videos[] = array(
                'id' => $video_id,
                'title' => $title,
                'published' => (string) $entry->published,
            );
        }

        return $videos;
    }

    protected function clear_cache() {
        global $wpdb;
        $like = $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%';
        $timeout_like = $wpdb->esc_like('_transient_timeout_' . self::CACHE_PREFIX) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout_like));
    }

    /* ---------------------------------------------------------------------
     * Stili
     * ------------------------------------------------------------------- */

    protected function print_styles() {
        if ($this->styles_printed) {
            return;
        }
        $this->styles_printed = true;

        $css = '
.rryf-feed{--rryf-columns:3;--rryf-gap:20px;}
.rryf-feed .rryf-item{margin:0;}
.rryf-feed .rryf-link{display:flex;flex-direction:column;text-decoration:none;color:inherit;}
.rryf-feed .rryf-thumb{position:relative;display:block;overflow:hidden;border-radius:8px;aspect-ratio:16/9;background:#000;}
.rryf-feed .rryf-thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s ease;}
.rryf-feed .rryf-link:hover .rryf-thumb img{transform:scale(1.05);}
.rryf-feed .rryf-play{position:absolute;top:50%;left:50%;width:56px;height:40px;transform:translate(-50%,-50%);background:rgba(0,0,0,.65);border-radius:10px;}
.rryf-feed .rryf-play:before{content:"";position:absolute;top:50%;left:50%;transform:translate(-40%,-50%);border-style:solid;border-width:9px 0 9px 15px;border-color:transparent transparent transparent #fff;}
.rryf-feed .rryf-link:hover .rryf-play{background:#ff0000;}
.rryf-feed .rryf-title{display:block;margin-top:10px;font-weight:600;line-height:1.3;}
.rryf-feed .rryf-date{display:block;margin-top:4px;font-size:.85em;opacity:.7;}
.rryf-layout-grid{display:grid;grid-template-columns:repeat(var(--rryf-columns),minmax(0,1fr));gap:var(--rryf-gap);}
.rryf-layout-inline{display:flex;flex-wrap:nowrap;gap:var(--rryf-gap);overflow-x:auto;padding-bottom:8px;scroll-snap-type:x mandatory;}
.rryf-layout-inline .rryf-item{flex:0 0 clamp(220px,60%,300px);scroll-snap-align:start;}
@media(max-width:980px){.rryf-layout-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:600px){.rryf-layout-grid{grid-template-columns:1fr;}}
';

        echo '<style id="' . esc_attr(self::STYLE_HANDLE) . '">' . $css . '</style>';
    }
}

$rete_riserve_youtube_feed = new ReteRiserveYouTubeFeed();
$rete_riserve_youtube_feed->init();
