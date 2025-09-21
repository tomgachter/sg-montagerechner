<?php

namespace SGMR\Region;

class AssignmentEngine
{
    /** @var array<int, array<string, mixed>> */
    private array $rules;
    /** @var array<string, array<string, float>> */
    private array $anchors;

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array<string, array<string, float>> $anchors
     */
    public function __construct(array $rules, array $anchors)
    {
        $this->rules = $this->normaliseRules($rules);
        $this->anchors = $this->normaliseAnchors($anchors);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{region:string, rule:?string, strategy:string, matched:array<string, mixed>}
     */
    public function assign(array $row): array
    {
        $rules = $this->rules;
        usort($rules, static function ($a, $b) {
            return ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999);
        });

        $result = [
            'region' => '',
            'rule' => null,
            'strategy' => 'none',
            'matched' => [],
        ];

        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            $type = $rule['type'];
            $region = '';
            $matched = [];
            switch ($type) {
                case 'canton':
                    if ($this->matchesCanton($rule, $row)) {
                        $region = $rule['region'];
                        $matched = ['type' => 'canton'];
                    }
                    break;
                case 'plz_prefix':
                    if ($this->matchesPlzPrefix($rule, $row)) {
                        $region = $rule['region'];
                        $matched = ['type' => 'plz_prefix'];
                    }
                    break;
                case 'metric':
                    if ($this->matchesMetric($rule, $row)) {
                        $region = $rule['region'];
                        $matched = ['type' => 'metric'];
                    }
                    break;
                case 'fallback':
                    $region = $this->fallbackRegion($row, $matched);
                    break;
            }

            if ($region !== '') {
                $result['region'] = $region;
                $result['rule'] = $rule['id'];
                $result['strategy'] = $type;
                $result['matched'] = $matched;
                return $result;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $row
     */
    private function matchesCanton(array $rule, array $row): bool
    {
        $allowed = $rule['config']['kanton'] ?? [];
        if (!$allowed) {
            return false;
        }
        $kanton = strtoupper((string) ($row['kanton'] ?? ''));
        return in_array($kanton, $allowed, true);
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $row
     */
    private function matchesPlzPrefix(array $rule, array $row): bool
    {
        $prefixes = $rule['config']['prefixes'] ?? [];
        if (!$prefixes) {
            return false;
        }
        $plz = (string) ($row['plz'] ?? '');
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && strpos($plz, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $row
     */
    private function matchesMetric(array $rule, array $row): bool
    {
        $config = $rule['config'];
        if (!empty($config['kanton'])) {
            $kanton = strtoupper((string) ($row['kanton'] ?? ''));
            if (!in_array($kanton, (array) $config['kanton'], true)) {
                return false;
            }
        }

        if (isset($config['fahrzeit_min'])) {
            $value = (float) ($row['fahrzeit_min'] ?? 9999);
            $range = (array) $config['fahrzeit_min'];
            if (isset($range['min']) && $value < (float) $range['min']) {
                return false;
            }
            if (isset($range['max']) && $value > (float) $range['max']) {
                return false;
            }
        }

        if (isset($config['distanz_km'])) {
            $value = (float) ($row['distanz_km'] ?? 9999);
            $range = (array) $config['distanz_km'];
            if (isset($range['min']) && $value < (float) $range['min']) {
                return false;
            }
            if (isset($range['max']) && $value > (float) $range['max']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $matched
     */
    private function fallbackRegion(array $row, array &$matched): string
    {
        if (!$this->anchors) {
            return '';
        }

        $lat = isset($row['lat']) ? (float) $row['lat'] : null;
        $lng = isset($row['lng']) ? (float) $row['lng'] : null;
        $dist = isset($row['distanz_km']) ? (float) $row['distanz_km'] : null;

        $bestRegion = '';
        $bestScore = INF;

        foreach ($this->anchors as $region => $anchor) {
            $score = INF;
            if ($lat !== null && $lng !== null && isset($anchor['lat'], $anchor['lng'])) {
                $score = $this->haversine($lat, $lng, (float) $anchor['lat'], (float) $anchor['lng']);
            } elseif ($dist !== null && isset($anchor['radius_km'])) {
                $score = abs((float) $anchor['radius_km'] - $dist);
            } elseif ($bestRegion === '') {
                $score = 0.0;
            }
            if ($score < $bestScore) {
                $bestRegion = $region;
                $bestScore = $score;
            }
        }

        if ($bestRegion) {
            $matched = [
                'type' => 'fallback',
                'score' => $bestScore,
            ];
        }

        return $bestRegion;
    }

    private function haversine(float $latFrom, float $lonFrom, float $latTo, float $lonTo): float
    {
        $earthRadius = 6371; // km
        $latFromRad = deg2rad($latFrom);
        $latToRad = deg2rad($latTo);
        $latDelta = deg2rad($latTo - $latFrom);
        $lonDelta = deg2rad($lonTo - $lonFrom);

        $a = sin($latDelta / 2) ** 2 + cos($latFromRad) * cos($latToRad) * sin($lonDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    private function normaliseRules(array $rules): array
    {
        $normalised = [];
        foreach ($rules as $rule) {
            $rule['id'] = isset($rule['id']) ? \sanitize_key((string) $rule['id']) : uniqid('rule_', true);
            $rule['type'] = isset($rule['type']) ? \sanitize_key((string) $rule['type']) : 'canton';
            $rule['region'] = isset($rule['region']) ? \sanitize_key((string) $rule['region']) : '';
            $rule['priority'] = isset($rule['priority']) ? (int) $rule['priority'] : 999;
            $rule['enabled'] = !empty($rule['enabled']);
            $rule['config'] = is_array($rule['config'] ?? null) ? $rule['config'] : [];
            $normalised[] = $rule;
        }
        return $normalised;
    }

    /**
     * @param array<string, array<string, float>> $anchors
     * @return array<string, array<string, float>>
     */
    private function normaliseAnchors(array $anchors): array
    {
        $normalised = [];
        foreach ($anchors as $slug => $config) {
            $slug = \sanitize_key((string) $slug);
            $lat = isset($config['lat']) ? (float) $config['lat'] : null;
            $lng = isset($config['lng']) ? (float) $config['lng'] : null;
            $radius = isset($config['radius_km']) ? (float) $config['radius_km'] : null;
            $normalised[$slug] = array_filter([
                'lat' => $lat,
                'lng' => $lng,
                'radius_km' => $radius,
            ], static function ($value) {
                return $value !== null;
            });
        }
        return $normalised;
    }
}
