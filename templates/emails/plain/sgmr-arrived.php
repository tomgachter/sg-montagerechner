<?php
if (!defined('ABSPATH')) exit;
$serviceLabel = isset($context['service_label']) ? (string) $context['service_label'] : __('Montage/Etagenlieferung', 'sg-mr');
$linkReason = isset($context['link_reason']) ? (string) $context['link_reason'] : 'ok';
$linkNote = isset($context['link_note']) ? (string) $context['link_note'] : '';
?>
<?php printf(__('Guten Tag %s,', 'sg-mr'), $order->get_billing_first_name()); ?>

<?php printf(__('Ihre Bestellung #%s ist bei uns eingetroffen.', 'sg-mr'), $order->get_order_number()); ?>

<?php printf(__('Bitte reservieren Sie jetzt Ihren Termin für die %s. Wählen Sie Ihr Wunschfenster (z.B. 08:00–10:00).', 'sg-mr'), $serviceLabel); ?>

<?php if (!empty($link_url)) : ?>
<?php printf(__('Terminlink: %s', 'sg-mr'), $link_url); ?>
<?php else : ?>
<?php if ($linkReason === 'no_region') : ?>
<?php _e('Hinweis: Ihre Region ist noch nicht hinterlegt. Wir melden uns telefonisch zur Terminvereinbarung.', 'sg-mr'); ?>
<?php else : ?>
<?php _e('Wir melden uns, um den Termin telefonisch abzustimmen.', 'sg-mr'); ?>
<?php endif; ?>
<?php endif; ?>

<?php if ($linkNote && $linkReason !== 'no_region') : ?>
<?php echo esc_html($linkNote); ?>
<?php endif; ?>

<?php _e('Freundliche Grüsse', 'sg-mr'); ?>
Sanigroup Montageteam
