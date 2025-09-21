<?php
if (!defined('ABSPATH')) exit;

$serviceLabel = isset($context['service_label']) ? (string) $context['service_label'] : __('Montage/Etagenlieferung', 'sg-mr');
$linkReason = isset($context['link_reason']) ? (string) $context['link_reason'] : 'ok';
$linkNote = isset($context['link_note']) ? (string) $context['link_note'] : '';

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php printf(__('Guten Tag %s,', 'sg-mr'), esc_html($order->get_billing_first_name())); ?></p>
<p><?php printf(__('Ihre Bestellung <strong>#%s</strong> ist bei uns eingetroffen.', 'sg-mr'), esc_html($order->get_order_number())); ?></p>
<p><?php printf(__('Reservieren Sie jetzt Ihren Termin für die %s im gewünschten Zeitfenster (z.&nbsp;B. 08:00–10:00).', 'sg-mr'), esc_html($serviceLabel)); ?></p>
<?php if (!empty($link_url)) : ?>
    <p><a class="button" href="<?php echo esc_url($link_url); ?>"><?php _e('Termin online reservieren', 'sg-mr'); ?></a></p>
<?php else : ?>
    <?php if ($linkReason === 'no_region') : ?>
        <p><?php _e('Ihre Region ist noch nicht hinterlegt. Unser Team meldet sich telefonisch, um den Termin zu vereinbaren.', 'sg-mr'); ?></p>
    <?php else : ?>
        <p><?php _e('Wir melden uns kurzfristig, um Ihren Termin abzustimmen.', 'sg-mr'); ?></p>
    <?php endif; ?>
<?php endif; ?>
<?php if ($linkNote && $linkReason !== 'no_region') : ?>
    <p><?php echo esc_html($linkNote); ?></p>
<?php endif; ?>
<p><?php _e('Freundliche Grüsse<br>Sanigroup Montageteam', 'sg-mr'); ?></p>
<?php do_action('woocommerce_email_footer', $email); ?>
