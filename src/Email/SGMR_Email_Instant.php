<?php

namespace SGMR\Email;

class SGMR_Email_Instant extends SGMR_Email_Base
{
    public function __construct()
    {
        $this->id = 'sgmr_instant';
        $this->title = __('Sanigroup – Termin freigegeben (sofort)', 'sg-mr');
        $this->description = __('Sendet einen Online-Buchungslink, sobald die Bestellung bezahlt ist und Lagerware verfügbar ist.', 'sg-mr');
        $this->heading = __('Ihr Termin – jetzt buchen', 'sg-mr');
        $this->subject = __('Ihr Termin – jetzt buchen (Bestellung {order_number})', 'sg-mr');
        $this->template_html = 'emails/sgmr-instant.php';
        $this->template_plain = 'emails/plain/sgmr-instant.php';
        $this->customer_email = true;

        parent::__construct();
    }
}
