<?php
if (!defined('ABSPATH')) {
    exit;
}

$mode = isset($context['mode']) ? (string) $context['mode'] : 'online';
$serviceLabel = isset($context['service_label']) ? (string) $context['service_label'] : __('Montage/Etagenlieferung', 'sg-mr');

echo sprintf(__('Guten Tag %s,', 'sg-mr'), $order->get_billing_first_name()) . "\n\n";
echo sprintf(__('Wir haben Ihre Zahlung zur Bestellung #%s erhalten.', 'sg-mr'), $order->get_order_number()) . "\n";
if ($mode === 'telefonisch') {
    echo sprintf(__('Wir bestellen Ihre Ware und melden uns telefonisch, sobald wir einen Termin für Ihre %s vereinbaren können.', 'sg-mr'), $serviceLabel) . "\n";
} else {
    echo sprintf(__('Ihre Ware ist aktuell noch unterwegs. Sobald sie eingetroffen ist, erhalten Sie den Terminlink für Ihre %s.', 'sg-mr'), $serviceLabel) . "\n";
}
echo __('Vielen Dank für Ihre Geduld.', 'sg-mr') . "\n\n";
echo __('Freundliche Grüsse', 'sg-mr') . "\n";
echo __('Sanigroup Montageteam', 'sg-mr') . "\n";
