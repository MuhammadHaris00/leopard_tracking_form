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
?>
<form class="ltf-form" method="get" action="<?php echo esc_url($result_url); ?>" data-ltf-form>
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
