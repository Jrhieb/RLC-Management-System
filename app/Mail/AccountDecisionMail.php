<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AccountDecisionMail extends Mailable implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public string $decision)
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid account decision.');
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->decision === 'approved' ? 'Your account has been approved' : 'Your account request was rejected');
    }

    public function send($mailer)
    {
        try {
            return parent::send($mailer);
        } catch (Throwable) {
            // Failed-job diagnostics must not retain SMTP credentials or bodies.
            throw new RuntimeException('Account decision email delivery failed; details withheld.');
        }
    }

    public function content(): Content
    {
        $path = route($this->decision === 'approved' ? 'login' : 'account.status', absolute: false);

        return new Content(view: 'mail.account-decision', with: [
            'approved' => $this->decision === 'approved',
            'url' => rtrim((string) config('app.url'), '/').$path,
        ]);
    }
}
