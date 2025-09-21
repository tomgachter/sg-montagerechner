<?php
if (!defined('ABSPATH')) exit;
$serviceLabel = isset($context['service_label']) ? (string) $context['service_label'] : __('Montage/Etagenlieferung', 'sg-mr');
$stage = isset($context['stage']) ? (string) $context['stage'] : 'paid_wait';
?>
<?php printf(__('Guten Tag %s,', 'sg-mr'), $order->get_billing_first_name()); ?>

<?php printf(__('Bestellung #%s', 'sg-mr'), $order->get_order_number()); ?>

<?php if ($stage === 'paid_instock') : ?>
<?php printf(__('Ihre Ware ist verfügbar. Wir melden uns telefonisch, um einen Termin für die %s zu vereinbaren.', 'sg-mr'), $serviceLabel); ?>
<?php elseif ($stage === 'arrived') : ?>
<?php printf(__('Ihre Ware ist eingetroffen. Wir rufen Sie an, um den Termin für die %s zu fixieren.', 'sg-mr'), $serviceLabel); ?>
<?php else : ?>
<?php printf(__('Wir bestellen Ihre Ware. Sobald sie eingetroffen ist, melden wir uns telefonisch zur Terminvereinbarung für die %s.', 'sg-mr'), $serviceLabel); ?>
<?php endif; ?>

<?php _e('Freundliche Grüsse', 'sg-mr'); ?>
Sanigroup Montageteam
