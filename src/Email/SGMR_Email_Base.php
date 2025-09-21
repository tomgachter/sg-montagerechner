<?php

namespace SGMR\Email;

use WC_Email;
use WC_Order;

use SGMR\Services\CartService;

abstract class SGMR_Email_Base extends WC_Email
{
    protected string $link_url = '';
    protected array $context = [];

    public function trigger($order_id, array $args = []): void
    {
        if (!$order_id) {
            return;
        }
        $this->object = wc_get_order($order_id);
        if (!$this->object instanceof WC_Order) {
            return;
        }
        $this->recipient = $this->object->get_billing_email();
        $this->placeholders['{order_number}'] = $this->object->get_order_number();
        $this->link_url = $args['link_url'] ?? '';
        $mode = '';
        if (class_exists(CartService::class)) {
            if (method_exists(CartService::class, 'ensureOrderCounts')) {
                CartService::ensureOrderCounts($this->object);
            }
            $mode = (string) $this->object->get_meta(CartService::META_TERMIN_MODE);
        }
        $descriptor = function_exists('sgmr_order_service_descriptor')
            ? sgmr_order_service_descriptor($this->object)
            : ['label' => __('Service', 'sg-mr'), 'flags' => []];
        $modeKey = $mode === 'telefonisch' ? 'telefonisch' : 'online';
        $this->context = array_merge([
            'link_url' => $this->link_url,
            'mode' => $modeKey,
            'mode_label' => $modeKey === 'telefonisch' ? __('Telefonisch', 'sg-mr') : __('Online', 'sg-mr'),
            'service_label' => $descriptor['label'] ?? __('Service', 'sg-mr'),
            'service_flags' => $descriptor['flags'] ?? [],
        ], $args);
        if (!$this->is_enabled() || !$this->get_recipient()) {
            return;
        }
        $this->setup_locale();
        $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
        $this->restore_locale();
    }

    public function get_content_html()
    {
        return wc_get_template_html(
            $this->template_html,
            [
                'order' => $this->object,
                'email' => $this,
                'email_heading' => $this->get_heading(),
                'link_url' => $this->link_url,
                'context' => $this->context,
            ],
            '',
            trailingslashit(dirname(__DIR__, 2)) . 'templates/'
        );
    }

    public function get_content_plain()
    {
        ob_start();
        wc_get_template(
            $this->template_plain,
            [
                'order' => $this->object,
                'email' => $this,
                'email_heading' => $this->get_heading(),
                'link_url' => $this->link_url,
                'context' => $this->context,
            ],
            '',
            trailingslashit(dirname(__DIR__, 2)) . 'templates/'
        );
        return ob_get_clean();
    }

    public function contextValue(string $key, $default = null)
    {
        return array_key_exists($key, $this->context) ? $this->context[$key] : $default;
    }
}
