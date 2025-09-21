<?php

namespace SGMR\Booking;

use RuntimeException;
use SGMR\Admin\Settings;
use SGMR\Plugin;
use SGMR\Services\CartService;
use SGMR\Services\ScheduleService;
use SGMR\Utils\PostcodeHelper;
use WC_Order;
use WC_Product;
use function __;
use function _n;
use function array_map;
use function array_unique;
use function esc_url_raw;
use function get_edit_post_link;
use function get_option;
use function is_email;
use function is_wp_error;
use function preg_replace;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_title;
use function sgmr_booking_signature;
use function sgmr_booking_signature_parse;
use function sgmr_normalize_region_slug;
use function time;
use function wc_get_order;
use function wc_get_product;
use function wc_price;
use function wp_strip_all_tags;
use function wp_get_object_terms;
use function wp_json_encode;

class PrefillManager
{
    /**
     * @return array<string, string>
     */
    public function availableSources(): array
    {
        return [
            'name' => __('Vollständiger Name', 'sg-mr'),
            'first_name' => __('Vorname', 'sg-mr'),
            'last_name' => __('Nachname', 'sg-mr'),
            'email' => __('E-Mail-Adresse', 'sg-mr'),
            'phone' => __('Telefon (präferiert)', 'sg-mr'),
            'phone_delivery' => __('Telefon (Lieferung)', 'sg-mr'),
            'phone_billing' => __('Telefon (Rechnung)', 'sg-mr'),
            'phone_shipping' => __('Telefon (Lieferadresse)', 'sg-mr'),
            'address' => __('Adresse (Zeile 1+2)', 'sg-mr'),
            'address_line1' => __('Adresse – Zeile 1', 'sg-mr'),
            'address_line2' => __('Adresse – Zeile 2', 'sg-mr'),
            'postcode' => __('PLZ', 'sg-mr'),
            'city' => __('Ort', 'sg-mr'),
            'company' => __('Firma', 'sg-mr'),
            'country' => __('Land', 'sg-mr'),
            'region' => __('Region (Key)', 'sg-mr'),
            'region_label' => __('Region (Label)', 'sg-mr'),
            'region_source' => __('Region Quelle', 'sg-mr'),
            'region_strategy' => __('Region Logik', 'sg-mr'),
            'region_rule' => __('Region Regel', 'sg-mr'),
            'region_postcode' => __('Region-PLZ', 'sg-mr'),
            'order' => __('WooCommerce Bestell-ID', 'sg-mr'),
            'order_number' => __('Bestellnummer', 'sg-mr'),
            'bexio_ref' => __('Bexio Auftragsnummer', 'sg-mr'),
            'm' => __('Montagen (Anzahl)', 'sg-mr'),
            'e' => __('Etagenlieferungen (Anzahl)', 'sg-mr'),
            'items' => __('Positionsliste (kompakt)', 'sg-mr'),
            'items_multiline' => __('Positionsliste (mehrzeilig)', 'sg-mr'),
            'items_total_qty' => __('Summe Positionen', 'sg-mr'),
            'counts_summary' => __('Service-Zusammenfassung', 'sg-mr'),
            'minutes_required' => __('Erforderliche Minuten', 'sg-mr'),
            'customer_note' => __('Kundenhinweis', 'sg-mr'),
            'token_sig' => __('Signierter Token', 'sg-mr'),
            'token_ts' => __('Token-Zeitstempel', 'sg-mr'),
            'token_hash' => __('Token-Hash', 'sg-mr'),
            'url_name' => __('Name (aus URL)', 'sg-mr'),
            'url_email' => __('E-Mail (aus URL)', 'sg-mr'),
        ];
    }

    public static function forOrder(int $orderId): PrefillOrderBuilder
    {
        return new PrefillOrderBuilder($orderId);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function mappingAll(): array
    {
        $settings = get_option(Plugin::OPTION_FB_MAPPING, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $mapping = $settings['prefill_map'] ?? [];
        if (!is_array($mapping)) {
            $mapping = [];
        }
        foreach (['montage', 'etage'] as $mode) {
            if (!isset($mapping[$mode]) || !is_array($mapping[$mode])) {
                $mapping[$mode] = [];
            }
        }
        return $mapping;
    }

    /**
     * @return array<string, string>
     */
    public function mappingFor(string $mode): array
    {
        $mode = $mode === 'etage' ? 'etage' : 'montage';
        $mapping = $this->mappingAll();
        $map = $mapping[$mode] ?? [];
        if (!is_array($map)) {
            return [];
        }
        $clean = [];
        foreach ($map as $source => $target) {
            $sourceKey = sanitize_key((string) $source);
            $targetField = is_string($target) ? trim($target) : '';
            if ($sourceKey === '' || $targetField === '') {
                continue;
            }
            $clean[$sourceKey] = $targetField;
        }
        return $clean;
    }

    /**
     * Build payload for front-end prefill handling.
     *
     * @param array<string, string> $urlParams
     * @return array<string, mixed>
     */
    public function payloadFor(WC_Order $order, string $region, int $m, int $e, string $signature, array $urlParams = []): array
    {
        $region = sanitize_key($region);
        $m = max(0, $m);
        $e = max(0, $e);

        $fieldsData = $this->fieldsForOrder($order, $region, $m, $e);
        $strings = $fieldsData['strings'];
        $lists = $fieldsData['lists'];
        $itemsLines = $lists['items'] ?? [];
        $itemsStruct = $lists['items_struct'] ?? [];
        $itemsText = implode(', ', $itemsLines);
        $itemsMultiline = implode("\n", $itemsLines);

        $parsedSig = sgmr_booking_signature_parse($signature);
        $tokenTs = (int) ($parsedSig['ts'] ?? 0);
        $tokenHash = $parsedSig['hash'] ?? '';

        $orderContext = [
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'payment_method' => (string) $order->get_payment_method(),
            'payment_method_title' => (string) $order->get_payment_method_title(),
            'requested_mode' => $this->requestedMode($order),
        ];

        $person = [
            'full_name' => $strings['name'],
            'first_name' => $strings['first_name'],
            'last_name' => $strings['last_name'],
            'email' => $strings['email'],
            'phone' => $strings['phone'],
            'phone_delivery' => $strings['phone_delivery'],
            'phone_billing' => $strings['phone_billing'],
            'phone_shipping' => $strings['phone_shipping'],
        ];

        $addressParts = $this->splitStreet($strings['address_line1']);
        $address = [
            'street' => $addressParts['street'],
            'number' => $addressParts['number'],
            'line1' => $strings['address_line1'],
            'line2' => $strings['address_line2'],
            'postcode' => $strings['postcode'],
            'city' => $strings['city'],
            'country' => $strings['country'],
            'company' => $strings['company'],
        ];

        $items = [
            'list' => $itemsStruct,
            'text' => $itemsText,
            'lines' => $itemsLines,
            'multiline' => $itemsMultiline,
            'total_qty' => $fieldsData['items_total_qty'] ?? 0,
        ];

        $service = [
            'montage_count' => $m,
            'etage_count' => $e,
            'onsite_duration_minutes' => ScheduleService::minutesRequired($m, $e),
            'device_count' => $this->deviceCount($order),
        ];

        $routerMeta = [];
        if (isset($urlParams['router']) && is_array($urlParams['router'])) {
            $routerMeta = $this->sanitizeRouterMeta($urlParams['router']);
        }

        $routing = $this->routingInfo($region, $service, $orderContext['requested_mode'], $routerMeta);

        $tokenTtlSeconds = $this->tokenTtlSeconds();
        $token = [
            'sig' => $signature,
            'hash' => $tokenHash,
            'ts' => $tokenTs,
            'ttl' => $tokenTtlSeconds,
            'expires_at' => $tokenTs ? $tokenTs + $tokenTtlSeconds : null,
        ];

        $legacyFields = $this->stringify(array_merge($strings, [
            'token_sig' => $token['sig'],
            'token_ts' => $tokenTs ? (string) $tokenTs : '',
            'token_hash' => $tokenHash,
            'items' => $itemsText,
            'items_multiline' => $itemsMultiline,
            'items_total_qty' => (string) $items['total_qty'],
        ]));

        $stableFields = $this->buildStableFields($orderContext, $person, $address, $items, $service, $routing, $token);

        $postalCode = $this->sanitizePlain($address['postcode']);
        $customerName = $this->sanitizePlain($person['full_name']);
        $customerPhone = $this->sanitizePhone($person['phone']);
        $customerEmail = $this->sanitizeEmailValue($person['email']);
        $addressLine1 = $this->sanitizePlain($address['line1']);
        $addressLine2 = $this->sanitizePlain($address['line2']);
        $city = $this->sanitizePlain($address['city']);

        $orderAdminUrl = $this->sanitizeUrl(get_edit_post_link($order->get_id(), 'raw'));
        $orderViewUrl = $this->sanitizeUrl($order->get_view_order_url());
        $bexioReference = $this->sanitizePlain((string) $order->get_meta('_sgmr_bexio_ref', true));

        $products = $this->buildProducts($order);
        $categoryFlags = $this->collectCategoryFlags($order);
        $weight = $this->calculateWeight($m, $e);
        $durationMinutes = ScheduleService::minutesRequired($m, $e);
        $routerMetaOut = $this->buildRouterMeta($routing, $routerMeta);

        return [
            'order_id' => $orderContext['id'],
            'order_number' => $orderContext['number'],
            'order' => $orderContext,
            'counts' => [
                'm' => $m,
                'e' => $e,
            ],
            'person' => [
                'first_name' => $person['first_name'],
                'last_name' => $person['last_name'],
                'full_name' => $person['full_name'],
                'email' => $person['email'],
                'phone' => $person['phone'],
            ],
            'customer' => [
                'name' => $person['full_name'],
                'first_name' => $person['first_name'],
                'last_name' => $person['last_name'],
                'email' => $person['email'],
                'phone' => $person['phone'],
            ],
            'address' => $address,
            'items' => $items,
            'items_lines' => $itemsLines,
            'items_multiline' => $itemsMultiline,
            'service' => $service,
            'routing' => $routing,
            'token' => $token,
            'meta' => $this->metaForOrder($order),
            'fields' => [
                'legacy' => $legacyFields,
                'stable' => $stableFields,
            ],
            'mapping' => $this->mappingAll(),
            'router' => $routerMeta,
            'postal_code' => $postalCode,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'address_line1' => $addressLine1,
            'address_line2' => $addressLine2,
            'city' => $city,
            'order_admin_url' => $orderAdminUrl,
            'order_view_url' => $orderViewUrl,
            'bexio_reference' => $bexioReference,
            'products' => $products,
            'services' => [
                'montage' => $m,
                'etage' => $e,
            ],
            'weight' => $weight,
            'category_flags' => $categoryFlags,
            'duration_minutes' => $durationMinutes,
            'router_meta' => $routerMetaOut,
        ];
    }

    /**
     * @return array{strings: array<string, string>, lists: array<string, array<int, string>>}
     */
    private function fieldsForOrder(WC_Order $order, string $region, int $m, int $e): array
    {
        $shippingFirst = trim((string) $order->get_shipping_first_name());
        $shippingLast = trim((string) $order->get_shipping_last_name());
        $billingFirst = trim((string) $order->get_billing_first_name());
        $billingLast = trim((string) $order->get_billing_last_name());

        $firstName = $shippingFirst !== '' ? $shippingFirst : $billingFirst;
        $lastName = $shippingLast !== '' ? $shippingLast : $billingLast;

        $fullName = trim($firstName . ' ' . $lastName);
        if ($fullName === '') {
            $fullName = trim($billingFirst . ' ' . $billingLast);
        }
        if ($fullName === '') {
            $fullName = trim((string) $order->get_formatted_billing_full_name());
        }

        $address1 = (string) ($order->get_shipping_address_1() ?: $order->get_billing_address_1());
        $address2 = (string) ($order->get_shipping_address_2() ?: $order->get_billing_address_2());
        $postcode = (string) ($order->get_shipping_postcode() ?: $order->get_billing_postcode());
        $city = (string) ($order->get_shipping_city() ?: $order->get_billing_city());
        $country = (string) ($order->get_shipping_country() ?: $order->get_billing_country());
        $company = (string) ($order->get_shipping_company() ?: $order->get_billing_company());

        $deliveryPhone = (string) $order->get_meta('_sg_delivery_phone', true);
        if ($deliveryPhone === '') {
            $deliveryPhone = (string) ($order->get_shipping_phone() ?: $order->get_billing_phone());
        }

        $itemsInfo = $this->buildItems($order);
        $itemsLines = $itemsInfo['lines'];
        $itemsCompact = implode(', ', $itemsLines);
        $itemsMultiline = implode("\n", $itemsLines);

        $strings = [
            'name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => (string) $order->get_billing_email(),
            'phone' => $deliveryPhone,
            'phone_delivery' => $deliveryPhone,
            'phone_billing' => (string) $order->get_billing_phone(),
            'phone_shipping' => (string) $order->get_shipping_phone(),
            'address' => trim($address1 . ' ' . $address2),
            'address_line1' => $address1,
            'address_line2' => $address2,
            'postcode' => $postcode,
            'city' => $city,
            'company' => $company,
            'country' => $country,
            'region' => $region,
            'region_label' => PostcodeHelper::regionLabel($region),
            'region_source' => (string) $order->get_meta(CartService::META_REGION_SOURCE, true),
            'region_strategy' => (string) $order->get_meta(CartService::META_REGION_STRATEGY, true),
            'region_rule' => (string) $order->get_meta(CartService::META_REGION_RULE, true),
            'region_postcode' => (string) $order->get_meta(CartService::META_REGION_POSTCODE, true),
            'order' => (string) $order->get_id(),
            'order_number' => $order->get_order_number(),
            'bexio_ref' => (string) $order->get_meta('_sg_bexio_ref', true),
            'm' => (string) $m,
            'e' => (string) $e,
            'items' => $itemsCompact,
            'items_multiline' => $itemsMultiline,
            'items_total_qty' => (string) $itemsInfo['total_qty'],
            'counts_summary' => $this->serviceSummary($m, $e),
            'minutes_required' => (string) ScheduleService::minutesRequired($m, $e),
            'customer_note' => (string) $order->get_customer_note(),
        ];

        $lists = [
            'items' => $itemsLines,
            'items_struct' => $itemsInfo['list'],
        ];

        return [
            'strings' => $strings,
            'lists' => $lists,
            'items_total_qty' => $itemsInfo['total_qty'],
        ];
    }

    /**
     * @return array{lines: array<int, string>, total_qty: int}
     */
    private function buildItems(WC_Order $order): array
    {
        $lines = [];
        $totalQty = 0;
        $list = [];
        $currency = $order->get_currency();
        foreach ($order->get_items('line_item') as $item) {
            $qty = max(0, (int) $item->get_quantity());
            $totalQty += $qty;
            $qty = $qty > 0 ? $qty : 1;
            $name = trim((string) $item->get_name());
            if ($name === '') {
                $name = __('Position', 'sg-mr');
            }
            $lines[] = sprintf('%dx %s', $qty, $name);
            $product = $item->get_product();
            $sku = '';
            if ($product instanceof \WC_Product) {
                $sku = (string) $product->get_sku();
            }
            $list[] = [
                'sku' => $sku,
                'name' => $name,
                'qty' => $qty,
                'type' => 'product',
            ];
        }

        foreach ($order->get_items('fee') as $fee) {
            $name = $this->sanitizePlain($fee->get_name());
            if ($name === '') {
                $name = __('Gebühr', 'sg-mr');
            }
            $amount = $this->formatOrderAmount($fee->get_total(), $currency);
            $lines[] = sprintf('%s: %s', $name, $amount);
            $list[] = [
                'sku' => '',
                'name' => $name,
                'qty' => 1,
                'type' => 'fee',
                'total' => $amount,
            ];
        }

        foreach ($order->get_items('shipping') as $shipping) {
            $name = $this->sanitizePlain($shipping->get_name());
            if ($name === '') {
                $name = __('Versand', 'sg-mr');
            }
            $amount = $this->formatOrderAmount($shipping->get_total(), $currency);
            $lines[] = sprintf('%s: %s', $name, $amount);
            $list[] = [
                'sku' => '',
                'name' => $name,
                'qty' => 1,
                'type' => 'shipping',
                'total' => $amount,
            ];
        }

        return [
            'lines' => $lines,
            'total_qty' => $totalQty,
            'list' => $list,
        ];
    }

    private function sanitizeRouterMeta(array $router): array
    {
        $clean = [];
        if (isset($router['team'])) {
            $clean['team'] = sanitize_key((string) $router['team']);
        }
        if (isset($router['team_label'])) {
            $clean['team_label'] = (string) $router['team_label'];
        }
        if (isset($router['calendar_id'])) {
            $clean['calendar_id'] = (int) $router['calendar_id'];
        }
        if (isset($router['strategy'])) {
            $strategy = strtolower((string) $router['strategy']);
            $clean['strategy'] = preg_replace('/[^a-z0-9_\-]+/', '', $strategy);
        }
        if (isset($router['drive_minutes'])) {
            $clean['drive_minutes'] = (int) $router['drive_minutes'];
        }
        if (isset($router['selection_index'])) {
            $clean['selection_index'] = (int) $router['selection_index'];
        }
        return $clean;
    }

    private function sanitizePlain($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return sanitize_text_field((string) $value);
    }

    private function formatOrderAmount($amount, string $currency): string
    {
        $amount = (float) $amount;
        $formatted = wc_price($amount, ['currency' => $currency]);
        return wp_strip_all_tags($formatted, true);
    }

    private function sanitizePhone($value): string
    {
        $value = $this->sanitizePlain($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^0-9+\- ]+/', '', $value);
        return $value ? trim($value) : '';
    }

    private function sanitizeUrl($value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $url = esc_url_raw($value);
        return $url ?: '';
    }

    private function sanitizeEmailValue($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $email = sanitize_email((string) $value);
        return $email && is_email($email) ? $email : '';
    }

    private function buildProducts(WC_Order $order): array
    {
        $products = [];
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product instanceof WC_Product) {
                $product = wc_get_product($item->get_product_id());
            }
            $name = $this->sanitizePlain($item->get_name());
            $sku = '';
            if ($product instanceof WC_Product) {
                $sku = $this->sanitizePlain($product->get_sku());
            }
            $products[] = [
                'name' => $name,
                'sku' => $sku,
                'qty' => max(0, (int) $item->get_quantity()),
            ];
        }
        return $products;
    }

    private function collectCategoryFlags(WC_Order $order): array
    {
        $settings = Settings::getSettings();
        $rawSlugs = $settings['phone_category_slugs'] ?? [];
        if (!is_array($rawSlugs) || !$rawSlugs) {
            return [];
        }
        $lookup = [];
        foreach ($rawSlugs as $slug) {
            $normalized = sanitize_title((string) $slug);
            if ($normalized !== '') {
                $lookup[$normalized] = true;
            }
        }
        if (!$lookup) {
            return [];
        }
        $flags = [];
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product instanceof WC_Product) {
                $product = wc_get_product($item->get_product_id());
            }
            if (!$product instanceof WC_Product) {
                continue;
            }
            $terms = wp_get_object_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
            if (is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $slug) {
                if (isset($lookup[$slug])) {
                    $flags[] = $slug;
                }
            }
        }
        if (!$flags) {
            return [];
        }
        return array_values(array_unique($flags));
    }

    private function calculateWeight(int $montage, int $etage): float
    {
        $weight = (float) $montage + (0.5 * (float) $etage);
        return round($weight, 2);
    }

    private function buildRouterMeta(array $routing, array $incoming): array
    {
        $strategy = $incoming['strategy'] ?? ($routing['strategy'] ?? '');
        $distance = $incoming['drive_minutes'] ?? ($routing['drive_minutes'] ?? null);

        $calendarId = isset($routing['calendar_id']) ? (int) $routing['calendar_id'] : (int) ($incoming['calendar_id'] ?? 0);
        $calendarId = $calendarId > 0 ? $calendarId : 0;
        $eventId = isset($routing['event_id']) ? (int) $routing['event_id'] : (int) ($incoming['event_id'] ?? 0);
        $eventId = $eventId > 0 ? $eventId : 0;

        $distanceMinutes = null;
        if ($distance !== null) {
            $distanceMinutes = max(0, (int) $distance);
        }

        return [
            'region' => $this->sanitizePlain($routing['region_key'] ?? ''),
            'team' => $this->sanitizePlain($routing['team_id'] ?? ($incoming['team'] ?? '')),
            'calendar_id' => $calendarId,
            'strategy' => $this->sanitizePlain($strategy),
            'distance_minutes' => $distanceMinutes,
            'event_id' => $eventId,
        ];
    }

    private function serviceSummary(int $m, int $e): string
    {
        $parts = [];
        $parts[] = sprintf(_n('%d Montage', '%d Montagen', $m, 'sg-mr'), $m);
        $parts[] = sprintf(_n('%d Etagenlieferung', '%d Etagenlieferungen', $e, 'sg-mr'), $e);
        return implode(' · ', $parts);
    }

    private function requestedMode(WC_Order $order): string
    {
        $mode = (string) $order->get_meta(CartService::META_TERMIN_MODE, true);
        return $mode === 'telefonisch' ? 'telefonisch' : 'online';
    }

    private function deviceCount(WC_Order $order): int
    {
        $stored = (int) $order->get_meta(CartService::META_DEVICE_COUNT, true);
        if ($stored > 0) {
            return $stored;
        }
        $total = 0;
        foreach ($order->get_items('line_item') as $item) {
            $qty = (int) $item->get_quantity();
            if ($qty > 0) {
                $total += $qty;
            }
        }
        return $total;
    }

    private function splitStreet(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return ['street' => '', 'number' => ''];
        }
        if (preg_match('/^(.*?)(\d+[\w\/-]*)\s*$/u', $line, $matches)) {
            return [
                'street' => trim($matches[1]),
                'number' => trim($matches[2]),
            ];
        }
        return ['street' => $line, 'number' => ''];
    }

    private function routingInfo(string $region, array $service, string $requestedMode, array $routerMeta = []): array
    {
        $region = sanitize_key($region);
        $modeKey = $service['montage_count'] > 0 ? 'montage' : 'etage';

        $settings = get_option(Plugin::OPTION_FB_MAPPING, []);
        $teamId = '';
        $teamLabel = '';
        $eventId = 0;
        $calendarId = isset($routerMeta['calendar_id']) ? (int) $routerMeta['calendar_id'] : 0;
        $strategy = isset($routerMeta['strategy']) ? (string) $routerMeta['strategy'] : '';
        $driveMinutes = isset($routerMeta['drive_minutes']) ? (int) $routerMeta['drive_minutes'] : null;

        if (!empty($routerMeta['team'])) {
            $teamId = sanitize_key((string) $routerMeta['team']);
        }
        if (!empty($routerMeta['team_label'])) {
            $teamLabel = (string) $routerMeta['team_label'];
        }

        if (($teamId === '' || $teamLabel === '') && is_array($settings)) {
            $regionTeams = $settings['regions'][$region] ?? [];
            if ($teamId === '' && is_array($regionTeams) && $regionTeams) {
                $teamId = sanitize_key((string) reset($regionTeams));
            }
            if ($teamId !== '') {
                if ($teamLabel === '' && !empty($settings['teams'][$teamId]['label'])) {
                    $teamLabel = (string) $settings['teams'][$teamId]['label'];
                } elseif ($teamLabel === '') {
                    $teamLabel = strtoupper($teamId);
                }
                if ($eventId === 0 && !empty($settings['region_events'][$region][$teamId][$modeKey])) {
                    $eventId = (int) $settings['region_events'][$region][$teamId][$modeKey];
                }
            }
        }

        if ($teamLabel === '' && $teamId !== '') {
            $teamLabel = strtoupper($teamId);
        }

        return [
            'region_key' => $region,
            'region_label' => PostcodeHelper::regionLabel($region),
            'team_id' => $teamId,
            'team_label' => $teamLabel,
            'event_id' => $eventId,
            'event_mode' => $modeKey,
            'requested_mode' => $requestedMode,
            'calendar_id' => $calendarId,
            'strategy' => $strategy,
            'drive_minutes' => $driveMinutes,
        ];
    }

    private function buildStableFields(array $orderContext, array $person, array $address, array $items, array $service, array $routing, array $token): array
    {
        $addressLine = trim(sprintf(
            '%s %s, %s %s',
            $address['street'],
            $address['number'],
            $address['postcode'],
            $address['city']
        ));
        $itemsJson = wp_json_encode($items['list'] ?? []);
        if (!$itemsJson) {
            $itemsJson = '[]';
        }

        return [
            'sg_first_name' => $person['first_name'],
            'sg_last_name' => $person['last_name'],
            'sg_full_name' => $person['full_name'],
            'sg_email' => $person['email'],
            'sg_phone' => $person['phone'],
            'sg_delivery_street' => $address['street'],
            'sg_delivery_number' => $address['number'],
            'sg_delivery_postcode' => $address['postcode'],
            'sg_delivery_city' => $address['city'],
            'sg_delivery_country' => $address['country'],
            'sg_delivery_address' => $addressLine,
            'sg_delivery_line2' => $address['line2'],
            'sg_company' => $address['company'],
            'sg_items_text' => $items['text'],
            'sg_items_lines' => implode("\n", $items['lines'] ?? []),
            'sg_items_json' => $itemsJson,
            'sg_items_total_qty' => (string) ($items['total_qty'] ?? 0),
            'sg_order_id' => (string) $orderContext['id'],
            'sg_order_number' => (string) $orderContext['number'],
            'sg_payment_method' => $orderContext['payment_method'],
            'sg_payment_method_title' => $orderContext['payment_method_title'],
            'sg_requested_mode' => $orderContext['requested_mode'],
            'sg_region_key' => $routing['region_key'],
            'sg_region_label' => $routing['region_label'],
            'sg_team_id' => $routing['team_id'],
            'sg_team_label' => $routing['team_label'],
            'sg_event_id' => $routing['event_id'] ? (string) $routing['event_id'] : '',
            'sg_event_mode' => $routing['event_mode'],
            'sg_calendar_id' => isset($routing['calendar_id']) ? (string) $routing['calendar_id'] : '',
            'sg_router_strategy' => isset($routing['strategy']) ? (string) $routing['strategy'] : '',
            'sg_router_drive_minutes' => isset($routing['drive_minutes']) && $routing['drive_minutes'] !== null ? (string) $routing['drive_minutes'] : '',
            'sg_token_sig' => $token['sig'],
            'sg_token_ts' => $token['ts'] ? (string) $token['ts'] : '',
            'sg_token_hash' => $token['hash'],
            'sg_service_montage' => (string) $service['montage_count'],
            'sg_service_etage' => (string) $service['etage_count'],
            'sg_service_minutes' => (string) $service['onsite_duration_minutes'],
            'sg_service_devices' => (string) $service['device_count'],
            'sg_service_summary' => $this->serviceSummary($service['montage_count'], $service['etage_count']),
        ];
    }

    private function metaForOrder(WC_Order $order): array
    {
        $meta = [
            'bexio_ref' => (string) $order->get_meta('_sg_bexio_ref', true),
            'region_source' => (string) $order->get_meta(CartService::META_REGION_SOURCE, true),
            'region_lookup' => (string) $order->get_meta(CartService::META_REGION_STRATEGY, true),
            'region_rule' => (string) $order->get_meta(CartService::META_REGION_RULE, true),
            'region_postcode' => (string) $order->get_meta(CartService::META_REGION_POSTCODE, true),
        ];

        return array_filter($meta, static fn ($value) => $value !== '');
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    private function stringify(array $fields): array
    {
        $strings = [];
        foreach ($fields as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $strings[$key] = $value === null ? '' : (string) $value;
            } else {
                $strings[$key] = '';
            }
        }
        return $strings;
    }

    private function tokenTtlSeconds(): int
    {
        $settings = Settings::getSettings();
        $hours = (int) ($settings['token_ttl_hours'] ?? 96);
        if ($hours <= 0) {
            $hours = 1;
        }
        return $hours * HOUR_IN_SECONDS;
    }
}

class PrefillOrderBuilder
{
    private int $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $order = wc_get_order($this->orderId);
        if (!$order instanceof WC_Order) {
            throw new RuntimeException(__('Order not found.', 'sg-mr'));
        }

        $region = sgmr_normalize_region_slug((string) $order->get_meta(CartService::META_REGION_KEY, true));
        if ($region === '') {
            $region = 'on_request';
        }

        $counts = ['montage' => 0, 'etage' => 0];
        if (class_exists(CartService::class) && method_exists(CartService::class, 'ensureOrderCounts')) {
            $resolved = CartService::ensureOrderCounts($order);
            if (is_array($resolved)) {
                $counts = $resolved;
            }
        }

        $montage = max(0, (int) ($counts['montage'] ?? 0));
        $etage = max(0, (int) ($counts['etage'] ?? 0));

        $signatureParams = [
            'region' => $region,
            'sgm' => $montage,
            'sge' => $etage,
        ];
        $signature = sgmr_booking_signature($this->orderId, $signatureParams, time());

        return Plugin::instance()->prefillManager()->payloadFor($order, $region, $montage, $etage, $signature);
    }
}
