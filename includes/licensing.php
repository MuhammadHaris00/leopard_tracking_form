<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * License client for Haris License Manager.
 * Server: https://licenses.yourdomain.com (self-hosted, see haris-license-manager/)
 * Option: ltf_license {server, key, token, public_key, status, last_check, message}
 */

define('LTF_LICENSE_OPTION', 'ltf_license');
define('LTF_PRODUCT', 'leopard-tracking'); // must match `product` on product-scoped keys ('' = universal)

function ltf_license_product() {
    return apply_filters('ltf_license_product', LTF_PRODUCT);
}

function ltf_license_defaults() {
    return array(
        'server'      => '',
        'key'         => '',
        'token'       => '',
        'public_key'  => '',
        'status'      => 'inactive', // active | inactive | invalid
        'last_check'  => 0,
        'message'     => '',
    );
}

function ltf_license_get() {
    $saved = get_option(LTF_LICENSE_OPTION, array());
    if (!is_array($saved)) {
        $saved = array();
    }
    return wp_parse_args($saved, ltf_license_defaults());
}

function ltf_license_domain() {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    $host = strtolower((string) $host);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return $host;
}

/** Verify JWT exp locally (signature verified server-side + via openssl below). */
function ltf_license_token_expired($token) {
    $parts = explode('.', (string) $token);
    if (count($parts) !== 3) {
        return true;
    }
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!is_array($payload) || empty($payload['exp'])) {
        return true;
    }
    return ((int) $payload['exp']) < time();
}

function ltf_license_verify_signature($token, $public_key_pem) {
    if (empty($token) || empty($public_key_pem) || !function_exists('openssl_verify')) {
        return null; // cannot verify locally -> rely on server
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    $signing_input = $parts[0] . '.' . $parts[1];
    $sig = base64_decode(strtr($parts[2], '-_', '+/'));
    $pub = openssl_pkey_get_public($public_key_pem);
    if (!$pub) {
        return null;
    }
    $ok = openssl_verify($signing_input, $sig, $pub, OPENSSL_ALGO_SHA256);
    return $ok === 1;
}

/**
 * Single gate used by tracking + shortcodes.
 * Grace period: cached token valid up to 7 days without server contact.
 */
function ltf_license_valid() {
    $lic = ltf_license_get();
    // Allow dev bypass: define('LTF_SKIP_LICENSE', true) in wp-config for localhost.
    if (defined('LTF_SKIP_LICENSE') && LTF_SKIP_LICENSE) {
        return true;
    }
    if (empty($lic['key']) || empty($lic['token']) || $lic['status'] !== 'active') {
        return apply_filters('ltf_license_valid', false);
    }
    if (ltf_license_token_expired($lic['token'])) {
        // Try silent re-validate (weekly heartbeat).
        $re = ltf_license_heartbeat();
        return apply_filters('ltf_license_valid', !empty($re['valid']));
    }
    return apply_filters('ltf_license_valid', true);
}

function ltf_license_heartbeat() {
    $lic = ltf_license_get();
    if (empty($lic['server']) || empty($lic['token'])) {
        return array('valid' => false, 'error' => 'no license');
    }
    // Throttle: max once per hour unless forced.
    if (!empty($lic['last_check']) && (time() - (int) $lic['last_check']) < HOUR_IN_SECONDS && ltf_license_token_expired($lic['token']) === false) {
        return array('valid' => $lic['status'] === 'active');
    }
    $res = wp_remote_post(trailingslashit($lic['server']) . 'v1/validate', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array('license_token' => $lic['token'], 'domain' => ltf_license_domain(), 'product' => ltf_license_product())),
    ));
    if (is_wp_error($res)) {
        // Offline: honor grace period if token not expired.
        if (!ltf_license_token_expired($lic['token'])) {
            return array('valid' => true, 'grace' => true);
        }
        return array('valid' => false, 'error' => $res->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);
    if ($code === 200 && !empty($json['valid'])) {
        $lic['status'] = 'active';
        $lic['last_check'] = time();
        $lic['message'] = '';
        update_option(LTF_LICENSE_OPTION, $lic);
        return array('valid' => true);
    }
    $lic['status'] = 'invalid';
    $lic['last_check'] = time();
    $lic['message'] = is_array($json) && isset($json['detail']) ? (string) $json['detail'] : 'license invalid';
    update_option(LTF_LICENSE_OPTION, $lic);
    return array('valid' => false, 'error' => $lic['message']);
}

function ltf_license_activate($key, $server) {
    $server = untrailingslashit(trim((string) $server));
    $key = strtoupper(trim((string) $key));
    if ($server === '' || $key === '') {
        return array('success' => false, 'error' => 'Server URL and license key required.');
    }
    $res = wp_remote_post($server . '/v1/activate', array(
        'timeout' => 20,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array('license_key' => $key, 'domain' => ltf_license_domain(), 'product' => ltf_license_product())),
    ));
    if (is_wp_error($res)) {
        return array('success' => false, 'error' => $res->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);
    if ($code !== 200 || empty($json['license_token'])) {
        $msg = is_array($json) && isset($json['detail']) ? (string) $json['detail'] : 'Activation failed (HTTP ' . $code . ').';
        $lic = ltf_license_get();
        $lic['server'] = $server;
        $lic['key'] = $key;
        $lic['status'] = 'invalid';
        $lic['message'] = $msg;
        update_option(LTF_LICENSE_OPTION, $lic);
        return array('success' => false, 'error' => $msg);
    }
    // Fetch public key for offline verify.
    $pub = '';
    $pk = wp_remote_get($server . '/v1/public-key', array('timeout' => 15));
    if (!is_wp_error($pk) && (int) wp_remote_retrieve_response_code($pk) === 200) {
        $pub = wp_remote_retrieve_body($pk);
    }
    $lic = array(
        'server'     => $server,
        'key'        => $key,
        'token'      => (string) $json['license_token'],
        'public_key' => $pub,
        'status'     => 'active',
        'last_check' => time(),
        'message'    => '',
    );
    update_option(LTF_LICENSE_OPTION, $lic);
    return array('success' => true, 'domain' => ltf_license_domain());
}

function ltf_license_deactivate_remote() {
    $lic = ltf_license_get();
    if (!empty($lic['server']) && !empty($lic['key'])) {
        wp_remote_post(trailingslashit($lic['server']) . 'v1/deactivate', array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('license_key' => $lic['key'], 'domain' => ltf_license_domain(), 'product' => ltf_license_product())),
        ));
    }
    update_option(LTF_LICENSE_OPTION, ltf_license_defaults());
}

/**
 * Tracking proxy: ask license server for shipment data.
 * Leopard api_key/password live ONLY on the server — never on client sites.
 *
 * @param string $cn Sanitized CN.
 * @return array {success:bool, ...} WP-shaped result, or error array.
 */
function ltf_proxy_track($cn) {
    $lic = ltf_license_get();
    if (empty($lic['server']) || empty($lic['token'])) {
        return array('success' => false, 'error' => __('License server not configured.', 'leopard-tracking-form'));
    }
    $res = wp_remote_post(trailingslashit($lic['server']) . 'v1/track', array(
        'timeout' => 25,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'license_token' => $lic['token'],
            'domain'        => ltf_license_domain(),
            'product'       => ltf_license_product(),
            'cn'            => $cn,
        )),
    ));
    if (is_wp_error($res)) {
        return array('success' => false, 'error' => $res->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);
    if ($code === 401 || $code === 403) {
        // License revoked/expired server-side: reflect locally so admin sees it.
        $lic['status'] = 'invalid';
        $lic['last_check'] = time();
        $lic['message'] = is_array($json) && isset($json['detail']) ? (string) $json['detail'] : 'license invalid';
        update_option(LTF_LICENSE_OPTION, $lic);
    }
    if ($code !== 200 || !is_array($json)) {
        $msg = is_array($json) && isset($json['detail']) ? (string) $json['detail'] : sprintf(__('Tracking server error (HTTP %d).', 'leopard-tracking-form'), $code);
        return array('success' => false, 'error' => $msg);
    }
    if (empty($json['success']) || empty($json['packets'])) {
        return array('success' => false, 'error' => __('No tracking information found for this number.', 'leopard-tracking-form'));
    }
    $json['credentials'] = 'license-server-proxy';
    return apply_filters('ltf_track_result', $json, $cn);
}

/** Whether to route tracking through the license server (default: yes when configured). */
function ltf_use_tracking_proxy() {
    $lic = ltf_license_get();
    $default = !empty($lic['server']) && !empty($lic['token']);
    return apply_filters('ltf_use_tracking_proxy', $default);
}

// ---- Admin UI: license section on same Leopard Tracking settings page ----
add_action('admin_init', 'ltf_license_admin_init');
function ltf_license_admin_init() {
    register_setting('ltf_license_group', LTF_LICENSE_OPTION);
    add_settings_section('ltf_license_sec', __('License', 'leopard-tracking-form'), 'ltf_license_section_cb', 'leopard-tracking');
    add_settings_field('ltf_license_fields', __('License server + key', 'leopard-tracking-form'), 'ltf_license_fields_cb', 'leopard-tracking', 'ltf_license_sec');
}

function ltf_license_section_cb() {
    echo '<p>' . esc_html__('Activate with a key from your license server. Tracking is disabled until license is active.', 'leopard-tracking-form') . '</p>';
}

function ltf_license_fields_cb() {
    $lic = ltf_license_get();
    $status = $lic['status'] === 'active' ? '<span style="color:#15803d;font-weight:700">active</span>' : '<span style="color:#b91c1c;font-weight:700">' . esc_html($lic['status']) . '</span>';
    ?>
    <input type="text" name="<?php echo esc_attr(LTF_LICENSE_OPTION); ?>[server]" value="<?php echo esc_attr($lic['server']); ?>" class="regular-text" placeholder="https://licenses.yourdomain.com" /><br />
    <input type="text" name="<?php echo esc_attr(LTF_LICENSE_OPTION); ?>[key]" value="<?php echo esc_attr($lic['key']); ?>" class="regular-text" placeholder="LTF-XXXX-XXXX-XXXX" style="margin-top:4px" /><br />
    <p class="description">Status: <?php echo $status; ?> · Domain: <code><?php echo esc_html(ltf_license_domain()); ?></code>
    <?php if (!empty($lic['message'])) echo ' · ' . esc_html($lic['message']); ?></p>
    <?php
}

// Handle activate/deactivate buttons (posted to options page via admin-post).
add_action('admin_post_ltf_license_activate', 'ltf_license_handle_activate');
function ltf_license_handle_activate() {
    if (!current_user_can('manage_options') || !check_admin_referer('ltf_license_action')) {
        wp_die('forbidden');
    }
    $posted = isset($_POST[LTF_LICENSE_OPTION]) && is_array($_POST[LTF_LICENSE_OPTION]) ? $_POST[LTF_LICENSE_OPTION] : array();
    $server = isset($posted['server']) ? esc_url_raw($posted['server']) : '';
    $key = isset($posted['key']) ? sanitize_text_field($posted['key']) : '';
    // Persist server+key first so fields survive errors.
    $lic = ltf_license_get();
    $lic['server'] = $server;
    $lic['key'] = $key;
    update_option(LTF_LICENSE_OPTION, $lic);

    $result = ltf_license_activate($key, $server);
    $loc = add_query_arg(array(
        'page' => 'leopard-tracking',
        'ltf_license' => $result['success'] ? 'activated' : 'failed',
        'msg' => $result['success'] ? '' : rawurlencode($result['error']),
    ), admin_url('options-general.php'));
    // Options page is under Settings; our page slug renders there via settings API.
    wp_redirect(admin_url('options-general.php?page=leopard-tracking&ltf_license=' . ($result['success'] ? 'activated' : 'failed')));
    exit;
}

add_action('admin_post_ltf_license_deactivate', 'ltf_license_handle_deactivate');
function ltf_license_handle_deactivate() {
    if (!current_user_can('manage_options') || !check_admin_referer('ltf_license_action')) {
        wp_die('forbidden');
    }
    ltf_license_deactivate_remote();
    wp_redirect(admin_url('options-general.php?page=leopard-tracking&ltf_license=deactivated'));
    exit;
}

// Show activate/deactivate buttons + notices on settings page.
add_action('admin_notices', 'ltf_license_admin_notices');
function ltf_license_admin_notices() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'settings_page_leopard-tracking') {
        return;
    }
    $lic = ltf_license_get();
    $action_url_a = admin_url('admin-post.php?action=ltf_license_activate');
    $action_url_d = admin_url('admin-post.php?action=ltf_license_deactivate');
    echo '<div style="background:#fff;border:1px solid #e2e8f0;padding:12px;margin:12px 0;max-width:640px">';
    echo '<strong>License:</strong> ' . ($lic['status'] === 'active' ? '<span style="color:#15803d">active</span>' : '<span style="color:#b91c1c">inactive</span>');
    echo ' <form method="post" action="' . esc_url($action_url_a) . '" style="display:inline;margin-left:8px">';
    wp_nonce_field('ltf_license_action');
    echo '<input type="hidden" name="' . esc_attr(LTF_LICENSE_OPTION) . '[server]" value="' . esc_attr($lic['server']) . '">';
    echo '<input type="hidden" name="' . esc_attr(LTF_LICENSE_OPTION) . '[key]" value="' . esc_attr($lic['key']) . '">';
    submit_button(__('Activate', 'leopard-tracking-form'), 'primary', 'submit', false);
    echo '</form> <form method="post" action="' . esc_url($action_url_d) . '" style="display:inline;margin-left:8px">';
    wp_nonce_field('ltf_license_action');
    submit_button(__('Deactivate', 'leopard-tracking-form'), 'secondary', 'submit', false);
    echo '</form></div>';
    if (isset($_GET['ltf_license']) && $_GET['ltf_license'] === 'failed') {
        echo '<div class="notice notice-error"><p>Activation failed. Check server URL / key / domain limit.</p></div>';
    }
}

// Weekly heartbeat.
add_action('ltf_license_weekly_cron', 'ltf_license_heartbeat');
if (!wp_next_scheduled('ltf_license_weekly_cron')) {
    wp_schedule_event(time() + WEEK_IN_SECONDS, 'weekly', 'ltf_license_weekly_cron');
}
