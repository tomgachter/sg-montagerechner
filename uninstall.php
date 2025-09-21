<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$optionKeys = [
    'sgmr_router_counters',
    'sgmr_router_rr_state',
    'sgmr_router_counters_lock',
];

foreach ($optionKeys as $option) {
    delete_option($option);
    delete_site_option($option);
}

if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('sgmr_purge_counters');
}
