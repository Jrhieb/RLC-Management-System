<?php

namespace App\Services;

use App\Jobs\SendRegistrationVerification;
use App\Jobs\SkipRegistrationVerification;
use App\Mail\RegistrationVerificationMail;
use App\Models\RegistrationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EmailRegistrationService
{
    public function __construct(private AccountNotificationService $notifications) {}

    public const SESSION_KEY = 'email_registration';

    public const LINK_MINUTES = 30;

    public const SESSION_MINUTES = 15;

    public const INVALID_SESSION = 'Your registration session has expired or is no longer valid. Request a new verification link.';

    public function requestLink(string $email): void
    {
        $email = Str::lower(trim($email));
        $this->assertSafeMailTransport((string) config('mail.default'));

        // Keep existing accounts untouched and give the same public response for every email.
        if ($this->emailExists($email)) {
            $this->queueVerification(new SkipRegistrationVerification);

            return;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $now = now();

        DB::transaction(function () use ($email, $token, $tokenHash, $now): void {
            // One atomic write replaces both the previous link and any verified session.
            DB::table('registration_requests')->upsert([
                'email' => $email,
                'token_hash' => $tokenHash,
                'expires_at' => $now->addMinutes(self::LINK_MINUTES),
                'verified_at' => null,
                'session_token_hash' => null,
                'session_expires_at' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['email'], [
                'token_hash', 'expires_at', 'verified_at', 'session_token_hash',
                'session_expires_at', 'completed_at', 'updated_at',
            ]);

            $id = (int) DB::table('registration_requests')->where('email', $email)->value('id');
            // Queue only ciphertext and a generation hash, never a bearer URL or plaintext token.
            $encryptedToken = Crypt::encryptString($token);
            $this->queueVerification(new SendRegistrationVerification($id, $tokenHash, $encryptedToken));
        });
    }

    private function queueVerification(SendRegistrationVerification|SkipRegistrationVerification $job): void
    {
        DB::afterCommit(function () use ($job): void {
            try {
                Bus::dispatch($job);
            } catch (Throwable) {
                if ($job instanceof SendRegistrationVerification) {
                    $this->invalidateLink($job->registrationRequestId, $job->tokenHash);
                }
                Log::warning('Registration verification email could not be queued.');

                // Reuse registration's static 503 response for both address types.
                throw new RuntimeException('Registration email dispatch failed; details withheld.');
            }
        });
    }

    public function deliverLink(int $id, string $tokenHash, string $encryptedToken): void
    {
        $registration = RegistrationRequest::query()->whereKey($id)->where('token_hash', $tokenHash)
            ->where('expires_at', '>', now())->whereNull('verified_at')->whereNull('completed_at')->first();

        // A newer request, verification, completion, or an account claimed while
        // waiting in the queue makes this delivery obsolete.
        if (! $registration || $this->emailExists($registration->email)) {
            return;
        }

        try {
            $this->assertSafeMailTransport((string) config('mail.default'));
            $token = Crypt::decryptString($encryptedToken);
            if (! $this->validToken($token) || ! hash_equals($tokenHash, hash('sha256', $token))) {
                throw new RuntimeException('Invalid registration delivery.');
            }
            // The same shared cache already backs registration rate limits.
            // Claim this generation once, including if SMTP accepts before an
            // interrupted worker can remove the queue row. A resend has a new hash.
            if (! Cache::add('registration-verification-delivery:'.$id.':'.$tokenHash, true, $registration->expires_at)) {
                return;
            }
            // Build the link only inside the worker, using the configured origin.
            $url = rtrim((string) config('app.url'), '/').route('registration.verify', ['token' => $token], absolute: false);
            $minutes = max(1, (int) ceil(now()->diffInSeconds($registration->expires_at) / 60));
            Mail::to($registration->email)->send(new RegistrationVerificationMail($url, $minutes));
        } catch (Throwable) {
            $this->invalidateLink($id, $tokenHash);
            Log::warning('Registration verification email delivery failed.');
            // Do not chain the original exception: SMTP errors can contain links,
            // email bodies, and credentials, including in failed-job diagnostics.
            throw new RuntimeException('Registration verification email delivery failed; details withheld.');
        }
    }

    private function invalidateLink(int $id, string $tokenHash): void
    {
        DB::table('registration_requests')->where('id', $id)->where('token_hash', $tokenHash)
            ->update(['token_hash' => null, 'updated_at' => now()]);
    }

    /** @return array{id: int, token: string}|null */
    public function verify(string $token): ?array
    {
        if (! $this->validToken($token)) {
            return null;
        }

        $sessionToken = bin2hex(random_bytes(32));
        $sessionHash = hash('sha256', $sessionToken);

        return DB::transaction(function () use ($token, $sessionToken, $sessionHash): ?array {
            $now = now();
            $consumed = RegistrationRequest::query()->where('token_hash', hash('sha256', $token))
                ->where('expires_at', '>', $now)->whereNull('verified_at')->whereNull('completed_at')
                ->update([
                    'token_hash' => null,
                    'verified_at' => $now,
                    'session_token_hash' => $sessionHash,
                    'session_expires_at' => $now->addMinutes(self::SESSION_MINUTES),
                ]);

            if ($consumed !== 1) {
                return null;
            }

            $registration = RegistrationRequest::query()->where('session_token_hash', $sessionHash)->sole();

            return ['id' => (int) $registration->getKey(), 'token' => $sessionToken];
        }, attempts: 3);
    }

    public function activeRequest(mixed $session): ?RegistrationRequest
    {
        return $this->sessionQuery($session)?->first();
    }

    /**
     * @param  array{id: int, token: string}  $session
     * @param  array{name: string, password: string}  $input
     */
    public function complete(array $session, array $input): User
    {
        try {
            return DB::transaction(function () use ($session, $input): User {
                // Consume before inserting. Competing submissions cannot both pass this update,
                // including on SQLite where SELECT FOR UPDATE does not lock a row.
                $consumed = $this->sessionQuery($session)?->update([
                    'completed_at' => now(),
                    'session_token_hash' => null,
                    'session_expires_at' => null,
                ]);

                if ($consumed !== 1) {
                    throw ValidationException::withMessages(['registration' => self::INVALID_SESSION]);
                }

                $registration = RegistrationRequest::findOrFail($session['id']);

                if ($this->emailExists($registration->email)) {
                    throw $this->duplicateEmailError();
                }

                $user = new User([
                    'name' => $input['name'],
                    'email' => $registration->email,
                    'password' => $input['password'],
                ]);
                $user->role = 'caseworker';
                $user->email_verified_at = $registration->verified_at;
                $user->approval_status = 'pending';

                if (! $user->save()) {
                    throw ValidationException::withMessages([
                        'registration' => 'Your account could not be saved. Please try again.',
                    ]);
                }

                $this->notifications->registrationCompleted($user);

                return $user;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // The users.email unique constraint also protects a concurrent email claim.
            throw $this->duplicateEmailError();
        }
    }

    /** @return Builder<RegistrationRequest>|null */
    private function sessionQuery(mixed $session): ?Builder
    {
        if (! is_array($session) || ! is_int($session['id'] ?? null) || $session['id'] < 1
            || ! is_string($session['token'] ?? null) || ! $this->validToken($session['token'])) {
            return null;
        }

        return RegistrationRequest::query()->whereKey($session['id'])
            ->where('session_token_hash', hash('sha256', $session['token']))
            ->whereNotNull('verified_at')->whereNull('completed_at')
            ->where('session_expires_at', '>', now());
    }

    private function validToken(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
    }

    private function emailExists(string $email): bool
    {
        return User::query()->whereRaw('LOWER(email) = ?', [$email])->exists();
    }

    private function duplicateEmailError(): ValidationException
    {
        return ValidationException::withMessages([
            'registration' => 'This email is already registered. Please log in or use another email.',
        ]);
    }

    /** @param array<int, string> $visited */
    private function assertSafeMailTransport(string $mailer, array $visited = []): void
    {
        $transport = config("mail.mailers.{$mailer}.transport");

        if (in_array($mailer, $visited, true) || ! is_string($transport)
            || $transport === 'log') {
            throw ValidationException::withMessages([
                'registration' => 'Registration email delivery is unavailable. Please try again later.',
            ]);
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach (config("mail.mailers.{$mailer}.mailers", []) as $child) {
                $this->assertSafeMailTransport($child, [...$visited, $mailer]);
            }
        }
    }
}
