<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin adapter over WPML / Polylang.
 *
 * Detects which multilingual plugin is active and exposes the few operations
 * the importer needs: assign a language to a post and link posts together as
 * translations of each other.
 */
class SJAI_Multilang {
    const PROVIDER_NONE = 'none';
    const PROVIDER_POLYLANG = 'polylang';
    const PROVIDER_WPML = 'wpml';

    protected $provider = null;
    protected $languages = null;

    public function get_provider() {
        if ($this->provider !== null) {
            return $this->provider;
        }

        // WPML first: some sites keep Polylang functions around through compatibility layers.
        if (defined('ICL_SITEPRESS_VERSION') && has_filter('wpml_element_trid')) {
            $this->provider = self::PROVIDER_WPML;
        } elseif (function_exists('pll_set_post_language') && function_exists('pll_save_post_translations')) {
            $this->provider = self::PROVIDER_POLYLANG;
        } else {
            $this->provider = self::PROVIDER_NONE;
        }

        return $this->provider;
    }

    public function is_active() {
        return $this->get_provider() !== self::PROVIDER_NONE;
    }

    public function get_provider_label() {
        switch ($this->get_provider()) {
            case self::PROVIDER_WPML:
                return 'WPML';
            case self::PROVIDER_POLYLANG:
                return 'Polylang';
        }
        return '';
    }

    /**
     * Language codes configured in the multilingual plugin.
     *
     * @return string[]
     */
    public function get_languages() {
        if ($this->languages !== null) {
            return $this->languages;
        }

        $languages = array();
        $provider = $this->get_provider();

        if ($provider === self::PROVIDER_POLYLANG && function_exists('pll_languages_list')) {
            $list = pll_languages_list(array('fields' => 'slug'));
            if (is_array($list)) {
                $languages = $list;
            }
        } elseif ($provider === self::PROVIDER_WPML) {
            $list = apply_filters('wpml_active_languages', null, array('skip_missing' => 0));
            if (is_array($list)) {
                $languages = array_keys($list);
            }
        }

        $this->languages = array_values(array_filter(array_map('strval', $languages)));
        return $this->languages;
    }

    /**
     * Maps a Drupal langcode (it, en, pt-br...) to a configured WP language code.
     *
     * Returns '' when the language is not configured.
     */
    public function map_langcode($langcode) {
        $langcode = strtolower(trim((string) $langcode));
        if ($langcode === '') {
            return '';
        }

        $languages = $this->get_languages();
        $lower = array_map('strtolower', $languages);

        $variants = array($langcode, str_replace('_', '-', $langcode), str_replace('-', '_', $langcode));
        foreach ($variants as $variant) {
            $pos = array_search($variant, $lower, true);
            if ($pos !== false) {
                return $languages[$pos];
            }
        }

        // pt-br -> pt, de-ch -> de.
        $base = preg_split('/[-_]/', $langcode);
        if (!empty($base[0])) {
            $pos = array_search($base[0], $lower, true);
            if ($pos !== false) {
                return $languages[$pos];
            }
        }

        return '';
    }

    public function is_translated_post_type($post_type) {
        $provider = $this->get_provider();

        if ($provider === self::PROVIDER_POLYLANG && function_exists('pll_is_translated_post_type')) {
            return (bool) pll_is_translated_post_type($post_type);
        }

        if ($provider === self::PROVIDER_WPML) {
            $translated = apply_filters('wpml_is_translated_post_type', null, $post_type);
            return $translated === null ? true : (bool) $translated;
        }

        return false;
    }

    public function get_post_language($post_id) {
        $post_id = (int) $post_id;
        $provider = $this->get_provider();

        if ($provider === self::PROVIDER_POLYLANG && function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id, 'slug');
            return is_string($lang) ? $lang : '';
        }

        if ($provider === self::PROVIDER_WPML) {
            $details = apply_filters('wpml_post_language_details', null, $post_id);
            if (is_array($details) && !empty($details['language_code'])) {
                return (string) $details['language_code'];
            }
        }

        return '';
    }

    /**
     * Current translation group of a post as lang => post_id.
     */
    public function get_translations($post_id, $post_type) {
        $post_id = (int) $post_id;
        $provider = $this->get_provider();

        if ($provider === self::PROVIDER_POLYLANG && function_exists('pll_get_post_translations')) {
            $translations = pll_get_post_translations($post_id);
            return is_array($translations) ? array_map('intval', $translations) : array();
        }

        if ($provider === self::PROVIDER_WPML) {
            $element_type = 'post_' . $post_type;
            $trid = apply_filters('wpml_element_trid', null, $post_id, $element_type);
            if (!$trid) {
                return array();
            }
            $elements = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
            $translations = array();
            if (is_array($elements)) {
                foreach ($elements as $lang => $element) {
                    if (is_object($element) && !empty($element->element_id)) {
                        $translations[(string) $lang] = (int) $element->element_id;
                    }
                }
            }
            return $translations;
        }

        return array();
    }

    /**
     * Sets the language of a single post (no linking).
     */
    public function set_post_language($post_id, $lang, $post_type) {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || $lang === '') {
            return false;
        }

        $provider = $this->get_provider();

        if ($provider === self::PROVIDER_POLYLANG) {
            pll_set_post_language($post_id, $lang);
            return true;
        }

        if ($provider === self::PROVIDER_WPML) {
            $element_type = apply_filters('wpml_element_type', $post_type);
            $trid = apply_filters('wpml_element_trid', null, $post_id, 'post_' . $post_type);
            $current = $this->get_post_language($post_id);

            if ($current === $lang && $trid) {
                return true;
            }

            // Keep the current trid only if no other post in it already uses this language.
            if ($trid) {
                $group = $this->get_translations($post_id, $post_type);
                if (isset($group[$lang]) && (int) $group[$lang] !== $post_id) {
                    $trid = false;
                }
            }

            do_action('wpml_set_element_language_details', array(
                'element_id' => $post_id,
                'element_type' => $element_type,
                'trid' => $trid ? $trid : false,
                'language_code' => $lang,
                'source_language_code' => null,
            ));
            return true;
        }

        return false;
    }

    /**
     * Links posts as translations of each other.
     *
     * @param array  $group           lang => post_id. Languages must already be set.
     * @param string $post_type       Post type shared by all posts.
     * @param string $source_lang     Language of the original content (optional).
     *
     * @return array { linked: bool, conflicts: string[] }
     */
    public function link_translations($group, $post_type, $source_lang = '') {
        $result = array('linked' => false, 'conflicts' => array());

        $group = array_filter(array_map('intval', (array) $group));
        if (count($group) < 2) {
            return $result;
        }

        // Merge with translations already linked in the plugin, without overwriting slots.
        $merged = $group;
        foreach ($group as $lang => $post_id) {
            foreach ($this->get_translations($post_id, $post_type) as $existing_lang => $existing_id) {
                if (!isset($merged[$existing_lang])) {
                    $merged[$existing_lang] = $existing_id;
                } elseif ((int) $merged[$existing_lang] !== (int) $existing_id) {
                    $result['conflicts'][] = sprintf(
                        'Lingua %s: post %d già collegato, il gruppo importato propone %d. Tenuto %d.',
                        $existing_lang,
                        $existing_id,
                        $merged[$existing_lang],
                        $merged[$existing_lang]
                    );
                }
            }
        }

        $provider = $this->get_provider();

        if ($provider === self::PROVIDER_POLYLANG) {
            pll_save_post_translations($merged);
            $result['linked'] = true;
            return $result;
        }

        if ($provider === self::PROVIDER_WPML) {
            $element_type = apply_filters('wpml_element_type', $post_type);

            if ($source_lang === '' || !isset($merged[$source_lang])) {
                $source_lang = (string) array_key_first($merged);
            }

            $source_id = (int) $merged[$source_lang];
            $trid = apply_filters('wpml_element_trid', null, $source_id, 'post_' . $post_type);

            if (!$trid) {
                do_action('wpml_set_element_language_details', array(
                    'element_id' => $source_id,
                    'element_type' => $element_type,
                    'trid' => false,
                    'language_code' => $source_lang,
                    'source_language_code' => null,
                ));
                $trid = apply_filters('wpml_element_trid', null, $source_id, 'post_' . $post_type);
            }

            if (!$trid) {
                $result['conflicts'][] = 'WPML: impossibile ottenere il trid del post ' . $source_id;
                return $result;
            }

            foreach ($merged as $lang => $post_id) {
                if ((int) $post_id === $source_id) {
                    continue;
                }
                do_action('wpml_set_element_language_details', array(
                    'element_id' => (int) $post_id,
                    'element_type' => $element_type,
                    'trid' => $trid,
                    'language_code' => $lang,
                    'source_language_code' => $source_lang,
                ));
            }

            $result['linked'] = true;
            return $result;
        }

        return $result;
    }

    /**
     * Extra get_posts() args so lookups ignore the admin language filter.
     */
    public function unfiltered_query_args() {
        $args = array('suppress_filters' => true);
        if ($this->get_provider() === self::PROVIDER_POLYLANG) {
            $args['lang'] = '';
        }
        return $args;
    }
}
