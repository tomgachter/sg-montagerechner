<?php

namespace SGMR\Order;

use SGMR\Booking\FluentBookingClient;
use SGMR\Services\CartService;
use SGMR\Services\ScheduleService;
use SGMR\Utils\Logger;
use WC_Order;

class CompositeBookingController
{
    private FluentBookingClient $client;

    public function __construct(FluentBookingClient $client)
    {
        $this->client = $client;
    }

    public function boot(): void
    {
        add_action('wp_ajax_sgmr_create_composite_booking', [$this, 'handle']);
        add_action('wp_ajax_nopriv_sgmr_create_composite_booking', [$this, 'handle']);
    }

    public function handle(): void
    {
        check_ajax_referer('sg_mr_booking_auto', 'nonce');

        $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $region = isset($_POST['region']) ? sanitize_key($_POST['region']) : '';
        $team = isset($_POST['team']) ? sanitize_key($_POST['team']) : '';
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        $startSlot = isset($_POST['start_slot']) ? (int) $_POST['start_slot'] : 0;
        $montage = isset($_POST['m']) ? (int) $_POST['m'] : 0;
        $etage = isset($_POST['e']) ? (int) $_POST['e'] : 0;

        if (!$orderId || !$region || !$date) {
            wp_send_json_error(['code' => 'invalid_request', 'message' => __('Ungültige Anfrage.', 'sg-mr')]);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(['code' => 'invalid_date', 'message' => __('Datum ungültig.', 'sg-mr')]);
        }
        if ($montage < 0 || $etage < 0) {
            wp_send_json_error(['code' => 'invalid_counts', 'message' => __('Ungültige Service-Anzahl.', 'sg-mr')]);
        }
        if ($montage >= 4) {
            wp_send_json_error(['code' => 'threshold', 'message' => __('Dieser Auftrag muss telefonisch geplant werden.', 'sg-mr')]);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['code' => 'order_not_found', 'message' => __('Bestellung nicht gefunden.', 'sg-mr')]);
        }

        $teams = $this->client->regionTeams($region);
        if (!$teams) {
            wp_send_json_error(['code' => 'unknown_region', 'message' => __('Keine Teams für diese Region hinterlegt.', 'sg-mr')]);
        }
        if (!$team) {
            $team = $this->client->pickPrimaryTeam($region) ?: $teams[0];
        }
        if (!in_array($team, $teams, true)) {
            wp_send_json_error(['code' => 'team_invalid', 'message' => __('Ungültiges Team für diese Region.', 'sg-mr')]);
        }

        $requiredMinutes = ScheduleService::minutesRequired($montage, $etage);
        $requiredSlots = ScheduleService::slotsRequired($montage, $etage);
        $slots = ScheduleService::slots();
        if ($startSlot < 0 || $startSlot >= count($slots)) {
            wp_send_json_error(['code' => 'invalid_slot', 'message' => __('Startfenster ungültig.', 'sg-mr')]);
        }

        $slotContext = ['montage' => $montage, 'etage' => $etage];
        $sequence = ScheduleService::findConsecutiveFreeSlots(
            $this->client,
            $team,
            $date,
            $startSlot,
            $requiredMinutes,
            $requiredSlots,
            $slotContext
        );
        if (!$sequence) {
            $teamOrder = array_values(array_unique(array_merge([$team], array_diff($teams, [$team]))));
            $resolved = ScheduleService::findBestSequenceToday(
                $this->client,
                $region,
                $teamOrder,
                $date,
                $startSlot,
                $requiredMinutes,
                $requiredSlots,
                $slotContext
            );
            $team = $resolved['team'];
            $sequence = $resolved['sequence'];
            if (!$team || !$sequence) {
                wp_send_json_error(['code' => 'no_sequence_today_in_region', 'message' => __('Für das gewählte Datum sind keine passenden Zeitfenster verfügbar. Bitte wählen Sie ein anderes Datum.', 'sg-mr')]);
            }
        }

        $groupId = 'order_' . $orderId;
        $bookings = [];
        foreach ($sequence as $index => $slotIndex) {
            $slotDef = $slots[$slotIndex] ?? null;
            if (!$slotDef) {
                continue;
            }
            $payload = [
                'team' => $team,
                'date' => $date,
                'slot_index' => $slotIndex,
                'slot' => $slotDef,
                'order_id' => $orderId,
                'group_id' => $groupId,
                'sequence_index' => $index,
                'montage_total' => $montage,
                'etage_total' => $etage,
                'slot_count' => count($sequence),
            ];
            $this->client->createSlotBooking($payload);
            $bookings[] = $payload;
        }

        $this->client->recordTeamSelection($region, $team);

        $teamConfig = $this->client->team($team);
        $response = [
            'team' => $team,
            'team_label' => $teamConfig['label'] ?? strtoupper($team),
            'region' => $region,
            'date' => $date,
            'sequence' => array_map(function ($slotIndex) use ($slots) {
                $slot = $slots[$slotIndex];
                return [
                    'slot_index' => $slotIndex,
                    'label' => $slot[0] . ' – ' . $slot[1],
                ];
            }, $sequence),
        ];

        Logger::log('Composite booking created', ['order' => $orderId, 'team' => $team, 'date' => $date, 'sequence' => $sequence]);
        wp_send_json_success($response);
    }

}
