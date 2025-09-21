<?php

namespace SGMR\Admin;

use SGMR\Order\Triggers;
use SGMR\Plugin;
use SGMR\Services\CartService;
use WC_Order;
use WP_Post;

class BookingGate
{
    private const NONCE_ACTION = 'sgmr_booking_gate';
    private const NONCE_FIELD = '_sgmr_booking_gate_nonce';

    public function boot(): void
    {
        if (!is_admin()) {
            return;
        }
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('save_post_shop_order', [$this, 'handleSave'], 20, 3);
    }

    public function registerMetaBox(): void
    {
        add_meta_box(
            'sgmr-booking-gate',
            __('Buchungsfreigabe', 'sg-mr'),
            [$this, 'renderMetaBox'],
            'shop_order',
            'side',
            'high'
        );
    }

    public function renderMetaBox(WP_Post $post): void
    {
        $order = wc_get_order($post->ID);
        if (!$order instanceof WC_Order) {
            echo '<p>' . esc_html__('Bestellung nicht gefunden.', 'sg-mr') . '</p>';
            return;
        }
        if (!CartService::orderHasService($order)) {
            echo '<p>' . esc_html__('Keine Serviceleistung hinterlegt.', 'sg-mr') . '</p>';
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $status = sanitize_key($order->get_status());
        $gateOpen = $status === \SGMR_STATUS_ARRIVED;
        $gateLabel = $gateOpen
            ? __('Buchung freigegeben – Ware eingetroffen', 'sg-mr')
            : __('Warte auf Ware', 'sg-mr');

        $triggers = Plugin::instance()->orderTriggers();
        $log = $triggers->emailLog($order, Triggers::EMAIL_SLUG_ARRIVED);
        $lastSentText = __('Noch kein Versand', 'sg-mr');
        if (!empty($log['ts'])) {
            $timestamp = (int) $log['ts'];
            $formatted = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            $hash = isset($log['hash']) ? trim((string) $log['hash']) : '';
            $lastSentText = $hash
                ? sprintf('%s · %s', $formatted, $hash)
                : $formatted;
        }

        $resendPending = $this->resendPending($order);

        echo '<p><strong>' . esc_html__('Gate-Status', 'sg-mr') . '</strong><br>' . esc_html($gateLabel) . '</p>';
        echo '<p><strong>' . esc_html__('Letzter Versand', 'sg-mr') . '</strong><br>' . esc_html($lastSentText) . '</p>';
        if ($resendPending) {
            echo '<p class="description">' . esc_html__('Neuer Versand angefordert – erfolgt automatisch bei Freigabe.', 'sg-mr') . '</p>';
        }
        echo '<label><input type="checkbox" name="sgmr_booking_gate_override" value="yes"> ' . esc_html__('Buchungslink jetzt erneut senden (Guard überschreiben)', 'sg-mr') . '</label>';
    }

    public function handleSave(int $postId, WP_Post $post, bool $update): void
    {
        if (!$update || !is_admin()) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        $nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_shop_order', $postId)) {
            return;
        }
        $order = wc_get_order($postId);
        if (!$order instanceof WC_Order) {
            return;
        }
        if (!CartService::orderHasService($order)) {
            return;
        }

        $overrideRequested = isset($_POST['sgmr_booking_gate_override'])
            && sanitize_text_field(wp_unslash((string) $_POST['sgmr_booking_gate_override'])) === 'yes';
        if (!$overrideRequested) {
            return;
        }

        $triggers = Plugin::instance()->orderTriggers();
        $triggers->requestResend($order, Triggers::EMAIL_SLUG_ARRIVED);
        $result = $triggers->manualSendArrived($order, [
            'force' => true,
            'origin' => 'admin_override',
        ]);

        $message = '';
        $type = 'info';
        if (!empty($result['email_sent'])) {
            $type = 'success';
            $message = sprintf(
                /* translators: %s: order number */
                __('Buchungslink erneut versendet (Bestellung %s).', 'sg-mr'),
                $order->get_order_number()
            );
        } else {
            $reason = isset($result['reason']) ? (string) $result['reason'] : 'unknown';
            if ($reason === 'status_not_arrived') {
                $message = __('Ware noch nicht als eingetroffen markiert – Versand folgt nach Freigabe.', 'sg-mr');
            } elseif ($reason === 'already_sent') {
                $message = __('Versand bereits ausgeführt. Setze Status erneut auf „Ware eingetroffen“, um neu zu senden.', 'sg-mr');
            } elseif ($reason === 'no_link') {
                $message = __('Kein Buchungslink verfügbar. Bitte Region & Counts prüfen.', 'sg-mr');
                $type = 'warning';
            } else {
                $message = __('Versand konnte nicht ausgelöst werden.', 'sg-mr');
                $type = 'warning';
            }
        }

        if ($message !== '') {
            $this->adminNotice($message, $type);
        }
    }

    private function resendPending(WC_Order $order): bool
    {
        $value = get_post_meta(
            $order->get_id(),
            Triggers::EMAIL_RESEND_PREFIX . Triggers::EMAIL_SLUG_ARRIVED,
            true
        );
        return in_array($value, ['pending', '1', 'yes', 'true'], true);
    }

    private function adminNotice(string $message, string $type): void
    {
        $class = in_array($type, ['success', 'warning', 'error', 'info'], true) ? $type : 'info';
        add_action('admin_notices', static function () use ($message, $class) {
            printf('<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        });
    }
}
