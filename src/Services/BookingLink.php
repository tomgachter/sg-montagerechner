<?php

namespace SGMR\Services;

use SGMR\Plugin;
use SGMR\Services\CartService;
use SGMR\Utils\ShortcodePageDetector;
use WC_Order;
use function add_query_arg;
use function esc_url_raw;
use function get_option;
use function get_permalink;
use function home_url;
use function sanitize_key;

class BookingLink
{
    private const REGION_PATHS = [
        'zuerich_limmattal' => '/zuerich-limmattal/',
        'basel_fricktal' => '/basel-fricktal/',
        'aargau_sued_zentralschweiz' => '/aargau-sued-zentralschweiz/',
        'mittelland_west' => '/mittelland-west/',
    ];

    private const LEGACY_ALIASES = [
        'zurich_limmattal' => 'zuerich_limmattal',
        'aargau_sued_zentral' => 'aargau_sued_zentralschweiz',
    ];

    public static function regionUrl(string $region): string
    {
        $region = sanitize_key($region);
        if (isset(self::LEGACY_ALIASES[$region])) {
            $region = self::LEGACY_ALIASES[$region];
        }
        $pages = get_option(Plugin::OPTION_REGIONS, []);
        if (is_array($pages) && !empty($pages[$region])) {
            return esc_url_raw($pages[$region]);
        }
        if (isset(self::REGION_PATHS[$region])) {
            return esc_url_raw(home_url(self::REGION_PATHS[$region]));
        }
        return '';
    }

    public static function build(WC_Order $order, ?string $region = null, ?array &$meta = null): string
    {
        $region = $region ?: (string) $order->get_meta(CartService::META_REGION_KEY);
        $region = \sgmr_normalize_region_slug($region);
        if (isset(self::LEGACY_ALIASES[$region])) {
            $region = self::LEGACY_ALIASES[$region];
        }
        $pageId = (int) get_option('sgmr_booking_page_id', 0);
        $base = '';
        if ($pageId > 0) {
            $base = get_permalink($pageId);
        }
        if ($base === '' || $base === false) {
            $detected = ShortcodePageDetector::detect('[sg_booking_auto]');
            if (is_string($detected) && $detected !== '') {
                $base = $detected;
            }
        }
        if (!$base) {
            $base = self::regionUrl($region);
        }
        if (!$base) {
            return '';
        }
        $base = esc_url_raw($base);
        $orderId = $order->get_id();
        $m = max(0, (int) $order->get_meta(CartService::META_MONTAGE_COUNT));
        $e = max(0, (int) $order->get_meta(CartService::META_ETAGE_COUNT));
        $timestamp = time();
        $signatureParams = [
            'region' => $region,
            'sgm' => $m,
            'sge' => $e,
        ];
        $signature = \sgmr_booking_signature($orderId, $signatureParams, $timestamp);

        $query = [
            'order' => $orderId,
            'region' => $region,
            'sgm' => $m,
            'sge' => $e,
            'sig' => $signature,
        ];
        $url = add_query_arg($query, $base);
        if (is_array($meta)) {
            $meta = [
                'link_ts' => \sgmr_booking_signature_timestamp($signature),
                'link_sig' => $signature,
                'link_hash' => \sgmr_booking_signature_parse($signature)['hash'] ?? '',
                'link_region' => $region,
                'link_sgm' => $m,
                'link_sge' => $e,
            ];
        }
        return esc_url_raw($url);
    }

    private static function orderFlags(WC_Order $order): array
    {
        $flags = [
            'montage' => false,
            'etage' => false,
            'altgeraet' => false,
            'positions' => [],
        ];
        $selection = $order->get_meta(CartService::META_SELECTION);
        if (is_array($selection)) {
            foreach ($selection as $row) {
                $mode = $row['mode'] ?? '';
                if ($mode === 'montage') {
                    $flags['montage'] = true;
                }
                if ($mode === 'etage') {
                    $flags['etage'] = true;
                }
                if (!empty($row['old_bundle']) || !empty($row['etage_alt'])) {
                    $flags['altgeraet'] = true;
                }
            }
        }
        foreach ($order->get_items('line_item') as $item) {
            $flags['positions'][] = sprintf('%dx %s', $item->get_quantity(), $item->get_name());
        }
        return $flags;
    }
}
