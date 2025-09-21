<?php

namespace SGMR\Email;

class EmailService
{
    public static function register(array $emails): array
    {
        $emails['SGMR_Email_Paid_Wait'] = new SGMR_Email_Paid_Wait();
        $emails['SGMR_Email_Instant'] = new SGMR_Email_Instant();
        $emails['SGMR_Email_Arrived'] = new SGMR_Email_Arrived();
        $emails['SGMR_Email_Offline'] = new SGMR_Email_Offline();
        return $emails;
    }
}
