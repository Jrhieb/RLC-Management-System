<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

class BrevoTransport extends AbstractTransport
{
    public function __construct(#[\SensitiveParameter] private string $key)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $category = 'transport_failure';
        $status = null;
        try {
            if (trim($this->key) === '') {
                $category = 'missing_key';
                throw new TransportException('Missing Brevo configuration.');
            }
            $email = MessageConverter::toEmail($message->getOriginalMessage());
            $addresses = static fn (array $addresses): array => array_map(
                static fn (Address $address): array => array_filter([
                    'email' => $address->getAddress(), 'name' => $address->getName(),
                ], static fn (string $value): bool => $value !== ''), $addresses,
            );
            $payload = array_filter([
                'sender' => $addresses($email->getFrom())[0] ?? null,
                'to' => $addresses($email->getTo()),
                'cc' => $addresses($email->getCc()),
                'bcc' => $addresses($email->getBcc()),
                'replyTo' => $addresses($email->getReplyTo())[0] ?? null,
                'subject' => $email->getSubject(),
                'htmlContent' => $email->getHtmlBody(),
                'textContent' => $email->getTextBody(),
            ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
            if ($email->getAttachments() !== []) {
                throw new TransportException('Attachments are not supported by this transport.');
            }
            // Do not retry ambiguous timeouts: the provider may already have accepted the email.
            $category = 'connection_failure';
            $response = Http::acceptJson()->asJson()->withHeaders(['api-key' => $this->key])
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://api.brevo.com/v3/smtp/email', $payload);
            $status = $response->status();
            $category = $status === 201 ? 'invalid_acceptance' : 'provider_rejected';
            $id = $response->json('messageId');
            if ($response->status() !== 201 || ! is_string($id) || trim($id) === '') {
                throw new TransportException('Brevo rejected the email.');
            }
            $message->setMessageId($id);
        } catch (Throwable) {
            Log::warning('Brevo email transport failure.', ['category' => $category, 'http_status' => $status]);
            // Provider responses and request exceptions can contain credentials and bearer content.
            $safeToRetry = $category === 'missing_key' || ($category === 'provider_rejected' && in_array($status, [400, 401, 403, 404, 422], true));
            throw new BrevoDeliveryException($safeToRetry);
        }
    }

    public function __toString(): string
    {
        return 'brevo+https';
    }
}
