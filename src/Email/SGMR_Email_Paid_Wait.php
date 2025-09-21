<?php

namespace SGMR\Email;

class SGMR_Email_Paid_Wait extends SGMR_Email_Base
{
    public function __construct()
    {
        $this->id = 'sgmr_paid_wait';
        $this->title = __('Sanigroup – Zahlung erhalten (Warte auf Wareneingang)', 'sg-mr');
        $this->description = __('Benachrichtigt Kundinnen und Kunden, dass die Zahlung verbucht wurde und der Terminlink folgt nach Wareneingang.', 'sg-mr');
        $this->heading = __('Zahlung erhalten – wir melden uns', 'sg-mr');
        $this->subject = __('Zahlung erhalten – wir halten Sie auf dem Laufenden (Bestellung {order_number})', 'sg-mr');
        $this->template_html = 'emails/sgmr-paid-wait.php';
        $this->template_plain = 'emails/plain/sgmr-paid-wait.php';
        $this->customer_email = true;

        parent::__construct();
    }

    public function trigger($order_id, array $args = []): void
    {
        parent::trigger($order_id, array_merge($args, ['link_url' => '']));
    }
}
