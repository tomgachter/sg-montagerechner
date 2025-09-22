<?php

namespace SGMR\Booking;

use DateTimeImmutable;
use DateTimeZone;
use FluentBooking\App\Services\Helper;
use SGMR\Admin\Settings;
use SGMR\Services\CartService;
use SGMR\Services\ScheduleService;
use WC_Order;
use function add_action;
use function add_filter;
use function array_filter;
use function array_merge;
use function esc_html__;
use function get_option;
use function gmdate;
use function is_array;
use function is_numeric;
use function is_string;
use function is_scalar;
use function max;
use function parse_str;
use function parse_url;
use function preg_match;
use function stripslashes;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function sgmr_booking_signature_normalize_params;
use function sgmr_log;
use function sgmr_normalize_region_slug;
use function sgmr_validate_booking_signature;
use function strpos;
use function explode;
use function trim;
use function wc_get_order;
use function wp_timezone;
use function wp_timezone_string;
use function _n;
use function get_transient;
use function set_transient;
use function setcookie;
use function wp_json_encode;
use function json_decode;
use function is_ssl;
use function urldecode;
use function headers_sent;
use const COOKIEPATH;
use const COOKIE_DOMAIN;
use const HOUR_IN_SECONDS;

class FluentBookingAugmentor
{
    private PrefillManager $prefillManager;

    /** @var array<string, array<string, mixed>> */
    private array $contextCache = [];

    /** @var array<int, int> */
    private array $eventDurationOverrides = [];

    /** @var array<string, mixed>|null */
    private ?array $requestContext = null;

    private bool $requestContextLoaded = false;

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    public function __construct(PrefillManager $prefillManager)
    {
        $this->prefillManager = $prefillManager;
    }

    public function boot(): void
    {
        if (!class_exists('\\FluentBooking\\App\\Services\\BookingService')) {
            return;
        }

        // Ensure Fluent public bookings use SGMR duration/title/description before storage.
        add_filter('fluent_booking/booking_data', [$this, 'filterBookingData'], 20, 4);
        // Persist our enriched metadata for later ICS/email rendering.
        add_action('fluent_booking/after_booking_meta_update', [$this, 'storeBookingMeta'], 20, 4);
        // Override ICS/summary titles to match SGMR conventions.
        add_filter('fluent_booking/booking_meeting_title', [$this, 'filterMeetingTitle'], 20, 5);
        // Align ICS/summary descriptions with SGMR data set.
        add_filter('fluent_booking/booking_meeting_description', [$this, 'filterMeetingDescription'], 20, 5);
        // Feed public booking UI with SGMR slot duration & labels.
        add_filter('fluent_booking/public_event_vars', [$this, 'filterPublicEventVars'], 20, 2);
        // Normalize booking time text (emails/landing) to SGMR range format.
        add_filter('fluent_booking/booking_time_text', [$this, 'filterBookingTimeText'], 20, 3);
        // Apply SGMR duration overrides to availability responses.
        add_filter('fluent_booking/available_slots_for_view', [$this, 'filterAvailableSlotsForView'], 99, 5);
    }

    /**
     * @param array<string, mixed> $bookingData
     * @param mixed $calendarSlot
     * @param array<string, mixed> $customFields
     * @param array<string, mixed> $rawData
     * @return array<string, mixed>
     */
    public function filterBookingData(array $bookingData, $calendarSlot, array $customFields, array $rawData): array
    {
        $context = $this->resolveContextFromPayload($bookingData, $rawData);
        if (!$context) {
            $context = $this->fallbackContextFromEvent($calendarSlot, $bookingData, $rawData);
        }
        if (!$context) {
            return $bookingData;
        }

        $duration = max(0, (int) ($context['duration_minutes'] ?? 0));
        if ($duration > 0) {
            $bookingData['duration'] = $duration;
            $bookingData['duration_minutes'] = $duration;
            $bookingData['slot_minutes'] = $duration;
            if (!empty($bookingData['start_time'])) {
                $startTimestamp = strtotime((string) $bookingData['start_time']);
                if ($startTimestamp) {
                    $bookingData['end_time'] = gmdate('Y-m-d H:i:s', $startTimestamp + ($duration * MINUTE_IN_SECONDS));
                }
            }
        }

        if (!empty($context['title'])) {
            $bookingData['title'] = $context['title'];
            $bookingData['meeting_title'] = $context['title'];
        }
        if (!empty($context['description'])) {
            $bookingData['description'] = $context['description'];
            $bookingData['meeting_description'] = $context['description'];
        }
        if (!empty($context['location'])) {
            $bookingData['location'] = $context['location'];
            $bookingData['location_details'] = [
                'type' => 'in_person_guest',
                'description' => $context['location'],
            ];
        }
        if (!empty($context['phone'])) {
            $bookingData['phone'] = $context['phone'];
        }
        if (!empty($context['email'])) {
            $bookingData['email'] = $context['email'];
        }

        $bookingData['message'] = $this->buildMessage($bookingData['message'] ?? '', $context['description']);

        if (!isset($bookingData['meta']) || !is_array($bookingData['meta'])) {
            $bookingData['meta'] = [];
        }
        $bookingData['meta']['sgmr_context'] = $context;

        if (!isset($bookingData['ics']) || !is_array($bookingData['ics'])) {
            $bookingData['ics'] = [];
        }
        if ($duration > 0) {
            $bookingData['ics']['duration'] = $duration;
        }
        if (!empty($context['title'])) {
            $bookingData['ics']['summary'] = $context['title'];
        }
        if (!empty($context['description'])) {
            $bookingData['ics']['description'] = $context['description'];
        }

        if ($this->extendedLoggingEnabled()) {
            sgmr_log('fluent_booking_request', [
                'order_id' => $context['order_id'],
                'duration' => $duration,
                'title' => $context['title'],
                'start_time' => $bookingData['start_time'] ?? '',
                'end_time' => $bookingData['end_time'] ?? '',
                'location' => $context['location'],
                'description' => $context['description'],
                'source' => $bookingData['source'] ?? 'web',
            ]);
        }

        return $bookingData;
    }

    /**
     * @param \FluentBooking\App\Models\Booking $booking
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $customFields
     * @param mixed $calendarSlot
     */
    public function storeBookingMeta($booking, array $bookingData, array $customFields, $calendarSlot): void
    {
        if (!method_exists($booking, 'get_id')) {
            return;
        }

        $context = $this->resolveContextFromPayload($bookingData, []);
        if (!$context) {
            $context = $this->fallbackContextFromEvent($calendarSlot, $bookingData, []);
        }
        if (!$context) {
            return;
        }

        $bookingId = (int) $booking->get_id();
        $meta = [
            'order_id' => $context['order_id'],
            'order_number' => $context['order_number'],
            'title' => $context['title'],
            'name' => $context['name'],
            'description' => $context['description'],
            'location' => $context['location'],
            'phone' => $context['phone'],
            'email' => $context['email'],
            'duration_minutes' => $context['duration_minutes'],
            'postcode' => $context['postcode'],
            'event_id' => isset($context['router']['event_id']) ? (int) $context['router']['event_id'] : 0,
            'stable' => $context['stable'],
        ];
        $meta['duration_label'] = $this->formatDurationLabel((int) $context['duration_minutes']);

        Helper::updateBookingMeta($booking->id, 'sgmr_context', $meta);

        if ($this->extendedLoggingEnabled()) {
            sgmr_log('fluent_booking_response', [
                'order_id' => $context['order_id'],
                'booking_id' => $bookingId,
                'duration' => $context['duration_minutes'],
                'start_time' => $bookingData['start_time'] ?? '',
                'end_time' => $bookingData['end_time'] ?? '',
                'status' => $bookingData['status'] ?? '',
            ]);
        }
    }

    /**
     * @param string $title
     * @param string $authorName
     * @param string $guestName
     * @param mixed $calendarEvent
     * @param \FluentBooking\App\Models\Booking $booking
     * @return string
     */
    public function filterMeetingTitle($title, $authorName, $guestName, $calendarEvent, $booking): string
    {
        if (!method_exists($booking, 'get_id')) {
            return $title;
        }

        $context = Helper::getBookingMeta($booking->id, 'sgmr_context');
        if (!is_array($context)) {
            $context = $this->fallbackContextFromBooking($booking);
            if (!$context) {
                return $title;
            }
        }

        $reloaded = $this->rebuildContextForBooking($booking, $context);
        if (is_array($reloaded)) {
            $context = $reloaded;
        }

        $details = $this->contactDetailsFromContext($context);
        if ($details['phone'] === '') {
            $details['phone'] = $this->orderPhone((int) ($context['order_id'] ?? 0));
        }

        $customTitle = $this->buildTitle($details['postcode'], $details['name'], $details['phone'], $context['stable'] ?? []);

        if ($customTitle === '') {
            $customTitle = $this->buildTitleFromOrder((int) ($context['order_id'] ?? 0));
        }

        return $customTitle !== '' ? $customTitle : $title;
    }

    /**
     * @param string $description
     * @param string $authorName
     * @param string $guestName
     * @param mixed $calendarEvent
     * @param \FluentBooking\App\Models\Booking $booking
     * @return string
     */
    public function filterMeetingDescription($description, $authorName, $guestName, $calendarEvent, $booking): string
    {
        if (!method_exists($booking, 'get_id')) {
            return $description;
        }

        $context = Helper::getBookingMeta($booking->id, 'sgmr_context');
        if (!is_array($context)) {
            $context = $this->fallbackContextFromBooking($booking);
            if (!$context) {
                return $description;
            }
        }

        $customDescription = isset($context['description']) && is_string($context['description']) ? trim($context['description']) : '';
        if ($customDescription === '') {
            $reloaded = $this->rebuildContextForBooking($booking, $context);
            if ($reloaded && isset($reloaded['description'])) {
                $customDescription = trim((string) $reloaded['description']);
            }
        }

        return $customDescription !== '' ? $customDescription : $description;
    }

    /**
     * @param array<string, mixed> $vars
     * @param mixed $event
     * @return array<string, mixed>
     */
    public function filterPublicEventVars(array $vars, $event): array
    {
        if (!$this->frontendAugmentationEnabled()) {
            return $vars;
        }

        $context = $this->resolvePublicContext();
        if (!$context) {
            if ($this->extendedLoggingEnabled()) {
                sgmr_log('fb_public_event_vars', [
                    'reason' => 'context_missing',
                ]);
            }
            return $vars;
        }

        $duration = max(0, (int) ($context['duration_minutes'] ?? 0));
        if ($duration <= 0) {
            return $vars;
        }

        $timezone = $this->determineTimezone($vars);
        $slots = isset($vars['slots']) && is_array($vars['slots']) ? $vars['slots'] : [];
        [$augmentedSlots, $lookup] = $this->augmentSlotsForDuration($slots, $duration, $timezone);

        if ($slots) {
            $vars['slots'] = $augmentedSlots;
        }
        $durationLabel = $this->formatDurationLabel($duration);
        $vars['duration_minutes'] = $duration;
        $vars['duration_label'] = $durationLabel;
        $vars['sgmr'] = array_merge(
            isset($vars['sgmr']) && is_array($vars['sgmr']) ? $vars['sgmr'] : [],
            [
                'duration_minutes' => $duration,
                'duration_label' => $durationLabel,
                'slot_lookup' => $lookup,
                'timezone' => $timezone,
                'order_id' => $context['order_id'],
                'signature' => $context['signature'],
                'region' => $context['router']['region'] ?? ($context['region'] ?? ''),
                'sgm' => $context['counts']['m'] ?? 0,
                'sge' => $context['counts']['e'] ?? 0,
                'event_id' => is_object($event) && property_exists($event, 'id') ? (int) $event->id : ($vars['event']['id'] ?? 0),
            ]
        );

        $durationKey = (string) $duration;

        if (!isset($vars['duration_lookup']) || !is_array($vars['duration_lookup'])) {
            $vars['duration_lookup'] = [];
        }
        $vars['duration_lookup'] = [$durationKey => $durationLabel];

        if (!isset($vars['multi_duration_lookup']) || !is_array($vars['multi_duration_lookup'])) {
            $vars['multi_duration_lookup'] = [];
        }
        $vars['multi_duration_lookup'] = [$durationKey => $durationLabel];

        if (isset($vars['event']) && is_array($vars['event'])) {
            $vars['event']['duration_minutes'] = $duration;
            $vars['event']['duration_label'] = $durationLabel;
            $vars['event']['duration'] = $duration;
            $vars['event']['slot_interval'] = $duration;
            $vars['event']['duration_lookup'] = [$durationKey => $durationLabel];
        }

        if (is_object($event)) {
            if (property_exists($event, 'duration')) {
                $event->duration = $duration;
            }
            if (property_exists($event, 'settings')) {
                $settings = is_array($event->settings) ? $event->settings : (array) $event->settings;
                $settings['slot_interval'] = $duration;
                if (isset($settings['multi_duration']) && is_array($settings['multi_duration'])) {
                    $settings['multi_duration']['enabled'] = false;
                }
                $event->settings = $settings;
            }
            if (property_exists($event, 'id')) {
                $eventId = (int) $event->id;
                if ($eventId > 0) {
                    $this->eventDurationOverrides[$eventId] = $duration;
                    set_transient('sgmr_event_duration_' . $eventId, $duration, HOUR_IN_SECONDS);
                }
            }
        }

        if (isset($vars['slot']) && is_array($vars['slot'])) {
            $vars['slot']['duration_minutes'] = $duration;
            $vars['slot']['duration'] = $duration;
            $vars['slot']['duration_label'] = $durationLabel;
            $vars['slot']['duration_readable'] = $durationLabel;
            $vars['slot']['slot_interval'] = $duration;
            $vars['slot']['duration_lookup'] = [$durationKey => $durationLabel];
            if (isset($vars['slot']['durations']) && is_array($vars['slot']['durations']) && $vars['slot']['durations']) {
                $vars['slot']['durations'] = [$durationLabel];
            }
            if (!isset($vars['slot']['slot_lookup'])) {
                $vars['slot']['slot_lookup'] = $lookup;
            }
            if (isset($vars['slot']['slots']) && is_array($vars['slot']['slots']) && $vars['slot']['slots']) {
                $vars['slot']['slots'] = $this->mergeSlotAugmentation($vars['slot']['slots'], $lookup, $durationLabel, $duration);
            }
            if (!empty($lookup) && !isset($vars['slot']['time_label'])) {
                $firstSlot = reset($lookup);
                if (isset($firstSlot['start_label'], $firstSlot['end_label'])) {
                    $vars['slot']['time_label'] = $firstSlot['start_label'] . ' – ' . $firstSlot['end_label'];
                }
            }
        }

        if ($this->extendedLoggingEnabled()) {
            sgmr_log('fb_public_event_vars', [
                'order_id' => $context['order_id'],
                'duration' => $duration,
                'slots_augmented' => count($lookup),
            ]);
        }

        return $vars;
    }

    /**
     * @param array<int, mixed> $slotPayload
     * @param array<int, array<string, mixed>> $lookup
     * @return array<int, mixed>
     */
    private function mergeSlotAugmentation(array $slotPayload, array $lookup, string $durationLabel, int $duration): array
    {
        if (!$slotPayload || !$lookup) {
            return $slotPayload;
        }

        foreach ($slotPayload as $index => $slot) {
            if (!is_array($slot)) {
                continue;
            }

            if (!isset($slot['duration'])) {
                $slotPayload[$index]['duration'] = $duration;
            }
            if (empty($slot['duration_label'])) {
                $slotPayload[$index]['duration_label'] = $durationLabel;
            }

            $match = null;
            foreach ($lookup as $row) {
                if (isset($row['index']) && (int) $row['index'] === $index) {
                    $match = $row;
                    break;
                }
                if ($match === null && isset($slot['id'], $row['slot_id']) && (string) $row['slot_id'] === (string) $slot['id']) {
                    $match = $row;
                }
            }

            if (!$match) {
                continue;
            }

            $startTime = isset($match['start_time']) ? (string) $match['start_time'] : (string) ($slot['start_time'] ?? $slot['start'] ?? '');
            if ($startTime === '') {
                $startTime = (string) ($slot['start'] ?? '');
            }
            $startTs = strtotime($startTime);
            if ($startTs === false) {
                continue;
            }

            $endTime = gmdate('Y-m-d H:i:s', $startTs + ($duration * MINUTE_IN_SECONDS));

            $startLabel = gmdate('H:i', $startTs);
            $endLabel = gmdate('H:i', $startTs + ($duration * MINUTE_IN_SECONDS));

            $slotPayload[$index]['start_time'] = $startTime;
            $slotPayload[$index]['end_time'] = $endTime;
            $slotPayload[$index]['start_label'] = $startLabel;
            $slotPayload[$index]['end_label'] = $endLabel;
            $slotPayload[$index]['label'] = $startLabel . ' – ' . $endLabel;
        }

        return $slotPayload;
    }

    private function resolvePublicContext(): ?array
    {
        return $this->resolveContextFromRequest();
    }

    /**
     * @param string $text
     * @param \FluentBooking\App\Models\Booking $booking
     * @param string $audience
     * @return string
     */
    public function filterBookingTimeText($text, $booking, string $audience): string
    {
        if (!method_exists($booking, 'get_id')) {
            return $text;
        }

        $timezone = $audience === 'host' ? $booking->getHostTimezone() : $booking->person_time_zone;
        $formatted = $this->formatBookingRange($booking->start_time, $booking->end_time, $timezone);
        if ($formatted === '') {
            return $text;
        }

        $suffix = $timezone ? ' (' . $timezone . ')' : '';
        return $formatted . $suffix;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $slots
     * @param mixed $calendarEvent
     * @param mixed $calendar
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function filterAvailableSlotsForView(array $slots, $calendarEvent, $calendar, string $timezone, int $duration): array
    {
        $eventId = is_object($calendarEvent) && property_exists($calendarEvent, 'id') ? (int) $calendarEvent->id : 0;
        $requestedDuration = isset($_REQUEST['duration']) ? (int) $_REQUEST['duration'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($requestedDuration > 0 && $requestedDuration !== $duration) {
            $duration = max($duration, $requestedDuration);
        }
        if ($eventId > 0 && !isset($this->eventDurationOverrides[$eventId])) {
            $cachedDuration = (int) get_transient('sgmr_event_duration_' . $eventId);
            if ($cachedDuration > 0) {
                $this->eventDurationOverrides[$eventId] = $cachedDuration;
            }
            if (!isset($this->eventDurationOverrides[$eventId])) {
                $cookieContext = $this->contextFromCookie($eventId);
                if (!empty($cookieContext['duration_minutes'])) {
                    $this->eventDurationOverrides[$eventId] = (int) $cookieContext['duration_minutes'];
                }
            }
        }
        if ($eventId > 0 && isset($this->eventDurationOverrides[$eventId])) {
            $duration = max($duration, $this->eventDurationOverrides[$eventId]);
        }

        if ($duration <= 0) {
            return $slots;
        }

        try {
            $tz = $timezone ? new DateTimeZone($timezone) : wp_timezone();
        } catch (\Throwable $exception) {
            $tz = wp_timezone();
        }

        if ($this->extendedLoggingEnabled()) {
            sgmr_log('fb_available_slots', [
                'event_id' => $eventId,
                'duration' => $duration,
                'dates' => array_keys($slots),
            ]);
        }

        foreach ($slots as $date => &$daySlots) {
            if (!is_array($daySlots)) {
                continue;
            }

            $candidates = [];
            foreach ($daySlots as $entry) {
                if (is_array($entry)) {
                    $candidates[] = $entry;
                }
            }

            if (!$candidates) {
                continue;
            }

            usort($candidates, function (array $left, array $right): int {
                $leftTs = $this->slotStartTimestamp($left);
                $rightTs = $this->slotStartTimestamp($right);
                if ($leftTs === $rightTs) {
                    $leftStart = $this->slotStartString($left);
                    $rightStart = $this->slotStartString($right);

                    $comparison = $leftStart <=> $rightStart;
                    if ($comparison !== 0) {
                        return $comparison;
                    }

                    $leftIndex = $this->slotScalarValue($left, 'index');
                    $rightIndex = $this->slotScalarValue($right, 'index');
                    if ($leftIndex !== null && $rightIndex !== null) {
                        return (int) $leftIndex <=> (int) $rightIndex;
                    }

                    return 0;
                }

                return $leftTs <=> $rightTs;
            });

            $filtered = [];
            $lastEndTs = null;
            foreach ($candidates as $entry) {
                $startTime = $this->slotStartString($entry);
                if ($startTime === '') {
                    continue;
                }
                $startTs = strtotime($startTime);
                if ($startTs === false) {
                    continue;
                }
                if ($lastEndTs !== null && $startTs < $lastEndTs) {
                    continue;
                }

                [$endTime, $endTs] = $this->slotEndDetails($entry, $startTs, $duration);

                $startDt = $this->createDateTime($startTime, $tz);
                $endDt = $this->createDateTime($endTime, $tz);

                $entry['start'] = $startTime;
                $entry['end'] = gmdate('Y-m-d H:i:s', $endTs);
                $entry['duration'] = $duration;
                $entry['start_label'] = $startDt ? $startDt->format('H:i') : '';
                $entry['end_label'] = $endDt ? $endDt->format('H:i') : '';
                if ($entry['start_label'] === '') {
                    $entry['start_label'] = substr($startTime, 11, 5);
                }
                if ($entry['end_label'] === '') {
                    $entry['end_label'] = substr($entry['end'], 11, 5);
                }
                if ($entry['start_label'] !== '' && $entry['end_label'] !== '') {
                    $entry['label'] = $entry['start_label'] . ' – ' . $entry['end_label'];
                }

                $filtered[] = $entry;
                $lastEndTs = $endTs;
            }

            if ($filtered) {
                $daySlots = $filtered;
            }
        }
        unset($daySlots);

        return $slots;
    }

    /**
     * @param array<string, mixed> $slot
     */
    private function slotStartString(array $slot): string
    {
        $keys = [
            'start',
            'start_time',
            'startTime',
            'start_at',
            'startAt',
            'slot_time',
            'slotTime',
            'slot_start',
            'slotStart',
            'start_date_time',
            'startDateTime',
            'start_datetime',
        ];

        foreach ($keys as $key) {
            $value = $this->slotScalarValue($slot, $key);
            if ($value !== null) {
                return $value;
            }
        }

        $date = $this->slotScalarValue($slot, 'date');
        if ($date !== null) {
            $timeKeys = ['time', 'start_label', 'startLabel'];
            foreach ($timeKeys as $timeKey) {
                $time = $this->slotScalarValue($slot, $timeKey);
                if ($time !== null) {
                    $composed = trim($date . ' ' . $time);
                    if ($composed !== '') {
                        return $composed;
                    }
                }
            }
        }

        $label = $this->slotScalarValue($slot, 'start_label');
        if ($label !== null) {
            return $label;
        }

        $label = $this->slotScalarValue($slot, 'startLabel');
        if ($label !== null) {
            return $label;
        }

        return '';
    }

    /**
     * @param mixed $slot
     */
    private function slotStartTimestamp($slot): int
    {
        if (!is_array($slot)) {
            return PHP_INT_MAX;
        }

        $start = $this->slotStartString($slot);
        if ($start === '') {
            return PHP_INT_MAX;
        }

        $timestamp = strtotime($start);
        if ($timestamp === false) {
            return PHP_INT_MAX;
        }

        return $timestamp;
    }

    /**
     * @param array<string, mixed> $slot
     * @return array{0: string, 1: int}
     */
    private function slotEndDetails(array $slot, int $startTs, int $duration): array
    {
        $keys = [
            'end',
            'end_time',
            'endTime',
            'end_at',
            'endAt',
            'slot_end',
            'slotEnd',
            'end_date_time',
            'endDateTime',
            'end_datetime',
        ];

        foreach ($keys as $key) {
            $value = $this->slotScalarValue($slot, $key);
            if ($value === null) {
                continue;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return [$value, $timestamp];
            }
        }

        $date = $this->slotScalarValue($slot, 'date');
        if ($date !== null) {
            $timeKeys = ['end_label', 'endLabel'];
            foreach ($timeKeys as $timeKey) {
                $time = $this->slotScalarValue($slot, $timeKey);
                if ($time !== null) {
                    $composed = trim($date . ' ' . $time);
                    if ($composed !== '') {
                        $timestamp = strtotime($composed);
                        if ($timestamp !== false) {
                            return [$composed, $timestamp];
                        }
                    }
                }
            }
        }

        $endTs = $startTs + ($duration * MINUTE_IN_SECONDS);
        $endTime = gmdate('Y-m-d H:i:s', $endTs);

        return [$endTime, $endTs];
    }

    /**
     * @param array<string, mixed> $slot
     */
    private function slotScalarValue(array $slot, string $key): ?string
    {
        if (!array_key_exists($key, $slot)) {
            return null;
        }

        $value = $slot[$key];
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param string $original
     */
    private function buildMessage($original, string $description): string
    {
        $description = trim($description);
        $original = trim((string) $original);

        if ($description === '') {
            return $original;
        }

        if ($original === '') {
            return $description;
        }

        return $description . "\n\n" . esc_html__('Customer note:', 'sg-mr') . "\n" . $original;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveContextFromPayload(array $bookingData, array $rawData): ?array
    {
        $sources = [];
        $sources[] = $rawData;
        $sources[] = $bookingData;
        $sources[] = $this->extractQueryParams($bookingData, $rawData);

        $orderId = (int) $this->pickValue(['sg_order_id', 'order_id', 'order', 'woo_order', 'sg_token_order_id'], $sources);
        $signature = $this->sanitizeSignatureString($this->pickValue(['sig', 'signature', 'token', 'token_sig', 'sg_token_sig', 'sg_token_signature'], $sources));
        $eventId = (int) ($this->pickValue(['event_id', 'calendar_event_id'], $sources) ?: 0);

        $cookieContext = [];
        if (($orderId <= 0 || $signature === '') && $eventId > 0) {
            $cookieContext = $this->contextFromCookie($eventId);
            if ($orderId <= 0 && !empty($cookieContext['order_id'])) {
                $orderId = (int) $cookieContext['order_id'];
            }
            if ($signature === '' && !empty($cookieContext['signature'])) {
                $signature = $this->sanitizeSignatureString((string) $cookieContext['signature']);
            }
        }

        if ($orderId <= 0 || $signature === '') {
            return null;
        }

        $cached = $this->cachedContext($orderId, $signature);
        if ($cached) {
            return $cached;
        }

        $args = [];
        $args['region'] = (string) $this->pickValue(['region', 'region_key'], $sources);
        $sgm = $this->pickValue(['sgm', 'm'], $sources);
        $sge = $this->pickValue(['sge', 'e'], $sources);
        if ($sgm !== '') {
            $args['sgm'] = (int) $sgm;
        } elseif (!empty($cookieContext['sgm'])) {
            $args['sgm'] = (int) $cookieContext['sgm'];
        }
        if ($sge !== '') {
            $args['sge'] = (int) $sge;
        } elseif (!empty($cookieContext['sge'])) {
            $args['sge'] = (int) $cookieContext['sge'];
        }

        if ($args['region'] === '' && !empty($cookieContext['region'])) {
            $args['region'] = (string) $cookieContext['region'];
        }

        $routerRaw = $this->pickValue(['router'], $sources);
        if (is_array($routerRaw)) {
            $sanitizedRouter = $this->sanitizeRouterMeta($routerRaw);
            if ($sanitizedRouter) {
                $args['router'] = $sanitizedRouter;
            }
        } elseif (!empty($cookieContext)) {
            $cookieRouter = $this->sanitizeRouterMeta($cookieContext);
            if ($cookieRouter) {
                $args['router'] = $cookieRouter;
            } elseif (!empty($cookieContext['event_id'])) {
                $args['router'] = ['event_id' => (int) $cookieContext['event_id']];
            }
        }

        return $this->contextForOrder($orderId, $signature, $args);
    }

    /**
     * @param array<int|string, mixed> $sources
     */
    private function pickValue(array $keys, array $sources)
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($keys as $key) {
                if (!array_key_exists($key, $source)) {
                    continue;
                }
                $value = $source[$key];
                if (is_string($value) || is_numeric($value)) {
                    $value = is_string($value) ? trim($value) : (string) $value;
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
        return '';
    }

    /**
     * @return array<string, string>
     */
    private function extractQueryParams(array $bookingData, array $rawData): array
    {
        $urls = [];
        if (!empty($bookingData['source_url'])) {
            $urls[] = (string) $bookingData['source_url'];
        }
        if (!empty($rawData['source_url'])) {
            $urls[] = (string) $rawData['source_url'];
        }

        $params = [];
        foreach ($urls as $url) {
            $query = parse_url($url, PHP_URL_QUERY);
            if ($query) {
                $parsed = [];
                parse_str($query, $parsed);
                if ($parsed) {
                    $params = array_merge($params, $this->flattenParams($parsed));
                }
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function flattenParams(array $params): array
    {
        $flat = [];
        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $flat[$key] = (string) $value;
            }
        }
        return $flat;
    }

    private function resolveContextFromRequest(): ?array
    {
        if ($this->requestContextLoaded) {
            return $this->requestContext;
        }

        $orderParam = $_GET['order_id'] ?? $_GET['order'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $signatureParam = $_GET['sig'] ?? $_GET['signature'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$orderParam || !$signatureParam) {
            $this->requestContextLoaded = true;
            $this->requestContext = null;
            return null;
        }

        $orderId = (int) $orderParam;
        if ($orderId <= 0) {
            $this->requestContextLoaded = true;
            $this->requestContext = null;
            return null;
        }

        $signature = $this->sanitizeSignatureString($signatureParam);
        if ($signature === '') {
            $this->requestContextLoaded = true;
            $this->requestContext = null;
            return null;
        }

        $cached = $this->cachedContext($orderId, $signature);
        if ($cached) {
            $this->requestContextLoaded = true;
            $this->requestContext = $cached;
            return $cached;
        }

        $args = [];
        if (!empty($_GET['region'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $args['region'] = sanitize_key((string) $_GET['region']);
        }
        if (!empty($_GET['sgm'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $args['sgm'] = (int) $_GET['sgm'];
        } elseif (!empty($_GET['m'])) {
            $args['sgm'] = (int) $_GET['m'];
        }
        if (!empty($_GET['sge'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $args['sge'] = (int) $_GET['sge'];
        } elseif (!empty($_GET['e'])) {
            $args['sge'] = (int) $_GET['e'];
        }

        $context = $this->contextForOrder($orderId, $signature, $args);
        $this->requestContextLoaded = true;
        $this->requestContext = $context;

        return $context;
    }

    private function contextForOrder(int $orderId, string $signature, array $args = []): ?array
    {
        if ($orderId <= 0 || $signature === '') {
            return null;
        }

        $cached = $this->cachedContext($orderId, $signature);
        if ($cached) {
            return $cached;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return null;
        }

        $region = $args['region'] ?? (string) $order->get_meta(CartService::META_REGION_KEY, true);
        $region = sgmr_normalize_region_slug($region);
        if ($region === '') {
            return null;
        }

        $counts = CartService::ensureOrderCounts($order);
        if (isset($args['sgm'])) {
            $counts['montage'] = max(0, (int) $args['sgm']);
        }
        if (isset($args['sge'])) {
            $counts['etage'] = max(0, (int) $args['sge']);
        }

        $signaturePayload = sgmr_booking_signature_normalize_params($orderId, [
            'region' => $region,
            'sgm' => $counts['montage'],
            'sge' => $counts['etage'],
        ], $order);

        if (!sgmr_validate_booking_signature($orderId, $signature, $this->tokenTtlSeconds(), $signaturePayload)) {
            return null;
        }

        $routerMeta = isset($args['router']) && is_array($args['router']) ? $this->sanitizeRouterMeta($args['router']) : [];

        try {
            $prefill = $this->prefillManager->payloadFor(
                $order,
                $region,
                (int) $counts['montage'],
                (int) $counts['etage'],
                $signature,
                ['router' => $routerMeta]
            );
        } catch (\Throwable $exception) {
            return null;
        }

        $stable = isset($prefill['fields']['stable']) && is_array($prefill['fields']['stable']) ? $prefill['fields']['stable'] : [];

        $duration = (int) ($prefill['duration_minutes'] ?? 0);
        if ($duration <= 0 && isset($stable['sg_service_minutes'])) {
            $duration = (int) $stable['sg_service_minutes'];
        }
        if ($duration <= 0) {
            $duration = ScheduleService::minutesRequired((int) $counts['montage'], (int) $counts['etage']);
        }

        $contactName = trim($stable['sg_full_name'] ?? '');
        if ($contactName === '' && (!empty($stable['sg_first_name']) || !empty($stable['sg_last_name']))) {
            $contactName = trim(($stable['sg_first_name'] ?? '') . ' ' . ($stable['sg_last_name'] ?? ''));
        }

        $title = $this->buildTitle(
            $stable['sg_delivery_postcode'] ?? '',
            $contactName,
            $stable['sg_phone'] ?? '',
            $stable
        );

        $description = $this->buildDescription(
            $stable['sg_service_summary'] ?? '',
            $stable['sg_items_lines'] ?? ($stable['sg_items_text'] ?? ''),
            $this->buildAddressLine($stable)
        );

        $context = [
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'signature' => $signature,
            'duration_minutes' => $duration,
            'title' => $title,
            'name' => $contactName,
            'description' => $description,
            'location' => $this->buildAddressLine($stable),
            'phone' => $this->sanitizePhone($stable['sg_phone'] ?? ''),
            'email' => sanitize_email($stable['sg_email'] ?? ''),
            'postcode' => sanitize_text_field($stable['sg_delivery_postcode'] ?? ''),
            'sg_event_id' => isset($routerMeta['event_id']) ? (int) $routerMeta['event_id'] : 0,
            'router' => array_merge($routerMeta, ['region' => $region]),
            'counts' => [
                'm' => (int) $counts['montage'],
                'e' => (int) $counts['etage'],
            ],
            'stable' => [
                'first_name' => $stable['sg_first_name'] ?? '',
                'last_name' => $stable['sg_last_name'] ?? '',
                'full_name' => $stable['sg_full_name'] ?? '',
                'phone' => $stable['sg_phone'] ?? '',
                'delivery_address' => $this->buildAddressLine($stable),
            ],
        ];

        $this->rememberContext($context);

        return $context;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function determineTimezone(array $vars): string
    {
        $candidates = [];
        if (isset($vars['time_zone']) && is_string($vars['time_zone'])) {
            $candidates[] = $vars['time_zone'];
        }
        if (isset($vars['timezone']) && is_string($vars['timezone'])) {
            $candidates[] = $vars['timezone'];
        }
        if (isset($vars['event']) && is_array($vars['event'])) {
            $event = $vars['event'];
            if (isset($event['time_zone']) && is_string($event['time_zone'])) {
                $candidates[] = $event['time_zone'];
            }
            if (isset($event['timezone']) && is_string($event['timezone'])) {
                $candidates[] = $event['timezone'];
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $wpTz = wp_timezone_string();
        return $wpTz !== '' ? $wpTz : 'UTC';
    }

    /**
     * @param array<int, mixed> $slots
     * @return array{0: array<int, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function augmentSlotsForDuration(array $slots, int $duration, string $timezoneString): array
    {
        if ($duration <= 0 || !$slots) {
            return [$slots, []];
        }

        try {
            $timezone = new DateTimeZone($timezoneString ?: 'UTC');
        } catch (\Throwable $exception) {
            $timezone = wp_timezone();
        }

        $lookup = [];
        foreach ($slots as $index => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $startInfo = $this->resolveSlotStartInfo($slot, $timezone);
            if (!$startInfo) {
                continue;
            }
            $end = $startInfo['date_time']->modify('+' . $duration . ' minutes');
            if (!$end) {
                continue;
            }

            $slots[$index]['duration'] = $duration;
            $slots[$index]['duration_label'] = $this->formatDurationLabel($duration);

            $endTime = $end->format('Y-m-d H:i:s');
            $slots[$index]['end_time'] = $endTime;
            $slots[$index]['end'] = $endTime;

            $startLabel = $startInfo['time_label'] !== '' ? $startInfo['time_label'] : $startInfo['date_time']->format('H:i');
            $endLabel = $end->format('H:i');
            $slots[$index]['start_label'] = $startLabel;
            $slots[$index]['end_label'] = $endLabel;
            if ($startLabel !== '' && $endLabel !== '') {
                $slots[$index]['label'] = $startLabel . ' – ' . $endLabel;
            }

            $lookup[] = [
                'index' => $index,
                'slot_id' => isset($slot['id']) ? (string) $slot['id'] : (isset($slot['slot_id']) ? (string) $slot['slot_id'] : null),
                'start_time' => $startInfo['iso'],
                'end_time' => $end->format('Y-m-d H:i:s'),
                'start_label' => $startLabel,
                'end_label' => $endLabel,
            ];
        }

        return [$slots, $lookup];
    }

    /**
     * @param array<string, mixed> $slot
     * @return array{date_time: DateTimeImmutable, iso: string, time_label: string}|null
     */
    private function resolveSlotStartInfo(array $slot, DateTimeZone $timezone): ?array
    {
        $value = null;
        $candidates = ['start_time', 'start', 'start_at', 'slot_time', 'slot_start', 'start_date_time', 'start_datetime'];
        foreach ($candidates as $key) {
            if (isset($slot[$key]) && is_scalar($slot[$key])) {
                $candidate = trim((string) $slot[$key]);
                if ($candidate !== '') {
                    $value = $candidate;
                    break;
                }
            }
        }

        if ($value === null && isset($slot['date'], $slot['time']) && is_scalar($slot['date']) && is_scalar($slot['time'])) {
            $composed = trim((string) $slot['date'] . ' ' . (string) $slot['time']);
            if ($composed !== '') {
                $value = $composed;
            }
        }

        if ($value === null) {
            return null;
        }

        $dateTime = $this->createDateTime($value, $timezone);
        if (!$dateTime) {
            return null;
        }

        return [
            'date_time' => $dateTime,
            'iso' => $dateTime->format('Y-m-d H:i:s'),
            'time_label' => $this->resolveStartLabel($slot, $dateTime),
        ];
    }

    /**
     * @param array<string, mixed> $slot
     */
    private function resolveStartLabel(array $slot, DateTimeImmutable $dateTime): string
    {
        $labelCandidates = ['start_label', 'time_label', 'display_time', 'label'];
        foreach ($labelCandidates as $key) {
            if (!isset($slot[$key]) || !is_scalar($slot[$key])) {
                continue;
            }
            $label = trim((string) $slot[$key]);
            if ($label === '') {
                continue;
            }
            if (strpos($label, '–') !== false) {
                $parts = explode('–', $label);
                $label = trim((string) $parts[0]);
            } elseif (strpos($label, '-') !== false) {
                $parts = explode('-', $label);
                $label = trim((string) $parts[0]);
            }
            if ($label !== '') {
                return $label;
            }
        }

        return $dateTime->format('H:i');
    }

    private function createDateTime(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', DATE_ATOM, 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s'];
        foreach ($formats as $format) {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime->setTimezone($timezone);
            }
        }

        if (preg_match('/^\d{9,}$/', $value)) {
            $timestamp = (int) $value;
            return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        }

        if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            $fallback = DateTimeImmutable::createFromFormat('Y-m-d H:i', '1970-01-01 ' . $value, $timezone);
            if ($fallback instanceof DateTimeImmutable) {
                return $fallback;
            }
        }

        try {
            return (new DateTimeImmutable($value, $timezone))->setTimezone($timezone);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function settings(): array
    {
        if ($this->settings === null) {
            $settings = Settings::getSettings();
            $this->settings = is_array($settings) ? $settings : [];
        }
        return $this->settings;
    }

    private function frontendAugmentationEnabled(): bool
    {
        $settings = $this->settings();
        if (array_key_exists('frontend_duration_override', $settings)) {
            return (bool) $settings['frontend_duration_override'];
        }
        return true;
    }

    private function extendedLoggingEnabled(): bool
    {
        $settings = $this->settings();
        if (!empty($settings['logging_extended'])) {
            return true;
        }
        return (bool) get_option('sgmr_logging_enabled', 0);
    }

    private function contextCacheKey(int $orderId, string $signature): string
    {
        return $orderId . '|' . $signature;
    }

    private function cachedContext(int $orderId, string $signature): ?array
    {
        $key = $this->contextCacheKey($orderId, $signature);
        if (isset($this->contextCache[$key])) {
            return $this->contextCache[$key];
        }

        $transientKey = $this->contextTransientKey($orderId, $signature);
        $cached = get_transient($transientKey);
        if (is_array($cached)) {
            $this->contextCache[$key] = $cached;
            return $cached;
        }

        return null;
    }

    private function rememberContext(array $context): void
    {
        $orderId = isset($context['order_id']) ? (int) $context['order_id'] : 0;
        $signature = isset($context['signature']) ? (string) $context['signature'] : '';
        if ($orderId <= 0 || $signature === '') {
            return;
        }
        $key = $this->contextCacheKey($orderId, $signature);
        $this->contextCache[$key] = $context;
        if (!$this->requestContextLoaded) {
            $this->requestContext = $context;
        }

        set_transient($this->contextTransientKey($orderId, $signature), $context, HOUR_IN_SECONDS);

        $eventId = 0;
        if (isset($context['router']) && is_array($context['router']) && !empty($context['router']['event_id'])) {
            $eventId = (int) $context['router']['event_id'];
        }
        if (!$eventId && isset($context['sg_event_id'])) {
            $eventId = (int) $context['sg_event_id'];
        }

        if ($eventId > 0 && !empty($context['duration_minutes'])) {
            $durationOverride = (int) $context['duration_minutes'];
            $this->eventDurationOverrides[$eventId] = $durationOverride;
            set_transient('sgmr_event_duration_' . $eventId, $durationOverride, HOUR_IN_SECONDS);
            $this->storeContextCookie($eventId, $context);
            if ($signature !== '') {
                $this->rememberEventSignature($eventId, $orderId, $signature, $context);
            }
        }
    }

    private function contextTransientKey(int $orderId, string $signature): string
    {
        return 'sgmr_ctx_' . md5($orderId . '|' . $signature);
    }

    private function eventSignatureTransientKey(int $eventId): string
    {
        return 'sgmr_event_ctx_' . $eventId;
    }

    private function rememberEventSignature(int $eventId, int $orderId, string $signature, array $context): void
    {
        $mapKey = $this->eventSignatureTransientKey($eventId);
        $payload = get_transient($mapKey);
        if (!is_array($payload)) {
            $payload = [];
        }

        $payload[$signature] = [
            'order_id' => $orderId,
            'signature' => $signature,
            'region' => $context['router']['region'] ?? ($context['region'] ?? ''),
            'sgm' => $context['counts']['m'] ?? 0,
            'sge' => $context['counts']['e'] ?? 0,
        ];

        set_transient($mapKey, $payload, HOUR_IN_SECONDS);
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFromCookie(int $eventId): array
    {
        if (!isset($_COOKIE['sgmr_booking_ctx'])) {
            return [];
        }
        $rawValue = urldecode(stripslashes((string) $_COOKIE['sgmr_booking_ctx']));
        $raw = json_decode($rawValue, true);
        if (!is_array($raw)) {
            return [];
        }
        return isset($raw[$eventId]) && is_array($raw[$eventId]) ? $raw[$eventId] : [];
    }

    private function storeContextCookie(int $eventId, array $context): void
    {
        $payload = [];
        if (isset($_COOKIE['sgmr_booking_ctx'])) {
            $decoded = json_decode(urldecode(stripslashes((string) $_COOKIE['sgmr_booking_ctx'])), true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $payload[$eventId] = [
            'order_id' => $context['order_id'] ?? 0,
            'signature' => $context['signature'] ?? '',
            'duration_minutes' => $context['duration_minutes'] ?? 0,
            'sgm' => $context['counts']['m'] ?? 0,
            'sge' => $context['counts']['e'] ?? 0,
            'region' => $context['router']['region'] ?? ($context['region'] ?? ''),
            'event_id' => $eventId,
        ];

        $encoded = wp_json_encode($payload);
        if ($encoded !== false && !headers_sent()) {
            setcookie('sgmr_booking_ctx', rawurlencode($encoded), time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false);
        }
    }

    /**
     * @param array<string, mixed> $router
     * @return array<string, mixed>
     */
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
            $clean['strategy'] = sanitize_key((string) $router['strategy']);
        }
        if (isset($router['drive_minutes'])) {
            $clean['drive_minutes'] = (int) $router['drive_minutes'];
        }
        if (isset($router['event_id'])) {
            $clean['event_id'] = (int) $router['event_id'];
        }
        return $clean;
    }

    /**
     * @param mixed $calendarSlot
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $rawData
     * @return array<string, mixed>|null
     */
    private function fallbackContextFromEvent($calendarSlot, array $bookingData, array $rawData): ?array
    {
        $eventId = $this->extractEventId($calendarSlot, $bookingData, $rawData);
        if ($eventId <= 0) {
            return null;
        }

        $cookieContext = $this->contextFromCookie($eventId);
        $orderId = isset($cookieContext['order_id']) ? (int) $cookieContext['order_id'] : 0;
        $signature = $this->sanitizeSignatureString($cookieContext['signature'] ?? '');

        if ($orderId <= 0 || $signature === '') {
            $map = get_transient($this->eventSignatureTransientKey($eventId));
            if (is_array($map) && $map) {
                if ($signature !== '' && isset($map[$signature])) {
                    $candidate = $map[$signature];
                } else {
                    $candidate = end($map);
                }
                if (is_array($candidate)) {
                    $orderId = isset($candidate['order_id']) ? (int) $candidate['order_id'] : $orderId;
                    if ($signature === '' && !empty($candidate['signature'])) {
                        $signature = $this->sanitizeSignatureString($candidate['signature']);
                    }
                    if (empty($cookieContext)) {
                        $cookieContext = $candidate;
                    } else {
                        $cookieContext = array_merge($candidate, $cookieContext);
                    }
                }
            }
        }

        if ($orderId <= 0 || $signature === '') {
            return null;
        }

        $cached = $this->cachedContext($orderId, $signature);
        if ($cached) {
            return $cached;
        }

        $args = [];
        if (!empty($cookieContext['region'])) {
            $args['region'] = (string) $cookieContext['region'];
        }
        if (isset($cookieContext['sgm'])) {
            $args['sgm'] = (int) $cookieContext['sgm'];
        }
        if (isset($cookieContext['sge'])) {
            $args['sge'] = (int) $cookieContext['sge'];
        }
        if (!empty($cookieContext['event_id'])) {
            $args['router'] = ['event_id' => (int) $cookieContext['event_id']];
        }

        return $this->contextForOrder($orderId, $signature, $args);
    }

    /**
     * @param \FluentBooking\App\Models\Booking $booking
     * @return array<string, mixed>|null
     */
    private function fallbackContextFromBooking($booking): ?array
    {
        $eventId = isset($booking->event_id) ? (int) $booking->event_id : 0;
        if ($eventId <= 0) {
            return null;
        }

        $cookieContext = $this->contextFromCookie($eventId);
        $orderId = isset($cookieContext['order_id']) ? (int) $cookieContext['order_id'] : 0;
        $signature = $this->sanitizeSignatureString($cookieContext['signature'] ?? '');

        if ($orderId <= 0 || $signature === '') {
            $map = get_transient($this->eventSignatureTransientKey($eventId));
            if (is_array($map) && $map) {
                if ($signature !== '' && isset($map[$signature])) {
                    $candidate = $map[$signature];
                } else {
                    $candidate = end($map);
                }
                if (is_array($candidate)) {
                    $orderId = isset($candidate['order_id']) ? (int) $candidate['order_id'] : $orderId;
                    if ($signature === '' && !empty($candidate['signature'])) {
                        $signature = $this->sanitizeSignatureString($candidate['signature']);
                    }
                    if (empty($cookieContext)) {
                        $cookieContext = $candidate;
                    } else {
                        $cookieContext = array_merge($candidate, $cookieContext);
                    }
                }
            }
        }

        if ($orderId <= 0 || $signature === '') {
            return null;
        }

        $cached = $this->cachedContext($orderId, $signature);
        if ($cached) {
            return $cached;
        }

        $args = [];
        if (!empty($cookieContext['region'])) {
            $args['region'] = (string) $cookieContext['region'];
        }
        if (isset($cookieContext['sgm'])) {
            $args['sgm'] = (int) $cookieContext['sgm'];
        }
        if (isset($cookieContext['sge'])) {
            $args['sge'] = (int) $cookieContext['sge'];
        }
        $args['router'] = ['event_id' => $eventId];

        return $this->contextForOrder($orderId, $signature, $args);
    }

    /**
     * @param \FluentBooking\App\Models\Booking $booking
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function rebuildContextForBooking($booking, array $context): ?array
    {
        $orderId = isset($context['order_id']) ? (int) $context['order_id'] : 0;
        $signature = isset($context['signature']) ? (string) $context['signature'] : '';
        $eventId = isset($context['router']['event_id']) ? (int) $context['router']['event_id'] : (int) ($context['sg_event_id'] ?? ($booking->event_id ?? 0));

        if ($orderId <= 0 || $signature === '') {
            if ($eventId > 0) {
                $map = get_transient($this->eventSignatureTransientKey($eventId));
                if (is_array($map) && $map) {
                    $candidate = end($map);
                    if (is_array($candidate)) {
                        if ($orderId <= 0 && !empty($candidate['order_id'])) {
                            $orderId = (int) $candidate['order_id'];
                        }
                        if ($signature === '' && !empty($candidate['signature'])) {
                            $signature = $this->sanitizeSignatureString($candidate['signature']);
                        }
                    }
                }
            }
        }

        if ($orderId <= 0 || $signature === '') {
            return null;
        }

        $args = [];
        if (isset($context['router']) && is_array($context['router'])) {
            $args['router'] = $context['router'];
        } elseif ($eventId > 0) {
            $args['router'] = ['event_id' => $eventId];
        }
        if (!empty($context['router']['region'])) {
            $args['region'] = (string) $context['router']['region'];
        } elseif (!empty($context['region'])) {
            $args['region'] = (string) $context['region'];
        }
        if (isset($context['counts']['m'])) {
            $args['sgm'] = (int) $context['counts']['m'];
        }
        if (isset($context['counts']['e'])) {
            $args['sge'] = (int) $context['counts']['e'];
        }

        $reloaded = $this->contextForOrder($orderId, $signature, $args);
        if ($reloaded) {
            Helper::updateBookingMeta($booking->id, 'sgmr_context', $reloaded);
        }
        return $reloaded;
    }

    private function buildTitleFromOrder(int $orderId): string
    {
        if ($orderId <= 0) {
            return '';
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $details = $this->orderContactDetails($order);

        return $this->buildTitle($details['postcode'], $details['name'], $details['phone'], [
            'sg_delivery_postcode' => $details['postcode'],
            'sg_full_name' => $details['name'],
            'sg_phone' => $details['phone'],
        ]);
    }

    private function orderContactDetails(WC_Order $order): array
    {
        $postcode = sanitize_text_field((string) ($order->get_shipping_postcode() ?: $order->get_billing_postcode()));
        $firstName = (string) ($order->get_shipping_first_name() ?: $order->get_billing_first_name());
        $lastName = (string) ($order->get_shipping_last_name() ?: $order->get_billing_last_name());
        $name = trim($firstName . ' ' . $lastName);
        if ($name === '') {
            $name = trim((string) $order->get_formatted_billing_full_name());
        }
        $phone = $this->sanitizePhone((string) (
            $order->get_meta('_sg_delivery_phone', true) ?: $order->get_shipping_phone() ?: $order->get_billing_phone()
        ));

        return [
            'postcode' => $postcode,
            'name' => $name,
            'phone' => $phone,
        ];
    }

    private function contactDetailsFromContext(array $context): array
    {
        $stable = isset($context['stable']) && is_array($context['stable']) ? $context['stable'] : [];
        $postcode = $stable['sg_delivery_postcode'] ?? ($context['postcode'] ?? '');
        $name = $stable['sg_full_name'] ?? ($context['name'] ?? '');
        if ($name === '' && (!empty($stable['sg_first_name']) || !empty($stable['sg_last_name']))) {
            $name = trim(($stable['sg_first_name'] ?? '') . ' ' . ($stable['sg_last_name'] ?? ''));
        }
        $phone = $stable['sg_phone'] ?? ($context['phone'] ?? '');

        return [
            'postcode' => sanitize_text_field((string) $postcode),
            'name' => trim((string) $name),
            'phone' => $this->sanitizePhone((string) $phone),
        ];
    }

    private function orderPhone(int $orderId): string
    {
        if ($orderId <= 0) {
            return '';
        }
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }
        return $this->orderContactDetails($order)['phone'];
    }

    /**
     * @param mixed $calendarSlot
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $rawData
     */
    private function extractEventId($calendarSlot, array $bookingData, array $rawData): int
    {
        if (is_object($calendarSlot) && property_exists($calendarSlot, 'id')) {
            $candidate = (int) $calendarSlot->id;
            if ($candidate > 0) {
                return $candidate;
            }
        }

        $sources = [$bookingData, $rawData];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach (['event_id', 'calendar_event_id', 'slot_id'] as $key) {
                if (isset($source[$key]) && is_numeric($source[$key])) {
                    $value = (int) $source[$key];
                    if ($value > 0) {
                        return $value;
                    }
                }
            }
        }

        return 0;
    }

    private function formatDurationLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }

        if (class_exists('\\FluentBooking\\App\\Services\\Helper')) {
            return Helper::formatDuration($minutes);
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = sprintf(_n('%d Day', '%d Days', $days, 'sg-mr'), $days);
        }
        if ($hours > 0) {
            $parts[] = sprintf(_n('%d Hour', '%d Hours', $hours, 'sg-mr'), $hours);
        }
        if ($mins > 0 || !$parts) {
            $parts[] = sprintf(_n('%d Minute', '%d Minutes', $mins, 'sg-mr'), $mins);
        }

        return implode(' ', $parts);
    }

    private function sanitizeSignatureString($signature): string
    {
        if (!is_string($signature)) {
            return '';
        }
        $signature = preg_replace('/[^0-9a-f\.]+/i', '', $signature);
        return $signature ?: '';
    }

    private function buildTitle(string $postcode, string $name, string $phone, array $stable = []): string
    {
        $postcode = $postcode !== '' ? sanitize_text_field($postcode) : sanitize_text_field($stable['sg_delivery_postcode'] ?? '');
        $fullname = trim($name);
        if ($fullname === '' && !empty($stable['sg_first_name']) || !empty($stable['sg_last_name'])) {
            $fullname = trim(($stable['sg_first_name'] ?? '') . ' ' . ($stable['sg_last_name'] ?? ''));
        }
        if ($fullname === '' && !empty($stable['sg_delivery_address'])) {
            $fullname = $stable['sg_delivery_address'];
        }
        $phoneValue = $this->sanitizePhone($phone);
        if ($phoneValue === '' && !empty($stable['sg_phone'])) {
            $phoneValue = $this->sanitizePhone($stable['sg_phone']);
        }

        $parts = [
            $postcode,
            $fullname,
            $phoneValue,
        ];

        $parts = array_map('trim', array_map('strval', array_filter($parts, static function ($value) {
            return $value !== null && $value !== '';
        })));

        if (!$parts) {
            return '';
        }

        return implode(' – ', $parts);
    }

    private function buildDescription(string $services, $items, string $address): string
    {
        if (is_array($items)) {
            $items = implode("\n", array_filter($items));
        }

        $lines = [];
        if ($services !== '') {
            $lines[] = 'Services: ' . $services;
        }
        if ($items !== '') {
            $lines[] = "Artikel:\n" . $items;
        }
        if ($address !== '') {
            $lines[] = 'Adresse des Teilnehmers: ' . $address;
        }

        return implode("\n\n", $lines);
    }

    private function buildAddressLine(array $stable): string
    {
        if (!empty($stable['sg_delivery_address'])) {
            return (string) $stable['sg_delivery_address'];
        }

        $street = $stable['sg_delivery_street'] ?? '';
        $postcode = $stable['sg_delivery_postcode'] ?? '';
        $city = $stable['sg_delivery_city'] ?? '';

        $street = sanitize_text_field((string) $street);
        $postcode = sanitize_text_field((string) $postcode);
        $city = sanitize_text_field((string) $city);

        $parts = array_filter([$street, trim($postcode . ' ' . $city)]);

        return implode(', ', $parts);
    }

    private function sanitizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9+\- ]+/', '', $phone);
        return $phone ? trim($phone) : '';
    }

    private function formatBookingRange(string $startUtc, string $endUtc, string $timezone): string
    {
        try {
            $tz = $timezone !== '' ? new DateTimeZone($timezone) : wp_timezone();
        } catch (\Throwable $exception) {
            $tz = wp_timezone();
        }

        try {
            $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
            $end = new DateTimeImmutable($endUtc, new DateTimeZone('UTC'));
        } catch (\Throwable $exception) {
            return '';
        }

        $start = $start->setTimezone($tz);
        $end = $end->setTimezone($tz);

        $timeFormat = get_option('time_format') ?: 'H:i';
        $dateFormat = get_option('date_format') ?: 'Y-m-d';

        $startLabel = $start->format($timeFormat);
        $endLabel = $end->format($timeFormat);
        $dateLabel = $start->format($dateFormat);

        return trim($startLabel . ' – ' . $endLabel . ', ' . $dateLabel);
    }

    private function tokenTtlSeconds(): int
    {
        $settings = $this->settings();
        $hours = isset($settings['token_ttl_hours']) ? (int) $settings['token_ttl_hours'] : 96;
        if ($hours <= 0) {
            $hours = 1;
        }
        return $hours * HOUR_IN_SECONDS;
    }
}
