<?php

namespace SGMR\Utils;

use SGMR\Plugin;
use SGMR\Services\CartService;
use WC_Order;
use function sgmr_normalize_region_slug;

class PostcodeHelper
{
    public static function currentPostcode(bool $fromRequest = false): string
    {
        if ($fromRequest) {
            if (!empty($_POST['shipping_postcode'])) {
                return preg_replace('/\D/', '', wc_clean(wp_unslash($_POST['shipping_postcode'])));
            }
            if (!empty($_POST['billing_postcode'])) {
                return preg_replace('/\D/', '', wc_clean(wp_unslash($_POST['billing_postcode'])));
            }
        }
        $session = WC()->session ? (string) WC()->session->get(Plugin::SESSION_POSTCODE, '') : '';
        if ($session) {
            return $session;
        }
        $customer = WC()->customer;
        if ($customer) {
            $value = $customer->get_shipping_postcode() ?: $customer->get_billing_postcode();
            if ($value) {
                return preg_replace('/\D/', '', $value);
            }
        }
        return '';
    }

    public static function currentCountry(bool $fromRequest = false): string
    {
        if ($fromRequest) {
            if (!empty($_POST['shipping_country'])) {
                return strtoupper(wc_clean(wp_unslash($_POST['shipping_country'])));
            }
            if (!empty($_POST['billing_country'])) {
                return strtoupper(wc_clean(wp_unslash($_POST['billing_country'])));
            }
        }
        $session = WC()->session ? (string) WC()->session->get(Plugin::SESSION_COUNTRY, '') : '';
        if ($session) {
            return strtoupper($session);
        }
        $customer = WC()->customer;
        if ($customer) {
            $value = $customer->get_shipping_country() ?: $customer->get_billing_country();
            if ($value) {
                return strtoupper($value);
            }
        }
        return '';
    }

    private static function radiusMinutes(): int
    {
        $params = get_option(\SG_Montagerechner_V3::OPT_PARAMS, []);
        $radius = isset($params['out_radius_min']) ? (int) $params['out_radius_min'] : 60;
        return $radius > 0 ? $radius : 60;
    }

    public static function postcodeAllowsService(string $postcode, string $country): bool
    {
        if (!$postcode) {
            return false;
        }
        if ($country && strtoupper($country) !== 'CH') {
            return false;
        }
        $row = self::lookupPostcode($postcode);
        if (!$row) {
            return false;
        }
        $minutes = isset($row['minutes']) ? (int) $row['minutes'] : 9999;
        return $minutes <= self::radiusMinutes();
    }

    public static function persistOrderContext(WC_Order $order, array $selection, array $options = []): array
    {
        $resolver = Plugin::instance()->regionResolver();
        $radius = self::radiusMinutes();

        $servicePostcode = isset($options['service_postcode']) ? preg_replace('/\D/', '', (string) $options['service_postcode']) : self::currentPostcode();
        $serviceCountry = isset($options['service_country']) ? strtoupper((string) $options['service_country']) : self::currentCountry();

        $candidates = [];
        $shippingPostcode = preg_replace('/\D/', '', (string) $order->get_shipping_postcode());
        if ($shippingPostcode !== '') {
            $candidates[] = [
                'source' => 'shipping',
                'postcode' => $shippingPostcode,
                'country' => strtoupper((string) $order->get_shipping_country()) ?: $serviceCountry,
            ];
        }
        $billingPostcode = preg_replace('/\D/', '', (string) $order->get_billing_postcode());
        if ($billingPostcode !== '' && $billingPostcode !== $shippingPostcode) {
            $candidates[] = [
                'source' => 'billing',
                'postcode' => $billingPostcode,
                'country' => strtoupper((string) $order->get_billing_country()) ?: $serviceCountry,
            ];
        }
        if ($servicePostcode !== '' && $servicePostcode !== $shippingPostcode && $servicePostcode !== $billingPostcode) {
            $candidates[] = [
                'source' => 'service_plz',
                'postcode' => $servicePostcode,
                'country' => $serviceCountry,
            ];
        }

        if (!$candidates) {
            $candidates[] = [
                'source' => 'service_plz',
                'postcode' => $servicePostcode,
                'country' => $serviceCountry,
            ];
        }

        $chosenRecord = null;
        $chosenSource = 'none';
        $chosenPostcode = '';
        $chosenCountry = $serviceCountry;
        $lastRecord = null;

        foreach ($candidates as $candidate) {
            $postcode = $candidate['postcode'];
            if ($postcode === '') {
                continue;
            }
            $record = $resolver->getPostcodeRecord($postcode);
            if ($record) {
                $lastRecord = $record + ['source' => $candidate['source']];
                if (!empty($record['region'])) {
                    $chosenRecord = $record;
                    $chosenSource = $candidate['source'];
                    $chosenPostcode = $postcode;
                    $chosenCountry = $candidate['country'];
                    break;
                }
                if ($chosenPostcode === '') {
                    $chosenPostcode = $postcode;
                    $chosenCountry = $candidate['country'];
                }
            }
        }

        if (!$chosenRecord && $lastRecord) {
            $chosenRecord = $lastRecord;
            $chosenSource = $lastRecord['source'] ?? 'service_plz';
        }

        $regionSlug = $chosenRecord && !empty($chosenRecord['region'])
            ? sgmr_normalize_region_slug((string) $chosenRecord['region'])
            : '';
        $lookupStrategy = 'none';
        $ruleId = $chosenRecord['rule'] ?? null;
        $minutes = isset($chosenRecord['minutes']) ? (int) $chosenRecord['minutes'] : 9999;
        if ($chosenRecord) {
            $strategy = sanitize_key($chosenRecord['strategy'] ?? '');
            if ($strategy === 'cache') {
                $lookupStrategy = 'cache';
            } elseif ($strategy !== '') {
                $lookupStrategy = 'rae';
            }
        }

        if (!$regionSlug) {
            $regionSlug = 'on_request';
        }

        $regionLabel = self::regionLabel($regionSlug);
        $allowed = ($chosenRecord && isset($chosenRecord['minutes'])) ? ((int) $chosenRecord['minutes'] <= $radius) : false;

        $order->update_meta_data(CartService::META_POSTCODE, $chosenPostcode ?: $servicePostcode);
        $order->update_meta_data(CartService::META_COUNTRY, $chosenCountry ?: $serviceCountry);
        $order->update_meta_data(CartService::META_REGION_KEY, $regionSlug);
        $order->update_meta_data(CartService::META_REGION_LABEL, $regionLabel);
        $order->update_meta_data(CartService::META_REGION_ON_REQUEST, (!$allowed || $regionSlug === 'on_request') ? 1 : 0);
        $order->update_meta_data(CartService::META_REGION_SOURCE, $chosenSource ?: 'none');
        $order->update_meta_data(CartService::META_REGION_STRATEGY, $lookupStrategy);
        $order->update_meta_data(CartService::META_REGION_POSTCODE, $chosenPostcode ?: $servicePostcode);
        if ($ruleId) {
            $order->update_meta_data(CartService::META_REGION_RULE, sanitize_key($ruleId));
        } else {
            $order->delete_meta_data(CartService::META_REGION_RULE);
        }
        $order->update_meta_data('_sg_plz_minutes', $minutes);

        $devices = 0;
        $express = false;
        foreach ($selection as $row) {
            $qty = isset($row['qty']) ? (int) $row['qty'] : 1;
            if ($qty < 1) {
                $qty = 1;
            }
            $devices += $qty;
            if (!empty($row['express'])) {
                $express = true;
            }
        }

        $logContext = [
            'order_id' => $order->get_id(),
            'region' => $regionSlug,
            'region_label' => $regionLabel,
            'region_source' => $chosenSource ?: 'none',
            'region_lookup' => $lookupStrategy,
            'region_rule' => $ruleId ?: '',
            'postcode' => $chosenPostcode ?: $servicePostcode,
            'country' => $chosenCountry ?: $serviceCountry,
            'minutes' => $minutes,
            'allowed' => $allowed ? 'yes' : 'no',
        ];

        if (function_exists('sgmr_log')) {
            sgmr_log('region_assignment', $logContext);
        }

        if ($regionSlug === 'on_request') {
            $order->add_order_note(__('[SGMR] Region konnte nicht automatisch bestimmt werden. Bitte Region im Auftrag prüfen und festlegen.', 'sg-mr'));
        }

        return [
            'device_count' => $devices,
            'express' => $express,
            'force_offline' => CartService::cartForceOffline(),
            'region' => $regionSlug,
            'region_source' => $chosenSource ?: 'none',
            'region_lookup' => $lookupStrategy,
            'region_rule' => $ruleId ?: '',
            'region_postcode' => $chosenPostcode ?: $servicePostcode,
            'region_allowed' => $allowed,
        ];
    }

    public static function lookupPostcode(string $postcode): ?array
    {
        $resolver = Plugin::instance()->regionResolver();
        $record = $resolver->getPostcodeRecord($postcode);
        if ($record) {
            return [
                'minutes' => $record['minutes'],
                'region' => $record['region'],
                'data' => $record,
            ];
        }

        // Legacy fallback if resolver cannot locate the postcode
        $map = get_transient('sg_mr_postcode_cache_v4');
        if (!is_array($map)) {
            $map = [];
            $uploads = wp_upload_dir();
            $fileUploads = isset($uploads['basedir']) ? trailingslashit($uploads['basedir']) . \SG_Montagerechner_V3::CSV_BASENAME : '';
            $filePlugin = trailingslashit(dirname(__DIR__, 2)) . \SG_Montagerechner_V3::CSV_BASENAME;
            $file = file_exists($fileUploads) ? $fileUploads : (file_exists($filePlugin) ? $filePlugin : '');
            if ($file && file_exists($file)) {
                $rows = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($rows) {
                    $delimiter = self::detectDelimiter($rows[0]);
                    $header = array_map('trim', str_getcsv($rows[0], $delimiter));
                    $minutesIdx = self::detectHeaderIndex($header, ['min','minutes','fahrzeit']);
                    foreach ($rows as $idx => $line) {
                        if ($idx === 0) {
                            continue;
                        }
                        $cols = array_map('trim', str_getcsv($line, $delimiter));
                        $plz = preg_replace('/\D/', '', $cols[0] ?? '');
                        if (!$plz) {
                            continue;
                        }
                        $minutes = isset($cols[$minutesIdx]) ? (int) $cols[$minutesIdx] : 9999;
                        $map[$plz] = [
                            'minutes' => $minutes,
                            'region' => '',
                        ];
                    }
                }
            }
            set_transient('sg_mr_postcode_cache_v4', $map, DAY_IN_SECONDS);
        }
        return $map[$postcode] ?? null;
    }

    private static function detectHeaderIndex(array $header, array $candidates): ?int
    {
        foreach ($header as $idx => $name) {
            $name = strtolower($name);
            foreach ($candidates as $candidate) {
                if (strpos($name, strtolower($candidate)) !== false) {
                    return $idx;
                }
            }
        }
        return null;
    }

    private static function detectDelimiter(string $header): string
    {
        $comma = substr_count($header, ',');
        $semi = substr_count($header, ';');
        return $semi > $comma ? ';' : ',';
    }

    public static function regionLabel(string $region): string
    {
        $legacyMap = [
            'zurich_limmattal' => 'zuerich_limmattal',
            'aargau_sued_zentral' => 'aargau_sued_zentralschweiz',
        ];
        if (isset($legacyMap[$region])) {
            $region = $legacyMap[$region];
        }
        $labels = [
            'zuerich_limmattal' => __('Zürich/Limmattal', 'sg-mr'),
            'basel_fricktal' => __('Basel/Fricktal', 'sg-mr'),
            'aargau_sued_zentralschweiz' => __('Aargau Süd/Zentralschweiz', 'sg-mr'),
            'mittelland_west' => __('Mittelland West', 'sg-mr'),
        ];
        return $labels[$region] ?? __('Montage auf Anfrage', 'sg-mr');
    }
}
