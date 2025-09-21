<?php

namespace SGMR\Booking;

use DateInterval;
use DateTimeImmutable;
use SGMR\Admin\Settings;
use SGMR\Routing\DistanceProvider;
use SGMR\Region\RegionDayPlanner;
use SGMR\Services\CartService;
use WC_Order;
use function __;
use function apply_filters;
use function preg_replace;
use function sanitize_key;
use function strtoupper;
use function wp_timezone;

class HybridRouter
{
    /** @var array<string, array<string, string>> */
    private array $priorityCache = [];
    private RegionDayPlanner $dayPlanner;
    private RouterState $state;
    private DistanceProvider $distanceProvider;

    public function __construct(RegionDayPlanner $dayPlanner, RouterState $state, DistanceProvider $distanceProvider)
    {
        $this->dayPlanner = $dayPlanner;
        $this->state = $state;
        $this->distanceProvider = $distanceProvider;
    }

    public function distanceMinutesForOrder(WC_Order $order): int
    {
        $postcode = (string) $order->get_meta(CartService::META_REGION_POSTCODE, true);
        if ($postcode === '') {
            $postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        }
        $postcode = preg_replace('/\D+/', '', $postcode ?? '');
        if ($postcode === '') {
            return (int) apply_filters('sgmr_router_distance_default', 999, $postcode, $order);
        }

        $minutes = $this->distanceProvider->getMinutes($postcode);
        if ($minutes === null) {
            $minutes = 999;
        }

        return (int) apply_filters('sgmr_router_distance_minutes', $minutes, $postcode, $order);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function select(WC_Order $order, string $region, string $service, int $driveMinutes, array $context = []): ?array
    {
        $region = sanitize_key($region);
        $service = $service === 'etage' ? 'etage' : 'montage';

        $teams = $this->teamsFor($region, $service);
        if (!$teams) {
            return null;
        }

        $config = Settings::getSettings();
        $horizon = max(1, (int) ($config['horizon_days'] ?? 14));
        $threshold = max(0, (int) ($config['rr_threshold_minutes'] ?? 20));
        $strategy = $driveMinutes <= $threshold ? 'round_robin' : 'priority';

        $selection = $strategy === 'round_robin'
            ? $this->selectRoundRobin($teams, $region, $service, $horizon)
            : $this->selectPriority($teams, $region, $service, $horizon);

        if (!$selection) {
            return null;
        }

        $selection['strategy'] = $strategy;
        $selection['drive_minutes'] = $driveMinutes;
        $selection['service'] = $service;
        $selection['region'] = $region;
        $selection['context'] = $context;

        return $selection;
    }

    /**
     * @param array<int, array<string, mixed>> $teams
     * @return array<string, mixed>|null
     */
    private function selectRoundRobin(array $teams, string $region, string $service, int $horizon): ?array
    {
        $count = count($teams);
        if ($count === 0) {
            return null;
        }
        $calendarIds = array_map(static fn ($team) => (int) $team['calendar_id'], $teams);
        $startIndex = $this->state->getNextRRIndex($region, $service, $calendarIds);

        for ($offset = 0; $offset < $count; $offset++) {
            $index = ($startIndex + $offset) % $count;
            $team = $teams[$index];
            if ($this->teamHasCapacity((int) $team['calendar_id'], $service, $region, $horizon)) {
                $this->state->advanceRR($region, $service, $calendarIds);
                return array_merge($team, [
                    'selection_index' => $index,
                ]);
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $teams
     * @return array<string, mixed>|null
     */
    private function selectPriority(array $teams, string $region, string $service, int $horizon): ?array
    {
        $priority = $this->priority($region, $service);
        $mapped = [];
        foreach ($teams as $index => $team) {
            $mapped[$team['team_key']] = ['data' => $team, 'index' => $index];
        }

        foreach ($priority as $teamKey) {
            if (!isset($mapped[$teamKey])) {
                continue;
            }
            $team = $mapped[$teamKey];
            if ($this->teamHasCapacity((int) $team['data']['calendar_id'], $service, $region, $horizon)) {
                return array_merge($team['data'], [
                    'selection_index' => $team['index'],
                ]);
            }
        }

        // Fallback: first available team in list order.
        foreach ($teams as $index => $team) {
            if ($this->teamHasCapacity((int) $team['calendar_id'], $service, $region, $horizon)) {
                return array_merge($team, [
                    'selection_index' => $index,
                ]);
            }
        }

        return null;
    }

    private function teamHasCapacity(int $calendarId, string $service, string $region, int $horizonDays): bool
    {
        if ($calendarId <= 0) {
            return false;
        }
        $timezone = wp_timezone();
        $today = new DateTimeImmutable('today', $timezone);
        for ($i = 0; $i < $horizonDays; $i++) {
            $candidate = $today->add(new DateInterval('P' . $i . 'D'));
            $dow = (int) $candidate->format('N');
            if (!$this->dayPlanner->isDowAllowed($region, $dow)) {
                continue;
            }
            $dateKey = $candidate->format('Y-m-d');
            if ($this->state->hasCapacity($calendarId, $dateKey, $service)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamsFor(string $region, string $service): array
    {
        $config = Settings::getSettings();
        $calendarConfig = $config['calendars'][$region][$service] ?? ['t1' => 0, 't2' => 0, 't3' => 0];
        $teams = [];
        $order = ['t1' => 'T1', 't2' => 'T2', 't3' => 'T3'];
        foreach ($order as $key => $teamKey) {
            $calendarId = isset($calendarConfig[$key]) ? (int) $calendarConfig[$key] : 0;
            if ($calendarId <= 0) {
                continue;
            }
            $teams[] = [
                'team_key' => $teamKey,
                'team_label' => $this->teamLabel($teamKey),
                'calendar_id' => $calendarId,
                'shortcode' => $this->calendarShortcode($calendarId),
            ];
        }
        return $teams;
    }

    /**
     * @return array<int, string>
     */
    private function priority(string $region, string $service): array
    {
        $region = sanitize_key($region);
        $service = $service === 'etage' ? 'etage' : 'montage';
        $cacheKey = $region . ':' . $service;
        if (isset($this->priorityCache[$cacheKey])) {
            return $this->priorityCache[$cacheKey];
        }

        $config = Settings::getSettings();
        $list = [];
        if (isset($config['priorities'][$region][$service]) && is_array($config['priorities'][$region][$service])) {
            foreach ($config['priorities'][$region][$service] as $teamKey) {
                $teamKey = strtoupper(trim((string) $teamKey));
                if ($teamKey !== '') {
                    $list[] = $teamKey;
                }
            }
        }
        if (!$list) {
            $list = ['T1', 'T2', 'T3'];
        } else {
            $list = array_values(array_unique($list));
        }
        $this->priorityCache[$cacheKey] = $list;
        return $list;
    }

    private function teamLabel(string $teamKey): string
    {
        $teamKey = strtoupper($teamKey);
        return apply_filters('sgmr_router_team_label', sprintf(__('Team %s', 'sg-mr'), $teamKey), $teamKey);
    }

    private function calendarShortcode(int $calendarId): string
    {
        return sprintf('[fluent_booking id="%d"]', $calendarId);
    }
}
