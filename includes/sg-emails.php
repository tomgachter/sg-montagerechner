<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WC_Email')) return;

if (!class_exists('SG_Email_Base_Min')){
class SG_Email_Base_Min extends WC_Email {
    /** @var string */
    public $additional_content = '';

    protected function setup($id, $title, $description, $heading, $subject){
        $this->id          = $id;
        $this->title       = $title;
        $this->description = $description;
        $this->heading     = $heading;
        $this->subject     = $subject;
        $this->template_html  = 'emails/'.$id.'.php';
        $this->template_base  = trailingslashit(dirname(__DIR__)).'templates/';
        $this->customer_email = true;
        $this->placeholders   = [ '{order_number}' => '' ];
        parent::__construct();
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled            = $this->get_option('enabled', 'yes');
        $this->subject            = $this->get_option('subject', $this->subject);
        $this->heading            = $this->get_option('heading', $this->heading);
        $this->additional_content = $this->get_option('additional_content', '');
        // Ensure HTML by default (Woo style)
        $this->email_type         = $this->get_option('email_type', 'html');
        add_action('woocommerce_update_options_email_'.$this->id, [ $this, 'process_admin_options' ]);
    }

    public function init_form_fields(){
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Aktivieren/Deaktivieren','sg-mr'),
                'type'    => 'checkbox',
                'label'   => __('Diese E‑Mail senden','sg-mr'),
                'default' => 'yes',
            ],
            'subject' => [
                'title'       => __('Betreff','sg-mr'),
                'type'        => 'text',
                'description' => __('Platzhalter: {order_number}','sg-mr'),
                'default'     => $this->subject,
            ],
            'heading' => [
                'title'       => __('Überschrift','sg-mr'),
                'type'        => 'text',
                'default'     => $this->heading,
            ],
            'additional_content' => [
                'title'       => __('Zusätzlicher Text','sg-mr'),
                'type'        => 'textarea',
                'default'     => '',
                'description' => __('Dieser Text erscheint unterhalb der Bestelldetails.','sg-mr'),
            ],
            'email_type' => [
                'title'       => __('E‑Mail‑Typ','sg-mr'),
                'type'        => 'select',
                'description' => __('Wählen Sie das E‑Mail‑Format.','sg-mr'),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
            ],
        ];
    }

    public function get_content_html(){
        ob_start();
        wc_get_template($this->template_html, [
            'order'         => $this->object,
            'email_heading' => $this->get_heading(),
            'sent_to_admin' => false,
            'plain_text'    => false,
            'email'         => $this,
        ], '', $this->template_base);
        return ob_get_clean();
    }

    // Build a minimal plain text fallback
    public function get_content_plain(){
        $o = $this->object; if (!$o) return '';
        $lines = [
            $this->get_heading(),
            sprintf(__('Bestellung #%s','sg-mr'), $o->get_order_number()),
            wc_get_email_order_items($o, [ 'plain_text'=>true ]),
        ];
        return implode("\n\n", array_map('wp_strip_all_tags', $lines));
    }
}
}

if (!class_exists('SG_Email_Ready_Pickup')){
class SG_Email_Ready_Pickup extends SG_Email_Base_Min {
    public function __construct(){
        $this->setup('sg_ready_pickup', __('Zur Abholung bereit','sg-mr'), __('Benachrichtigt Kunden, dass die Ware abholbereit ist.','sg-mr'), __('Ihre Ware ist zur Abholung bereit','sg-mr'), __('Ihre Ware ist zur Abholung bereit','sg-mr'));
        add_action('woocommerce_order_status_ready-pickup_notification', [$this, 'trigger'], 10, 2);
        add_action('woocommerce_order_status_ready-pickup', function($order_id){ $this->trigger($order_id); });
    }
    public function trigger($order_id){
        $this->object = wc_get_order($order_id);
        if (!$this->object) return;
        $this->placeholders['{order_number}'] = $this->object->get_order_number();
        $this->recipient = $this->object->get_billing_email();
        if ($this->is_enabled() && $this->get_recipient()) $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
    }
}
}

if (!class_exists('SG_Email_Picked_Up')){
class SG_Email_Picked_Up extends SG_Email_Base_Min {
    public function __construct(){
        $this->setup('sg_picked_up', __('Abholung bestätigt','sg-mr'), __('Bestätigung nach Abholung.','sg-mr'), __('Vielen Dank für Ihre Abholung','sg-mr'), __('Vielen Dank für Ihre Abholung','sg-mr'));
        add_action('woocommerce_order_status_picked-up_notification', [$this, 'trigger'], 10, 2);
        add_action('woocommerce_order_status_picked-up', function($order_id){ $this->trigger($order_id); });
    }
    public function trigger($order_id){
        $this->object = wc_get_order($order_id);
        if (!$this->object) return;
        $this->placeholders['{order_number}'] = $this->object->get_order_number();
        $this->recipient = $this->object->get_billing_email();
        if ($this->is_enabled() && $this->get_recipient()) $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
    }
}
}
