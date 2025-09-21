<?php
/** @var WC_Order $order */
/** @var WC_Email $email */
if (!defined('ABSPATH')) exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<?php $serviceLabel = isset($context['service_label']) ? (string) $context['service_label'] : __('Montage/Etagenlieferung', 'sg-mr'); ?>
<p><?php printf(__('Guten Tag %s,', 'sg-mr'), esc_html($order->get_billing_first_name())); ?></p>
<p><?php printf(__('vielen Dank für Ihre Bestellung <strong>#%s</strong>. Ihre Ware ist verfügbar.', 'sg-mr'), esc_html($order->get_order_number())); ?></p>
<p><?php printf(__('Für Ihre %s können Sie jetzt den Termin wählen. Wir arbeiten mit festen Zeitfenstern (z.&nbsp;B. 08:00–10:00).', 'sg-mr'), esc_html($serviceLabel)); ?></p>
<?php if (!empty($link_url)) : ?>
    <p><a class="button" href="<?php echo esc_url($link_url); ?>"><?php _e('Termin jetzt online buchen', 'sg-mr'); ?></a></p>
<?php endif; ?>
<p><?php _e('Hinweis: Buchungen sind mindestens <strong>2 Werktage</strong> im Voraus möglich.', 'sg-mr'); ?></p>
<p><?php _e('Freundliche Grüsse<br>Sanigroup Montageteam', 'sg-mr'); ?></p>
<?php do_action('woocommerce_email_footer', $email); ?>
