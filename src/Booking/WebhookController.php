<?php

namespace SGMR\Booking;

use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use SGMR\Admin\Settings;
use SGMR\Integrations\PrefillController;
use SGMR\Services\CartService;
use SGMR\Region\RegionDayPlanner;
use function get_option;
use function sanitize_key;
use function sgmr_booking_signature_parse;
use function sgmr_log;
use function sgmr_validate_booking_signature;
use function sgmr_normalize_region_slug;
use function wc_get_order;
use function wp_json_encode;
use function wp_parse_url;

class WebhookController
{
    private FluentBookingClient $client;
    private PrefillManager $prefillManager;
    private ?BookingOrchestrator $orchestrator;
    private RegionDayPlanner $regionDayPlanner;

    public function __construct(
        FluentBookingClient $client,
        PrefillManager $prefillManager,
        ?BookingOrchestrator $orchestrator = null,
        ?RegionDayPlanner $regionDayPlanner = null
    ) {
        $this->client = $client;
        $this->prefillManager = $prefillManager;
        $this->orchestrator = $orchestrator;
        $this->regionDayPlanner = $regionDayPlanner ?: new RegionDayPlanner();
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('sgmr/v1', '/fluent-booking/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handleWebhook'],
            'permission_callback' => '__return_true',
            'args' => [],
        ]);
    }

    public function handlePrefill(WP_REST_Request $request)
    {
        return \SGMR\Integrations\PrefillController::handle($request);
    }

    public function handleWebhook(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            $data = [];
        }

        $event = $this->resolveEvent($data);
        if ($event === '') {
            return new WP_Error('sgmr_webhook_event', __('Webhook event missing or invalid.', 'sg-mr'), ['status' => 400]);
        }

        $orderId = $this->extractOrderId($data);
        $signature = $this->extractSignature($data);

        if ($orderId <= 0 || $signature === '') {
            return new WP_Error('sgmr_webhook_signature', __('Order reference or signature missing.', 'sg-mr'), ['status' => 400]);
        }

        $parsedSignature = sgmr_booking_signature_parse($signature);
        $timestamp = isset($parsedSignature['ts']) ? (int) $parsedSignature['ts'] : 0;
        $ttl = $this->tokenTtlSeconds();
        if ($timestamp <= 0 || abs(time() - $timestamp) > $ttl) {
            sgmr_log('webhook_timestamp_invalid', [
                'order_id' => $orderId,
                'event' => $event,
                'token_ts' => $timestamp,
            ]);
            return new WP_Error('sgmr_webhook_timestamp_invalid', __('Webhook timestamp invalid.', 'sg-mr'), ['status' => 401]);
        }

        $signatureParams = $this->signatureParamsFromRequest($data);
        if (!sgmr_validate_booking_signature($orderId, $signature, $this->tokenTtlSeconds(), $signatureParams)) {
            sgmr_log('webhook_signature_invalid', [
                'order_id' => $orderId,
                'event' => $event,
                'token_ts' => $timestamp,
            ]);
            return new WP_Error('sgmr_webhook_signature_invalid', __('Signature validation failed.', 'sg-mr'), ['status' => 401]);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return new WP_Error('sgmr_webhook_order_missing', __('Order not found.', 'sg-mr'), ['status' => 404]);
        }

        if (get_option('sgmr_logging_enabled')) {
            error_log('SGMR Webhook: ' . $event . ' payload=' . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $context = [
            'order_id' => $orderId,
            'event' => $event,
            'token_ts' => $parsedSignature['ts'],
            'token_hash' => $parsedSignature['hash'],
            'booking_id' => $this->extractBookingId($data),
            'remote_ip' => $this->remoteAddress($request),
            'user_agent' => $this->userAgent($request),
        ];
        $context['region_day_policy'] = $this->regionDayPlanner->policy();
        $context['phase'] = 'received';
        sgmr_log('webhook_' . $event, $context);

        if ($this->orchestrator) {
            $result = $this->orchestrator->handle($event, $order, $data, $signature);
            if ($result instanceof WP_Error) {
                $this->logProcessedEvent($event, $context, false, ['error' => $result->get_error_message()]);
                return $result;
            }
            if ($result instanceof WP_REST_Response) {
                $this->logProcessedEvent($event, $context, true, $this->responseData($result));
                return $result;
            }
            if (is_array($result)) {
                $this->logProcessedEvent($event, $context, !empty($result['handled']), $result);
                return new WP_REST_Response($result);
            }
            $this->logProcessedEvent($event, $context, false, ['result' => $result]);
        }

        $this->logProcessedEvent($event, $context, false, ['status' => 'accepted', 'handled' => false]);
        return new WP_REST_Response([
            'status' => 'accepted',
            'handled' => false,
        ]);
    }

    private function resolveEvent(array $data): string
    {
        $candidate = $this->extractEventCandidate($data);
        if ($candidate !== '') {
            return $this->normalizeEventKey($candidate);
        }

        $status = $this->extractStatusCandidate($data);
        if ($status !== '') {
            return $this->normalizeEventKey($status, true);
        }

        return '';
    }

    private function extractEventCandidate(array $data): string
    {
        $candidates = ['event', 'event_type', 'type'];
        foreach ($candidates as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }
        if (!empty($data['booking']) && is_array($data['booking'])) {
            $booking = $data['booking'];
            foreach ($candidates as $key) {
                if (!empty($booking[$key]) && is_scalar($booking[$key])) {
                    return (string) $booking[$key];
                }
            }
        }
        return '';
    }

    private function extractStatusCandidate(array $data): string
    {
        $statusKeys = ['status', 'booking_status'];
        foreach ($statusKeys as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }
        if (isset($data['booking']) && is_array($data['booking'])) {
            foreach ($statusKeys as $key) {
                if (!empty($data['booking'][$key]) && is_scalar($data['booking'][$key])) {
                    return (string) $data['booking'][$key];
                }
            }
        }
        if (isset($data['payload']) && is_array($data['payload'])) {
            foreach ($statusKeys as $key) {
                if (!empty($data['payload'][$key]) && is_scalar($data['payload'][$key])) {
                    return (string) $data['payload'][$key];
                }
            }
        }
        return '';
    }

    private function normalizeEventKey(string $value, bool $fromStatus = false): string
    {
        $normalized = sanitize_key($value);
        if ($normalized === '') {
            return '';
        }

        $map = [
            'single' => 'booking_created',
            'booking_single' => 'booking_created',
            'scheduled' => 'booking_created',
            'confirmed' => 'booking_created',
            'booked' => 'booking_created',
            'created' => 'booking_created',
            'booking_confirmed' => 'booking_created',
            'appointment_confirmed' => 'booking_created',
            'rescheduled' => 'booking_rescheduled',
            'booking_rescheduled' => 'booking_rescheduled',
            'modified' => 'booking_rescheduled',
            'updated' => 'booking_rescheduled',
            'changed' => 'booking_rescheduled',
            'cancelled' => 'booking_cancelled',
            'canceled' => 'booking_cancelled',
            'booking_cancelled' => 'booking_cancelled',
            'deleted' => 'booking_cancelled',
            'rejected' => 'booking_cancelled',
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        if ($fromStatus && $normalized === 'completed') {
            return 'booking_created';
        }

        return $normalized;
    }

    private function extractOrderId(array $data): int
    {
        $orderId = 0;
        $orderKeys = ['order_id', 'order', 'woo_order', 'sg_order_id', 'sg_token_order_id'];
        foreach ($orderKeys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $orderId = (int) $data[$key];
                break;
            }
        }
        if ($orderId <= 0 && isset($data['booking']) && is_array($data['booking'])) {
            $orderId = (int) $this->extractFromBooking($data['booking'], $orderKeys);
        }
        if ($orderId <= 0 && isset($data['payload']) && is_array($data['payload'])) {
            $orderId = (int) $this->extractFromBooking($data['payload'], $orderKeys);
        }
        if ($orderId <= 0) {
            $queryParams = $this->queryParamsFromData($data);
            foreach ($orderKeys as $key) {
                if (!isset($queryParams[$key])) {
                    continue;
                }
                $candidate = (int) $queryParams[$key];
                if ($candidate > 0) {
                    return $candidate;
                }
            }
        }
        return $orderId;
    }

    private function extractSignature(array $data): string
    {
        $candidates = ['sig', 'signature', 'token', 'token_sig', 'sg_token_sig', 'sg_token_signature'];
        foreach ($candidates as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                return $this->sanitizeSignature((string) $data[$key]);
            }
        }
        if (isset($data['booking']) && is_array($data['booking'])) {
            $value = $this->extractFromBooking($data['booking'], $candidates);
            if ($value !== '') {
                return $this->sanitizeSignature($value);
            }
        }
        if (isset($data['payload']) && is_array($data['payload'])) {
            $value = $this->extractFromBooking($data['payload'], $candidates);
            if ($value !== '') {
                return $this->sanitizeSignature($value);
            }
        }
        $queryParams = $this->queryParamsFromData($data);
        foreach ($candidates as $key) {
            if (!isset($queryParams[$key])) {
                continue;
            }
            $value = $this->sanitizeSignature((string) $queryParams[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function signatureParamsFromRequest(array $data): array
    {
        $params = $this->signatureParamsFromArray($data);
        if (!$params && isset($data['payload']) && is_array($data['payload'])) {
            $params = $this->signatureParamsFromArray($data['payload']);
        }
        if (!$params && isset($data['booking']) && is_array($data['booking'])) {
            $params = $this->signatureParamsFromArray($data['booking']);
        }
        return $params;
    }

    private function signatureParamsFromArray(array $data): array
    {
        $params = [];
        $legacyAllowed = sgmr_booking_legacy_params_enabled();
        if (isset($data['region'])) {
            $params['region'] = sanitize_key((string) $data['region']);
        }
        if (isset($data['sgm'])) {
            $params['sgm'] = (int) $data['sgm'];
        }
        if (isset($data['sge'])) {
            $params['sge'] = (int) $data['sge'];
        }
        if ($legacyAllowed) {
            if (!isset($params['sgm']) && isset($data['m'])) {
                $params['sgm'] = (int) $data['m'];
            }
            if (!isset($params['sge']) && isset($data['e'])) {
                $params['sge'] = (int) $data['e'];
            }
        }
        $queryParams = $this->queryParamsFromData($data);
        if (!isset($params['region']) && isset($queryParams['region'])) {
            $params['region'] = sanitize_key((string) $queryParams['region']);
        }
        if (!isset($params['sgm']) && isset($queryParams['sgm'])) {
            $params['sgm'] = (int) $queryParams['sgm'];
        } elseif (!isset($params['sgm']) && $legacyAllowed && isset($queryParams['m'])) {
            $params['sgm'] = (int) $queryParams['m'];
        }
        if (!isset($params['sge']) && isset($queryParams['sge'])) {
            $params['sge'] = (int) $queryParams['sge'];
        } elseif (!isset($params['sge']) && $legacyAllowed && isset($queryParams['e'])) {
            $params['sge'] = (int) $queryParams['e'];
        }
        return $params;
    }

    private function extractFromBooking(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }
        if (isset($data['meta']) && is_array($data['meta'])) {
            foreach ($keys as $key) {
                if (isset($data['meta'][$key]) && is_scalar($data['meta'][$key])) {
                    return (string) $data['meta'][$key];
                }
            }
        }
        if (isset($data['form_data']) && is_array($data['form_data'])) {
            foreach ($keys as $key) {
                if (isset($data['form_data'][$key]) && is_scalar($data['form_data'][$key])) {
                    return (string) $data['form_data'][$key];
                }
            }
        }
        if (isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (isset($field['name']) && in_array($field['name'], $keys, true) && isset($field['value'])) {
                    return (string) $field['value'];
                }
                if (isset($field['slug']) && in_array($field['slug'], $keys, true) && isset($field['value'])) {
                    return (string) $field['value'];
                }
                foreach ($keys as $key) {
                    if (isset($field[$key]) && is_scalar($field[$key])) {
                        return (string) $field[$key];
                    }
                }
            }
        }
        if (isset($data['responses']) && is_array($data['responses'])) {
            foreach ($data['responses'] as $response) {
                if (!is_array($response)) {
                    continue;
                }
                if (isset($response['name']) && in_array($response['name'], $keys, true) && isset($response['value'])) {
                    return (string) $response['value'];
                }
            }
        }
        return '';
    }

    private function queryParamsFromData(array $data): array
    {
        $urls = [];
        if (isset($data['source_url']) && is_scalar($data['source_url'])) {
            $urls[] = (string) $data['source_url'];
        }
        foreach (['booking', 'payload'] as $nestedKey) {
            if (isset($data[$nestedKey]) && is_array($data[$nestedKey]) && isset($data[$nestedKey]['source_url']) && is_scalar($data[$nestedKey]['source_url'])) {
                $urls[] = (string) $data[$nestedKey]['source_url'];
            }
        }
        if (isset($data['meta']) && is_array($data['meta']) && isset($data['meta']['source_url']) && is_scalar($data['meta']['source_url'])) {
            $urls[] = (string) $data['meta']['source_url'];
        }

        $params = [];
        foreach ($urls as $url) {
            if ($url === '') {
                continue;
            }
            $query = wp_parse_url($url, PHP_URL_QUERY);
            if (!$query) {
                continue;
            }
            parse_str((string) $query, $parsed);
            if (!is_array($parsed)) {
                continue;
            }
            foreach ($parsed as $key => $value) {
                if (is_scalar($value) && $value !== '') {
                    $params[$key] = (string) $value;
                }
            }
        }

        return $params;
    }

    private function extractBookingId(array $data): string
    {
        if (isset($data['booking']) && is_array($data['booking'])) {
            $booking = $data['booking'];
            foreach (['booking_id', 'id', 'uid'] as $key) {
                if (isset($booking[$key]) && is_scalar($booking[$key])) {
                    return (string) $booking[$key];
                }
            }
        }
        if (isset($data['booking_id']) && is_scalar($data['booking_id'])) {
            return (string) $data['booking_id'];
        }
        return '';
    }

    private function sanitizeSignature(string $signature): string
    {
        $signature = trim($signature);
        if ($signature === '') {
            return '';
        }
        $signature = preg_replace('/[^0-9a-f\.]+/i', '', $signature);
        return $signature ?: '';
    }

    private function logProcessedEvent(string $event, array $context, bool $handled, array $result = []): void
    {
        $payload = $context;
        $payload['phase'] = 'processed';
        $payload['handled'] = $handled ? 'yes' : 'no';
        if (isset($result['status'])) {
            $payload['result_status'] = (string) $result['status'];
        }
        if (isset($result['handled'])) {
            $payload['handled'] = !empty($result['handled']) ? 'yes' : 'no';
        }
        if (isset($result['rescheduled'])) {
            $payload['rescheduled'] = (string) $result['rescheduled'];
        }
        if (isset($result['sequence_source'])) {
            $payload['sequence_source'] = (string) $result['sequence_source'];
        }
        if (isset($result['slot_minutes_remote'])) {
            $payload['slot_minutes_remote'] = (int) $result['slot_minutes_remote'];
        }
        if (!$handled && isset($result['error'])) {
            $payload['error'] = (string) $result['error'];
        }
        sgmr_log('webhook_' . $event, $payload);
    }

    private function responseData(WP_REST_Response $response): array
    {
        $data = $response->get_data();
        return is_array($data) ? $data : [];
    }

    private function tokenTtlSeconds(): int
    {
        $config = Settings::getSettings();
        $hours = (int) ($config['token_ttl_hours'] ?? 96);
        if ($hours <= 0) {
            $hours = 1;
        }
        return $hours * HOUR_IN_SECONDS;
    }

    private function remoteAddress(WP_REST_Request $request): string
    {
        $header = $request->get_header('x-forwarded-for');
        if ($header) {
            $parts = explode(',', $header);
            if (!empty($parts[0])) {
                return trim($parts[0]);
            }
        }
        $remote = $request->get_header('remote_addr');
        if ($remote) {
            return trim($remote);
        }
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    private function userAgent(WP_REST_Request $request): string
    {
        $agent = $request->get_header('user_agent');
        if ($agent) {
            return trim($agent);
        }
        return isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    }
}

class_alias(__NAMESPACE__ . '\\WebhookController', 'Sanigroup\\Montagerechner\\Booking\\WebhookController');
