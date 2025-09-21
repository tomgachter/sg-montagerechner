<?php

namespace SGMR\Booking;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use SGMR\Plugin;
use SGMR\Services\CartService;
use SGMR\Services\ScheduleService;
use SGMR\Utils\PostcodeHelper;
use SGMR\Region\RegionDayPlanner;
use WC_Order;
use WP_Error;
use function __;
use function _n;
use function apply_filters;
use function current_time;
use function date_i18n;
use function get_option;
use function sanitize_key;
use function sanitize_text_field;
use function preg_replace;
use function sgmr_booking_signature_parse;
use function sgmr_log;
use function sgmr_normalize_region_slug;
use function time;
use function wp_timezone;
use const WEEK_IN_SECONDS;

class BookingOrchestrator
{
    public const ORDER_META_BOOKINGS = '_sgmr_fb_bookings';
    private const ORDER_META_ROUTER_LOG = '_sgmr_router_log';
    private const ORDER_META_ROUTER_EVENTS = '_sgmr_router_events';
    private const EVENT_LOG_TTL = WEEK_IN_SECONDS;

    private FluentBookingClient $client;
    private PrefillManager $prefillManager;
    private bool $keepSelectorDefault;
    private RegionDayPlanner $regionDayPlanner;
    private RouterState $routerState;
    private ?FluentBookingConnector $connector;
    /** @var array<int, array<string, mixed>> */
    private array $routerLogCache = [];
    /** @var array<int, array<string, int>> */
    private array $eventLogCache = [];

    public function __construct(FluentBookingClient $client, PrefillManager $prefillManager, bool $keepSelector, RegionDayPlanner $regionDayPlanner, RouterState $routerState, ?FluentBookingConnector $connector = null)
    {
        $this->client = $client;
        $this->prefillManager = $prefillManager;
        $this->keepSelectorDefault = $keepSelector;
        $this->regionDayPlanner = $regionDayPlanner;
        $this->routerState = $routerState;
        $this->connector = $connector;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|WP_Error
     */
    public function handle(string $event, WC_Order $order, array $payload, string $signature)
    {
        try {
            $eventHash = $this->eventHash($event, $payload);

            switch ($event) {
                case 'booking_created':
                    return $this->handleCreated($order, $payload, $signature, false, $eventHash);
                case 'booking_rescheduled':
                    return $this->handleRescheduled($order, $payload, $signature, $eventHash);
                case 'booking_cancelled':
                    return $this->handleCancelled($order, $payload, $signature, $eventHash);
                default:
                    return [
                        'status' => 'ignored',
                        'handled' => false,
                        'event' => $event,
                    ];
            }
        } catch (RuntimeException $exception) {
            sgmr_log('webhook_' . sanitize_key($event) . '_failed', [
                'order_id' => $order->get_id(),
                'reason' => $exception->getMessage(),
            ]);
            return new WP_Error('sgmr_webhook_failed', $exception->getMessage(), ['status' => 500]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleCreated(WC_Order $order, array $payload, string $signature, bool $isReschedule, ?string $eventHash = null): array
    {
        if ($eventHash && $this->hasProcessedEvent($order, $eventHash)) {
            return [
                'status' => 'duplicate',
                'handled' => false,
                'event' => $isReschedule ? 'booking_rescheduled' : 'booking_created',
            ];
        }

        if (!CartService::orderHasService($order)) {
            throw new RuntimeException(__('Für diesen Auftrag ist keine Serviceleistung hinterlegt.', 'sg-mr'));
        }

        $region = sgmr_normalize_region_slug((string) $order->get_meta(CartService::META_REGION_KEY, true));
        if ($region === '' || $region === 'on_request') {
            throw new RuntimeException(__('Region kann nicht ermittelt werden.', 'sg-mr'));
        }

        $counts = CartService::ensureOrderCounts($order);
        $montageCount = max(0, (int) ($counts['montage'] ?? 0));
        $etageCount = max(0, (int) ($counts['etage'] ?? 0));
        if ($montageCount <= 0 && $etageCount <= 0) {
            throw new RuntimeException(__('Keine Montage- oder Etagenlieferungspositionen vorhanden.', 'sg-mr'));
        }
        if ($montageCount >= 4) {
            throw new RuntimeException(__('Automatische Planung nicht möglich (≥4 Montagen).', 'sg-mr'));
        }

        $startContext = $this->extractStartContext($payload);
        if (!$startContext) {
            throw new RuntimeException(__('Startzeit konnte nicht aus dem Selector gelesen werden.', 'sg-mr'));
        }
        if ($startContext['slot_index'] === null) {
            throw new RuntimeException(__('Das gewählte Zeitfenster ist unbekannt.', 'sg-mr'));
        }

        $remoteBooking = isset($payload['booking']) && is_array($payload['booking']) ? $payload['booking'] : [];
        $bookingSource = isset($remoteBooking['source']) ? (string) $remoteBooking['source'] : '';
        $existingRemoteId = isset($remoteBooking['id']) ? (int) $remoteBooking['id'] : 0;
        $slotMinutesRemote = $this->slotMinutesFromPayload($payload, $remoteBooking);

        $requiredMinutes = ScheduleService::minutesRequired($montageCount, $etageCount);
        $requiredSlots = ScheduleService::slotsRequired($montageCount, $etageCount);
        $slotContext = [
            'montage' => $montageCount,
            'etage' => $etageCount,
        ];

        $originalContext = $startContext;
        $startDateTime = $startContext['start_at'] instanceof DateTimeInterface
            ? $startContext['start_at']
            : new DateTimeImmutable($startContext['date'] . ' 00:00:00', wp_timezone());

        $policyMode = $this->regionDayPlanner->policy();
        $enforcePolicy = $bookingSource !== 'web';

        $teamKey = null;
        $sequence = [];
        $sequenceSource = 'resolved';
        $wasRescheduled = false;

        if ($this->regionDayPlanner->isDateAllowed($region, $startDateTime)) {
            [$teamKey, $sequence] = $this->resolveTeamAndSequence(
                $region,
                $startContext['date'],
                (int) $startContext['slot_index'],
                $requiredMinutes,
                $requiredSlots,
                $slotContext
            );
        } elseif ($policyMode === RegionDayPlanner::POLICY_RESCHEDULE && $enforcePolicy) {
            $replacement = $this->findRescheduledPlacement(
                $region,
                $originalContext,
                $requiredMinutes,
                $requiredSlots,
                $slotContext
            );
            if (!$replacement) {
                throw new RuntimeException(__('Für diese Region konnte kein Ausweichtermin gefunden werden.', 'sg-mr'));
            }
            $startContext = $replacement['context'];
            $teamKey = $replacement['team'];
            $sequence = $replacement['sequence'];
            $wasRescheduled = true;
            $startDateTime = $startContext['start_at'] instanceof DateTimeInterface
                ? $startContext['start_at']
                : new DateTimeImmutable($startContext['date'] . ' 00:00:00', wp_timezone());
        } elseif ($enforcePolicy) {
            throw new RuntimeException(__('Der gewählte Wochentag ist für diese Region nicht buchbar. Bitte wählen Sie einen erlaubten Termin.', 'sg-mr'));
        } else {
            [$teamKey, $sequence] = $this->resolveTeamAndSequence(
                $region,
                $startContext['date'],
                (int) $startContext['slot_index'],
                $requiredMinutes,
                $requiredSlots,
                $slotContext
            );
        }

        if (!$teamKey && isset($remoteBooking['calendar_id'])) {
            $teamKey = $this->client->teamByCalendarId((int) $remoteBooking['calendar_id']);
        }

        if (!$sequence && $slotMinutesRemote > 0) {
            $sequence = $this->buildSequenceFromMinutes((int) $startContext['slot_index'], $slotMinutesRemote);
            if ($sequence) {
                $sequenceSource = 'slot_minutes';
            }
        }

        if (!$sequence) {
            $sequence = $this->buildFallbackSequence((int) $startContext['slot_index'], $requiredSlots);
            if ($sequence && $sequenceSource === 'resolved') {
                $sequenceSource = 'fallback_required_slots';
            }
        }

        if (!$teamKey || !$sequence) {
            throw new RuntimeException(__('Kein passendes Zeitfenster verfügbar.', 'sg-mr'));
        }

        $teamConfig = $this->client->team($teamKey);
        $teamLabel = $teamConfig['label'] ?? strtoupper($teamKey);

        $prefill = $this->prefillManager->payloadFor($order, $region, $montageCount, $etageCount, $signature);
        $routerMeta = isset($prefill['router']) && is_array($prefill['router']) ? $prefill['router'] : [];
        $primaryService = $this->primaryService($montageCount, $etageCount);
        $calendarId = $this->resolveCalendarId($region, $primaryService, $teamKey, $routerMeta);
        if ($calendarId <= 0 && isset($remoteBooking['calendar_id'])) {
            $calendarId = (int) $remoteBooking['calendar_id'];
        }
        $routerLog = $this->getRouterLog($order);
        $logChanged = false;

        $prefillRouterMeta = isset($prefill['router_meta']) && is_array($prefill['router_meta']) ? $prefill['router_meta'] : [];
        $durationMinutesPrefill = isset($prefill['duration_minutes']) ? max(0, (int) $prefill['duration_minutes']) : $this->calculateDurationFallback($montageCount, $etageCount);

        $parsedSignature = sgmr_booking_signature_parse($signature);

        $groupId = 'order_' . $order->get_id() . '_' . gmdate('YmdHis');
        $createdEntries = [];
        $slotsDefinitions = ScheduleService::slots();
        $regionLabel = PostcodeHelper::regionLabel($region);

        $title = $this->buildTitle($prefill, $regionLabel, $teamLabel);
        $description = $this->buildDescription(
            $prefill,
            $montageCount,
            $etageCount,
            $durationMinutesPrefill,
            $prefillRouterMeta,
            $regionLabel,
            $teamLabel
        );
        $metaBase = $this->buildMeta($order, $prefill, $region, $regionLabel, $teamKey, $teamLabel, $startContext, $calendarId, $primaryService, $routerMeta);

        foreach ($sequence as $index => $slotIndex) {
            $slotDef = $slotsDefinitions[$slotIndex] ?? null;
            if (!$slotDef) {
                continue;
            }

            $bookingPayload = [
                'team' => $teamKey,
                'team_label' => $teamLabel,
                'date' => $startContext['date'],
                'slot_index' => $slotIndex,
                'slot' => $slotDef,
                'duration' => $durationMinutesPrefill,
                'duration_minutes' => $durationMinutesPrefill,
                'order_id' => $order->get_id(),
                'group_id' => $groupId,
                'sequence_index' => $index,
                'sequence_size' => count($sequence),
                'montage_total' => $montageCount,
                'etage_total' => $etageCount,
                'minutes_required' => $requiredMinutes,
                'context' => [
                    'region' => $region,
                    'region_label' => $regionLabel,
                    'team_label' => $teamLabel,
                    'selector_booking_id' => $startContext['booking_id'],
                    'token_ts' => $parsedSignature['ts'],
                ],
                'customer' => $prefill['person'] ?? [],
                'address' => $prefill['address'] ?? [],
                'items' => $prefill['items']['lines'] ?? [],
                'ics' => [
                    'summary' => $title,
                    'description' => $description,
                ],
                'meta' => $metaBase,
                'prefill' => $prefill,
                'remote_booking_id' => $existingRemoteId ? (string) $existingRemoteId : '',
            ];

            if ($existingRemoteId > 0) {
                $bookingPayload['source'] = 'webhook';
            }

            do_action_ref_array('sg_mr_fb_create_slot_booking', [&$bookingPayload]);
            if (!empty($bookingPayload['error'])) {
                throw new RuntimeException($bookingPayload['error']);
            }

            $remoteIdForEntry = isset($bookingPayload['remote_booking_id']) && $bookingPayload['remote_booking_id'] !== ''
                ? (string) $bookingPayload['remote_booking_id']
                : ($existingRemoteId ? (string) $existingRemoteId : '');

            $createdEntries[] = [
                'internal_id' => $groupId . '_' . $index,
                'team' => $teamKey,
                'team_label' => $teamLabel,
                'date' => $startContext['date'],
                'slot_index' => $slotIndex,
                'slot' => $slotDef,
                'mode' => $this->determineMode($montageCount, $etageCount),
                'selector_booking_id' => $startContext['booking_id'],
                'created_at' => current_time('mysql'),
                'group_id' => $groupId,
                'token_ts' => $parsedSignature['ts'],
                'remote_booking_id' => $remoteIdForEntry,
                'remote_response' => $bookingPayload['remote_response'] ?? [],
                'calendar_id' => $calendarId,
                'service' => $primaryService,
            ];
        }

        if ($createdEntries) {
            $this->storeBookings($order, $createdEntries);
        }

        if ($calendarId > 0 && $primaryService !== '' && isset($startContext['date'])) {
            $logKey = $this->routerLogKey($calendarId, $startContext['date'], $primaryService);
            if (!isset($routerLog[$logKey])) {
                $this->routerState->bumpCounter($calendarId, $startContext['date'], $primaryService, 1);
                $routerLog[$logKey] = [
                    'calendar_id' => $calendarId,
                    'date' => $startContext['date'],
                    'service' => $primaryService,
                ];
                $logChanged = true;
            }
        }

        $slotsDefinitions = ScheduleService::slots();
        $firstSlotIndex = $sequence[0] ?? null;
        $lastSlotIndex = $sequence[count($sequence) - 1] ?? $firstSlotIndex;
        $startTime = ($firstSlotIndex !== null && isset($slotsDefinitions[$firstSlotIndex][0])) ? $slotsDefinitions[$firstSlotIndex][0] : '';
        $endTime = ($lastSlotIndex !== null && isset($slotsDefinitions[$lastSlotIndex][1])) ? $slotsDefinitions[$lastSlotIndex][1] : '';
        $timeRange = ($startTime !== '' && $endTime !== '') ? sprintf('%s – %s', $startTime, $endTime) : ($startTime ?: '');
        $serviceSummary = $this->formatServiceSummary($montageCount, $etageCount);
        $dateDisplay = $this->formatBookingDate($startContext['date']);

        $calendarDisplay = $calendarId > 0 ? ('#' . $calendarId) : __('unbekannt', 'sg-mr');
        $startLabel = $startTime !== '' ? $startTime : __('unbekannt', 'sg-mr');
        $endLabel = $endTime !== '' ? $endTime : __('unbekannt', 'sg-mr');
        $serviceSuffix = $serviceSummary !== '' ? ' (' . $serviceSummary . ')' : '';

        $bookingNote = sprintf(
            'SGMR: booking_created – Kalender %1$s – %2$s %3$s → %4$s – Team %5$s%6$s.',
            $calendarDisplay,
            $dateDisplay,
            $startLabel,
            $endLabel,
            $teamLabel,
            $serviceSuffix
        );

        $order->add_order_note($bookingNote);

        $itemLines = [];
        if (isset($prefill['items']['lines']) && is_array($prefill['items']['lines'])) {
            $itemLines = $prefill['items']['lines'];
        } elseif (isset($prefill['items_lines']) && is_array($prefill['items_lines'])) {
            $itemLines = $prefill['items_lines'];
        }
        if (!empty($itemLines)) {
            $formattedItems = implode("\n", array_map(function ($line) {
                return ' - ' . trim((string) $line);
            }, $itemLines));
            $order->add_order_note(sprintf("[SGMR] Terminpositionen aktualisiert (%s – %s):\n%s", $regionLabel, $teamLabel, $formattedItems));
        }

        if (!$isReschedule) {
            if (defined('SGMR_STATUS_BOOKED')) {
                $this->transitionStatus($order, \SGMR_STATUS_BOOKED, $bookingNote);
            } else {
                $this->transitionStatus($order, \SGMR_STATUS_PLANNED_ONLINE, $bookingNote);
            }
        }

        if ($logChanged) {
            $this->storeRouterLog($order, $routerLog);
        }

        if ($eventHash) {
            $this->markEventProcessed($order, $eventHash);
        }

        $logContext = [
            'order_id' => $order->get_id(),
            'team' => $teamKey,
            'team_label' => $teamLabel,
            'date' => $startContext['date'],
            'slots' => $this->formatSequence($sequence),
            'selector_booking_id' => $startContext['booking_id'],
            'minutes_required' => $requiredMinutes,
            'slot_count' => count($sequence),
            'is_reschedule' => ($isReschedule || $wasRescheduled) ? 'yes' : 'no',
            'time_range' => $timeRange,
            'region_day_policy' => $policyMode,
            'slot_minutes_remote' => $slotMinutesRemote,
            'sequence_source' => $sequenceSource,
        ];
        if ($wasRescheduled && isset($originalContext['date'])) {
            $logContext['requested_date'] = $originalContext['date'];
            $logContext['rescheduled_from'] = $originalContext['date'];
        }

        $logContext['phase'] = 'processed';
        $eventKey = ($isReschedule || $wasRescheduled) ? 'webhook_booking_rescheduled' : 'webhook_booking_created';
        $remoteIds = array_filter(array_map(static function ($entry) {
            return isset($entry['remote_booking_id']) ? (string) $entry['remote_booking_id'] : '';
        }, $createdEntries));
        if ($remoteIds) {
            $logContext['remote_booking_ids'] = $remoteIds;
        }
        sgmr_log($eventKey, $logContext);
        sgmr_log('composite_bookings_created', $logContext);
        sgmr_log('fb_booking_orchestrated', $logContext);

        if ($startContext['booking_id'] !== '') {
            if ($this->shouldKeepSelector()) {
                sgmr_log('selector_kept', [
                    'order_id' => $order->get_id(),
                    'selector_booking_id' => $startContext['booking_id'],
                ]);
            } else {
                do_action('sg_mr_fb_cancel_selector_booking', $startContext['booking_id'], $order->get_id(), $payload);
                sgmr_log('selector_cancelled', [
                    'order_id' => $order->get_id(),
                    'selector_booking_id' => $startContext['booking_id'],
                ]);
            }
        }

        return [
            'status' => 'scheduled',
            'handled' => true,
            'team' => $teamKey,
            'team_label' => $teamLabel,
            'date' => $startContext['date'],
            'slots' => $this->formatSequence($sequence),
            'rescheduled' => $wasRescheduled ? 'yes' : 'no',
            'time_range' => $timeRange,
            'region_day_policy' => $policyMode,
            'note_summary' => $this->formatScheduleSummary($dateDisplay, $timeRange, $teamLabel),
            'slot_minutes_remote' => $slotMinutesRemote,
            'sequence_source' => $sequenceSource,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleRescheduled(WC_Order $order, array $payload, string $signature, ?string $eventHash = null): array
    {
        if ($eventHash && $this->hasProcessedEvent($order, $eventHash)) {
            return [
                'status' => 'duplicate',
                'handled' => false,
                'event' => 'booking_rescheduled',
            ];
        }

        $previousEntries = $this->getStoredBookings($order);
        $previousEntry = $previousEntries[0] ?? null;
        $oldSummary = $previousEntry ? $this->formatStoredEntrySummary($previousEntry) : '';

        $cancelled = $this->cancelStoredBookings($order, $payload, 'rescheduled');
        $result = $this->handleCreated($order, $payload, $signature, true, null);
        $result['cancelled_before'] = $cancelled;

        $newSummary = $result['note_summary'] ?? '';
        $note = sprintf(
            'SGMR: Alt %1$s → Neu %2$s.',
            $oldSummary !== '' ? $oldSummary : __('unbekannt', 'sg-mr'),
            $newSummary !== '' ? $newSummary : __('unbekannt', 'sg-mr')
        );

        if ($order->get_status() === SGMR_STATUS_RESCHEDULE) {
            $order->add_order_note($note);
        } else {
            if (defined('SGMR_STATUS_RESCHEDULE')) {
                $this->transitionStatus($order, \SGMR_STATUS_RESCHEDULE, $note);
            } else {
                $order->add_order_note($note);
            }
        }

        if ($eventHash) {
            $this->markEventProcessed($order, $eventHash);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleCancelled(WC_Order $order, array $payload, string $signature, ?string $eventHash = null): array
    {
        if ($eventHash && $this->hasProcessedEvent($order, $eventHash)) {
            return [
                'status' => 'duplicate',
                'handled' => false,
                'event' => 'booking_cancelled',
            ];
        }

        $existingEntries = $this->getStoredBookings($order);
        $firstSummary = $existingEntries ? $this->formatStoredEntrySummary($existingEntries[0]) : '';

        $cancelled = $this->cancelStoredBookings($order, $payload, 'cancelled');
        $note = sprintf(
            'SGMR: booking_cancelled – %s.',
            $firstSummary !== '' ? $firstSummary : __('keine Termin-Details', 'sg-mr')
        );
        $current = $order->get_status();
        if (defined('SGMR_STATUS_CANCELED')) {
            if ($current === SGMR_STATUS_CANCELED) {
                $order->add_order_note($note);
            } else {
                $this->transitionStatus($order, \SGMR_STATUS_CANCELED, $note);
            }
        } else {
            $this->transitionStatus($order, \SGMR_STATUS_ONLINE, $note);
        }
        $selectorBookingId = $this->firstScalar([
            isset($payload['booking']) && is_array($payload['booking']) ? $payload['booking'] : [],
            $payload,
        ], ['booking_id', 'id', 'uid']);
        if ($selectorBookingId !== '') {
            sgmr_log('selector_cancelled', [
                'order_id' => $order->get_id(),
                'selector_booking_id' => $selectorBookingId,
            ]);
        }
        sgmr_log('webhook_booking_cancelled', [
            'order_id' => $order->get_id(),
            'cancelled_slots' => $cancelled,
            'phase' => 'processed',
        ]);

        if ($eventHash) {
            $this->markEventProcessed($order, $eventHash);
        }

        return [
            'status' => 'cancelled',
            'handled' => true,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{date:string,time:string,slot_index:int|null,timezone:string,start_at:DateTimeImmutable,booking_id:string}|null
     */
    private function extractStartContext(array $payload): ?array
    {
        $booking = isset($payload['booking']) && is_array($payload['booking']) ? $payload['booking'] : [];

        $start = $this->firstScalar([$booking, $payload], ['start', 'start_time', 'startDateTime']);
        $timezone = $this->firstScalar([$booking, $payload], ['timezone', 'time_zone', 'timeZone']);
        $personTimezone = $this->firstScalar([$booking, $payload], ['person_time_zone', 'person_timezone']);

        if ($start === '') {
            $datePart = $this->firstScalar([$booking, $payload], ['start_date', 'date']);
            $timePart = $this->firstScalar([$booking, $payload], ['time', 'start_time']);
            if ($datePart !== '' && $timePart !== '') {
                $start = trim($datePart) . ' ' . trim($timePart);
            }
        }

        if ($start === '') {
            return null;
        }

        $siteTz = wp_timezone();
        $timezoneCandidates = array_values(array_filter(array_unique([
            $timezone,
            $personTimezone,
            'UTC',
            $siteTz->getName(),
        ])));

        foreach ($timezoneCandidates as $tzName) {
            try {
                $dt = new DateTimeImmutable($start, new DateTimeZone($tzName));
            } catch (\Exception $exception) {
                continue;
            }

            $local = $dt->setTimezone($siteTz);
            $time = $local->format('H:i');
            $slotIndex = $this->matchSlotIndex($time);
            if ($slotIndex !== null) {
                return [
                    'date' => $local->format('Y-m-d'),
                    'time' => $time,
                    'slot_index' => $slotIndex,
                    'timezone' => $tzName,
                    'start_at' => $local,
                    'booking_id' => $this->firstScalar([$booking, $payload], ['booking_id', 'id', 'uid']),
                ];
            }
        }

        try {
            $dt = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            return null;
        }

        $local = $dt->setTimezone($siteTz);
        $nearestSlotIndex = $this->nearestSlotIndex($local->format('H:i'));
        if ($nearestSlotIndex === null) {
            return null;
        }

        $slots = ScheduleService::slots();
        $slotStart = isset($slots[$nearestSlotIndex][0]) ? (string) $slots[$nearestSlotIndex][0] : $local->format('H:i');

        try {
            $adjusted = new DateTimeImmutable($local->format('Y-m-d') . ' ' . $slotStart, $siteTz);
        } catch (\Exception $exception) {
            $adjusted = $local;
        }

        return [
            'date' => $adjusted->format('Y-m-d'),
            'time' => $adjusted->format('H:i'),
            'slot_index' => $nearestSlotIndex,
            'timezone' => 'UTC',
            'start_at' => $adjusted,
            'booking_id' => $this->firstScalar([$booking, $payload], ['booking_id', 'id', 'uid']),
        ];
    }

    private function matchSlotIndex(string $time): ?int
    {
        $slots = ScheduleService::slots();
        $targetMinutes = $this->timeToMinutes($time);
        foreach ($slots as $index => $slot) {
            $start = $this->timeToMinutes($slot[0]);
            $end = $this->timeToMinutes($slot[1]);
            if ($targetMinutes === $start) {
                return $index;
            }
            if ($targetMinutes > $start && $targetMinutes < $end) {
                return $index;
            }
        }
        return null;
    }

    private function nearestSlotIndex(string $time): ?int
    {
        $slots = ScheduleService::slots();
        if (!$slots) {
            return null;
        }

        $targetMinutes = $this->timeToMinutes($time);
        $closestIndex = null;
        $closestDiff = PHP_INT_MAX;

        foreach ($slots as $index => $slot) {
            $startMinutes = $this->timeToMinutes($slot[0]);
            $diff = abs($targetMinutes - $startMinutes);
            if ($diff < $closestDiff) {
                $closestDiff = $diff;
                $closestIndex = $index;
            }
        }

        return $closestIndex;
    }

    private function buildSequenceFromMinutes(int $slotIndex, int $minutes): array
    {
        $slots = ScheduleService::slots();
        $count = count($slots);
        if ($count === 0 || $minutes <= 0) {
            return [];
        }

        $slotIndex = max(0, min($slotIndex, $count - 1));
        $remaining = $minutes;
        $sequence = [];

        for ($i = $slotIndex; $i < $count && $remaining > 0; $i++) {
            $sequence[] = $i;
            $slot = $slots[$i];
            $slotDuration = $this->timeToMinutes($slot[1]) - $this->timeToMinutes($slot[0]);
            if ($slotDuration <= 0) {
                $slotDuration = 30;
            }
            $remaining -= $slotDuration;
        }

        if (!$sequence) {
            $sequence[] = $slotIndex;
        }

        return $sequence;
    }

    private function buildFallbackSequence(int $slotIndex, int $requiredSlots): array
    {
        $slots = ScheduleService::slots();
        $count = count($slots);
        if ($count === 0) {
            return [];
        }

        $slotIndex = max(0, min($slotIndex, $count - 1));
        $required = max(1, $requiredSlots);

        $sequence = [];
        for ($i = 0; $i < $required && ($slotIndex + $i) < $count; $i++) {
            $sequence[] = $slotIndex + $i;
        }

        if (!$sequence) {
            $sequence[] = $slotIndex;
        }

        return $sequence;
    }

    private function slotMinutesFromPayload(array $payload, array $booking): int
    {
        $sources = [];
        $sources[] = $booking;
        $sources[] = $payload;
        if (isset($payload['meta']) && is_array($payload['meta'])) {
            $sources[] = $payload['meta'];
        }

        $keys = ['slot_minutes', 'duration_minutes', 'duration', 'slotMinutes'];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($keys as $key) {
                if (!isset($source[$key])) {
                    continue;
                }
                $value = $source[$key];
                if (is_numeric($value)) {
                    $minutes = (int) $value;
                    if ($minutes > 0) {
                        return $minutes;
                    }
                }
                if (is_string($value)) {
                    $minutes = (int) trim($value);
                    if ($minutes > 0) {
                        return $minutes;
                    }
                }
            }
        }

        return 0;
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);
        return $hours * 60 + $minutes;
    }

    /**
     * @return array{0:string,1:array<int, int>}
     */
    private function resolveTeamAndSequence(string $region, string $date, int $slotIndex, int $requiredMinutes, int $requiredSlots, array $context): array
    {
        $teams = $this->client->regionTeams($region);
        if (!$teams) {
            throw new RuntimeException(__('Für diese Region ist kein Team hinterlegt.', 'sg-mr'));
        }
        $maxTeams = max(1, $this->regionDayPlanner->maxTeams($region));
        $teams = array_slice($teams, 0, $maxTeams);
        $primary = $this->client->pickPrimaryTeam($region);
        $teamOrder = $this->buildTeamOrder($teams, $primary);
        $resolved = ScheduleService::findBestSequenceToday(
            $this->client,
            $region,
            $teamOrder,
            $date,
            $slotIndex,
            $requiredMinutes,
            $requiredSlots,
            $context
        );
        $team = $resolved['team'] ?? null;
        $sequence = $resolved['sequence'] ?? [];
        if (!$team || !$sequence) {
            throw new RuntimeException(__('Kein passendes Zeitfenster verfügbar.', 'sg-mr'));
        }
        return [$team, $sequence];
    }

    /**
     * @param array<string, mixed> $startContext
     * @param array<string, mixed> $slotContext
     * @return array<string, mixed>|null
     */
    private function findRescheduledPlacement(string $region, array $startContext, int $requiredMinutes, int $requiredSlots, array $slotContext): ?array
    {
        $timezone = wp_timezone();
        $startAt = $startContext['start_at'] instanceof DateTimeInterface
            ? $startContext['start_at']
            : new DateTimeImmutable($startContext['date'] . ' 00:00:00', $timezone);

        for ($dayOffset = 1; $dayOffset <= 21; $dayOffset++) {
            $candidate = $startAt->add(new DateInterval('P' . $dayOffset . 'D'));
            if (!$this->regionDayPlanner->isDateAllowed($region, $candidate)) {
                continue;
            }
            $date = $candidate->format('Y-m-d');
            try {
                [$teamKey, $sequence] = $this->resolveTeamAndSequence(
                    $region,
                    $date,
                    0,
                    $requiredMinutes,
                    $requiredSlots,
                    $slotContext
                );
            } catch (RuntimeException $exception) {
                continue;
            }
            if (!$teamKey || !$sequence) {
                continue;
            }
            $firstSlot = $sequence[0];
            $slots = ScheduleService::slots();
            $slotDef = $slots[$firstSlot] ?? null;
            if (!$slotDef) {
                continue;
            }
            $startTime = $slotDef[0];
            try {
                $newStart = new DateTimeImmutable($date . ' ' . $startTime, $timezone);
            } catch (\Exception $exception) {
                $hoursMinutes = explode(':', $startTime);
                $hour = isset($hoursMinutes[0]) ? (int) $hoursMinutes[0] : 8;
                $minute = isset($hoursMinutes[1]) ? (int) $hoursMinutes[1] : 0;
                $newStart = $candidate->setTime($hour, $minute, 0);
            }

            $context = $startContext;
            $context['date'] = $date;
            $context['time'] = $startTime;
            $context['slot_index'] = $firstSlot;
            $context['start_at'] = $newStart;

            return [
                'team' => $teamKey,
                'sequence' => $sequence,
                'context' => $context,
            ];
        }

        return null;
    }

    /**
     * @param array<int, string> $teams
     * @return array<int, string>
     */
    private function buildTeamOrder(array $teams, ?string $primary): array
    {
        $ordered = [];
        if ($primary && in_array($primary, $teams, true)) {
            $ordered[] = $primary;
        }
        foreach ($teams as $team) {
            if (!in_array($team, $ordered, true)) {
                $ordered[] = $team;
            }
        }
        return $ordered;
    }

    /**
     * @param array<string, mixed> $prefill
     * @param array<string, mixed> $routerMeta
     */
    private function buildTitle(array $prefill, string $regionLabel, string $teamLabel): string
    {
        $postal = sanitize_text_field((string) ($prefill['postal_code'] ?? ($prefill['address']['postcode'] ?? '')));
        if ($postal === '') {
            $postal = sanitize_text_field($regionLabel);
        }
        if ($postal === '') {
            $postal = 'n/a';
        }

        $name = sanitize_text_field((string) ($prefill['customer_name'] ?? ($prefill['customer']['name'] ?? '')));
        if ($name === '') {
            $name = sanitize_text_field($teamLabel);
        }
        if ($name === '') {
            $name = __('Unbekannt', 'sg-mr');
        }

        $phone = (string) ($prefill['customer_phone'] ?? ($prefill['customer']['phone'] ?? ''));
        if ($phone === '') {
            $phone = $this->prefillValue($prefill['fields'] ?? [], ['phone', 'phone_delivery', 'phone_billing', 'phone_shipping', 'sg_phone']);
        }
        $phone = $this->formatSummaryPhone($phone);
        if ($phone === '') {
            $phone = '---';
        }

        return sprintf('%s – %s – %s', $postal, $name, $phone);
    }

    private function buildDescription(
        array $prefill,
        int $montageCount,
        int $etageCount,
        int $durationMinutes,
        array $routerMeta,
        string $regionLabel,
        string $teamLabel
    ): string {
        $sections = [];

        $orderSection = ['**Bestellung**'];
        if (!empty($prefill['order_admin_url'])) {
            $orderSection[] = '- Admin: ' . $this->escapeMarkdown((string) $prefill['order_admin_url']);
        }
        if (!empty($prefill['order_view_url'])) {
            $orderSection[] = '- Kunden-View: ' . $this->escapeMarkdown((string) $prefill['order_view_url']);
        }
        $sections[] = implode("\n", array_filter($orderSection));

        $customerSection = ['**Kunde**'];
        $customerName = $this->escapeMarkdown((string) ($prefill['customer_name'] ?? ($prefill['customer']['name'] ?? '')));
        if ($customerName !== '') {
            $customerSection[] = '- Name: ' . $customerName;
        }
        $customerPhoneRaw = (string) ($prefill['customer_phone'] ?? ($prefill['customer']['phone'] ?? ''));
        if ($customerPhoneRaw === '') {
            $customerPhoneRaw = $this->prefillValue($prefill['fields'] ?? [], ['phone', 'phone_delivery', 'phone_billing', 'phone_shipping', 'sg_phone']);
        }
        $customerPhone = $this->escapeMarkdown($this->formatSummaryPhone($customerPhoneRaw));
        if ($customerPhone !== '') {
            $customerSection[] = '- Telefon: ' . $customerPhone;
        }
        $customerEmail = $this->escapeMarkdown((string) ($prefill['customer_email'] ?? ($prefill['customer']['email'] ?? '')));
        if ($customerEmail !== '') {
            $customerSection[] = '- E-Mail: ' . $customerEmail;
        }
        $sections[] = implode("\n", array_filter($customerSection));

        $addressSection = ['**Adresse**'];
        $addressLine1 = $this->escapeMarkdown((string) ($prefill['address_line1'] ?? ($prefill['address']['line1'] ?? '')));
        $addressLine2 = $this->escapeMarkdown((string) ($prefill['address_line2'] ?? ($prefill['address']['line2'] ?? '')));
        $cityLine = trim(($prefill['postal_code'] ?? '') . ' ' . ($prefill['city'] ?? ''));
        $cityLine = $this->escapeMarkdown($cityLine);
        if ($addressLine1 !== '') {
            $addressSection[] = '- ' . $addressLine1;
        }
        if ($addressLine2 !== '') {
            $addressSection[] = '- ' . $addressLine2;
        }
        if ($cityLine !== '') {
            $addressSection[] = '- ' . $cityLine;
        }
        $sections[] = implode("\n", array_filter($addressSection));

        $productsSection = ['**Produkte & Leistungen**'];
        $products = $prefill['products'] ?? [];
        if (is_array($products) && $products) {
            foreach ($products as $product) {
                $qty = isset($product['qty']) ? max(0, (int) $product['qty']) : 0;
                $nameSource = (string) ($product['name'] ?? 'Produkt');
                $name = $this->escapeMarkdown($nameSource !== '' ? $nameSource : 'Produkt');
                $line = sprintf('• %1$d× %2$s', $qty, $name !== '' ? $name : 'Produkt');
                $sku = $this->escapeMarkdown((string) ($product['sku'] ?? ''));
                if ($sku !== '') {
                    $line .= sprintf(' (SKU %s)', $sku);
                }
                $productsSection[] = $line;
            }
        } elseif (!empty($prefill['items_lines']) && is_array($prefill['items_lines'])) {
            foreach ($prefill['items_lines'] as $itemLine) {
                $productsSection[] = '• ' . $this->escapeMarkdown((string) $itemLine);
            }
        }
        $productsSection[] = '• Montage: ' . max(0, (int) $montageCount);
        $productsSection[] = '• Etage: ' . max(0, (int) $etageCount);
        $sections[] = implode("\n", array_filter($productsSection));

        $durationSection = ['**' . __('Dauer', 'sg-mr') . '**'];
        $durationSection[] = sprintf('%d min', max(0, (int) $durationMinutes));
        $sections[] = implode("\n", $durationSection);

        $routingSection = ['**Routing**'];
        $regionValue = $this->escapeMarkdown((string) ($routerMeta['region'] ?? $regionLabel));
        if ($regionValue === '') {
            $regionValue = 'unbekannt';
        }
        $teamValue = $this->escapeMarkdown((string) ($routerMeta['team'] ?? $teamLabel));
        if ($teamValue === '') {
            $teamValue = 'unbekannt';
        }
        $calendarValue = isset($routerMeta['calendar_id']) ? (int) $routerMeta['calendar_id'] : 0;
        $calendarLineValue = $calendarValue > 0 ? (string) $calendarValue : 'unbekannt';
        $routingSection[] = sprintf('Region: %1$s / Team: %2$s / Calendar: %3$s', $regionValue, $teamValue, $calendarLineValue);
        $strategy = $this->escapeMarkdown((string) ($routerMeta['strategy'] ?? ''));
        $strategyValue = $strategy !== '' ? $strategy : 'unbekannt';
        $distance = isset($routerMeta['distance_minutes']) ? $routerMeta['distance_minutes'] : null;
        $distanceValue = 'unbekannt';
        if ($distance !== null) {
            $distanceValue = sprintf('%d min', max(0, (int) $distance));
        }
        $routingSection[] = sprintf('Strategie: %1$s / Distanz: %2$s', $strategyValue, $distanceValue);
        $sections[] = implode("\n", $routingSection);

        $bexioReference = $this->escapeMarkdown((string) ($prefill['bexio_reference'] ?? ''));
        if ($bexioReference !== '') {
            $sections[] = '**Bexio**' . "\n" . 'Referenz: ' . $bexioReference;
        }

        return trim(implode("\n\n", array_filter($sections)));
    }

    private function formatSummaryPhone(string $phone): string
    {
        $clean = sanitize_text_field($phone);
        if ($clean === '') {
            return '';
        }
        $clean = preg_replace('/[^0-9+\- ]+/', '', $clean);
        if (!is_string($clean)) {
            return '';
        }
        $clean = trim($clean);
        return $clean;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int, string> $keys
     */
    private function prefillValue(array $fields, array $keys): string
    {
        $candidates = [];
        if (isset($fields['legacy']) && is_array($fields['legacy'])) {
            $candidates[] = $fields['legacy'];
        }
        if (isset($fields['stable']) && is_array($fields['stable'])) {
            $candidates[] = $fields['stable'];
        }
        $candidates[] = $fields;

        foreach ($keys as $key) {
            foreach ($candidates as $pool) {
                if (isset($pool[$key]) && trim((string) $pool[$key]) !== '') {
                    return trim((string) $pool[$key]);
                }
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $prefill
     * @param array<string, mixed> $startContext
     * @return array<string, mixed>
     */
    private function buildMeta(
        WC_Order $order,
        array $prefill,
        string $region,
        string $regionLabel,
        string $teamKey,
        string $teamLabel,
        array $startContext,
        int $calendarId,
        string $serviceType,
        array $routerMeta
    ): array {
        $metaPrefill = isset($prefill['meta']) && is_array($prefill['meta']) ? $prefill['meta'] : [];
        $customer = isset($prefill['customer']) && is_array($prefill['customer']) ? $prefill['customer'] : [];
        $address = isset($prefill['address']) && is_array($prefill['address']) ? $prefill['address'] : [];
        return [
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'bexio_ref' => $metaPrefill['bexio_ref'] ?? '',
            'region' => $region,
            'region_label' => $regionLabel,
            'team' => $teamKey,
            'team_label' => $teamLabel,
            'selector_booking_id' => $startContext['booking_id'] ?? '',
            'service_m' => $prefill['counts']['m'] ?? 0,
            'service_e' => $prefill['counts']['e'] ?? 0,
            'customer_email' => $customer['email'] ?? '',
            'customer_phone' => $customer['phone'] ?? '',
            'postcode' => $address['postcode'] ?? '',
            'city' => $address['city'] ?? '',
            'items' => $prefill['items']['text'] ?? ($prefill['items'] ?? ''),
            'start_at' => isset($startContext['start_at']) && $startContext['start_at'] instanceof DateTimeInterface
                ? $startContext['start_at']->format(DateTimeInterface::ATOM)
                : '',
            'minutes_required' => ScheduleService::minutesRequired(
                (int) ($prefill['counts']['m'] ?? 0),
                (int) ($prefill['counts']['e'] ?? 0)
            ),
            'calendar_id' => $calendarId,
            'service_type' => $serviceType,
            'router_strategy' => isset($routerMeta['strategy']) ? (string) $routerMeta['strategy'] : '',
            'router_drive_minutes' => isset($routerMeta['drive_minutes']) ? (int) $routerMeta['drive_minutes'] : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function storeBookings(WC_Order $order, array $entries): void
    {
        $order->update_meta_data(self::ORDER_META_BOOKINGS, $entries);
        $order->save_meta_data();
    }

    private function shouldKeepSelector(): bool
    {
        return (bool) get_option(Plugin::OPTION_KEEP_SELECTOR_BOOKING, $this->keepSelectorDefault);
    }

    private function getStoredBookings(WC_Order $order): array
    {
        $stored = $order->get_meta(self::ORDER_META_BOOKINGS, true);
        return is_array($stored) ? $stored : [];
    }

    private function cancelStoredBookings(WC_Order $order, array $payload, string $reason): int
    {
        $stored = $this->getStoredBookings($order);
        if (!$stored) {
            return 0;
        }
        $remaining = [];
        $cancelled = 0;
        $routerLog = $this->getRouterLog($order);
        $logChanged = false;

        foreach ($stored as $entry) {
            $entryRef = $entry;
            do_action_ref_array('sg_mr_fb_cancel_slot_booking', [&$entryRef, $order->get_id(), $reason, $payload]);

            $remoteId = isset($entryRef['remote_booking_id']) ? (string) $entryRef['remote_booking_id'] : '';
            $cancelSuccess = empty($remoteId) || !empty($entryRef['cancelled']);

            if (!$cancelSuccess) {
                $errorMessage = isset($entryRef['error']) ? (string) $entryRef['error'] : __('Unbekannter Fehler beim Stornieren in FluentBooking.', 'sg-mr');
                sgmr_log('fluent_booking_cancel_failed', [
                    'order_id' => $order->get_id(),
                    'booking' => $entryRef,
                    'reason' => $reason,
                    'error' => $errorMessage,
                ]);
                $remaining[] = $entryRef;
                continue;
            }

            $cancelled++;

            $calendarId = isset($entry['calendar_id']) ? (int) $entry['calendar_id'] : 0;
            $service = isset($entry['service']) ? (string) $entry['service'] : ($entry['mode'] ?? '');
            $service = $service === 'etage' ? 'etage' : 'montage';
            $date = isset($entry['date']) ? (string) $entry['date'] : '';
            if ($calendarId > 0 && $date !== '') {
                $logKey = $this->routerLogKey($calendarId, $date, $service);
                $this->routerState->bumpCounter($calendarId, $date, $service, -1);
                if (isset($routerLog[$logKey])) {
                    unset($routerLog[$logKey]);
                    $logChanged = true;
                }
            }
        }

        if ($remaining) {
            $order->update_meta_data(self::ORDER_META_BOOKINGS, $remaining);
        } else {
            $order->delete_meta_data(self::ORDER_META_BOOKINGS);
        }
        $order->save_meta_data();

        if ($logChanged) {
            $this->storeRouterLog($order, $routerLog);
        }

        sgmr_log('composite_bookings_cancelled', [
            'order_id' => $order->get_id(),
            'reason' => $reason,
            'count' => $cancelled,
            'remaining' => count($remaining),
        ]);

        return $cancelled;
    }

    private function primaryService(int $montageCount, int $etageCount): string
    {
        if ($montageCount > 0) {
            return 'montage';
        }
        if ($etageCount > 0) {
            return 'etage';
        }
        return '';
    }

    /**
     * @param array<string, mixed> $routerMeta
     */
    private function resolveCalendarId(string $region, string $serviceType, string $teamKey, array $routerMeta): int
    {
        $calendarId = isset($routerMeta['calendar_id']) ? (int) $routerMeta['calendar_id'] : 0;
        if ($calendarId > 0) {
            return $calendarId;
        }
        if ($serviceType === '') {
            return 0;
        }
        return $this->routerState->calendarIdForTeam($region, $serviceType, $teamKey);
    }

    private function getRouterLog(WC_Order $order): array
    {
        $orderId = $order->get_id();
        if (!isset($this->routerLogCache[$orderId])) {
            $value = $order->get_meta(self::ORDER_META_ROUTER_LOG, true);
            $this->routerLogCache[$orderId] = is_array($value) ? $value : [];
        }
        return $this->routerLogCache[$orderId];
    }

    private function storeRouterLog(WC_Order $order, array $log): void
    {
        $orderId = $order->get_id();
        $previous = $this->routerLogCache[$orderId] ?? null;
        $this->routerLogCache[$orderId] = $log;
        if ($previous === $log) {
            return;
        }
        if ($log) {
            $order->update_meta_data(self::ORDER_META_ROUTER_LOG, $log);
        } else {
            $order->delete_meta_data(self::ORDER_META_ROUTER_LOG);
        }
        $order->save_meta_data();
    }

    private function routerLogKey(int $calendarId, string $date, string $service): string
    {
        return md5($calendarId . '|' . $date . '|' . $service);
    }

    private function calculateDurationFallback(int $montageCount, int $etageCount): int
    {
        return ScheduleService::minutesRequired($montageCount, $etageCount);
    }

    private function formatScheduleSummary(string $dateDisplay, string $timeRange, string $teamLabel): string
    {
        $parts = [];
        $dateDisplay = sanitize_text_field($dateDisplay);
        $timeRange = sanitize_text_field($timeRange);
        $teamLabel = sanitize_text_field($teamLabel);
        if ($dateDisplay !== '') {
            $parts[] = $dateDisplay;
        }
        if ($timeRange !== '') {
            $parts[] = $timeRange;
        }
        if ($teamLabel !== '') {
            $parts[] = $teamLabel;
        }
        return implode(' | ', $parts);
    }

    private function formatStoredEntrySummary(array $entry): string
    {
        $date = isset($entry['date']) ? (string) $entry['date'] : '';
        $displayDate = $date !== '' ? $this->formatBookingDate($date) : '';
        $slot = '';
        if (isset($entry['slot']) && is_array($entry['slot']) && isset($entry['slot'][0], $entry['slot'][1])) {
            $slot = trim((string) $entry['slot'][0] . ' – ' . $entry['slot'][1]);
        }
        $teamLabel = isset($entry['team_label']) ? (string) $entry['team_label'] : '';
        if ($teamLabel === '' && isset($entry['team'])) {
            $teamLabel = strtoupper((string) $entry['team']);
        }
        return $this->formatScheduleSummary($displayDate, $slot, $teamLabel);
    }

    private function escapeMarkdown(string $text): string
    {
        $map = [
            '\\' => '\\\\',
            '*' => '\\*',
            '_' => '\\_',
            '`' => '\\`',
            '[' => '\\[',
            ']' => '\\]',
            '(' => '\\(',
            ')' => '\\)',
            '>' => '\\>',
            '#' => '\\#',
            '+' => '\\+',
            '-' => '\\-',
            '!' => '\\!',
            '|' => '\\|',
        ];
        return strtr($text, $map);
    }

    private function eventHash(string $event, array $payload): string
    {
        $booking = isset($payload['booking']) && is_array($payload['booking']) ? $payload['booking'] : [];
        $bookingId = (string) $this->firstScalar([$booking, $payload], ['booking_id', 'id', 'uid']);
        $calendarId = (string) $this->firstScalar([$booking, $payload], ['calendar_id', 'calendar', 'calendarId']);
        $status = (string) $this->firstScalar([$booking, $payload], ['status']);
        $startAt = (string) $this->firstScalar([$booking, $payload], ['start', 'start_time', 'startDateTime', 'date']);
        $eventKey = strtolower(trim((string) $event));

        return sha1($eventKey . '|' . $bookingId . '|' . $status . '|' . $startAt . '|' . $calendarId);
    }

    private function hasProcessedEvent(WC_Order $order, string $hash): bool
    {
        $events = $this->eventLog($order);
        return isset($events[$hash]);
    }

    private function markEventProcessed(WC_Order $order, string $hash): void
    {
        $orderId = $order->get_id();
        $events = $this->eventLog($order);
        $events[$hash] = time();
        $events = $this->pruneEventLog($events);
        $this->eventLogCache[$orderId] = $events;
        $order->update_meta_data(self::ORDER_META_ROUTER_EVENTS, $events);
        $order->save_meta_data();
    }

    /**
     * @return array<string, int>
     */
    private function eventLog(WC_Order $order): array
    {
        $orderId = $order->get_id();
        if (!isset($this->eventLogCache[$orderId])) {
            $stored = $order->get_meta(self::ORDER_META_ROUTER_EVENTS, true);
            $events = [];
            $now = time();
            $cutoff = $now - self::EVENT_LOG_TTL;
            if (is_array($stored)) {
                foreach ($stored as $key => $value) {
                    if (!is_string($key) || $key === '') {
                        continue;
                    }
                    $timestamp = null;
                    if (is_int($value)) {
                        $timestamp = $value;
                    } elseif ($value) {
                        $timestamp = $now;
                    }
                    if ($timestamp === null || $timestamp < $cutoff) {
                        continue;
                    }
                    $events[$key] = $timestamp;
                }
            }
            $events = $this->pruneEventLog($events);
            $this->eventLogCache[$orderId] = $events;
        }
        return $this->eventLogCache[$orderId];
    }

    /**
     * @param array<string, int> $events
     * @return array<string, int>
     */
    private function pruneEventLog(array $events): array
    {
        $now = time();
        $cutoff = $now - self::EVENT_LOG_TTL;
        foreach ($events as $key => $timestamp) {
            if (!is_int($timestamp) || $timestamp < $cutoff) {
                unset($events[$key]);
            }
        }
        if (count($events) > 100) {
            $events = array_slice($events, -100, null, true);
        }
        return $events;
    }

    private function transitionStatus(WC_Order $order, string $status, string $note): void
    {
        $current = $order->get_status();
        if ($current === $status) {
            return;
        }
        $order->update_status($status, $note, true);
    }

    private function determineMode(int $montageCount, int $etageCount): string
    {
        if ($montageCount > 0 && $etageCount > 0) {
            return 'mixed';
        }
        if ($montageCount > 0) {
            return 'montage';
        }
        if ($etageCount > 0) {
            return 'etage';
        }
        return 'unknown';
    }

    private function formatSequence(array $sequence): array
    {
        $slots = ScheduleService::slots();
        $formatted = [];
        foreach ($sequence as $slotIndex) {
            $slot = $slots[$slotIndex] ?? null;
            if (!$slot) {
                continue;
            }
            $formatted[] = [
                'slot_index' => $slotIndex,
                'label' => $slot[0] . ' – ' . $slot[1],
            ];
        }
        return $formatted;
    }

    private function formatServiceSummary(int $montageCount, int $etageCount): string
    {
        $parts = [];
        if ($montageCount > 0) {
            $parts[] = sprintf(_n('%d Montage', '%d Montagen', $montageCount, 'sg-mr'), $montageCount);
        }
        if ($etageCount > 0) {
            $parts[] = sprintf(_n('%d Etagenlieferung', '%d Etagenlieferungen', $etageCount, 'sg-mr'), $etageCount);
        }
        if (!$parts) {
            $parts[] = __('Keine Serviceleistung hinterlegt', 'sg-mr');
        }
        return implode(' · ', $parts);
    }

    private function formatBookingDate(string $date): string
    {
        $timestamp = strtotime($date . ' 00:00:00');
        if (!$timestamp) {
            return $date;
        }
        $format = (string) get_option('date_format');
        return date_i18n($format !== '' ? $format : 'Y-m-d', $timestamp);
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string> $keys
     */
    private function firstScalar(array $sources, array $keys): string
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($source[$key]) && is_scalar($source[$key])) {
                    $value = (string) $source[$key];
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
        return '';
    }
}
