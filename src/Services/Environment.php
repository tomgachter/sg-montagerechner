<?php

namespace SGMR\Services;

use SGMR\Plugin;

class Environment
{
    public static function option(string $key, $default = null)
    {
        return get_option($key, $default);
    }

    public static function updateOption(string $key, $value): void
    {
        update_option($key, $value, true);
    }

    public static function leadTimeDays(): int
    {
        $days = (int) get_option(Plugin::OPTION_LEAD_TIME_DAYS, 2);
        return $days > 0 ? $days : 2;
    }

    public static function bookingWindows(): array
    {
        return [
            '08:00–10:00',
            '10:00–12:30',
            '13:00–15:00',
            '15:00–16:30',
            '16:30–18:00',
        ];
    }
}
