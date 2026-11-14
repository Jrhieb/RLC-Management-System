<?php

namespace Tests\Feature\Auth;

use App\Actions\ReviewCaseworkerAccount;
use App\Jobs\SendRegistrationVerification;
use App\Jobs\SkipRegistrationVerification;
use App\Mail\AccountDecisionMail;
use App\Mail\RegistrationVerificationMail;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Services\EmailRegistrationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RegistrationEmailReliabilityTest extends TestCase
{
    private QueueManager $originalQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->beforeApplicationDestroyed(fn () => DB::purge('sqlite'));
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
        $this->originalQueue = Queue::getFacadeRoot();
        Queue::fake();
    }

    #[DataProvider('accountVerificationStates')]
    public function test_unsafe_mail_configuration_returns_the_same_private_error_for_new_and_existing_addresses(bool $verified): void
    {
        User::factory()->create(['email' => 'existing@example.test', 'email_verified_at' => $verified ? now() : null]);
        config(['mail.default' => 'log']);
        $new = $this->postJson(route('registration.request'), ['email' => 'new@example.test']);
        $existing = $this->postJson(route('registration.request'), ['email' => 'existing@example.test']);
        $new->assertUnprocessable()->assertJsonValidationErrors('registration');
        $existing->assertStatus($new->getStatusCode())->assertExactJson($new->json());
        $this->assertDatabaseCount('registration_requests', 0);
        $this->assertDatabaseCount('users', 1);
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public static function accountVerificationStates(): array
    {
        return ['verified' => [true], 'unverified' => [false]];
    }

    #[DataProvider('decisions')]
    public function test_decision_transport_failure_has_no_sensitive_exception_chain_or_failed_job_diagnostics(string $decision): void
    {
        $mail = (new AccountDecisionMail($decision))->to('decision@example.test');
        $factory = Mockery::mock(MailFactory::class);
        $mailer = Mockery::mock(Mailer::class);
        $factory->shouldReceive('mailer')->andReturn($mailer);
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic private SMTP credential marker'));
        try {
            (new SendQueuedMailable($mail))->handle($factory);
            $this->fail('The fake transport must fail.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertFalse(str_contains((string) $exception, 'Synthetic private SMTP credential marker'), 'Transport details must not survive in an exception.');
            app('queue.failer')->log('database', 'default', json_encode([
                'uuid' => '00000000-0000-4000-8000-000000000001', 'displayName' => SendQueuedMailable::class,
                'data' => ['commandName' => SendQueuedMailable::class, 'command' => serialize(new SendQueuedMailable($mail))],
            ]), $exception);
            $this->assertDatabaseCount('failed_jobs', 1);
            $this->assertFalse(str_contains(DB::table('failed_jobs')->sole()->exception, 'Synthetic private SMTP credential marker'));
        }
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public static function decisions(): array
    {
        return [['approved'], ['rejected']];
    }

    #[DataProvider('deliveryKinds')]
    public function test_database_queue_with_after_commit_false_discards_rollback_and_enqueues_after_commit(string $kind): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite']);
        Queue::swap($this->originalQueue);
        $queue = Queue::connection('database');
        $this->assertSame(':memory:', $queue->getDatabase()->getDatabaseName());
        $this->assertFalse(config('queue.connections.database.after_commit'));
        $transport = new ArrayTransport;
        $mailer = new \Illuminate\Mail\Mailer('smtp', app('view'), $transport, app('events'));
        $mailer->setQueue($this->originalQueue);
        Mail::swap($mailer); // In-process array transport; no SMTP socket exists.
        $director = User::factory()->create(['role' => 'project_director']);
        $account = User::factory()->pendingApproval()->create(['email' => 'decision@example.test']);
        $dispatch = function () use ($kind, $director, $account): void {
            if (in_array($kind, ['registration', 'existing'], true)) {
                app(EmailRegistrationService::class)->requestLink($kind === 'existing' ? 'decision@example.test' : 'registration@example.test');
            } else {
                $this->assertTrue(app(ReviewCaseworkerAccount::class)->execute($director, $account, [
                    'decision' => $kind === 'approved' ? 'approve' : 'reject',
                    'confirmed' => true, 'rejection_reason' => 'Synthetic reason',
                ]));
            }
        };
        DB::beginTransaction();
        $dispatch();
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('registration_requests', 0);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame('pending', $account->fresh()->approval_status);
        DB::beginTransaction();
        $dispatch();
        $this->assertDatabaseCount('jobs', 0);
        DB::commit();
        $row = DB::table('jobs')->sole();
        $this->assertSame('default', $row->queue);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->reserved_at);
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $expectedClass = match ($kind) {
            'registration' => SendRegistrationVerification::class,
            'existing' => SkipRegistrationVerification::class,
            default => SendQueuedMailable::class,
        };
        $this->assertSame($expectedClass, $payload['data']['commandName']);
        $job = unserialize($payload['data']['command']); // This test's own synthetic payload.
        if ($kind === 'registration') {
            $this->assertSame(RegistrationRequest::sole()->id, $job->registrationRequestId);
            $this->assertSame(1, $job->tries);
        } elseif ($kind === 'existing') {
            $this->assertSame(['tries' => 1], get_object_vars($job));
            $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
            $job->handle();
            $this->assertDatabaseCount('registration_requests', 0);
            $this->assertDatabaseCount('account_notifications', 0);
            $this->assertSame('pending', $account->fresh()->approval_status);
        } else {
            $this->assertSame(AccountDecisionMail::class, get_class($job->mailable));
            $this->assertSame($kind, $job->mailable->decision);
            $this->assertTrue($job->mailable->hasTo('decision@example.test'));
            $this->assertSame(3, $job->tries);
            $this->assertSame([60, 300], $job->backoff());
        }
        $this->assertTrue($transport->messages()->isEmpty());
        $this->assertDatabaseCount('failed_jobs', 0);
        Notification::assertNothingSent();
    }

    public static function deliveryKinds(): array
    {
        return [['registration'], ['existing'], ['approved'], ['rejected']];
    }

    public function test_transport_failure_leaves_no_live_link_but_a_fresh_request_after_cooldown_can_send(): void
    {
        $this->postJson(route('registration.request'), ['email' => 'retry@example.test'])->assertStatus(202);
        $old = Queue::pushed(SendRegistrationVerification::class)->sole();
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Synthetic transport failure'));
        Log::spy();
        try {
            $old->handle(app(EmailRegistrationService::class));
            $this->fail('The fake transport must fail.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
        }
        Mail::swap($mailFake);
        $this->assertNull(RegistrationRequest::sole()->token_hash);
        $this->assertTrue(Cache::has('registration-verification-delivery:'.$old->registrationRequestId.':'.$old->tokenHash));
        $this->postJson(route('registration.request'), ['email' => 'retry@example.test'])->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson(route('registration.request'), ['email' => 'retry@example.test'])->assertStatus(202);
        $new = Queue::pushed(SendRegistrationVerification::class)->last();
        $this->assertFalse(hash_equals($old->tokenHash, $new->tokenHash));
        $old->handle(app(EmailRegistrationService::class));
        Mail::assertNothingOutgoing();
        $new->handle(app(EmailRegistrationService::class));
        Mail::assertSent(RegistrationVerificationMail::class, fn ($mail) => $mail->hasTo('retry@example.test'));
        Mail::assertSentCount(1);
        Queue::assertPushed(SendRegistrationVerification::class, 2);
        $this->assertDatabaseCount('registration_requests', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_simulated_acceptance_followed_by_error_does_not_retry_the_same_generation(): void
    {
        app(EmailRegistrationService::class)->requestLink('ambiguous@example.test');
        $job = Queue::pushed(SendRegistrationVerification::class)->sole();
        $calls = 0;
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andReturnUsing(function () use (&$calls) {
            $calls++; // Simulated acceptance only; no transport is contacted.
            throw new RuntimeException('Synthetic error after possible acceptance');
        });
        Mail::shouldReceive('to')->once()->andReturn($pending);
        Log::spy();
        try {
            $job->handle(app(EmailRegistrationService::class));
            $this->fail('The ambiguous simulated send must fail.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
        }
        $job->handle(app(EmailRegistrationService::class));
        $this->assertSame(1, $calls);
        $this->assertNull(RegistrationRequest::sole()->token_hash);
        Queue::assertPushed(SendRegistrationVerification::class, 1);
    }

    #[DataProvider('dispatchFailureRequests')]
    public function test_registration_returns_a_generic_retry_later_response_during_queue_dispatch_failure(string $addressType, bool $json): void
    {
        $email = 'outage@example.test';
        $existing = str_starts_with($addressType, 'existing');
        $account = $existing ? User::factory()->create([
            'email' => $email, 'email_verified_at' => $addressType === 'existing_verified' ? now() : null,
        ]) : null;
        $account?->refresh();
        $before = $account?->getRawOriginal();
        if ($addressType === 'resend') {
            DB::table('registration_requests')->insert([
                'email' => $email, 'token_hash' => hash('sha256', 'synthetic-old-generation'),
                'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $dispatcher = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function (): void {
            $this->assertSame(0, DB::transactionLevel());
            throw new RuntimeException('Synthetic private queue failure');
        });
        Log::spy();
        $response = $json
            ? $this->postJson(route('registration.request'), ['email' => $email])
            : $this->from(route('register'))->post(route('registration.request'), ['email' => $email]);
        $response->assertStatus(503)
            ->assertContent('Registration is temporarily unavailable. Please try again later.')
            ->assertHeaderMissing('Location')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSessionMissing('status')->assertSessionMissing('_old_input')->assertSessionHasNoErrors();
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('users', $existing ? 1 : 0);
        $this->assertDatabaseCount('registration_requests', $existing ? 0 : 1);
        if ($existing) {
            $this->assertTrue($before === $account->fresh()->getRawOriginal(), 'The account record must remain unchanged.');
        } else {
            $row = RegistrationRequest::sole();
            $this->assertNull($row->token_hash);
            $this->assertNull($row->verified_at);
            $this->assertNull($row->session_token_hash);
            $this->assertNull($row->completed_at);
        }
        // Both failed address types consume the same existing email cooldown.
        $this->postJson(route('registration.request'), ['email' => $email])->assertStatus(429)
            ->assertContent('Too many attempts. Please try again later.');
        Bus::swap($dispatcher);
        $this->travel(61)->seconds();
        $success = 'If this email is available, a verification email has been queued. Check your inbox shortly.';
        if ($json) {
            $this->postJson(route('registration.request'), ['email' => $email])
                ->assertStatus(202)->assertExactJson(['message' => $success]);
        } else {
            $this->post(route('registration.request'), ['email' => $email])
                ->assertRedirect(route('register'))->assertSessionHas('status', $success)->assertSessionHasNoErrors();
        }
        $jobClass = $existing ? SkipRegistrationVerification::class : SendRegistrationVerification::class;
        Queue::assertPushed($jobClass, 1);
        Queue::assertCount(1);
        app()->call([Queue::pushed($jobClass)->sole(), 'handle']); // Fake mail; no worker or network.
        if ($existing) {
            Mail::assertNothingOutgoing();
            $this->assertDatabaseCount('registration_requests', 0);
            $this->assertTrue($before === $account->fresh()->getRawOriginal(), 'The account record must remain unchanged.');
        } else {
            Mail::assertSent(RegistrationVerificationMail::class, fn ($mail) => $mail->hasTo($email));
            Mail::assertSentCount(1);
            $this->assertNotNull(RegistrationRequest::sole()->token_hash);
        }
    }

    public static function dispatchFailureRequests(): array
    {
        $cases = [];
        foreach (['unused', 'resend', 'existing_verified', 'existing_unverified'] as $addressType) {
            foreach ([false, true] as $json) {
                $cases[$addressType.($json ? '_json' : '_web')] = [$addressType, $json];
            }
        }

        return $cases;
    }

    public function test_hourly_ip_limit_allows_thirty_then_blocks_thirty_first_after_minute_windows_reset(): void
    {
        for ($batch = 0; $batch < 3; $batch++) {
            for ($i = 0; $i < 10; $i++) {
                $this->postJson(route('registration.request'), ['email' => 'ip-hour-'.$batch.'-'.$i.'@example.test'])->assertStatus(202);
            }
            $this->travel(61)->seconds();
        }
        $blocked = $this->postJson(route('registration.request'), ['email' => 'ip-hour-blocked@example.test']);
        $blocked->assertStatus(429)->assertHeader('Retry-After', '3417')
            ->assertContent('Too many attempts. Please try again later.');
        Queue::assertPushed(SendRegistrationVerification::class, 30);
        $this->assertDatabaseCount('registration_requests', 30);
        Mail::assertNothingOutgoing();
    }

    public function test_invalid_requests_consume_the_existing_ip_limit_even_without_queueing(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('registration.request'), ['email' => 'invalid-'.$i])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson(route('registration.request'), ['email' => 'valid@example.test'])->assertStatus(429);
        $this->assertDatabaseCount('registration_requests', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public function test_normalization_preserves_plus_tags_and_case_whitespace_cannot_bypass_email_cooldown(): void
    {
        $this->postJson(route('registration.request'), ['email' => '  Person+Tag@Example.Test  '])->assertStatus(202);
        $this->postJson(route('registration.request'), ['email' => 'PERSON+TAG@EXAMPLE.TEST'])->assertStatus(429);
        $this->postJson(route('registration.request'), ['email' => 'person+other@example.test'])->assertStatus(202);
        $this->assertSame(['person+other@example.test', 'person+tag@example.test'], RegistrationRequest::orderBy('email')->pluck('email')->all());
        Queue::assertPushed(SendRegistrationVerification::class, 2);
        Mail::assertNothingOutgoing();
    }

    public function test_existing_unverified_account_uses_fortify_resend_and_its_six_per_minute_user_limit(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'legacy@example.test']);
        $this->postJson(route('registration.request'), ['email' => ' LEGACY@EXAMPLE.TEST '])->assertStatus(202);
        $this->assertDatabaseCount('registration_requests', 0);
        Queue::assertPushed(SkipRegistrationVerification::class, 1);
        Queue::assertCount(1);
        $this->actingAs($user);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('verification.send'))->assertStatus(202)->assertContent('""');
        }
        $this->postJson(route('verification.send'))->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
        $this->travel(61)->seconds();
        $this->from(route('verification.notice'))->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentToTimes($user, VerifyEmail::class, 7);
        Queue::assertPushed(SkipRegistrationVerification::class, 1);
        Queue::assertCount(1);
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_existing_account_signed_verification_link_rejects_expiry_without_changing_the_account(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->travel(60)->minutes();
        $this->travel(1)->seconds();
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('registration_requests', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_request_and_verification_timestamps_keep_their_actual_event_time_through_resend(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC'));
        $service = app(EmailRegistrationService::class);
        $service->requestLink('timestamps@example.test');
        $row = RegistrationRequest::sole();
        $id = $row->id;
        $this->assertSame('2027-01-01 00:00:00', $row->getRawOriginal('created_at'));
        $this->assertSame('2027-01-01 00:30:00', $row->getRawOriginal('expires_at'));
        $this->travel(61)->seconds();
        $service->requestLink('timestamps@example.test');
        $row = RegistrationRequest::sole();
        $this->assertSame($id, $row->id);
        $this->assertSame('2027-01-01 00:00:00', $row->getRawOriginal('created_at'));
        $this->assertSame('2027-01-01 00:01:01', $row->getRawOriginal('updated_at'));
        $this->assertSame('2027-01-01 00:31:01', $row->getRawOriginal('expires_at'));
        $this->travel(29)->minutes();
        $job = Queue::pushed(SendRegistrationVerification::class)->last();
        $this->assertNotNull($service->verify(Crypt::decryptString($job->encryptedToken)));
        $row = RegistrationRequest::sole();
        $this->assertSame('2027-01-01 00:30:01', $row->getRawOriginal('verified_at'));
        $this->assertSame('2027-01-01 00:45:01', $row->getRawOriginal('session_expires_at'));
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('changedAccounts')]
    public function test_standard_decision_job_uses_its_captured_recipient_after_account_change_or_deletion(string $change): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $account = User::factory()->pendingApproval()->create(['email' => 'original@example.test']);
        $this->assertTrue(app(ReviewCaseworkerAccount::class)->execute($director, $account, ['decision' => 'approve', 'confirmed' => true]));
        $mail = Mail::queued(AccountDecisionMail::class)->sole();
        match ($change) {
            'deleted' => $account->delete(),
            'email changed' => $account->forceFill(['email' => 'changed@example.test'])->save(),
            'status changed' => $account->forceFill(['approval_status' => 'rejected'])->save(),
        };
        $transport = new ArrayTransport;
        $mailer = new \Illuminate\Mail\Mailer('array', app('view'), $transport, app('events'));
        $mailer->alwaysFrom('no-reply@example.test', 'Synthetic sender');
        $factory = Mockery::mock(MailFactory::class);
        $factory->shouldReceive('mailer')->once()->andReturn($mailer);
        // Characterization of the standard job, without a worker or network.
        // The LAN helper has a separate current-recipient/status gate.
        (new SendQueuedMailable($mail))->handle($factory);
        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()->sole()->getOriginalMessage();
        $this->assertSame('original@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('Your account has been approved', $message->getSubject());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function changedAccounts(): array
    {
        return [['deleted'], ['email changed'], ['status changed']];
    }
}
