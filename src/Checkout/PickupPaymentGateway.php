<?php

namespace SGMR\Checkout;

use SGMR\Services\CartService;
use WC_Order;
use function absint;
use function add_action;
use function get_query_var;
use function is_admin;
use function is_ajax;
use function function_exists;
use function wc_get_order;
use function wpautop;
use function wptexturize;
use function wp_strip_all_tags;

class PickupPaymentGateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'sgmr_pickup';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('Bezahlung vor Ort', 'sg-mr');
        $this->method_description = __('Kundinnen und Kunden bezahlen bequem bei der Abholung im Geschäft.', 'sg-mr');
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', __('Bezahlung vor Ort', 'sg-mr'));
        $this->description = $this->get_option('description', __('Sie bezahlen bei der Abholung im Geschäft.', 'sg-mr'));
        $this->instructions = $this->get_option('instructions', $this->description);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyouPage']);
        add_action('woocommerce_email_before_order_table', [$this, 'emailInstructions'], 10, 3);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Aktiviert', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Bezahlung vor Ort aktivieren', 'sg-mr'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Titel', 'woocommerce'),
                'type' => 'text',
                'description' => __('Titel, der beim Checkout angezeigt wird.', 'sg-mr'),
                'default' => __('Bezahlung vor Ort', 'sg-mr'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Beschreibung', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Text, der Kundinnen und Kunden beim Checkout angezeigt wird.', 'sg-mr'),
                'default' => __('Sie bezahlen bei der Abholung im Geschäft.', 'sg-mr'),
            ],
            'instructions' => [
                'title' => __('Anweisungen', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Hinweise auf der Danke-Seite und in E-Mails.', 'sg-mr'),
                'default' => __('Bitte begleichen Sie den Betrag direkt bei der Abholung vor Ort.', 'sg-mr'),
            ],
        ];
    }

    public function is_available()
    {
        if (!parent::is_available()) {
            return false;
        }

        if (CartService::cartHasPickup()) {
            return true;
        }

        $orderId = absint(get_query_var('order-pay'));
        if ($orderId > 0) {
            $order = wc_get_order($orderId);
            if ($order instanceof WC_Order && CartService::orderHasPickup($order)) {
                return true;
            }
        }

        if (is_admin() && !is_ajax()) {
            return true;
        }

        return false;
    }

    public function process_payment($orderId)
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return [
                'result' => 'fail',
                'redirect' => '',
            ];
        }

        $order->update_status('on-hold', __('Wartet auf Abholung und Zahlung vor Ort.', 'sg-mr'));
        $order->reduce_order_stock();

        if (function_exists('WC')) {
            $cart = WC()->cart ?? null;
            if ($cart) {
                $cart->empty_cart();
            }
        }

        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    public function thankyouPage($orderId)
    {
        if (!$this->instructions) {
            return;
        }
        echo wpautop(wptexturize($this->instructions));
    }

    public function emailInstructions($order, $sentToAdmin, $plainText = false)
    {
        if ($sentToAdmin) {
            return;
        }
        if (!$order instanceof WC_Order || $order->get_payment_method() !== $this->id) {
            return;
        }
        if (!$this->instructions) {
            return;
        }
        if ($plainText) {
            echo PHP_EOL . wp_strip_all_tags($this->instructions) . PHP_EOL;
            return;
        }
        echo wpautop(wptexturize($this->instructions));
    }
}
