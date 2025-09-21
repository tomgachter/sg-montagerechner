<?php

namespace SGMR\Booking;

use DateInterval;
use DateTimeImmutable;
use SGMR\Admin\Settings;
use function array_filter;
use function array_map;
use function array_values;
use function delete_option;
use function delete_transient;
use function get_option;
use function get_transient;
use function is_array;
use function sanitize_key;
use function set_transient;
use function time;
use function update_option;
use function usleep;
use function wp_next_scheduled;
use function wp_schedule_event;
use function wp_timezone;

class RouterState
{
    public const OPT_COUNTERS = 'sgmr_router_counters';
    public const OPT_RR = 'sgmr_router_rr_state';
    public const OPT_LOCK = 'sgmr_router_counters_lock';

    private const LEGACY_RR_OPTION = 'sg_fb_rr_state';
    private const LOCK_TTL = 5; // seconds
    private const LOCK_RETRIES = 10;
    private const LOCK_SLEEP_MICROSECONDS = 200000; // 0.2s

    /** @var array<string, array<string, mixed>> */
    private array $rrCache = [];

    public function __construct()
    {
        $this->migrateLegacyRR();
    }

    public function schedulePurgeJob(): void
    {
        if (!wp_next_scheduled('sgmr_purge_counters')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sgmr_purge_counters');
        }
    }

    public function getCounter(int $calendarId, string $date, string $service): int
    {
        $calendarId = max(0, $calendarId);
        if ($calendarId === 0) {
            return 0;
        }
        $dateKey = $this->normalizeDate($date);
        if ($dateKey === '') {
            return 0;
        }
        $serviceKey = $service === 'etage' ? 'etage' : 'montage';

        $counters = get_option(self::OPT_COUNTERS, []);
        if (!is_array($counters) || empty($counters[$calendarId][$dateKey][$serviceKey])) {
            return 0;
        }
        return (int) $counters[$calendarId][$dateKey][$serviceKey];
    }

    public function bumpCounter(int $calendarId, string $date, string $service, int $delta): void
    {
        $calendarId = max(0, $calendarId);
        if ($calendarId === 0 || $delta === 0) {
            return;
        }

        $dateKey = $this->normalizeDate($date);
        if ($dateKey === '') {
            return;
        }

        $serviceKey = $service === 'etage' ? 'etage' : 'montage';
        $lockKey = $this->lockKey($calendarId, $dateKey, $serviceKey);

        if (!$this->acquireLock($lockKey)) {
            return;
        }

        $counters = get_option(self::OPT_COUNTERS, []);
        if (!is_array($counters)) {
            $counters = [];
        }

        $current = isset($counters[$calendarId][$dateKey][$serviceKey])
            ? (int) $counters[$calendarId][$dateKey][$serviceKey]
            : 0;

        $newValue = $current + $delta;
        if ($newValue < 0) {
            $newValue = 0;
        }

        if ($newValue === 0) {
            unset($counters[$calendarId][$dateKey][$serviceKey]);
        } else {
            $counters[$calendarId][$dateKey][$serviceKey] = $newValue;
        }

        if (isset($counters[$calendarId][$dateKey]) && empty($counters[$calendarId][$dateKey])) {
            unset($counters[$calendarId][$dateKey]);
        }
        if (isset($counters[$calendarId]) && empty($counters[$calendarId])) {
            unset($counters[$calendarId]);
        }

        update_option(self::OPT_COUNTERS, $counters, false);
        $this->releaseLock($lockKey);
    }

    public function hasCapacity(int $calendarId, string $date, string $service): bool
    {
        $calendarId = max(0, $calendarId);
        if ($calendarId === 0) {
            return true;
        }
        $dateKey = $this->normalizeDate($date);
        if ($dateKey === '') {
            return true;
        }
        $serviceKey = $service === 'etage' ? 'etage' : 'montage';
        $limit = $serviceKey === 'etage' ? 4 : 5;
        return $this->getCounter($calendarId, $dateKey, $serviceKey) < $limit;
    }

    /**
     * @param array<int, int> $calendarIds
     */
    public function getNextRRIndex(string $region, string $service, array $calendarIds): int
    {
        $calendarIds = $this->normalizeCalendarIds($calendarIds);
        $count = count($calendarIds);
        if ($count === 0) {
            return 0;
        }

        $key = $this->stateKey($region, $service);
        $state = $this->roundRobinState();
        $entry = $state[$key] ?? null;

        if (!is_array($entry) || ($entry['calendar_ids'] ?? null) !== $calendarIds) {
            $entry = [
                'last_index' => -1,
                'calendar_ids' => $calendarIds,
            ];
            $state[$key] = $entry;
            $this->saveRoundRobinState($state);
        }

        $lastIndex = isset($entry['last_index']) ? (int) $entry['last_index'] : -1;
        $next = ($lastIndex + 1) % $count;
        if ($next < 0) {
            $next = 0;
        }

        $this->rrCache[$key] = [
            'next' => $next,
            'calendar_ids' => $calendarIds,
        ];

        return $next;
    }

    /**
     * @param array<int, int> $calendarIds
     */
    public function advanceRR(string $region, string $service, array $calendarIds): void
    {
        $calendarIds = $this->normalizeCalendarIds($calendarIds);
        $count = count($calendarIds);
        if ($count === 0) {
            return;
        }

        $key = $this->stateKey($region, $service);
        $state = $this->roundRobinState();

        $next = $this->rrCache[$key]['next'] ?? null;
        if ($next === null) {
            $next = $this->getNextRRIndex($region, $service, $calendarIds);
        }

        $state[$key] = [
            'last_index' => $next,
            'calendar_ids' => $calendarIds,
        ];
        $this->saveRoundRobinState($state);
        unset($this->rrCache[$key]);
    }

    public function purgeOldCounters(int $days = 7): void
    {
        $days = max(1, $days);
        $timezone = wp_timezone();
        $threshold = (new DateTimeImmutable('today', $timezone))->sub(new DateInterval('P' . $days . 'D'));
        $thresholdTs = $threshold->getTimestamp();

        $counters = get_option(self::OPT_COUNTERS, []);
        if (!is_array($counters)) {
            return;
        }

        $changed = false;
        foreach ($counters as $calendarId => $dates) {
            if (!is_array($dates)) {
                continue;
            }
            foreach ($dates as $date => $counts) {
                $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', (string) $date, $timezone);
                if (!$dateObj) {
                    continue;
                }
                if ($dateObj->getTimestamp() < $thresholdTs) {
                    unset($counters[$calendarId][$date]);
                    $changed = true;
                }
            }
            if (isset($counters[$calendarId]) && empty($counters[$calendarId])) {
                unset($counters[$calendarId]);
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::OPT_COUNTERS, $counters, false);
        }
    }

    /**
     * @return array<int, array<string, int>>
     */
    public function todayCounters(): array
    {
        $timezone = wp_timezone();
        $today = (new DateTimeImmutable('today', $timezone))->format('Y-m-d');
        $counters = get_option(self::OPT_COUNTERS, []);
        if (!is_array($counters)) {
            return [];
        }

        $result = [];
        foreach ($counters as $calendarId => $dates) {
            if (!is_array($dates) || empty($dates[$today]) || !is_array($dates[$today])) {
                continue;
            }
            $result[(int) $calendarId] = [
                'montage' => (int) ($dates[$today]['montage'] ?? 0),
                'etage' => (int) ($dates[$today]['etage'] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * @return array<int, array<string, int>>
     */
    public function exportTodayCounters(): array
    {
        return $this->todayCounters();
    }

    public function calendarIdForTeam(string $region, string $service, string $teamKey): int
    {
        $settings = Settings::getSettings();
        $region = sanitize_key($region);
        $service = $service === 'etage' ? 'etage' : 'montage';
        $team = strtolower(sanitize_key($teamKey));
        if (!in_array($team, ['t1', 't2', 't3'], true)) {
            $team = 't1';
        }
        $calendars = $settings['calendars'][$region][$service] ?? ['t1' => 0, 't2' => 0, 't3' => 0];
        return isset($calendars[$team]) ? (int) $calendars[$team] : 0;
    }

    /**
     * @return array<int>
     */
    public function calendarIdsForRegionService(string $region, string $service): array
    {
        $region = sanitize_key($region);
        $service = $service === 'etage' ? 'etage' : 'montage';
        $settings = Settings::getSettings();
        $row = $settings['calendars'][$region][$service] ?? ['t1' => 0, 't2' => 0, 't3' => 0];

        return $this->normalizeCalendarIds([
            $row['t1'] ?? 0,
            $row['t2'] ?? 0,
            $row['t3'] ?? 0,
        ]);
    }

    public function migrateLegacyRR(): void
    {
        if (get_option(self::OPT_RR, null) !== null) {
            return;
        }

        $legacy = get_option(self::LEGACY_RR_OPTION, null);
        if (!is_array($legacy)) {
            update_option(self::OPT_RR, [], false);
            return;
        }

        $newState = [];
        foreach ($legacy as $region => $services) {
            if (!is_array($services)) {
                continue;
            }
            foreach ($services as $service => $index) {
                $key = $this->stateKey((string) $region, (string) $service);
                $newState[$key] = [
                    'last_index' => (int) $index,
                    'calendar_ids' => [],
                ];
            }
        }

        update_option(self::OPT_RR, $newState, false);
        delete_option(self::LEGACY_RR_OPTION);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function roundRobinState(): array
    {
        $state = get_option(self::OPT_RR, []);
        return is_array($state) ? $state : [];
    }

    private function saveRoundRobinState(array $state): void
    {
        update_option(self::OPT_RR, $state, false);
    }

    /**
     * @param array<int, int> $calendarIds
     * @return array<int, int>
     */
    private function normalizeCalendarIds(array $calendarIds): array
    {
        $clean = array_filter(array_map('intval', $calendarIds));
        $clean = array_values(array_unique($clean));
        return $clean;
    }

    private function stateKey(string $region, string $service): string
    {
        $region = sanitize_key($region);
        $service = $service === 'etage' ? 'etage' : 'montage';
        return $region . '|' . $service;
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        $timezone = wp_timezone();
        $formats = ['Y-m-d', 'Y/m/d', 'd.m.Y', DateTimeImmutable::ATOM];
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $date, $timezone);
            if ($dt instanceof DateTimeImmutable) {
                return $dt->setTimezone($timezone)->format('Y-m-d');
            }
        }

        try {
            $dt = new DateTimeImmutable($date, $timezone);
            return $dt->format('Y-m-d');
        } catch (\Exception $exception) {
            return '';
        }
    }

    private function lockKey(int $calendarId, string $date, string $service): string
    {
        return self::OPT_LOCK . '_' . md5($calendarId . '|' . $date . '|' . $service);
    }

    private function acquireLock(string $key): bool
    {
        for ($attempt = 0; $attempt < self::LOCK_RETRIES; $attempt++) {
            if (false === get_transient($key)) {
                if (set_transient($key, 1, self::LOCK_TTL)) {
                    return true;
                }
            }
            usleep(self::LOCK_SLEEP_MICROSECONDS);
        }
        return false;
    }

private function releaseLock(string $key): void
    {
        delete_transient($key);
    }
}

class_alias(__NAMESPACE__ . '\\RouterState', 'Sanigroup\\Montagerechner\\Booking\\RouterState');
