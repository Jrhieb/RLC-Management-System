<?php

namespace App\Services;

use App\Enums\PpiApplicationStatus;
use App\Mail\ApplicationNoticeMail;
use App\Models\PpiHouseholdApplication;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PpiGuardianResultDelivery
{
    public function __construct(private BrevoSmsService $sms) {}

    public static function configured(): bool
    {
        return filled(config('mail.mailers.brevo.key'))
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)
            && config('services.brevo.sms_enabled') === true
            && filled(config('services.brevo.sms_key'))
            && preg_match('/^[A-Za-z][A-Za-z0-9]{0,10}$/D', (string) config('services.brevo.sms_sender'))
            && in_array('63', config('services.brevo.sms_country_codes', []), true)
            && (int) config('services.brevo.sms_daily_limit') > 0;
    }

    public static function smsRecipient(string $number): string
    {
        $phone = PhoneNumber::normalize($number);
        if (! collect(config('services.brevo.sms_country_codes', []))->contains(
            fn ($prefix): bool => preg_match('/^[1-9][0-9]{0,2}$/D', (string) $prefix) && str_starts_with($phone, '+'.$prefix),
        )) {
            throw ValidationException::withMessages(['contact_number' => 'Enter a mobile number in a supported country.']);
        }

        return $phone;
    }

    public function sendOnce(PpiHouseholdApplication $application): void
    {
        $pending = PpiHouseholdApplication::query()->whereKey($application->id)
            ->where('guardian_delivery_status', 'pending');
        if ($pending->update(['guardian_delivery_status' => 'sending']) !== 1) {
            return;
        }

        $reference = $application->status === PpiApplicationStatus::DidNotPassScreening ? null : $application->reference;
        $message = $application->status->screeningMessage();
        $status = 'accepted';

        try {
            if ($application->email) {
                Mail::mailer('brevo')->to($application->email)
                    ->send(new ApplicationNoticeMail('RLCP application result', $message, $reference));
            } else {
                $phone = self::smsRecipient($application->contact_number);
                $this->reserveSms($phone);
                $text = str_replace('’', "'", $message);
                $this->sms->sendMessage($phone, $reference ? $text.' Reference: '.$reference : $text);
            }
        } catch (Throwable) {
            $status = 'failed';
            Log::warning('PPI result delivery was not accepted.', ['application_id' => $application->id]);
        }

        PpiHouseholdApplication::query()->whereKey($application->id)
            ->update(['guardian_delivery_status' => $status]);
    }

    private function reserveSms(string $phone): void
    {
        $reserved = Cache::lock('phone-verification-budget', 10)->get(function () use ($phone): bool {
            $phoneKey = 'sms-phone-day:'.hash('sha256', $phone);
            $globalKey = 'sms-global-day';
            $limit = (int) config('services.brevo.sms_daily_limit');
            if ($limit <= 0 || RateLimiter::tooManyAttempts($phoneKey, 5) || RateLimiter::tooManyAttempts($globalKey, $limit)) {
                return false;
            }

            RateLimiter::hit($phoneKey, 86400);
            RateLimiter::hit($globalKey, 86400);

            return true;
        });

        if ($reserved !== true) {
            throw new RuntimeException('SMS delivery budget unavailable.');
        }
    }
}
