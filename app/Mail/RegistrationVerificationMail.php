<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationVerificationMail extends Mailable
{
    public function __construct(protected string $verificationUrl, protected int $expiresInMinutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your email to register');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.registration-verification', with: [
            'verificationUrl' => $this->verificationUrl,
            'expiresInMinutes' => $this->expiresInMinutes,
        ]);
    }
}
