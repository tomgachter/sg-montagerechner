<?php if (!defined('ABSPATH')) exit; ?>
Ihre Bestellung #<?php echo esc_html($order->get_order_number()); ?> erfordert noch eine Zahlung.
Zahlungslink: <?php echo esc_url( $order->get_checkout_payment_url() ); ?>

