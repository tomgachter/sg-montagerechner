<?php

namespace SGMR\Booking;

use SGMR\Plugin;
use SGMR\Services\CartService;
use SGMR\Services\BookingLink;
use SGMR\Utils\PostcodeHelper;
use WC_Order;

class BookingConfig
{
    public function settings(): array
    {
        $defaults = [
            'teams' => [],
            'regions' => [],
            'region_events' => [],
            'region_days' => [],
        ];
        $stored = get_option(Plugin::OPTION_BOOKING_MAPPING, null);
        if ($stored === null) {
            $legacy = get_option('sg_fb_mapping', null);
            if (is_array($legacy)) {
                update_option(Plugin::OPTION_BOOKING_MAPPING, $legacy, true);
                delete_option('sg_fb_mapping');
                $stored = $legacy;
            }
        }
        if (!is_array($stored)) {
            return $defaults;
        }
        $stored['teams'] = isset($stored['teams']) && is_array($stored['teams']) ? $stored['teams'] : [];
        $stored['regions'] = isset($stored['regions']) && is_array($stored['regions']) ? $stored['regions'] : [];
        return wp_parse_args($stored, $defaults);
    }

    public function onsiteBuffer(): int
    {
        return 0;
    }

    public function regionTeams(string $region): array
    {
        $settings = $this->settings();
        $region = sanitize_key($region);
        if (!empty($settings['regions'][$region]) && is_array($settings['regions'][$region])) {
            return array_values(array_filter(array_map('sanitize_key', $settings['regions'][$region])));
        }
        return [];
    }

    public function team(string $teamKey): array
    {
        $settings = $this->settings();
        $teamKey = sanitize_key($teamKey);
        return $settings['teams'][$teamKey] ?? [];
    }

    public function teamByCalendarId(int $calendarId): ?string
    {
        $calendarId = (int) $calendarId;
        if ($calendarId <= 0) {
            return null;
        }
        $settings = $this->settings();
        foreach ($settings['teams'] as $key => $config) {
            if (!is_array($config)) {
                continue;
            }
            if (isset($config['calendar_id']) && (int) $config['calendar_id'] === $calendarId) {
                return sanitize_key((string) $key);
            }
        }
        return null;
    }

    public function pickPrimaryTeam(string $region): ?string
    {
        $teams = $this->regionTeams($region);
        if (!$teams) {
            return null;
        }
        $state = get_option('sg_booking_rr_state', null);
        if (!is_array($state)) {
            $legacy = get_option('sg_fb_rr_state', null);
            if (is_array($legacy)) {
                update_option('sg_booking_rr_state', $legacy, false);
                delete_option('sg_fb_rr_state');
                $state = $legacy;
            }
        }
        if (!is_array($state)) {
            $state = [];
        }
        $last = $state[$region] ?? null;
        $next = null;
        if ($last && false !== ($idx = array_search($last, $teams, true))) {
            $next = $teams[($idx + 1) % count($teams)];
        } else {
            $next = $teams[0];
        }
        return $next;
    }

    public function recordTeamSelection(string $region, string $team): void
    {
        $teams = $this->regionTeams($region);
        if (!in_array($team, $teams, true)) {
            return;
        }
        $state = get_option('sg_booking_rr_state', null);
        if (!is_array($state)) {
            $legacy = get_option('sg_fb_rr_state', null);
            if (is_array($legacy)) {
                $state = $legacy;
                delete_option('sg_fb_rr_state');
            }
        }
        if (!is_array($state)) {
            $state = [];
        }
        $state[$region] = $team;
        update_option('sg_booking_rr_state', $state, false);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function slotIsFree(string $teamKey, string $date, int $slotIndex, array $context = []): bool
    {
        $default = true;
        return (bool) apply_filters('sg_mr_slot_is_free', $default, $teamKey, $date, $slotIndex, $context);
    }

    public function slotBookings(string $teamKey, string $date, int $slotIndex): array
    {
        $default = ['montage' => 0, 'etage' => 0];
        $bookings = apply_filters('sg_mr_slot_bookings', $default, $teamKey, $date, $slotIndex);
        if (!is_array($bookings)) {
            $bookings = $default;
        }
        $bookings['montage'] = isset($bookings['montage']) ? (int) $bookings['montage'] : 0;
        $bookings['etage'] = isset($bookings['etage']) ? (int) $bookings['etage'] : 0;
        return $bookings;
    }

    public function createSlotBooking(array $payload): void
    {
        do_action_ref_array('sg_mr_create_slot_booking', [&$payload]);
    }

    public function buildBookingLink(WC_Order $order): ?string
    {
        $regionKey = (string) $order->get_meta(CartService::META_REGION_KEY);
        if (!$regionKey || $regionKey === 'on_request') {
            return '';
        }
        return BookingLink::build($order, $regionKey);
    }

    public function autopopulateParams(WC_Order $order): array
    {
        $shippingName = trim($order->get_shipping_first_name().' '.$order->get_shipping_last_name());
        $billingName = trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
        $name = $shippingName ?: $billingName;
        $address = trim($order->get_shipping_address_1().' '.$order->get_shipping_address_2());
        if (!$address) {
            $address = trim($order->get_billing_address_1().' '.$order->get_billing_address_2());
        }
        $postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        $city = $order->get_shipping_city() ?: $order->get_billing_city();
        $phone = $order->get_meta('_sg_delivery_phone') ?: ($order->get_shipping_phone() ?: $order->get_billing_phone());
        $flags = $this->modeFlags($order);
        $positions = [];
        foreach ($order->get_items('line_item') as $item) {
            $positions[] = sprintf('%dx %s', $item->get_quantity(), $item->get_name());
        }
        $montageCount = (int) $order->get_meta(CartService::META_MONTAGE_COUNT);
        $etageCount = (int) $order->get_meta(CartService::META_ETAGE_COUNT);
        $region = (string) $order->get_meta(CartService::META_REGION_KEY);

        return [
            'order_id' => (string) $order->get_id(),
            'name' => $name,
            'email' => $order->get_billing_email(),
            'phone' => $phone,
            'address' => $address,
            'postcode' => $postcode,
            'city' => $city,
            'positions' => implode(' | ', $positions),
            'montage' => $flags['montage'] ? '1' : '0',
            'etagenlieferung' => $flags['etage'] ? '1' : '0',
            'altgeraet' => $flags['altgeraet'] ? '1' : '0',
            'express_flag' => $order->get_meta(CartService::META_EXPRESS_FLAG) ? '1' : '0',
            'region' => $region,
            'm' => (string) max(0, $montageCount),
            'e' => (string) max(0, $etageCount),
            'order' => (string) $order->get_id(),
        ];
    }

    private function modeFlags(WC_Order $order): array
    {
        $flags = ['montage'=>false,'etage'=>false,'altgeraet'=>false];
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
        return $flags;
    }

    public function renderTeamShortcode(string $teamKey, string $type): string
    {
        $team = $this->team($teamKey);
        if (!$team) {
            return '';
        }
        $shortcode = $type === 'etage' ? ($team['etage_shortcode'] ?? '') : ($team['montage_shortcode'] ?? '');
        if (!$shortcode) {
            return '';
        }
        return do_shortcode($shortcode);
    }

    public function regionEventIds(string $region, string $teamKey): array
    {
        $settings = $this->settings();
        $region = sanitize_key($region);
        $teamKey = sanitize_key($teamKey);
        return $settings['region_events'][$region][$teamKey] ?? ['montage' => 0, 'etage' => 0];
    }
}
