<?php

namespace SGMR\Email;

class SGMR_Email_Offline extends SGMR_Email_Base
{
    public function __construct()
    {
        $this->id = 'sgmr_offline';
        $this->title = __('Sanigroup – Telefonische Terminvereinbarung', 'sg-mr');
        $this->description = __('Informiert Kunden, dass sich das Team telefonisch zur Terminvereinbarung meldet.', 'sg-mr');
        $this->heading = __('Wir melden uns telefonisch', 'sg-mr');
        $this->subject = __('Wir melden uns telefonisch – Bestellung {order_number}', 'sg-mr');
        $this->template_html = 'emails/sgmr-offline.php';
        $this->template_plain = 'emails/plain/sgmr-offline.php';
        $this->customer_email = true;

        parent::__construct();
    }

    public function trigger($order_id, array $args = []): void
    {
        $args['link_url'] = '';
        parent::trigger($order_id, $args);
    }
}
