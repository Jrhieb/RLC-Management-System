<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendRegistrationVerification;
use App\Mail\RegistrationVerificationMail;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Services\EmailRegistrationService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class RegistrationDeliveryQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // TestCase guards SQLite :memory: and fake mail before any migration.
        // Use actual commit boundaries, then discard the memory connection.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->beforeApplicationDestroyed(fn () => DB::purge('sqlite'));
        Queue::fake();
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_job_is_dispatched_only_after_outer_commit_and_contains_no_plaintext_token_or_url(): void
    {
        DB::beginTransaction();
        app(EmailRegistrationService::class)->requestLink('registration@example.test');
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
        DB::commit();
        Queue::assertPushed(SendRegistrationVerification::class, 1);
        $job = Queue::pushed(SendRegistrationVerification::class)->sole();
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
        $this->assertSame('database', $job->connection);
        $this->assertSame(1, $job->tries);
        $token = Crypt::decryptString($job->encryptedToken);
        $this->assertSame(RegistrationRequest::sole()->token_hash, hash('sha256', $token));
        $queue = new DatabaseQueue(DB::connection('sqlite'), 'jobs', 'default');
        $queue->setContainer($this->app);
        $payload = (new ReflectionMethod($queue, 'createPayload'))->invoke($queue, $job, 'default');
        $this->assertStringNotContainsString($token, $payload);
        $this->assertStringNotContainsString('/register/verify/', $payload);
        Mail::assertNothingOutgoing();
    }

    public function test_outer_rollback_discards_the_request_and_delivery_callback(): void
    {
        DB::beginTransaction();
        app(EmailRegistrationService::class)->requestLink('rollback@example.test');
        DB::rollBack();
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_json_request_returns_queued_confirmation_without_calling_smtp_and_preserves_account_privacy(): void
    {
        $new = $this->postJson(route('registration.request'), ['email' => 'new@example.test']);
        $new->assertStatus(202)->assertJsonStructure(['message'])->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('queued', $new->json('message'));
        $this->assertStringNotContainsString('delivered', $new->json('message'));
        User::factory()->create(['email' => 'existing@example.test']);
        $existing = $this->postJson(route('registration.request'), ['email' => 'existing@example.test']);
        $existing->assertStatus(202)->assertExactJson($new->json());
        Queue::assertPushed(SendRegistrationVerification::class, 1);
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('registration_requests', 1);
    }

    public function test_current_job_sends_the_existing_mailable_using_lan_origin_and_remaining_lifetime(): void
    {
        config(['app.url' => 'http://192.0.2.41:8000']);
        $job = $this->requestJob();
        $this->travel(10)->minutes();
        $job->handle(app(EmailRegistrationService::class));
        Mail::assertSent(RegistrationVerificationMail::class, function ($mail) {
            $content = $mail->content()->with;

            return $mail->hasTo('registration@example.test')
                && str_starts_with($content['verificationUrl'], 'http://192.0.2.41:8000/register/')
                && $content['expiresInMinutes'] === 20;
        });
        Mail::assertSentCount(1);
        $this->assertNull(RegistrationRequest::sole()->verified_at);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('obsoleteRequests')]
    public function test_obsolete_delivery_is_skipped_without_mail(string $state): void
    {
        $job = $this->requestJob();
        $service = app(EmailRegistrationService::class);
        match ($state) {
            'expired' => $this->travel(30)->minutes(),
            'consumed' => $service->verify(Crypt::decryptString($job->encryptedToken)),
            'completed' => RegistrationRequest::query()->update(['completed_at' => now()]),
            'superseded' => $service->requestLink('registration@example.test'),
            'deleted' => RegistrationRequest::query()->delete(),
            'account claimed' => User::factory()->create(['email' => 'REGISTRATION@example.test']),
        };
        $job->handle($service);
        Mail::assertNothingOutgoing();
    }

    public function test_superseded_job_does_not_invalidate_or_send_the_new_request(): void
    {
        $old = $this->requestJob();
        app(EmailRegistrationService::class)->requestLink('registration@example.test');
        $new = Queue::pushed(SendRegistrationVerification::class)->last();
        $old->handle(app(EmailRegistrationService::class));
        $this->assertSame($new->tokenHash, RegistrationRequest::sole()->token_hash);
        Mail::assertNothingOutgoing();
        $new->handle(app(EmailRegistrationService::class));
        Mail::assertSentCount(1);
    }

    public function test_duplicate_delivery_of_the_same_generation_sends_only_once(): void
    {
        $job = $this->requestJob();
        $job->handle(app(EmailRegistrationService::class));
        $job->handle(app(EmailRegistrationService::class));
        Mail::assertSentCount(1);
        $this->assertSame($job->tokenHash, RegistrationRequest::sole()->token_hash);
    }

    public function test_delivery_failure_invalidates_only_its_generation_and_has_no_sensitive_exception_chain(): void
    {
        $job = $this->requestJob();
        $token = Crypt::decryptString($job->encryptedToken);
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Synthetic SMTP secret '.$token));
        Log::shouldReceive('warning')->once()->with('Registration verification email delivery failed.');
        try {
            $job->handle(app(EmailRegistrationService::class));
            $this->fail('Delivery must report failure.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($token, (string) $exception);
            $this->assertStringNotContainsString('Synthetic SMTP secret', (string) $exception);
        }
        $this->assertNull(RegistrationRequest::sole()->token_hash);
        Queue::assertPushed(SendRegistrationVerification::class, 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_tampered_ciphertext_is_not_sent_and_is_reported_without_token_details(): void
    {
        $job = $this->requestJob();
        $job->encryptedToken = 'invalid-ciphertext';
        Log::shouldReceive('warning')->once()->with('Registration verification email delivery failed.');
        try {
            $job->handle(app(EmailRegistrationService::class));
            $this->fail('Tampered delivery must fail.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('invalid-ciphertext', $exception->getMessage());
        }
        Mail::assertNothingOutgoing();
    }

    public function test_log_transport_selected_after_enqueue_is_still_blocked_without_logging_a_link(): void
    {
        $job = $this->requestJob();
        config(['mail.default' => 'log']);
        Log::shouldReceive('warning')->once()->with('Registration verification email delivery failed.');
        try {
            $job->handle(app(EmailRegistrationService::class));
            $this->fail('A queued link must not be sent to a log transport.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
        }
        Mail::assertNothingOutgoing();
    }

    public function test_failed_old_delivery_does_not_invalidate_a_new_generation_requested_during_the_attempt(): void
    {
        $job = $this->requestJob();
        $service = app(EmailRegistrationService::class);
        Mail::shouldReceive('to')->once()->andReturnUsing(function () use ($service) {
            $service->requestLink('registration@example.test');
            throw new RuntimeException('Synthetic transport failure');
        });
        Log::shouldReceive('warning')->once()->with('Registration verification email delivery failed.');
        try {
            $job->handle($service);
            $this->fail('The old attempt must fail.');
        } catch (RuntimeException) {
            $new = Queue::pushed(SendRegistrationVerification::class)->last();
            $this->assertNotSame($job->tokenHash, $new->tokenHash);
            $this->assertSame($new->tokenHash, RegistrationRequest::sole()->token_hash);
        }
        Queue::assertPushed(SendRegistrationVerification::class, 2);
    }

    public static function obsoleteRequests(): array
    {
        return array_map(fn ($state) => [$state], ['expired', 'consumed', 'completed', 'superseded', 'deleted', 'account claimed']);
    }

    private function requestJob(): SendRegistrationVerification
    {
        app(EmailRegistrationService::class)->requestLink('registration@example.test');

        return Queue::pushed(SendRegistrationVerification::class)->last();
    }
}
