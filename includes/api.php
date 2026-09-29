<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Track one (or comma-separated) CN(s) via Leopards Courier API.
 *
 * Endpoint: GET {base}trackBookedPacket/format/json/
 * Params: api_key, api_password, track_numbers
 *
 * @param string $cn Single CN or comma-separated list.
 * @return array {success:bool, data?:array, error?:string, cached?:bool}
 */
function ltf_track_shipment($cn) {
    $cn = ltf_sanitize_cn($cn);

    if ($cn === '') {
        return array('success' => false, 'error' => __('Please enter a tracking number.', 'leopard-tracking-form'));
    }

    $creds = ltf_get_credentials();
    if (empty($creds['api_key']) || empty($creds['api_password'])) {
        return array(
            'success' => false,
            'error'   => __('Leopard API credentials are missing. Please configure them in Settings → Leopard Tracking.', 'leopard-tracking-form'),
        );
    }

    $settings = ltf_get_settings();
    $cache_minutes = max(0, absint($settings['cache_minutes']));
    $cache_key = 'ltf_track_' . md5($cn . ($creds['test_mode'] ? '_test' : '_live'));

    if ($cache_minutes > 0) {
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['success'])) {
            $cached['cached'] = true;
            return $cached;
        }
    }

    $base = ltf_api_base_url(!empty($creds['test_mode']));
    $url = add_query_arg(
        array(
            'api_key'       => $creds['api_key'],
            'api_password'  => $creds['api_password'],
            'track_numbers' => $cn,
        ),
        $base . 'trackBookedPacket/format/json/'
    );

    $response = wp_remote_get($url, array('timeout' => 20, 'sslverify' => true));

    if (is_wp_error($response)) {
        return array('success' => false, 'error' => $response->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);

    if ($code !== 200) {
        $msg = isset($json['error']) ? (string) $json['error'] : sprintf(__('Leopard API HTTP error %d.', 'leopard-tracking-form'), $code);
        return array('success' => false, 'error' => $msg);
    }

    if (!is_array($json)) {
        return array('success' => false, 'error' => __('Invalid response from Leopard API.', 'leopard-tracking-form'));
    }

    // Success shape: status = 1 / true, packet_list = [...].
    $status_ok = !empty($json['status']) && ($json['status'] == 1 || $json['status'] === true || $json['status'] === '1');
    if (!$status_ok) {
        $msg = isset($json['error']) ? (string) $json['error'] : __('No tracking information found for this number.', 'leopard-tracking-form');
        return array('success' => false, 'error' => $msg);
    }

    $packet_list = isset($json['packet_list']) && is_array($json['packet_list']) ? array_values($json['packet_list']) : array();

    if (empty($packet_list)) {
        return array('success' => false, 'error' => __('No tracking information found for this number.', 'leopard-tracking-form'));
    }

    $result = array(
        'success'     => true,
        'cn'          => $cn,
        'packets'     => $packet_list,
        'packet'      => $packet_list[0], // first packet for single-CN display
        'raw'         => $json,
        'credentials' => $creds['source'],
    );

    if ($cache_minutes > 0) {
        set_transient($cache_key, $result, $cache_minutes * MINUTE_IN_SECONDS);
    }

    return apply_filters('ltf_track_result', $result, $cn);
}

/**
 * Normalize a packet array into summary + history rows for the template.
 * Handles varying Leopard response key names gracefully.
 *
 * @param array $packet
 * @return array {summary:array, history:array}
 */
function ltf_normalize_packet($packet) {
    if (!is_array($packet)) {
        return array('summary' => array(), 'history' => array());
    }

    // Candidate keys for summary fields.
    $pick = function ($candidates) use ($packet) {
        foreach ((array) $candidates as $k) {
            foreach ($packet as $pk => $pv) {
                if (strtolower((string) $pk) === strtolower($k) && $pv !== '' && !is_array($pv)) {
                    return $pv;
                }
            }
        }
        return '';
    };

    $summary = array(
        'tracking_number' => $pick(array('track_number', 'tracking_number', 'cn_number', 'booked_packet_track_number', 'consignment_no')),
        'status'          => $pick(array('booked_packet_status', 'status', 'current_status', 'packet_status')),
        'origin'          => $pick(array('origin_city', 'origin', 'shipper_city', 'from_city')),
        'destination'     => $pick(array('destination_city', 'destination', 'consignee_city', 'to_city')),
        'booking_date'    => $pick(array('booked_packet_order_date', 'booking_date', 'order_date', 'created_at', 'date')),
        'shipper'         => $pick(array('shipment_name_eng', 'shipper_name', 'sender_name')),
        'consignee'       => $pick(array('consignment_name_eng', 'consignee_name', 'receiver_name')),
        'weight'          => $pick(array('booked_packet_weight', 'weight')),
        'pieces'          => $pick(array('booked_packet_no_piece', 'no_piece', 'pieces')),
        'collect_amount'  => $pick(array('booked_packet_collect_amount', 'collect_amount', 'cod_amount')),
        'order_id'        => $pick(array('booked_packet_order_id', 'order_id')),
    );

    // History: look for known nested arrays.
    $history_keys = array('tracking_detail', 'tracking_details', 'track_history', 'history', 'activities', 'detail', 'details', 'events', 'packet_track_list');
    $history = array();
    foreach ($packet as $pk => $pv) {
        if (is_array($pv) && in_array(strtolower((string) $pk), $history_keys, true)) {
            // Could be assoc or list; normalize to list of rows.
            if (ltf_is_assoc($pv) && isset($pv[0])) {
                $history = array_values($pv);
            } elseif (ltf_is_assoc($pv)) {
                $history = array($pv);
            } else {
                $history = $pv;
            }
            break;
        }
    }
    // Fallback: if packet itself looks like a list of events, use parent handling elsewhere.
    // Also support 'Activity' strings: convert to single row.
    if (empty($history)) {
        foreach (array('activity', 'remarks', 'comment') as $ak) {
            foreach ($packet as $pk => $pv) {
                if (strtolower((string) $pk) === $ak && is_string($pv) && $pv !== '') {
                    $history = array(array('activity' => $pv, 'date' => $summary['booking_date']));
                    break 2;
                }
            }
        }
    }

    return apply_filters('ltf_normalized_packet', array('summary' => $summary, 'history' => $history), $packet);
}

function ltf_is_assoc($arr) {
    if (!is_array($arr) || empty($arr)) {
        return false;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
}
