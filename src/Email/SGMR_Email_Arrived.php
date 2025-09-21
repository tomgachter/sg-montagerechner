<?php

namespace SGMR\Email;

class SGMR_Email_Arrived extends SGMR_Email_Base
{
    public function __construct()
    {
        $this->id = 'sgmr_arrived';
        $this->title = __('Sanigroup – Ware eingetroffen', 'sg-mr');
        $this->description = __('Sendet den Online-Buchungslink, sobald alle Artikel eingetroffen sind.', 'sg-mr');
        $this->heading = __('Ihre Ware ist eingetroffen – Termin wählen', 'sg-mr');
        $this->subject = __('Ihre Ware ist eingetroffen – Termin wählen (Bestellung {order_number})', 'sg-mr');
        $this->template_html = 'emails/sgmr-arrived.php';
        $this->template_plain = 'emails/plain/sgmr-arrived.php';
        $this->customer_email = true;

        parent::__construct();
    }
}
