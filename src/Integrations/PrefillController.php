<?php

namespace SGMR\Integrations;

use RuntimeException;
use SGMR\Booking\PrefillManager;
use SGMR\Plugin;
use SGMR\Region\RegionDayPlanner;
use SGMR\Services\CartService;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use function __;
use function absint;
use function add_action;
use function class_exists;
use function function_exists;
use function get_option;
use function is_array;
use function is_string;
use function max;
use function method_exists;
use function register_rest_route;
use function sanitize_key;
use function sgmr_append_diag_log;
use function sgmr_booking_signature_parse;
use function sgmr_log;
use function sgmr_normalize_region_slug;
use function sgmr_validate_booking_signature;
use function trim;
use function wc_get_order;
use const HOUR_IN_SECONDS;

class PrefillController
{
    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route('sgmr/v1', '/fluent-booking/prefill', [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'handle'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('sgmr/v1', '/fluent-booking/prefill', [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handlePost'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * Proxy that keeps backwards compatibility for callers still invoking handle() directly.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function handle(WP_REST_Request $request)
    {
        return strtoupper($request->get_method()) === 'GET'
            ? self::handleGet($request)
            : self::handlePost($request);
    }

    /**
     * Handle the lightweight GET variant returning the prefill payload for an order.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function handleGet(WP_REST_Request $request)
    {
        $orderId = absint($request->get_param('order_id'));
        if (!$orderId) {
            return new WP_Error('bad_request', 'order_id missing', ['status' => 400]);
        }

        try {
            $payload = PrefillManager::forOrder($orderId)->build();
        } catch (RuntimeException $exception) {
            return new WP_Error('sgmr_prefill_order_missing', $exception->getMessage(), ['status' => 404]);
        } catch (WP_Error $error) {
            return $error;
        }

        if (!is_array($payload)) {
            return new WP_Error('sgmr_prefill_invalid_payload', 'Unable to build prefill payload.', ['status' => 500]);
        }

        return new WP_REST_Response($payload, 200);
    }

    /**
     * Handle the legacy POST prefill contract used by the booking page.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function handlePost(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            $data = [];
        }

        $orderId = isset($data['order_id']) ? (int) $data['order_id'] : 0;
        if (!$orderId && isset($data['order'])) {
            $orderId = (int) $data['order'];
        }

        $signature = isset($data['sig']) ? (string) $data['sig'] : '';
        if ($signature === '' && isset($data['signature'])) {
            $signature = (string) $data['signature'];
        }

        if ($orderId <= 0 || $signature === '') {
            return new WP_Error('sgmr_prefill_invalid', __('Order reference or signature missing.', 'sg-mr'), ['status' => 400]);
        }

        $signatureParams = self::signatureParamsFromArray($data);
        if (!sgmr_validate_booking_signature($orderId, $signature, self::tokenTtlSeconds(), $signatureParams)) {
            return new WP_Error('sgmr_prefill_signature', __('Signature validation failed.', 'sg-mr'), ['status' => 403]);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return new WP_Error('sgmr_prefill_order_missing', __('Order not found.', 'sg-mr'), ['status' => 404]);
        }

        $region = isset($data['region']) ? sanitize_key((string) $data['region']) : (string) $order->get_meta(CartService::META_REGION_KEY, true);
        $region = sgmr_normalize_region_slug($region);

        $counts = ['montage' => 0, 'etage' => 0];
        if (class_exists(CartService::class) && method_exists(CartService::class, 'ensureOrderCounts')) {
            $counts = CartService::ensureOrderCounts($order);
        }
        $m = (int) ($counts['montage'] ?? 0);
        $e = (int) ($counts['etage'] ?? 0);

        if (isset($data['sgm'])) {
            $m = max(0, (int) $data['sgm']);
        } elseif (function_exists('sgmr_booking_legacy_params_enabled') && sgmr_booking_legacy_params_enabled() && isset($data['m'])) {
            $m = max(0, (int) $data['m']);
        }

        if (isset($data['sge'])) {
            $e = max(0, (int) $data['sge']);
        } elseif (function_exists('sgmr_booking_legacy_params_enabled') && sgmr_booking_legacy_params_enabled() && isset($data['e'])) {
            $e = max(0, (int) $data['e']);
        }

        $routerMeta = [];
        if (isset($data['router']) && is_array($data['router'])) {
            $routerMeta = $data['router'];
        }

        $prefill = self::prefillManager()->payloadFor($order, $region, $m, $e, $signature, [
            'router' => $routerMeta,
        ]);

        $parsedSignature = sgmr_booking_signature_parse($signature);
        $context = [
            'order_id' => $orderId,
            'region' => $region,
            'token_ts' => $parsedSignature['ts'] ?? null,
            'token_hash' => $parsedSignature['hash'] ?? '',
            'fields_count' => isset($prefill['fields']['stable']) ? count((array) $prefill['fields']['stable']) : 0,
            'remote_ip' => self::remoteAddress($request),
            'user_agent' => self::userAgent($request),
        ];
        $context['region_day_policy'] = self::regionDayPlanner()->policy();

        sgmr_log('prefill_applied', $context);

        if (function_exists('sgmr_append_diag_log')) {
            $routingInfo = isset($prefill['routing']) && is_array($prefill['routing']) ? $prefill['routing'] : [];
            $stableFields = isset($prefill['fields']['stable']) && is_array($prefill['fields']['stable']) ? $prefill['fields']['stable'] : [];
            sgmr_append_diag_log('prefill', [
                'order_id' => $orderId,
                'team_id' => $routingInfo['team_id'] ?? '',
                'event_id' => $routingInfo['event_id'] ?? 0,
                'fields_count' => count($stableFields),
                'sgm' => $m,
                'sge' => $e,
                'status' => 'served',
            ]);
        }

        return new WP_REST_Response($prefill);
    }

    private static function tokenTtlSeconds(): int
    {
        $hours = (int) get_option('sgmr_booking_token_ttl_hours', 96);
        if ($hours <= 0) {
            $hours = 96;
        }
        return $hours * HOUR_IN_SECONDS;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function signatureParamsFromArray(array $data): array
    {
        $params = [];
        if (isset($data['region'])) {
            $params['region'] = sanitize_key((string) $data['region']);
        }
        if (isset($data['sgm'])) {
            $params['sgm'] = (int) $data['sgm'];
        }
        if (isset($data['sge'])) {
            $params['sge'] = (int) $data['sge'];
        }
        if (function_exists('sgmr_booking_legacy_params_enabled') && sgmr_booking_legacy_params_enabled()) {
            if (!isset($params['sgm']) && isset($data['m'])) {
                $params['sgm'] = (int) $data['m'];
            }
            if (!isset($params['sge']) && isset($data['e'])) {
                $params['sge'] = (int) $data['e'];
            }
        }
        return $params;
    }

    private static function remoteAddress(WP_REST_Request $request): string
    {
        $ip = $request->get_header('X-Forwarded-For');
        if (!$ip) {
            $ip = $request->get_header('REMOTE_ADDR');
        }
        if (!$ip) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        return is_string($ip) ? trim($ip) : '';
    }

    private static function userAgent(WP_REST_Request $request): string
    {
        $ua = $request->get_header('User-Agent');
        if (!$ua) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        return is_string($ua) ? trim($ua) : '';
    }

    private static function prefillManager(): PrefillManager
    {
        return Plugin::instance()->prefillManager();
    }

    private static function regionDayPlanner(): RegionDayPlanner
    {
        return Plugin::instance()->regionDayPlanner();
    }
}

class_alias(__NAMESPACE__ . '\\PrefillController', 'Sanigroup\\Montagerechner\\Integrations\\PrefillController');
