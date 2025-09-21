<?php

namespace SGMR\Admin;

use SGMR\Booking\FluentBookingClient;
use SGMR\Booking\PrefillManager;
use SGMR\Booking\RouterState;
use SGMR\Region\RegionDayPlanner;
use SGMR\Region\RegionResolver;
use SGMR\Routing\DistanceProvider;
use SGMR\Utils\PostcodeHelper;
use SGMR\Plugin;
use function __;
use function absint;
use function add_action;
use function add_settings_error;
use function add_settings_field;
use function add_settings_section;
use function add_submenu_page;
use function array_filter;
use function array_unique;
use function array_values;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function get_option;
use function get_term;
use function is_wp_error;
use function sanitize_key;
use function sanitize_title;
use function selected;
use function settings_errors;
use function settings_fields;
use function submit_button;
use function wp_die;
use function register_setting;
use function do_settings_sections;
use function wp_dropdown_pages;

class Settings
{
    private const OPTION_NAME = 'sgmr_router_settings';
    private const OPTION_GROUP = 'sgmr_router';
    private const OPTION_BOOKING_PAGE_ID = 'sgmr_booking_page_id';
    private const SECTION_LIMITS = 'sgmr_router_section';
    private const SECTION_PRIORITIES = 'sgmr_router_section_priorities';
    private const SECTION_CALENDARS = 'sgmr_router_section_calendars';
    private const SECTION_COUNTERS = 'sgmr_router_section_counters';

    private const REGIONS = [
        'zuerich_limmattal',
        'basel_fricktal',
        'mittelland_west',
        'aargau_sued_zentralschweiz',
    ];

    private const SERVICES = ['montage', 'etage'];
    private const TEAMS = ['t1', 't2', 't3'];

    private RouterState $routerState;

    public function __construct(
        FluentBookingClient $client,
        RegionResolver $regionResolver,
        PrefillManager $prefillManager,
        RegionDayPlanner $regionDayPlanner,
        RouterState $routerState,
        DistanceProvider $distanceProvider
    ) {
        unset($client, $regionResolver, $prefillManager, $regionDayPlanner, $distanceProvider);
        $this->routerState = $routerState;
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_init', [$this, 'initializeSettings']);
    }

    public function registerPage(): void
    {
        add_submenu_page(
            'sg-services',
            __('Disposition & Termine Variante A', 'sg-mr'),
            __('Variante A Termine', 'sg-mr'),
            'manage_woocommerce',
            'sg-services-variante-a',
            [$this, 'render']
        );
    }

    public function initializeSettings(): void
    {
        $this->ensureSettingsOption();

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitize'],
                'show_in_rest' => false,
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_BOOKING_PAGE_ID,
            [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'show_in_rest' => false,
                'default' => 0,
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'sgmr_logging_enabled',
            [
                'type' => 'boolean',
                'sanitize_callback' => static function ($value) {
                    return (int) (!empty($value));
                },
                'default' => 0,
                'show_in_rest' => false,
            ]
        );

        add_settings_section(self::SECTION_LIMITS, __('Hybrid-Router & Limits', 'sg-mr'), '__return_false', self::OPTION_GROUP);
        add_settings_field('sgmr_router_core', __('Parameter', 'sg-mr'), [$this, 'renderCoreFields'], self::OPTION_GROUP, self::SECTION_LIMITS);
        add_settings_field('sgmr_booking_page', __('Auto-Buchungsseite', 'sg-mr'), [$this, 'renderBookingPageField'], self::OPTION_GROUP, self::SECTION_LIMITS);
        add_settings_field('sgmr_logging_enabled', __('Diagnose-Logging', 'sg-mr'), [$this, 'renderLoggingField'], self::OPTION_GROUP, self::SECTION_LIMITS);

        add_settings_section(self::SECTION_PRIORITIES, __('Team-Priorisierung', 'sg-mr'), '__return_false', self::OPTION_GROUP);
        add_settings_field('sgmr_router_priorities', __('Prioritäten', 'sg-mr'), [$this, 'renderPrioritiesField'], self::OPTION_GROUP, self::SECTION_PRIORITIES);

        add_settings_section(self::SECTION_CALENDARS, __('Kalender-Zuordnung', 'sg-mr'), '__return_false', self::OPTION_GROUP);
        add_settings_field('sgmr_router_calendars', __('Kalender', 'sg-mr'), [$this, 'renderCalendarsField'], self::OPTION_GROUP, self::SECTION_CALENDARS);

        add_settings_section(self::SECTION_COUNTERS, __('Heutige Auslastung', 'sg-mr'), '__return_false', self::OPTION_GROUP);
        add_settings_field('sgmr_router_counters', __('Auslastung', 'sg-mr'), [$this, 'renderCountersField'], self::OPTION_GROUP, self::SECTION_COUNTERS);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unzureichende Berechtigungen.', 'sg-mr'), '', ['response' => 403]);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Variante A – Terminierung', 'sg-mr') . '</h1>';
        settings_errors(self::OPTION_GROUP);
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::OPTION_GROUP);
        submit_button(__('Änderungen speichern', 'sg-mr'));
        echo '</form>';
        echo '</div>';
    }

    public static function sanitize($input): array
    {
        $value = self::prepareSettings(is_array($input) ? $input : []);
        add_settings_error(self::OPTION_GROUP, 'settings_updated', __('Einstellungen gespeichert.', 'sg-mr'), 'updated');
        return $value;
    }

    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        return self::prepareSettings(is_array($stored) ? $stored : []);
    }

    public static function defaults(): array
    {
        $priorities = [];
        foreach (self::REGIONS as $region) {
            $priorities[$region] = [];
            foreach (self::SERVICES as $service) {
                $priorities[$region][$service] = ['t1', 't2'];
            }
        }

        $calendars = [];
        foreach (self::REGIONS as $region) {
            $calendars[$region] = [];
            foreach (self::SERVICES as $service) {
                $calendars[$region][$service] = ['t1' => 0, 't2' => 0, 't3' => 0];
            }
        }

        return [
            'horizon_days' => 14,
            'rr_threshold_minutes' => 20,
            'token_ttl_hours' => 96,
            'weight_gate' => 2.0,
            'montage_duration_minutes' => 120,
            'etage_duration_minutes' => 60,
            'frontend_duration_override' => true,
            'logging_extended' => false,
            'phone_category_slugs' => ['dusch-wc', 'food-center', 'boiler'],
            'priorities' => $priorities,
            'calendars' => $calendars,
        ];
    }

    private static function prepareSettings(array $input): array
    {
        $defaults = self::defaults();
        $settings = $defaults;

        $settings['horizon_days'] = self::clampInt($input['horizon_days'] ?? $defaults['horizon_days'], 1, 90);
        $settings['rr_threshold_minutes'] = self::clampInt($input['rr_threshold_minutes'] ?? $defaults['rr_threshold_minutes'], 0, 360);
        $settings['token_ttl_hours'] = self::clampInt($input['token_ttl_hours'] ?? $defaults['token_ttl_hours'], 1, 240);
        $settings['weight_gate'] = self::clampFloat($input['weight_gate'] ?? $defaults['weight_gate'], 0.0, 10.0);
        $settings['montage_duration_minutes'] = self::clampInt($input['montage_duration_minutes'] ?? $defaults['montage_duration_minutes'], 10, 600);
        $settings['etage_duration_minutes'] = self::clampInt($input['etage_duration_minutes'] ?? $defaults['etage_duration_minutes'], 10, 600);
        $settings['frontend_duration_override'] = self::normalizeFlagValue($input['frontend_duration_override'] ?? $defaults['frontend_duration_override'], (bool) $defaults['frontend_duration_override']);
        $settings['logging_extended'] = self::normalizeFlagValue($input['logging_extended'] ?? $defaults['logging_extended'], (bool) $defaults['logging_extended']);

        $settings['phone_category_slugs'] = self::sanitizePhoneSlugs($input['phone_category_slugs'] ?? $defaults['phone_category_slugs']);

        $priorities = $defaults['priorities'];
        if (isset($input['priorities']) && is_array($input['priorities'])) {
            foreach (self::REGIONS as $region) {
                foreach (self::SERVICES as $service) {
                    $rawList = $input['priorities'][$region][$service] ?? [];
                    if (!is_array($rawList)) {
                        $rawList = [$rawList];
                    }
                    $teams = [];
                    foreach ($rawList as $teamId) {
                        $teamKey = strtolower(sanitize_key((string) $teamId));
                        if (in_array($teamKey, self::TEAMS, true)) {
                            $teams[] = $teamKey;
                        }
                    }
                    if ($teams) {
                        $priorities[$region][$service] = array_values(array_unique($teams));
                    }
                }
            }
        }
        $settings['priorities'] = $priorities;

        $calendarConfig = $defaults['calendars'];
        if (isset($input['calendars']) && is_array($input['calendars'])) {
            foreach (self::REGIONS as $region) {
                foreach (self::SERVICES as $service) {
                    $raw = $input['calendars'][$region][$service] ?? [];
                    if (!is_array($raw)) {
                        $raw = [];
                    }
                    $calendarConfig[$region][$service] = [
                        't1' => isset($raw['t1']) ? absint($raw['t1']) : $calendarConfig[$region][$service]['t1'],
                        't2' => isset($raw['t2']) ? absint($raw['t2']) : $calendarConfig[$region][$service]['t2'],
                        't3' => isset($raw['t3']) ? absint($raw['t3']) : $calendarConfig[$region][$service]['t3'],
                    ];
                }
            }
        }
        $settings['calendars'] = $calendarConfig;

        return $settings;
    }

    private function ensureSettingsOption(): void
    {
        $existing = get_option(self::OPTION_NAME, null);
        if (!is_array($existing)) {
            $settings = $this->migrateLegacyOptions();
            add_option(self::OPTION_NAME, $settings, '', 'no');
            if (get_option(self::OPTION_BOOKING_PAGE_ID, null) === null) {
                add_option(self::OPTION_BOOKING_PAGE_ID, 0, '', 'no');
            }
            return;
        }

        if (get_option(self::OPTION_BOOKING_PAGE_ID, null) === null) {
            add_option(self::OPTION_BOOKING_PAGE_ID, 0, '', 'no');
        }

        $normalized = self::prepareSettings($existing);
        if ($normalized !== $existing) {
            update_option(self::OPTION_NAME, $normalized, false);
        }
    }

    private function migrateLegacyOptions(): array
    {
        $defaults = self::defaults();
        $raw = $defaults;

        $raw['horizon_days'] = get_option(Plugin::OPTION_ROUTER_HORIZON, $defaults['horizon_days']);
        $raw['rr_threshold_minutes'] = get_option(Plugin::OPTION_ROUTER_DISTANCE_THRESHOLD, $defaults['rr_threshold_minutes']);
        $raw['token_ttl_hours'] = get_option(Plugin::OPTION_TOKEN_TTL_HOURS, $defaults['token_ttl_hours']);
        $raw['weight_gate'] = get_option(Plugin::OPTION_WEIGHT_ONLINE_MAX, $defaults['weight_gate']);

        $legacyPhone = get_option(Plugin::OPTION_PHONE_ONLY_CATEGORIES, []);
        if (is_array($legacyPhone)) {
            $slugs = [];
            foreach ($legacyPhone as $item) {
                $termId = absint($item);
                if ($termId <= 0) {
                    continue;
                }
                $term = get_term($termId, 'product_cat');
                if ($term && !is_wp_error($term) && !empty($term->slug)) {
                    $slugs[] = sanitize_title($term->slug);
                }
            }
            if ($slugs) {
                $raw['phone_category_slugs'] = $slugs;
            }
        }

        $legacyPriority = get_option(Plugin::OPTION_ROUTER_PRIORITY, []);
        if (is_array($legacyPriority)) {
            foreach (self::REGIONS as $region) {
                foreach (self::SERVICES as $service) {
                    $raw['priorities'][$region][$service] = $legacyPriority[$region][$service] ?? $defaults['priorities'][$region][$service];
                }
            }
        }

        $legacyCalendars = get_option(Plugin::OPTION_ROUTER_CALENDAR_MAP, []);
        if (is_array($legacyCalendars)) {
            foreach (self::REGIONS as $region) {
                foreach (self::SERVICES as $service) {
                    $entry = $legacyCalendars[$region][$service] ?? [];
                    if (!is_array($entry)) {
                        $entry = [];
                    }
                    $raw['calendars'][$region][$service] = [
                        't1' => isset($entry['t1_id']) ? absint($entry['t1_id']) : 0,
                        't2' => isset($entry['t2_id']) ? absint($entry['t2_id']) : 0,
                        't3' => isset($entry['t3_id']) ? absint($entry['t3_id']) : 0,
                    ];
                }
            }
        }

        return self::prepareSettings($raw);
    }

    public function renderCoreFields(): void
    {
        $settings = self::getSettings();
        $phoneValue = implode(', ', $settings['phone_category_slugs']);
        $montageDuration = (int) ($settings['montage_duration_minutes'] ?? 120);
        $etageDuration = (int) ($settings['etage_duration_minutes'] ?? 60);
        $preset = 'custom';
        if ($montageDuration === 120 && $etageDuration === 60) {
            $preset = 'standard';
        } elseif ($montageDuration === 90 && $etageDuration === 30) {
            $preset = 'compact';
        }
        ?>
        <fieldset class="sgmr-router-fieldset">
            <p>
                <label for="sgmr_router_horizon"><?php esc_html_e('Planungshorizont (Tage)', 'sg-mr'); ?></label><br>
                <input type="number" min="1" max="90" id="sgmr_router_horizon" name="<?php echo esc_attr(self::OPTION_NAME); ?>[horizon_days]" value="<?php echo esc_attr((string) $settings['horizon_days']); ?>" class="small-text" />
            </p>
            <p>
                <label for="sgmr_router_threshold"><?php esc_html_e('Round-Robin Schwelle (Fahrzeit in Minuten)', 'sg-mr'); ?></label><br>
                <input type="number" min="0" max="360" id="sgmr_router_threshold" name="<?php echo esc_attr(self::OPTION_NAME); ?>[rr_threshold_minutes]" value="<?php echo esc_attr((string) $settings['rr_threshold_minutes']); ?>" class="small-text" />
            </p>
            <p>
                <label for="sgmr_router_weight"><?php esc_html_e('Gewichts-Limit für Online-Buchung (t)', 'sg-mr'); ?></label><br>
                <input type="number" min="0" max="10" step="0.1" id="sgmr_router_weight" name="<?php echo esc_attr(self::OPTION_NAME); ?>[weight_gate]" value="<?php echo esc_attr(number_format((float) $settings['weight_gate'], 1, '.', '')); ?>" class="small-text" />
            </p>
            <p>
                <label for="sgmr_router_token_ttl"><?php esc_html_e('Token Gültigkeit (Stunden)', 'sg-mr'); ?></label><br>
                <input type="number" min="1" max="240" id="sgmr_router_token_ttl" name="<?php echo esc_attr(self::OPTION_NAME); ?>[token_ttl_hours]" value="<?php echo esc_attr((string) $settings['token_ttl_hours']); ?>" class="small-text" />
            </p>
            <p>
                <label for="sgmr_router_phone_categories"><?php esc_html_e('Telefon-Pflicht (Produktkategorien, Slugs, CSV)', 'sg-mr'); ?></label><br>
                <input type="text" id="sgmr_router_phone_categories" name="<?php echo esc_attr(self::OPTION_NAME); ?>[phone_category_slugs]" value="<?php echo esc_attr($phoneValue); ?>" class="regular-text" />
                <span class="description"><?php esc_html_e('Beispiel: dusch-wc, food-center, boiler', 'sg-mr'); ?></span>
            </p>

            <hr>
            <h4><?php esc_html_e('Service-Dauern', 'sg-mr'); ?></h4>
            <p><?php esc_html_e('Steuert die Dauer je Auftrag für die automatische Terminierung und FluentBooking.', 'sg-mr'); ?></p>
            <p>
                <label><strong><?php esc_html_e('Voreinstellung', 'sg-mr'); ?></strong></label><br>
                <label style="margin-right:1em;">
                    <input type="radio" name="sgmr_router_duration_preset" value="standard" data-montage="120" data-etage="60" <?php checked('standard', $preset); ?> />
                    <?php esc_html_e('Standard (120/60)', 'sg-mr'); ?>
                </label>
                <label style="margin-right:1em;">
                    <input type="radio" name="sgmr_router_duration_preset" value="compact" data-montage="90" data-etage="30" <?php checked('compact', $preset); ?> />
                    <?php esc_html_e('Kompakt (90/30)', 'sg-mr'); ?>
                </label>
                <label>
                    <input type="radio" name="sgmr_router_duration_preset" value="custom" data-montage="" data-etage="" <?php checked('custom', $preset); ?> />
                    <?php esc_html_e('Individuell', 'sg-mr'); ?>
                </label>
            </p>
            <p>
                <label for="sgmr_duration_montage"><?php esc_html_e('Montage (Minuten pro Auftrag)', 'sg-mr'); ?></label><br>
                <input type="number" min="10" max="600" id="sgmr_duration_montage" name="<?php echo esc_attr(self::OPTION_NAME); ?>[montage_duration_minutes]" value="<?php echo esc_attr((string) $montageDuration); ?>" class="small-text" />
            </p>
            <p>
                <label for="sgmr_duration_etage"><?php esc_html_e('Etagenlieferung (Minuten pro Auftrag)', 'sg-mr'); ?></label><br>
                <input type="number" min="10" max="600" id="sgmr_duration_etage" name="<?php echo esc_attr(self::OPTION_NAME); ?>[etage_duration_minutes]" value="<?php echo esc_attr((string) $etageDuration); ?>" class="small-text" />
            </p>
            <hr>
            <h4><?php esc_html_e('FluentBooking Ausgabe & Debug', 'sg-mr'); ?></h4>
            <p><?php esc_html_e('Steuert, ob das öffentliche FluentBooking-Frontend unsere Dauer-/Slot-Anzeige nutzt und ob detaillierte Logs geschrieben werden.', 'sg-mr'); ?></p>
            <p>
                <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[frontend_duration_override]" value="0" />
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[frontend_duration_override]" value="1" <?php checked(true, (bool) $settings['frontend_duration_override']); ?> />
                    <?php esc_html_e('Fluent-Frontend: Dauer & Slot-Labels mit SGMR-Daten überschreiben', 'sg-mr'); ?>
                </label>
            </p>
            <p class="description"><?php esc_html_e('Aktivieren, um Kunden im Fluent-Frontend Start–Ende-Zeiten und konsistente Dauerangaben zu zeigen.', 'sg-mr'); ?></p>
            <p>
                <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[logging_extended]" value="0" />
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[logging_extended]" value="1" <?php checked(true, (bool) $settings['logging_extended']); ?> />
                    <?php esc_html_e('Erweitertes Logging für FluentBooking aktivieren', 'sg-mr'); ?>
                </label>
            </p>
            <p class="description"><?php esc_html_e('Schreibt zusätzliche Einträge (fluent_booking_request/response, fb_public_event_vars, status_transition) in debug.log. Für längere Zeiträume deaktivieren.', 'sg-mr'); ?></p>
            <script>
            (function () {
                if (window.sgmrDurationPresetInit) {
                    return;
                }
                window.sgmrDurationPresetInit = true;
                function applyPreset(radio) {
                    var montage = radio.getAttribute('data-montage');
                    var etage = radio.getAttribute('data-etage');
                    if (montage) {
                        var montageField = document.getElementById('sgmr_duration_montage');
                        if (montageField) {
                            montageField.value = montage;
                        }
                    }
                    if (etage) {
                        var etageField = document.getElementById('sgmr_duration_etage');
                        if (etageField) {
                            etageField.value = etage;
                        }
                    }
                }
                document.addEventListener('DOMContentLoaded', function () {
                    var radios = document.querySelectorAll('input[name="sgmr_router_duration_preset"]');
                    radios.forEach(function (radio) {
                        radio.addEventListener('change', function () {
                            if (this.checked && this.getAttribute('data-montage')) {
                                applyPreset(this);
                            }
                        });
                    });
                });
            })();
            </script>
        </fieldset>
        <?php
    }

    public function renderLoggingField(): void
    {
        $enabled = (int) get_option('sgmr_logging_enabled', 0);
        ?>
        <label for="sgmr_logging_enabled">
            <input type="checkbox" id="sgmr_logging_enabled" name="sgmr_logging_enabled" value="1" <?php checked(1, $enabled); ?> />
            <?php esc_html_e('Diagnose-Logging in debug.log aktivieren (FluentBooking & Webhook).', 'sg-mr'); ?>
        </label>
        <p class="description"><?php esc_html_e('Nur für kurzfristige Analysen aktivieren – schreibt sensible Daten ins Debug-Log.', 'sg-mr'); ?></p>
        <?php
    }

    public function renderBookingPageField(): void
    {
        $current = (int) get_option(self::OPTION_BOOKING_PAGE_ID, 0);
        $dropdown = wp_dropdown_pages([
            'name' => self::OPTION_BOOKING_PAGE_ID,
            'id' => 'sgmr_booking_page_id',
            'show_option_none' => __('— Seite auswählen —', 'sg-mr'),
            'option_none_value' => 0,
            'selected' => $current,
            'echo' => 0,
        ]);

        echo '<p>' . $dropdown . '</p>';
        echo '<p class="description">' . esc_html__('Wählen Sie die Seite mit dem Shortcode [sg_booking_auto]. Wird nichts gesetzt, sucht das System automatisch nach der ersten passenden Seite.', 'sg-mr') . '</p>';
    }

    public function renderPrioritiesField(): void
    {
        $settings = self::getSettings();
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Region', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('Leistung', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('1. Priorität', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('2. Priorität', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('3. Priorität', 'sg-mr'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (self::REGIONS as $region) :
                    $regionLabel = $this->regionLabel($region);
                    foreach (self::SERVICES as $service) :
                        $serviceLabel = $service === 'etage' ? esc_html__('Etagenlieferung', 'sg-mr') : esc_html__('Montage', 'sg-mr');
                        $current = $settings['priorities'][$region][$service] ?? ['t1', 't2'];
                ?>
                <tr>
                    <td><?php echo esc_html($regionLabel); ?></td>
                    <td><?php echo esc_html($serviceLabel); ?></td>
                    <?php for ($position = 0; $position < 3; $position++) :
                        $teamValue = $current[$position] ?? '';
                    ?>
                    <td>
                        <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[priorities][<?php echo esc_attr($region); ?>][<?php echo esc_attr($service); ?>][<?php echo esc_attr((string) $position); ?>]">
                            <option value="" <?php selected($teamValue, ''); ?>><?php esc_html_e('–', 'sg-mr'); ?></option>
                            <?php foreach (self::TEAMS as $team) : ?>
                                <option value="<?php echo esc_attr($team); ?>" <?php selected($teamValue, $team); ?>><?php echo esc_html(sprintf(__('Team %s', 'sg-mr'), strtoupper($team))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <?php endfor; ?>
                </tr>
                <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function renderCalendarsField(): void
    {
        $settings = self::getSettings();
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Region', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('Leistung', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('Kalender T1', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('Kalender T2', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('Kalender T3', 'sg-mr'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (self::REGIONS as $region) :
                    $regionLabel = $this->regionLabel($region);
                    foreach (self::SERVICES as $service) :
                        $serviceLabel = $service === 'etage' ? esc_html__('Etagenlieferung', 'sg-mr') : esc_html__('Montage', 'sg-mr');
                        $calendarRow = $settings['calendars'][$region][$service] ?? ['t1' => 0, 't2' => 0, 't3' => 0];
                ?>
                <tr>
                    <td><?php echo esc_html($regionLabel); ?></td>
                    <td><?php echo esc_html($serviceLabel); ?></td>
                    <?php foreach (self::TEAMS as $team) :
                        $value = isset($calendarRow[$team]) ? (int) $calendarRow[$team] : 0;
                    ?>
                    <td>
                        <input type="number" min="0" step="1" name="<?php echo esc_attr(self::OPTION_NAME); ?>[calendars][<?php echo esc_attr($region); ?>][<?php echo esc_attr($service); ?>][<?php echo esc_attr($team); ?>]" value="<?php echo esc_attr((string) $value); ?>" class="small-text" />
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function renderCountersField(): void
    {
        $counters = $this->routerState->exportTodayCounters();
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Kalender-ID', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('Montage', 'sg-mr'); ?></th>
                    <th><?php esc_html_e('Etagenlieferung', 'sg-mr'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$counters) : ?>
                    <tr>
                        <td colspan="3"><?php esc_html_e('Keine Buchungen für heute erfasst.', 'sg-mr'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($counters as $calendarId => $counts) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $calendarId); ?></td>
                            <td><?php echo esc_html((string) ($counts['montage'] ?? 0)); ?></td>
                            <td><?php echo esc_html((string) ($counts['etage'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function regionLabel(string $region): string
    {
        $label = PostcodeHelper::regionLabel($region);
        return $label !== '' ? $label : ucwords(str_replace('_', ' ', $region));
    }

    private static function sanitizePhoneSlugs($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value);
        }
        if (!is_array($value)) {
            $value = [];
        }
        $slugs = [];
        foreach ($value as $item) {
            $slug = sanitize_title((string) $item);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        return array_values(array_unique($slugs));
    }

    private static function normalizeFlagValue($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            return $default;
        }
        if ($value === null) {
            return $default;
        }
        return (bool) $value;
    }

    private static function clampInt($value, int $min, int $max): int
    {
        $value = absint($value);
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private static function clampFloat($value, float $min, float $max): float
    {
        $value = (float) $value;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }
}
