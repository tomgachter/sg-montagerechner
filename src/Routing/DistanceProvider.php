<?php

namespace SGMR\Routing;

use function basename;
use function current_user_can;
use function delete_option;
use function delete_transient;
use function error_log;
use function esc_html;
use function esc_html__;
use function file_exists;
use function filemtime;
use function filesize;
use function is_readable;
use function fopen;
use function fclose;
use function fgets;
use function get_option;
use function get_transient;
use function is_array;
use function is_numeric;
use function preg_replace;
use function set_transient;
use function sprintf;
use function str_getcsv;
use function str_replace;
use function strncmp;
use function substr;
use function substr_count;
use function time;
use function trim;
use function update_option;
use const HOUR_IN_SECONDS;

class DistanceProvider
{
    public const CSV_PATH = WP_CONTENT_DIR . '/uploads/sanigroup_postcodes_minutes.csv';

    private const TRANSIENT_KEY = 'sgmr_plz_minutes_cache';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;
    private const ERROR_OPTION = 'sgmr_plz_minutes_error';

    /** @var array{map: array<string, int>, meta: array{rows: int, mtime: int, delimiter: string}}|null */
    private ?array $cache = null;
    private ?string $lastError = null;

    public function getMinutes(string $plz): ?int
    {
        $plz = trim($plz);
        if ($plz === '') {
            return null;
        }
        $plz = preg_replace('/\D+/', '', $plz);
        if ($plz === '') {
            return null;
        }

        $data = $this->load();
        $map = $data['map'];

        return array_key_exists($plz, $map) ? (int) $map[$plz] : null;
    }

    /**
     * @return array{map: array<string, int>, meta: array{rows: int, mtime: int, delimiter: string}}
     */
    public function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $cached = $this->getCached();
        $currentEtag = $this->currentEtag();
        if ($cached && $cached['etag'] === $currentEtag) {
            $this->cache = $cached['data'];
            return $this->cache;
        }

        $data = $this->parseFile();
        $etag = $currentEtag !== '' ? $currentEtag : 'missing';
        $this->storeCache($data, $etag);
        $this->cache = $data;

        return $data;
    }

    public function invalidateCache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
        $this->cache = null;
    }

    public function renderAdminNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $stored = get_option(self::ERROR_OPTION, null);
        if (!is_array($stored) || empty($stored['message'])) {
            return;
        }
        $message = (string) $stored['message'];
        printf(
            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(esc_html__('Sanigroup Montage: %s', 'sg-mr'), $message))
        );
    }

    public function getLastError(): ?string
    {
        if ($this->lastError !== null) {
            return $this->lastError;
        }
        $stored = get_option(self::ERROR_OPTION, null);
        if (is_array($stored) && !empty($stored['message'])) {
            return (string) $stored['message'];
        }
        return null;
    }

    /**
     * @return array{etag: string, data: array{map: array<string, int>, meta: array{rows: int, mtime: int, delimiter: string}}}|null
     */
    private function getCached(): ?array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (!is_array($cached) || !isset($cached['etag'], $cached['data']) || !is_array($cached['data'])) {
            return null;
        }
        $data = $cached['data'];
        if (!isset($data['map'], $data['meta']) || !is_array($data['map']) || !is_array($data['meta'])) {
            return null;
        }

        return [
            'etag' => (string) $cached['etag'],
            'data' => [
                'map' => array_map(static fn($value) => (int) $value, $data['map']),
                'meta' => [
                    'rows' => isset($data['meta']['rows']) ? (int) $data['meta']['rows'] : 0,
                    'mtime' => isset($data['meta']['mtime']) ? (int) $data['meta']['mtime'] : 0,
                    'delimiter' => isset($data['meta']['delimiter']) ? (string) $data['meta']['delimiter'] : ',',
                ],
            ],
        ];
    }

    /**
     * @param array{map: array<string, int>, meta: array{rows: int, mtime: int, delimiter: string}} $data
     */
    private function storeCache(array $data, string $etag): void
    {
        set_transient(self::TRANSIENT_KEY, [
            'etag' => $etag,
            'data' => $data,
        ], self::CACHE_TTL);
    }

    /**
     * @return array{map: array<string, int>, meta: array{rows: int, mtime: int, delimiter: string}}
     */
    private function parseFile(): array
    {
        $path = self::CSV_PATH;
        if (!file_exists($path) || !is_readable($path)) {
            $this->reportError(sprintf('CSV %s nicht gefunden oder nicht lesbar.', basename($path)));
            return $this->emptyResult();
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            $this->reportError(sprintf('CSV %s konnte nicht geöffnet werden.', basename($path)));
            return $this->emptyResult();
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                $this->reportError(sprintf('CSV %s ist leer.', basename($path)));
                return $this->emptyResult();
            }

            $firstLine = $this->stripBom($firstLine);
            $delimiter = $this->detectDelimiter($firstLine);
            $headers = str_getcsv($firstLine, $delimiter);
            $headerMap = $this->normalizeHeaders($headers);
            if (!isset($headerMap['plz'], $headerMap['fahrzeit_min'])) {
                $this->reportError(sprintf('CSV %s besitzt keine gültigen Header (plz/fahrzeit_min).', basename($path)));
                return $this->emptyResult();
            }

            $postcodeIdx = (int) $headerMap['plz'];
            $minutesIdx = (int) $headerMap['fahrzeit_min'];

            $map = [];
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $line = $this->stripBom($line);
                $columns = str_getcsv($line, $delimiter);
                if (!isset($columns[$postcodeIdx], $columns[$minutesIdx])) {
                    continue;
                }
                $postcode = trim((string) $columns[$postcodeIdx]);
                if ($postcode === '') {
                    continue;
                }
                $postcode = preg_replace('/\D+/', '', $postcode);
                if ($postcode === '') {
                    continue;
                }
                $minutesRaw = trim((string) $columns[$minutesIdx]);
                if ($minutesRaw === '') {
                    continue;
                }
                if (!is_numeric($minutesRaw)) {
                    continue;
                }
                $minutes = (int) round((float) $minutesRaw);
                if ($minutes < 0) {
                    continue;
                }
                $map[$postcode] = $minutes;
            }
        } finally {
            fclose($handle);
        }

        $mtime = filemtime($path) ?: 0;
        $result = [
            'map' => $map,
            'meta' => [
                'rows' => count($map),
                'mtime' => $mtime,
                'delimiter' => $delimiter,
            ],
        ];

        if (!$map) {
            $this->reportError(sprintf('CSV %s enthält keine gültigen Datensätze.', basename($path)));
            return $result;
        }

        $this->clearError();
        return $result;
    }

    private function currentEtag(): string
    {
        $path = self::CSV_PATH;
        if (!file_exists($path) || !is_readable($path)) {
            return '';
        }
        $mtime = filemtime($path) ?: 0;
        $size = filesize($path) ?: 0;
        return md5($mtime . '|' . $size);
    }

    private function detectDelimiter(string $line): string
    {
        $line = str_replace("\xEF\xBB\xBF", '', $line);
        $candidates = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($candidates);
        foreach ($candidates as $delimiter => $count) {
            if ($count > 0) {
                return (string) $delimiter;
            }
        }
        return ',';
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, int>
     */
    private function normalizeHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $normalized = strtolower(trim($header));
            if ($normalized === '') {
                continue;
            }
            $map[$normalized] = (int) $index;
        }
        return $map;
    }

    private function stripBom(string $value): string
    {
        if (strncmp($value, "\xEF\xBB\xBF", 3) === 0) {
            return substr($value, 3);
        }
        return $value;
    }

    /**
     * @return array{map: array<string, int>, meta: array{rows: int, mtime: int, delimiter: string}}
     */
    private function emptyResult(): array
    {
        return [
            'map' => [],
            'meta' => [
                'rows' => 0,
                'mtime' => 0,
                'delimiter' => ',',
            ],
        ];
    }

    private function reportError(string $message): void
    {
        if ($this->lastError === $message) {
            return;
        }
        $this->lastError = $message;
        error_log(sprintf('[sgmr] %s', $message));
        update_option(self::ERROR_OPTION, [
            'message' => $message,
            'time' => time(),
        ], false);
    }

    private function clearError(): void
    {
        $this->lastError = null;
        delete_option(self::ERROR_OPTION);
    }
}
