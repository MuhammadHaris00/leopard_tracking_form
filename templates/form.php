<?php
/**
 * Editable template: tracking form.
 * Override: copy to your-theme/leopard-tracking/form.php
 *
 * Variables: $atts, $result_url, $prefill, $settings
 */
if (!defined('ABSPATH')) {
    exit;
}

// Split $result_url into a query-less action + hidden fields.
// Browsers drop the action's own query string on method="get" submits,
// which breaks plain permalinks (?page_id=123) and any tracked query
// vars — the form then "just reloads" with only ?ltf_cn=....
$form_action = $result_url;
$form_hidden = array();
$parts = wp_parse_url((string) $result_url);
if (is_array($parts) && !empty($parts['query'])) {
    parse_str($parts['query'], $form_hidden);
    // Rebuild action without query string.
    $form_action = '';
    if (!empty($parts['scheme'])) {
        $form_action .= $parts['scheme'] . '://';
    }
    if (!empty($parts['host'])) {
        $form_action .= $parts['host'];
    }
    if (!empty($parts['port'])) {
        $form_action .= ':' . $parts['port'];
    }
    if (!empty($parts['path'])) {
        $form_action .= $parts['path'];
    }
    if ($form_action === '') {
        $form_action = $result_url;
        $form_hidden = array();
    }
}
?>
<form class="ltf-form" method="get" action="<?php echo esc_url($form_action); ?>" data-ltf-form>
    <?php foreach ($form_hidden as $hk => $hv) : ?>
        <input type="hidden" name="<?php echo esc_attr((string) $hk); ?>" value="<?php echo esc_attr(is_scalar($hv) ? (string) $hv : ''); ?>" />
    <?php endforeach; ?>
    <label class="ltf-label" for="ltf-cn"><?php esc_html_e('Tracking Number', 'leopard-tracking-form'); ?></label>
    <div class="ltf-row">
        <input
            id="ltf-cn"
            class="ltf-input"
            type="text"
            name="ltf_cn"
            value="<?php echo esc_attr($prefill); ?>"
            placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
            required
            autocomplete="off"
            spellcheck="false"
        />
        <button class="ltf-button" type="submit"><?php echo esc_html($atts['button']); ?></button>
    </div>
    <p class="ltf-hint"><?php esc_html_e('Enter your Leopards Courier CN number (e.g. from your receipt or SMS).', 'leopard-tracking-form'); ?></p>
    <div class="ltf-inline-result" data-ltf-inline-result aria-live="polite"></div>
</form>
