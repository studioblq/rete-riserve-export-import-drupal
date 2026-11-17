<?php
/**
 * Area051 GitHub updater (shared copy).
 *
 * Every plugin ships its own copy of this file in lib/. The class is declared
 * only once per request (class_exists guard), so several plugins can bundle it.
 * Canonical source: wordpress-plugin/_shared/class-area051-github-updater.php.
 * Do not edit the copies: change the canonical file and run
 * python tools/wp-plugins-add-updater.py. If the public API ever changes, rename the class
 * (V2, V3...) so plugins with an older copy keep working.
 *
 * How updates are published: a GitHub Action builds one zip per plugin, attaches
 * it to a release tagged "<slug>-v<version>" and refreshes manifest.json on the
 * "updates" release. This class reads that manifest.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Area051_GitHub_Updater_V1', false)) {

    class Area051_GitHub_Updater_V1 {
        const MANIFEST_URL = 'https://github.com/studioblq/rete-riserve-export-import-drupal/releases/download/updates/manifest.json';
        const CACHE_KEY = 'area051_gh_manifest_v1';
        const CACHE_TTL = 6 * HOUR_IN_SECONDS;
        const ERROR_TTL = 30 * MINUTE_IN_SECONDS;

        /** @var array<string,bool> Hooks registered once per request, shared by all instances. */
        protected static $instances = array();

        protected $plugin_file;
        protected $basename;
        protected $slug;

        /**
         * @param string $plugin_file Main plugin file (__FILE__).
         * @param string $slug        Plugin slug in the repository (folder name).
         */
        public function __construct($plugin_file, $slug = '') {
            $this->plugin_file = $plugin_file;
            $this->basename = plugin_basename($plugin_file);
            $this->slug = $slug !== '' ? $slug : dirname($this->basename);

            if (isset(self::$instances[$this->basename])) {
                return;
            }
            self::$instances[$this->basename] = true;

            // Plugins with "Update URI: https://github.com/..." are checked through this filter (WP 5.8+).
            add_filter('update_plugins_github.com', array($this, 'filter_update'), 10, 4);
            add_filter('plugins_api', array($this, 'filter_plugins_api'), 20, 3);
            add_filter('upgrader_source_selection', array($this, 'fix_source_folder'), 10, 4);
            add_action('upgrader_process_complete', array(__CLASS__, 'flush_cache'), 10, 0);
            add_filter('plugin_row_meta', array($this, 'filter_row_meta'), 10, 2);
            add_action('admin_init', array($this, 'maybe_force_check'));
        }

        public static function flush_cache() {
            delete_site_transient(self::CACHE_KEY);
        }

        /**
         * Manifest decoded as array: { plugins: { slug: {...} } }.
         */
        protected static function get_manifest($force = false) {
            if (!$force) {
                $cached = get_site_transient(self::CACHE_KEY);
                if (is_array($cached)) {
                    return $cached;
                }
            }

            $response = wp_remote_get(self::MANIFEST_URL, array(
                'timeout' => 10,
                'headers' => array('Accept' => 'application/json'),
            ));

            $manifest = array('plugins' => array(), 'error' => '');
            $ttl = self::CACHE_TTL;

            if (is_wp_error($response)) {
                $manifest['error'] = $response->get_error_message();
                $ttl = self::ERROR_TTL;
            } elseif ((int) wp_remote_retrieve_response_code($response) !== 200) {
                $manifest['error'] = 'HTTP ' . (int) wp_remote_retrieve_response_code($response);
                $ttl = self::ERROR_TTL;
            } else {
                $decoded = json_decode(wp_remote_retrieve_body($response), true);
                if (is_array($decoded) && isset($decoded['plugins']) && is_array($decoded['plugins'])) {
                    $manifest = $decoded;
                    $manifest['error'] = '';
                } else {
                    $manifest['error'] = 'Manifest non valido';
                    $ttl = self::ERROR_TTL;
                }
            }

            set_site_transient(self::CACHE_KEY, $manifest, $ttl);
            return $manifest;
        }

        protected function get_remote() {
            $manifest = self::get_manifest();
            if (empty($manifest['plugins'][$this->slug]) || !is_array($manifest['plugins'][$this->slug])) {
                return null;
            }
            $remote = $manifest['plugins'][$this->slug];
            if (empty($remote['version']) || empty($remote['package'])) {
                return null;
            }
            return $remote;
        }

        /**
         * "Verifica di nuovo" on Dashboard > Updates also refreshes the manifest.
         */
        public function maybe_force_check() {
            global $pagenow;
            if ($pagenow === 'update-core.php' && isset($_GET['force-check']) && current_user_can('update_plugins')) {
                self::flush_cache();
            }
        }

        /**
         * @param array|false $update      Update data from earlier filters.
         * @param array       $plugin_data Plugin headers.
         * @param string      $plugin_file Plugin basename.
         */
        public function filter_update($update, $plugin_data, $plugin_file, $locales = array()) {
            if ($plugin_file !== $this->basename || !empty($update)) {
                return $update;
            }

            $remote = $this->get_remote();
            if ($remote === null) {
                return $update;
            }

            // WordPress compares "version" with the installed one and shows the notice when it is higher.
            return array(
                'id' => $this->basename,
                'slug' => $this->slug,
                'plugin' => $this->basename,
                'version' => (string) $remote['version'],
                'url' => isset($remote['url']) ? (string) $remote['url'] : '',
                'package' => (string) $remote['package'],
                'tested' => isset($remote['tested']) ? (string) $remote['tested'] : '',
                'requires' => isset($remote['requires']) ? (string) $remote['requires'] : '',
                'requires_php' => isset($remote['requires_php']) ? (string) $remote['requires_php'] : '',
            );
        }

        /**
         * "Visualizza dettagli" popup.
         */
        public function filter_plugins_api($result, $action, $args) {
            if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) {
                return $result;
            }

            $remote = $this->get_remote();
            if ($remote === null) {
                return $result;
            }

            $data = function_exists('get_plugin_data') ? get_plugin_data($this->plugin_file, false, false) : array();
            $changelog = isset($remote['changelog']) && $remote['changelog'] !== '' ? (string) $remote['changelog'] : 'Nessun changelog disponibile.';

            return (object) array(
                'name' => isset($remote['name']) ? (string) $remote['name'] : (isset($data['Name']) ? $data['Name'] : $this->slug),
                'slug' => $this->slug,
                'version' => (string) $remote['version'],
                'author' => isset($data['Author']) ? $data['Author'] : '',
                'homepage' => isset($remote['url']) ? (string) $remote['url'] : '',
                'requires' => isset($remote['requires']) ? (string) $remote['requires'] : '',
                'requires_php' => isset($remote['requires_php']) ? (string) $remote['requires_php'] : '',
                'tested' => isset($remote['tested']) ? (string) $remote['tested'] : '',
                'last_updated' => isset($remote['released']) ? (string) $remote['released'] : '',
                'download_link' => (string) $remote['package'],
                'sections' => array(
                    'description' => isset($data['Description']) ? wp_kses_post($data['Description']) : '',
                    'changelog' => '<pre style="white-space:pre-wrap;">' . esc_html($changelog) . '</pre>',
                ),
            );
        }

        /**
         * Keeps the installed folder name, whatever the zip folder is called.
         *
         * Without this, a plugin installed as "foo (3)" would be reinstalled as
         * "foo" next to it and WordPress would deactivate it.
         */
        public function fix_source_folder($source, $remote_source, $upgrader, $hook_extra = array()) {
            global $wp_filesystem;

            if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename || !$wp_filesystem) {
                return $source;
            }

            $wanted = trailingslashit($remote_source) . dirname($this->basename);
            if (untrailingslashit($source) === untrailingslashit($wanted)) {
                return $source;
            }

            if ($wp_filesystem->move(untrailingslashit($source), $wanted, true)) {
                return trailingslashit($wanted);
            }

            return new WP_Error('area051_updater_rename', 'Impossibile rinominare la cartella del plugin aggiornato.');
        }

        public function filter_row_meta($links, $file) {
            if ($file !== $this->basename || !current_user_can('update_plugins')) {
                return $links;
            }

            $url = wp_nonce_url(admin_url('update-core.php?force-check=1'), 'upgrade-core');
            $links[] = '<a href="' . esc_url($url) . '">Controlla aggiornamenti GitHub</a>';

            $manifest = get_site_transient(self::CACHE_KEY);
            if (is_array($manifest) && !empty($manifest['error'])) {
                $links[] = '<span style="color:#b32d2e;">Aggiornamenti GitHub non raggiungibili: ' . esc_html($manifest['error']) . '</span>';
            }

            return $links;
        }
    }
}
