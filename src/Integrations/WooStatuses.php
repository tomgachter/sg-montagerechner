<?php

namespace SGMR\Integrations;

use function _n_noop;
use function _x;
use function add_action;
use function add_filter;
use function register_post_status;

class WooStatuses
{
    public function boot(): void
    {
        add_action('init', [$this, 'register']);
        add_filter('wc_order_statuses', [$this, 'expose']);
    }

    public function register(): void
    {
        $this->registerStatus(
            SGMR_STATUS_BOOKED,
            _x('Termin gebucht', 'Order status', 'sg-mr'),
            _n_noop('Termin gebucht <span class="count">(%s)</span>', 'Termin gebucht <span class="count">(%s)</span>', 'sg-mr')
        );
        $this->registerStatus(
            SGMR_STATUS_RESCHEDULE,
            _x('Termin verschoben', 'Order status', 'sg-mr'),
            _n_noop('Termin verschoben <span class="count">(%s)</span>', 'Termin verschoben <span class="count">(%s)</span>', 'sg-mr')
        );
        $this->registerStatus(
            SGMR_STATUS_CANCELED,
            _x('Termin storniert', 'Order status', 'sg-mr'),
            _n_noop('Termin storniert <span class="count">(%s)</span>', 'Termin storniert <span class="count">(%s)</span>', 'sg-mr')
        );
    }

    /**
     * @param array<string, string> $statuses
     * @return array<string, string>
     */
    public function expose(array $statuses): array
    {
        $map = [
            'wc-' . SGMR_STATUS_BOOKED => _x('Termin gebucht', 'Order status', 'sg-mr'),
            'wc-' . SGMR_STATUS_RESCHEDULE => _x('Termin verschoben', 'Order status', 'sg-mr'),
            'wc-' . SGMR_STATUS_CANCELED => _x('Termin storniert', 'Order status', 'sg-mr'),
        ];

        foreach ($map as $key => $label) {
            $statuses[$key] = $label;
        }

        return $statuses;
    }

    private function registerStatus(string $status, string $label, array $labelCount): void
    {
        register_post_status('wc-' . $status, [
            'label' => $label,
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => $labelCount,
        ]);
    }
}

class_alias(__NAMESPACE__ . '\\WooStatuses', 'Sanigroup\\Montagerechner\\Integrations\\WooStatuses');
