<?php

namespace SGMR\Checkout;

use SGMR\Booking\FluentBookingClient;
use SGMR\Plugin;
use SGMR\Services\CartService;
use SGMR\Utils\PostcodeHelper;
use WC_Order;

class Fields
{
    private FluentBookingClient $bookingClient;

    public function __construct(FluentBookingClient $bookingClient)
    {
        $this->bookingClient = $bookingClient;
    }

    public function boot(): void
    {
        add_action('woocommerce_after_checkout_billing_form', [$this, 'render']);
        add_action('woocommerce_checkout_process', [$this, 'validate']);
        add_action('woocommerce_checkout_create_order', [$this, 'persist'], 15, 2);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'renderAdmin']);
        add_filter('woocommerce_email_order_meta_fields', [$this, 'renderEmailMeta'], 10, 3);
    }

    public function render(): void
    {
        if (!CartService::cartHasService()) {
            echo '<div id="sg-appointment-wrap" style="display:none"></div>';
            return;
        }

        $postcode = PostcodeHelper::currentPostcode();
        $country = PostcodeHelper::currentCountry();
        $hasPostcode = $postcode !== '';
        $allowed = $hasPostcode ? PostcodeHelper::postcodeAllowsService($postcode, $country) : false;
        if (!$allowed) {
            WC()->session?->set(Plugin::SESSION_APPOINTMENT, 'telefonisch');
        }
        $sessionValue = WC()->session ? WC()->session->get(Plugin::SESSION_APPOINTMENT, '') : '';
        $mode = $sessionValue ?: (CartService::cartForceOffline() ? 'telefonisch' : 'online');
        $mode = $mode === 'online' ? 'online' : 'telefonisch';
        $forceOffline = CartService::cartForceOffline();

        $hint = $forceOffline ? '<p class="sg-note" style="color:#a00;margin:4px 0">'.esc_html__('Große Aufträge telefonisch','sg-mr').'</p>' : '';
        $requestText = esc_html__('Montage auf Anfrage – keine Online-Terminierung & keine Zahlung für Montage möglich.','sg-mr');
        $noPostcodeText = esc_html__('Bitte PLZ eingeben, damit wir die Terminoptionen anzeigen können.','sg-mr');

        $notice = '';
        if (!$hasPostcode) {
            $notice = $noPostcodeText;
        } elseif (!$allowed) {
            $notice = $requestText;
        }

        echo '<div id="sg-appointment-wrap" class="sg-appointment-block" data-force="'.($forceOffline ? '1' : '0').'" data-has-plz="'.($hasPostcode ? '1' : '0').'" data-allowed="'.($allowed ? '1' : '0').'">';
        echo '<h3>'.esc_html__('Terminvereinbarung','sg-mr').'</h3>';
        echo $hint;
        if ($notice) {
            echo '<div class="sg-note sg-appointment-on-request" style="color:#a00;margin:4px 0">'.$notice.'</div>';
        }
        echo '<div class="sg-appointment-options">';
        echo '<label style="display:block;margin-bottom:4px">';
        printf('<input type="radio" name="sg_appointment_mode" value="online" %s %s> %s',
            checked($mode, 'online', false), disabled($forceOffline || !$allowed, true, false), esc_html__('Online (Sie buchen selbst)','sg-mr'));
        echo '</label>';
        echo '<label style="display:block;margin-bottom:4px">';
        printf('<input type="radio" name="sg_appointment_mode" value="telefonisch" %s> %s',
            checked($mode, 'telefonisch', false), esc_html__('Telefonisch (wir melden uns)','sg-mr'));
        echo '</label>';
        echo '</div>';
        echo '</div>';
    }

    public function validate(): void
    {
        if (!CartService::cartHasService()) {
            WC()->session?->set(Plugin::SESSION_APPOINTMENT, '');
            return;
        }
        $postcode = PostcodeHelper::currentPostcode(true);
        $country = PostcodeHelper::currentCountry(true);
        $allowed = $postcode ? PostcodeHelper::postcodeAllowsService($postcode, $country) : false;
        if ($postcode) {
            WC()->session?->set(Plugin::SESSION_POSTCODE, $postcode);
        }
        if ($country) {
            WC()->session?->set(Plugin::SESSION_COUNTRY, strtoupper($country));
        }
        if (!$allowed) {
            WC()->session?->set(Plugin::SESSION_APPOINTMENT, 'telefonisch');
            return;
        }
        $mode = isset($_POST['sg_appointment_mode']) ? sanitize_text_field(wp_unslash($_POST['sg_appointment_mode'])) : '';
        if (!$mode) {
            wc_add_notice(__('Bitte wählen Sie eine Terminvereinbarung.','sg-mr'), 'error');
            return;
        }
        if ($mode === 'online' && CartService::cartForceOffline()) {
            wc_add_notice(__('Online-Terminierung ist für diesen Auftrag nicht verfügbar. Bitte wählen Sie "Telefonisch".','sg-mr'), 'error');
            $mode = 'telefonisch';
        }
        WC()->session?->set(Plugin::SESSION_APPOINTMENT, $mode);
    }

    public function persist(WC_Order $order, $data): void
    {
        $selection = CartService::cartSelection();
        $enriched = [];
        foreach ($selection as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (empty($row['product_id'])) {
                $productId = CartService::cartProductId((string) $key);
                if ($productId) {
                    $row['product_id'] = $productId;
                }
            }
            if (empty($row['qty'])) {
                $row['qty'] = CartService::cartItemQuantity((string) $key);
            }
            $enriched[$key] = $row;
        }
        $selection = $enriched ?: $selection;
        $servicePostcode = PostcodeHelper::currentPostcode();
        $serviceCountry = PostcodeHelper::currentCountry();

        $context = PostcodeHelper::persistOrderContext($order, $selection, [
            'service_postcode' => $servicePostcode,
            'service_country' => $serviceCountry,
        ]);

        $mode = CartService::selectionHasService($selection)
            ? (WC()->session ? (string) WC()->session->get(Plugin::SESSION_APPOINTMENT, '') : '')
            : '';
        if (!$mode) {
            $mode = $context['force_offline'] ? 'telefonisch' : 'online';
        }
        $mode = $mode === 'online' ? 'online' : 'telefonisch';

        $counts = CartService::selectionCounts($selection);

        $order->update_meta_data(CartService::META_SELECTION, $selection);
        $order->update_meta_data(CartService::META_TERMIN_MODE, $mode);
        $order->update_meta_data(CartService::META_FORCE_OFFLINE, $context['force_offline'] ? 1 : 0);
        $order->update_meta_data(CartService::META_EXPRESS_FLAG, $context['express'] ? 1 : 0);
        $order->update_meta_data(CartService::META_DEVICE_COUNT, (int) $context['device_count']);
        $order->update_meta_data(CartService::META_MONTAGE_COUNT, (int) $counts['montage']);
        $order->update_meta_data(CartService::META_ETAGE_COUNT, (int) $counts['etage']);
    }

    public function renderAdmin($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $mode = $order->get_meta(CartService::META_TERMIN_MODE);
        if (!$mode) {
            return;
        }
        $label = $mode === 'online'
            ? __('Online (Kunde bucht selbst)','sg-mr')
            : __('Telefonisch (wir melden uns)','sg-mr');
        echo '<p><strong>'.esc_html__('Terminvereinbarung','sg-mr').':</strong> '.esc_html($label).'</p>';
    }

    public function renderEmailMeta(array $fields, bool $sentToAdmin, $order): array
    {
        if (!$order instanceof WC_Order) {
            return $fields;
        }
        $mode = $order->get_meta(CartService::META_TERMIN_MODE);
        if ($mode) {
            $fields['sg_terminvereinbarung'] = [
                'label' => __('Terminvereinbarung','sg-mr'),
                'value' => $mode === 'online' ? __('Online (Sie buchen selbst)','sg-mr') : __('Telefonisch (wir melden uns)','sg-mr'),
            ];
        }
        return $fields;
    }
}
