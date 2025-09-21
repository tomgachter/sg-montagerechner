<?php if (!defined('ABSPATH')) exit; ?>
Zu Ihrer Bestellung #<?php echo esc_html($order->get_order_number()); ?> meldet sich unser Montageteam telefonisch zur Terminvereinbarung.
<?php $phone = method_exists($order,'get_shipping_phone') ? $order->get_shipping_phone() : ''; if (!$phone) $phone = $order->get_billing_phone(); if ($phone): ?>
Telefon (Lieferung): <?php echo esc_html($phone); ?>
<?php endif; ?>
