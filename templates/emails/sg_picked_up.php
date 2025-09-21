<?php
if (!defined('ABSPATH')) exit;
do_action('woocommerce_email_header', $email_heading, $email);
?>
<p>Guten Tag,</p>
<p>vielen Dank! Wir bestätigen die Abholung Ihrer Bestellung #<?php echo esc_html($order->get_order_number()); ?>.</p>
<?php do_action('woocommerce_email_order_details', $order, false, false, $email); ?>
<?php do_action('woocommerce_email_customer_details', $order, false, false, $email); ?>
<?php do_action('woocommerce_email_footer', $email); ?>

