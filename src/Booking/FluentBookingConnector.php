<?php

namespace SGMR\Booking;

use DateTimeImmutable;
use DateTimeZone;
use SGMR\Plugin;
use WC_Order;
use WC_Order_Query;
use WP_Error;
use function add_action;
use function add_filter;
use function array_filter;
use function get_option;
use function implode;
use function is_array;
use function is_wp_error;
use function rest_url;
use function sanitize_key;
use function sgmr_log;
use function trim;
use function wp_json_encode;
use function wp_parse_args;
use function wp_remote_request;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function wp_timezone;
use function wp_timezone_string;
use function wc_get_order_statuses;
use function __;

class FluentBookingConnector
{
    private FluentBookingClient $client;

    /** @var array<string, mixed> */
    private array $apiConfig = [];

    /** @var array<string, array<string, array<int, array<string, int>>>> */
    private array $ledger = [];

    private bool $ledgerLoaded = false;

    public function __construct(FluentBookingClient $client)
    {
        $this->client = $client;
        $config = get_option(Plugin::OPTION_FB_API, []);
        $this->apiConfig = is_array($config) ? wp_parse_args($config, ['base_url' => '', 'token' => '', 'timeout' => 15]) : ['base_url' => '', 'token' => '', 'timeout' => 15];
    }

    public function boot(): void
    {
        add_filter('sg_mr_fb_slot_bookings', [$this, 'filterSlotBookings'], 10, 4);
        add_filter('sg_mr_fb_slot_is_free', [$this, 'filterSlotIsFree'], 10, 5);
        add_action('sg_mr_fb_create_slot_booking', [$this, 'handleCreate'], 10, 1);
        add_action('sg_mr_fb_cancel_slot_booking', [$this, 'handleCancelSlot'], 10, 4);
        add_action('sg_mr_fb_cancel_selector_booking', [$this, 'handleCancelSelector'], 10, 3);
    }

    /**
     * @param array<string, int> $default
     * @return array<string, int>
     */
    public function filterSlotBookings(array $default, string $team, string $date, int $slotIndex): array
    {
        $this->ensureLedger();
        $teamKey = sanitize_key($team);
        $day = sanitize_key($date);
        if (isset($this->ledger[$teamKey][$day][$slotIndex])) {
            return wp_parse_args($this->ledger[$teamKey][$day][$slotIndex], ['montage' => 0, 'etage' => 0]);
        }
        return ['montage' => 0, 'etage' => 0];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function filterSlotIsFree(bool $default, string $team, string $date, int $slotIndex, array $context): bool
    {
        $counts = $this->filterSlotBookings(['montage' => 0, 'etage' => 0], $team, $date, $slotIndex);
        $montageBooked = (int) ($counts['montage'] ?? 0);
        $etageBooked = (int) ($counts['etage'] ?? 0);
        $montageRequested = isset($context['montage']) ? (int) $context['montage'] : 0;
        $etageRequested = isset($context['etage']) ? (int) $context['etage'] : 0;

        if ($montageRequested > 0) {
            return $montageBooked === 0 && $etageBooked === 0;
        }
        if ($etageRequested > 0) {
            return $montageBooked === 0 && ($etageBooked + $etageRequested) <= 2;
        }
        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleCreate(array &$payload): void
    {
        if (!empty($payload['remote_booking_id']) && (($payload['source'] ?? '') === 'webhook')) {
            return;
        }

        if (!$this->apiReady()) {
            $payload['error'] = __('FluentBooking API ist nicht konfiguriert.', 'sg-mr');
            return;
        }

        $response = $this->createRemoteBooking($payload);
        if (is_wp_error($response)) {
            $payload['error'] = $response->get_error_message();
            sgmr_log('fluent_booking_create_failed', [
                'order_id' => $payload['order_id'] ?? 0,
                'team' => $payload['team'] ?? '',
                'date' => $payload['date'] ?? '',
                'slot_index' => $payload['slot_index'] ?? null,
                'error' => $payload['error'],
            ]);
            return;
        }

        $payload['remote_response'] = $response;
        $payload['remote_booking_id'] = isset($response['id']) ? (string) $response['id'] : ((string) ($response['booking_id'] ?? ''));
        $this->ledgerLoaded = false;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $payload
     */
    public function handleCancelSlot(array &$entry, int $orderId, string $reason, array $payload): void
    {
        $remoteId = isset($entry['remote_booking_id']) ? (string) $entry['remote_booking_id'] : '';
        if ($remoteId === '') {
            $entry['cancelled'] = true;
            $this->ledgerLoaded = false;
            return;
        }
        if (!$this->apiReady()) {
            $entry['error'] = __('FluentBooking API ist nicht konfiguriert.', 'sg-mr');
            return;
        }

        $result = $this->request('DELETE', 'schedules/' . rawurlencode($remoteId), ['reason' => $reason]);
        if (is_wp_error($result)) {
            $entry['error'] = $result->get_error_message();
            return;
        }

        $entry['cancelled'] = true;
        $this->ledgerLoaded = false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleCancelSelector(string $selectorBookingId, int $orderId, array $payload): void
    {
        $selectorBookingId = trim($selectorBookingId);
        if ($selectorBookingId === '') {
            return;
        }
        if (!$this->apiReady()) {
            sgmr_log('fluent_booking_selector_cancel_skipped', [
                'order_id' => $orderId,
                'selector_booking_id' => $selectorBookingId,
                'reason' => 'api_not_configured',
            ]);
            return;
        }

        $result = $this->request('DELETE', 'schedules/' . rawurlencode($selectorBookingId), ['reason' => 'selector']);
        if (is_wp_error($result)) {
            sgmr_log('fluent_booking_selector_cancel_failed', [
                'order_id' => $orderId,
                'selector_booking_id' => $selectorBookingId,
                'error' => $result->get_error_message(),
            ]);
            return;
        }

        sgmr_log('fluent_booking_selector_cancelled', [
            'order_id' => $orderId,
            'selector_booking_id' => $selectorBookingId,
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function createRemoteBooking(array $payload)
    {
        $body = $this->buildBookingBody($payload, true);
        if (empty($body)) {
            return new WP_Error('fluent_booking_payload_invalid', __('Zeitfenster konnte nicht berechnet werden.', 'sg-mr'));
        }
        if (empty($body['event_id'])) {
            return new WP_Error('fluent_booking_event_missing', __('Kein FluentBooking-Event für diese Region/Team-Kombination konfiguriert.', 'sg-mr'));
        }

        sgmr_log('fluent_booking_request', [
            'order_id' => $payload['order_id'] ?? null,
            'body' => $body,
        ]);

        if (get_option('sgmr_logging_enabled')) {
            $logContext = [
                'order_id' => $payload['order_id'] ?? null,
                'duration_minutes' => $body['duration_minutes'] ?? ($body['duration'] ?? null),
                'title' => $body['title'] ?? '',
            ];
            error_log('SGMR FB prepared: ' . wp_json_encode($logContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            error_log('SGMR FB payload: ' . wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $this->request('POST', 'bookings', $body);
    }

    private function buildBookingBody(array $payload, bool $includeEventId): array
    {
        $slot = $payload['slot'] ?? [];
        if (!is_array($slot) || count($slot) < 2) {
            return [];
        }

        $date = (string) ($payload['date'] ?? '');
        if ($date === '') {
            return [];
        }

        $startTime = (string) $slot[0];
        $endTime = (string) $slot[1];
        $mode = $this->determineMode($payload);
        $eventId = $this->resolveEventId($payload, $mode);

        $timezone = wp_timezone();
        $summary = isset($payload['ics']['summary']) ? (string) $payload['ics']['summary'] : '';
        $description = isset($payload['ics']['description']) ? (string) $payload['ics']['description'] : '';

        $body = [
            'team_key' => $payload['team'] ?? '',
            'start_at' => $this->combineDateTime($date, $startTime, $timezone->getName()),
            'end_at' => $this->combineDateTime($date, $endTime, $timezone->getName()),
            'timezone' => wp_timezone_string(),
            'status' => 'confirmed',
            'title' => $summary,
            'description' => $description,
            'meta' => $this->buildMetaPayload($payload, $mode),
            'customer' => $payload['customer'] ?? [],
            'address' => $payload['address'] ?? [],
            'items' => $payload['items'] ?? [],
        ];

        if ($includeEventId) {
            $body['event_id'] = $eventId;
        }

        $prefill = isset($payload['prefill']) && is_array($payload['prefill']) ? $payload['prefill'] : [];
        $stableFields = isset($prefill['fields']['stable']) && is_array($prefill['fields']['stable'])
            ? $prefill['fields']['stable']
            : [];

        $minutes = (int) ($prefill['duration_minutes'] ?? ($stableFields['sg_service_minutes'] ?? 0));
        if ($minutes <= 0) {
            $minutes = isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : 0;
        }
        if ($minutes <= 0) {
            $minutes = isset($payload['duration']) ? (int) $payload['duration'] : 0;
        }
        if ($minutes > 0) {
            $body['duration'] = $minutes;
            $body['duration_minutes'] = $minutes;
            unset($body['buffer'], $body['buffer_minutes']);
        }

        $postal = isset($stableFields['sg_delivery_postcode']) ? (string) $stableFields['sg_delivery_postcode'] : '';
        $name = isset($stableFields['sg_full_name']) ? (string) $stableFields['sg_full_name'] : '';
        if ($name === '') {
            $first = isset($stableFields['sg_first_name']) ? (string) $stableFields['sg_first_name'] : '';
            $last = isset($stableFields['sg_last_name']) ? (string) $stableFields['sg_last_name'] : '';
            $name = trim($first . ' ' . $last);
        }
        $phone = isset($stableFields['sg_phone']) ? (string) $stableFields['sg_phone'] : '';
        $titleParts = array_filter([$postal, $name, $phone], static function ($part) {
            return $part !== null && $part !== '';
        });
        $title = trim(implode(' – ', $titleParts));
        if ($title === '' && $summary !== '') {
            $title = $summary;
        }
        if ($title !== '') {
            $body['title'] = $title;
            $body['summary'] = $title;
            $body['subject'] = $title;
        }

        $itemsText = isset($stableFields['sg_items_lines']) ? (string) $stableFields['sg_items_lines'] : '';
        if ($itemsText === '') {
            $itemsText = isset($stableFields['sg_items_text']) ? (string) $stableFields['sg_items_text'] : '';
        }
        $services = isset($stableFields['sg_service_summary']) ? (string) $stableFields['sg_service_summary'] : '';
        $addrLine = isset($stableFields['sg_delivery_address']) ? (string) $stableFields['sg_delivery_address'] : '';
        if ($addrLine === '') {
            $street = isset($stableFields['sg_delivery_street']) ? (string) $stableFields['sg_delivery_street'] : '';
            $postcode = isset($stableFields['sg_delivery_postcode']) ? (string) $stableFields['sg_delivery_postcode'] : '';
            $city = isset($stableFields['sg_delivery_city']) ? (string) $stableFields['sg_delivery_city'] : '';
            $addrLine = trim(trim($street) . ' , ' . trim($postcode . ' ' . $city));
        }

        $descLines = [];
        if ($services !== '') {
            $descLines[] = 'Services: ' . $services;
        }
        if ($itemsText !== '') {
            $descLines[] = "Artikel:\n" . $itemsText;
        }
        if ($addrLine !== '') {
            $descLines[] = 'Adresse des Teilnehmers: ' . $addrLine;
        }
        $desc = trim(implode("\n\n", $descLines));
        if ($desc !== '') {
            $body['description'] = $desc;
            $body['notes'] = $desc;
            $body['detail'] = $desc;
        }

        $teamConfig = $this->teamConfig($payload['team'] ?? '');
        $calendarId = 0;
        if (isset($payload['meta']['calendar_id'])) {
            $calendarId = (int) $payload['meta']['calendar_id'];
        }
        if (!$calendarId && isset($prefill['routing']['calendar_id'])) {
            $calendarId = (int) $prefill['routing']['calendar_id'];
        }
        if (!$calendarId && !empty($teamConfig['calendar_id'])) {
            $calendarId = (int) $teamConfig['calendar_id'];
        }
        if ($calendarId > 0) {
            $body['calendar_id'] = $calendarId;
        }

        if ($addrLine !== '') {
            $body['location'] = $addrLine;
            $body['venue'] = $addrLine;
        }

        return $body;
    }

    private function apiReady(): bool
    {
        return isset($this->apiConfig['base_url'], $this->apiConfig['token'])
            && $this->apiConfig['base_url'] !== ''
            && $this->apiConfig['token'] !== '';
    }

    private function ensureLedger(): void
    {
        if ($this->ledgerLoaded) {
            return;
        }
        $this->ledger = [];

        $statuses = array_map(static function ($statusKey) {
            return str_replace('wc-', '', $statusKey);
        }, array_keys(wc_get_order_statuses()));

        $query = new WC_Order_Query([
            'limit' => -1,
            'return' => 'objects',
            'status' => $statuses,
            'meta_query' => [
                [
                    'key' => BookingOrchestrator::ORDER_META_BOOKINGS,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $orders = $query->get_orders();
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            $entries = $order->get_meta(BookingOrchestrator::ORDER_META_BOOKINGS, true);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                $team = sanitize_key($entry['team'] ?? '');
                $date = sanitize_key($entry['date'] ?? '');
                $slotIndex = isset($entry['slot_index']) ? (int) $entry['slot_index'] : -1;
                $mode = sanitize_key($entry['mode'] ?? '');
                if ($team === '' || $date === '' || $slotIndex < 0) {
                    continue;
                }
                if (!isset($this->ledger[$team][$date][$slotIndex])) {
                    $this->ledger[$team][$date][$slotIndex] = ['montage' => 0, 'etage' => 0];
                }
                if ($mode === 'montage' || $mode === 'mixed') {
                    $this->ledger[$team][$date][$slotIndex]['montage']++;
                } elseif ($mode === 'etage') {
                    $this->ledger[$team][$date][$slotIndex]['etage']++;
                }
            }
        }

        $this->ledgerLoaded = true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildMetaPayload(array $payload, string $mode): array
    {
        $meta = $payload['meta'] ?? [];
        $meta['mode'] = $mode;
        $meta['order_id'] = $payload['order_id'] ?? 0;
        $meta['group_id'] = $payload['group_id'] ?? '';
        $meta['sequence_index'] = $payload['sequence_index'] ?? 0;
        $meta['sequence_size'] = $payload['sequence_size'] ?? 1;
        $meta['minutes_required'] = $payload['minutes_required'] ?? 0;
        $meta['montage_total'] = $payload['montage_total'] ?? 0;
        $meta['etage_total'] = $payload['etage_total'] ?? 0;
        $meta['token_ts'] = $payload['context']['token_ts'] ?? null;
        return $meta;
    }

    private function determineMode(array $payload): string
    {
        $montage = isset($payload['montage_total']) ? (int) $payload['montage_total'] : 0;
        $etage = isset($payload['etage_total']) ? (int) $payload['etage_total'] : 0;
        if ($montage > 0) {
            return 'montage';
        }
        if ($etage > 0) {
            return 'etage';
        }
        return 'montage';
    }

    private function resolveEventId(array $payload, string $mode): int
    {
        $region = sanitize_key($payload['context']['region'] ?? '');
        $teamKey = sanitize_key($payload['team'] ?? '');
        if ($region === '' || $teamKey === '') {
            return 0;
        }

        $settings = $this->client->settings();
        $eventId = 0;
        if (!empty($settings['region_events'][$region][$teamKey][$mode])) {
            $eventId = (int) $settings['region_events'][$region][$teamKey][$mode];
        }
        if (!$eventId && !empty($settings['teams'][$teamKey])) {
            $team = $settings['teams'][$teamKey];
            $fallbackKey = $mode === 'etage' ? 'event_etage' : 'event_montage';
            if (!empty($team[$fallbackKey])) {
                $eventId = (int) $team[$fallbackKey];
            }
        }

        return $eventId;
    }

    private function teamConfig(string $teamKey): array
    {
        $settings = $this->client->settings();
        return isset($settings['teams'][$teamKey]) && is_array($settings['teams'][$teamKey]) ? $settings['teams'][$teamKey] : [];
    }

    private function combineDateTime(string $date, string $time, string $timezone): string
    {
        try {
            $dateTime = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone($timezone));
        } catch (\Exception $exception) {
            $dateTime = new DateTimeImmutable('now', new DateTimeZone($timezone));
        }

        return $dateTime->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|WP_Error
     */
    private function request(string $method, string $path, ?array $body = null)
    {
        $namespace = 'fluent-booking/v1/';
        if (str_starts_with($path, 'schedules/')) {
            $namespace = 'fluent-booking/v2/';
        }

        $base = $this->apiConfig['base_url'] ?: rest_url($namespace);
        $url = rtrim($base, '/');
        $url .= '/' . ltrim($path, '/');

        $timeout = isset($this->apiConfig['timeout']) ? (int) $this->apiConfig['timeout'] : 15;
        if ($timeout <= 0) {
            $timeout = 15;
        }

        $args = [
            'method' => $method,
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiConfig['token'],
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $decoded = json_decode($rawBody, true);

        sgmr_log('fluent_booking_response', [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : $rawBody,
        ]);

        if (get_option('sgmr_logging_enabled')) {
            $logLabel = $status >= 400 ? 'SGMR FB response_error' : 'SGMR FB response';
            error_log(
                $logLabel . ': ' . wp_json_encode(
                    [
                        'status' => $status,
                        'body' => is_array($decoded) ? $decoded : $rawBody,
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
        }

        if ($status >= 400) {
            $message = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : __('Unbekannte Antwort der FluentBooking API.', 'sg-mr');
            return new WP_Error('fluent_booking_http_' . $status, $message, ['response' => $decoded, 'status' => $status]);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
