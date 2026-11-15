<?php

namespace App\Services;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

class PhoneVerificationService
{
    public function __construct(private BrevoSmsService $sms) {}

    public function savePhone(User $user, ?string $phone): void
    {
        $this->authorize($user);
        $phone = $phone === null || trim($phone) === '' ? null : PhoneNumber::normalize($phone);
        DB::transaction(function () use ($user, $phone): void {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            $account->phone_number = $phone;
            $account->save();
        });
    }

    public function send(User $user, string $ip): void
    {
        $this->authorize($user);
        $ran = Cache::lock('phone-verification-send:'.$user->id, 30)->get(function () use ($user, $ip): bool {
            $account = $user->fresh();
            $phone = $account->phone_number;
            $countries = config('services.brevo.sms_country_codes', ['63']);
            if (! $phone || $account->phone_verified_at !== null
                || ! collect($countries)->contains(fn ($prefix) => preg_match('/^[1-9][0-9]{0,2}$/D', (string) $prefix) && str_starts_with($phone, '+'.$prefix))) {
                $this->unavailable('Save an unverified mobile number in an enabled country first.');
            }
            $this->reserveBudget($user->id, $phone, $ip);
            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $hash = $this->hash($user->id, $phone, $code);
            } while ($hash === $account->phone_verification_hash);
            $reserved = User::query()->whereKey($user->id)->where('phone_number', $phone)->whereNull('phone_verified_at')->update([
                'phone_verification_hash' => $hash, 'phone_verification_expires_at' => now()->addMinutes(5),
                'phone_verification_sent_at' => null, 'phone_verification_attempts' => 0,
            ]);
            if ($reserved !== 1) {
                $this->unavailable();
            }
            try {
                $this->sms->send($phone, $code);
            } catch (Throwable) {
                User::query()->whereKey($user->id)->where('phone_verification_hash', $hash)
                    ->update(['phone_verification_hash' => null, 'phone_verification_sent_at' => null]);
                Log::warning('Phone verification SMS delivery was not accepted.');
                $this->unavailable('The SMS could not be sent. Please try again later.');
            }
            if (User::query()->whereKey($user->id)->where('phone_number', $phone)
                ->where('phone_verification_hash', $hash)->update(['phone_verification_sent_at' => now()]) !== 1) {
                $this->unavailable('Your phone number changed. Request a new code.');
            }

            return true;
        });
        if ($ran !== true) {
            $this->unavailable('A request is already processing. Please wait.');
        }
    }

    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $code): bool {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $account->phone_number || ! $account->phone_verification_hash
                || ! $account->phone_verification_sent_at || ! $account->phone_verification_expires_at
                || $account->phone_verification_expires_at->lessThanOrEqualTo(now())
                || $account->phone_verification_attempts >= 5 || $account->phone_verified_at) {
                return false;
            }
            $account->phone_verification_attempts++;
            if (! hash_equals($account->phone_verification_hash, $this->hash($user->id, $account->phone_number, $code))) {
                if ($account->phone_verification_attempts >= 5) {
                    $account->phone_verification_hash = null;
                }
                $account->save();

                return false;
            }
            $account->phone_verified_at = now();
            $account->phone_verification_hash = null;
            $account->phone_verification_expires_at = null;
            $account->phone_verification_sent_at = null;
            $account->save();

            return true;
        });
    }

    private function authorize(User $user): void
    {
        abort_unless(config('services.brevo.sms_enabled'), 404);
        abort_unless($user->hasVerifiedEmail(), 403);
    }

    private function hash(int $id, string $phone, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $id.':'.$phone.':'.$code, (string) config('app.key'));
    }

    private function reserveBudget(int $id, string $phone, string $ip): void
    {
        $reserved = Cache::lock('phone-verification-budget', 10)->get(function () use ($id, $phone, $ip): bool {
            $phone = hash('sha256', $phone);
            $ip = hash('sha256', $ip);
            $limits = [
                ['sms-cooldown:'.$id, 1, 60], ['sms-user-hour:'.$id, 3, 3600],
                ['sms-user-day:'.$id, 5, 86400], ['sms-phone-hour:'.$phone, 3, 3600],
                ['sms-phone-day:'.$phone, 5, 86400], ['sms-ip-hour:'.$ip, 10, 3600],
                ['sms-global-day', max(0, (int) config('services.brevo.sms_daily_limit', 0)), 86400],
            ];
            foreach ($limits as [$key, $max]) {
                if ($max === 0 || RateLimiter::tooManyAttempts($key, $max)) {
                    $this->unavailable('SMS request limit reached. Please try again later.');
                }
            }
            // Count attempts before the provider call, including failures and ambiguous timeouts.
            foreach ($limits as [$key, $max, $seconds]) {
                RateLimiter::hit($key, $seconds);
            }

            return true;
        });
        if ($reserved !== true) {
            $this->unavailable('SMS requests are busy. Please try again later.');
        }
    }

    private function unavailable(string $message = 'Phone verification is unavailable. Please try again later.'): never
    {
        throw ValidationException::withMessages(['phone_number' => $message]);
    }
}
