<?php
/**
 * Editable template: tracking result.
 * Override: copy to your-theme/leopard-tracking/result.php
 *
 * Variables: $cn (string), $result (array|null), $error (string)
 *            $ltf_ajax_mode (bool, set in AJAX context)
 */
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="ltf-result" data-ltf-result>
    <?php if ($cn === '') : ?>
        <p class="ltf-empty"><?php esc_html_e('Enter a tracking number above to see shipment details.', 'leopard-tracking-form'); ?></p>
    <?php elseif ($error !== '') : ?>
        <div class="ltf-error" role="alert">
            <strong><?php esc_html_e('Tracking failed', 'leopard-tracking-form'); ?></strong>
            <p><?php echo esc_html($error); ?> (<?php echo esc_html($cn); ?>)</p>
        </div>
    <?php elseif (!empty($result['packets'])) : ?>
        <?php foreach ($result['packets'] as $packet) :
            $norm = ltf_normalize_packet(is_array($packet) ? $packet : array());
            $summary = $norm['summary'];
            $history = $norm['history'];
        ?>
        <article class="ltf-card">
            <header class="ltf-card-head">
                <div>
                    <span class="ltf-card-label"><?php esc_html_e('Tracking Number', 'leopard-tracking-form'); ?></span>
                    <h2 class="ltf-card-cn"><?php echo esc_html($summary['tracking_number'] !== '' ? $summary['tracking_number'] : $cn); ?></h2>
                </div>
                <?php if ($summary['status'] !== '') : ?>
                    <span class="ltf-status"><?php echo esc_html($summary['status']); ?></span>
                <?php endif; ?>
            </header>

            <dl class="ltf-meta">
                <?php if ($summary['origin'] !== '') : ?>
                    <div><dt><?php esc_html_e('Origin', 'leopard-tracking-form'); ?></dt><dd><?php echo esc_html($summary['origin']); ?></dd></div>
                <?php endif; ?>
                <?php if ($summary['destination'] !== '') : ?>
                    <div><dt><?php esc_html_e('Destination', 'leopard-tracking-form'); ?></dt><dd><?php echo esc_html($summary['destination']); ?></dd></div>
                <?php endif; ?>
                <?php if ($summary['booking_date'] !== '') : ?>
                    <div><dt><?php esc_html_e('Booking Date', 'leopard-tracking-form'); ?></dt><dd><?php echo esc_html($summary['booking_date']); ?></dd></div>
                <?php endif; ?>
                <?php if ($summary['shipper'] !== '') : ?>
                    <div><dt><?php esc_html_e('Shipper', 'leopard-tracking-form'); ?></dt><dd><?php echo esc_html($summary['shipper']); ?></dd></div>
                <?php endif; ?>
                <?php if ($summary['consignee'] !== '') : ?>
                    <div><dt><?php esc_html_e('Consignee', 'leopard-tracking-form'); ?></dt><dd><?php echo esc_html($summary['consignee']); ?></dd></div>
                <?php endif; ?>
                <?php if ($summary['weight'] !== '') : ?>
                    <div><dt><?php esc_html_e('Weight', 'leopard-tracking-form'); ?></dt><dd><?php echo esc_html($summary['weight']); ?></dd></div>
                <?php endif; ?>
                <?php if ($summary['pieces'] !== '') : ?>
                    <div><dt><?php esc_html_e('Pieces', 'leopard-tracking-form'); ?></dt><dd><?php echo esc_html($summary['pieces']); ?></dd></div>
                <?php endif; ?>
                <?php if ($summary['order_id'] !== '') : ?>
                    <div><dt><?php esc_html_e('Order ID', 'leopard-tracking-form'); ?></dt><dd><?php echo esc_html($summary['order_id']); ?></dd></div>
                <?php endif; ?>
            </dl>

            <?php if (!empty($history)) : ?>
                <h3 class="ltf-timeline-title"><?php esc_html_e('Shipment History', 'leopard-tracking-form'); ?></h3>
                <ol class="ltf-timeline">
                    <?php foreach ($history as $event) :
                        if (!is_array($event)) {
                            $event = array('activity' => (string) $event);
                        }
                        $date = isset($event['date']) ? $event['date'] : (isset($event['datetime']) ? $event['datetime'] : (isset($event['created_at']) ? $event['created_at'] : (isset($event['activity_date']) ? $event['activity_date'] : '')));
                        $activity = isset($event['activity']) ? $event['activity'] : (isset($event['status']) ? $event['status'] : (isset($event['remarks']) ? $event['remarks'] : implode(' — ', array_map('strval', array_filter($event, 'is_scalar')))));
                        $location = isset($event['location']) ? $event['location'] : (isset($event['city']) ? $event['city'] : '');
                    ?>
                    <li class="ltf-timeline-item">
                        <span class="ltf-dot" aria-hidden="true"></span>
                        <div>
                            <p class="ltf-activity"><?php echo esc_html((string) $activity); ?></p>
                            <?php if ($location !== '') : ?><p class="ltf-location"><?php echo esc_html((string) $location); ?></p><?php endif; ?>
                            <?php if ($date !== '') : ?><time class="ltf-date"><?php echo esc_html((string) $date); ?></time><?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>
            <?php else : ?>
                <details class="ltf-raw">
                    <summary><?php esc_html_e('View full shipment details', 'leopard-tracking-form'); ?></summary>
                    <dl class="ltf-meta">
                        <?php foreach ($packet as $k => $v) :
                            if (is_array($v) || $v === '' || $v === null) {
                                continue;
                            }
                        ?>
                            <div><dt><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $k))); ?></dt><dd><?php echo esc_html((string) $v); ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </details>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
