<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationNoticeMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $applicantMessage, public ?string $reference) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.application-notice');
    }
}
