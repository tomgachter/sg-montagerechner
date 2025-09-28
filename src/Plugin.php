<?php

namespace SGMR;

use SGMR\Admin\Settings;
use SGMR\Admin\BookingGate;
use SGMR\Booking\BookingConfig;
use SGMR\Booking\HybridRouter;
use SGMR\Booking\RouterState;
use SGMR\Booking\PrefillManager;
use SGMR\Checkout\Fields;
use SGMR\Integrations\PrefillController;
use SGMR\Integrations\WooStatuses;
use SGMR\Routing\DistanceProvider;
use SGMR\Order\CompositeBookingController;
use SGMR\Order\Triggers;
use SGMR\Shortcodes\BookingShortcodes;
use SGMR\Shortcodes\AutoBookingShortcode;
use SGMR\Region\RegionResolver;
use SGMR\Region\RegionDayPlanner;
use SGMR\Services\CartService;
use SGMR\Services\Environment;
use SGMR\Utils\Logger;
use SGMR\Email\EmailService;
use SGMR\Assets;
use function absint;
use function add_action;
use function add_query_arg;
use function get_option;
use function remove_query_arg;
use function sanitize_key;
use function sanitize_text_field;
use function wp_doing_ajax;
use function wp_safe_redirect;
use function wp_unslash;

class Plugin
{
    public const OPTION_BOOKING_MAPPING = 'sg_booking_mapping';
    public const OPTION_REGIONS = 'sg_regions';
    public const OPTION_EMAIL_TEMPLATES = 'sg_email_templates';
    public const OPTION_MODE_OVERLAP_GUARD = 'sg_mode_overlap_guard';
    public const OPTION_LEAD_TIME_DAYS = 'sg_lead_time_days';
    public const OPTION_REGION_RULES = 'sg_region_rules';
    public const OPTION_REGION_ANCHORS = 'sg_region_anchors';
    public const OPTION_REGION_MAPPING = 'sg_region_mapping';
    public const OPTION_BOOKING_SECRET = 'sg_booking_secret';
    public const OPTION_KEEP_SELECTOR_BOOKING = 'sg_booking_keep_selector';
    public const OPTION_REGION_WEEKPLAN = 'sg_region_weekplan';
    public const OPTION_REGION_DAY_POLICY = 'sg_region_day_policy';
    public const OPTION_BOOKING_API = 'sg_booking_api';
    public const OPTION_ROUTER_HORIZON = 'sgmr_router_horizon_days';
    public const OPTION_ROUTER_DISTANCE_THRESHOLD = 'sgmr_router_distance_threshold';
    public const OPTION_ROUTER_PRIORITY = 'sgmr_router_priority';
    public const OPTION_ROUTER_CALENDAR_MAP = 'sgmr_router_calendar_map';
    public const OPTION_ROUTER_DISTANCE_MAP = 'sgmr_router_distance_map';
    public const OPTION_ROUTER_COUNTERS = 'sgmr_router_counters';
    public const OPTION_ROUTER_RR_STATE = 'sgmr_router_rr_state';
    public const OPTION_WEIGHT_ONLINE_MAX = 'sgmr_booking_weight_online_max';
    public const OPTION_PHONE_ONLY_CATEGORIES = 'sgmr_booking_phone_only_categories';
    public const OPTION_TOKEN_TTL_HOURS = 'sgmr_booking_token_ttl_hours';
    public const OPTION_BEXIO_META = 'sgmr_booking_bexio_meta';
    public const CSV_BASENAME = \SG_Montagerechner_V3::CSV_BASENAME;
 
    public const SESSION_SELECTION = \SG_Montagerechner_V3::SESSION_SEL;
    public const SESSION_POSTCODE = \SG_Montagerechner_V3::SESSION_PLZ;
    public const SESSION_COUNTRY = 'sg_mr_country';
    public const SESSION_APPOINTMENT = 'sg_mr_appt';
 
    private static ?self $instance = null;
 
    private BookingConfig $bookingClient;
    private Fields $checkoutFields;
    private Triggers $orderTriggers;
    private BookingShortcodes $shortcodes;
    private AutoBookingShortcode $autoShortcode;
    private CompositeBookingController $compositeController;
    private Settings $settings;
    private Assets $assets;
    private BookingGate $bookingGate;
    private RegionResolver $regionResolver;
    private RegionDayPlanner $regionDayPlanner;
    private PrefillManager $prefillManager;
    private HybridRouter $hybridRouter;
    private RouterState $routerState;
    private DistanceProvider $distanceProvider;
    private WooStatuses $wooStatuses;
    private string $bookingSecret = '';
    private bool $legacyParamsEnabled = true;

    private function __construct()
    {
        $legacy = \SG_Montagerechner_V3::init();
        remove_action('woocommerce_after_checkout_billing_form', [$legacy, 'checkout_render_appointment']);
        remove_action('woocommerce_checkout_process', ['SG_Montagerechner_V3', 'checkout_validate_appointment']);
        remove_action('woocommerce_checkout_create_order', ['SG_Montagerechner_V3', 'save_selection_to_order'], 15);

        $this->ensureDefaults();

        $this->bookingClient = new BookingConfig();
        $this->prefillManager = new PrefillManager();
        $this->checkoutFields = new Fields($this->bookingClient);
        $this->orderTriggers = new Triggers($this->bookingClient);
        $this->shortcodes = new BookingShortcodes($this->bookingClient);
        $this->regionDayPlanner = new RegionDayPlanner();
        $this->routerState = new RouterState();
        $this->distanceProvider = new DistanceProvider();
        $this->hybridRouter = new HybridRouter($this->regionDayPlanner, $this->routerState, $this->distanceProvider);
        $this->autoShortcode = new AutoBookingShortcode($this->bookingClient, $this->prefillManager, $this->regionDayPlanner, $this->hybridRouter);
        $this->regionResolver = new RegionResolver();
        $this->settings = new Settings($this->bookingClient, $this->regionResolver, $this->prefillManager, $this->regionDayPlanner, $this->routerState, $this->distanceProvider);
        $this->assets = new Assets();
        $this->bookingGate = new BookingGate();
        $this->compositeController = new CompositeBookingController($this->bookingClient);
        $this->wooStatuses = new WooStatuses();

        $this->bookingSecret = (string) get_option(self::OPTION_BOOKING_SECRET, '');
        $this->legacyParamsEnabled = $this->isLegacyParamsEnabled();

        CartService::boot();
        $this->checkoutFields->boot();
        $this->orderTriggers->boot();
        $this->shortcodes->boot();
        $this->autoShortcode->boot();
        $this->regionResolver->boot();
        $this->settings->boot();
        $this->assets->boot();
        $this->bookingGate->boot();
        $this->compositeController->boot();
        PrefillController::register();
        $this->wooStatuses->boot();

        add_action('sgmr_purge_counters', [$this->routerState, 'purgeOldCounters'], 10, 0);
        add_action('admin_notices', [$this->distanceProvider, 'renderAdminNotice']);

        add_filter('woocommerce_email_classes', [EmailService::class, 'register']);
        add_filter('query_vars', [$this, 'filterQueryVars']);
        add_action('template_redirect', [$this, 'redirectLegacyBookingParams'], 1);
        add_filter('sg_mr_logging_enabled', static function (bool $enabled): bool {
            if ($enabled) {
                return true;
            }
            if ((bool) get_option('sgmr_logging_enabled', 0)) {
                return true;
            }
            $settings = Settings::getSettings();
            return !empty($settings['logging_extended']);
        });
    }
 
    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function regionResolver(): RegionResolver
    {
        return $this->regionResolver;
    }

    public function regionDayPlanner(): RegionDayPlanner
    {
        return $this->regionDayPlanner;
    }

    public function prefillManager(): PrefillManager
    {
        return $this->prefillManager;
    }

    public function routerState(): RouterState
    {
        return $this->routerState;
    }

    public function distanceProvider(): DistanceProvider
    {
        return $this->distanceProvider;
    }

    public function routerSettings(): array
    {
        return Settings::getSettings();
    }

    public function regionDayPolicy(): string
    {
        return $this->regionDayPlanner->policy();
    }

    public function orderTriggers(): Triggers
    {
        return $this->orderTriggers;
    }

    public function bookingSecret(): string
    {
        if ($this->bookingSecret === '') {
            $this->bookingSecret = $this->generateSecret();
            update_option(self::OPTION_BOOKING_SECRET, $this->bookingSecret, false);
        }
        return $this->bookingSecret;
    }

    public function filterQueryVars(array $vars): array
    {
        $custom = ['region', 'order', 'sgm', 'sge', 'sig'];
        foreach ($custom as $var) {
            if (!in_array($var, $vars, true)) {
                $vars[] = $var;
            }
        }
        return $vars;
    }

    public function redirectLegacyBookingParams(): void
    {
        if (is_admin()) {
            return;
        }
        if (wp_doing_ajax()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        if (!isset($_GET['sig'])) {
            return;
        }

        $hasLegacy = false;
        $query = wp_unslash($_GET);
        if (isset($query['m'])) {
            $hasLegacy = true;
            if ($this->legacyParamsEnabled && !isset($query['sgm'])) {
                $query['sgm'] = $query['m'];
            }
        }
        if (isset($query['e'])) {
            $hasLegacy = true;
            if ($this->legacyParamsEnabled && !isset($query['sge'])) {
                $query['sge'] = $query['e'];
            }
        }

        if (!$hasLegacy) {
            return;
        }

        unset($query['m'], $query['e']);

        $allowed = array_flip(['order', 'sig', 'region', 'sgm', 'sge']);
        $sanitized = [];
        foreach ($query as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            switch ($key) {
                case 'order':
                    $sanitized[$key] = (string) absint($value);
                    break;
                case 'sgm':
                case 'sge':
                    if (!$this->legacyParamsEnabled) {
                        continue 2;
                    }
                    $sanitized[$key] = (string) max(0, (int) $value);
                    break;
                case 'region':
                    $sanitized[$key] = sanitize_key((string) $value);
                    break;
                case 'sig':
                    $sanitized[$key] = sanitize_text_field((string) $value);
                    break;
            }
        }

        $target = remove_query_arg(['m', 'e']);
        $target = add_query_arg($sanitized, $target);
        wp_safe_redirect($target, 302);
        exit;
    }

    public function legacyBookingParamsEnabled(): bool
    {
        return $this->legacyParamsEnabled;
    }

    private function isLegacyParamsEnabled(): bool
    {
        if (function_exists('sgmr_booking_legacy_params_enabled')) {
            return sgmr_booking_legacy_params_enabled();
        }
        return (bool) get_option('sgmr_booking_legacy_params_enabled', 1);
    }

    private function ensureDefaults(): void
    {
        if (get_option(self::OPTION_MODE_OVERLAP_GUARD, null) === null) {
            update_option(self::OPTION_MODE_OVERLAP_GUARD, false, true);
        }
        if (get_option(self::OPTION_LEAD_TIME_DAYS, null) === null) {
            update_option(self::OPTION_LEAD_TIME_DAYS, 2, true);
        }
        if (get_option(self::OPTION_REGION_WEEKPLAN, null) === null) {
            update_option(self::OPTION_REGION_WEEKPLAN, [], true);
        }
        if (get_option(self::OPTION_REGION_DAY_POLICY, null) === null) {
            update_option(self::OPTION_REGION_DAY_POLICY, RegionDayPlanner::POLICY_REJECT, true);
        }
        if (get_option(self::OPTION_ROUTER_COUNTERS, null) === null) {
            update_option(self::OPTION_ROUTER_COUNTERS, [], false);
        }

        if (get_option(self::OPTION_ROUTER_RR_STATE, null) === null) {
            update_option(self::OPTION_ROUTER_RR_STATE, [], false);
        }

        Settings::getSettings();

        $bexioMeta = get_option(self::OPTION_BEXIO_META, null);
        if (!is_array($bexioMeta)) {
            $bexioMeta = [];
        }
        $bexioDefaults = [
            'meta_bexio_order_url' => '',
            'meta_bexio_order_id' => '',
            'meta_bexio_delivery_url' => '',
            'meta_bexio_delivery_id' => '',
        ];
        $mergedBexio = wp_parse_args($bexioMeta, $bexioDefaults);
        if ($mergedBexio !== $bexioMeta) {
            update_option(self::OPTION_BEXIO_META, $mergedBexio, false);
        }

        $slugMap = [
            'zurich_limmattal' => 'zuerich_limmattal',
            'aargau_sued_zentral' => 'aargau_sued_zentralschweiz',
        ];

        $mapping = get_option(self::OPTION_BOOKING_MAPPING, null);
        if ($mapping === null) {
            $legacyMapping = get_option('sg_fb_mapping', []);
            if (is_array($legacyMapping) && $legacyMapping) {
                update_option(self::OPTION_BOOKING_MAPPING, $legacyMapping, true);
                delete_option('sg_fb_mapping');
                $mapping = $legacyMapping;
            }
        }
        if (!is_array($mapping)) {
            $mapping = [];
        }
        foreach ($slugMap as $legacy => $modern) {
            if (isset($mapping['regions'][$legacy])) {
                if (!isset($mapping['regions'][$modern])) {
                    $mapping['regions'][$modern] = $mapping['regions'][$legacy];
                }
                unset($mapping['regions'][$legacy]);
            }
            if (isset($mapping['region_events'][$legacy])) {
                if (!isset($mapping['region_events'][$modern])) {
                    $mapping['region_events'][$modern] = $mapping['region_events'][$legacy];
                }
                unset($mapping['region_events'][$legacy]);
            }
        }
        $mappingDefaults = [
            'teams' => [
                'team_1' => [
                    'label' => 'Montageteam 1',
                    'montage_shortcode' => '',
                    'etage_shortcode' => '',
                    'calendar_id' => '',
                    'event_montage' => 11,
                    'event_etage' => 12,
                ],
                'team_2' => [
                    'label' => 'Montageteam 2',
                    'montage_shortcode' => '',
                    'etage_shortcode' => '',
                    'calendar_id' => '',
                    'event_montage' => 13,
                    'event_etage' => 14,
                ],
            ],
            'regions' => [
                'zuerich_limmattal' => ['team_1', 'team_2'],
                'basel_fricktal' => ['team_1', 'team_2'],
                'aargau_sued_zentralschweiz' => ['team_1', 'team_2'],
                'mittelland_west' => ['team_1', 'team_2'],
            ],
            'region_events' => [
                'zuerich_limmattal' => [
                    'team_1' => ['montage' => 11, 'etage' => 12],
                    'team_2' => ['montage' => 13, 'etage' => 14],
                ],
                'basel_fricktal' => [
                    'team_1' => ['montage' => 11, 'etage' => 12],
                    'team_2' => ['montage' => 13, 'etage' => 14],
                ],
                'aargau_sued_zentralschweiz' => [
                    'team_1' => ['montage' => 11, 'etage' => 12],
                    'team_2' => ['montage' => 13, 'etage' => 14],
                ],
                'mittelland_west' => [
                    'team_1' => ['montage' => 11, 'etage' => 12],
                    'team_2' => ['montage' => 13, 'etage' => 14],
                ],
            ],
            'selectors' => [
                'zuerich_limmattal' => ['montage' => '', 'etage' => ''],
                'basel_fricktal' => ['montage' => '', 'etage' => ''],
                'aargau_sued_zentralschweiz' => ['montage' => '', 'etage' => ''],
                'mittelland_west' => ['montage' => '', 'etage' => ''],
            ],
            'prefill_map' => [
                'montage' => [],
                'etage' => [],
            ],
        ];
        $mergedMapping = wp_parse_args($mapping, $mappingDefaults);
        if (!isset($mergedMapping['selectors']) || !is_array($mergedMapping['selectors'])) {
            $mergedMapping['selectors'] = [];
        }
        foreach (array_keys($mappingDefaults['regions']) as $regionKey) {
            $existingSelectors = $mergedMapping['selectors'][$regionKey] ?? [];
            if (!is_array($existingSelectors)) {
                $existingSelectors = [];
            }
            $mergedMapping['selectors'][$regionKey] = wp_parse_args($existingSelectors, ['montage' => '', 'etage' => '']);
        }
        if (!isset($mergedMapping['prefill_map']) || !is_array($mergedMapping['prefill_map'])) {
            $mergedMapping['prefill_map'] = ['montage' => [], 'etage' => []];
        }
        foreach (['montage', 'etage'] as $mode) {
            if (!isset($mergedMapping['prefill_map'][$mode]) || !is_array($mergedMapping['prefill_map'][$mode])) {
                $mergedMapping['prefill_map'][$mode] = [];
            }
        }
        if ($mergedMapping !== $mapping) {
            update_option(self::OPTION_BOOKING_MAPPING, $mergedMapping, true);
        }

        $apiDefaults = [
            'base_url' => '',
            'token' => '',
            'timeout' => 15,
        ];
        $apiConfig = get_option(self::OPTION_BOOKING_API, null);
        if ($apiConfig === null) {
            $legacyApi = get_option('sg_fb_api', null);
            if (is_array($legacyApi) && $legacyApi) {
                update_option(self::OPTION_BOOKING_API, $legacyApi, false);
                delete_option('sg_fb_api');
                $apiConfig = $legacyApi;
            }
        }
        if (!is_array($apiConfig)) {
            update_option(self::OPTION_BOOKING_API, $apiDefaults, false);
        } else {
            $mergedApi = wp_parse_args($apiConfig, $apiDefaults);
            if ($mergedApi !== $apiConfig) {
                update_option(self::OPTION_BOOKING_API, $mergedApi, false);
            }
        }

        $regionUrls = get_option(self::OPTION_REGIONS, []);
        if (!is_array($regionUrls)) {
            $regionUrls = [];
        }
        foreach ($slugMap as $legacy => $modern) {
            if (isset($regionUrls[$legacy]) && !isset($regionUrls[$modern])) {
                $regionUrls[$modern] = $regionUrls[$legacy];
            }
            unset($regionUrls[$legacy]);
        }
        $regionPaths = [
            'zuerich_limmattal' => '/zuerich-limmattal/',
            'basel_fricktal' => '/basel-fricktal/',
            'aargau_sued_zentralschweiz' => '/aargau-sued-zentralschweiz/',
            'mittelland_west' => '/mittelland-west/',
        ];
        foreach ($regionPaths as $key => $path) {
            if (empty($regionUrls[$key])) {
                $regionUrls[$key] = home_url($path);
            }
        }
        update_option(self::OPTION_REGIONS, $regionUrls, true);
        $currentTemplates = get_option(self::OPTION_EMAIL_TEMPLATES, []);
        if (!is_array($currentTemplates)) {
            $currentTemplates = [];
        }
        $defaults = $this->emailDefaults();
        $merged = array_merge($defaults, $currentTemplates);
        if ($merged !== $currentTemplates) {
            update_option(self::OPTION_EMAIL_TEMPLATES, $merged, true);
        }
        if (get_option('sg_onsite_buffer_minutes', null) === null) {
            update_option('sg_onsite_buffer_minutes', 15, true);
        }

        $resolver = new RegionResolver();
        if (get_option(self::OPTION_REGION_RULES, null) === null) {
            update_option(self::OPTION_REGION_RULES, $resolver->defaultRulesList(), false);
        }
        if (get_option(self::OPTION_REGION_ANCHORS, null) === null) {
            update_option(self::OPTION_REGION_ANCHORS, $resolver->defaultAnchorsList(), false);
        }
        if (get_option(self::OPTION_KEEP_SELECTOR_BOOKING, null) === null) {
            $legacyKeep = get_option('sg_fb_keep_selector', null);
            if ($legacyKeep !== null) {
                update_option(self::OPTION_KEEP_SELECTOR_BOOKING, (bool) $legacyKeep, false);
                delete_option('sg_fb_keep_selector');
            } else {
                update_option(self::OPTION_KEEP_SELECTOR_BOOKING, false, false);
            }
        }
        $mappingOption = get_option(self::OPTION_REGION_MAPPING, null);
        if (!is_array($mappingOption)) {
            update_option(self::OPTION_REGION_MAPPING, [
                'updated_at' => null,
                'entries' => [],
                'stats' => [
                    'total' => 0,
                    'per_region' => [],
                    'fallback_total' => 0,
                    'fallback_per_region' => [],
                    'strategy_counts' => [],
                    'by_rule' => [],
                    'stale' => true,
                ],
            ], false);
        }
        if (!get_option(self::OPTION_BOOKING_SECRET)) {
            update_option(self::OPTION_BOOKING_SECRET, $this->generateSecret(), false);
        }
        if (get_option('sgmr_booking_legacy_params_enabled', null) === null) {
            update_option('sgmr_booking_legacy_params_enabled', 1, false);
        }
    }

    /**
     * @return array<int>
     */
    private function generateSecret(int $length = 64): string
    {
        if (!function_exists('wp_generate_password')) {
            @require_once ABSPATH . WPINC . '/pluggable.php';
        }
        if (function_exists('wp_generate_password')) {
            return wp_generate_password($length, false, false);
        }
        try {
            $bytes = random_bytes(max(1, (int) ceil($length / 2)));
            return substr(bin2hex($bytes), 0, $length);
        } catch (\Exception $exception) {
            return substr(hash('sha256', uniqid('', true)), 0, $length);
        }
    }

    private function emailDefaults(): array
    {
        $windowNote = __('Bitte stellen Sie sicher, dass Sie innerhalb des gewählten 2-Stunden-Fensters anwesend sind.', 'sg-mr');
        return [
            'online_instant' => [
                'subject' => __('Ihr Termin zur Montage/Etagenlieferung – jetzt buchen (Bestellung {{order_number}})', 'sg-mr'),
                'html' => '<p>Guten Tag {{customer_first_name}},</p><p>Ihre Ware ist verfügbar.</p><p><a href="{{link_url}}">Termin jetzt online buchen</a></p><p>Hinweis: Buchungen sind mindestens 2 Werktage im Voraus möglich.</p>',
            ],
            'online_arrived' => [
                'subject' => __('Ihre Ware ist eingetroffen – bitte Termin buchen (Bestellung {{order_number}})', 'sg-mr'),
                'html' => '<p>Guten Tag {{customer_first_name}},</p><p>Ihre Ware ist eingetroffen.</p><p><a href="{{link_url}}">Termin online reservieren</a></p>',
            ],
            'offline_internal' => [
                'subject' => __('Telefonische Terminvereinbarung nötig – Bestellung {{order_number}}', 'sg-mr'),
                'html' => '<p><strong>Aktion erforderlich:</strong> Kunde wünscht <em>telefonische</em> Terminvereinbarung.</p>' .
                    '<ul>' .
                    '<li>Bestellung: #{{order_number}}</li>' .
                    '<li>Kunde: {{customer_first_name}}</li>' .
                    '<li>Kontakt: {{customer_phone}}</li>' .
                    '<li>Region: {{region_label}}</li>' .
                    '</ul>' .
                    '<p>Bitte telefonisch Termin vergeben und im Teamkalender eintragen.</p>',
            ],
            'planning_offline' => [
                'subject' => __('Terminierung telefonisch (Bestellung {{order_number}})', 'sg-mr'),
                'html' => '<p>Wir melden uns telefonisch zur Terminvereinbarung.</p>',
            ],
            'paid' => [
                'subject' => __('Zahlung eingegangen – Bestellung {{order_number}}', 'sg-mr'),
                'html' => '<p>Zahlung eingegangen – wir planen jetzt.</p>',
            ],
            'ordered' => [
                'subject' => __('Ware bestellt – Bestellung {{order_number}}', 'sg-mr'),
                'html' => '<p>Ihre Ware wurde beim Hersteller bestellt.</p>',
            ],
            'finished' => [
                'subject' => __('Montage abgeschlossen – Bestellung {{order_number}}', 'sg-mr'),
                'html' => '<p>Montage/Etagenlieferung abgeschlossen. Vielen Dank!</p>',
            ],
            'awaiting_payment' => [
                'subject' => __('Zahlung offen – Bestellung {{order_number}}', 'sg-mr'),
                'html' => '<p>Bitte begleichen Sie Ihre Zahlung, damit wir mit der Planung beginnen können.</p>',
            ],
        ];
    }
}

class_alias(__NAMESPACE__ . '\\Plugin', 'Sanigroup\\Montagerechner\\Plugin');
