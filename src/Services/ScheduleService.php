<?php

namespace SGMR\Services;

use SGMR\Booking\FluentBookingClient;
use WC_Order;
use function absint;
use function get_option;
use function is_array;
use function max;
use function wc_get_order;

class ScheduleService
{
    private const SLOT_DEFINITIONS = [
        ['08:00', '10:00', 120],
        ['10:00', '12:30', 150],
        ['13:00', '15:00', 120],
        ['15:00', '16:30', 90],
        ['16:30', '18:00', 90],
    ];

    public static function slots(): array
    {
        return apply_filters('sg_mr_schedule_slots', self::SLOT_DEFINITIONS);
    }

    public static function bufferMinutes(): int
    {
        return 0;
    }

    public static function minutesRequired(int $m, int $e): int
    {
        $config = self::serviceDurations();
        $montageMinutes = max(0, $m) * $config['montage'];
        $etageMinutes = max(0, $e) * $config['etage'];
        $total = $montageMinutes + $etageMinutes;
        return $total > 0 ? $total : $config['montage'];
    }

    public static function slotsRequired(int $m, int $e): int
    {
        $montageSlots = max(0, $m);
        $etageSlots = (int) ceil(max(0, $e) / 2);
        $total = $montageSlots + $etageSlots;
        return $total > 0 ? $total : 1;
    }

    public static function findConsecutiveFreeSlots(
        FluentBookingClient $client,
        string $team,
        string $date,
        int $startIndex,
        int $requiredMinutes,
        int $requiredSlots,
        array $context = []
    ): array {
        $slots = self::slots();
        $sum = 0;
        $sequence = [];
        $countSlots = count($slots);
        for ($i = $startIndex; $i < $countSlots; $i++) {
            if (!self::slotAllowsRequirements($client, $team, $date, $i, $context)) {
                break;
            }
            $sequence[] = $i;
            $sum += (int) $slots[$i][2];
            if ($sum >= $requiredMinutes && count($sequence) >= $requiredSlots) {
                return $sequence;
            }
        }
        return [];
    }

    public static function findBestSequenceToday(
        FluentBookingClient $client,
        string $region,
        array $teamOrder,
        string $date,
        int $startIndex,
        int $requiredMinutes,
        int $requiredSlots,
        array $context = []
    ): array {
        if (!$teamOrder) {
            return ['team' => null, 'sequence' => []];
        }
        $primary = $teamOrder[0];
        $others = array_slice($teamOrder, 1);

        $result = self::probeTeam($client, $primary, $date, $startIndex, $requiredMinutes, $requiredSlots, $context);
        if ($result) {
            return ['team' => $primary, 'sequence' => $result];
        }

        $slotsCount = count(self::slots());
        for ($i = $startIndex + 1; $i < $slotsCount; $i++) {
            $result = self::probeTeam($client, $primary, $date, $i, $requiredMinutes, $requiredSlots, $context);
            if ($result) {
                return ['team' => $primary, 'sequence' => $result];
            }
        }

        foreach ($others as $team) {
            $result = self::probeTeam($client, $team, $date, $startIndex, $requiredMinutes, $requiredSlots, $context);
            if ($result) {
                return ['team' => $team, 'sequence' => $result];
            }
            for ($i = $startIndex + 1; $i < $slotsCount; $i++) {
                $result = self::probeTeam($client, $team, $date, $i, $requiredMinutes, $requiredSlots, $context);
                if ($result) {
                    return ['team' => $team, 'sequence' => $result];
                }
            }
        }

        return ['team' => null, 'sequence' => []];
    }

    private static function probeTeam(
        FluentBookingClient $client,
        string $team,
        string $date,
        int $startIndex,
        int $requiredMinutes,
        int $requiredSlots,
        array $context = []
    ): array {
        return self::findConsecutiveFreeSlots($client, $team, $date, $startIndex, $requiredMinutes, $requiredSlots, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function slotAllowsRequirements(
        FluentBookingClient $client,
        string $team,
        string $date,
        int $slotIndex,
        array $context
    ): bool {
        if (!$client->slotIsFree($team, $date, $slotIndex, $context)) {
            return false;
        }

        $booked = $client->slotBookings($team, $date, $slotIndex);
        $montageBooked = $booked['montage'] ?? 0;
        $etageBooked = $booked['etage'] ?? 0;

        $montageRequested = isset($context['montage']) ? (int) $context['montage'] : 0;
        $etageRequested = isset($context['etage']) ? (int) $context['etage'] : 0;

        if ($montageRequested > 0) {
            if ($montageBooked > 0) {
                return false;
            }
            if ($etageBooked > 0) {
                return false;
            }
        }

        if ($etageRequested > 0) {
            if ($montageBooked > 0) {
                return false;
            }
            if ($etageBooked + $etageRequested > 2) {
                return false;
            }
        }

        return true;
    }

    public static function calculateDurationMinutes($orderOrId): int
    {
        $order = $orderOrId instanceof WC_Order ? $orderOrId : wc_get_order(absint($orderOrId));
        if (!$order instanceof WC_Order) {
            return 0;
        }

        $counts = CartService::ensureOrderCounts($order);
        $montage = isset($counts['montage']) ? (int) $counts['montage'] : 0;
        $etage = isset($counts['etage']) ? (int) $counts['etage'] : 0;

        return self::minutesRequired($montage, $etage);
    }

    /**
     * @return array{montage:int, etage:int}
     */
    private static function serviceDurations(): array
    {
        $options = get_option('sgmr_router_settings', []);
        if (!is_array($options)) {
            $options = [];
        }
        $montage = isset($options['montage_duration_minutes']) ? (int) $options['montage_duration_minutes'] : 120;
        $etage = isset($options['etage_duration_minutes']) ? (int) $options['etage_duration_minutes'] : 60;

        $montage = self::clampDuration($montage);
        $etage = self::clampDuration($etage);

        return ['montage' => $montage, 'etage' => $etage];
    }

    private static function clampDuration(int $value): int
    {
        if ($value < 10) {
            return 10;
        }
        if ($value > 600) {
            return 600;
        }
        return $value;
    }
}
