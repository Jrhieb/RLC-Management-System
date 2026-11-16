<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

class BrevoDeliveryException extends TransportException
{
    public function __construct(public readonly bool $safeToRetry)
    {
        parent::__construct('Brevo email delivery was not accepted.');
    }
}
