<?php

namespace SGMR;

use SGMR\Admin\Settings;
use function add_action;
use function esc_html__;
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
    private const PREFILL_HANDLE = 'sgmr-fbp-prefill';
    private const AUGMENT_HANDLE = 'sgmr-fbp-augment';

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
            plugins_url('assets/js/sgmr-fbp-prefill.js', $pluginFile),
            [],
            '1.1.0',
            true
        );

        wp_localize_script(
            self::PREFILL_HANDLE,
            'sgmrFbpPrefill',
            [
                'endpoint' => esc_url_raw(rest_url('sgmr/v1/fluent-booking/prefill')),
                'orderParamCandidates' => ['order', 'order_id', 'orderId', 'orderID'],
            ]
        );

        $settings = Settings::getSettings();
        $frontendOverrideEnabled = !empty($settings['frontend_duration_override']);

        if ($frontendOverrideEnabled) {
            wp_enqueue_script(
                self::AUGMENT_HANDLE,
                plugins_url('assets/js/sgmr-fbp-augment.js', $pluginFile),
                [self::PREFILL_HANDLE],
                '1.1.0',
                true
            );

            wp_localize_script(
                self::AUGMENT_HANDLE,
                'sgmrFbpAugment',
                [
                    'durationLabelPrefix' => esc_html__('Dauer', 'sg-mr'),
                    'rangeSeparator' => ' – ',
                    'maxAttempts' => 25,
                    'retryDelay' => 300,
                    'durationMinutes' => 0,
                ]
            );
        }
    }
}
