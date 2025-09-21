<?php
if (!defined('ABSPATH')) exit;
$serviceLabel = isset($context['service_label']) ? (string) $context['service_label'] : __('Montage/Etagenlieferung', 'sg-mr');
?>
<?php printf(__('Guten Tag %s,', 'sg-mr'), $order->get_billing_first_name()); ?>

<?php printf(__('Vielen Dank für Ihre Bestellung #%s. Ihre Ware ist verfügbar.', 'sg-mr'), $order->get_order_number()); ?>

<?php printf(__('Für Ihre %s können Sie jetzt den Termin wählen. Wir arbeiten mit festen Zeitfenstern (z.B. 08:00–10:00).', 'sg-mr'), $serviceLabel); ?>

<?php if (!empty($link_url)) : ?>
<?php printf(__('Terminlink: %s', 'sg-mr'), $link_url); ?>
<?php endif; ?>

<?php _e('Hinweis: Buchungen sind mindestens 2 Werktage im Voraus möglich.', 'sg-mr'); ?>

<?php _e('Freundliche Grüsse', 'sg-mr'); ?>
Sanigroup Montageteam
