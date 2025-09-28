<?php

namespace SGMR\Services;

use SGMR\Admin\Settings;
use SGMR\Plugin;
use WC_Cart;
use WC_Order;
use WC_Product;
use function array_fill_keys;
use function array_unique;
use function array_values;
use function is_wp_error;
use function sanitize_title;
use function wp_get_object_terms;

class CartService
{
    public const META_TERMIN_MODE = '_sg_terminvereinbarung';
    public const META_SELECTION = '_sg_mr_sel';
    public const META_FORCE_OFFLINE = '_sg_force_offline';
    public const META_EXPRESS_FLAG = '_sg_express_flag';
    public const META_DEVICE_COUNT = '_sg_device_count';
    public const META_REGION_KEY = '_sg_region_key';
    public const META_REGION_LABEL = '_sg_region_label';
    public const META_REGION_ON_REQUEST = '_sg_region_on_request';
    public const META_REGION_SOURCE = '_sg_region_source';
    public const META_REGION_STRATEGY = '_sg_region_strategy';
    public const META_REGION_RULE = '_sg_region_rule';
    public const META_REGION_POSTCODE = '_sg_region_postcode';
    public const META_POSTCODE = '_sg_postcode';
    public const META_COUNTRY = '_sg_country';
    public const META_BOOKING_LINK = '_sg_booking_link';
    public const META_MONTAGE_COUNT = '_sg_montage_count';
    public const META_ETAGE_COUNT = '_sg_etage_count';
    public const META_STOCK_OVERRIDE = '_sgmr_stock_override';

    public static function boot(): void
    {
        add_filter('woocommerce_checkout_get_value', [self::class, 'prefillPostcode'], 10, 2);
        add_filter('woocommerce_ship_to_different_address_checked', '__return_false');
    }

    public static function prefillPostcode($value, string $key)
    {
        if (in_array($key, ['billing_postcode', 'shipping_postcode'], true)) {
            $sessionValue = WC()->session ? WC()->session->get(Plugin::SESSION_POSTCODE, '') : '';
            if ($sessionValue) {
                return $sessionValue;
            }
        }
        return $value;
    }

    public static function cartSelection(): array
    {
        return WC()->session ? (array) WC()->session->get(Plugin::SESSION_SELECTION, []) : [];
    }

    public static function cartProductId(string $cartKey): ?int
    {
        $cart = WC()->cart;
        if (!$cart instanceof WC_Cart) {
            return null;
        }
        $contents = $cart->get_cart();
        if (!isset($contents[$cartKey])) {
            return null;
        }
        $item = $contents[$cartKey];
        if (!empty($item['product_id'])) {
            return (int) $item['product_id'];
        }
        if (!empty($item['data']) && $item['data'] instanceof WC_Product) {
            return (int) $item['data']->get_id();
        }
        return null;
    }

    public static function cartItemQuantity(string $cartKey): int
    {
        $cart = WC()->cart;
        if (!$cart instanceof WC_Cart) {
            return 1;
        }
        $contents = $cart->get_cart();
        if (!isset($contents[$cartKey])) {
            return 1;
        }
        $qty = isset($contents[$cartKey]['quantity']) ? (int) $contents[$cartKey]['quantity'] : 1;
        return $qty > 0 ? $qty : 1;
    }

    public static function cartHasService(): bool
    {
        return self::selectionHasService(self::cartSelection());
    }

    public static function selectionHasService(array $selection): bool
    {
        foreach ($selection as $row) {
            $mode = $row['mode'] ?? '';
            if (in_array($mode, ['montage', 'etage'], true)) {
                return true;
            }
        }
        return false;
    }

    public static function selectionHasPickup(array $selection): bool
    {
        foreach ($selection as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['mode'] ?? '') === 'abholung') {
                return true;
            }
        }
        return false;
    }

    public static function cartHasPickup(): bool
    {
        return self::selectionHasPickup(self::cartSelection());
    }

    public static function cartForceOffline(): bool
    {
        if (!self::cartHasService()) {
            return false;
        }
        $cart = WC()->cart;
        if (!$cart instanceof WC_Cart) {
            return false;
        }
        $selection = self::cartSelection();
        $counts = self::selectionCounts($selection);
        $routerSettings = Settings::getSettings();
        $weightLimit = (float) ($routerSettings['weight_gate'] ?? 2.0);
        $weight = (float) $counts['montage'] + 0.5 * (float) $counts['etage'];
        if ($weightLimit > 0 && ($weight - $weightLimit) > 0.0001) {
            return true;
        }

        $phoneOnlySlugs = self::phoneOnlySlugs();
        if ($phoneOnlySlugs) {
            $lookup = array_fill_keys($phoneOnlySlugs, true);
            foreach ($cart->get_cart() as $item) {
                $product = $item['data'] ?? null;
                $productId = null;
                if ($product instanceof WC_Product) {
                    $productId = $product->get_id();
                } elseif (isset($item['product_id'])) {
                    $productId = (int) $item['product_id'];
                }
                if (!$productId) {
                    continue;
                }
                $slugs = wp_get_object_terms($productId, 'product_cat', ['fields' => 'slugs']);
                if (is_wp_error($slugs)) {
                    continue;
                }
                foreach ($slugs as $slug) {
                    if (isset($lookup[$slug])) {
                        return true;
                    }
                }
            }
        }

        foreach ($selection as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['express'])) {
                return true;
            }
        }

        return false;
    }

    public static function forceOfflineThreshold(): int
    {
        $option = defined('SG_Montagerechner_V3::OPT_DISPO_SETTINGS')
            ? \SG_Montagerechner_V3::OPT_DISPO_SETTINGS
            : 'sg_mr_disposition_v1';
        $dispo = get_option($option, []);
        if (!is_array($dispo)) {
            $dispo = [];
        }
        $threshold = isset($dispo['offline_threshold']) ? (int) $dispo['offline_threshold'] : 3;
        return $threshold > 0 ? $threshold : 3;
    }

    public static function productIsInstant(WC_Product $product): bool
    {
        if (!$product->managing_stock()) {
            return false;
        }
        $stock = $product->get_stock_quantity();
        return $stock !== null && $stock > 1;
    }

    public static function orderHasInstantStock(WC_Order $order): bool
    {
        $override = $order->get_meta(self::META_STOCK_OVERRIDE, true);
        if ($override === 'yes' || $override === 1 || $override === true) {
            return true;
        }

        $selection = $order->get_meta(self::META_SELECTION);
        $serviceProducts = [];
        if (is_array($selection)) {
            foreach ($selection as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $mode = $row['mode'] ?? '';
                if (!in_array($mode, ['montage','etage'], true)) {
                    continue;
                }
                if (!empty($row['product_id'])) {
                    $serviceProducts[(int) $row['product_id']] = true;
                }
            }
        }

        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product instanceof WC_Product) {
                continue;
            }
            if ($serviceProducts) {
                if (!isset($serviceProducts[$product->get_id()])) {
                    continue;
                }
            }
            if (self::productIsInstant($product)) {
                return true;
            }
        }
        return $serviceProducts ? false : self::orderContainsInstantFallback($order);
    }

    public static function selectionCounts(array $selection): array
    {
        $montage = 0;
        $etage = 0;
        foreach ($selection as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mode = $row['mode'] ?? '';
            $qty = isset($row['qty']) ? (int) $row['qty'] : 1;
            if ($qty < 1) {
                $qty = 1;
            }
            if ($mode === 'montage') {
                $montage += $qty;
            }
            if ($mode === 'etage') {
                $etage += $qty;
            }
        }
        return [
            'montage' => $montage,
            'etage' => $etage,
        ];
    }

    public static function ensureOrderCounts(WC_Order $order): array
    {
        $rawMontage = $order->get_meta(self::META_MONTAGE_COUNT, true);
        $rawEtage = $order->get_meta(self::META_ETAGE_COUNT, true);

        $hasMontageMeta = $rawMontage !== '' && $rawMontage !== null;
        $hasEtageMeta = $rawEtage !== '' && $rawEtage !== null;

        $counts = [
            'montage' => $hasMontageMeta ? (int) $rawMontage : 0,
            'etage' => $hasEtageMeta ? (int) $rawEtage : 0,
        ];

        $wasMissing = !$hasMontageMeta || !$hasEtageMeta;
        $source = $wasMissing ? 'computed' : 'meta';

        if ($wasMissing) {
            $selection = $order->get_meta(self::META_SELECTION);
            if (is_array($selection) && !empty($selection)) {
                $derived = self::selectionCounts($selection);
                $counts['montage'] = max(0, (int) ($derived['montage'] ?? 0));
                $counts['etage'] = max(0, (int) ($derived['etage'] ?? 0));
                $source = 'selection';
            }

            if ($counts['montage'] === 0 && $counts['etage'] === 0) {
                foreach ($order->get_items('fee') as $fee) {
                    $name = strtolower($fee->get_name());
                    if (strpos($name, 'montage') !== false) {
                        $counts['montage'] += 1;
                    }
                    if (strpos($name, 'etagen') !== false || strpos($name, 'etage') !== false) {
                        $counts['etage'] += 1;
                    }
                }
                if ($counts['montage'] > 0 || $counts['etage'] > 0) {
                    $source = 'fees';
                }
            }

            $order->update_meta_data(self::META_MONTAGE_COUNT, max(0, (int) $counts['montage']));
            $order->update_meta_data(self::META_ETAGE_COUNT, max(0, (int) $counts['etage']));
            $order->save_meta_data();
        }

        return [
            'montage' => max(0, (int) $counts['montage']),
            'etage' => max(0, (int) $counts['etage']),
            'was_missing' => $wasMissing,
            'source' => $source,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function phoneOnlySlugs(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $settings = Settings::getSettings();
        $raw = $settings['phone_category_slugs'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $slugs = [];
        foreach ($raw as $value) {
            $slug = sanitize_title((string) $value);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        $cache = array_values(array_unique($slugs));
        return $cache;
    }

    private static function orderContainsInstantFallback(WC_Order $order): bool
    {
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if ($product instanceof WC_Product && self::productIsInstant($product)) {
                return true;
            }
        }
        return false;
    }

    public static function orderHasService(WC_Order $order): bool
    {
        $selection = $order->get_meta(self::META_SELECTION);
        if (is_array($selection) && self::selectionHasService($selection)) {
            return true;
        }
        foreach ($order->get_items('fee') as $fee) {
            $name = strtolower($fee->get_name());
            if (strpos($name, 'montage') !== false || strpos($name, 'etagen') !== false) {
                return true;
            }
        }
        return false;
    }

    public static function orderHasPickup(WC_Order $order): bool
    {
        $selection = $order->get_meta(self::META_SELECTION);
        return is_array($selection) && self::selectionHasPickup($selection);
    }
}
