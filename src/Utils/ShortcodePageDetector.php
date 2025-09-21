<?php

namespace SGMR\Utils;

use WP_Post;
use function esc_url_raw;
use function get_permalink;
use function get_posts;
use function has_shortcode;
use function is_string;
use function md5;
use function trim;
use function wp_cache_get;
use function wp_cache_set;
use const HOUR_IN_SECONDS;

class ShortcodePageDetector
{
    public static function detect(string $shortcode): string
    {
        $shortcode = trim($shortcode);
        if ($shortcode === '') {
            return '';
        }

        $needle = trim($shortcode, '[]');
        if ($needle === '') {
            return '';
        }

        $cacheKey = 'sgmr_shortcode_' . md5($needle);
        $cached = wp_cache_get($cacheKey, 'sgmr');
        if (is_string($cached)) {
            return $cached;
        }

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'private'],
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $url = '';
        foreach ($pages as $page) {
            if (!$page instanceof WP_Post) {
                continue;
            }
            $content = $page->post_content ?? '';
            if (!is_string($content) || $content === '') {
                continue;
            }
            if (has_shortcode($content, $needle)) {
                $permalink = get_permalink($page);
                if ($permalink) {
                    $url = esc_url_raw($permalink);
                }
                break;
            }
        }

        wp_cache_set($cacheKey, $url, 'sgmr', HOUR_IN_SECONDS);
        return $url;
    }
}
