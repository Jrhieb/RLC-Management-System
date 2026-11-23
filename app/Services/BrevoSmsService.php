<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BrevoSmsService
{
    public function send(string $phone, #[\SensitiveParameter] string $code): void
    {
        $this->sendMessage($phone, 'Your RLC phone verification code is '.$code.'. It expires in 5 minutes. Do not share this code.');
    }

    public function sendMessage(string $phone, #[\SensitiveParameter] string $message): void
    {
        try {
            $key = (string) config('services.brevo.sms_key');
            $sender = (string) config('services.brevo.sms_sender');
            if (! config('services.brevo.sms_enabled') || (int) config('services.brevo.sms_daily_limit') <= 0
                || trim($key) === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9]{0,10}$/D', $sender)) {
                throw new RuntimeException('SMS configuration unavailable.');
            }
            $response = Http::acceptJson()->asJson()->withHeaders(['api-key' => $key])
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://api.brevo.com/v3/transactionalSMS/send', [
                    'sender' => $sender, 'recipient' => $phone, 'type' => 'transactional',
                    'content' => $message,
                    'unicodeEnabled' => false,
                ]);
            if ($response->status() !== 201 || ! is_numeric($response->json('messageId')) || (float) $response->json('messageId') <= 0) {
                throw new RuntimeException('SMS rejected.');
            }
        } catch (Throwable) {
            throw new RuntimeException('SMS delivery was not accepted; details withheld.');
        }
    }
}
