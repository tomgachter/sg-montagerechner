<?php
/** @var WC_Order $order */
/** @var WC_Email $email */
if (!defined('ABSPATH')) {
    exit;
}

$mode = isset($context['mode']) ? (string) $context['mode'] : 'online';
$serviceLabel = isset($context['service_label']) ? (string) $context['service_label'] : __('Montage/Etagenlieferung', 'sg-mr');

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php printf(__('Guten Tag %s,', 'sg-mr'), esc_html($order->get_billing_first_name())); ?></p>
<p><?php printf(__('wir haben Ihre Zahlung zur Bestellung <strong>#%s</strong> erhalten.', 'sg-mr'), esc_html($order->get_order_number())); ?></p>
<?php if ($mode === 'telefonisch') : ?>
    <p><?php printf(__('Wir bestellen Ihre Ware und melden uns telefonisch, sobald wir einen Termin für Ihre %s vereinbaren können.', 'sg-mr'), esc_html($serviceLabel)); ?></p>
<?php else : ?>
    <p><?php printf(__('Ihre Ware ist aktuell noch unterwegs. Sobald sie eingetroffen ist, erhalten Sie den Terminlink für Ihre %s.', 'sg-mr'), esc_html($serviceLabel)); ?></p>
<?php endif; ?>
<p><?php _e('Vielen Dank für Ihre Geduld.', 'sg-mr'); ?></p>
<p><?php _e('Freundliche Grüsse<br>Sanigroup Montageteam', 'sg-mr'); ?></p>
<?php do_action('woocommerce_email_footer', $email); ?>
