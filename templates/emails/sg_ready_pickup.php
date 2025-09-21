<?php
if (!defined('ABSPATH')) exit;
do_action('woocommerce_email_header', $email_heading, $email);
$p = is_callable(['SG_Montagerechner_V3','get_params']) ? SG_Montagerechner_V3::get_params() : [];
$addr = !empty($p['pickup_address']) ? $p['pickup_address'] : '';
$url  = !empty($p['pickup_hours_url']) ? $p['pickup_hours_url'] : '';
?>
<p>Guten Tag,</p>
<p>Ihre Bestellung #<?php echo esc_html($order->get_order_number()); ?> ist abholbereit.</p>
<?php if ($addr): ?><p>Abholadresse: <?php echo esc_html($addr); ?></p><?php endif; ?>
<?php if ($url): ?><p>Bitte beachten Sie unsere <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Öffnungszeiten</a>.</p><?php endif; ?>
<?php do_action('woocommerce_email_order_details', $order, false, false, $email); ?>
<?php do_action('woocommerce_email_customer_details', $order, false, false, $email); ?>
<?php do_action('woocommerce_email_footer', $email); ?>

