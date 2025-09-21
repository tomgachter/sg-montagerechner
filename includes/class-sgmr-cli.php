<?php

use SGMR\Plugin;
use SGMR\Routing\DistanceProvider;

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class SGMR_Distances_CLI extends \WP_CLI_Command
{
    private DistanceProvider $provider;

    public function __construct()
    {
        $this->provider = Plugin::instance()->distanceProvider();
    }

    /**
     * Invalidiert den Distanz-Cache und lädt die CSV neu.
     */
    public function reload($args, $assoc_args): void
    {
        $this->provider->invalidateCache();
        $data = $this->provider->load();
        $meta = $data['meta'];
        $rows = isset($meta['rows']) ? (int) $meta['rows'] : 0;
        $mtime = isset($meta['mtime']) ? (int) $meta['mtime'] : 0;
        $timestamp = $mtime ? \date_i18n('Y-m-d H:i:s', $mtime) : 'unknown';

        \WP_CLI::success(sprintf('Reloaded %d rows (mtime %s).', $rows, $timestamp));
    }

    /**
     * Gibt die Fahrzeit einer einzelnen PLZ aus.
     *
     * ## OPTIONS
     *
     * <plz>
     * : Postleitzahl, vierstellig.
     */
    public function get($args, $assoc_args): void
    {
        if (empty($args[0])) {
            \WP_CLI::error('Bitte PLZ angeben.');
        }
        $plz = (string) $args[0];
        $minutes = $this->provider->getMinutes($plz);
        if ($minutes === null) {
            \WP_CLI::warning('not found');
            return;
        }
        \WP_CLI::success((string) $minutes);
    }

    /**
     * Zeigt eine Stichprobe der geladenen Distanzdaten.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Anzahl Datensätze (Standard 5).
     */
    public function sample($args, $assoc_args): void
    {
        $limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 5;
        $data = $this->provider->load();
        $map = $data['map'];
        if (!$map) {
            \WP_CLI::warning('Keine Einträge vorhanden.');
            return;
        }

        $items = [];
        $count = 0;
        foreach ($map as $plz => $minutes) {
            $items[] = ['plz' => $plz, 'minutes' => (int) $minutes];
            $count++;
            if ($count >= $limit) {
                break;
            }
        }

        \WP_CLI\Utils\format_items('table', $items, ['plz', 'minutes']);
    }
}

\WP_CLI::add_command('sgmr distances', new SGMR_Distances_CLI());

class SGMR_Router_CLI extends \WP_CLI_Command
{
    private Plugin $plugin;

    public function __construct()
    {
        $this->plugin = Plugin::instance();
    }

    /**
     * Löscht alte Tageszähler.
     *
     * ## OPTIONS
     *
     * [--days=<n>]
     * : Anzahl Tage, die aufbewahrt bleiben (Standard 7).
     */
    public function purge($args, $assoc_args): void
    {
        $days = isset($assoc_args['days']) ? max(1, (int) $assoc_args['days']) : 7;
        $this->plugin->routerState()->purgeOldCounters($days);
        \WP_CLI::success(sprintf('Router counters purged (kept last %d days).', $days));
    }

    /**
     * Leert den Fahrzeiten-Cache.
     */
    public function flush_cache(): void
    {
        $this->plugin->distanceProvider()->invalidateCache();
        \WP_CLI::success('Distance cache invalidated.');
    }
}

\WP_CLI::add_command('sgmr router', new SGMR_Router_CLI());
