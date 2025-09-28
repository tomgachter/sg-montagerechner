<?php

namespace SGMR;

use function add_action;
use function esc_url_raw;
use function get_option;
use function is_admin;
use function is_page;
use function plugins_url;
use function rest_url;
use function wp_enqueue_script;
use function wp_localize_script;

class Assets
{
    private const PREFILL_HANDLE = 'sgmr-booking-prefill';

    public function boot(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueBookingAssets']);
    }

    public function enqueueBookingAssets(): void
    {
        if (is_admin()) {
            return;
        }

        $pageId = (int) get_option('sgmr_booking_page_id', 0);
        if ($pageId <= 0 || !is_page($pageId)) {
            return;
        }

        $pluginFile = dirname(__DIR__) . '/sg-montagerechner.php';

        wp_enqueue_script(
            self::PREFILL_HANDLE,
            plugins_url('assets/js/sgmr-booking-prefill.js', $pluginFile),
            [],
            '1.1.0',
            true
        );

        wp_localize_script(
            self::PREFILL_HANDLE,
            'sgmrBookingPrefill',
            [
                'endpoint' => esc_url_raw(rest_url('sgmr/v1/booking/prefill')),
                'orderParamCandidates' => ['order', 'order_id', 'orderId', 'orderID'],
            ]
        );
    }
}
