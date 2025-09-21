<?php

namespace SGMR\Order;

use SGMR\Booking\FluentBookingClient;
use SGMR\Plugin;
use SGMR\Services\CartService;
use SGMR\Services\Environment;
use SGMR\Services\BookingLink;
use WC_Order;

class Triggers
{
    private const REMINDER_HOOK = 'sg_mr_payment_reminder';
    private const EMAIL_META_PREFIX = '_sg_email_sent_';
    private const META_BOOKING_INVITE = '_sg_email_sent_booking_invite';
    public const EMAIL_RESEND_PREFIX = '_sg_email_resend_';
    public const EMAIL_SLUG_INSTANT = 'instant';
    public const EMAIL_SLUG_ARRIVED = 'arrived';
    public const EMAIL_SLUG_OFFLINE = 'offline';
    public const EMAIL_SLUG_PAID_WAIT = 'paid_wait';

    private FluentBookingClient $bookingClient;
    /** @var array<string,string> */
    private array $legacyMailMeta = [
        self::EMAIL_SLUG_INSTANT => '_sgmr_mail_instant_sent',
        self::EMAIL_SLUG_ARRIVED => '_sgmr_mail_arrived_sent',
        self::EMAIL_SLUG_OFFLINE => '_sgmr_mail_offline_sent',
        self::EMAIL_SLUG_PAID_WAIT => '_sgmr_mail_paid_wait_sent',
    ];

    public function __construct(FluentBookingClient $bookingClient)
    {
        $this->bookingClient = $bookingClient;
    }

    public function boot(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 50, 4);
        add_action('woocommerce_payment_complete', [$this, 'onPaymentComplete']);
        add_action(self::REMINDER_HOOK, [$this, 'sendReminder']);

        add_action('sgmr_email_send_instant', [$this, 'dispatchInstant'], 10, 3);
        add_action('sgmr_email_send_arrived', [$this, 'dispatchArrived'], 10, 3);
        add_action('sgmr_email_send_planning_offline', [$this, 'dispatchOffline'], 10, 2);
        add_action('sgmr_email_send_paid_wait', [$this, 'dispatchPaidWait'], 10, 2);

        add_action('sgmr_booking_created', [$this, 'onBookingCreated'], 10, 2);
        add_action('sgmr_booking_rescheduled', [$this, 'onBookingRescheduled'], 10, 2);
        add_action('sgmr_booking_cancelled', [$this, 'onBookingCancelled'], 10, 2);
        add_action('sgmr_booking_completed', [$this, 'onBookingCompleted'], 10, 2);
    }

    public function onStatusChanged($orderId, $oldStatus, $newStatus, $order = null): void
    {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($orderId);
        }
        if (!$order instanceof WC_Order) {
            return;
        }
        if (!CartService::orderHasService($order)) {
            return;
        }

        $scenario = $this->orderScenario($order);

        $normalized = preg_replace('/^wc-/', '', sanitize_key($newStatus));
        $fromNormalized = preg_replace('/^wc-/', '', sanitize_key($oldStatus));

        if ($normalized === 'sg-awaiting-payment') {
            $this->scheduleReminder($order);
        } elseif ($fromNormalized === 'sg-awaiting-payment') {
            $this->clearReminder($order);
        }

        $baseContext = $this->baseContextFor($order, $fromNormalized, $normalized, $scenario);

        $this->logStatusChange($order, $baseContext);

        $paidStates = [\SGMR_STATUS_PAID, 'processing', 'completed'];
        if (in_array($normalized, $paidStates, true)) {
            $paid = $this->handlePaidStage($order, $fromNormalized, $normalized, $scenario);
            $this->logTrigger($order, $baseContext, $paid);
        }

        if ($normalized === \SGMR_STATUS_ARRIVED) {
            $arrived = $this->handleArrivedStage($order, $fromNormalized, $scenario);
            $this->logTrigger($order, $baseContext, $arrived);
        }

        if ($normalized === \SGMR_STATUS_DONE) {
            $done = $this->handleServiceDoneStage($order, $fromNormalized, $scenario);
            $this->logTrigger($order, $baseContext, $done);
        }

        $order->update_meta_data('_sgmr_last_status', $normalized);
        $order->save_meta_data();
    }

    public function onPaymentComplete(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }
        $status = preg_replace('/^wc-/', '', sanitize_key($order->get_status()));
        $paidStates = [\SGMR_STATUS_PAID, 'processing', 'completed'];
        if (in_array($status, $paidStates, true)) {
            $from = $order->get_meta('_sgmr_last_status', true) ?: $status;
            $this->onStatusChanged($orderId, $from, $status, $order);
        }
    }

    private function scheduleReminder(WC_Order $order): void
    {
        $days = 5;
        $timestamp = $this->businessDayTimestamp($days);
        wp_clear_scheduled_hook(self::REMINDER_HOOK, [$order->get_id()]);
        wp_schedule_single_event($timestamp, self::REMINDER_HOOK, [$order->get_id()]);
        $order->update_meta_data('_sg_reminder_ts', $timestamp);
        $order->save();
    }

    private function clearReminder(WC_Order $order): void
    {
        $timestamp = (int) $order->get_meta('_sg_reminder_ts');
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::REMINDER_HOOK, [$order->get_id()]);
            $order->delete_meta_data('_sg_reminder_ts');
            $order->save();
        }
    }

    public function sendReminder(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }
        if ($order->get_status() !== 'sg-awaiting-payment') {
            return;
        }
        $templates = Environment::option(Plugin::OPTION_EMAIL_TEMPLATES, []);
        if (empty($templates['awaiting_payment'])) {
            return;
        }
        $template = $templates['awaiting_payment'];
        $subject = $this->replacePlaceholders($template['subject'] ?? '', $order, []);
        $body = $this->replacePlaceholders($template['html'] ?? '', $order, []);
        wp_mail($order->get_billing_email(), $subject, $this->wrapHtml($body));
        $order->add_order_note(__('Vorauskasse-Reminder versendet.', 'sg-mr'));
        $order->delete_meta_data('_sg_reminder_ts');
        $order->save();
    }

    private function businessDayTimestamp(int $days): int
    {
        $days = max(0, $days);
        $timestamp = current_time('timestamp');
        while ($days > 0) {
            $timestamp += DAY_IN_SECONDS;
            $weekday = (int) wp_date('N', $timestamp);
            if ($weekday >= 1 && $weekday <= 5) {
                $days--;
            }
        }
        return $timestamp;
    }

    private function windowNote(): string
    {
        return __('Bitte stellen Sie sicher, dass Sie innerhalb des gewählten 2-Stunden-Fensters anwesend sind.', 'sg-mr');
    }

    private function replacePlaceholders(string $template, WC_Order $order, array $context = []): string
    {
        $link = $context['link_url'] ?? '';
        $replacements = [
            '{{order_number}}' => $order->get_order_number(),
            '{{customer_first_name}}' => $order->get_billing_first_name(),
            '{{customer_last_name}}' => $order->get_billing_last_name(),
            '{{customer_name}}' => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
            '{{link_url}}' => $link,
            '{{booking_url}}' => $link,
            '{{booking_link}}' => $link,
            '{{customer_phone}}' => $order->get_billing_phone(),
            '{{region_label}}' => $order->get_meta(CartService::META_REGION_LABEL),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function wrapHtml(string $body): string
    {
        $mailer = WC()->mailer();
        return $mailer->wrap_message(__('Information von Sanigroup', 'sg-mr'), $body);
    }

    private function logTrigger(WC_Order $order, array $baseContext, array $result): void
    {
        if (!function_exists('sgmr_log')) {
            return;
        }
        $event = isset($result['event']) ? 'trigger_' . sanitize_key($result['event']) : 'trigger';
        $payload = array_merge([
            'order_id' => $order->get_id(),
        ], $baseContext, $result);
        if (isset($payload['reason']) && $payload['reason'] === '') {
            unset($payload['reason']);
        }
        if (!empty($payload['link']) && !empty($payload['email_sent']) && function_exists('sgmr_mask_link')) {
            $payload['link_masked'] = sgmr_mask_link($payload['link']);
        }
        unset($payload['link']);
        \sgmr_log($event, $payload);
    }

    private function logStatusChange(WC_Order $order, array $context): void
    {
        if (!function_exists('sgmr_log')) {
            return;
        }
        \sgmr_log('status_change', array_merge([
            'order_id' => $order->get_id(),
        ], $context));
    }

    private function baseContextFor(WC_Order $order, string $from, string $to, array $scenario): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'terminart' => $scenario['mode'] ?: 'unknown',
            'wirklich_an_lager' => $scenario['in_stock'] ? 'yes' : 'no',
            'email_template' => 'none',
            'email_sent' => false,
        ];
    }

    private function orderScenario(WC_Order $order): array
    {
        $mode = (string) $order->get_meta(CartService::META_TERMIN_MODE);
        $mode = $mode === 'telefonisch' ? 'telefonisch' : 'online';
        $region = (string) $order->get_meta(CartService::META_REGION_KEY);
        $inStock = CartService::orderHasInstantStock($order);
        if (function_exists('sgmr_order_is_really_in_stock')) {
            $inStock = (bool) sgmr_order_is_really_in_stock($order);
        }
        return [
            'mode' => $mode,
            'region' => $region,
            'in_stock' => $inStock,
            'payment_method' => (string) $order->get_payment_method(),
        ];
    }

    private function mailMetaKey(string $slug): string
    {
        return self::EMAIL_META_PREFIX . sanitize_key($slug);
    }

    private function wasMailSent(WC_Order $order, string $slug): bool
    {
        $record = $this->emailMeta($order, $slug);
        return isset($record['ts']) && (int) $record['ts'] > 0;
    }

    private function markMailSent(WC_Order $order, string $slug, array $record = []): void
    {
        $fingerprint = isset($record['fingerprint']) && is_array($record['fingerprint'])
            ? $record['fingerprint']
            : ['slug' => sanitize_key($slug)];
        $sanitized = $this->sanitizeRecord(array_merge(
            [
                'ts' => $this->now(),
                'hash' => $this->hashFingerprint($fingerprint),
                'fingerprint' => $fingerprint,
                'resend' => !empty($record['resend']),
                'trigger' => isset($record['trigger']) ? (string) $record['trigger'] : 'system',
            ],
            array_diff_key($record, ['fingerprint' => true, 'resend' => true, 'trigger' => true])
        ));

        update_post_meta($order->get_id(), $this->mailMetaKey($slug), $sanitized);

        $legacyKey = $this->legacyMailMetaKey($slug);
        if ($legacyKey) {
            delete_post_meta($order->get_id(), $legacyKey);
        }
    }

    private function bookingInviteMeta(WC_Order $order): array
    {
        $value = get_post_meta($order->get_id(), self::META_BOOKING_INVITE, true);
        if (is_array($value)) {
            return $value;
        }
        if ($value === '' || $value === null) {
            return [];
        }
        return [
            'hash' => (string) $value,
            'ts' => $this->now(),
        ];
    }

    private function updateBookingInviteMeta(WC_Order $order, string $hash): void
    {
        $record = [
            'hash' => $hash,
            'ts' => $this->now(),
        ];
        update_post_meta($order->get_id(), self::META_BOOKING_INVITE, $record);
    }

    private function currentStatus(WC_Order $order): string
    {
        return preg_replace('/^wc-/', '', sanitize_key($order->get_status()));
    }

    private function emailMeta(WC_Order $order, string $slug): array
    {
        $value = get_post_meta($order->get_id(), $this->mailMetaKey($slug), true);
        if (is_array($value)) {
            return $value;
        }

        if ($value !== '' && $value !== null) {
            $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
            return [
                'ts' => $timestamp ?: $this->now(),
                'hash' => $this->hashFingerprint(['legacy' => (string) $value]),
                'legacy' => true,
            ];
        }

        $legacyKey = $this->legacyMailMetaKey($slug);
        if ($legacyKey) {
            $legacyValue = get_post_meta($order->get_id(), $legacyKey, true);
            if ($legacyValue) {
                $timestamp = is_numeric($legacyValue) ? (int) $legacyValue : strtotime((string) $legacyValue);
                return [
                    'ts' => $timestamp ?: $this->now(),
                    'hash' => $this->hashFingerprint(['legacy' => (string) $legacyValue]),
                    'legacy' => true,
                ];
            }
        }

        return [];
    }

    private function legacyMailMetaKey(string $slug): ?string
    {
        return $this->legacyMailMeta[$slug] ?? null;
    }

    private function hashFingerprint(array $fingerprint): string
    {
        return hash('sha256', (string) wp_json_encode($fingerprint));
    }

    private function sanitizeRecord(array $record): array
    {
        foreach ($record as $key => $value) {
            if (is_array($value)) {
                $record[$key] = $this->sanitizeRecord($value);
            } elseif (is_string($value)) {
                $record[$key] = sanitize_text_field($value);
            }
        }
        return $record;
    }

    private function now(): int
    {
        return (int) current_time('timestamp');
    }

    private function logEmailSend(WC_Order $order, string $slug, array $record, array $previous = [], string $trigger = ''): void
    {
        if (!function_exists('sgmr_append_diag_log')) {
            return;
        }
        $sentTs = isset($record['ts']) ? (int) $record['ts'] : $this->now();
        $firstTs = isset($previous['ts']) ? (int) $previous['ts'] : $sentTs;
        $entry = [
            'order_id' => $order->get_id(),
            'template_key' => sanitize_key($slug),
            'sent_ts' => $sentTs,
            'first_send_ts' => $firstTs,
            'resend' => !empty($record['resend']) ? 'yes' : 'no',
            'trigger' => $record['trigger'] ?? $trigger,
        ];
        sgmr_append_diag_log('email', $entry);
    }

    private function resendMetaKey(string $slug): string
    {
        return self::EMAIL_RESEND_PREFIX . sanitize_key($slug);
    }

    private function isResendRequested(WC_Order $order, string $slug): bool
    {
        $value = get_post_meta($order->get_id(), $this->resendMetaKey($slug), true);
        return in_array($value, ['pending', '1', 'yes', 'true'], true);
    }

    public function requestResend(WC_Order $order, string $slug): void
    {
        update_post_meta($order->get_id(), $this->resendMetaKey($slug), 'pending');
    }

    public function clearResendRequest(WC_Order $order, string $slug): void
    {
        delete_post_meta($order->get_id(), $this->resendMetaKey($slug));
    }

    private function autoTransition(WC_Order $order, string $from, string $target, string $note, string $context = ''): bool
    {
        $fromSlug = preg_replace('/^wc-/', '', $from);
        $targetSlug = preg_replace('/^wc-/', '', $target);
        if ($fromSlug === $targetSlug) {
            \sgmr_log('status_transition', [
                'order_id' => $order->get_id(),
                'from' => $fromSlug,
                'to' => $targetSlug,
                'context' => $context,
                'result' => 'blocked',
                'reason' => 'same_status',
                'guard' => 'skipped',
            ]);
            return false;
        }

        $guardState = 'missing';
        $canTransition = true;

        if (function_exists('sgmr_can_transition')) {
            $guardState = 'allowed';
            if (!sgmr_can_transition($order, $fromSlug, $targetSlug)) {
                if ($context === 'status_arrived') {
                    $guardState = 'bypassed';
                } else {
                    $guardState = 'blocked';
                    $canTransition = false;
                }
            }
        }

        if (!$canTransition) {
            \sgmr_log('status_transition', [
                'order_id' => $order->get_id(),
                'from' => $fromSlug,
                'to' => $targetSlug,
                'context' => $context,
                'result' => 'blocked',
                'reason' => 'guard_blocked',
                'guard' => $guardState,
            ]);
            return false;
        }

        $order->update_status('wc-' . $targetSlug, $note);
        $logContext = [
            'order_id' => $order->get_id(),
            'from' => $fromSlug,
            'to' => $targetSlug,
            'context' => $context,
            'result' => 'done',
            'reason' => $context !== '' ? $context : 'auto',
            'guard' => $guardState,
        ];
        if ($note !== '') {
            $logContext['note'] = $note;
        }
        \sgmr_log('status_transition', $logContext);
        return true;
    }

    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            $key = is_string($key) ? sanitize_key($key) : (string) $key;
            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = is_string($value) ? sanitize_text_field((string) $value) : $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
            }
        }
        return $sanitized;
    }

    private function handlePaidStage(WC_Order $order, string $fromStatus, string $currentStatus, array $scenario): array
    {
        $mode = $scenario['mode'];
        $inStock = $scenario['in_stock'];
        if (method_exists(CartService::class, 'ensureOrderCounts')) {
            CartService::ensureOrderCounts($order);
        }
        $result = [
            'event' => 'paid_stage',
            'email_template' => 'none',
            'email_sent' => false,
            'reason' => '',
            'wirklich_an_lager' => $inStock ? 'yes' : 'no',
        ];

        if ($mode === 'online') {
            if ($inStock) {
                $result['reason'] = 'waiting_arrival';
            } else {
                $result['email_template'] = self::EMAIL_SLUG_PAID_WAIT;
                if (!$this->wasMailSent($order, self::EMAIL_SLUG_PAID_WAIT)) {
                    $previous = $this->emailMeta($order, self::EMAIL_SLUG_PAID_WAIT);
                    do_action('sgmr_email_send_paid_wait', $order, ['stage' => 'paid_wait']);
                    $this->markMailSent($order, self::EMAIL_SLUG_PAID_WAIT, [
                        'trigger' => 'paid_stage',
                        'fingerprint' => [
                            'slug' => self::EMAIL_SLUG_PAID_WAIT,
                            'stage' => 'paid_wait',
                            'order' => $order->get_id(),
                        ],
                    ]);
                    $result['email_sent'] = true;
                    $this->logEmailSend(
                        $order,
                        self::EMAIL_SLUG_PAID_WAIT,
                        $this->emailMeta($order, self::EMAIL_SLUG_PAID_WAIT),
                        $previous,
                        'paid_stage'
                    );
                } else {
                    $result['reason'] = 'already_sent';
                }
            }
        } elseif ($mode === 'telefonisch') {
            if ($inStock) {
                $result['email_template'] = self::EMAIL_SLUG_OFFLINE;
                if (!$this->wasMailSent($order, self::EMAIL_SLUG_OFFLINE)) {
                    $previous = $this->emailMeta($order, self::EMAIL_SLUG_OFFLINE);
                    do_action('sgmr_email_send_planning_offline', $order, ['stage' => 'paid_instock']);
                    $this->markMailSent($order, self::EMAIL_SLUG_OFFLINE, [
                        'trigger' => 'paid_stage',
                        'fingerprint' => [
                            'slug' => self::EMAIL_SLUG_OFFLINE,
                            'stage' => 'paid_instock',
                            'order' => $order->get_id(),
                        ],
                    ]);
                    $result['email_sent'] = true;
                    $this->logEmailSend(
                        $order,
                        self::EMAIL_SLUG_OFFLINE,
                        $this->emailMeta($order, self::EMAIL_SLUG_OFFLINE),
                        $previous,
                        'paid_stage'
                    );
                } else {
                    $result['reason'] = 'already_sent';
                }
                $transitioned = $this->autoTransition($order, $currentStatus, \SGMR_STATUS_PHONE, __('[SGMR] Automatischer Wechsel: Telefonische Terminvereinbarung (Ware verfügbar).', 'sg-mr'), 'paid_stage');
                $result['auto_transition'] = \SGMR_STATUS_PHONE;
                $result['auto_transition_status'] = $transitioned ? 'done' : 'blocked';
                if ($transitioned) {
                    $result['next_status'] = \SGMR_STATUS_PHONE;
                } elseif ($result['reason'] === '') {
                    $result['reason'] = 'transition_blocked';
                }
            } else {
                $result['email_template'] = self::EMAIL_SLUG_PAID_WAIT;
                if (!$this->wasMailSent($order, self::EMAIL_SLUG_PAID_WAIT)) {
                    $previous = $this->emailMeta($order, self::EMAIL_SLUG_PAID_WAIT);
                    do_action('sgmr_email_send_paid_wait', $order, ['stage' => 'paid_wait']);
                    $this->markMailSent($order, self::EMAIL_SLUG_PAID_WAIT, [
                        'trigger' => 'paid_stage',
                        'fingerprint' => [
                            'slug' => self::EMAIL_SLUG_PAID_WAIT,
                            'stage' => 'paid_wait',
                            'order' => $order->get_id(),
                        ],
                    ]);
                    $result['email_sent'] = true;
                    $this->logEmailSend(
                        $order,
                        self::EMAIL_SLUG_PAID_WAIT,
                        $this->emailMeta($order, self::EMAIL_SLUG_PAID_WAIT),
                        $previous,
                        'paid_stage'
                    );
                } else {
                    $result['reason'] = 'already_sent';
                }
            }
        } else {
            $result['reason'] = 'mode_unknown';
        }

        return $result;
    }

    private function handleArrivedStage(WC_Order $order, string $fromStatus, array $scenario): array
    {
        $mode = $scenario['mode'];
        $counts = CartService::ensureOrderCounts($order);
        $result = [
            'event' => 'arrived_stage',
            'email_template' => 'none',
            'email_sent' => false,
            'reason' => '',
            'link_build_reason' => 'other',
        ];

        if ($mode === 'online') {
            if ($order->get_meta(CartService::META_STOCK_OVERRIDE, true) !== 'yes') {
                $order->update_meta_data(CartService::META_STOCK_OVERRIDE, 'yes');
                $order->save_meta_data();
            }

            $result['email_template'] = self::EMAIL_SLUG_ARRIVED;
            $linkData = $this->resolveArrivedLink($order, $scenario, $counts);
            $result['link_build_reason'] = $linkData['reason'];
            $forceResend = $this->isResendRequested($order, self::EMAIL_SLUG_ARRIVED);
            $origin = $forceResend ? 'status_arrived_force' : 'status_arrived';
            $sendResult = $this->sendArrivedMail($order, $linkData, $origin, $forceResend);
            $result = array_merge($result, $sendResult);

            if (!empty($sendResult['email_sent'])) {
                $transitioned = $this->autoTransition($order, \SGMR_STATUS_ARRIVED, \SGMR_STATUS_ONLINE, __('[SGMR] Automatischer Wechsel: Ware eingetroffen – Terminlink versendet.', 'sg-mr'), 'status_arrived');
                $result['auto_transition'] = \SGMR_STATUS_ONLINE;
                $result['auto_transition_status'] = $transitioned ? 'done' : 'blocked';
                if ($transitioned) {
                    $result['next_status'] = \SGMR_STATUS_ONLINE;
                } elseif (($result['reason'] ?? '') === '') {
                    $result['reason'] = 'transition_blocked';
                }
                if ($forceResend) {
                    $this->clearResendRequest($order, self::EMAIL_SLUG_ARRIVED);
                }
            } elseif ($forceResend && ($sendResult['reason'] ?? '') !== 'status_not_arrived') {
                $this->clearResendRequest($order, self::EMAIL_SLUG_ARRIVED);
            }
        } elseif ($mode === 'telefonisch') {
            if ($order->get_meta(CartService::META_STOCK_OVERRIDE, true) !== 'yes') {
                $order->update_meta_data(CartService::META_STOCK_OVERRIDE, 'yes');
                $order->save_meta_data();
            }

            $result['email_template'] = self::EMAIL_SLUG_OFFLINE;
            $result['link_build_reason'] = 'other';
            if (!$this->wasMailSent($order, self::EMAIL_SLUG_OFFLINE)) {
                $previousOffline = $this->emailMeta($order, self::EMAIL_SLUG_OFFLINE);
                do_action('sgmr_email_send_planning_offline', $order, ['stage' => 'arrived']);
                $this->markMailSent($order, self::EMAIL_SLUG_OFFLINE, [
                    'trigger' => 'status_arrived',
                    'fingerprint' => [
                        'slug' => self::EMAIL_SLUG_OFFLINE,
                        'stage' => 'arrived',
                        'order' => $order->get_id(),
                    ],
                ]);
                $result['email_sent'] = true;
                $result['trigger'] = 'status_arrived';
                $this->logEmailSend(
                    $order,
                    self::EMAIL_SLUG_OFFLINE,
                    $this->emailMeta($order, self::EMAIL_SLUG_OFFLINE),
                    $previousOffline,
                    'status_arrived'
                );
            } else {
                $result['reason'] = 'already_sent';
            }
            $transitioned = $this->autoTransition($order, \SGMR_STATUS_ARRIVED, \SGMR_STATUS_PHONE, __('[SGMR] Automatischer Wechsel: Ware eingetroffen – Telefonische Terminvereinbarung.', 'sg-mr'), 'status_arrived');
            $result['auto_transition'] = \SGMR_STATUS_PHONE;
            $result['auto_transition_status'] = $transitioned ? 'done' : 'blocked';
            if ($transitioned) {
                $result['next_status'] = \SGMR_STATUS_PHONE;
            } elseif ($result['reason'] === '') {
                $result['reason'] = 'transition_blocked';
            }
        } else {
            $result['reason'] = 'mode_unknown';
        }

        $this->addArrivedOrderNote($order, $scenario, $result);

        return $result;
    }

    private function handleServiceDoneStage(WC_Order $order, string $fromStatus, array $scenario): array
    {
        $mode = $scenario['mode'] === 'telefonisch' ? 'telefonisch' : 'online';
        $result = [
            'event' => 'service_done_stage',
            'email_template' => 'none',
            'email_sent' => false,
            'reason' => '',
        ];

        $defaultTargets = [
            'online' => \SGMR_STATUS_ONLINE,
            'telefonisch' => \SGMR_STATUS_PHONE,
        ];

        $target = apply_filters('sgmr_service_done_next_status', $defaultTargets[$mode] ?? '', $order, $scenario);
        $target = is_string($target) ? sanitize_key($target) : '';

        if ($target === '' || $target === \SGMR_STATUS_DONE) {
            $result['reason'] = 'no_transition_target';
            return $result;
        }

        $note = $mode === 'online'
            ? __('[SGMR] Automatischer Wechsel: Service erfolgt – Online-Abschluss.', 'sg-mr')
            : __('[SGMR] Automatischer Wechsel: Service erfolgt – Telefonischer Abschluss.', 'sg-mr');

        $transitioned = $this->autoTransition($order, \SGMR_STATUS_DONE, $target, $note, 'service_done');
        $result['auto_transition'] = $target;
        $result['auto_transition_status'] = $transitioned ? 'done' : 'blocked';

        if ($transitioned) {
            $result['next_status'] = $target;
        } else {
            $result['reason'] = 'transition_blocked';
        }

        return $result;
    }

    private function sendArrivedMail(WC_Order $order, array $linkData, string $origin, bool $forceResend): array
    {
        $currentStatus = $this->currentStatus($order);
        $previousRecord = $this->emailMeta($order, self::EMAIL_SLUG_ARRIVED);
        $alreadySent = $this->wasMailSent($order, self::EMAIL_SLUG_ARRIVED);
        $resendFlag = $alreadySent || $forceResend;

        $context = isset($linkData['context']) && is_array($linkData['context']) ? $linkData['context'] : [];
        $link = isset($linkData['url']) ? (string) $linkData['url'] : '';
        $reason = isset($linkData['reason']) ? (string) $linkData['reason'] : '';
        $linkHash = isset($context['link_hash']) ? (string) $context['link_hash'] : '';
        $inviteMeta = $this->bookingInviteMeta($order);
        $previousHash = is_array($inviteMeta) ? (string) ($inviteMeta['hash'] ?? '') : '';
        $sameHash = $linkHash !== '' && $previousHash !== '' && hash_equals($previousHash, $linkHash);

        if ($currentStatus !== \SGMR_STATUS_ARRIVED) {
            return [
                'email_sent' => false,
                'reason' => 'status_not_arrived',
                'trigger' => $origin,
                'resend' => $resendFlag ? 'yes' : 'no',
            ];
        }

        if ($alreadySent && !$forceResend) {
            if ($sameHash || ($linkHash === '' && $previousHash !== '')) {
                return [
                    'email_sent' => false,
                    'reason' => $sameHash ? 'same_hash' : 'already_sent',
                    'trigger' => $origin,
                    'resend' => 'yes',
                ];
            }
        }

        $extra = array_merge(
            [
                'stage' => 'arrived',
                'trigger' => $origin,
                'resend' => $resendFlag ? 'yes' : 'no',
            ],
            $context
        );

        if ($reason === 'no_region') {
            $this->dispatchArrived($order, '', $extra);
        } elseif ($link !== '') {
            $this->dispatchArrived($order, $link, $extra);
        } else {
            return [
                'email_sent' => false,
                'reason' => $reason !== '' ? $reason : 'no_link',
                'trigger' => $origin,
                'resend' => $resendFlag ? 'yes' : 'no',
            ];
        }

        $fingerprint = [
            'slug' => self::EMAIL_SLUG_ARRIVED,
            'origin' => $origin,
            'order' => $order->get_id(),
        ];
        if (!empty($context['link_hash'])) {
            $fingerprint['link_hash'] = (string) $context['link_hash'];
        }
        if (!empty($context['link_sig'])) {
            $fingerprint['link_sig'] = (string) $context['link_sig'];
        }
        if (!empty($context['link_ts'])) {
            $fingerprint['link_ts'] = (int) $context['link_ts'];
        }

        $this->markMailSent($order, self::EMAIL_SLUG_ARRIVED, [
            'fingerprint' => $fingerprint,
            'trigger' => $origin,
            'resend' => $resendFlag,
            'context' => array_intersect_key($context, array_flip(['link_ts', 'link_hash', 'link_sig'])),
        ]);
        $currentRecord = $this->emailMeta($order, self::EMAIL_SLUG_ARRIVED);
        $this->logEmailSend($order, self::EMAIL_SLUG_ARRIVED, $currentRecord, $previousRecord, $origin);
        $this->updateBookingInviteMeta($order, $linkHash);

        $masked = '';
        if ($link !== '' && function_exists('sgmr_mask_link')) {
            $masked = sgmr_mask_link($link);
        }

        return [
            'email_sent' => true,
            'reason' => '',
            'link' => $link,
            'link_ts' => $context['link_ts'] ?? null,
            'link_hash' => $context['link_hash'] ?? '',
            'link_masked' => $masked,
            'trigger' => $origin,
            'resend' => $resendFlag ? 'yes' : 'no',
        ];
    }

    public function emailLog(WC_Order $order, string $slug): array
    {
        return $this->emailMeta($order, $slug);
    }

    public function manualSendArrived(WC_Order $order, array $options = []): array
    {
        if (!CartService::orderHasService($order)) {
            return [
                'event' => 'arrived_manual',
                'email_template' => self::EMAIL_SLUG_ARRIVED,
                'email_sent' => false,
                'reason' => 'no_service',
            ];
        }

        $scenario = $this->orderScenario($order);
        $counts = CartService::ensureOrderCounts($order);
        $linkData = $this->resolveArrivedLink($order, $scenario, $counts);
        $force = !empty($options['force']);
        $origin = isset($options['origin']) ? (string) $options['origin'] : 'manual_override';

        $sendResult = $this->sendArrivedMail($order, $linkData, $origin, $force);
        $result = array_merge([
            'event' => 'arrived_manual',
            'email_template' => self::EMAIL_SLUG_ARRIVED,
            'link_build_reason' => $linkData['reason'],
        ], $sendResult);

        $status = $this->currentStatus($order);
        $baseContext = $this->baseContextFor($order, $status, $status, $scenario);
        $this->logTrigger($order, $baseContext, $result);

        if (!empty($result['email_sent'])) {
            $this->addArrivedOrderNote($order, $scenario, $result);
            $this->clearResendRequest($order, self::EMAIL_SLUG_ARRIVED);
        }

        return $result;
    }

    public function dispatchInstant(WC_Order $order, string $link, array $extra = []): void
    {
        $email = $this->getEmailInstance('SGMR_Email_Instant');
        if ($email) {
            $email->trigger($order->get_id(), array_merge(['link_url' => $link], $extra));
        }
    }

    public function dispatchArrived(WC_Order $order, string $link, array $extra = []): void
    {
        $email = $this->getEmailInstance('SGMR_Email_Arrived');
        if ($email) {
            $email->trigger($order->get_id(), array_merge(['link_url' => $link], $extra));
        }
    }

    public function dispatchOffline(WC_Order $order, array $extra = []): void
    {
        $email = $this->getEmailInstance('SGMR_Email_Offline');
        if ($email) {
            $email->trigger($order->get_id(), $extra);
        }
    }

    public function dispatchPaidWait(WC_Order $order, array $extra = []): void
    {
        $email = $this->getEmailInstance('SGMR_Email_Paid_Wait');
        if ($email) {
            $email->trigger($order->get_id(), $extra);
        }
    }

    private function resolveArrivedLink(WC_Order $order, array $scenario, array $counts): array
    {
        $region = $scenario['region'];
        $linkMeta = [];
        $link = BookingLink::build($order, $region ?: null, $linkMeta);
        if ($link) {
            return [
                'url' => $link,
                'reason' => 'ok',
                'context' => ['link_reason' => 'ok'] + $linkMeta,
                'note' => '',
            ];
        }

        if ($region === '' || $region === 'on_request') {
            return [
                'url' => '',
                'reason' => 'no_region',
                'context' => [
                    'link_reason' => 'no_region',
                    'link_note' => __('Ihre Region ist noch nicht zugeordnet. Wir melden uns telefonisch zur Terminvereinbarung.', 'sg-mr'),
                ],
                'note' => '',
            ];
        }

        $base = BookingLink::regionUrl($region);
        if (!$base) {
            $base = home_url('/');
        }

        $timestamp = time();
        $montageCount = isset($counts['montage']) ? (int) $counts['montage'] : 0;
        $etageCount = isset($counts['etage']) ? (int) $counts['etage'] : 0;
        $signatureParams = [
            'region' => $region,
            'sgm' => $montageCount,
            'sge' => $etageCount,
        ];
        $signature = \sgmr_booking_signature($order->get_id(), $signatureParams, $timestamp);
        $parsedSignature = \sgmr_booking_signature_parse($signature);

        $query = [
            'order' => $order->get_id(),
            'region' => $region,
            'sgm' => $montageCount,
            'sge' => $etageCount,
            'sig' => $signature,
        ];

        $fallbackUrl = add_query_arg($query, $base);
        $reason = !empty($counts['was_missing']) ? 'fallback_no_m_e' : 'other';

        $linkTs = $parsedSignature['ts'] ?? $timestamp;
        $linkHash = $parsedSignature['hash'] ?? '';

        return [
            'url' => $fallbackUrl,
            'reason' => $reason,
            'context' => [
                'link_reason' => $reason,
                'link_ts' => $linkTs,
                'link_sig' => $signature,
                'link_hash' => $linkHash,
                'link_region' => $region,
                'link_sgm' => $montageCount,
                'link_sge' => $etageCount,
            ],
            'note' => '',
        ];
    }

    private function addArrivedOrderNote(WC_Order $order, array $scenario, array $result): void
    {
        $note = sprintf(
            '[SGMR] event=arrived_stage terminart=%s email_template=%s email_sent=%s link_build_reason=%s',
            $scenario['mode'] ?: 'unknown',
            $result['email_template'] ?? 'none',
            !empty($result['email_sent']) ? 'true' : 'false',
            $result['link_build_reason'] ?? 'other'
        );
        $order->add_order_note($note);

        if (($result['link_build_reason'] ?? '') === 'no_region') {
            $order->add_order_note(__('[SGMR] Kein Booking-Link verfügbar: Region fehlt. Bitte Region im Auftrag hinterlegen oder Termin telefonisch planen.', 'sg-mr'));
        }
    }

    public function onBookingCreated(int $orderId, array $payload = []): void
    {
        $this->handleBookingEvent('booking_created', $orderId, $payload, \SGMR_STATUS_PLANNED_ONLINE);
    }

    public function onBookingRescheduled(int $orderId, array $payload = []): void
    {
        $this->handleBookingEvent('booking_rescheduled', $orderId, $payload, \SGMR_STATUS_ONLINE);
    }

    public function onBookingCancelled(int $orderId, array $payload = []): void
    {
        $this->handleBookingEvent('booking_cancelled', $orderId, $payload, \SGMR_STATUS_ONLINE);
    }

    public function onBookingCompleted(int $orderId, array $payload = []): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }
        if (!CartService::orderHasService($order)) {
            return;
        }
        $scenario = $this->orderScenario($order);
        $from = $this->currentStatus($order);
        $base = $this->baseContextFor($order, $from, $from, $scenario);
        $result = [
            'event' => 'booking_completed',
            'email_template' => 'none',
            'email_sent' => false,
            'reason' => 'manual_completion_required',
            'payload' => $this->sanitizePayload($payload),
        ];
        $this->logTrigger($order, $base, $result);
    }

    private function handleBookingEvent(string $event, int $orderId, array $payload, string $targetStatus): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }
        if (!CartService::orderHasService($order)) {
            return;
        }
        $scenario = $this->orderScenario($order);
        $from = $this->currentStatus($order);
        $base = $this->baseContextFor($order, $from, $from, $scenario);
        $result = [
            'event' => $event,
            'email_template' => 'none',
            'email_sent' => false,
            'reason' => '',
            'payload' => $this->sanitizePayload($payload),
        ];

        if ($scenario['mode'] !== 'online') {
            $result['reason'] = 'mode_not_online';
            $this->logTrigger($order, $base, $result);
            return;
        }

        $transitioned = $this->autoTransition($order, $from, $targetStatus, $this->bookingNoteForEvent($event), 'webhook_event');
        $result['auto_transition'] = $targetStatus;
        $result['auto_transition_status'] = $transitioned ? 'done' : 'blocked';
        if ($transitioned) {
            $result['next_status'] = $targetStatus;
            $base = $this->baseContextFor($order, $from, $this->currentStatus($order), $scenario);
        } elseif ($result['reason'] === '') {
            $result['reason'] = 'transition_blocked';
        }

        $this->logTrigger($order, $base, $result);
    }

    private function bookingNoteForEvent(string $event): string
    {
        switch ($event) {
            case 'booking_created':
                return __('[SGMR] Automatischer Wechsel: Termin wurde online gebucht.', 'sg-mr');
            case 'booking_rescheduled':
                return __('[SGMR] Automatischer Wechsel: Termin wurde angepasst – erneut online planen.', 'sg-mr');
            case 'booking_cancelled':
                return __('[SGMR] Automatischer Wechsel: Termin storniert – erneut online planen.', 'sg-mr');
            default:
                return __('[SGMR] Automatischer Wechsel: Aktualisierung der Buchung.', 'sg-mr');
        }
    }

    private function getEmailInstance(string $key)
    {
        $mailer = WC()->mailer();
        if (method_exists($mailer, 'get_emails')) {
            $emails = $mailer->get_emails();
            return $emails[$key] ?? null;
        }
        return null;
    }
}
