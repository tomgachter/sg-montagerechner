<?php

namespace SGMR\Utils;

/**
 * Lightweight debug logger.
 *
 * Typical log keys include:
 * - booking_request / booking_response
 * - booking_public_event_vars
 * - status_transition
 * - webhook_* (booking lifecycle)
 */
class Logger
{
    public static function enabled(): bool
    {
        return (bool) apply_filters('sg_mr_logging_enabled', false);
    }

    public static function log(string $message, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }
        $line = '['.date('Y-m-d H:i:s').'] '.$message;
        if ($context) {
            $line .= ' '.wp_json_encode($context);
        }
        error_log($line);
    }
}
