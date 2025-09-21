<?php
if (!defined('ABSPATH')) exit;

$serviceLabel = isset($context['service_label']) ? (string) $context['service_label'] : __('Montage/Etagenlieferung', 'sg-mr');
$stage = isset($context['stage']) ? (string) $context['stage'] : 'paid_wait';

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php printf(__('Guten Tag %s,', 'sg-mr'), esc_html($order->get_billing_first_name())); ?></p>
<p><?php printf(__('Bestellung <strong>#%s</strong>', 'sg-mr'), esc_html($order->get_order_number())); ?></p>
<?php if ($stage === 'paid_instock') : ?>
    <p><?php printf(__('Ihre Ware ist verfügbar. Wir melden uns telefonisch, um einen Termin für die %s zu vereinbaren.', 'sg-mr'), esc_html($serviceLabel)); ?></p>
<?php elseif ($stage === 'arrived') : ?>
    <p><?php printf(__('Ihre Ware ist eingetroffen. Wir rufen Sie an, um den Termin für die %s fix zu planen.', 'sg-mr'), esc_html($serviceLabel)); ?></p>
<?php else : ?>
    <p><?php printf(__('Wir bestellen Ihre Ware. Sobald sie eingetroffen ist, melden wir uns telefonisch zur Terminvereinbarung für die %s.', 'sg-mr'), esc_html($serviceLabel)); ?></p>
<?php endif; ?>
<p><?php _e('Freundliche Grüsse<br>Sanigroup Montageteam', 'sg-mr'); ?></p>
<?php do_action('woocommerce_email_footer', $email); ?>
