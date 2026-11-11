<?php

namespace App\Jobs;

use App\Services\EmailRegistrationService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class SendRegistrationVerification implements ShouldQueueAfterCommit
{
    use Queueable;

    // A transport failure may follow SMTP acceptance. Require a new request
    // rather than automatically resending the same bearer link.
    public int $tries = 1;

    public function __construct(
        public int $registrationRequestId,
        public string $tokenHash,
        public string $encryptedToken,
    ) {
        $this->onConnection('database');
    }

    public function handle(EmailRegistrationService $registration): void
    {
        $registration->deliverLink($this->registrationRequestId, $this->tokenHash, $this->encryptedToken);
    }
}
