<?php

namespace SGMR\Shortcodes;

use SGMR\Booking\FluentBookingClient;
use SGMR\Booking\HybridRouter;
use SGMR\Booking\PrefillManager;
use SGMR\Admin\Settings;
use SGMR\Plugin;
use SGMR\Services\CartService;
use SGMR\Region\RegionDayPlanner;
use SGMR\Utils\PostcodeHelper;
use WC_Order;
use function sanitize_key;
use function _n;
use function apply_filters;
use function sgmr_booking_signature_parse;
use function sgmr_log;
use function sgmr_normalize_region_slug;
use function sgmr_validate_booking_signature;

class AutoBookingShortcode
{
    private FluentBookingClient $client;
    private PrefillManager $prefillManager;
    private RegionDayPlanner $regionDayPlanner;
    private HybridRouter $router;
    private static bool $scriptEnqueued = false;

    public function __construct(FluentBookingClient $client, PrefillManager $prefillManager, RegionDayPlanner $regionDayPlanner, HybridRouter $router)
    {
        $this->client = $client;
        $this->prefillManager = $prefillManager;
        $this->regionDayPlanner = $regionDayPlanner;
        $this->router = $router;
    }

    public function boot(): void
    {
        add_shortcode('sg_booking_auto', [$this, 'render']);
    }

    public function render($atts): string
    {
        $atts = shortcode_atts([
            'region' => '',
            'render_mode' => '',
        ], $atts, 'sg_booking_auto');

        $regionQuery = isset($_GET['region']) ? sanitize_text_field(wp_unslash((string) $_GET['region'])) : '';
        $regionAttr = (string) $atts['region'];
        $rawRegion = $regionQuery !== '' ? $regionQuery : $regionAttr;
        $normalizedRegion = $this->normalizeRegion($rawRegion);
        if ($normalizedRegion === '') {
            $this->logFailure(0, '', 'region_unknown');
            return $this->renderError(__('Region fehlt oder ist unbekannt.', 'sg-mr'));
        }

        $orderId = isset($_GET['order']) ? (int) $_GET['order'] : 0;
        if ($orderId <= 0) {
            $this->logFailure(0, $normalizedRegion, 'order_missing');
            return $this->renderError(__('Bestellreferenz fehlt.', 'sg-mr'));
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            $this->logFailure($orderId, $normalizedRegion, 'order_not_found');
            return $this->renderError(__('Bestellung nicht gefunden.', 'sg-mr'));
        }
        if (!CartService::orderHasService($order)) {
            $this->logFailure($orderId, $normalizedRegion, 'order_without_service');
            return $this->renderError(__('Für diese Bestellung ist keine Serviceleistung hinterlegt.', 'sg-mr'));
        }

        $signature = isset($_GET['sig']) ? sanitize_text_field(wp_unslash((string) $_GET['sig'])) : '';
        if ($signature === '') {
            $this->logFailure($orderId, $normalizedRegion, 'missing_token');
            return $this->renderError(__('Ungültiger oder unvollständiger Link.', 'sg-mr'), 403);
        }
        $parsedSignature = sgmr_booking_signature_parse($signature);
        $timestamp = (int) $parsedSignature['ts'];
        if ($timestamp <= 0 || $parsedSignature['hash'] === '') {
            $this->logFailure($orderId, $normalizedRegion, 'token_malformed', ['sig' => $signature]);
            return $this->renderError(__('Der Buchungslink ist beschädigt oder unvollständig. Bitte fordern Sie einen neuen Link an.', 'sg-mr'), 403);
        }

        $legacyAllowed = Plugin::instance()->legacyBookingParamsEnabled();
        $raw = [
            'sgm' => isset($_GET['sgm']) ? $_GET['sgm'] : null,
            'sge' => isset($_GET['sge']) ? $_GET['sge'] : null,
            'm' => isset($_GET['m']) ? $_GET['m'] : null,
            'e' => isset($_GET['e']) ? $_GET['e'] : null,
        ];

        $sgm = isset($raw['sgm']) ? max(0, (int) $raw['sgm']) : null;
        $sge = isset($raw['sge']) ? max(0, (int) $raw['sge']) : null;

        if ($sgm === null && $legacyAllowed && $raw['m'] !== null) {
            $sgm = max(0, (int) $raw['m']);
        }
        if ($sge === null && $legacyAllowed && $raw['e'] !== null) {
            $sge = max(0, (int) $raw['e']);
        }

        if (!$legacyAllowed && ($raw['m'] !== null || $raw['e'] !== null) && $sgm === null && $sge === null) {
            $this->logFailure($orderId, $normalizedRegion, 'legacy_params_disabled');
            return $this->renderError(__('Dieser Buchungslink ist veraltet. Bitte fordern Sie einen neuen Link an.', 'sg-mr'), 403);
        }

        $signatureParams = [
            'order' => $orderId,
            'region' => $normalizedRegion,
            'sge' => max(0, (int) ($sge ?? 0)),
            'sgm' => max(0, (int) ($sgm ?? 0)),
        ];
        ksort($signatureParams);

        $tokenTtl = $this->tokenTtlSeconds();

        if (abs(time() - $timestamp) > $tokenTtl) {
            $this->logFailure($orderId, $normalizedRegion, 'token_expired', ['ts' => $timestamp]);
            return $this->renderError(__('Dieser Buchungslink ist abgelaufen. Bitte fordern Sie einen neuen Link an.', 'sg-mr'), 403);
        }
        if (!$this->validateSignature($orderId, $signature, $signatureParams)) {
            $this->logFailure($orderId, $normalizedRegion, 'invalid_sig', ['ts' => $timestamp]);
            return $this->renderError(__('Link konnte nicht verifiziert werden. Bitte fordern Sie einen neuen Link an.', 'sg-mr'), 403);
        }

        $mParam = (int) $signatureParams['sgm'];
        $eParam = (int) $signatureParams['sge'];

        $counts = CartService::ensureOrderCounts($order);
        $mActual = (int) ($counts['montage'] ?? 0);
        $eActual = (int) ($counts['etage'] ?? 0);
        $m = $mActual > 0 ? $mActual : $mParam;
        $e = $eActual > 0 ? $eActual : $eParam;

        $modes = $this->desiredModes($m, $e);
        $primaryMode = $modes[0];
        $renderMode = $this->resolveRenderMode($atts['render_mode']);

        $driveMinutes = $this->router->distanceMinutesForOrder($order);
        $selection = $this->router->select($order, $normalizedRegion, $primaryMode, $driveMinutes, [
            'montage_count' => $m,
            'etage_count' => $e,
        ]);
        if (!$selection) {
            sgmr_log('router_selection_failed', [
                'order_id' => $orderId,
                'region' => $normalizedRegion,
                'service' => $primaryMode,
                'drive_minutes' => $driveMinutes,
            ]);
        }

        $selectors = [];
        $selectorMode = 'hybrid';

        if ($selection && !empty($selection['shortcode'])) {
            $selectors[] = [
                'key' => 'router:' . strtolower((string) $selection['team_key']),
                'label' => sprintf('%s – %s', $selection['team_label'], $this->modeLabel($primaryMode)),
                'shortcode' => (string) $selection['shortcode'],
                'mode' => $primaryMode,
                'team' => (string) $selection['team_key'],
                'team_label' => (string) $selection['team_label'],
                'calendar_id' => (int) $selection['calendar_id'],
                'strategy' => (string) $selection['strategy'],
                'drive_minutes' => (int) $selection['drive_minutes'],
            ];
        }

        if (empty($selectors)) {
            $maxTeams = max(1, min($this->regionDayPlanner->maxTeams($normalizedRegion), 4));
            $teamSelectors = $this->buildTeamSelectors($normalizedRegion, $modes, $maxTeams);
            $mapping = get_option(Plugin::OPTION_FB_MAPPING, []);
            $selectors = $teamSelectors;
            $selectorMode = 'teams';

            if (empty($selectors)) {
                $mapping = is_array($mapping) ? $mapping : [];
                $selectors = $this->prepareSelectors($normalizedRegion, $mapping);
                $selectors = $this->filterSelectorsByModes($selectors, $modes);
                $selectorMode = 'legacy';
            }

            if ($selectorMode !== 'teams') {
                $selectors = array_slice($selectors, 0, 2);
            }
        }

        if (empty($selectors)) {
            $this->logFailure($orderId, $normalizedRegion, 'no_selectors');
            return '<div class="sg-booking-auto-note sg-booking-auto-offline">' . esc_html__('Aktuell keine Online-Slots – bitte telefonisch planen.', 'sg-mr') . '</div>';
        }

        $routerMeta = null;
        if ($selectorMode === 'hybrid' && $selection && !empty($selectors)) {
            $routerMeta = [
                'team' => (string) $selection['team_key'],
                'team_label' => (string) $selection['team_label'],
                'calendar_id' => (int) $selection['calendar_id'],
                'strategy' => (string) $selection['strategy'],
                'drive_minutes' => (int) $selection['drive_minutes'],
                'selection_index' => isset($selection['selection_index']) ? (int) $selection['selection_index'] : 0,
            ];
        }

        self::enqueueScript();

        $regionDayMeta = $this->regionDaysMeta($normalizedRegion);
        $configTeams = $this->teamsMeta($selectors);

        $config = [
            'order' => $orderId,
            'sig' => $signature,
            'region' => $normalizedRegion,
            'counts' => ['m' => $m, 'e' => $e],
            'selectors' => $this->selectorsMeta($selectors),
            'rest' => $this->restEndpoints(),
            'params' => [
                'sgm' => $signatureParams['sgm'],
                'sge' => $signatureParams['sge'],
            ],
            'render_mode' => $renderMode,
            'selector_mode' => $selectorMode,
            'region_days' => $regionDayMeta,
            'teams' => $configTeams,
            'router' => $routerMeta,
        ];
        self::addPrefillInline($config);

        sgmr_log('booking_page_opened', array_merge(
            [
                'order_id' => $orderId,
                'region' => $normalizedRegion,
                'm' => $m,
                'e' => $e,
                'selector_mode' => $selectorMode,
                'sig_valid' => 'yes',
                'link_ts' => $timestamp,
                'token_hash' => $parsedSignature['hash'],
                'prefill_source' => 'url',
            ],
            $this->regionMetaContext($order)
        ));

        return $this->renderSelectors($normalizedRegion, $selectors, $order, $m, $e, [
            'render_mode' => $renderMode,
            'selector_mode' => $selectorMode,
        ]);
    }

    private function normalizeRegion(string $raw): string
    {
        $raw = str_replace(['-', ' '], '_', strtolower($raw));
        return sgmr_normalize_region_slug($raw);
    }

    private function tokenTtlSeconds(): int
    {
        $settings = Settings::getSettings();
        $hours = (int) ($settings['token_ttl_hours'] ?? 96);
        if ($hours <= 0) {
            $hours = 1;
        }
        return $hours * HOUR_IN_SECONDS;
    }

    private function validateSignature(int $orderId, string $signature, array $params): bool
    {
        return sgmr_validate_booking_signature($orderId, $signature, $this->tokenTtlSeconds(), $params);
    }

    private function prepareSelectors(string $region, array $mapping): array
    {
        $selectors = [];
        $regionSelectors = $mapping['selectors'][$region] ?? [];
        if (is_array($regionSelectors)) {
            foreach ($regionSelectors as $key => $shortcode) {
                $shortcode = trim((string) $shortcode);
                if ($shortcode === '') {
                    continue;
                }
                $label = $this->selectorLabel($region, (string) $key);
                $selectors[] = [
                    'key' => (string) $key,
                    'label' => $label,
                    'shortcode' => $shortcode,
                    'mode' => $this->normalizeModeKey((string) $key),
                    'team' => '',
                    'team_label' => '',
                ];
            }
        }
        return $selectors;
    }

    private function normalizeModeKey(string $key): string
    {
        $key = strtolower($key);
        return $key === 'etage' ? 'etage' : 'montage';
    }

    private function resolveRenderMode($attributeValue): string
    {
        $mode = '';
        if (is_string($attributeValue)) {
            $mode = strtolower(trim($attributeValue));
        }
        /** @var string $mode */
        $mode = apply_filters('sg_mr_booking_render_mode', $mode, $attributeValue);
        return $mode === 'tabs' ? 'tabs' : 'default';
    }

    private function selectorLabel(string $region, string $key): string
    {
        switch ($key) {
            case 'montage':
                return __('Montage – Termin wählen', 'sg-mr');
            case 'etage':
                return __('Etagenlieferung – Termin wählen', 'sg-mr');
            default:
                return sprintf('%s (%s)', PostcodeHelper::regionLabel($region), strtoupper($key));
        }
    }

    /**
     * @return array<string>
     */
    private function desiredModes(int $m, int $e): array
    {
        $modes = [];
        if ($m > 0) {
            $modes[] = 'montage';
        }
        if ($e > 0) {
            $modes[] = 'etage';
        }
        if (!$modes) {
            $modes[] = 'montage';
        }
        return $modes;
    }

    /**
     * @param array<string> $modes
     * @return array<int, array<string, mixed>>
     */
    private function buildTeamSelectors(string $region, array $modes, int $maxTeams): array
    {
        $teams = $this->client->regionTeams($region);
        if (!$teams) {
            return [];
        }
        $maxTeams = max(1, min($maxTeams, count($teams)));
        $selectors = [];
        foreach ($teams as $teamKey) {
            if (count($selectors) >= $maxTeams) {
                break;
            }
            $teamKey = sanitize_key($teamKey);
            if ($teamKey === '') {
                continue;
            }
            $teamConfig = $this->client->team($teamKey);
            $teamLabel = $teamConfig['label'] ?? strtoupper($teamKey);
            $shortcode = '';
            $modeUsed = null;
            foreach ($modes as $mode) {
                $shortcode = $this->client->renderTeamShortcode($teamKey, $mode);
                if ($shortcode !== '') {
                    $modeUsed = $mode;
                    break;
                }
            }
            if ($shortcode === '') {
                $fallback = $this->client->renderTeamShortcode($teamKey, 'montage');
                if ($fallback !== '') {
                    $shortcode = $fallback;
                    $modeUsed = 'montage';
                }
            }
            if ($shortcode === '') {
                continue;
            }
            $modeUsed = $modeUsed ?? $modes[0];
            $selectors[] = [
                'key' => 'team:' . $teamKey,
                'label' => sprintf('%s – %s', $teamLabel, $this->modeLabel($modeUsed)),
                'shortcode' => $shortcode,
                'mode' => $modeUsed,
                'team' => $teamKey,
                'team_label' => $teamLabel,
            ];
        }
        return $selectors;
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @param array<string> $modes
     * @return array<int, array<string, mixed>>
     */
    private function filterSelectorsByModes(array $selectors, array $modes): array
    {
        $modes = array_values(array_unique(array_map('strval', $modes)));
        return array_values(array_filter($selectors, static function ($selector) use ($modes) {
            $mode = $selector['mode'] ?? ($selector['key'] ?? '');
            return in_array($mode, $modes, true);
        }));
    }

    private function modeLabel(string $mode): string
    {
        return $mode === 'etage'
            ? __('Etagenlieferung', 'sg-mr')
            : __('Montage', 'sg-mr');
    }

    /**
     * @return array<string, mixed>
     */
    private function regionDaysMeta(string $region): array
    {
        $allowedDays = $this->regionDayPlanner->allowedDays($region);
        $dayKeys = $this->regionDayPlanner->allowedDayKeys($region);
        $labels = $this->regionDayPlanner->allowedDayLabels($region);
        $message = '';
        if ($labels && count($labels) < 7) {
            $message = sprintf(__('Nur %s buchbar.', 'sg-mr'), implode(', ', $labels));
        }
        return [
            'allowed_days' => $allowedDays,
            'allowed_day_keys' => $dayKeys,
            'labels' => $labels,
            'message' => $message,
            'max_teams' => $this->regionDayPlanner->maxTeams($region),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @return array<string, array<string, string>>
     */
    private function teamsMeta(array $selectors): array
    {
        $meta = [];
        foreach ($selectors as $selector) {
            if (empty($selector['team'])) {
                continue;
            }
            $teamKey = (string) $selector['team'];
            if (!isset($meta[$teamKey])) {
                $meta[$teamKey] = [
                    'label' => (string) ($selector['team_label'] ?? ''),
                    'mode' => (string) ($selector['mode'] ?? 'montage'),
                    'team_label' => (string) ($selector['team_label'] ?? ''),
                ];
            }
        }
        return $meta;
    }

    private function renderSelectors(string $region, array $selectors, WC_Order $order, int $m, int $e, array $options = []): string
    {
        $renderMode = isset($options['render_mode']) ? (string) $options['render_mode'] : 'default';
        $useTabs = $renderMode === 'tabs' && count($selectors) > 1 && !empty($selectors[0]['team']);
        ob_start();
        echo '<div class="sg-booking-auto" data-region="' . esc_attr($region) . '" data-render-mode="' . esc_attr($renderMode) . '">';
        $regionLabel = PostcodeHelper::regionLabel($region);
        $serviceParts = [];
        if ($m > 0) {
            $serviceParts[] = sprintf(_n('%d Montage', '%d Montagen', $m, 'sg-mr'), $m);
        }
        if ($e > 0) {
            $serviceParts[] = sprintf(_n('%d Etagenlieferung', '%d Etagenlieferungen', $e, 'sg-mr'), $e);
        }
        if (!$serviceParts) {
            $serviceParts[] = __('Serviceleistungen unbekannt', 'sg-mr');
        }
        printf(
            '<p class="sg-booking-auto-summary">%s</p>',
            esc_html(
                sprintf(
                    /* translators: 1: order number, 2: region label, 3: service summary */
                    __('Buchung für Bestellung #%1$s (%2$s) – %3$s.', 'sg-mr'),
                    $order->get_order_number(),
                    $regionLabel,
                    implode(' · ', $serviceParts)
                )
            )
        );

        $allowedLabels = $this->regionDayPlanner->allowedDayLabels($region);
        if ($allowedLabels && count($allowedLabels) < 7) {
            echo '<p class="sg-booking-auto-days">' . esc_html(sprintf(__('Buchbar: %s', 'sg-mr'), implode(', ', $allowedLabels))) . '</p>';
        }

        if ($useTabs) {
            echo '<div class="sg-booking-auto-tabs-nav" role="tablist">';
            foreach ($selectors as $index => $selector) {
                $teamLabel = isset($selector['team_label']) && $selector['team_label'] !== '' ? (string) $selector['team_label'] : ($selector['label'] ?? '');
                $isActive = $index === 0;
                $tabAttrs = sprintf(
                    ' type="button" class="sg-booking-auto-tab%s" data-selector-key="%s" role="tab" aria-selected="%s" tabindex="%s"',
                    $isActive ? ' is-active' : '',
                    esc_attr($selector['key']),
                    $isActive ? 'true' : 'false',
                    $isActive ? '0' : '-1'
                );
                echo '<button' . $tabAttrs . '>' . esc_html($teamLabel ?: sprintf(__('Team %d', 'sg-mr'), $index + 1)) . '</button>';
            }
            echo '</div>';
        }

        foreach ($selectors as $index => $selector) {
            $mode = isset($selector['mode']) ? (string) $selector['mode'] : (string) ($selector['key'] ?? '');
            $dataAttrs = sprintf(
                ' data-selector-key="%s" data-selector-mode="%s"',
                esc_attr($selector['key']),
                esc_attr($mode)
            );
            if (!empty($selector['team'])) {
                $dataAttrs .= ' data-selector-team="' . esc_attr((string) $selector['team']) . '"';
            }
            if (!empty($selector['team_label'])) {
                $dataAttrs .= ' data-selector-team-label="' . esc_attr((string) $selector['team_label']) . '"';
            }
            $dataAttrs .= ' data-selector-source="' . esc_attr(!empty($selector['team']) ? 'teams' : 'legacy') . '"';
            $panelClasses = 'sg-booking-auto-selector';
            if ($useTabs) {
                $panelClasses .= $index === 0 ? ' is-active' : ' is-hidden';
                $dataAttrs .= ' role="tabpanel" aria-hidden="' . ($index === 0 ? 'false' : 'true') . '"';
                if ($index !== 0) {
                    $dataAttrs .= ' hidden="hidden"';
                }
            }
            echo '<div class="' . esc_attr($panelClasses) . '"' . $dataAttrs . '>';
            if (!empty($selector['label'])) {
                echo '<h3>' . esc_html($selector['label']) . '</h3>';
            }
            echo do_shortcode($selector['shortcode']);
            echo '</div>';
        }
        echo '<p class="sg-booking-auto-hint">' . esc_html__('Bitte wählen Sie das gewünschte Zeitfenster. Die Formularfelder sind bereits mit Ihren Auftragsdaten gefüllt.', 'sg-mr') . '</p>';
        echo '</div>';
        return ob_get_clean();
    }

    private function selectorsMeta(array $selectors): array
    {
        $meta = [];
        foreach ($selectors as $selector) {
            $meta[] = [
                'key' => $selector['key'],
                'label' => $selector['label'],
                'mode' => isset($selector['mode']) ? (string) $selector['mode'] : (string) $selector['key'],
                'team' => isset($selector['team']) ? (string) $selector['team'] : '',
                'team_label' => isset($selector['team_label']) ? (string) $selector['team_label'] : '',
                'calendar_id' => isset($selector['calendar_id']) ? (int) $selector['calendar_id'] : 0,
                'strategy' => isset($selector['strategy']) ? (string) $selector['strategy'] : '',
                'drive_minutes' => isset($selector['drive_minutes']) ? (int) $selector['drive_minutes'] : null,
            ];
        }
        return $meta;
    }

    private function renderError(string $message, int $status = 200): string
    {
        if ($status !== 200 && !headers_sent()) {
            status_header($status);
        }
        return '<div class="sg-booking-auto-error">' . esc_html($message) . '</div>';
    }

    private function logFailure(int $orderId, string $region, string $reason, array $extra = []): void
    {
        $context = array_merge([
            'order_id' => $orderId,
            'region' => $region,
            'reason' => $reason,
        ], $extra);
        sgmr_log('booking_page_open_failed', $context);
    }

    private function regionMetaContext(WC_Order $order): array
    {
        $context = array_filter([
            'region_source' => $order->get_meta(CartService::META_REGION_SOURCE, true),
            'region_lookup' => $order->get_meta(CartService::META_REGION_STRATEGY, true),
            'region_rule' => $order->get_meta(CartService::META_REGION_RULE, true),
            'postcode' => $order->get_meta(CartService::META_REGION_POSTCODE, true),
        ]);
        $orderRegion = sgmr_normalize_region_slug((string) $order->get_meta(CartService::META_REGION_KEY, true));
        if ($orderRegion !== '') {
            $context['order_region'] = $orderRegion;
        }
        return $context;
    }

    private function restEndpoints(): array
    {
        if (!function_exists('rest_url')) {
            return [];
        }
        $endpoints = [
            'prefill' => esc_url_raw(rest_url('sgmr/v1/fluent-booking/prefill')),
        ];
        return $endpoints;
    }

    private static function enqueueScript(): void
    {
        if (self::$scriptEnqueued) {
            return;
        }
        self::$scriptEnqueued = true;
        wp_enqueue_script(
            'sg-booking-auto',
            plugins_url('sg-booking-auto.js', dirname(__DIR__, 2) . '/sg-montagerechner.php'),
            [],
            '4.1.0',
            true
        );
    }

    private static function addPrefillInline(array $data): void
    {
        $json = wp_json_encode($data);
        if ($json) {
            wp_add_inline_script('sg-booking-auto', 'window.SG_BOOKING_PREFILL_CONFIG = ' . $json . ';', 'before');
        }
    }
}
