<?php
/**
 * Plugin Name:       Leopard Tracking Form
 * Description:       Track Leopards Courier (Pakistan) shipments via shortcode. Auto-generated result page, AJAX + redirect modes, separate CSS, editable templates.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Muhammad Haris
 * License:           None
 * Text Domain:       leopard-tracking-form
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LTF_VERSION', '1.0.0');
define('LTF_PLUGIN_FILE', __FILE__);
define('LTF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LTF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LTF_OPTION', 'ltf_settings');

require_once LTF_PLUGIN_DIR . 'includes/api.php';
require_once LTF_PLUGIN_DIR . 'includes/settings.php';
require_once LTF_PLUGIN_DIR . 'includes/shortcodes.php';

/**
 * Default settings.
 *
 * @return array
 */
function ltf_get_default_settings() {
    return array(
        'api_key'             => '',
        'api_password'        => '',
        'test_mode'           => 0, // 0 = live, 1 = staging
        'use_woo_credentials' => 1, // prefer Woo Leopards add-on when found
        'result_page_id'      => 0,
        'enable_ajax'         => 1,
        'cache_minutes'       => 10,
    );
}

/**
 * Get merged settings.
 *
 * @return array
 */
function ltf_get_settings() {
    $defaults = ltf_get_default_settings();
    $saved = get_option(LTF_OPTION, array());
    if (!is_array($saved)) {
        $saved = array();
    }
    return wp_parse_args($saved, $defaults);
}

/**
 * Live vs staging base URL.
 */
function ltf_api_base_url($test_mode = false) {
    if ($test_mode) {
        return 'https://merchantapistaging.leopardscourier.com/api/';
    }
    return 'https://merchantapi.leopardscourier.com/api/';
}

/**
 * Try to detect credentials from a Leopards Courier WooCommerce add-on,
 * if installed/active. Scans known option keys + wildcard DB scan.
 *
 * @return array {found:bool, api_key:string, api_password:string, source:string}
 */
function ltf_detect_woo_leopard_credentials() {
    $result = array(
        'found'        => false,
        'api_key'      => '',
        'api_password' => '',
        'source'       => '',
    );

    // 1) Known scalar option keys.
    $key_candidates = array(
        'leopards_api_key', 'leopards_api_password',
        'leopard_api_key', 'leopard_api_password',
        'lcs_api_key', 'lcs_api_password',
        'wc_leopards_api_key', 'wc_leopards_api_password',
        'wc_leopard_api_key', 'wc_leopard_api_password',
        'woocommerce_leopards_api_key', 'woocommerce_leopards_api_password',
        'leopards_courier_api_key', 'leopards_courier_api_password',
        'leopardscourier_api_key', 'leopardscourier_api_password',
    );
    $found_key = '';
    $found_pass = '';
    $found_source = '';
    foreach ($key_candidates as $opt) {
        $val = get_option($opt, '');
        if (is_string($val) && $val !== '') {
            if (stripos($opt, 'password') !== false && !$found_pass) {
                $found_pass = $val;
                $found_source = "option:{$opt}";
            } elseif (stripos($opt, 'key') !== false && !$found_key) {
                $found_key = $val;
                $found_source = "option:{$opt}";
            }
        }
    }

    // 2) Known array option keys (settings arrays).
    if (!$found_key || !$found_pass) {
        $array_candidates = array(
            'leopards_courier_settings',
            'leopards_settings',
            'leopard_settings',
            'lcs_settings',
            'wc_leopards_settings',
            'woocommerce_leopards_settings',
            'woocommerce_leopards_courier_settings',
            'leopards_courier_woo_settings',
        );
        foreach ($array_candidates as $opt) {
            $arr = get_option($opt, null);
            if (is_array($arr)) {
                $k = ltf_array_find_credential($arr, 'key');
                $p = ltf_array_find_credential($arr, 'password');
                if ($k && !$found_key) {
                    $found_key = $k;
                    $found_source = "option:{$opt} (array)";
                }
                if ($p && !$found_pass) {
                    $found_pass = $p;
                    $found_source = "option:{$opt} (array)";
                }
            }
        }
    }

    // 3) Wildcard DB scan for anything with "leopard" in option name.
    if ((!$found_key || !$found_pass) && function_exists('get_option')) {
        global $wpdb;
        if (isset($wpdb->options)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%leopard%' OR option_name LIKE '%lcs_%' LIMIT 100",
                ARRAY_A
            );
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $name = isset($row['option_name']) ? $row['option_name'] : '';
                    // Skip our own option to avoid false positives.
                    if ($name === LTF_OPTION) {
                        continue;
                    }
                    $raw = isset($row['option_value']) ? $row['option_value'] : '';
                    $val = maybe_unserialize($raw);
                    if (is_array($val)) {
                        $k = ltf_array_find_credential($val, 'key');
                        $p = ltf_array_find_credential($val, 'password');
                        if ($k && !$found_key) {
                            $found_key = $k;
                            $found_source = "option:{$name} (auto)";
                        }
                        if ($p && !$found_pass) {
                            $found_pass = $p;
                            $found_source = "option:{$name} (auto)";
                        }
                    } elseif (is_string($val) && $val !== '') {
                        if (stripos($name, 'password') !== false && !$found_pass) {
                            $found_pass = $val;
                            $found_source = "option:{$name}";
                        } elseif (stripos($name, 'api_key') !== false && !$found_key) {
                            $found_key = $val;
                            $found_source = "option:{$name}";
                        }
                    }
                    if ($found_key && $found_pass) {
                        break;
                    }
                }
            }
        }
    }

    // 4) Constants fallback (some setups define keys in wp-config).
    if (!$found_key && defined('LEOPARDS_API_KEY')) {
        $found_key = (string) LEOPARDS_API_KEY;
        $found_source = 'constant:LEOPARDS_API_KEY';
    }
    if (!$found_key && defined('LEOPARD_API_KEY')) {
        $found_key = (string) LEOPARD_API_KEY;
        $found_source = 'constant:LEOPARD_API_KEY';
    }
    if (!$found_pass && defined('LEOPARDS_API_PASSWORD')) {
        $found_pass = (string) LEOPARDS_API_PASSWORD;
        $found_source = 'constant:LEOPARDS_API_PASSWORD';
    }
    if (!$found_pass && defined('LEOPARD_API_PASSWORD')) {
        $found_pass = (string) LEOPARD_API_PASSWORD;
        $found_source = 'constant:LEOPARD_API_PASSWORD';
    }

    if ($found_key && $found_pass) {
        $result['found'] = true;
        $result['api_key'] = $found_key;
        $result['api_password'] = $found_pass;
        $result['source'] = $found_source;
    } elseif ($found_key || $found_pass) {
        $result['api_key'] = $found_key;
        $result['api_password'] = $found_pass;
        $result['source'] = $found_source . ' (partial)';
    }

    return apply_filters('ltf_detected_woo_credentials', $result);
}

/**
 * Recursively search array for api key / password-ish keys.
 */
function ltf_array_find_credential($arr, $type = 'key') {
    $wanted = ($type === 'password')
        ? array('api_password', 'apipassword', 'password', 'api_secret', 'secret')
        : array('api_key', 'apikey', 'key');
    foreach ($arr as $k => $v) {
        if (is_array($v)) {
            $inner = ltf_array_find_credential($v, $type);
            if ($inner) {
                return is_string($inner) ? trim($inner) : $inner;
            }
            continue;
        }
        $nk = strtolower((string) $k);
        foreach ($wanted as $w) {
            if ($nk === $w || strpos($nk, $w) !== false) {
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }
    }
    return '';
}

/**
 * Effective credentials: Woo add-on first (if enabled + found), else own settings.
 *
 * @return array {api_key, api_password, test_mode, source}
 */
function ltf_get_credentials() {
    $s = ltf_get_settings();
    $creds = array(
        'api_key'      => trim((string) $s['api_key']),
        'api_password' => (string) $s['api_password'],
        'test_mode'    => !empty($s['test_mode']) ? 1 : 0,
        'source'       => 'plugin-settings',
    );

    if (!empty($s['use_woo_credentials'])) {
        $woo = ltf_detect_woo_leopard_credentials();
        if (!empty($woo['found'])) {
            $creds['api_key'] = $woo['api_key'];
            $creds['api_password'] = $woo['api_password'];
            $creds['source'] = 'woocommerce-addon (' . $woo['source'] . ')';
        }
    }

    return apply_filters('ltf_api_credentials', $creds);
}

/**
 * Locate editable template, allowing theme override:
 * your-theme/leopard-tracking/form.php or result.php
 */
function ltf_locate_template($template) {
    $theme_file = locate_template(array('leopard-tracking/' . $template));
    if ($theme_file) {
        return $theme_file;
    }
    $plugin_file = LTF_PLUGIN_DIR . 'templates/' . $template;
    if (file_exists($plugin_file)) {
        return $plugin_file;
    }
    return '';
}

/**
 * Enqueue assets only when a shortcode is present.
 */
function ltf_maybe_enqueue_assets($posts) {
    if (is_admin() || empty($posts)) {
        return $posts;
    }
    $found = false;
    foreach ($posts as $post) {
        if (is_object($post) && isset($post->post_content) &&
            (has_shortcode($post->post_content, 'leopard_tracking_form') ||
             has_shortcode($post->post_content, 'leopard_tracking_result'))) {
            $found = true;
            break;
        }
    }
    if ($found) {
        wp_enqueue_style('ltf-tracking', LTF_PLUGIN_URL . 'assets/css/leopard-tracking.css', array(), LTF_VERSION);
        $s = ltf_get_settings();
        if (!empty($s['enable_ajax'])) {
            wp_enqueue_script('ltf-tracking', LTF_PLUGIN_URL . 'assets/js/leopard-tracking.js', array(), LTF_VERSION, true);
            wp_localize_script('ltf-tracking', 'LTF_DATA', array(
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('ltf_track_nonce'),
                'resultPage' => esc_url(ltf_get_result_page_url()),
            ));
        }
    }
    return $posts;
}
add_filter('the_posts', 'ltf_maybe_enqueue_assets');

/**
 * Result page ID from settings, fallback to auto-created page.
 */
function ltf_get_result_page_id() {
    $s = ltf_get_settings();
    $id = absint($s['result_page_id']);
    if ($id && get_post_status($id)) {
        return $id;
    }
    $page = get_page_by_path('leopard-tracking-result');
    if ($page) {
        return (int) $page->ID;
    }
    return 0;
}

/**
 * Result page URL from settings, fallback to auto-created page.
 */
function ltf_get_result_page_url() {
    $id = ltf_get_result_page_id();
    if ($id) {
        return get_permalink($id);
    }
    $page = get_page_by_path('leopard-tracking-result');
    if ($page) {
        return get_permalink($page);
    }
    return home_url('/');
}

/**
 * Non-AJAX safety net: if ?ltf_cn lands on any page other than the
 * result page (e.g. stale form action, plain permalinks dropping
 * ?page_id=, cached HTML), redirect to the result page preserving CN.
 *
 * Without this, method="get" forms submit to home_url('/') with only
 * ?ltf_cn=... and appear to "just reload the page".
 */
function ltf_maybe_redirect_to_result_page() {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    if (empty($_GET['ltf_cn'])) {
        return;
    }
    // Only handle GET form submissions.
    if (!empty($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'GET') {
        return;
    }

    $cn = ltf_sanitize_cn(wp_unslash($_GET['ltf_cn']));
    if ($cn === '') {
        return;
    }

    $result_id = ltf_get_result_page_id();
    if (!$result_id) {
        return;
    }
    if (is_page($result_id)) {
        return;
    }
    // Custom result page (shortcode present but ID not saved in settings): stay.
    global $post;
    if (is_object($post) && isset($post->post_content) &&
        (has_shortcode($post->post_content, 'leopard_tracking_result'))) {
        return;
    }

    $url = get_permalink($result_id);
    if (!$url) {
        return;
    }
    wp_safe_redirect(add_query_arg('ltf_cn', $cn, $url), 302);
    exit;
}
add_action('template_redirect', 'ltf_maybe_redirect_to_result_page');

/**
 * Sanitize a tracking number / comma list.
 */
function ltf_sanitize_cn($raw) {
    $raw = strtoupper(trim((string) $raw));
    // Allow letters, digits, dash, comma, space.
    $raw = preg_replace('/[^A-Z0-9,\-\s]/', '', $raw);
    $raw = preg_replace('/\s+/', '', (string) $raw);
    return substr((string) $raw, 0, 200);
}

/**
 * Activation: defaults + auto-create result page.
 */
function ltf_activate() {
    $defaults = ltf_get_default_settings();
    $saved = get_option(LTF_OPTION, null);
    if (!is_array($saved)) {
        add_option(LTF_OPTION, $defaults);
    } else {
        update_option(LTF_OPTION, wp_parse_args($saved, $defaults));
    }

    $existing = get_page_by_path('leopard-tracking-result');
    if (!$existing) {
        $page_id = wp_insert_post(array(
            'post_title'   => __('Leopard Tracking Result', 'leopard-tracking-form'),
            'post_name'    => 'leopard-tracking-result',
            'post_content' => '[leopard_tracking_result]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ));
        if ($page_id && !is_wp_error($page_id)) {
            $opts = ltf_get_settings();
            $opts['result_page_id'] = $page_id;
            update_option(LTF_OPTION, $opts);
        }
    } else {
        $opts = ltf_get_settings();
        if (empty($opts['result_page_id'])) {
            $opts['result_page_id'] = $existing->ID;
            update_option(LTF_OPTION, $opts);
        }
    }
}
register_activation_hook(__FILE__, 'ltf_activate');
