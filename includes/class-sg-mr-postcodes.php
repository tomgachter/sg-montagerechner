<?php
if (!defined('ABSPATH')) {
    exit;
}

class SG_MR_Postcode_Manager
{
    private const CSV_BASENAME = 'sanigroup_postcodes_minutes.csv';
    public const REGION_LABELS = [
        'zurich_limmattal'    => 'Zürich/Limmattal', // Legacy
        'zuerich_limmattal'   => 'Zürich/Limmattal',
        'basel_fricktal'      => 'Basel/Fricktal',
        'aargau_sued_zentral' => 'Aargau Süd/Zentralschweiz', // Legacy
        'aargau_sued_zentralschweiz' => 'Aargau Süd/Zentralschweiz',
        'mittelland_west'     => 'Mittelland West',
    ];

    private const LEGACY_ALIASES = [
        'zurich_limmattal' => 'zuerich_limmattal',
        'aargau_sued_zentral' => 'aargau_sued_zentralschweiz',
    ];

    private array $map = [];

    public function __construct()
    {
        $this->map = $this->load_map();
    }

    public function get(string $postcode): ?array
    {
        $postcode = preg_replace('/\D/', '', $postcode);
        if (!$postcode) {
            return null;
        }
        return $this->map[$postcode] ?? null;
    }

    public function all(): array
    {
        return $this->map;
    }

    public function region_label(string $region): string
    {
        return self::REGION_LABELS[$region] ?? '';
    }

    private function load_map(): array
    {
        $uploads = wp_upload_dir();
        $files = [];
        if (!empty($uploads['basedir'])) {
            $files[] = trailingslashit($uploads['basedir']) . self::CSV_BASENAME;
        }
        $files[] = trailingslashit(dirname(__DIR__)) . self::CSV_BASENAME;

        $file = '';
        foreach ($files as $candidate) {
            $candidate = wp_normalize_path($candidate);
            if (file_exists($candidate) && is_readable($candidate)) {
                $file = $candidate;
                break;
            }
        }
        if (!$file) {
            return [];
        }

        $rows = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$rows) {
            return [];
        }

        $delimiter = $this->detect_delimiter($rows[0]);
        $header = array_map('trim', str_getcsv($rows[0], $delimiter));
        $minutesIdx = $this->detect_index($header, ['minutes','min','fahrzeit']);
        if ($minutesIdx === null) {
            $minutesIdx = count($header) >= 3 ? 2 : 1;
        }
        $regionIdx  = $this->detect_index($header, ['region']);
        $map = [];

        foreach ($rows as $idx => $line) {
            if ($idx === 0) {
                continue;
            }
            $cols = array_map('trim', str_getcsv($line, $delimiter));
            $postcode = preg_replace('/\D/', '', $cols[0] ?? '');
            if (!$postcode) {
                continue;
            }
            $minutes = isset($cols[$minutesIdx]) ? (int) $cols[$minutesIdx] : 9999;
            $region = $regionIdx !== null && isset($cols[$regionIdx]) ? sanitize_key($cols[$regionIdx]) : '';
            if (isset(self::LEGACY_ALIASES[$region])) {
                $region = self::LEGACY_ALIASES[$region];
            }
            $map[$postcode] = [
                'minutes' => $minutes,
                'region' => $region,
                'on_request' => $minutes > 60 || $region === '',
            ];
        }

        return $map;
    }

    private function detect_delimiter(string $header): string
    {
        $semi = substr_count($header, ';');
        $comma = substr_count($header, ',');
        return $semi > $comma ? ';' : ',';
    }

    private function detect_index(array $header, array $needles): ?int
    {
        foreach ($header as $idx => $name) {
            $name = strtolower($name);
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($name, strtolower($needle)) !== false) {
                    return $idx;
                }
            }
        }
        return null;
    }
}
