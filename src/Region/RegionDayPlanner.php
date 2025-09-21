<?php

namespace SGMR\Region;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use SGMR\Plugin;
use function __;
use function array_fill_keys;
use function get_option;
use function in_array;
use function is_array;
use function sanitize_key;

class RegionDayPlanner
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    public const POLICY_REJECT = 'reject';
    public const POLICY_RESCHEDULE = 'auto_reschedule';

    /** @var array<string, string> */
    private array $dayLabels;

    public function __construct()
    {
        $this->dayLabels = [
            'mon' => __('Montag', 'sg-mr'),
            'tue' => __('Dienstag', 'sg-mr'),
            'wed' => __('Mittwoch', 'sg-mr'),
            'thu' => __('Donnerstag', 'sg-mr'),
            'fri' => __('Freitag', 'sg-mr'),
            'sat' => __('Samstag', 'sg-mr'),
            'sun' => __('Sonntag', 'sg-mr'),
        ];
    }

    /**
     * @return array<string, array{days: array<string, bool>, max_teams: int}>
     */
    public function all(): array
    {
        $raw = get_option(Plugin::OPTION_REGION_WEEKPLAN, []);
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $region => $config) {
            $regionKey = sanitize_key((string) $region);
            if ($regionKey === '') {
                continue;
            }
            $clean[$regionKey] = $this->sanitizeConfig($config);
        }

        return $clean;
    }

    /**
     * @return array{days: array<string, bool>, max_teams: int}
     */
    public function configFor(string $region): array
    {
        $region = sanitize_key($region);
        $defaults = [
            'days' => array_fill_keys(self::DAY_KEYS, false),
            'max_teams' => 1,
        ];
        if ($region === '') {
            return $defaults;
        }
        $all = $this->all();
        if (!isset($all[$region])) {
            return $defaults;
        }

        $config = $all[$region];
        $config['days'] = array_merge(array_fill_keys(self::DAY_KEYS, false), $config['days']);
        if ($config['max_teams'] < 1) {
            $config['max_teams'] = 1;
        }
        if ($config['max_teams'] > 4) {
            $config['max_teams'] = 4;
        }
        return $config;
    }

    /**
     * @return array<string, bool>
     */
    public function dayFlags(string $region): array
    {
        return $this->configFor($region)['days'];
    }

    /**
     * @return array<int>
     */
    public function allowedDays(string $region): array
    {
        $flags = $this->dayFlags($region);
        $allowed = [];
        foreach ($flags as $key => $enabled) {
            if (!$enabled) {
                continue;
            }
            $int = self::dayIntFromKey($key);
            if ($int > 0) {
                $allowed[] = $int;
            }
        }
        if (!$allowed) {
            return [1, 2, 3, 4, 5, 6, 7];
        }
        sort($allowed);
        return array_values(array_unique($allowed));
    }

    /**
     * @return array<string>
     */
    public function allowedDayKeys(string $region): array
    {
        $flags = $this->dayFlags($region);
        $keys = [];
        foreach ($flags as $key => $enabled) {
            if ($enabled) {
                $keys[] = $key;
            }
        }
        if ($keys) {
            return $keys;
        }
        return self::DAY_KEYS;
    }

    public function maxTeams(string $region): int
    {
        $config = $this->configFor($region);
        return $config['max_teams'] > 0 ? (int) $config['max_teams'] : 1;
    }

    public function isDateAllowed(string $region, DateTimeInterface $date): bool
    {
        $dow = (int) $date->format('N');
        $allowed = $this->allowedDays($region);
        return in_array($dow, $allowed, true);
    }

    public function isDowAllowed(string $region, int $dow): bool
    {
        $dow = (int) $dow;
        if ($dow < 1 || $dow > 7) {
            return false;
        }
        return in_array($dow, $this->allowedDays($region), true);
    }

    public function nextAllowedDate(string $region, DateTimeInterface $from, int $maxIterations = 120): ?DateTimeImmutable
    {
        $timezone = $from->getTimezone() ?: new DateTimeZone('UTC');
        $candidate = DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);
        if (!$candidate) {
            return null;
        }

        for ($i = 0; $i < $maxIterations; $i++) {
            if ($this->isDateAllowed($region, $candidate)) {
                return $candidate;
            }
            $candidate = $candidate->add(new DateInterval('P1D'))->setTimezone($timezone);
        }

        return null;
    }

    /**
     * @return array<string>
     */
    public function allowedDayLabels(string $region): array
    {
        $keys = $this->allowedDayKeys($region);
        $labels = [];
        foreach ($keys as $key) {
            $labels[] = $this->dayLabels[$key] ?? $key;
        }
        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public function dayChoices(): array
    {
        return $this->dayLabels;
    }

    /**
     * @param mixed $config
     * @return array{days: array<string, bool>, max_teams: int}
     */
    private function sanitizeConfig($config): array
    {
        $days = [];
        $maxTeams = 1;
        if (is_array($config)) {
            if (isset($config['max_teams'])) {
                $maxTeams = (int) $config['max_teams'];
            } elseif (isset($config['teams'])) {
                $maxTeams = (int) $config['teams'];
            }
            if ($maxTeams < 1) {
                $maxTeams = 1;
            }
            if ($maxTeams > 4) {
                $maxTeams = 4;
            }

            foreach (self::DAY_KEYS as $dayKey) {
                if (isset($config[$dayKey])) {
                    $dayConfig = $config[$dayKey];
                    if (is_array($dayConfig)) {
                        $days[$dayKey] = !empty($dayConfig['enabled']);
                    } else {
                        $days[$dayKey] = !empty($dayConfig);
                    }
                    continue;
                }
                if (isset($config['days'][$dayKey])) {
                    $dayConfig = $config['days'][$dayKey];
                    if (is_array($dayConfig)) {
                        $days[$dayKey] = !empty($dayConfig['enabled']);
                    } else {
                        $days[$dayKey] = !empty($dayConfig);
                    }
                }
            }
        }
        $days = array_merge(array_fill_keys(self::DAY_KEYS, false), $days);
        return [
            'days' => $days,
            'max_teams' => $maxTeams,
        ];
    }

    public static function dayKeyFromInt(int $dow): string
    {
        $map = [
            1 => 'mon',
            2 => 'tue',
            3 => 'wed',
            4 => 'thu',
            5 => 'fri',
            6 => 'sat',
            7 => 'sun',
        ];
        return $map[$dow] ?? '';
    }

    public static function dayIntFromKey(string $key): int
    {
        $map = [
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
            'sun' => 7,
        ];
        return $map[$key] ?? 0;
    }

    public function policy(): string
    {
        $value = get_option(Plugin::OPTION_REGION_DAY_POLICY, self::POLICY_REJECT);
        return $value === self::POLICY_RESCHEDULE ? self::POLICY_RESCHEDULE : self::POLICY_REJECT;
    }

    public function shouldAutoReschedule(): bool
    {
        return $this->policy() === self::POLICY_RESCHEDULE;
    }
}
