<?php
/*
Plugin Name: SG Montagerechner v3
Description: Pro-Artikel Service-Auswahl (Montage / Etagenlieferung / Versand / Abholung) inkl. PLZ-Radius (CSV), feste Montagebasispreise (dynamisch mit Fahrzeit), Etagenlieferung-Basispreise, Posttarife, Versand-Pooling, Rabatte und Breakdance-kompatibler UI. Enthält Produkt-Shortcodes (Richtpreis-Button & Karte).
Version: 3.3.0
Author: Sanigroup
*/

if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('sgmr_purge_counters')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sgmr_purge_counters');
    }
    if (class_exists('SGMR\\Plugin')) {
        SGMR\Plugin::instance()->routerState()->migrateLegacyRR();
    }
});

register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled('sgmr_purge_counters');
    while ($timestamp) {
        wp_unschedule_event($timestamp, 'sgmr_purge_counters');
        $timestamp = wp_next_scheduled('sgmr_purge_counters');
    }
    wp_clear_scheduled_hook('sgmr_purge_counters');
});

if (!defined('SGMR_STATUS_PAID')) {
    define('SGMR_STATUS_PAID', 'sg-paid');
}
if (!defined('SGMR_STATUS_ARRIVED')) {
    define('SGMR_STATUS_ARRIVED', 'sg-arrived');
}
if (!defined('SGMR_STATUS_ONLINE')) {
    define('SGMR_STATUS_ONLINE', 'sg-online');
}
if (!defined('SGMR_STATUS_PHONE')) {
    define('SGMR_STATUS_PHONE', 'sg-phone');
}
if (!defined('SGMR_STATUS_PLANNED_ONLINE')) {
    define('SGMR_STATUS_PLANNED_ONLINE', 'sg-planned-online');
}
if (!defined('SGMR_STATUS_DONE')) {
    define('SGMR_STATUS_DONE', 'sg-done');
}
if (!defined('SGMR_STATUS_BOOKED')) {
    define('SGMR_STATUS_BOOKED', 'sg-booked');
}
if (!defined('SGMR_STATUS_RESCHEDULE')) {
    define('SGMR_STATUS_RESCHEDULE', 'sg-reschedule');
}
if (!defined('SGMR_STATUS_CANCELED')) {
    define('SGMR_STATUS_CANCELED', 'sg-canceled');
}

require_once __DIR__ . '/includes/class-sg-mr-postcodes.php';

final class SG_Montagerechner_V3 {

    /* Optionen / Konstanten */
    const OPT_MONTAGE_BASE = 'sg_mr_montage_base';   // [cat_slug => base_price]
    const OPT_ETAGE_BASE   = 'sg_mr_etage_base';     // [cat_slug => base_price]
    const OPT_PARAMS       = 'sg_mr_params';         // diverse Parameter
    const SESSION_SEL      = 'sg_mr_sel';            // Auswahl je cart_item_key
    const SESSION_PLZ      = 'sg_mr_plz';            // PLZ-Session
    const CSV_BASENAME     = 'sanigroup_postcodes_minutes.csv';
    const CSV_TRANSIENT    = 'sg_mr_plz_minutes_cache';
    const UPLOAD_PDF_DIR   = 'sg-montage-pdf';       // /uploads/sg-montage-pdf
    const OPT_PDFS_MAP     = 'sg_mr_pdfs';           // [cat_slug => attachment_id]
    const OPT_PDF_GLOBAL   = 'sg_mr_pdf_global';     // int attachment id
    private static $status_guard_running = false;

    /** Bootstrap */
    public static function init() {
        static $inst = null;
        if ($inst) return $inst;
        $inst = new self();

        /* Assets */
        add_action('wp_enqueue_scripts', [$inst, 'assets']);

        /* Admin */
        add_action('admin_menu', [$inst, 'admin_menu']);

        /* Cart/Checkout-Steuerelemente */
        add_action('woocommerce_after_cart_item_name',        [$inst, 'render_cart_item_controls'], 20, 2);
        // PLZ box no longer auto-injected; use shortcode [sg_plz_box]
        // removed on checkout: PLZ syncs to WC fields instead

        /* Shortcodes */
        add_shortcode('sg_montage_rechner_product', [$inst, 'sc_product_calc']);   // Karte auf Produktseite
        add_shortcode('sg_montage_rechner_button',  [$inst, 'sc_product_popup']);  // Button + aufklappbare Box
        add_shortcode('sg_plz_box',                 [$inst, 'sc_plz_box']);        // Freiplatzierbare PLZ Box (Breakdance)

        /* Ajax */
        add_action('wp_ajax_sg_m_set_plz',          [$inst, 'ajax_set_plz']);
        add_action('wp_ajax_nopriv_sg_m_set_plz',  [$inst, 'ajax_set_plz']);
        add_action('wp_ajax_sg_m_toggle',          [$inst, 'ajax_toggle']);
        add_action('wp_ajax_nopriv_sg_m_toggle',   [$inst, 'ajax_toggle']);
        add_action('wp_ajax_sg_m_estimate',        [$inst, 'ajax_estimate']);
        add_action('wp_ajax_nopriv_sg_m_estimate', [$inst, 'ajax_estimate']);
        add_action('wp_ajax_sg_m_line_price',      [$inst, 'ajax_line_price']);
        add_action('wp_ajax_nopriv_sg_m_line_price', [$inst, 'ajax_line_price']);
        // per-item express handled in sg_m_toggle

        /* Gebühren + Versandsteuerung */
        add_action('woocommerce_cart_calculate_fees', [$inst, 'calculate_fees'], 20, 1);

        /* E-Mail Anhänge (PDFs) */
        add_filter('woocommerce_email_attachments', [$inst, 'email_attachments'], 10, 3);

        /* Woo-Versand-UI ausblenden, Lieferadresse beibehalten */
        add_action('wp', function () {
            if (function_exists('is_cart') && (is_cart() || is_checkout())) {
                add_filter('woocommerce_shipping_packages', '__return_empty_array', 99);
                add_filter('woocommerce_available_shipping_methods', '__return_empty_array', 99);
                add_filter('woocommerce_package_rates', '__return_empty_array', 99);
                add_filter('woocommerce_cart_totals_shipping_html', '__return_empty_string', 99);
                add_filter('woocommerce_order_shipping_to_display', '__return_empty_string', 99);
                add_filter('woocommerce_cart_needs_shipping', '__return_true', 99);
                add_filter('woocommerce_cart_needs_shipping_address', '__return_true', 99);
            }
        });

        /* Standard-Optionen initialisieren */
        add_action('admin_init', [__CLASS__, 'maybe_seed_defaults']);

        /* Prefill checkout postcode with PLZ from session */
        add_filter('woocommerce_checkout_get_value', function($val, $key){
            if (in_array($key, ['billing_postcode','shipping_postcode'], true)){
                $plz = (string) self::session_get(self::SESSION_PLZ, '');
                if ($plz) return $plz;
            }
            return $val;
        }, 10, 2);

        /* Store cart service selection on order */
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_selection_to_order'], 15, 1);


        /* Checkout: Liefer-Telefon erfassen + speichern */
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'checkout_add_shipping_phone']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'checkout_capture_delivery_phone'], 20, 1);
        add_action('woocommerce_admin_order_data_after_shipping_address', [__CLASS__, 'admin_show_delivery_phone']);
        add_filter('woocommerce_email_order_meta_fields', [__CLASS__, 'email_meta_delivery_phone'], 10, 3);

        // Processing‑Mail: Hinweisblock (Zahlung erhalten + Szenario)
        add_action('woocommerce_email_before_order_table', [__CLASS__, 'email_processing_hint_block'], 10, 4);

        // Eigene Kunden-E-Mails (Woo-Stil)
        // Woo-Template-Override: Processing-Mail Intro entfernen (Woo-Style behalten)
        add_filter('woocommerce_locate_template', [__CLASS__, 'override_email_template'], 10, 3);
        // Fallback-Trigger: Sende Abhol-Mails bei Statuswechsel
        add_action('woocommerce_order_status_ready-pickup', [__CLASS__, 'maybe_email_ready_pickup']);
        add_action('woocommerce_order_status_picked-up',    [__CLASS__, 'maybe_email_picked_up']);
        // Admin-Farben für Custom Status
        add_action('admin_head', [__CLASS__, 'admin_status_styles']);

        // Custom Status + Admin-Aktionen
        add_action('init', [__CLASS__, 'register_statuses']);
        add_filter('wc_order_statuses', [__CLASS__, 'add_statuses_to_list']);
        add_filter('woocommerce_order_actions', [__CLASS__, 'order_row_actions']);
        add_action('woocommerce_order_action_sg_mark_ready_pickup', [__CLASS__, 'act_mark_ready_pickup']);
        add_action('woocommerce_order_action_sg_mark_picked_up', [__CLASS__, 'act_mark_picked_up']);
        add_action('woocommerce_order_action_sg_mark_service_done', [__CLASS__, 'act_mark_service_done']);
        add_action('woocommerce_order_action_sgmr_mark_paid', [__CLASS__, 'act_mark_paid']);
        add_action('woocommerce_order_action_sgmr_mark_arrived', [__CLASS__, 'act_mark_arrived']);
        add_filter('bulk_actions-edit-shop_order', [__CLASS__, 'bulk_actions']);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'guard_status_changes'], 9, 4);

        return $inst;
    }

    /* ---------------------------------------------------------------------- */
    /* Defaults                                                               */
    /* ---------------------------------------------------------------------- */

    public static function maybe_seed_defaults() {
        $mont = get_option(self::OPT_MONTAGE_BASE);
        $etag = get_option(self::OPT_ETAGE_BASE);
        $par  = get_option(self::OPT_PARAMS);

        // Montage-Kategorien (Basispreis CHF)
        $mont_defaults = [
            'haushaltgeraete/geschirrspuelen/geschirrspueler-einbau-45/'        => 220,
            'haushaltgeraete/geschirrspuelen/geschirrspueler-einbau-55/'        => 220,
            'haushaltgeraete/geschirrspuelen/geschirrspueler-einbau-60/'        => 220,
            'haushaltgeraete/geschirrspuelen/freistehend/'                       => 220,
            'haushaltgeraete/kuehlen-und-gefrieren/kuehl-gefrierkombi/'          => 320,
            'haushaltgeraete/backen-kochen-und-steamen/kaffeemaschinen/'        => 250, // nur Einbau
            'haushaltgeraete/waschen-trocknen-und-saugen/waschmaschine/'        => 220,
            'haushaltgeraete/waschen-trocknen-und-saugen/waermepumpentrockner/' => 220,
            'haushaltgeraete/backen-kochen-und-steamen/backofen/'               => 190,
            'haushaltgeraete/kuehlen-und-gefrieren/kuehlschrank/'               => 260,
            'haushaltgeraete/backen-kochen-und-steamen/steamer/'                => 260,
            'haushaltgeraete/backen-kochen-und-steamen/herd/'                   => 260,
            'haushaltgeraete/backen-kochen-und-steamen/mikrowelle/'             => 190, // nur Einbau
            'haushaltgeraete/backen-kochen-und-steamen/kochfeld/'               => 180, // aufliegend
            'haushaltgeraete/backen-kochen-und-steamen/induktions-kochfeld/'    => 180,
            'haushaltgeraete/backen-kochen-und-steamen/back-mikro-kombi/'       => 260,
            'haushaltgeraete/backen-kochen-und-steamen/waermeschublade/'        => 150,
            'haushaltgeraete/waschen-trocknen-und-saugen/waeschetrockner/'      => 220,
            'haushaltgeraete/kuehlen-und-gefrieren/gefrierschrank/'             => 260, // nur Einbau
            'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waschmaschine/'    => 260,
            'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waeschetrockner/'  => 260,
            'haushaltgeraete/backen-kochen-und-steamen/bedienelement/'          => 110,
            'haushaltgeraete/kuehlen-und-gefrieren/food-center/'                => 500,
            'haushaltgeraete/backen-kochen-und-steamen/induktions-kochfeld-mit-dunstabzug/' => 280,
            'haushaltgeraete/waschen-trocknen-und-saugen/waschtrockner-kombigeraet/' => 260,
            'haushaltgeraete/backen-kochen-und-steamen/gas-herd/'               => 0, // nur auf Anfrage
        ];
        if (!is_array($mont)) update_option(self::OPT_MONTAGE_BASE, $mont_defaults);

        // Etagenlieferung Kategorien (Basispreis CHF)
        $etag_defaults = [
            'haushaltgeraete/backen-kochen-und-steamen/dunstabzug/'            => 0,
            'haushaltgeraete/geschirrspuelen/geschirrspueler-einbau-45/'        => 60,
            'haushaltgeraete/geschirrspuelen/geschirrspueler-einbau-55/'        => 60,
            'haushaltgeraete/geschirrspuelen/geschirrspueler-einbau-60/'        => 60,
            'haushaltgeraete/geschirrspuelen/freistehend/'                       => 60,
            'haushaltgeraete/kuehlen-und-gefrieren/kuehl-gefrierkombi/'          => 80,
            'haushaltgeraete/backen-kochen-und-steamen/kaffeemaschinen/'        => 40,
            'haushaltgeraete/waschen-trocknen-und-saugen/waschmaschine/'        => 60,
            'haushaltgeraete/waschen-trocknen-und-saugen/waermepumpentrockner/' => 60,
            'haushaltgeraete/backen-kochen-und-steamen/backofen/'               => 60,
            'haushaltgeraete/kuehlen-und-gefrieren/kuehlschrank/'               => 70,
            'haushaltgeraete/backen-kochen-und-steamen/steamer/'                => 70,
            'haushaltgeraete/backen-kochen-und-steamen/herd/'                   => 70,
            'haushaltgeraete/backen-kochen-und-steamen/mikrowelle/'             => 40,
            'haushaltgeraete/backen-kochen-und-steamen/kochfeld/'               => 40,
            'haushaltgeraete/backen-kochen-und-steamen/induktions-kochfeld/'    => 40,
            'haushaltgeraete/backen-kochen-und-steamen/back-mikro-kombi/'       => 70,
            'haushaltgeraete/backen-kochen-und-steamen/waermeschublade/'        => 40,
            'haushaltgeraete/waschen-trocknen-und-saugen/waeschetrockner/'      => 60,
            'haushaltgeraete/kuehlen-und-gefrieren/gefrierschrank/'             => 80,
            'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waschmaschine/'    => 80,
            'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waeschetrockner/'  => 80,
            'haushaltgeraete/backen-kochen-und-steamen/bedienelement/'          => 30,
            'haushaltgeraete/kuehlen-und-gefrieren/food-center/'                => 120,
            'haushaltgeraete/backen-kochen-und-steamen/induktions-kochfeld-mit-dunstabzug/' => 90,
            'haushaltgeraete/waschen-trocknen-und-saugen/waschtrockner-kombigeraet/' => 80,
            'haushaltgeraete/backen-kochen-und-steamen/gas-herd/'               => 0,
            'haushaltgeraete/waschen-trocknen-und-saugen/buegeln/'              => 0,
            'zubehoer-haushaltgeraete/'                                         => 0,
            'haushaltgeraete/waschen-trocknen-und-saugen/staubsauger/'          => 0,
            'haushaltgeraete/waschen-trocknen-und-saugen/kassiersysteme/'      => 0,
            'haushaltgeraete/waschen-trocknen-und-saugen/raumluftwaeschetrockner/' => 0,
            'haushaltgeraete/kuehlen-und-gefrieren/weinschrank/'                => 0,
            'haushaltgeraete/kuehlen-und-gefrieren/gefriertruhe/'               => 0,
            'haushaltgeraete/geschirrspuelen/modular/'                          => 0,
        ];
        if (!is_array($etag)) update_option(self::OPT_ETAGE_BASE, $etag_defaults);

        // Parameter Defaults
        $params_defaults = [
            // Fahrzeit / PLZ
            'out_radius_min' => 60,
            'free_min'       => 20,
            'rate_per_min'   => 1.0,

            // Zuschläge
            'old_item_fee'   => 30.0,
            'tower_fee'      => 20.0,

            // Rabatte wenn Montage in Bestellung vorhanden
            'ship_disc_with_mont'  => 50.0, // %
            'etage_disc_with_mont' => 70.0, // %

            // Etagenlieferung Zusatz
            'etage_alt_mitnahme_add' => 30.0,

            // Posttarife (Priority)
            'post_0_2'   => 10.50,
            'post_2_10'  => 13.50,
            'post_10_30' => 22.50,
            'post_sperr' => 32.50, // 10–30kg + Sperrgut
            'post_fallback' => 13.50,

            // Express-Montage
            'express_enabled'   => 1,
            'express_base'      => 40.0,
            'express_per_min'   => 1.50,
            'express_thresh_min'=> 20,
            'express_days'      => 5,
            'express_tooltip'   => "Schnellere Disposition & Terminierung. Ziel innerhalb {{days}} AT.",

            // Kochfeld Montagearten (Basispreise)
            'kochfeld_flat_base'    => 0.0,  // flächenbündig
            'kochfeld_overlay_base' => 0.0,  // aufliegend

            // Montage-Stückzahlrabatt (nur Montage-Anteile)
            'mont_disc_2' => 10.0,
            'mont_disc_3' => 15.0,
            'mont_disc_4' => 20.0,

            // Abholung: Konfiguration
            'pickup_address'   => 'Dohlenzelgstrasse 2b, 5210 Windisch',
            'pickup_hours_url' => home_url('/oeffnungszeiten/'),

            // Montage: Zuschlag grosse Kühlschränke
            'fridge_height_thresh_cm' => 160,
            'fridge_height_add'       => 20.0,

            // Etagenlieferung: Schwer+hoch Zuschlag (beide Bedingungen)
            'etage_surcharge_weight_kg' => 60,
            'etage_surcharge_height_cm' => 170,
            'etage_surcharge_add'       => 30.0,
        ];
        if (!is_array($par)) update_option(self::OPT_PARAMS, $params_defaults);
    }

    /* ---------------------------------------------------------------------- */
    /* Custom Status                                                          */
    /* ---------------------------------------------------------------------- */

    public static function register_statuses(){
        register_post_status('wc-' . SGMR_STATUS_PAID, [
            'label'                     => _x('Zahlung erhalten', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Zahlung erhalten <span class="count">(%s)</span>', 'Zahlung erhalten <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-' . SGMR_STATUS_ARRIVED, [
            'label'                     => _x('Ware eingetroffen', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Ware eingetroffen <span class="count">(%s)</span>', 'Ware eingetroffen <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-' . SGMR_STATUS_ONLINE, [
            'label'                     => _x('Terminbuchung online offen', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Terminbuchung online offen <span class="count">(%s)</span>', 'Terminbuchung online offen <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-' . SGMR_STATUS_PHONE, [
            'label'                     => _x('Telefonische Terminvereinbarung', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Telefonische Terminvereinbarung <span class="count">(%s)</span>', 'Telefonische Terminvereinbarung <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-' . SGMR_STATUS_PLANNED_ONLINE, [
            'label'                     => _x('Termin online geplant', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Termin online geplant <span class="count">(%s)</span>', 'Termin online geplant <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-' . SGMR_STATUS_DONE, [
            'label'                     => _x('Montage/Etagenlieferung erfolgt', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Montage/Etagenlieferung erfolgt <span class="count">(%s)</span>', 'Montage/Etagenlieferung erfolgt <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-' . SGMR_STATUS_BOOKED, [
            'label'                     => _x('Termin gebucht', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Termin gebucht <span class="count">(%s)</span>', 'Termin gebucht <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-' . SGMR_STATUS_RESCHEDULE, [
            'label'                     => _x('Termin verschoben', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Termin verschoben <span class="count">(%s)</span>', 'Termin verschoben <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-' . SGMR_STATUS_CANCELED, [
            'label'                     => _x('Termin storniert', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Termin storniert <span class="count">(%s)</span>', 'Termin storniert <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-ready-pickup', [
            'label'                     => _x('zur Abholung bereit', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('zur Abholung bereit <span class="count">(%s)</span>', 'zur Abholung bereit <span class="count">(%s)</span>', 'sg-mr')
        ]);
        register_post_status('wc-picked-up', [
            'label'                     => _x('Abgeholt', 'Order status', 'sg-mr'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Abgeholt <span class="count">(%s)</span>', 'Abgeholt <span class="count">(%s)</span>', 'sg-mr')
        ]);
    }

    public static function add_statuses_to_list($statuses){
        $new = [];
        foreach ($statuses as $k=>$v){
            $new[$k] = $v;
            if ($k === 'wc-processing'){
                $new['wc-' . SGMR_STATUS_PAID]    = _x('Zahlung erhalten', 'Order status', 'sg-mr');
                $new['wc-' . SGMR_STATUS_ARRIVED] = _x('Ware eingetroffen', 'Order status', 'sg-mr');
                $new['wc-' . SGMR_STATUS_ONLINE]  = _x('Terminbuchung online offen', 'Order status', 'sg-mr');
                $new['wc-' . SGMR_STATUS_PHONE]   = _x('Telefonische Terminvereinbarung', 'Order status', 'sg-mr');
                $new['wc-' . SGMR_STATUS_PLANNED_ONLINE] = _x('Termin online geplant', 'Order status', 'sg-mr');
                $new['wc-' . SGMR_STATUS_BOOKED] = _x('Termin gebucht', 'Order status', 'sg-mr');
                $new['wc-' . SGMR_STATUS_RESCHEDULE] = _x('Termin verschoben', 'Order status', 'sg-mr');
                $new['wc-' . SGMR_STATUS_CANCELED] = _x('Termin storniert', 'Order status', 'sg-mr');
            }
            if ($k === 'wc-completed'){
                $new['wc-' . SGMR_STATUS_DONE] = _x('Montage/Etagenlieferung erfolgt', 'Order status', 'sg-mr');
                $new['wc-ready-pickup']        = _x('zur Abholung bereit', 'Order status', 'sg-mr');
                $new['wc-picked-up']           = _x('Abgeholt', 'Order status', 'sg-mr');
            }
        }
        return $new;
    }

    /* Store selection to order */
    public static function save_selection_to_order(WC_Order $order){
        $sel = WC()->session ? WC()->session->get(self::SESSION_SEL) : null;
        if ($sel) $order->update_meta_data('_sg_mr_sel', $sel);
    }

    private static function order_contains_service(WC_Order $order) : bool {
        // 1) From fees
        foreach ($order->get_items('fee') as $fee){
            $name = strtolower($fee->get_name());
            if (strpos($name,'montage') !== false || strpos($name,'etagen') !== false) return true;
        }
        // 2) From saved selection
        $sel = $order->get_meta('_sg_mr_sel');
        if (is_array($sel)){
            foreach ($sel as $s){
                if (!empty($s['mode']) && in_array($s['mode'], ['montage','etage'], true)) return true;
            }
        }
        return false;
    }

    // Removed: maybe_mark_service_pending (custom status flow)

    // Removed: maybe_send_pending_payment_email (custom email)

    /* Admin order row actions */
    // Removed: admin row actions and handlers for custom statuses/emails

    /* Bulk actions */
    // Removed: bulk actions for custom statuses/emails

    /* Badge-like status action on My Account Orders */
    // Removed: account status badge for custom statuses

    /* Emails registration */
    // Removed: register_emails for custom WC_Email classes

    /* ---------------------------------------------------------------------- */
    /* Delivery Phone (Woo → Bexio helper)                                    */
    /* ---------------------------------------------------------------------- */

    public static function checkout_add_shipping_phone($fields){
        if (!isset($fields['shipping'])) $fields['shipping'] = [];
        $fields['shipping']['shipping_phone'] = [
            'type'        => 'tel',
            'label'       => __('Telefon (Lieferung)','sg-mr'),
            'required'    => false,
            'priority'    => 120,
            'class'       => ['form-row-wide'],
            'autocomplete'=> 'tel',
            'placeholder' => '+41 …',
        ];
        return $fields;
    }

    public static function checkout_capture_delivery_phone(WC_Order $order){
        $ship_phone = trim((string) $order->get_meta('shipping_phone'));
        if (!$ship_phone && method_exists($order,'get_shipping_phone')){
            $ship_phone = trim((string) $order->get_shipping_phone());
        }
        if (!$ship_phone) $ship_phone = trim((string) $order->get_billing_phone());
        $ship_phone = preg_replace('/\s+/', ' ', $ship_phone);
        if ($ship_phone) {
            // Save canonical delivery phone
            $order->update_meta_data('_sg_delivery_phone', $ship_phone);
            // Ensure Woo has shipping_phone populated so integrations can pick it up
            if (method_exists($order,'set_shipping_phone')){
                $order->set_shipping_phone($ship_phone);
            } else {
                $order->update_meta_data('shipping_phone', $ship_phone);
            }
        }
    }

    public static function admin_show_delivery_phone($order){
        if (!$order instanceof WC_Order) return;
        $p = $order->get_meta('_sg_delivery_phone');
        if ($p){ echo '<p><strong>'.esc_html__('Telefon (Lieferung)','sg-mr').':</strong> '.esc_html($p).'</p>'; }
    }

    public static function email_meta_delivery_phone($fields, $sent_to_admin, $order){
        if ($order instanceof WC_Order){
            $p = $order->get_meta('_sg_delivery_phone');
            if ($p){ $fields['sg_delivery_phone'] = ['label'=>__('Telefon (Lieferung)','sg-mr'),'value'=>$p]; }
        }
        return $fields;
    }

    /* ---------------------------------------------------------------------- */
    /* Assets                                                                 */
    /* ---------------------------------------------------------------------- */

    public function assets() {
        wp_register_style ('sg-montage', plugins_url('sg-montage.css', __FILE__), [], '3.3.0');
        wp_register_script('sg-montage', plugins_url('sg-montage.js',  __FILE__), ['jquery'], '3.3.0', true);

        $data = [
            'ajax'           => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('sg_mr'),
            'currency'       => get_woocommerce_currency_symbol(),
            'contact_url'    => home_url('/kontakt/'),
            'txt_out_radius' => 'Außerhalb unseres Radius → Montage/Etagenlieferung nur auf Anfrage',
            'txt_within_radius' => 'Innerhalb unseres Radius – Montage/Etagenlieferung möglich',
        ];
        wp_localize_script('sg-montage', 'SG_M', $data);

        if (is_cart() || is_checkout() || is_product()) {
            wp_enqueue_style('sg-montage');
            wp_enqueue_script('sg-montage');
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Admin                                                                  */
    /* ---------------------------------------------------------------------- */

    public function admin_menu() {
        add_menu_page('SG Services', 'SG Services', 'manage_woocommerce', 'sg-services', [$this, 'admin_services'], 'dashicons-admin-tools', 56);
        add_submenu_page('sg-services', 'Montagepreise', 'Montagepreise', 'manage_woocommerce', 'sg-services', [$this, 'admin_services']);
        add_submenu_page('sg-services', 'Versand & Parameter', 'Versand & Parameter', 'manage_woocommerce', 'sg-services-params', [$this, 'admin_params']);
        add_submenu_page('sg-services', 'Etagenlieferung Preise', 'Etagenlieferung Preise', 'manage_woocommerce', 'sg-services-etage', [$this, 'admin_etage']);
        add_submenu_page('sg-services', 'PDFs', 'PDFs', 'manage_woocommerce', 'sg-services-pdfs', [$this, 'admin_pdfs']);
    }

    private function admin_save_notice($ok=true){
        printf('<div class="%s"><p>%s</p></div>', $ok?'updated':'error', $ok?'Gespeichert.':'Fehler beim Speichern.');
    }

    public function admin_services() {
        if (!current_user_can('manage_woocommerce')) return;
        $map = get_option(self::OPT_MONTAGE_BASE, []);
        if (!empty($_POST['sg_save_montage']) && check_admin_referer('sg_save_montage')) {
            $new = [];
            if (!empty($_POST['base']) && is_array($_POST['base'])) {
                foreach ($_POST['base'] as $slug => $val) $new[$slug] = (float) $val;
            }
            update_option(self::OPT_MONTAGE_BASE, $new);
            $this->admin_save_notice(true);
            $map = $new;
        }

        $cats = array_keys(self::maybe_seed_defaults_get(self::OPT_MONTAGE_BASE));

        echo '<div class="wrap"><h1>Montage – Basispreise</h1>';
        echo '<form method="post" style="background:#fff;padding:16px;border:1px solid #e5e5e5">';
        echo '<table class="widefat striped"><thead><tr><th>Produktkategorie (Permalink)</th><th style="width:160px">Basispreis (CHF)</th></tr></thead><tbody>';
        foreach ($cats as $slug){
            $val = isset($map[$slug]) ? $map[$slug] : '';
            printf('<tr><td><code>%s</code></td><td><input type="number" step="0.05" name="base[%s]" value="%s" /></td></tr>',
                esc_html($slug), esc_attr($slug), esc_attr($val));
        }
        echo '</tbody></table>';
        wp_nonce_field('sg_save_montage');
        echo '<p><button class="button button-primary" name="sg_save_montage" value="1">Speichern</button></p>';
        echo '</form></div>';
    }

    public function admin_etage() {
        if (!current_user_can('manage_woocommerce')) return;
        $map = get_option(self::OPT_ETAGE_BASE, []);
        if (!empty($_POST['sg_save_etage']) && check_admin_referer('sg_save_etage')) {
            $new = [];
            if (!empty($_POST['base']) && is_array($_POST['base'])) {
                foreach ($_POST['base'] as $slug => $val) $new[$slug] = (float) $val;
            }
            update_option(self::OPT_ETAGE_BASE, $new);
            $this->admin_save_notice(true);
            $map = $new;
        }

        $cats = array_keys(self::maybe_seed_defaults_get(self::OPT_ETAGE_BASE));

        echo '<div class="wrap"><h1>Etagenlieferung – Basispreise</h1>';
        echo '<form method="post" style="background:#fff;padding:16px;border:1px solid #e5e5e5">';
        echo '<table class="widefat striped"><thead><tr><th>Produktkategorie (Permalink)</th><th style="width:160px">Basispreis (CHF)</th></tr></thead><tbody>';
        foreach ($cats as $slug){
            $val = isset($map[$slug]) ? $map[$slug] : '';
            printf('<tr><td><code>%s</code></td><td><input type="number" step="0.05" name="base[%s]" value="%s" /></td></tr>',
                esc_html($slug), esc_attr($slug), esc_attr($val));
        }
        echo '</tbody></table>';
        wp_nonce_field('sg_save_etage');
        echo '<p><button class="button button-primary" name="sg_save_etage" value="1">Speichern</button></p>';
        echo '</form></div>';
    }

    public function admin_pdfs() {
        if (!current_user_can('manage_woocommerce')) return;
        $map = get_option(self::OPT_PDFS_MAP, []);
        $global = (int) get_option(self::OPT_PDF_GLOBAL, 0);

        if (!empty($_POST['sg_save_pdfs']) && check_admin_referer('sg_save_pdfs')) {
            $new = [];
            if (!empty($_POST['pdf']) && is_array($_POST['pdf'])) {
                foreach ($_POST['pdf'] as $slug => $val) {
                    $val = trim((string)$val);
                    if ($val === '') continue;
                    // allow ID or URL
                    if (ctype_digit($val)) {
                        $att_id = (int) $val;
                    } else {
                        $att_id = attachment_url_to_postid($val);
                    }
                    if ($att_id > 0) $new[$slug] = $att_id;
                }
            }
            $global_in = isset($_POST['pdf_global']) ? trim((string)$_POST['pdf_global']) : '';
            if ($global_in !== '') {
                $global = ctype_digit($global_in) ? (int)$global_in : (int) attachment_url_to_postid($global_in);
            } else {
                $global = 0;
            }
            update_option(self::OPT_PDFS_MAP, $new);
            update_option(self::OPT_PDF_GLOBAL, $global);
            $map = $new;
            $this->admin_save_notice(true);
        }

        $cats = array_unique(array_merge(
            array_keys(self::maybe_seed_defaults_get(self::OPT_MONTAGE_BASE)),
            array_keys(self::maybe_seed_defaults_get(self::OPT_ETAGE_BASE))
        ));
        sort($cats);

        echo '<div class="wrap"><h1>PDFs – Montagehinweise pro Kategorie</h1>';
        echo '<form method="post" style="background:#fff;padding:16px;border:1px solid #e5e5e5">';
        echo '<p>Hinterlegen Sie hier PDF-Anleitungen je Kategorie. Geben Sie die Attachment-ID oder die URL an. Optional: Globales PDF (AGB/Reglement) für alle E-Mails.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Produktkategorie (Permalink)</th><th style="width:260px">PDF (ID oder URL)</th></tr></thead><tbody>';
        foreach ($cats as $slug){
            $val = isset($map[$slug]) ? (int)$map[$slug] : 0;
            $disp = $val>0 ? (string)$val : '';
            printf('<tr><td><code>%s</code></td><td><input type="text" name="pdf[%s]" value="%s" placeholder="z.B. 123 oder https://.../file.pdf" /></td></tr>',
                esc_html($slug), esc_attr($slug), esc_attr($disp));
        }
        echo '</tbody></table>';

        echo '<h2>Global</h2><table class="form-table">';
        printf('<tr><th>AGB / Reglement (PDF)</th><td><input type="text" name="pdf_global" value="%s" placeholder="Attachment-ID oder URL" /></td></tr>', esc_attr($global?:''));
        echo '</table>';

        wp_nonce_field('sg_save_pdfs');
        echo '<p><button class="button button-primary" name="sg_save_pdfs" value="1">Speichern</button></p>';
        echo '</form></div>';
    }

    private static function maybe_seed_defaults_get($opt){
        switch($opt){
            case self::OPT_MONTAGE_BASE: return get_option(self::OPT_MONTAGE_BASE, []);
            case self::OPT_ETAGE_BASE:   return get_option(self::OPT_ETAGE_BASE, []);
        }
        return [];
    }

    public function admin_params() {
        if (!current_user_can('manage_woocommerce')) return;
        $p = wp_parse_args(get_option(self::OPT_PARAMS, []), []);
        if (!empty($_POST['sg_save_params']) && check_admin_referer('sg_save_params')) {
            $keys = ['out_radius_min','free_min','rate_per_min','old_item_fee','tower_fee','ship_disc_with_mont','etage_disc_with_mont','etage_alt_mitnahme_add','post_0_2','post_2_10','post_10_30','post_sperr','post_fallback','express_enabled','express_base','express_per_min','express_thresh_min','express_days','kochfeld_flat_base','kochfeld_overlay_base','mont_disc_2','mont_disc_3','mont_disc_4','fridge_height_thresh_cm','fridge_height_add','etage_surcharge_weight_kg','etage_surcharge_height_cm','etage_surcharge_add'];
            $text_keys = ['express_tooltip','pickup_address','pickup_hours_url'];
            $new  = [];
            foreach ($keys as $k) {
                if ($k==='express_enabled') {
                    $new[$k] = !empty($_POST[$k]) ? 1 : 0;
                } else {
                    $new[$k] = isset($_POST[$k]) ? (float) $_POST[$k] : ($p[$k] ?? 0);
                }
            }
            foreach ($text_keys as $k) $new[$k] = isset($_POST[$k]) ? wp_kses_post($_POST[$k]) : ($p[$k] ?? '');
            update_option(self::OPT_PARAMS, $new);
            $p = $new;
            $this->admin_save_notice(true);
        }

        if (!empty($_POST['sg_clear_csv']) && check_admin_referer('sg_save_params')) {
            delete_transient(self::CSV_TRANSIENT);
            $this->admin_save_notice(true);
        }

        echo '<div class="wrap"><h1>Versand & Parameter</h1>';
        echo '<form method="post" style="background:#fff;padding:16px;border:1px solid #e5e5e5">';
        echo '<h2>PLZ / Fahrzeit</h2><table class="form-table">';
        printf('<tr><th>Radius-Minuten</th><td><input type="number" name="out_radius_min" value="%s"></td></tr>', esc_attr($p['out_radius_min']));
        printf('<tr><th>Freiminuten</th><td><input type="number" name="free_min" value="%s"></td></tr>', esc_attr($p['free_min']));
        printf('<tr><th>Tarif (CHF/Minute)</th><td><input type="number" step="0.05" name="rate_per_min" value="%s"></td></tr>', esc_attr($p['rate_per_min']));
        echo '</table>';
        echo '<p><button class="button" name="sg_clear_csv" value="1">CSV-Cache (PLZ-Minuten) leeren</button></p>';

        echo '<h2>Zuschläge</h2><table class="form-table">';
        printf('<tr><th>Altgerät (pro Stück)</th><td><input type="number" step="0.05" name="old_item_fee" value="%s"></td></tr>', esc_attr($p['old_item_fee']));
        printf('<tr><th>Turm-Montage (pro Gerät)</th><td><input type="number" step="0.05" name="tower_fee" value="%s"></td></tr>', esc_attr($p['tower_fee']));
        echo '</table>';

        echo '<h2>Rabatte (wenn Montage in Bestellung)</h2><table class="form-table">';
        printf('<tr><th>Versand-Rabatt %%</th><td><input type="number" step="0.1" name="ship_disc_with_mont" value="%s"></td></tr>', esc_attr($p['ship_disc_with_mont']));
        printf('<tr><th>Etagenlieferung-Rabatt %%</th><td><input type="number" step="0.1" name="etage_disc_with_mont" value="%s"></td></tr>', esc_attr($p['etage_disc_with_mont']));
        echo '</table>';

        echo '<h2>Etagenlieferung</h2><table class="form-table">';
        printf('<tr><th>Aufpreis „mit Mitnahme Altgerät“</th><td><input type="number" step="0.05" name="etage_alt_mitnahme_add" value="%s"></td></tr>', esc_attr($p['etage_alt_mitnahme_add']));
        echo '</table>';

        echo '<h2>Posttarife (Priority)</h2><table class="form-table">';
        printf('<tr><th>≤ 2 kg</th><td><input type="number" step="0.05" name="post_0_2" value="%s"></td></tr>', esc_attr($p['post_0_2']));
        printf('<tr><th>2–10 kg</th><td><input type="number" step="0.05" name="post_2_10" value="%s"></td></tr>', esc_attr($p['post_2_10']));
        printf('<tr><th>10–30 kg</th><td><input type="number" step="0.05" name="post_10_30" value="%s"></td></tr>', esc_attr($p['post_10_30']));
        printf('<tr><th>Sperrgut 10–30 kg</th><td><input type="number" step="0.05" name="post_sperr" value="%s"></td></tr>', esc_attr($p['post_sperr']));
        printf('<tr><th>Fallback (ohne Gewicht)</th><td><input type="number" step="0.05" name="post_fallback" value="%s"></td></tr>', esc_attr($p['post_fallback']));
        echo '</table>';

        echo '<h2>Express‑Montage</h2><table class="form-table">';
        printf('<tr><th>Aktiv</th><td><label><input type="checkbox" name="express_enabled" value="1" %s> Option anzeigen</label></td></tr>', checked(!empty($p['express_enabled']), true, false));
        printf('<tr><th>Basisbetrag</th><td><input type="number" step="0.05" name="express_base" value="%s"></td></tr>', esc_attr($p['express_base']));
        printf('<tr><th>Zuschlag pro Minute</th><td><input type="number" step="0.05" name="express_per_min" value="%s"></td></tr>', esc_attr($p['express_per_min']));
        printf('<tr><th>Schwelle Freiminuten</th><td><input type="number" name="express_thresh_min" value="%s"></td></tr>', esc_attr($p['express_thresh_min']));
        printf('<tr><th>Ziel (Arbeitstage)</th><td><input type="number" name="express_days" value="%s"></td></tr>', esc_attr($p['express_days']));
        $tip = !empty($p['express_tooltip']) ? str_replace('{{days}}',(string)$p['express_days'],$p['express_tooltip']) : '';
        printf('<tr><th>Tooltip</th><td><textarea name="express_tooltip" rows="3" style="width:100%%">%s</textarea><p class="description">Platzhalter: {{days}}</p></td></tr>', esc_textarea($tip));
        echo '</table>';

        echo '<h2>Kochfeld – Montageart (Basispreise)</h2><table class="form-table">';
        printf('<tr><th>Flächenbündig (Basis)</th><td><input type="number" step="0.05" name="kochfeld_flat_base" value="%s"></td></tr>', esc_attr($p['kochfeld_flat_base']));
        printf('<tr><th>Aufliegend (Basis)</th><td><input type="number" step="0.05" name="kochfeld_overlay_base" value="%s"></td></tr>', esc_attr($p['kochfeld_overlay_base']));
        echo '</table>';

        echo '<h2>Montage – Stückzahlrabatt</h2><table class="form-table">';
        printf('<tr><th>ab 2 Geräte</th><td><input type="number" step="0.1" name="mont_disc_2" value="%s"> %%</td></tr>', esc_attr($p['mont_disc_2']));
        printf('<tr><th>ab 3 Geräte</th><td><input type="number" step="0.1" name="mont_disc_3" value="%s"> %%</td></tr>', esc_attr($p['mont_disc_3']));
        printf('<tr><th>ab 4 Geräte</th><td><input type="number" step="0.1" name="mont_disc_4" value="%s"> %%</td></tr>', esc_attr($p['mont_disc_4']));
        echo '</table>';

        echo '<h2>Abholung</h2><table class="form-table">';
        printf('<tr><th>Abholadresse</th><td><input type="text" name="pickup_address" value="%s" style="width:100%%" placeholder="Strasse Nr, PLZ Ort"></td></tr>', esc_attr($p['pickup_address']??''));
        printf('<tr><th>Öffnungszeiten URL</th><td><input type="url" name="pickup_hours_url" value="%s" style="width:100%%" placeholder="https://.../oeffnungszeiten/"></td></tr>', esc_attr($p['pickup_hours_url']??''));
        echo '</table>';

        echo '<h2>Montage – Zuschläge</h2><table class="form-table">';
        printf('<tr><th>Kühlschrank Zuschlag ab Höhe</th><td><input type="number" name="fridge_height_thresh_cm" value="%s"> cm</td></tr>', esc_attr($p['fridge_height_thresh_cm']));
        printf('<tr><th>Kühlschrank Zuschlag</th><td><input type="number" step="0.05" name="fridge_height_add" value="%s"> CHF</td></tr>', esc_attr($p['fridge_height_add']));
        echo '</table>';

        echo '<h2>Etagenlieferung – Zuschläge (schwer & hoch)</h2><table class="form-table">';
        printf('<tr><th>Gewicht über</th><td><input type="number" step="0.1" name="etage_surcharge_weight_kg" value="%s"> kg</td></tr>', esc_attr($p['etage_surcharge_weight_kg']));
        printf('<tr><th>Höhe über</th><td><input type="number" name="etage_surcharge_height_cm" value="%s"> cm</td></tr>', esc_attr($p['etage_surcharge_height_cm']));
        printf('<tr><th>Zuschlag</th><td><input type="number" step="0.05" name="etage_surcharge_add" value="%s"> CHF</td></tr>', esc_attr($p['etage_surcharge_add']));
        echo '</table>';

        wp_nonce_field('sg_save_params');
        echo '<p><button class="button button-primary" name="sg_save_params" value="1">Speichern</button></p>';
        echo '</form></div>';

        // Test PLZ Tool
        echo '<div class="wrap" style="margin-top:20px"><h2>Test PLZ / Produkt</h2>';
        echo '<form method="post" style="background:#fff;padding:16px;border:1px solid #e5e5e5;display:block;max-width:760px">';
        wp_nonce_field('sg_test_plz');
        echo '<p style="display:flex;gap:20px;flex-wrap:wrap">';
        echo '<label>PLZ: <input type="text" name="sg_test_plz" value="'.esc_attr($_POST['sg_test_plz']??'').'" maxlength="4" pattern="[0-9]*"></label>';
        echo '<label>Produkt‑ID: <input type="number" name="sg_test_product" value="'.esc_attr($_POST['sg_test_product']??'').'" style="width:120px"></label>';
        echo '<label>Menge: <input type="number" name="sg_test_qty" value="'.esc_attr($_POST['sg_test_qty']??1).'" min="1" style="width:80px"></label>';
        echo '</p>';
        echo '<p><strong>Service:</strong> ';
        $svc = $_POST['sg_test_service'] ?? 'montage';
        printf('<label style="margin-right:12px"><input type="radio" name="sg_test_service" value="montage" %s> Montage</label>', checked($svc,'montage',false));
        printf('<label><input type="radio" name="sg_test_service" value="etage" %s> Etagenlieferung</label>', checked($svc,'etage',false));
        echo '</p>';
        echo '<p><strong>Optionen:</strong> ';
        $old = !empty($_POST['sg_test_old']);
        $etAlt = !empty($_POST['sg_test_etalt']);
        $exp = !empty($_POST['sg_test_exp']);
        $tower = !empty($_POST['sg_test_tower']);
        $ktype = $_POST['sg_test_ktype'] ?? '';
        echo '<label style="margin-right:10px"><input type="checkbox" name="sg_test_old" '.checked($old,true,false).'> Altgerät</label>';
        echo '<label style="margin-right:10px"><input type="checkbox" name="sg_test_etalt" '.checked($etAlt,true,false).'> Etage: Mitnahme Altgerät</label>';
        echo '<label style="margin-right:10px"><input type="checkbox" name="sg_test_exp" '.checked($exp,true,false).'> Express</label>';
        echo '<label style="margin-right:10px"><input type="checkbox" name="sg_test_tower" '.checked($tower,true,false).'> Turm‑Montage</label>';
        echo '<label style="margin-left:10px">Kochfeld: ';
        printf('<select name="sg_test_ktype"><option value="">–</option><option value="flat" %s>flächenbündig</option><option value="overlay" %s>aufliegend</option></select>', selected($ktype,'flat',false), selected($ktype,'overlay',false));
        echo '</label></p>';
        echo '<p><button class="button">Berechnen</button></p>';
        echo '</form>';
        if (!empty($_POST['sg_test_plz']) && check_admin_referer('sg_test_plz')){
            $plz = preg_replace('/\D/','', $_POST['sg_test_plz']);
            $min = self::minutes_for_plz($plz);
            $within = self::plz_within_radius($plz);
            $pid = (int)($_POST['sg_test_product']??0); $qty = max(1,(int)($_POST['sg_test_qty']??1));
            $svc = $_POST['sg_test_service'] ?? 'montage';
            $product = $pid? wc_get_product($pid) : null;
            echo '<div style="margin-top:10px">';
            printf('<p>PLZ %s → %d Minuten, %s Radius (Grenze: %d)</p>', esc_html($plz), (int)$min, $within?'innerhalb':'außerhalb', (int)$p['out_radius_min']);
            if ($product) {
                $sel = [
                    'mode'=>$svc,
                    'old_bundle'=>$old?1:0,
                    'etage_alt'=>$etAlt?1:0,
                    'express'=>$exp?1:0,
                    'tower'=>$tower?1:0,
                    'kochfeld_type'=>$ktype,
                ];
                $cat = self::product_primary_cat_slug($product);
                $amount = 0.0; $label='';
                if ($svc==='montage' && $within){
                    $base = self::montage_base_for_product($product);
                    if (str_contains($cat,'/kochfeld/')||str_contains($cat,'/induktions-kochfeld/')){
                        if ($ktype==='flat' && (float)$p['kochfeld_flat_base']>0) $base=(float)$p['kochfeld_flat_base'];
                        if ($ktype==='overlay' && (float)$p['kochfeld_overlay_base']>0) $base=(float)$p['kochfeld_overlay_base'];
                    }
                    $extra = max(0, $min - (int)$p['free_min']);
                    $amount = ($base + $extra*(float)$p['rate_per_min']) * $qty;
                    if ($old)   $amount += (float)$p['old_item_fee'] * $qty;
                    if ($tower) $amount += (float)$p['tower_fee'] * $qty;
                    if ($exp)   $amount += ((float)$p['express_base'] + max(0,$min-(int)$p['express_thresh_min'])*(float)$p['express_per_min']) * $qty;
                    $label='Montage';
                } elseif ($svc==='etage' && $within){
                    $base = self::etage_base_for_product($product);
                    $extra = max(0, $min - (int)$p['free_min']);
                    $amount = ($base + $extra*(float)$p['rate_per_min']) * $qty;
                    if ($etAlt) $amount += (float)$p['etage_alt_mitnahme_add'] * $qty;
                    $label='Etagenlieferung';
                }
                printf('<p><strong>%s</strong> für Produkt %d (Menge %d): %s</p>', esc_html($label?:'–'), (int)$pid, (int)$qty, $amount? wc_price($amount):'–');
            } else {
                echo '<p><em>Produkt‑ID angeben für Preisvorschau.</em></p>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    /* ---------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* ---------------------------------------------------------------------- */

    private static function get_params(){
        return wp_parse_args(get_option(self::OPT_PARAMS, []), []);
    }

    private static function plz_minutes_map() : array {
        $file_uploads = wp_upload_dir()['basedir'].'/'.self::CSV_BASENAME;
        $file_plugin  = plugin_dir_path(__FILE__).self::CSV_BASENAME;
        $file         = file_exists($file_uploads) ? $file_uploads : (file_exists($file_plugin) ? $file_plugin : $file_uploads);
        $ver          = file_exists($file) ? (int) @filemtime($file) : 0;

        // Try cache and respect file version
        $cache = get_transient(self::CSV_TRANSIENT);
        if (is_array($cache) && isset($cache['map']) && isset($cache['ver']) && (int)$cache['ver'] === $ver) {
            return is_array($cache['map']) ? $cache['map'] : [];
        }

        $map = [];
        if (file_exists($file)) {
            $csv = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($csv && count($csv) > 0) {
                $first = $csv[0];
                // Detect delimiter by frequency
                $cntComma = substr_count($first, ',');
                $cntSemi  = substr_count($first, ';');
                $del = $cntSemi > $cntComma ? ';' : ',';

                // Try to detect minutes column by header name
                $head = array_map('trim', str_getcsv($first, $del));
                $minIdx = null;
                foreach ($head as $idx=>$h) {
                    $hl = strtolower($h);
                    if (strpos($hl, 'min') !== false) { $minIdx = $idx; break; }
                }
                // Default guesses: 3rd col if >=3, else 2nd col
                if ($minIdx === null) $minIdx = count($head) >= 3 ? 2 : 1;

                foreach ($csv as $i=>$row) {
                    if ($i===0) continue; // skip header
                    $cols = array_map('trim', str_getcsv($row, $del));
                    if (count($cols) <= $minIdx) continue;
                    $plzRaw = $cols[0];
                    $p = preg_replace('/\D/','', (string)$plzRaw);
                    $minRaw = (string) $cols[$minIdx];
                    // Accept formats like "35", "35.0", "35,0" and strip non-digits safely
                    if (preg_match('/\d+/', $minRaw, $mch)) {
                        $m = (int) preg_replace('/[^0-9]/','', $minRaw);
                    } else {
                        $m = 0;
                    }
                    if ($p) $map[$p] = $m;
                }
            }
        }
        set_transient(self::CSV_TRANSIENT, ['ver'=>$ver, 'map'=>$map], DAY_IN_SECONDS);
        return $map;
    }

    private static function minutes_for_plz(string $plz) : int {
        if (!$plz) return 9999;
        $map = self::plz_minutes_map();
        return isset($map[$plz]) ? (int)$map[$plz] : 9999;
    }

    private static function plz_within_radius(string $plz) : bool {
        $p = self::get_params();
        return self::minutes_for_plz($plz) <= (int)$p['out_radius_min'];
    }

    private static function session_get($key, $default = null) {
        $val = WC()->session ? WC()->session->get($key) : null;
        if ($val === null) {
            if ($key === self::SESSION_PLZ && isset($_COOKIE['sg_plz'])) {
                $plz = preg_replace('/\D/','', (string)$_COOKIE['sg_plz']);
                if ($plz) { self::session_set(self::SESSION_PLZ, $plz); return $plz; }
            }
        }
        return $val !== null ? $val : $default;
    }
    private static function session_set($key, $val) {
        if (WC()->session) WC()->session->set($key, $val);
    }

    private static function product_primary_cat_slug(WC_Product $product) : string {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (is_wp_error($terms) || !$terms) return '';
        usort($terms, function($a,$b){ return $a->term_id - $b->term_id; });
        $term = $terms[0];
        // reconstruct full path slugs
        $slugs = [];
        while ($term && !is_wp_error($term)) {
            array_unshift($slugs, $term->slug);
            if ($term->parent) $term = get_term($term->parent, 'product_cat'); else break;
        }
        return implode('/', $slugs).'/';
    }

    private static function get_attr_ci(WC_Product $product, array $keys) : string {
        foreach ($keys as $k){
            $v = $product->get_attribute($k);
            if ($v) return strtolower(trim($v));
        }
        return '';
    }

    private static function is_montage_on_request_for_product(WC_Product $product) : bool {
        $slug = self::product_primary_cat_slug($product);
        $on_request = [
            'haushaltgeraete/backen-kochen-und-steamen/kaffeemaschinen/',
            'haushaltgeraete/waschen-trocknen-und-saugen/kassiersysteme/',
            'haushaltgeraete/waschen-trocknen-und-saugen/raumluftwaeschetrockner/',
            'haushaltgeraete/kuehlen-und-gefrieren/weinschrank/',
            'haushaltgeraete/backen-kochen-und-steamen/gas-herd/',
            'haushaltgeraete/geschirrspuelen/modular/',
            'haushaltgeraete/backen-kochen-und-steamen/dunstabzug/',
        ];
        foreach ($on_request as $r) if (str_starts_with($slug,$r)) return true;
        return false;
    }

    private static function is_montage_allowed_for_product(WC_Product $product) : bool {
        $slug = self::product_primary_cat_slug($product);
        // Keine Montage
        $no = [
            'haushaltgeraete/kuehlen-und-gefrieren/gefrierschrank/',
            'haushaltgeraete/waschen-trocknen-und-saugen/buegeln/',
            'zubehoer-haushaltgeraete/',
            'haushaltgeraete/waschen-trocknen-und-saugen/staubsauger/',
            'haushaltgeraete/kuehlen-und-gefrieren/gefriertruhe/',
        ];
        foreach ($no as $n) if (str_starts_with($slug, $n)) return false;

        // Auf Anfrage (derzeit im Shop nicht direkt buchbar)
        if (self::is_montage_on_request_for_product($product)) return false;

        // Kategorien: Montage erlaubt nur bei Bauform=Einbau
        $einbau_only = [
            'haushaltgeraete/geschirrspuelen/geschirrspueler-einbau-55/',
            'haushaltgeraete/geschirrspuelen/geschirrspueler-einbau-60/',
            'haushaltgeraete/kuehlen-und-gefrieren/kuehl-gefrierkombi/',
            'haushaltgeraete/backen-kochen-und-steamen/backofen/',
            'haushaltgeraete/kuehlen-und-gefrieren/kuehlschrank/',
            'haushaltgeraete/geschirrspuelen/geschirrspueler-einbau-45/',
            'haushaltgeraete/backen-kochen-und-steamen/steamer/',
            'haushaltgeraete/backen-kochen-und-steamen/herd/',
            'haushaltgeraete/backen-kochen-und-steamen/mikrowelle/',
            'haushaltgeraete/backen-kochen-und-steamen/kochfeld/',
            'haushaltgeraete/backen-kochen-und-steamen/induktions-kochfeld/',
            'haushaltgeraete/backen-kochen-und-steamen/back-mikro-kombi/',
            'haushaltgeraete/backen-kochen-und-steamen/waermeschublade/',
            'haushaltgeraete/backen-kochen-und-steamen/bedienelement/',
            'haushaltgeraete/backen-kochen-und-steamen/induktions-kochfeld-mit-dunstabzug/',
        ];
        foreach ($einbau_only as $e){
            if (str_starts_with($slug, $e)){
                $bauform = self::get_attr_ci($product, ['bauform','pa_bauform','Bauform','pa_Bauform']);
                return (strpos($bauform,'einbau') !== false);
            }
        }

        // Kategorien: Montage erlaubt (unabhängig von Attributen)
        $always_yes = [
            'haushaltgeraete/kuehlen-und-gefrieren/food-center/',
            'haushaltgeraete/geschirrspuelen/freistehend/',
            'haushaltgeraete/waschen-trocknen-und-saugen/waschmaschine/',
            'haushaltgeraete/waschen-trocknen-und-saugen/waermepumpentrockner/',
            'haushaltgeraete/waschen-trocknen-und-saugen/waeschetrockner/',
            'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waschmaschine/',
            'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waeschetrockner/',
            'haushaltgeraete/waschen-trocknen-und-saugen/waschtrockner-kombigeraet/',
        ];
        foreach ($always_yes as $y) if (str_starts_with($slug, $y)) return true;

        // Sonst: default nicht erlauben
        return false;
    }

    private static function montage_base_for_product(WC_Product $product) : float {
        $slug = self::product_primary_cat_slug($product);
        $map  = get_option(self::OPT_MONTAGE_BASE, []);
        // längster passender Schlüssel
        $best = 0; $price = 0.0;
        foreach ($map as $k=>$v){
            if (str_starts_with($slug, $k) && strlen($k) > $best){ $best = strlen($k); $price = (float)$v; }
        }
        return $price;
    }

    private static function etage_base_for_product(WC_Product $product) : float {
        $slug = self::product_primary_cat_slug($product);
        $map  = get_option(self::OPT_ETAGE_BASE, []);
        $best = 0; $price = 0.0;
        foreach ($map as $k=>$v){
            if (str_starts_with($slug, $k) && strlen($k) > $best){ $best = strlen($k); $price = (float)$v; }
        }
        return $price;
    }

    private static function brand_or_name(WC_Product $p) : string {
        $brand = $p->get_attribute('pa_brand');
        if (!$brand) $brand = $p->get_attribute('marke');
        if (!$brand) $brand = $p->get_attribute('brand');
        $cat = self::product_primary_cat_slug($p);
        $name = $brand ? ($brand.' '. $p->get_name()) : $p->get_name();
        return $name;
    }

    private static function best_match_from_map(string $slug, array $map) : int {
        $best = 0; $att = 0;
        foreach ($map as $k=>$id){ if (str_starts_with($slug,$k) && strlen($k)>$best){ $best=strlen($k); $att=(int)$id; } }
        return $att;
    }

    private static function pdf_url_for_product(WC_Product $product) : string {
        $map = get_option(self::OPT_PDFS_MAP, []);
        if (!is_array($map) || empty($map)) return '';
        $slug = self::product_primary_cat_slug($product);
        $att  = self::best_match_from_map($slug, $map);
        if ($att > 0){
            $url = wp_get_attachment_url($att);
            if ($url) return $url;
        }
        return '';
    }

    private static function collect_pdf_paths_for_order(WC_Order $order) : array {
        $paths = [];
        $map = get_option(self::OPT_PDFS_MAP, []);
        if (!is_array($map) || empty($map)) return $paths;
        foreach ($order->get_items() as $item){
            $product = $item->get_product(); if (!$product) continue;
            $slug = self::product_primary_cat_slug($product);
            $att  = self::best_match_from_map($slug, $map);
            if ($att > 0){
                $file = get_attached_file($att);
                if ($file && file_exists($file)) $paths[$file] = true;
            }
        }
        // global
        $global = (int) get_option(self::OPT_PDF_GLOBAL, 0);
        if ($global>0){ $gfile = get_attached_file($global); if ($gfile && file_exists($gfile)) $paths[$gfile]=true; }
        return array_keys($paths);
    }

    /* ---------------------------------------------------------------------- */
    /* E-Mails: Processing-Hinweis + Registration                             */
    /* ---------------------------------------------------------------------- */

    public static function email_processing_hint_block($order, $sent_to_admin, $plain_text, $email){
        if ($sent_to_admin || $plain_text) return;
        if (!is_object($email) || empty($email->id) || $email->id !== 'customer_processing_order') return;
        if (!$order instanceof WC_Order) return;

        $m = self::order_mode_flags($order);
        $lines = [];
        $lines[] = 'Wir haben Ihre Zahlung erhalten.';
        if ($m['montage'] && $m['etage']){
            $lines[] = 'Unser Montageteam und Lieferteam kontaktieren Sie zur gemeinsamen Terminvereinbarung.';
        } elseif ($m['montage']){
            $lines[] = 'Unser Montageteam meldet sich telefonisch zur Terminierung.';
        } elseif ($m['etage']){
            $lines[] = 'Unser Lieferteam kontaktiert Sie telefonisch zur Terminvereinbarung.';
        } elseif ($m['abholung']){
            $lines[] = 'Sie erhalten eine Bestätigungs‑E‑Mail, sobald die Ware abholbereit ist.';
        } else {
            $lines[] = 'Wir versenden Ihre Bestellung in Kürze. Das Tracking folgt, sobald verfügbar.';
        }

        echo '<div style="margin:0 0 12px 0; padding:12px 15px; background:#f8f8f8; border:1px solid #eee;">';
        foreach ($lines as $l){ echo '<p style="margin:0 0 6px 0;">'.wp_kses_post($l).'</p>'; }
        echo '</div>';
    }

    private static function order_mode_flags(WC_Order $order) : array {
        $flags = ['montage'=>false,'etage'=>false,'abholung'=>false,'versand'=>false];
        $sel = $order->get_meta('_sg_mr_sel');
        if (is_array($sel) && !empty($sel)){
            foreach ($sel as $s){
                $mode = $s['mode'] ?? '';
                if ($mode==='montage') $flags['montage']=true;
                elseif ($mode==='etage') $flags['etage']=true;
                elseif ($mode==='abholung') $flags['abholung']=true;
            }
        }
        // Dominanz: Abholung vor allen anderen → ignoriert evtl. vorhandene Fees
        if (!$flags['abholung']){
            foreach ($order->get_items('fee') as $fee){
                $name = strtolower($fee->get_name());
                if (strpos($name,'montage') !== false) $flags['montage']=true;
                if (strpos($name,'etagen') !== false) $flags['etage']=true;
            }
        } else {
            $flags['montage'] = false; $flags['etage'] = false;
        }
        if (!$flags['montage'] && !$flags['etage'] && !$flags['abholung']) $flags['versand'] = true;
        return $flags;
    }

    public static function order_row_actions($actions){
        global $theorder;
        $order = $theorder instanceof WC_Order ? $theorder : null;
        if (!$order) return $actions;
        $m = self::order_mode_flags($order);
        $current = preg_replace('/^wc-/', '', $order->get_status());
        if (sgmr_can_transition($order, $current, SGMR_STATUS_PAID)) {
            $actions['sgmr_mark_paid'] = __('Markieren: Zahlung erhalten','sg-mr');
        }
        if (sgmr_can_transition($order, $current, SGMR_STATUS_ARRIVED)) {
            $actions['sgmr_mark_arrived'] = __('Markieren: Ware eingetroffen','sg-mr');
        }
        if ($m['abholung']){
            $actions['sg_mark_ready_pickup'] = __('Markieren: Zur Abholung bereit','sg-mr');
            $actions['sg_mark_picked_up']    = __('Markieren: Abgeholt','sg-mr');
        }
        if ($m['montage'] || $m['etage']){
            $actions['sg_mark_service_done'] = __('Markieren: Service erfolgt','sg-mr');
        }
        return $actions;
    }
    public static function act_mark_ready_pickup($order){ if ($order instanceof WC_Order) $order->update_status('wc-ready-pickup'); }
    public static function act_mark_picked_up($order){ if ($order instanceof WC_Order) $order->update_status('wc-picked-up'); }
    public static function act_mark_service_done($order){ if ($order instanceof WC_Order) $order->update_status('wc-' . SGMR_STATUS_DONE); }
    public static function act_mark_paid($order){ if ($order instanceof WC_Order) $order->update_status('wc-' . SGMR_STATUS_PAID); }
    public static function act_mark_arrived($order){ if ($order instanceof WC_Order) $order->update_status('wc-' . SGMR_STATUS_ARRIVED); }

    public static function bulk_actions($actions){
        $actions['mark_' . SGMR_STATUS_PAID] = __('Markieren: Zahlung erhalten','sg-mr');
        $actions['mark_' . SGMR_STATUS_ARRIVED] = __('Markieren: Ware eingetroffen','sg-mr');
        return $actions;
    }

    public static function maybe_email_ready_pickup($order_id){
        $order = wc_get_order($order_id); if (!$order) return;
        // send via email class if available
        $mailer = WC()->mailer(); if (!$mailer) return;
        $emails = $mailer->get_emails();
        if (!empty($emails['sg_email_ready_pickup'])){
            $emails['sg_email_ready_pickup']->trigger($order_id);
        }
    }
    public static function maybe_email_picked_up($order_id){
        $order = wc_get_order($order_id); if (!$order) return;
        $mailer = WC()->mailer(); if (!$mailer) return;
        $emails = $mailer->get_emails();
        if (!empty($emails['sg_email_picked_up'])){
            $emails['sg_email_picked_up']->trigger($order_id);
        }
    }

    public static function admin_status_styles(){
        // Style Badges in Admin-Liste ähnlich Woo-Design
        echo '<style>
        .widefat .column-order_status mark.status-sg-done,
        .widefat .column-order_status mark.status-picked-up {
            background-color:#c8d7e1; color:#2e4453; border-color:#b3c1cb;
        }
        .widefat .column-order_status mark.status-ready-pickup {
            background-color:#d1f1e8; color:#0b6b50; border-color:#8bd7c3;
        }
        .widefat .column-order_status mark.status-sg-online {
            background-color:#e8f3ff; color:#1f3a5f; border-color:#bfd8ff;
        }
        .widefat .column-order_status mark.status-sg-phone {
            background-color:#fff7e6; color:#6a4700; border-color:#ffe0a3;
        }
        .widefat .column-order_status mark.status-sg-planned-online {
            background-color:#edf9f0; color:#1d4b28; border-color:#b5e0c0;
        }
        </style>';
    }

    public static function override_email_template($template, $template_name, $template_path){
        // Nur die Processing-Kundenmail ersetzen, damit das Standard-Intro entfällt
        if ($template_name === 'emails/customer-processing-order.php'){
            $cand = plugin_dir_path(__FILE__).'templates/emails/customer-processing-order.php';
            if (file_exists($cand)) return $cand;
        }
        return $template;
    }

    public static function guard_status_changes($order_id, $old_status, $new_status, $order){
        if (self::$status_guard_running) return;
        if (!$order instanceof WC_Order) return;
        self::$status_guard_running = true;
        try {
            // Notiz bei Zahlungseingang (on-hold -> processing)
            if ($old_status === 'on-hold' && $new_status === 'processing'){
                $order->add_order_note(__('Zahlungseingang bestätigt.','sg-mr'));
            }

            // Blockiere terminalen Service-Status ohne Service in Bestellung
            if ($new_status === SGMR_STATUS_DONE && !self::order_contains_service($order)){
                $order->add_order_note(__('Statuswechsel zu "Service erfolgt" blockiert: Bestellung enthält keinen Service.','sg-mr'));
                // revert to previous
                $order->update_status('wc-'.$old_status);
            }

            // Blockiere Abhol-Status ohne Abholung
            $m = self::order_mode_flags($order);
            if (in_array($new_status, ['ready-pickup','picked-up'], true) && empty($m['abholung'])){
                $label = $new_status==='ready-pickup' ? __('zur Abholung bereit','sg-mr') : __('abgeholt','sg-mr');
                /* translators: %s status label */
                $order->add_order_note(sprintf(__('Statuswechsel zu "%s" blockiert: Bestellung hat keine Abholung.','sg-mr'), $label));
                $order->update_status('wc-'.$old_status);
            }
        } finally {
            self::$status_guard_running = false;
        }
    }
    // Removed: custom pickup email sender

    private static function post_tariff_for_product(WC_Product $p) : float {
        $par = self::get_params();
        $w = (float) $p->get_weight(); // Woo in kg (wenn gepflegt)
        $L = (float) $p->get_length(); // cm
        $W = (float) $p->get_width();
        $H = (float) $p->get_height();

        if ($w <= 0){
            return (float)$par['post_fallback'];
        }
        if ($w <= 2)   return (float)$par['post_0_2'];
        if ($w <= 10)  return (float)$par['post_2_10'];
        if ($w <= 30){
            $sperr = ($L > 100) || (($L>60) + ($W>60) + ($H>60) >= 2);
            return $sperr ? (float)$par['post_sperr'] : (float)$par['post_10_30'];
        }
        // >30kg → Post nicht zulässig, 0 hier (wird als „Haushaltgerät Versand“ gerechnet wenn _sg_ship_price gesetzt)
        return 0.0;
    }

    public function email_attachments($attachments, $email_id, $order) {
        if (!$order instanceof WC_Order) return $attachments;
        $targets = [
            'customer_processing_order',
            'customer_completed_order',
            'customer_on_hold_order',
            'customer_invoice',
            'new_order'
        ];
        if (!in_array($email_id, $targets, true)) return $attachments;
        $paths = self::collect_pdf_paths_for_order($order);
        if (!empty($paths)) $attachments = array_merge($attachments, $paths);
        return array_unique($attachments);
    }

    private static function freight_base_for_product(WC_Product $p) : float {
        $override = (float) get_post_meta($p->get_id(), '_sg_ship_price', true);
        if ($override > 0) return $override;
        // Fallback: wenn >30kg und kein Override → 0 (damit nicht doppelt)
        return 0.0;
    }

    /* ---------------------------------------------------------------------- */
    /* UI Rendering                                                           */
    /* ---------------------------------------------------------------------- */

    public function render_cart_plz() {
        $plz = (string) self::session_get(self::SESSION_PLZ, '');
        $p   = self::get_params();
        $within = $plz ? self::plz_within_radius($plz) : null;
        ?>
        <div class="sg-plz-wrap">
            <label for="sg_plz_cart"><strong>PLZ (Liefer-/Montageort)</strong></label>
            <input id="sg_plz_cart" class="sg-plz-cart" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" value="<?php echo esc_attr($plz); ?>" placeholder="z.B. 5210">
            <div class="sg-note small sg-plz-hint<?php echo ($within===true?' ok':($within===false?' warn':'')); ?>"><?php
                if ($within===true) echo esc_html('Innerhalb unseres Radius – Montage/Etagenlieferung möglich');
                elseif ($within===false) echo esc_html('Außerhalb unseres Radius → Montage/Etagenlieferung nur auf Anfrage');
                else echo '';
            ?></div>
        </div>
        <?php
    }

    public function render_checkout_plz() {
        $plz = (string) self::session_get(self::SESSION_PLZ, '');
        $within = $plz ? self::plz_within_radius($plz) : null;
        ?>
        <div class="sg-plz-wrap" style="margin-bottom:12px">
            <label for="sg_plz_checkout"><strong>PLZ Montageort</strong></label>
            <input id="sg_plz_checkout" class="sg-plz-checkout" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" value="<?php echo esc_attr($plz); ?>" placeholder="PLZ">
            <div class="sg-note small sg-plz-hint<?php echo ($within===true?' ok':($within===false?' warn':'')); ?>"><?php
                if ($within===true) echo esc_html('Innerhalb unseres Radius – Montage/Etagenlieferung möglich');
                elseif ($within===false) echo esc_html('Außerhalb unseres Radius → Montage/Etagenlieferung nur auf Anfrage');
                else echo '';
            ?></div>
        </div>
        <?php
    }

    public function render_cart_item_controls($cart_item, $cart_item_key) {
        $product = $cart_item['data'];
        if (!$product instanceof WC_Product) return;

        $sel_all = (array) self::session_get(self::SESSION_SEL, []);
        $sel     = isset($sel_all[$cart_item_key]) ? $sel_all[$cart_item_key] : ['mode'=>'versand','old_bundle'=>0,'etage_alt'=>0,'express'=>0,'tower'=>0,'kochfeld_type'=>''];

        $qty   = (int) $cart_item['quantity'];
        $label_old = $qty>1 ? 'Altgeräte ausbauen & abtransportieren' : 'Altgerät ausbauen & abtransportieren';
        $mont_ok   = self::is_montage_allowed_for_product($product) && (self::montage_base_for_product($product) > 0);
        $etage_ok  = self::etage_base_for_product($product) > 0;
        $cat_slug  = self::product_primary_cat_slug($product);

        ?>
        <div class="sg-linebox" data-key="<?php echo esc_attr($cart_item_key); ?>">
            <div class="sg-row">
                <label><strong>Service:</strong></label>
                <select class="sg-service-select">
                    <?php
                    $opts = ['versand'=>'Versand'];
                    if ($etage_ok) $opts['etage'] = 'Etagenlieferung';
                    if ($mont_ok)  $opts['montage'] = 'Montage';
                    $opts['abholung'] = 'Abholung';
                    foreach ($opts as $v=>$t){
                        printf('<option value="%s"%s>%s</option>', esc_attr($v), selected($sel['mode'],$v,false), esc_html($t));
                    }
                    ?>
                </select>
                <div class="sg-plz-req small" style="display:none;margin-top:6px;color:#a00">Für Montage/Etagenlieferung bitte oben die PLZ eingeben (und innerhalb Radius).</div>
            </div>

            <div class="sg-montage-opts" <?php if ($sel['mode']!=='montage') echo 'style="display:none"'; ?>>
                <label class="sg-old-wrap">
                    <input type="checkbox" class="sg-old-toggle" <?php checked(!empty($sel['old_bundle'])); ?> />
                    <span><?php echo esc_html($label_old); ?></span>
                </label>
                <?php
                // Turm-Montage: only for specified washer/dryer categories
                $tower_cats = [
                    'haushaltgeraete/waschen-trocknen-und-saugen/waschmaschine/',
                    'haushaltgeraete/waschen-trocknen-und-saugen/waermepumpentrockner/',
                    'haushaltgeraete/waschen-trocknen-und-saugen/waeschetrockner/',
                    'haushaltgeraete/waschen-trocknen-und-saugen/waschtrockner-kombigeraet/',
                    'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waschmaschine/',
                    'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waeschetrockner/',
                ];
                $is_tower_cat = false; foreach($tower_cats as $tc){ if (str_starts_with($cat_slug,$tc)) { $is_tower_cat=true; break; } }
                if ($is_tower_cat): ?>
                <label class="sg-old-wrap" style="margin-top:6px">
                    <input type="checkbox" class="sg-tower-toggle" <?php checked(!empty($sel['tower'])); ?> />
                    <span>Turm‑Montage</span>
                </label>
                <?php endif; ?>

                <?php
                // Kochfeld Montageart
                $is_kochfeld = (str_contains($cat_slug,'/kochfeld/') || str_contains($cat_slug,'/induktions-kochfeld/'));
                if ($is_kochfeld): ?>
                <div class="sg-old-wrap" style="margin-top:8px; display:block">
                    <label style="display:block"><input type="radio" name="sg-kochfeld-<?php echo esc_attr($cart_item_key); ?>" value="flat" <?php checked(($sel['kochfeld_type']??'')==='flat'); ?>> Kochfeld Montage flächenbündig</label>
                    <label style="display:block; margin-top:4px"><input type="radio" name="sg-kochfeld-<?php echo esc_attr($cart_item_key); ?>" value="overlay" <?php checked(($sel['kochfeld_type']??'')==='overlay'); ?>> Kochfeld Montage aufliegend</label>
                </div>
                <?php endif; ?>
                <?php $p = self::get_params(); if (!empty($p['express_enabled'])): $tip = !empty($p['express_tooltip']) ? str_replace('{{days}}',(string)$p['express_days'],$p['express_tooltip']) : ''; ?>
                <label class="sg-old-wrap" style="margin-top:6px">
                    <input type="checkbox" class="sg-express-item-toggle" <?php checked(!empty($sel['express'])); ?> />
                    <span>Express‑Montage</span>
                    <?php if($tip): ?><span class="sg-help" data-tip="<?php echo esc_attr(wp_strip_all_tags($tip)); ?>" style="cursor:help;color:#666;margin-left:6px">[?]</span><?php endif; ?>
                </label>
                <?php endif; ?>
                <div class="sg-hint small"><span class="sg-line-price"></span></div>
            </div>

            <div class="sg-etage-opts" <?php if ($sel['mode']!=='etage') echo 'style="display:none"'; ?>>
                <label><input type="radio" name="sg-etage-<?php echo esc_attr($cart_item_key); ?>" value="0" <?php checked(empty($sel['etage_alt'])); ?>> Etagenlieferung (ohne Altgerät)</label>
                <label style="margin-left:10px"><input type="radio" name="sg-etage-<?php echo esc_attr($cart_item_key); ?>" value="1" <?php checked(!empty($sel['etage_alt'])); ?>> Etagenlieferung (mit Mitnahme Altgerät)</label>
                <div class="sg-hint small"><span class="sg-line-price"></span></div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------- */
    /* Shortcodes                                                             */
    /* ---------------------------------------------------------------------- */

    // [sg_montage_rechner_product product_id="123"]
    public function sc_product_calc($atts) {
        $atts = shortcode_atts(['product_id'=>0, 'title'=>'Montage-Rechner'], $atts, 'sg_montage_rechner_product');
        $product_id = (int)$atts['product_id'];
        if (!$product_id && is_product()) $product_id = get_the_ID();
        if (!$product_id) return '';

        $p = wc_get_product($product_id); if(!$p) return '';
        $slug = self::product_primary_cat_slug($p);
        $is_koch = (str_contains($slug,'/kochfeld/') || str_contains($slug,'/induktions-kochfeld/'));
        $on_req  = self::is_montage_on_request_for_product($p);
        $par = self::get_params();

        ob_start(); ?>
        <div class="sg-montage-card" data-product-id="<?php echo esc_attr($product_id); ?>" data-onreq="<?php echo $on_req?'1':'0'; ?>">
            <h3><?php echo esc_html($atts['title']); ?> (Fixpreis + Fahrzeit)</h3>
            <input class="sg-input" type="text" inputmode="numeric" maxlength="4" placeholder="PLZ" value="">
            <?php if ($is_koch): ?>
            <div style="margin:6px 0">
                <label style="margin-right:10px"><input type="radio" name="sg-ktype" value="flat"> Kochfeld flächenbündig</label>
                <label><input type="radio" name="sg-ktype" value="overlay"> Kochfeld aufliegend</label>
            </div>
            <?php endif; ?>
            <?php if (!empty($par['express_enabled'])): ?>
            <div style="margin:4px 0">
                <label><input type="checkbox" class="sg-express-calc-toggle"> Express hinzufügen</label>
            </div>
            <?php endif; ?>
            <button class="sg-btn-calc" type="button">Preis berechnen</button>
            <div class="sg-montage-result"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // [sg_montage_rechner_button product_id="123" title="Montage ab …"]
    public function sc_product_popup($atts) {
        $atts = shortcode_atts(['product_id'=>0,'title'=>''], $atts, 'sg_montage_rechner_button');
        $product_id = (int)$atts['product_id'];
        if (!$product_id && is_product()) $product_id = get_the_ID();
        if (!$product_id) return '';
        $id = 'sg-mini-'.uniqid();
        $p = wc_get_product($product_id); if(!$p) return '';
        $allowed = self::is_montage_allowed_for_product($p);
        $onreq   = self::is_montage_on_request_for_product($p);
        if (!$allowed && !$onreq) return '';
        $base = self::montage_base_for_product($p);
        // Kochfeld Spezial: nimm kleinstes positives Basis-Override wenn vorhanden
        $flat = (float)(self::get_params()['kochfeld_flat_base'] ?? 0);
        $over = (float)(self::get_params()['kochfeld_overlay_base'] ?? 0);
        $slug = self::product_primary_cat_slug($p);
        if (str_contains($slug,'/kochfeld/')){
            $cands = array_filter([$base,$flat,$over], function($v){ return $v>0; });
            if (!empty($cands)) $base = min($cands);
        }
        if ($base <= 0) return '';
        $title = $atts['title']!=='' ? $atts['title'] : ('Montage ab '.wc_price($base).($onreq?' – nur auf Anfrage':' – jetzt PLZ prüfen'));
        ob_start(); ?>
        <button class="sg-btn-mini" type="button" aria-controls="<?php echo esc_attr($id); ?>" aria-expanded="false"><?php echo esc_html($title); ?></button>
        <?php if ($onreq): ?>
        <div class="sg-note small" style="margin-top:6px">Montage nur auf Anfrage – <a class="sg-link" href="<?php echo esc_url(home_url('/kontakt/')); ?>" target="_blank" rel="noopener">Kontakt</a></div>
        <?php endif; ?>
        <div id="<?php echo esc_attr($id); ?>" class="sg-montage-card" data-product-id="<?php echo esc_attr($product_id); ?>" data-onreq="<?php echo $onreq?'1':'0'; ?>" hidden>
            <h3>Montage – Richtwert</h3>
            <input class="sg-input" type="text" inputmode="numeric" maxlength="4" placeholder="PLZ" value="">
            <?php if (str_contains($slug,'/kochfeld/')): ?>
            <div style="margin:6px 0">
                <label style="margin-right:10px"><input type="radio" name="sg-ktype" value="flat"> Kochfeld flächenbündig</label>
                <label><input type="radio" name="sg-ktype" value="overlay"> Kochfeld aufliegend</label>
            </div>
            <?php endif; ?>
            <?php $par = self::get_params(); if (!empty($par['express_enabled'])): ?>
            <div style="margin:4px 0">
                <label><input type="checkbox" class="sg-express-calc-toggle"> Express hinzufügen</label>
            </div>
            <?php endif; ?>
            <button class="sg-btn-calc" type="button">Preis berechnen</button>
            <div class="sg-montage-result"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // [sg_plz_box]
    public function sc_plz_box($atts){
        $atts = shortcode_atts(['label'=>'PLZ (Liefer-/Montageort)'], $atts, 'sg_plz_box');
        ob_start();
        $this->render_cart_plz();
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------- */
    /* Ajax                                                                   */
    /* ---------------------------------------------------------------------- */

    public function ajax_set_plz() {
        check_ajax_referer('sg_mr','nonce');
        $plz = preg_replace('/\D/','', $_POST['plz'] ?? '');
        self::session_set(self::SESSION_PLZ, $plz);
        $min = self::minutes_for_plz($plz);
        $p   = self::get_params();
        $within = ($plz && $min <= (int)$p['out_radius_min']);
        wp_send_json_success(['plz'=>$plz,'within'=>$within,'min'=>$min,'radius'=>(int)$p['out_radius_min']]);
    }

    public function ajax_toggle() {
        check_ajax_referer('sg_mr','nonce');
        $key      = sanitize_text_field($_POST['key'] ?? '');
        $mode     = sanitize_text_field($_POST['mode'] ?? 'versand');
        $oldQ     = isset($_POST['old_qty']) ? (int) $_POST['old_qty'] : 0;
        $etAlt    = isset($_POST['etage_alt']) ? (int) $_POST['etage_alt'] : 0;
        $express  = isset($_POST['express']) ? (int) $_POST['express'] : 0;
        $tower    = isset($_POST['tower']) ? (int) $_POST['tower'] : 0;
        $ktype    = sanitize_text_field($_POST['ktype'] ?? '');

        $sel_all = (array) self::session_get(self::SESSION_SEL, []);
        $sel     = isset($sel_all[$key]) ? $sel_all[$key] : [];

        $sel['mode']       = in_array($mode, ['montage','versand','abholung','etage'], true) ? $mode : 'versand';
        $sel['old_bundle'] = $oldQ > 0 ? 1 : 0;
        $sel['etage_alt']  = $etAlt > 0 ? 1 : 0;
        $sel['express']    = $express > 0 ? 1 : 0;
        $sel['tower']      = $tower > 0 ? 1 : 0;
        $sel['kochfeld_type'] = in_array($ktype, ['flat','overlay'], true) ? $ktype : ($sel['kochfeld_type'] ?? '');

        $sel_all[$key] = $sel;
        self::session_set(self::SESSION_SEL, $sel_all);

        wp_send_json_success();
    }

    public function ajax_estimate() {
        check_ajax_referer('sg_mr','nonce');
        $product_id = (int) ($_POST['product_id'] ?? 0);
        $plz        = preg_replace('/\D/','', $_POST['plz'] ?? '');
        $ktype      = sanitize_text_field($_POST['ktype'] ?? '');
        $express    = !empty($_POST['express']) ? 1 : 0;
        $product    = wc_get_product($product_id);
        if (!$product) wp_send_json_error();

        $deliverable = self::plz_within_radius($plz);
        $base = self::montage_base_for_product($product);
        $p    = self::get_params();
        $min  = self::minutes_for_plz($plz);
        $extra= $deliverable ? max(0, $min - (int)$p['free_min']) : 0; // außerhalb Radius kein Fahrzeit‑Aufschlag

        // Kochfeld Override
        $slug = self::product_primary_cat_slug($product);
        if (str_contains($slug,'/kochfeld/')){
            if ($ktype==='flat' && (float)$p['kochfeld_flat_base']>0) $base=(float)$p['kochfeld_flat_base'];
            if ($ktype==='overlay' && (float)$p['kochfeld_overlay_base']>0) $base=(float)$p['kochfeld_overlay_base'];
        }

        $price = $base + $extra * (float)$p['rate_per_min'];
        // Express Zuschlag
        if ($express){
            $thr = (int)$p['express_thresh_min'];
            $extraE = max(0, $min - $thr);
            $price += (float)$p['express_base'] + $extraE*(float)$p['express_per_min'];
        }
        $pdf_url = self::pdf_url_for_product($product);
        $onreq   = self::is_montage_on_request_for_product($product);

        wp_send_json_success([
            'deliverable' => $deliverable,
            'price'       => number_format($price, 2, '.', '\''),
            'on_request'  => $onreq ? 1 : 0,
            'note'        => 'Fixpreis + Fahrzeit ab Windisch, Abweichungen werden gut-/nachberechnet.',
            'pdf_url'     => $pdf_url,
            'debug'       => [
                'plz' => $plz,
                'minutes' => $min,
                'free_min' => (int)$p['free_min'],
                'rate' => (float)$p['rate_per_min'],
                'base' => $base,
                'extra' => $extra,
                'raw_price' => $price,
            ],
        ]);
    }

    public function ajax_line_price(){
        check_ajax_referer('sg_mr','nonce');
        $key  = sanitize_text_field($_POST['key'] ?? '');
        $sel_all = (array) self::session_get(self::SESSION_SEL, []);
        $item = null; $product=null; $qty=1; $sel=['mode'=>'versand'];
        foreach (WC()->cart->get_cart() as $k=>$it){ if ($k===$key){ $item=$it; break; } }
        if ($item){ $product=$item['data']; $qty=(int)$item['quantity']; }
        if (!$product instanceof WC_Product){ wp_send_json_error(); }
        if (isset($sel_all[$key])) $sel=$sel_all[$key];

        $p    = self::get_params();
        $plz  = (string) self::session_get(self::SESSION_PLZ, '');
        $within = self::plz_within_radius($plz);
        $cat = self::product_primary_cat_slug($product);

        $label=''; $amount=0.0;
        if ($sel['mode']==='montage' && $within){
            $base = self::montage_base_for_product($product);
            if (str_contains($cat, '/kochfeld/') || str_contains($cat,'/induktions-kochfeld/')){
                if (($sel['kochfeld_type'] ?? '')==='flat' && (float)$p['kochfeld_flat_base']>0) $base=(float)$p['kochfeld_flat_base'];
                if (($sel['kochfeld_type'] ?? '')==='overlay' && (float)$p['kochfeld_overlay_base']>0) $base=(float)$p['kochfeld_overlay_base'];
            }
            $min   = self::minutes_for_plz($plz);
            $extra = max(0, $min - (int)$p['free_min']);
            $line  = ($base + $extra * (float)$p['rate_per_min']) * $qty;
            $line += !empty($sel['old_bundle']) ? (float)$p['old_item_fee'] * $qty : 0.0;
            if (!empty($sel['tower'])) $line += (float)$p['tower_fee'] * $qty;
            // Kühlschrank groß Zuschlag
            if (str_starts_with($cat,'haushaltgeraete/kuehlen-und-gefrieren/kuehlschrank/') || str_starts_with($cat,'haushaltgeraete/kuehlen-und-gefrieren/kuehl-gefrierkombi/')){
                $Hcm = (float) wc_get_dimension((float)$product->get_height(), 'cm');
                if ($Hcm > (float)$p['fridge_height_thresh_cm']) $line += (float)$p['fridge_height_add'] * $qty;
            }
            if (!empty($sel['express'])){
                $thr = (int)$p['express_thresh_min'];
                $extraE = max(0, $min - $thr);
                $line += ((float)$p['express_base'] + $extraE*(float)$p['express_per_min']) * $qty;
            }
            $label='Montage'; $amount=$line;
        } elseif ($sel['mode']==='etage' && $within){
            $base = self::etage_base_for_product($product);
            $min   = self::minutes_for_plz($plz);
            $extra = max(0, $min - (int)$p['free_min']);
            $line  = ($base + $extra * (float)$p['rate_per_min']) * $qty;
            if (!empty($sel['etage_alt'])) $line += (float)$p['etage_alt_mitnahme_add'] * $qty;
            $wkg = (float) wc_get_weight((float)$product->get_weight(), 'kg');
            $hcm = (float) wc_get_dimension((float)$product->get_height(), 'cm');
            if ($wkg > (float)$p['etage_surcharge_weight_kg'] && $hcm > (float)$p['etage_surcharge_height_cm']){
                $line += (float)$p['etage_surcharge_add'] * $qty;
            }
            $label='Etagenlieferung'; $amount=$line;
        }

        wp_send_json_success(['label'=>$label,'amount'=>wc_price($amount)]);
    }

    // ajax_express removed: express handled per cart item in ajax_toggle

    /* ---------------------------------------------------------------------- */
    /* Calculation                                                            */
    /* ---------------------------------------------------------------------- */

    public function calculate_fees(WC_Cart $cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;

        $sel_all     = (array) self::session_get(self::SESSION_SEL, []);
        $plz         = (string) self::session_get(self::SESSION_PLZ, '');
        $within      = self::plz_within_radius($plz);
        $p           = self::get_params();

        $ship_freight_bases = []; // für Pooling (Haushaltgeräte)
        $ship_post_sum      = 0.0; // Kleinteile (ohne Pooling)
        $montage_sum        = 0.0; $montage_count = 0; $has_montage=false; $has_service=false; $has_shipping=false;

        $etage_items = []; // sammeln, später einzeln/gesamt ausgeben
        $etage_sum   = 0.0;

        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data']; if (!$product instanceof WC_Product) continue;
            $qty     = (int) $item['quantity'];

            $sel = isset($sel_all[$key]) ? $sel_all[$key] : ['mode'=>'versand','old_bundle'=>0,'etage_alt'=>0,'express'=>0,'tower'=>0,'kochfeld_type'=>''];

            if ($sel['mode']==='abholung') continue;

            if ($sel['mode']==='versand') {
                $has_shipping = true;
                $freight = self::freight_base_for_product($product);
                if ($freight > 0) {
                    for ($i=0;$i<$qty;$i++) $ship_freight_bases[] = (float)$freight;
                } else {
                    $post = self::post_tariff_for_product($product);
                    $ship_post_sum += $post * $qty;
                }
                continue;
            }

            if ($sel['mode']==='etage') {
                $has_service = true;
                if (!$within) {
                    // außerhalb Radius → Etagenlieferung nicht verfügbar
                    continue;
                }
                $base = self::etage_base_for_product($product);
                if ($base > 0) {
                    $min   = self::minutes_for_plz($plz);
                    $extra = max(0, $min - (int)$p['free_min']);
                    $line  = ($base + $extra * (float)$p['rate_per_min']) * $qty;
                    if (!empty($sel['etage_alt'])) $line += (float)$p['etage_alt_mitnahme_add'] * $qty;
                    // Schwer & hoch Zuschlag (beide Kriterien)
                    $wkg = (float) wc_get_weight((float)$product->get_weight(), 'kg');
                    $hcm = (float) wc_get_dimension((float)$product->get_height(), 'cm');
                    if ($wkg > (float)$p['etage_surcharge_weight_kg'] && $hcm > (float)$p['etage_surcharge_height_cm']){
                        $line += (float)$p['etage_surcharge_add'] * $qty;
                    }
                    $t = sprintf('%dx Etagenlieferung %s', $qty, self::brand_or_name($product));
                    if (!empty($sel['etage_alt'])) $t .= ' – mit Mitnahme Altgerät';
                    $etage_items[] = [ 'title' => $t, 'amount'=> $line ];
                    $etage_sum += $line;
                }
                continue;
            }

            if ($sel['mode']==='montage') {
                $has_service = true;
                if (!$within || !self::is_montage_allowed_for_product($product)) {
                    // außerhalb Radius → Montage ignorieren (Nutzer sieht Hinweis in UI)
                    continue;
                }
                // Kategorie-Slug einmal ermitteln (für Kochfeld/Turm)
                $cat = self::product_primary_cat_slug($product);
                $base = self::montage_base_for_product($product);
                // Override base for Kochfeld montage types if selected
                if (str_contains($cat, '/kochfeld/') || str_contains($cat,'/induktions-kochfeld/')){
                    if (($sel['kochfeld_type'] ?? '')==='flat' && (float)$p['kochfeld_flat_base']>0) {
                        $base = (float)$p['kochfeld_flat_base'];
                    }
                    if (($sel['kochfeld_type'] ?? '')==='overlay' && (float)$p['kochfeld_overlay_base']>0) {
                        $base = (float)$p['kochfeld_overlay_base'];
                    }
                }
                if ($base <= 0) continue;

                $min   = self::minutes_for_plz($plz);
                $extra = max(0, $min - (int)$p['free_min']);
                $line_base = ($base + $extra * (float)$p['rate_per_min']) * $qty;

                $old_fee = !empty($sel['old_bundle']) ? (float)$p['old_item_fee'] * $qty : 0.0;

                // Turm-Montage (wenn Wasch/Trockner)
                $tower_fee = 0.0;
                if (!empty($sel['tower'])) {
                    $tower_cats = [
                        'haushaltgeraete/waschen-trocknen-und-saugen/waschmaschine/',
                        'haushaltgeraete/waschen-trocknen-und-saugen/waermepumpentrockner/',
                        'haushaltgeraete/waschen-trocknen-und-saugen/waeschetrockner/',
                        'haushaltgeraete/waschen-trocknen-und-saugen/waschtrockner-kombigeraet/',
                        'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waschmaschine/',
                        'haushaltgeraete/waschen-trocknen-und-saugen/mfh-waeschetrockner/',
                    ];
                    foreach($tower_cats as $tc){ if (str_starts_with($cat,$tc)) { $tower_fee = (float)$p['tower_fee'] * $qty; break; } }
                }

                // Kochfeld Montageart Aufpreise
                $koch_add = 0.0;
                if (str_contains($cat, '/kochfeld/') || str_contains($cat,'/induktions-kochfeld/')){
                    if (($sel['kochfeld_type'] ?? '') === 'flat') $koch_add = (float)$p['kochfeld_flat_add'] * $qty;
                    if (($sel['kochfeld_type'] ?? '') === 'overlay') $koch_add = (float)$p['kochfeld_overlay_add'] * $qty;
                }

                // Express pro Position (optional)
                $exp_fee = 0.0;
                if (!empty($sel['express'])) {
                    $thr   = (int) $p['express_thresh_min'];
                    $extraE= max(0, $min - $thr);
                    $exp_fee = ((float)$p['express_base'] + $extraE * (float)$p['express_per_min']) * $qty;
                }

                // Kühlschrank groß Zuschlag
                $fridge_add = 0.0;
                if (str_starts_with($cat,'haushaltgeraete/kuehlen-und-gefrieren/kuehlschrank/') || str_starts_with($cat,'haushaltgeraete/kuehlen-und-gefrieren/kuehl-gefrierkombi/')){
                    $thr = (float)$p['fridge_height_thresh_cm'];
                    $add = (float)$p['fridge_height_add'];
                    $Hcm = (float) wc_get_dimension( (float)$product->get_height(), 'cm' );
                    if ($Hcm > $thr && $add>0) $fridge_add = $add * $qty;
                }

                $line_total = $line_base + $old_fee + $tower_fee + $koch_add + $exp_fee + $fridge_add;
                $montage_sum   += $line_total;
                $montage_count += $qty;
                $has_montage    = true;

                $title = sprintf('%dx Montage %s', $qty, self::brand_or_name($product));
                if ($old_fee > 0) $title .= sprintf(' – inkl. Altgerät-Mitnahme (×%d)', $qty);
                if ($tower_fee > 0) $title .= ' – Turm';
                if ($koch_add > 0) {
                    if (($sel['kochfeld_type'] ?? '')==='flat') $title .= ' – Kochfeld flächenbündig';
                    if (($sel['kochfeld_type'] ?? '')==='overlay') $title .= ' – Kochfeld aufliegend';
                }
                if ($exp_fee > 0) $title .= sprintf(' – Express (Ziel: %d AT)', (int)$p['express_days']);
                if ($fridge_add > 0) $title .= ' – Zuschlag (Höhe)';
                $cart->add_fee($title, $line_total, true);
                continue;
            }
        }

        // Etagenlieferung Ausgabe: Einzelzeile wenn 1, sonst Gesamt
        if ($etage_sum > 0) {
            if (count($etage_items) === 1) {
                $cart->add_fee($etage_items[0]['title'], $etage_items[0]['amount'], true);
            } else {
                $cart->add_fee('Etagenlieferung (Gesamt)', $etage_sum, true);
            }
        }

        // Versandkosten: Wenn es irgendeinen Service (Montage/Etagenlieferung) gibt → Versand gratis, Hinweiszeile
        if (!$has_service) {
            // Versand - Post (ohne Pooling)
            if ($ship_post_sum > 0) {
                $cart->add_fee('Versand (Post)', $ship_post_sum, true);
            }
            // Versand - Haushaltgeräte (Pooling)
            if (!empty($ship_freight_bases)) {
                rsort($ship_freight_bases);
                $sum = array_shift($ship_freight_bases);
                foreach ($ship_freight_bases as $b) $sum += 0.5 * $b;
                $cart->add_fee('Versand (Gesamt)', $sum, true);
            }
        } else {
            if ($has_shipping) {
                $cart->add_fee('Versand gratis (wg. Montage/Etage)', 0, true);
            }
        }

        // Rabatte bei Montage vorhanden
        if ($has_montage) {
            // Stückzahlrabatt nur auf Montage-Anteile
            $disc = 0.0;
            if ($montage_count >= 4)      $disc = (float)$p['mont_disc_4'];
            elseif ($montage_count >= 3)  $disc = (float)$p['mont_disc_3'];
            elseif ($montage_count >= 2)  $disc = (float)$p['mont_disc_2'];
            if ($disc > 0 && $montage_sum > 0){
                $cart->add_fee('Montage‑Rabatt (Stückzahl)', - round($montage_sum * ($disc/100), 2), true);
            }

            // Versand-Rabatt
            $ship_disc = (float)$p['ship_disc_with_mont'];
            if ($ship_disc > 0) {
                $ship_total = 0.0;
                foreach ($cart->get_fees() as $fee) {
                    if (stripos($fee->name,'Versand') !== false) $ship_total += (float)$fee->amount;
                }
                if ($ship_total > 0) {
                    $cart->add_fee('Versand-Rabatt (wg. Montage)', - round($ship_total * ($ship_disc/100), 2), true);
                }
            }
            // Etagen-Rabatt
            $et_disc = (float)$p['etage_disc_with_mont'];
            if ($et_disc > 0 && $etage_sum > 0) {
                $cart->add_fee('Etagenlieferung-Rabatt (wg. Montage)', - round($etage_sum * ($et_disc/100), 2), true);
            }
        }
    }
}

SG_Montagerechner_V3::init();

require_once __DIR__ . '/src/bootstrap.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/includes/class-sgmr-cli.php';
}

if (!function_exists('sgmr_log')) {
    function sgmr_log(string $key, array $context = []): void
    {
        $line = '[sgmr] ' . $key . ' ' . wp_json_encode($context);
        error_log($line);
        if (!empty($context['order_id'])) {
            $order = wc_get_order((int) $context['order_id']);
            if ($order instanceof WC_Order) {
                $order->add_order_note($line);
                if (function_exists('sgmr_append_timeline_entry')) {
                    sgmr_append_timeline_entry($order, $key, $context);
                }
            }
        }
    }
}

if (!function_exists('sgmr_mask_link')) {
    function sgmr_mask_link(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return 'hash:' . substr(hash('sha256', $url), 0, 16);
    }
}

if (!function_exists('sgmr_normalize_region_slug')) {
    function sgmr_normalize_region_slug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = str_replace(['-', ' '], '_', $slug);
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug);
        $aliases = [
            'zurich_limmattal' => 'zuerich_limmattal',
            'zuerich_limmattal' => 'zuerich_limmattal',
            'aargau_sued_zentral' => 'aargau_sued_zentralschweiz',
            'aargau_sued_zentralschweiz' => 'aargau_sued_zentralschweiz',
            'basel_fricktal' => 'basel_fricktal',
            'mittelland_west' => 'mittelland_west',
            'on_request' => 'on_request',
        ];
        return $aliases[$slug] ?? $slug;
    }
}

if (!function_exists('sgmr_sanitize_diag_entry')) {
    function sgmr_sanitize_diag_entry(array $entry): array
    {
        $sanitized = [];
        foreach ($entry as $key => $value) {
            $key = sanitize_key(is_string($key) ? $key : (string) $key);
            if ($key === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = is_string($value) ? sanitize_text_field((string) $value) : $value;
            }
        }
        return $sanitized;
    }
}

if (!function_exists('sgmr_append_diag_log')) {
    function sgmr_append_diag_log(string $channel, array $entry, int $limit = 200): void
    {
        $channel = sanitize_key($channel);
        if ($channel === '') {
            return;
        }
        $option = 'sgmr_diag_' . $channel;
        $log = get_option($option, []);
        if (!is_array($log)) {
            $log = [];
        }
        $sanitizedEntry = sgmr_sanitize_diag_entry($entry);
        $sanitizedEntry['ts'] = current_time('mysql');
        $log[] = $sanitizedEntry;
        if ($limit > 0 && count($log) > $limit) {
            $log = array_slice($log, -$limit);
        }
        update_option($option, $log, false);
    }
}

if (!function_exists('sgmr_get_diag_log')) {
    function sgmr_get_diag_log(string $channel, int $limit = 50, bool $latestFirst = true): array
    {
        $channel = sanitize_key($channel);
        if ($channel === '') {
            return [];
        }
        $log = get_option('sgmr_diag_' . $channel, []);
        if (!is_array($log)) {
            return [];
        }
        if ($limit > 0 && count($log) > $limit) {
            $log = array_slice($log, -$limit);
        }
        if ($latestFirst) {
            $log = array_reverse($log);
        }
        return array_map('sgmr_sanitize_diag_entry', $log);
    }
}

if (!function_exists('sgmr_booking_legacy_params_enabled')) {
    function sgmr_booking_legacy_params_enabled(): bool
    {
        $default = get_option('sgmr_booking_legacy_params_enabled', 1);
        return (bool) apply_filters('sgmr_booking_legacy_params_enabled', (bool) $default);
    }
}

if (!function_exists('sgmr_booking_signature_normalize_params')) {
    function sgmr_booking_signature_normalize_params(int $orderId, array $params, ?WC_Order $order = null): array
    {
        $normalized = [];

        if (isset($params['region'])) {
            $normalized['region'] = sgmr_normalize_region_slug((string) $params['region']);
        }
        $legacyAllowed = sgmr_booking_legacy_params_enabled();

        if (isset($params['sgm'])) {
            $normalized['sgm'] = max(0, (int) $params['sgm']);
        } elseif ($legacyAllowed && isset($params['m'])) {
            $normalized['sgm'] = max(0, (int) $params['m']);
        }

        if (isset($params['sge'])) {
            $normalized['sge'] = max(0, (int) $params['sge']);
        } elseif ($legacyAllowed && isset($params['e'])) {
            $normalized['sge'] = max(0, (int) $params['e']);
        }

        $needsOrder = !isset($normalized['region']) || !isset($normalized['sgm']) || !isset($normalized['sge']);
        if ($needsOrder) {
            if (!$order instanceof WC_Order) {
                $order = wc_get_order($orderId);
            }
            if ($order instanceof WC_Order) {
                if (!isset($normalized['region'])) {
                    $region = (string) $order->get_meta(\SGMR\Services\CartService::META_REGION_KEY, true);
                    $normalized['region'] = sgmr_normalize_region_slug($region);
                }
                if (!isset($normalized['sgm']) || !isset($normalized['sge'])) {
                    $counts = [];
                    if (class_exists('SGMR\\Services\\CartService') && method_exists('SGMR\\Services\\CartService', 'ensureOrderCounts')) {
                        $counts = \SGMR\Services\CartService::ensureOrderCounts($order);
                    }
                    if (!isset($normalized['sgm'])) {
                        $normalized['sgm'] = isset($counts['montage']) ? (int) $counts['montage'] : 0;
                    }
                    if (!isset($normalized['sge'])) {
                        $normalized['sge'] = isset($counts['etage']) ? (int) $counts['etage'] : 0;
                    }
                }
            }
        }

        $normalized['region'] = isset($normalized['region']) ? $normalized['region'] : '';
        $normalized['sgm'] = isset($normalized['sgm']) ? max(0, (int) $normalized['sgm']) : 0;
        $normalized['sge'] = isset($normalized['sge']) ? max(0, (int) $normalized['sge']) : 0;

        return $normalized;
    }
}

if (!function_exists('sgmr_booking_signature_payload')) {
    function sgmr_booking_signature_payload(int $orderId, array $params, int $timestamp): array
    {
        $payload = [
            'sge' => (string) max(0, (int) ($params['sge'] ?? 0)),
            'sgm' => (string) max(0, (int) ($params['sgm'] ?? 0)),
            'order' => (string) $orderId,
            'region' => sgmr_normalize_region_slug($params['region'] ?? ''),
            'ts' => (int) $timestamp,
        ];
        return $payload;
    }
}

if (!function_exists('sgmr_booking_signature_payload_legacy')) {
    function sgmr_booking_signature_payload_legacy(int $orderId, array $params, int $timestamp): array
    {
        return [
            'e' => (string) max(0, (int) ($params['sge'] ?? 0)),
            'm' => (string) max(0, (int) ($params['sgm'] ?? 0)),
            'order' => (string) $orderId,
            'region' => sgmr_normalize_region_slug($params['region'] ?? ''),
            'ts' => (int) $timestamp,
        ];
    }
}

if (!function_exists('sgmr_booking_signature_canonical')) {
    function sgmr_booking_signature_canonical(array $payload): string
    {
        ksort($payload, SORT_STRING);
        return http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('sgmr_booking_signature')) {
    function sgmr_booking_signature(int $orderId, array $params = [], ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?: time();
        $normalized = sgmr_booking_signature_normalize_params($orderId, $params);
        $payload = sgmr_booking_signature_payload($orderId, $normalized, $timestamp);
        $canonical = sgmr_booking_signature_canonical($payload);
        $secret = \SGMR\Plugin::instance()->bookingSecret();
        $hash = hash_hmac('sha256', $canonical, $secret);
        return $timestamp . '.' . $hash;
    }
}

if (!function_exists('sgmr_booking_signature_parse')) {
    /**
     * @param string $signature
     * @return array{ts:int, hash:string}
     */
    function sgmr_booking_signature_parse(string $signature): array
    {
        $signature = trim($signature);
        if ($signature === '') {
            return ['ts' => 0, 'hash' => ''];
        }
        $parts = explode('.', $signature, 2);
        if (count($parts) !== 2) {
            return ['ts' => 0, 'hash' => ''];
        }
        [$timestampRaw, $hash] = $parts;
        if (!preg_match('/^\d{10,}$/', $timestampRaw)) {
            return ['ts' => 0, 'hash' => ''];
        }
        if (!preg_match('/^[a-f0-9]{32,128}$/i', $hash)) {
            return ['ts' => 0, 'hash' => ''];
        }
        return ['ts' => (int) $timestampRaw, 'hash' => strtolower($hash)];
    }
}

if (!function_exists('sgmr_booking_signature_timestamp')) {
    function sgmr_booking_signature_timestamp(string $signature): int
    {
        $parsed = sgmr_booking_signature_parse($signature);
        return $parsed['ts'];
    }
}

if (!function_exists('sgmr_validate_booking_signature')) {
    function sgmr_validate_booking_signature(int $orderId, string $signature, int $ttl = WEEK_IN_SECONDS, array $params = []): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        $parsed = sgmr_booking_signature_parse($signature);
        if ($parsed['ts'] <= 0 || $parsed['hash'] === '') {
            return false;
        }
        if (abs(time() - $parsed['ts']) > $ttl) {
            return false;
        }
        $secret = \SGMR\Plugin::instance()->bookingSecret();
        $normalized = sgmr_booking_signature_normalize_params($orderId, $params);
        $payload = sgmr_booking_signature_payload($orderId, $normalized, $parsed['ts']);
        $canonical = sgmr_booking_signature_canonical($payload);
        $expected = hash_hmac('sha256', $canonical, $secret);
        $hash = strtolower((string) $parsed['hash']);
        if (hash_equals($expected, $hash)) {
            return true;
        }

        if (!sgmr_booking_legacy_params_enabled()) {
            return false;
        }

        $legacyPayload = sgmr_booking_signature_payload_legacy($orderId, $normalized, $parsed['ts']);
        $legacyCanonical = sgmr_booking_signature_canonical($legacyPayload);
        $legacyExpected = hash_hmac('sha256', $legacyCanonical, $secret);
        return hash_equals($legacyExpected, $hash);
    }
}

if (!function_exists('sgmr_order_service_descriptor')) {
    function sgmr_order_service_descriptor(\WC_Order $order): array
    {
        $flags = [
            'montage' => false,
            'etage' => false,
            'versand' => false,
            'abholung' => false,
        ];
        if (class_exists('SGMR\\Services\\CartService')) {
            $selection = $order->get_meta(\SGMR\Services\CartService::META_SELECTION);
            if (is_array($selection)) {
                foreach ($selection as $row) {
                    $mode = isset($row['mode']) ? (string) $row['mode'] : '';
                    switch ($mode) {
                        case 'montage':
                            $flags['montage'] = true;
                            break;
                        case 'etage':
                            $flags['etage'] = true;
                            break;
                        case 'versand':
                            $flags['versand'] = true;
                            break;
                        case 'abholung':
                            $flags['abholung'] = true;
                            break;
                    }
                }
            }
            if (method_exists('SGMR\\Services\\CartService', 'ensureOrderCounts')) {
                $counts = \SGMR\Services\CartService::ensureOrderCounts($order);
                if (($counts['montage'] ?? 0) > 0) {
                    $flags['montage'] = true;
                }
                if (($counts['etage'] ?? 0) > 0) {
                    $flags['etage'] = true;
                }
            }
        }
        if (!$flags['versand']) {
            if ((float) $order->get_shipping_total() > 0) {
                $flags['versand'] = true;
            } else {
                foreach ($order->get_items('fee') as $fee) {
                    if (stripos($fee->get_name(), 'versand') !== false) {
                        $flags['versand'] = true;
                        break;
                    }
                }
            }
        }
        $label = (function () use ($flags) {
            if ($flags['montage'] && $flags['etage'] && $flags['versand']) {
                return __('Versand + Montage/Etagenlieferung', 'sg-mr');
            }
            if ($flags['montage'] && $flags['etage']) {
                return __('Montage/Etagenlieferung', 'sg-mr');
            }
            if ($flags['montage'] && $flags['versand']) {
                return __('Versand + Montage', 'sg-mr');
            }
            if ($flags['etage'] && $flags['versand']) {
                return __('Versand + Etagenlieferung', 'sg-mr');
            }
            if ($flags['montage']) {
                return __('Montage', 'sg-mr');
            }
            if ($flags['etage']) {
                return __('Etagenlieferung', 'sg-mr');
            }
            if ($flags['versand']) {
                return __('Versand', 'sg-mr');
            }
            if ($flags['abholung']) {
                return __('Abholung', 'sg-mr');
            }
            return __('Service', 'sg-mr');
        })();

        return [
            'label' => $label,
            'flags' => $flags,
        ];
    }
}

if (!function_exists('sgmr_sanitize_timeline_context')) {
    function sgmr_sanitize_timeline_context(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $idx = is_string($key) ? sanitize_key($key) : (string) $key;
            if (is_string($value)) {
                $sanitized[$idx] = sanitize_text_field($value);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $sanitized[$idx] = $value;
            } elseif (is_array($value)) {
                $sanitized[$idx] = sgmr_sanitize_timeline_context($value);
            }
        }
        return $sanitized;
    }
}

if (!function_exists('sgmr_append_timeline_entry')) {
    function sgmr_append_timeline_entry(\WC_Order $order, string $key, array $context): void
    {
        $timeline = $order->get_meta('_sgmr_timeline', true);
        if (!is_array($timeline)) {
            $timeline = [];
        }
        if (isset($context['order_id'])) {
            unset($context['order_id']);
        }
        $timeline[] = [
            'time' => current_time('mysql'),
            'key' => sanitize_key($key),
            'context' => sgmr_sanitize_timeline_context($context),
        ];
        if (count($timeline) > 200) {
            $timeline = array_slice($timeline, -200);
        }
        $order->update_meta_data('_sgmr_timeline', $timeline);
        $order->save_meta_data();
    }
}

if (!function_exists('sgmr_get_timeline')) {
    function sgmr_get_timeline(\WC_Order $order): array
    {
        $timeline = $order->get_meta('_sgmr_timeline', true);
        if (!is_array($timeline)) {
            return [];
        }
        usort($timeline, static function ($a, $b) {
            $ta = isset($a['time']) ? $a['time'] : '';
            $tb = isset($b['time']) ? $b['time'] : '';
            return strcmp((string) $ta, (string) $tb);
        });
        return $timeline;
    }
}

if (!function_exists('sgmr_timeline_status_label')) {
    function sgmr_timeline_status_label(string $slug): string
    {
        $slug = sanitize_key(preg_replace('/^wc-/', '', $slug));
        if ($slug === '') {
            return '';
        }
        if (function_exists('wc_get_order_status_name')) {
            $label = wc_get_order_status_name('wc-' . $slug);
            if ($label) {
                return $label;
            }
        }
        return strtoupper(str_replace('-', ' ', $slug));
    }
}

if (!function_exists('sgmr_timeline_event_label')) {
    function sgmr_timeline_event_label(string $key): string
    {
        $map = [
            'status_change' => __('Statuswechsel', 'sg-mr'),
            'region_assignment' => __('Region zugeordnet', 'sg-mr'),
            'booking_page_opened' => __('Buchungsseite geöffnet', 'sg-mr'),
            'booking_page_open_failed' => __('Buchungsseite Fehler', 'sg-mr'),
            'prefill_applied' => __('Prefill angewendet', 'sg-mr'),
            'trigger_paid_stage' => __('Automatik: Zahlung verarbeitet', 'sg-mr'),
            'trigger_arrived_stage' => __('Automatik: Wareneingang', 'sg-mr'),
            'trigger_booking_created' => __('Online-Buchung erstellt', 'sg-mr'),
            'trigger_booking_rescheduled' => __('Online-Buchung verschoben', 'sg-mr'),
            'trigger_booking_cancelled' => __('Online-Buchung storniert', 'sg-mr'),
            'trigger_booking_completed' => __('Online-Buchung abgeschlossen', 'sg-mr'),
            'webhook_booking_created' => __('Webhook: Selector erstellt', 'sg-mr'),
            'webhook_booking_created_failed' => __('Webhook: Selector erstellt (Fehler)', 'sg-mr'),
            'webhook_booking_rescheduled' => __('Webhook: Selector verschoben', 'sg-mr'),
            'webhook_booking_rescheduled_failed' => __('Webhook: Selector verschoben (Fehler)', 'sg-mr'),
            'webhook_booking_cancelled' => __('Webhook: Selector storniert', 'sg-mr'),
            'webhook_booking_cancelled_failed' => __('Webhook: Selector storniert (Fehler)', 'sg-mr'),
            'composite_bookings_created' => __('Finale Buchungen erstellt', 'sg-mr'),
            'composite_bookings_cancelled' => __('Finale Buchungen storniert', 'sg-mr'),
            'fb_booking_orchestrated' => __('Orchestrierung abgeschlossen', 'sg-mr'),
            'selector_cancelled' => __('Selector storniert', 'sg-mr'),
            'selector_kept' => __('Selector beibehalten', 'sg-mr'),
            'fluent_booking_create_failed' => __('FluentBooking: Erstellung fehlgeschlagen', 'sg-mr'),
            'fluent_booking_cancel_failed' => __('FluentBooking: Storno fehlgeschlagen', 'sg-mr'),
            'fluent_booking_selector_cancelled' => __('FluentBooking: Selector storniert', 'sg-mr'),
            'fluent_booking_selector_cancel_failed' => __('FluentBooking: Selector-Storno fehlgeschlagen', 'sg-mr'),
            'fluent_booking_selector_cancel_skipped' => __('FluentBooking: Selector-Storno übersprungen', 'sg-mr'),
        ];
        $key = sanitize_key($key);
        return $map[$key] ?? strtoupper(str_replace('_', ' ', $key));
    }
}

if (!function_exists('sgmr_timeline_reason_label')) {
    function sgmr_timeline_reason_label(string $reason): string
    {
        $map = [
            'already_sent' => __('Bereits versendet', 'sg-mr'),
            'no_link' => __('Kein Buchungslink verfügbar', 'sg-mr'),
            'await_arrival' => __('Ware noch nicht eingetroffen', 'sg-mr'),
            'mode_unknown' => __('Terminart unbekannt', 'sg-mr'),
            'mode_not_online' => __('Nicht für Online-Terminierung vorgesehen', 'sg-mr'),
            'transition_blocked' => __('Statuswechsel blockiert', 'sg-mr'),
            'manual_completion_required' => __('Abschluss erfolgt manuell im Backoffice', 'sg-mr'),
        ];
        $reason = sanitize_key($reason);
        return $map[$reason] ?? strtoupper(str_replace('_', ' ', $reason));
    }
}

if (!function_exists('sgmr_timeline_link_reason_label')) {
    function sgmr_timeline_link_reason_label(string $reason): string
    {
        $map = [
            'ok' => __('Standard-Link', 'sg-mr'),
            'fallback_no_m_e' => __('Fallback-Link (m/e ergänzt)', 'sg-mr'),
            'no_region' => __('Kein Link – Region fehlt', 'sg-mr'),
            'other' => __('Fallback-Link', 'sg-mr'),
            'not_applicable' => __('Nicht erforderlich', 'sg-mr'),
        ];
        $reason = sanitize_key($reason);
        return $map[$reason] ?? strtoupper(str_replace('_', ' ', $reason));
    }
}

if (!function_exists('sgmr_timeline_region_source_label')) {
    function sgmr_timeline_region_source_label(string $source): string
    {
        $map = [
            'shipping' => __('Lieferadresse', 'sg-mr'),
            'billing' => __('Rechnungsadresse', 'sg-mr'),
            'service_plz' => __('Service-PLZ', 'sg-mr'),
            'none' => __('Unbekannt', 'sg-mr'),
        ];
        return $map[$source] ?? strtoupper(str_replace('_', ' ', $source));
    }
}

if (!function_exists('sgmr_timeline_lookup_label')) {
    function sgmr_timeline_lookup_label(string $lookup): string
    {
        $map = [
            'cache' => __('Mapping-Cache', 'sg-mr'),
            'rae' => __('Regel-Engine', 'sg-mr'),
            'none' => __('Unbekannt', 'sg-mr'),
        ];
        return $map[$lookup] ?? strtoupper(str_replace('_', ' ', $lookup));
    }
}

if (!function_exists('sgmr_render_timeline_metabox')) {
    function sgmr_render_timeline_metabox($post): void
    {
        $order = wc_get_order($post->ID);
        if (!$order instanceof \WC_Order) {
            echo '<p>' . esc_html__('Keine Bestellung gefunden.', 'sg-mr') . '</p>';
            return;
        }
        $timeline = sgmr_get_timeline($order);
        if (empty($timeline)) {
            echo '<p>' . esc_html__('Keine Ereignisse aufgezeichnet.', 'sg-mr') . '</p>';
            return;
        }

        static $timelineStylePrinted = false;
        if (!$timelineStylePrinted) {
            $timelineStylePrinted = true;
            echo '<style>
            .sgmr-timeline{margin:0;padding:0;}
            .sgmr-timeline ol{list-style:none;margin:0;padding:0;}
            .sgmr-timeline li{border-left:2px solid #dcdcde;margin:0 0 16px 0;padding:0 0 0 14px;position:relative;}
            .sgmr-timeline li::before{content:"";position:absolute;left:-5px;top:4px;width:10px;height:10px;background:#1e8cbe;border-radius:50%;}
            .sgmr-timeline .sgmr-time{font-weight:600;display:block;margin-bottom:4px;}
            .sgmr-timeline .sgmr-label{font-size:13px;color:#555;margin-bottom:6px;display:block;}
            .sgmr-timeline-details{list-style:none;margin:0;padding:0;}
            .sgmr-timeline-details li{margin:0 0 4px 0;}
            .sgmr-timeline-details span{font-weight:600;margin-right:6px;display:inline-block;min-width:140px;color:#2c3338;}
            .sgmr-timeline-details code{background:#f6f7f7;padding:1px 4px;border-radius:3px;display:inline-block;}
            </style>';
        }

        echo '<div class="sgmr-timeline"><ol>';
        foreach ($timeline as $entry) {
            $timeRaw = isset($entry['time']) ? $entry['time'] : '';
            $timeLabel = $timeRaw;
            if ($timeRaw && class_exists('WC_DateTime')) {
                try {
                    $dt = new \WC_DateTime($timeRaw);
                    $timeLabel = wc_format_datetime($dt, get_option('date_format') . ' ' . get_option('time_format'));
                } catch (\Exception $e) {
                    $timeLabel = $timeRaw;
                }
            }
            $keyLabel = sgmr_timeline_event_label($entry['key'] ?? '');
            $context = isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : [];

            $details = [];
            if (!empty($context['from']) || !empty($context['to'])) {
                $fromStatus = !empty($context['from']) ? sgmr_timeline_status_label($context['from']) : __('(unbekannt)', 'sg-mr');
                $toStatus = !empty($context['to']) ? sgmr_timeline_status_label($context['to']) : __('(unbekannt)', 'sg-mr');
                $details[] = [__('Status', 'sg-mr'), sprintf('%s → %s', $fromStatus, $toStatus)];
                unset($context['from'], $context['to']);
            }

            if (!empty($context['terminart'])) {
                $mode = $context['terminart'] === 'telefonisch' ? __('Telefonisch', 'sg-mr') : __('Online', 'sg-mr');
                $details[] = [__('Terminart', 'sg-mr'), $mode];
                unset($context['terminart']);
            }

            if (isset($context['wirklich_an_lager'])) {
                $flag = $context['wirklich_an_lager'] === 'yes';
                $details[] = [__('Ware verfügbar', 'sg-mr'), $flag ? __('Ja', 'sg-mr') : __('Nein', 'sg-mr')];
                unset($context['wirklich_an_lager']);
            }

            if (!empty($context['email_template']) && $context['email_template'] !== 'none') {
                $emailMap = [
                    'instant' => __('Termin freigegeben (sofort)', 'sg-mr'),
                    'arrived' => __('Ware eingetroffen (Termin wählen)', 'sg-mr'),
                    'offline' => __('Telefonische Terminvereinbarung', 'sg-mr'),
                    'paid_wait' => __('Zahlung erhalten – Warte auf Wareneingang', 'sg-mr'),
                ];
                $template = sanitize_key($context['email_template']);
                $details[] = [__('E-Mail', 'sg-mr'), $emailMap[$template] ?? strtoupper(str_replace('_', ' ', $template))];
                unset($context['email_template']);
            }

            if (isset($context['email_sent'])) {
                $details[] = [__('E-Mail versendet', 'sg-mr'), $context['email_sent'] ? __('Ja', 'sg-mr') : __('Nein', 'sg-mr')];
                unset($context['email_sent']);
            }

            if (!empty($context['reason'])) {
                $details[] = [__('Hinweis', 'sg-mr'), sgmr_timeline_reason_label($context['reason'])];
                unset($context['reason']);
            }

            if (!empty($context['auto_transition'])) {
                $target = sgmr_timeline_status_label($context['auto_transition']);
                $status = !empty($context['auto_transition_status']) && $context['auto_transition_status'] === 'done' ? __('erfolgreich', 'sg-mr') : __('blockiert', 'sg-mr');
                $details[] = [__('Automatischer Status', 'sg-mr'), sprintf('%s (%s)', $target, $status)];
                unset($context['auto_transition'], $context['auto_transition_status']);
            }

            if (!empty($context['order_region'])) {
                $details[] = [
                    __('Region (Auftrag)', 'sg-mr'),
                    \SGMR\Utils\PostcodeHelper::regionLabel($context['order_region']) . ' [' . $context['order_region'] . ']'
                ];
                unset($context['order_region']);
            }

            if (!empty($context['region'])) {
                $details[] = [
                    __('Region', 'sg-mr'),
                    \SGMR\Utils\PostcodeHelper::regionLabel($context['region']) . ' [' . $context['region'] . ']'
                ];
                unset($context['region']);
            }

            if (!empty($context['postcode'])) {
                $details[] = [__('PLZ', 'sg-mr'), $context['postcode']];
                unset($context['postcode']);
            }

            if (!empty($context['region_source'])) {
                $details[] = [__('Quelle', 'sg-mr'), sgmr_timeline_region_source_label($context['region_source'])];
                unset($context['region_source']);
            }

            if (!empty($context['region_lookup'])) {
                $details[] = [__('Ermittelt über', 'sg-mr'), sgmr_timeline_lookup_label($context['region_lookup'])];
                unset($context['region_lookup']);
            }

            if (!empty($context['region_rule'])) {
                $details[] = [__('Regel', 'sg-mr'), $context['region_rule']];
                unset($context['region_rule']);
            }

            if (isset($context['allowed'])) {
                $details[] = [__('Innerhalb Radius', 'sg-mr'), $context['allowed'] === 'yes' ? __('Ja', 'sg-mr') : __('Nein', 'sg-mr')];
                unset($context['allowed']);
            }

            if (!empty($context['link_build_reason'])) {
                $details[] = [__('Link-Auswertung', 'sg-mr'), sgmr_timeline_link_reason_label($context['link_build_reason'])];
                unset($context['link_build_reason']);
            }

            if (isset($context['link_sig'])) {
                unset($context['link_sig']);
            }

            if (!empty($context['link_masked'])) {
                $details[] = [__('Buchungslink', 'sg-mr'), '<code>' . esc_html($context['link_masked']) . '</code>'];
                unset($context['link_masked']);
            }

            if (isset($context['payload']) && is_array($context['payload'])) {
                $json = wp_json_encode($context['payload'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $details[] = [__('Payload', 'sg-mr'), '<pre style="white-space:pre-wrap;margin:0;">' . esc_html($json) . '</pre>'];
                unset($context['payload']);
            }

            if (!empty($context['next_status'])) {
                $details[] = [__('Neuer Status', 'sg-mr'), sgmr_timeline_status_label($context['next_status'])];
                unset($context['next_status']);
            }

            if (!empty($context)) {
                $rest = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $details[] = [__('Weitere Daten', 'sg-mr'), '<pre style="white-space:pre-wrap;margin:0;">' . esc_html((string) $rest) . '</pre>'];
            }

            echo '<li>'; 
            echo '<span class="sgmr-time">' . esc_html($timeLabel) . '</span>';
            echo '<span class="sgmr-label">' . esc_html($keyLabel) . '</span>';
            if ($details) {
                echo '<ul class="sgmr-timeline-details">';
                foreach ($details as [$label, $value]) {
                    $isHtml = is_string($value) && strpos($value, '<') === 0;
                    echo '<li><span>' . esc_html($label) . ':</span> ' . ($isHtml ? $value : esc_html((string) $value)) . '</li>';
                }
                echo '</ul>';
            }
            echo '</li>';
        }
        echo '</ol></div>';
    }
}

if (!function_exists('sgmr_region_for_order')) {
    function sgmr_region_for_order(\WC_Order $order): string
    {
        $region = $order->get_meta(\SGMR\Services\CartService::META_REGION_KEY);
        return is_string($region) ? $region : '';
    }
}

if (!function_exists('sgmr_region_page_url')) {
    function sgmr_region_page_url(string $region): string
    {
        return \SGMR\Services\BookingLink::regionUrl($region);
    }
}

if (!function_exists('sgmr_build_booking_url')) {
    function sgmr_build_booking_url(\WC_Order $order, string $region_slug): string
    {
        return \SGMR\Services\BookingLink::build($order, $region_slug);
    }
}

function sgmr_allowed_transitions(\WC_Order $order, string $from): array
{
    $from = sanitize_key(preg_replace('/^wc-/', '', $from));

    $mode = '';
    $hasService = true;
    $inStock = false;

    if (class_exists('SGMR\\Services\\CartService')) {
        $mode = (string) $order->get_meta(\SGMR\Services\CartService::META_TERMIN_MODE);
        $hasService = \SGMR\Services\CartService::orderHasService($order);
        $inStock = \SGMR\Services\CartService::orderHasInstantStock($order);
        if (function_exists('sgmr_order_is_really_in_stock')) {
            $inStock = (bool) sgmr_order_is_really_in_stock($order);
        }
    }

    $online = $mode === 'online';
    $common = ['cancelled', 'refunded'];

    $allowed = [];

    switch ($from) {
        case 'pending':
            $allowed = ['pending', 'on-hold'];
            break;
        case 'on-hold':
            $allowed = ['on-hold'];
            if ($hasService) {
                $allowed[] = SGMR_STATUS_PAID;
            }
            break;
        case SGMR_STATUS_PAID:
            $allowed = [SGMR_STATUS_PAID, SGMR_STATUS_ARRIVED, 'processing', 'completed'];
            if ($hasService && $inStock) {
                $allowed[] = $online ? SGMR_STATUS_ONLINE : SGMR_STATUS_PHONE;
            }
            break;
        case 'processing':
        case 'completed':
            $allowed = [$from, SGMR_STATUS_ARRIVED];
            if ($hasService && $inStock) {
                $allowed[] = $online ? SGMR_STATUS_ONLINE : SGMR_STATUS_PHONE;
            }
            break;
        case SGMR_STATUS_ARRIVED:
            $allowed = [SGMR_STATUS_ARRIVED, SGMR_STATUS_BOOKED];
            if ($hasService) {
                $allowed[] = $online ? SGMR_STATUS_ONLINE : SGMR_STATUS_PHONE;
            }
            break;
        case SGMR_STATUS_ONLINE:
            $allowed = [SGMR_STATUS_ONLINE, SGMR_STATUS_PLANNED_ONLINE, SGMR_STATUS_BOOKED];
            break;
        case SGMR_STATUS_PHONE:
            $allowed = [SGMR_STATUS_PHONE, SGMR_STATUS_DONE];
            break;
        case SGMR_STATUS_PLANNED_ONLINE:
            $allowed = [SGMR_STATUS_PLANNED_ONLINE, SGMR_STATUS_ONLINE, SGMR_STATUS_DONE, SGMR_STATUS_BOOKED];
            break;
        case SGMR_STATUS_DONE:
            $allowed = [SGMR_STATUS_DONE];
            if ($hasService) {
                $allowed[] = $online ? SGMR_STATUS_ONLINE : SGMR_STATUS_PHONE;
            }
            break;
        default:
            $allowed = ['*'];
            break;
    }

    if ($hasService && !in_array(SGMR_STATUS_DONE, $allowed, true) && in_array($from, [SGMR_STATUS_ONLINE, SGMR_STATUS_PLANNED_ONLINE, SGMR_STATUS_PHONE], true)) {
        $allowed[] = SGMR_STATUS_DONE;
    }

    foreach ($common as $fallback) {
        if (!in_array($fallback, $allowed, true)) {
            $allowed[] = $fallback;
        }
    }

    $allowed = array_values(array_unique(array_filter(array_map('sanitize_key', $allowed))));

    /**
     * Filter the allowed transitions for a given status.
     *
     * @param string[]  $allowed Allowed target statuses (without "wc-" prefix).
     * @param string    $from    Current status (without "wc-" prefix).
     * @param \WC_Order $order   Order instance.
     */
    return apply_filters('sgmr_allowed_transitions', $allowed, $from, $order);
}

function sgmr_can_transition(\WC_Order $order, string $from, string $to): bool
{
    $from = preg_replace('/^wc-/', '', $from);
    $to   = preg_replace('/^wc-/', '', $to);
    if ($from === $to) {
        return true;
    }
    $allowed = sgmr_allowed_transitions($order, $from);
    if (in_array('*', $allowed, true)) {
        return true;
    }
    if (in_array($to, $allowed, true)) {
        return true;
    }
    return in_array($to, apply_filters('sgmr_fallback_allowed_statuses', [], $from, $order), true);
}

function sgmr_guard_order_status($order): void
{
    if (!$order instanceof \WC_Order) {
        return;
    }
    static $guarding = false;
    if ($guarding) {
        return;
    }
    if ((class_exists('SGMR\\Services\\CartService') || class_exists('Sanigroup\\Montagerechner\\Services\\CartService')) && !\SGMR\Services\CartService::orderHasService($order)) {
        return;
    }
    $new = preg_replace('/^wc-/', '', $order->get_status());
    $stored = $order->get_meta('_sgmr_last_status', true);
    if (!$stored) {
        $order->update_meta_data('_sgmr_last_status', $new);
        return;
    }
    if ($new === $stored) {
        return;
    }
    if (sgmr_can_transition($order, $stored, $new)) {
        return;
    }

    $guarding = true;
    $order->set_status($stored);

    $labelFor = static function (string $slug): string {
        $slug = sanitize_key(preg_replace('/^wc-/', '', $slug));
        $statusKey = 'wc-' . $slug;
        if (function_exists('wc_get_order_status_name')) {
            $label = wc_get_order_status_name($statusKey);
            if (!empty($label)) {
                return $label;
            }
        }
        return $slug;
    };

    $allowedSlugs = sgmr_allowed_transitions($order, $stored);
    if (in_array('*', $allowedSlugs, true)) {
        $allowedSlugs = [$stored];
    }
    $allowedSlugs = array_values(array_unique(array_map('sanitize_key', (array) $allowedSlugs)));
    $allowedNames = array_map($labelFor, $allowedSlugs);
    $allowedNames = array_filter($allowedNames);
    $allowedText = $allowedNames ? implode(', ', $allowedNames) : __('(keine)', 'sg-mr');

    $message = sprintf('[SGMR] Unzulässiger Statuswechsel von %s nach %s blockiert. Erlaubt: %s.', $labelFor($stored), $labelFor($new), $allowedText);
    $order->add_order_note($message);

    if (is_admin()) {
        add_action('admin_notices', function () use ($labelFor, $stored, $new, $allowedText) {
            $notice = sprintf(__('Unzulässiger Statuswechsel von %1$s nach %2$s. Erlaubte Ziele: %3$s.', 'sg-mr'), $labelFor($stored), $labelFor($new), $allowedText);
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($notice));
        });
    }

    $mode = 'unknown';
    $stockState = 'unknown';
    if (class_exists('SGMR\\Services\\CartService')) {
        $metaMode = (string) $order->get_meta(\SGMR\Services\CartService::META_TERMIN_MODE);
        if ($metaMode !== '') {
            $mode = $metaMode;
        }
        $stockState = \SGMR\Services\CartService::orderHasInstantStock($order) ? 'yes' : 'no';
    }

    sgmr_log('invalid_transition', [
        'order_id' => $order->get_id(),
        'from' => $stored,
        'to' => $new,
        'terminart' => $mode,
        'wirklich_an_lager' => $stockState,
        'email_template' => 'none',
        'email_sent' => false,
        'reason' => 'transition_blocked',
        'allowed' => $allowedSlugs,
    ]);
    $guarding = false;
}

function sgmr_update_last_status_meta($order_id, $from, $to, $order = null): void
{
    if (!$order instanceof \WC_Order) {
        $order = wc_get_order($order_id);
    }
    if (!$order instanceof \WC_Order) {
        return;
    }
    $order->update_meta_data('_sgmr_last_status', preg_replace('/^wc-/', '', $to));
    $order->save_meta_data();
}

function sgmr_initial_status_meta($order_id): void
{
    $order = wc_get_order($order_id);
    if (!$order instanceof \WC_Order) {
        return;
    }
    $order->update_meta_data('_sgmr_last_status', preg_replace('/^wc-/', '', $order->get_status()));
    $order->save_meta_data();
}

function sgmr_register_status_metabox(): void
{
    add_meta_box('sgmr_next_status', __('SGMR – Nächster Schritt', 'sg-mr'), 'sgmr_render_status_metabox', 'shop_order', 'side', 'high');
    add_meta_box('sgmr_timeline', __('SGMR – Timeline', 'sg-mr'), 'sgmr_render_timeline_metabox', 'shop_order', 'normal', 'default');
}

function sgmr_render_status_metabox($post): void
{
    $order = wc_get_order($post->ID);
    if (!$order instanceof \WC_Order) {
        echo '<p>' . esc_html__('Keine Bestellung gefunden.', 'sg-mr') . '</p>';
        return;
    }
    $hasService = true;
    if (class_exists('SGMR\\Services\\CartService')) {
        $hasService = \SGMR\Services\CartService::orderHasService($order);
    }
    if (!$hasService) {
        echo '<p>' . esc_html__('Keine Service-Leistung – kein geführter Ablauf nötig.', 'sg-mr') . '</p>';
        return;
    }
    $current = preg_replace('/^wc-/', '', $order->get_status());
    $allowed = sgmr_allowed_transitions($order, $current);
    $flowStatuses = [
        SGMR_STATUS_PAID,
        SGMR_STATUS_ARRIVED,
        SGMR_STATUS_ONLINE,
        SGMR_STATUS_PHONE,
        SGMR_STATUS_PLANNED_ONLINE,
        SGMR_STATUS_DONE,
    ];
    $targets = array_values(array_filter(array_unique($allowed), function ($slug) use ($current, $flowStatuses) {
        return $slug !== $current && in_array($slug, $flowStatuses, true);
    }));
    $buttons = [];
    foreach ($targets as $slug) {
        if (!sgmr_can_transition($order, $current, $slug)) {
            continue;
        }
        $label = function_exists('wc_get_order_status_name') ? wc_get_order_status_name('wc-' . $slug) : $slug;
        if (!$label) {
            continue;
        }
        $buttons[] = [$slug, $label];
    }
    if (!$buttons) {
        echo '<p>' . esc_html__('Keine weiteren Schritte erforderlich.', 'sg-mr') . '</p>';
        return;
    }
    foreach ($buttons as [$slug, $label]) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=sgmr_mark_status&order=' . $order->get_id() . '&to=' . $slug), 'sgmr_mark');
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
    }
}

function sgmr_admin_enqueue_status_script($hook): void
{
    if ($hook !== 'post.php' || get_post_type() !== 'shop_order') {
        return;
    }
    $orderId = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    $order = wc_get_order($orderId);
    if (!$order instanceof \WC_Order) {
        return;
    }
    $current = preg_replace('/^wc-/', '', $order->get_status());
    $allowed = function_exists('sgmr_allowed_transitions') ? sgmr_allowed_transitions($order, $current) : [];
    if (in_array('*', $allowed, true)) {
        return;
    }
    if ($allowed) {
        if (!in_array($current, $allowed, true)) {
            $allowed[] = $current;
        }
        $allowed = array_values(array_unique($allowed));
        $script = 'jQuery(function($){var allow=' . wp_json_encode($allowed) . ';$("#order_status option").each(function(){var val=$(this).val().replace(/^wc-/,""),$opt=$(this);if(allow.indexOf(val)===-1){$opt.prop("disabled",true).text($opt.text()+" ✖");}});});';
        wp_add_inline_script('jquery-core', $script);
    }
}

function sgmr_render_flash_notice(): void
{
    if (empty($_GET['sgmr_notice_msg'])) {
        return;
    }
    $type = sanitize_key($_GET['sgmr_notice'] ?? 'success');
    if (!in_array($type, ['success', 'error'], true)) {
        $type = 'success';
    }
    $message = sanitize_text_field(wp_unslash($_GET['sgmr_notice_msg']));
    $message = rawurldecode($message);
    echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
}

function sgmr_handle_mark_status(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('Keine Berechtigung.', 'sg-mr'));
    }
    $orderId = isset($_GET['order']) ? (int) $_GET['order'] : 0;
    $target = isset($_GET['to']) ? sanitize_key($_GET['to']) : '';
    if (!$orderId || !$target || empty($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'sgmr_mark')) {
        wp_die(__('Ungültige Anfrage.', 'sg-mr'));
    }
    $order = wc_get_order($orderId);
    $redirect = wp_get_referer() ?: admin_url('post.php?post=' . $orderId . '&action=edit');
    $redirect = remove_query_arg(['sgmr_notice', 'sgmr_notice_msg'], $redirect);
    if (!$order instanceof \WC_Order) {
        $redirect = add_query_arg([
            'sgmr_notice' => 'error',
            'sgmr_notice_msg' => rawurlencode(__('Bestellung nicht gefunden.', 'sg-mr')),
        ], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }
    $current = preg_replace('/^wc-/', '', $order->get_status());
    if (!sgmr_can_transition($order, $current, $target)) {
        $redirect = add_query_arg([
            'sgmr_notice' => 'error',
            'sgmr_notice_msg' => rawurlencode(__('Unzulässiger Statuswechsel.', 'sg-mr')),
        ], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }
    $targetStatus = (strpos($target, 'sg-') === 0) ? 'wc-' . $target : $target;
    $order->update_status($targetStatus);
    $order->add_order_note('[SGMR] Status via Schnellaktion gesetzt: ' . $targetStatus);
    $redirect = add_query_arg([
        'sgmr_notice' => 'success',
        'sgmr_notice_msg' => rawurlencode(__('Status aktualisiert.', 'sg-mr')),
    ], $redirect);
    wp_safe_redirect($redirect);
    exit;
}

add_action('woocommerce_before_order_object_save', 'sgmr_guard_order_status', 20, 1);
add_action('woocommerce_checkout_order_processed', 'sgmr_initial_status_meta', 10, 1);
add_action('add_meta_boxes', 'sgmr_register_status_metabox');
add_action('admin_post_sgmr_mark_status', 'sgmr_handle_mark_status');
add_action('admin_notices', 'sgmr_render_flash_notice');
add_action('admin_enqueue_scripts', 'sgmr_admin_enqueue_status_script');
add_action('admin_notices', 'sgmr_region_admin_notice');

function sgmr_region_admin_notice(): void
{
    if (!function_exists('get_current_screen')) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'shop_order') {
        return;
    }
    $orderId = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if (!$orderId) {
        return;
    }
    $order = wc_get_order($orderId);
    if (!$order instanceof \WC_Order) {
        return;
    }
    $region = $order->get_meta(\SGMR\Services\CartService::META_REGION_KEY);
    if ($region !== 'on_request') {
        return;
    }
    $postcode = $order->get_meta(\SGMR\Services\CartService::META_REGION_POSTCODE) ?: ($order->get_shipping_postcode() ?: $order->get_billing_postcode());
    $message = esc_html__('Region konnte nicht automatisch zugeordnet werden. Bitte Region und PLZ prüfen.', 'sg-mr');
    if ($postcode) {
        $message .= ' ' . sprintf(esc_html__('(PLZ: %s)', 'sg-mr'), esc_html($postcode));
    }
    echo '<div class="notice notice-warning"><p>' . $message . '</p></div>';
}
