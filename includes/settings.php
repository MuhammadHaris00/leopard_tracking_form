<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'ltf_register_settings_page');
add_action('admin_init', 'ltf_register_settings');

/**
 * Settings page under Settings → Leopard Tracking.
 */
function ltf_register_settings_page() {
    add_options_page(
        __('Leopard Tracking', 'leopard-tracking-form'),
        __('Leopard Tracking', 'leopard-tracking-form'),
        'manage_options',
        'leopard-tracking',
        'ltf_render_settings_page'
    );
}

function ltf_register_settings() {
    register_setting('ltf_settings_group', LTF_OPTION, 'ltf_sanitize_settings');

    add_settings_section('ltf_api', __('Leopard Courier API', 'leopard-tracking-form'), 'ltf_section_api_cb', 'leopard-tracking');
    add_settings_field('api_key', __('API Key', 'leopard-tracking-form'), 'ltf_field_api_key_cb', 'leopard-tracking', 'ltf_api');
    add_settings_field('api_password', __('API Password', 'leopard-tracking-form'), 'ltf_field_api_password_cb', 'leopard-tracking', 'ltf_api');
    add_settings_field('test_mode', __('Test / Staging Mode', 'leopard-tracking-form'), 'ltf_field_test_mode_cb', 'leopard-tracking', 'ltf_api');
    add_settings_field('use_woo', __('Use WooCommerce add-on credentials', 'leopard-tracking-form'), 'ltf_field_use_woo_cb', 'leopard-tracking', 'ltf_api');

    add_settings_section('ltf_display', __('Display', 'leopard-tracking-form'), 'ltf_section_display_cb', 'leopard-tracking');
    add_settings_field('result_page', __('Tracking Result Page', 'leopard-tracking-form'), 'ltf_field_result_page_cb', 'leopard-tracking', 'ltf_display');
    add_settings_field('enable_ajax', __('Enable AJAX (inline result)', 'leopard-tracking-form'), 'ltf_field_ajax_cb', 'leopard-tracking', 'ltf_display');
    add_settings_field('cache', __('Cache duration (minutes)', 'leopard-tracking-form'), 'ltf_field_cache_cb', 'leopard-tracking', 'ltf_display');
}

function ltf_sanitize_settings($input) {
    $out = ltf_get_default_settings();
    if (isset($input['api_key'])) {
        $out['api_key'] = sanitize_text_field($input['api_key']);
    }
    if (isset($input['api_password'])) {
        $out['api_password'] = sanitize_text_field($input['api_password']);
    }
    $out['test_mode'] = !empty($input['test_mode']) ? 1 : 0;
    $out['use_woo_credentials'] = !empty($input['use_woo_credentials']) ? 1 : 0;
    $out['result_page_id'] = isset($input['result_page_id']) ? absint($input['result_page_id']) : 0;
    $out['enable_ajax'] = !empty($input['enable_ajax']) ? 1 : 0;
    $out['cache_minutes'] = isset($input['cache_minutes']) ? max(0, absint($input['cache_minutes'])) : 10;
    return $out;
}

function ltf_section_api_cb() {
    echo '<p>' . esc_html__('Enter your Leopards Courier merchant API credentials. If the Leopards Courier WooCommerce add-on is installed, its keys can be reused automatically.', 'leopard-tracking-form') . '</p>';
    $woo = ltf_detect_woo_leopard_credentials();
    if (!empty($woo['found'])) {
        echo '<div class="notice notice-success inline"><p>' . esc_html(sprintf(__('WooCommerce add-on credentials detected (%s). They will be used when the option below is enabled.', 'leopard-tracking-form'), $woo['source'])) . '</p></div>';
    } else {
        echo '<p><em>' . esc_html__('No WooCommerce Leopards add-on credentials detected. Using the keys saved here.', 'leopard-tracking-form') . '</em></p>';
    }
}

function ltf_section_display_cb() {
    echo '<p>' . esc_html__('Use shortcode [leopard_tracking_form] to show the form. Results render on the auto-generated page via [leopard_tracking_result], or inline with AJAX.', 'leopard-tracking-form') . '</p>';
}

function ltf_field_api_key_cb() {
    $s = ltf_get_settings();
    echo '<input type="text" name="' . esc_attr(LTF_OPTION) . '[api_key]" value="' . esc_attr($s['api_key']) . '" class="regular-text" autocomplete="off" />';
}

function ltf_field_api_password_cb() {
    $s = ltf_get_settings();
    echo '<input type="password" name="' . esc_attr(LTF_OPTION) . '[api_password]" value="' . esc_attr($s['api_password']) . '" class="regular-text" autocomplete="new-password" />';
}

function ltf_field_test_mode_cb() {
    $s = ltf_get_settings();
    echo '<label><input type="checkbox" name="' . esc_attr(LTF_OPTION) . '[test_mode]" value="1" ' . checked(1, $s['test_mode'], false) . ' /> ';
    esc_html_e('Use staging endpoint (merchantapistaging). Leave off for live.', 'leopard-tracking-form');
    echo '</label>';
}

function ltf_field_use_woo_cb() {
    $s = ltf_get_settings();
    echo '<label><input type="checkbox" name="' . esc_attr(LTF_OPTION) . '[use_woo_credentials]" value="1" ' . checked(1, $s['use_woo_credentials'], false) . ' /> ';
    esc_html_e('Prefer credentials from Leopards Courier WooCommerce add-on when found.', 'leopard-tracking-form');
    echo '</label>';
}

function ltf_field_result_page_cb() {
    $s = ltf_get_settings();
    wp_dropdown_pages(array(
        'name'              => LTF_OPTION . '[result_page_id]',
        'selected'          => $s['result_page_id'],
        'show_option_none'  => __('— Auto (leopard-tracking-result) —', 'leopard-tracking-form'),
        'option_none_value' => 0,
    ));
    echo '<p class="description">' . esc_html__('Page must contain the [leopard_tracking_result] shortcode.', 'leopard-tracking-form') . '</p>';
}

function ltf_field_ajax_cb() {
    $s = ltf_get_settings();
    echo '<label><input type="checkbox" name="' . esc_attr(LTF_OPTION) . '[enable_ajax]" value="1" ' . checked(1, $s['enable_ajax'], false) . ' /> ';
    esc_html_e('Show result inline below the form without page reload (falls back to redirect).', 'leopard-tracking-form');
    echo '</label>';
}

function ltf_field_cache_cb() {
    $s = ltf_get_settings();
    echo '<input type="number" min="0" max="1440" name="' . esc_attr(LTF_OPTION) . '[cache_minutes]" value="' . esc_attr($s['cache_minutes']) . '" class="small-text" /> ';
    esc_html_e('minutes (0 = no cache)', 'leopard-tracking-form');
}

function ltf_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Leopard Tracking Settings', 'leopard-tracking-form'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ltf_settings_group');
            do_settings_sections('leopard-tracking');
            submit_button();
            ?>
        </form>
        <hr />
        <h2><?php esc_html_e('Usage', 'leopard-tracking-form'); ?></h2>
        <p><code>[leopard_tracking_form]</code> — <?php esc_html_e('tracking form (redirects to result page, or inline via AJAX).', 'leopard-tracking-form'); ?></p>
        <p><code>[leopard_tracking_result]</code> — <?php esc_html_e('place on the result page; reads ?ltf_cn=NUMBER.', 'leopard-tracking-form'); ?></p>
        <p><?php esc_html_e('Templates can be overridden by copying templates/form.php and templates/result.php to your-theme/leopard-tracking/.', 'leopard-tracking-form'); ?></p>
    </div>
    <?php
}
