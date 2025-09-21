<?php

namespace SGMR\Region;

use SGMR\Plugin;
use function __;

class RegionResolver
{
    private const CSV_TRANSIENT = 'sgmr_region_csv_rows_v1';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $csvRows = null;

    public function boot(): void
    {
        \add_action('admin_post_sgmr_region_mapping_rebuild', [$this, 'handleMappingRequest']);
    }

    public function handleMappingRequest(): void
    {
        if (!\current_user_can('manage_woocommerce')) {
            \wp_die(__('Keine Berechtigung.', 'sg-mr'));
        }
        \check_admin_referer('sgmr_region_mapping');
        $result = $this->rebuildCache();
        $query = [
            'page' => 'sg-services-variante-a',
            'sgmr_region_mapping' => 'updated',
            'mapped_total' => $result['stats']['total'] ?? 0,
            'mapped_fallback' => $result['stats']['fallback_total'] ?? 0,
        ];
        \wp_safe_redirect(\add_query_arg($query, \admin_url('admin.php')));
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuildCache(): array
    {
        $this->csvRows = null;
        \delete_transient(self::CSV_TRANSIENT);
        $rows = $this->loadCsvRows();
        $engine = new AssignmentEngine($this->getRules(), $this->getAnchors());

        $entries = [];
        $stats = [
            'total' => 0,
            'per_region' => [],
            'fallback_total' => 0,
            'fallback_per_region' => [],
            'strategy_counts' => [],
            'by_rule' => [],
        ];

        foreach ($rows as $plz => $row) {
            $assignment = $engine->assign($row);
            $region = $assignment['region'] ?? '';
            $strategy = $assignment['strategy'] ?? 'none';
            $ruleId = $assignment['rule'] ?? null;

            $entries[$plz] = [
                'region' => $region,
                'strategy' => $strategy,
                'rule' => $ruleId,
            ];

            $stats['total']++;
            if ($region !== '') {
                if (!isset($stats['per_region'][$region])) {
                    $stats['per_region'][$region] = 0;
                }
                $stats['per_region'][$region]++;
            }
            if (!isset($stats['strategy_counts'][$strategy])) {
                $stats['strategy_counts'][$strategy] = 0;
            }
            $stats['strategy_counts'][$strategy]++;
            if ($ruleId) {
                if (!isset($stats['by_rule'][$ruleId])) {
                    $stats['by_rule'][$ruleId] = 0;
                }
                $stats['by_rule'][$ruleId]++;
            }
            if ($strategy === 'fallback') {
                $stats['fallback_total']++;
                if ($region !== '') {
                    if (!isset($stats['fallback_per_region'][$region])) {
                        $stats['fallback_per_region'][$region] = 0;
                    }
                    $stats['fallback_per_region'][$region]++;
                }
            }
        }

        $stats['stale'] = false;

        $payload = [
            'updated_at' => \current_time('mysql'),
            'entries' => $entries,
            'stats' => $stats,
        ];

        update_option(Plugin::OPTION_REGION_MAPPING, $payload, false);

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRules(): array
    {
        $stored = \get_option(Plugin::OPTION_REGION_RULES, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $defaults = $this->defaultRules();
        $combined = [];
        $index = [];
        foreach ($stored as $rule) {
            if (empty($rule['id'])) {
                continue;
            }
            $index[$rule['id']] = $this->normaliseRule($rule);
        }
        foreach ($defaults as $rule) {
            $id = $rule['id'];
            if (isset($index[$id])) {
                $merged = array_merge($rule, $index[$id]);
                $combined[] = $this->normaliseRule($merged);
                unset($index[$id]);
            } else {
                $combined[] = $this->normaliseRule($rule);
            }
        }
        foreach ($index as $rule) {
            $combined[] = $this->normaliseRule($rule);
        }
        return $combined;
    }

    /**
     * @return array<string, array<string, float>>
     */
    public function getAnchors(): array
    {
        $stored = \get_option(Plugin::OPTION_REGION_ANCHORS, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $defaults = $this->defaultAnchors();
        $anchors = [];
        foreach ($defaults as $slug => $data) {
            if (isset($stored[$slug]) && is_array($stored[$slug])) {
                $merged = array_merge($data, $stored[$slug]);
                $anchors[$slug] = $this->normaliseAnchor($merged);
            } else {
                $anchors[$slug] = $this->normaliseAnchor($data);
            }
        }
        foreach ($stored as $slug => $data) {
            $slug = \sanitize_key($slug);
            if (!isset($anchors[$slug]) && is_array($data)) {
                $anchors[$slug] = $this->normaliseAnchor($data);
            }
        }
        return $anchors;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    public function sanitizeRules(array $input): array
    {
        $existing = $this->getRules();
        $output = [];
        foreach ($existing as $rule) {
            $id = $rule['id'];
            $posted = $input[$id] ?? [];
            $rule['enabled'] = !empty($posted['enabled']);
            $rule['priority'] = isset($posted['priority']) ? (int) $posted['priority'] : $rule['priority'];
            $rule['label'] = \sanitize_text_field($posted['label'] ?? $rule['label']);
            if ($rule['type'] !== 'fallback') {
                $rule['region'] = \sanitize_key($posted['region'] ?? $rule['region']);
            }

            switch ($rule['type']) {
                case 'canton':
                    $rule['config']['kanton'] = $this->sanitizeList($posted['kanton'] ?? $rule['config']['kanton'] ?? []);
                    break;
                case 'plz_prefix':
                    $rule['config']['prefixes'] = $this->sanitizeList($posted['prefixes'] ?? $rule['config']['prefixes'] ?? []);
                    break;
                case 'metric':
                    $rule['config']['kanton'] = $this->sanitizeList($posted['kanton'] ?? $rule['config']['kanton'] ?? []);
                    $rule['config']['fahrzeit_min'] = $this->sanitizeRange($posted['fahrzeit_min'] ?? $rule['config']['fahrzeit_min'] ?? []);
                    $rule['config']['distanz_km'] = $this->sanitizeRange($posted['distanz_km'] ?? $rule['config']['distanz_km'] ?? []);
                    break;
                case 'fallback':
                default:
                    break;
            }

            $output[] = $this->normaliseRule($rule);
        }
        return $output;
    }

    /**
     * @param array<string, array<string, mixed>> $input
     * @return array<string, array<string, float>>
     */
    public function sanitizeAnchors(array $input): array
    {
        $anchors = $this->getAnchors();
        foreach ($anchors as $slug => &$anchor) {
            $posted = $input[$slug] ?? [];
            if (array_key_exists('lat', $posted)) {
                if ($posted['lat'] === '' || $posted['lat'] === null) {
                    unset($anchor['lat']);
                } else {
                    $anchor['lat'] = (float) $posted['lat'];
                }
            }
            if (array_key_exists('lng', $posted)) {
                if ($posted['lng'] === '' || $posted['lng'] === null) {
                    unset($anchor['lng']);
                } else {
                    $anchor['lng'] = (float) $posted['lng'];
                }
            }
            if (array_key_exists('radius_km', $posted)) {
                if ($posted['radius_km'] === '' || $posted['radius_km'] === null) {
                    unset($anchor['radius_km']);
                } else {
                    $anchor['radius_km'] = (float) $posted['radius_km'];
                }
            }
        }
        unset($anchor);
        return $anchors;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMapping(): array
    {
        $mapping = \get_option(Plugin::OPTION_REGION_MAPPING, []);
        if (!is_array($mapping)) {
            return [];
        }
        if (!isset($mapping['stats']) || !is_array($mapping['stats'])) {
            $mapping['stats'] = [];
        }
        if (!array_key_exists('stale', $mapping['stats'])) {
            $mapping['stats']['stale'] = empty($mapping['updated_at']);
        }
        return $mapping;
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        $mapping = $this->getMapping();
        $entries = $mapping['entries'] ?? [];
        $perRegion = [];
        $fallbackPerRegion = [];
        $aliasMap = [
            'zurich_limmattal' => 'zuerich_limmattal',
            'aargau_sued_zentral' => 'aargau_sued_zentralschweiz',
        ];

        foreach ($entries as $plz => $row) {
            $region = \sanitize_key($row['region'] ?? '');
            if (isset($aliasMap[$region])) {
                $region = $aliasMap[$region];
            }
            if (!isset($perRegion[$region])) {
                $perRegion[$region] = [];
            }
            $perRegion[$region][] = $plz;
            if (($row['strategy'] ?? '') === 'fallback') {
                if (!isset($fallbackPerRegion[$region])) {
                    $fallbackPerRegion[$region] = 0;
                }
                $fallbackPerRegion[$region]++;
            }
        }

        return [
            'updated_at' => $mapping['updated_at'] ?? null,
            'per_region' => $perRegion,
            'fallback_per_region' => $fallbackPerRegion,
            'stats' => $mapping['stats'] ?? [],
        ];
    }

    /**
     * @param string $plz
     * @return array<string, mixed>|null
     */
    public function getPostcodeRecord(string $plz): ?array
    {
        $plz = preg_replace('/\D/', '', $plz);
        if ($plz === '') {
            return null;
        }
        $rows = $this->loadCsvRows();
        if (!isset($rows[$plz])) {
            return null;
        }
        $row = $rows[$plz];
        $mapping = $this->getMapping();
        $region = '';
        $strategy = 'none';
        $rule = null;
        $aliasMap = [
            'zurich_limmattal' => 'zuerich_limmattal',
            'aargau_sued_zentral' => 'aargau_sued_zentralschweiz',
        ];

        if (!empty($mapping['entries'][$plz])) {
            $entry = $mapping['entries'][$plz];
            $region = \sanitize_key($entry['region'] ?? '');
            $strategy = \sanitize_key($entry['strategy'] ?? 'cache');
            $rule = $entry['rule'] ?? null;
            $lookup = 'cache';
            if (isset($aliasMap[$region])) {
                $region = $aliasMap[$region];
            }
        } else {
            $assignment = (new AssignmentEngine($this->getRules(), $this->getAnchors()))->assign($row);
            $region = $assignment['region'];
            $strategy = $assignment['strategy'];
            $rule = $assignment['rule'];
            if (isset($aliasMap[$region])) {
                $region = $aliasMap[$region];
            }
            $lookup = 'rae';
            $this->memoizeMapping($plz, $assignment);
        }

        return [
            'plz' => $plz,
            'minutes' => isset($row['fahrzeit_min']) ? (int) round((float) $row['fahrzeit_min']) : null,
            'fahrzeit_min' => $row['fahrzeit_min'] ?? null,
            'distanz_km' => $row['distanz_km'] ?? null,
            'kanton' => $row['kanton'] ?? '',
            'region' => $region,
            'strategy' => $strategy,
            'lookup' => $lookup,
            'rule' => $rule,
            'row' => $row,
        ];
    }

    /**
     * @param array<string, mixed> $assignment
     */
    private function memoizeMapping(string $plz, array $assignment): void
    {
        $mapping = $this->getMapping();
        if (!isset($mapping['entries']) || !is_array($mapping['entries'])) {
            $mapping['entries'] = [];
        }
        $mapping['entries'][$plz] = [
            'region' => isset($assignment['region']) ? \sanitize_key((string) $assignment['region']) : '',
            'strategy' => isset($assignment['strategy']) ? \sanitize_key((string) $assignment['strategy']) : 'rae',
            'rule' => $assignment['rule'] ?? null,
        ];
        if (!isset($mapping['stats']) || !is_array($mapping['stats'])) {
            $mapping['stats'] = [];
        }
        $mapping['stats']['stale'] = true;
        update_option(Plugin::OPTION_REGION_MAPPING, $mapping, false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadCsvRows(): array
    {
        if ($this->csvRows !== null) {
            return $this->csvRows;
        }
        $cached = \get_transient(self::CSV_TRANSIENT);
        if (is_array($cached)) {
            $this->csvRows = $cached;
            return $cached;
        }

        $rows = [];
        $file = $this->csvFilePath();
        if ($file && file_exists($file) && is_readable($file)) {
            $handle = fopen($file, 'r');
            if ($handle) {
                $headerLine = fgets($handle);
                if ($headerLine !== false) {
                    $delimiter = $this->detectDelimiter($headerLine);
                    $header = array_map('trim', str_getcsv($headerLine, $delimiter));
                    $map = $this->mapHeader($header);
                    while (($line = fgets($handle)) !== false) {
                        $cols = str_getcsv($line, $delimiter);
                        $plz = preg_replace('/\D/', '', $cols[$map['plz']] ?? '');
                        if (!$plz) {
                            continue;
                        }
                        $rows[$plz] = [
                            'plz' => $plz,
                            'ort' => isset($map['ort']) ? trim($cols[$map['ort']] ?? '') : '',
                            'kanton' => isset($map['kanton']) ? strtoupper(trim($cols[$map['kanton']] ?? '')) : '',
                            'fahrzeit_min' => isset($map['fahrzeit_min']) ? $this->toFloat($cols[$map['fahrzeit_min']] ?? null) : null,
                            'distanz_km' => isset($map['distanz_km']) ? $this->toFloat($cols[$map['distanz_km']] ?? null) : null,
                            'lat' => isset($map['lat']) ? $this->toFloat($cols[$map['lat']] ?? null) : null,
                            'lng' => isset($map['lng']) ? $this->toFloat($cols[$map['lng']] ?? null) : null,
                        ];
                    }
                }
                fclose($handle);
            }
        }

        \set_transient(self::CSV_TRANSIENT, $rows, DAY_IN_SECONDS);
        $this->csvRows = $rows;
        return $rows;
    }

    private function csvFilePath(): ?string
    {
        $uploads = \wp_upload_dir();
        $uploadFile = isset($uploads['basedir']) ? \trailingslashit($uploads['basedir']) . Plugin::CSV_BASENAME : '';
        if ($uploadFile && file_exists($uploadFile)) {
            return $uploadFile;
        }
        $pluginFile = \trailingslashit(dirname(__DIR__, 2)) . Plugin::CSV_BASENAME;
        if (file_exists($pluginFile)) {
            return $pluginFile;
        }
        return null;
    }

    /**
     * @param string|null $value
     */
    private function toFloat($value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = str_replace([' ', ','], ['', '.'], (string) $value);
        if ($value === '') {
            return null;
        }
        return (float) $value;
    }

    /**
     * @param string $header
     */
    private function detectDelimiter(string $header): string
    {
        $comma = substr_count($header, ',');
        $semi = substr_count($header, ';');
        return $semi > $comma ? ';' : ',';
    }

    /**
     * @param array<int, string> $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $map = [
            'plz' => 0,
        ];
        foreach ($header as $idx => $name) {
            $key = strtolower(trim($name));
            if (strpos($key, 'ort') !== false) {
                $map['ort'] = $idx;
            }
            if (strpos($key, 'kant') !== false) {
                $map['kanton'] = $idx;
            }
            if (strpos($key, 'fahr') !== false || strpos($key, 'minute') !== false) {
                $map['fahrzeit_min'] = $idx;
            }
            if (strpos($key, 'dist') !== false || strpos($key, 'km') !== false) {
                $map['distanz_km'] = $idx;
            }
            if (strpos($key, 'lat') !== false) {
                $map['lat'] = $idx;
            }
            if (strpos($key, 'lon') !== false || strpos($key, 'lng') !== false) {
                $map['lng'] = $idx;
            }
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function normaliseRule(array $rule): array
    {
        $rule['id'] = isset($rule['id']) ? \sanitize_key((string) $rule['id']) : uniqid('rule_', true);
        $rule['label'] = isset($rule['label']) ? \sanitize_text_field($rule['label']) : $rule['id'];
        $rule['type'] = isset($rule['type']) ? \sanitize_key((string) $rule['type']) : 'canton';
        $rule['region'] = isset($rule['region']) ? \sanitize_key((string) $rule['region']) : '';
        $rule['priority'] = isset($rule['priority']) ? (int) $rule['priority'] : 999;
        $rule['enabled'] = !empty($rule['enabled']);
        $rule['config'] = is_array($rule['config'] ?? null) ? $rule['config'] : [];
        return $rule;
    }

    /**
     * @param array<string, mixed> $anchor
     * @return array<string, float>
     */
    private function normaliseAnchor(array $anchor): array
    {
        $output = [];
        if (isset($anchor['lat'])) {
            $output['lat'] = (float) $anchor['lat'];
        }
        if (isset($anchor['lng'])) {
            $output['lng'] = (float) $anchor['lng'];
        }
        if (isset($anchor['radius_km'])) {
            $output['radius_km'] = (float) $anchor['radius_km'];
        }
        return $output;
    }

    /**
     * @param string|array<int, string> $value
     * @return array<int, string>
     */
    private function sanitizeList($value): array
    {
        if (is_array($value)) {
            $list = $value;
        } else {
            $list = array_map('trim', explode(',', (string) $value));
        }
        $list = array_filter(array_map(static function ($item) {
            return strtoupper(\sanitize_text_field($item));
        }, $list));
        return array_values(array_unique($list));
    }

    /**
     * @param mixed $value
     * @return array<string, float>
     */
    private function sanitizeRange($value): array
    {
        $range = is_array($value) ? $value : [];
        $out = [];
        if (isset($range['min']) && $range['min'] !== '') {
            $out['min'] = (float) $range['min'];
        }
        if (isset($range['max']) && $range['max'] !== '') {
            $out['max'] = (float) $range['max'];
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultRules(): array
    {
        return [
            [
                'id' => 'rule_canton_zh',
                'label' => __('Kanton ZH → Zürich/Limmattal', 'sg-mr'),
                'type' => 'canton',
                'priority' => 1,
                'enabled' => true,
                'region' => 'zuerich_limmattal',
                'config' => ['kanton' => ['ZH']],
            ],
            [
                'id' => 'rule_canton_bs_bl',
                'label' => __('Kanton BS/BL → Basel/Fricktal', 'sg-mr'),
                'type' => 'canton',
                'priority' => 2,
                'enabled' => true,
                'region' => 'basel_fricktal',
                'config' => ['kanton' => ['BS', 'BL']],
            ],
            [
                'id' => 'rule_canton_be_so_fr',
                'label' => __('Kanton BE/SO/FR → Mittelland West', 'sg-mr'),
                'type' => 'canton',
                'priority' => 3,
                'enabled' => true,
                'region' => 'mittelland_west',
                'config' => ['kanton' => ['BE', 'SO', 'FR']],
            ],
            [
                'id' => 'rule_plz_52',
                'label' => __('PLZ 52* → Aargau Süd/Zentralschweiz', 'sg-mr'),
                'type' => 'plz_prefix',
                'priority' => 4,
                'enabled' => true,
                'region' => 'aargau_sued_zentralschweiz',
                'config' => ['prefixes' => ['52']],
            ],
            [
                'id' => 'rule_metric_ag_fast',
                'label' => __('Kanton AG ≤ 35 Min → Aargau Süd/Zentralschweiz', 'sg-mr'),
                'type' => 'metric',
                'priority' => 5,
                'enabled' => true,
                'region' => 'aargau_sued_zentralschweiz',
                'config' => [
                    'kanton' => ['AG'],
                    'fahrzeit_min' => ['max' => 35],
                ],
            ],
            [
                'id' => 'rule_fallback_anchor',
                'label' => __('Fallback: Nächster Ankerpunkt', 'sg-mr'),
                'type' => 'fallback',
                'priority' => 999,
                'enabled' => true,
                'region' => 'fallback',
                'config' => [],
            ],
        ];
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function defaultAnchors(): array
    {
        return [
            'zuerich_limmattal' => ['lat' => 47.388, 'lng' => 8.53, 'radius_km' => 35],
            'basel_fricktal' => ['lat' => 47.557, 'lng' => 7.588, 'radius_km' => 35],
            'aargau_sued_zentralschweiz' => ['lat' => 47.37, 'lng' => 8.167, 'radius_km' => 30],
            'mittelland_west' => ['lat' => 47.17, 'lng' => 7.3, 'radius_km' => 40],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaultRulesList(): array
    {
        return $this->defaultRules();
    }

    /**
     * @return array<string, array<string, float>>
     */
    public function defaultAnchorsList(): array
    {
        return $this->defaultAnchors();
    }
}
