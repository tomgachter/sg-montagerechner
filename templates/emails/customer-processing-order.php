<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Woo header
do_action( 'woocommerce_email_header', $email_heading, $email );

// Standard Woo Bestelldetails und Kundendetails beibehalten
do_action( 'woocommerce_email_order_details', $order, false, false, $email );
do_action( 'woocommerce_email_order_meta', $order, false, false, $email );
do_action( 'woocommerce_email_customer_details', $order, false, false, $email );

// Optionaler zusätzlicher Inhalt aus Woo-Einstellungen
if ( ! empty( $email ) && method_exists( $email, 'get_additional_content' ) ) {
    $additional_content = $email->get_additional_content();
    if ( $additional_content ) {
        echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
    }
}

// Woo footer
do_action( 'woocommerce_email_footer', $email );
