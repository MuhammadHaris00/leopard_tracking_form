<?php
if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('leopard_tracking_form', 'ltf_shortcode_form');
add_shortcode('leopard_tracking_result', 'ltf_shortcode_result');
add_action('wp_ajax_ltf_track', 'ltf_ajax_track');
add_action('wp_ajax_nopriv_ltf_track', 'ltf_ajax_track');

/**
 * [leopard_tracking_form placeholder="..." button="..." show_result_inline="yes"]
 */
function ltf_shortcode_form($atts) {
    $atts = shortcode_atts(array(
        'placeholder'        => __('Enter tracking / CN number', 'leopard-tracking-form'),
        'button'             => __('Track Shipment', 'leopard-tracking-form'),
        'show_result_inline' => 'yes',
    ), $atts, 'leopard_tracking_form');

    // Ensure CSS/JS on pages using do_shortcode outside the loop.
    wp_enqueue_style('ltf-tracking', LTF_PLUGIN_URL . 'assets/css/leopard-tracking.css', array(), LTF_VERSION);
    $settings = ltf_get_settings();
    if (!empty($settings['enable_ajax'])) {
        wp_enqueue_script('ltf-tracking', LTF_PLUGIN_URL . 'assets/js/leopard-tracking.js', array(), LTF_VERSION, true);
        wp_localize_script('ltf-tracking', 'LTF_DATA', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('ltf_track_nonce'),
            'resultPage' => esc_url(ltf_get_result_page_url()),
        ));
    }

    $result_url = ltf_get_result_page_url();
    $prefill = ltf_sanitize_cn(isset($_GET['ltf_cn']) ? wp_unslash($_GET['ltf_cn']) : '');

    ob_start();
    $tpl = ltf_locate_template('form.php');
    if ($tpl) {
        include $tpl;
    }
    return ob_get_clean();
}

/**
 * [leopard_tracking_result] — reads ?ltf_cn= / ?tracking_number= / ?cn=
 */
function ltf_shortcode_result($atts) {
    wp_enqueue_style('ltf-tracking', LTF_PLUGIN_URL . 'assets/css/leopard-tracking.css', array(), LTF_VERSION);

    $raw = '';
    foreach (array('ltf_cn', 'tracking_number', 'cn', 'track_number') as $k) {
        if (!empty($_GET[$k])) {
            $raw = wp_unslash($_GET[$k]);
            break;
        }
    }
    $cn = ltf_sanitize_cn($raw);
    $result = null;
    $error = '';

    if ($cn !== '') {
        $lookup = ltf_track_shipment($cn);
        if (!empty($lookup['success'])) {
            $result = $lookup;
        } else {
            $error = isset($lookup['error']) ? $lookup['error'] : __('Tracking failed.', 'leopard-tracking-form');
        }
    }

    ob_start();
    $tpl = ltf_locate_template('result.php');
    if ($tpl) {
        include $tpl;
    }
    return ob_get_clean();
}

/**
 * AJAX: action=ltf_track, POST cn + nonce → JSON {success, html}
 */
function ltf_ajax_track() {
    check_ajax_referer('ltf_track_nonce', 'nonce');

    $cn = ltf_sanitize_cn(isset($_POST['cn']) ? wp_unslash($_POST['cn']) : '');
    if ($cn === '') {
        wp_send_json_error(array('message' => __('Please enter a tracking number.', 'leopard-tracking-form')));
    }

    $lookup = ltf_track_shipment($cn);
    if (empty($lookup['success'])) {
        wp_send_json_error(array('message' => isset($lookup['error']) ? $lookup['error'] : __('Tracking failed.', 'leopard-tracking-form')));
    }

    $result = $lookup;
    $error = '';
    ob_start();
    $tpl = ltf_locate_template('result.php');
    if ($tpl) {
        // In AJAX mode hide the redundant form heading inside result template.
        $ltf_ajax_mode = true;
        include $tpl;
    }
    $html = ob_get_clean();

    wp_send_json_success(array('html' => $html, 'cn' => $cn));
}
