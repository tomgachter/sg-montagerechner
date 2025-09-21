<?php

namespace SGMR\Shortcodes;

use SGMR\Booking\FluentBookingClient;

class BookingShortcodes
{
    private FluentBookingClient $client;

    public function __construct(FluentBookingClient $client)
    {
        $this->client = $client;
    }

    public function boot(): void
    {
        add_shortcode('sg_booking', [$this, 'renderSingle']);
        add_shortcode('sg_booking_both', [$this, 'renderBoth']);
    }

    public function renderSingle($atts): string
    {
        $atts = shortcode_atts(['region' => '', 'type' => 'montage'], $atts, 'sg_booking');
        $type = $atts['type'] === 'etage' ? 'etage' : 'montage';
        return $this->renderEmbed($atts['region'], [$type]);
    }

    public function renderBoth($atts): string
    {
        $atts = shortcode_atts(['region' => ''], $atts, 'sg_booking_both');
        return $this->renderEmbed($atts['region'], ['montage', 'etage']);
    }

    private function renderEmbed(string $region, array $types): string
    {
        $region = sanitize_key($region);
        if (!$region) {
            return '';
        }
        $teams = $this->client->regionTeams($region);
        if (!$teams) {
            return '<div class="sg-booking-embed sg-note">'.esc_html__('Keine Teams für diese Region hinterlegt.','sg-mr').'</div>';
        }
        $blocks = [];
        foreach ($types as $type) {
            if (!in_array($type, ['montage','etage'], true)) {
                continue;
            }
            $content = '';
            foreach ($teams as $teamKey) {
                $team = $this->client->team($teamKey);
                $label = $team['label'] ?? strtoupper($teamKey);
                $shortcode = $this->client->renderTeamShortcode($teamKey, $type);
                if (!$shortcode) {
                    continue;
                }
                $content .= '<div class="sg-booking-team">';
                $content .= '<h3>'.esc_html($label).'</h3>';
                $content .= $shortcode;
                $content .= '</div>';
            }
            if (!$content) {
                $content = '<div class="sg-note">'.esc_html__('Kein Buchungs-Shortcode hinterlegt.','sg-mr').'</div>';
            }
            $heading = $type === 'etage' ? __('Etagenlieferung','sg-mr') : __('Montage','sg-mr');
            $blocks[] = '<section class="sg-booking-type sg-booking-type-'.$type.'"><h2>'.esc_html($heading).'</h2>'.$content.'</section>';
        }
        if (!$blocks) {
            return '<div class="sg-booking-embed sg-note">'.esc_html__('Keine Buchungsoption verfügbar.','sg-mr').'</div>';
        }
        return '<div class="sg-booking-embed">'.implode('', $blocks).'</div>';
    }
}
