<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\RegistrationController;
use App\Jobs\SendRegistrationVerification;
use App\Mail\RegistrationVerificationMail;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Services\EmailRegistrationService;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'new-caseworker@example.test';

    private const PASSWORD = 'Registration-Password-123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
    }

    public function test_email_entry_reuses_auth_ui_and_has_no_standard_fortify_registration_route(): void
    {
        $response = $this->get(route('register'));
        $response->assertOk()->assertSee('Verify your email')->assertSee(route('registration.request'))
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $xpath = $this->dom($response->getContent());
        $this->assertSame(1, $xpath->query('//input[@name="email" and @type="email" and @autocomplete="email"]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="name" or @name="password" or @name="password_confirmation"]')->length);
        $this->assertSame(1, $xpath->query('//meta[@name="viewport"]')->length);
        $this->assertFalse(Features::enabled(Features::registration()));
        $this->assertFalse(Route::has('register.store'));

        $registerRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => $route->uri() === 'register');
        $this->assertCount(2, $registerRoutes);
        foreach ($registerRoutes as $route) {
            $this->assertStringStartsWith(RegistrationController::class.'@', $route->getActionName());
        }
        $this->get(route('login'))->assertOk()->assertSee(route('register'));
    }

    public function test_request_queues_delivery_stores_only_hashes_and_never_creates_a_user(): void
    {
        $response = $this->post(route('registration.request'), [
            'email' => '  NEW-Caseworker@Example.Test  ', ...$this->payload(),
        ]);
        $response->assertRedirect(route('register'))->assertSessionHasNoErrors()
            ->assertSessionHas('status')->assertSessionMissing(EmailRegistrationService::SESSION_KEY);
        Queue::assertPushed(SendRegistrationVerification::class, 1);
        Mail::assertNothingOutgoing();
        $token = $this->linkToken();
        $row = RegistrationRequest::sole();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertTrue($row->expires_at->equalTo(now()->addMinutes(30)));
        $this->assertNull($row->verified_at);
        $this->assertNull($row->session_token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('registration_requests')->first()));
        $this->assertArrayNotHasKey('token_hash', $row->toArray());
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString($token, json_encode(session()->all()));
        $this->assertStringNotContainsString($token, serialize(Queue::pushed(SendRegistrationVerification::class)->sole()));
        $this->get(route('register'))->assertOk()->assertSee('Resend verification link')->assertDontSee($token);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_verification_rotates_session_and_completion_forces_verified_pending_caseworker_without_logging_in(): void
    {
        $this->requestLink();
        $link = $this->verificationLink();
        $this->get(route('register'));
        $oldSessionId = session()->getId();
        $this->get($link)->assertRedirect(route('registration.complete'))->assertSessionHasNoErrors();
        $session = session(EmailRegistrationService::SESSION_KEY);
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertSame(hash('sha256', $session['token']), RegistrationRequest::sole()->session_token_hash);
        $this->assertNull(RegistrationRequest::sole()->token_hash);
        $this->assertStringNotContainsString($this->linkToken(), json_encode(session()->all()));
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();

        $page = $this->get(route('registration.complete'));
        $page->assertOk()->assertSee('Complete Registration')->assertSee(self::EMAIL)->assertDontSee($session['token']);
        $xpath = $this->dom($page->getContent());
        $this->assertSame(0, $xpath->query('//input[@name="email" or @name="role"]')->length);
        $this->assertSame(2, $xpath->query('//input[@type="password" and @autocomplete="new-password"]')->length);
        Event::fake([Registered::class]);

        $this->post(route('registration.complete.store'), $this->payload() + [
            'email' => 'attacker@example.test', 'role' => 'project_director', 'approval_status' => 'approved',
            'email_verified_at' => null, 'approval_reviewed_by' => 99, 'approval_reviewed_at' => now(),
            'rejection_reason' => 'Untrusted input', 'registration_request_id' => 99,
        ])->assertRedirect(route('registration.awaiting-approval'))->assertSessionHasNoErrors()
            ->assertSessionMissing(EmailRegistrationService::SESSION_KEY);
        $user = User::sole();
        $this->assertSame(self::EMAIL, $user->email);
        $this->assertSame('New Caseworker', $user->name);
        $this->assertSame('caseworker', $user->role);
        $this->assertSame('pending', $user->approval_status);
        $this->assertTrue($user->email_verified_at->equalTo(RegistrationRequest::sole()->verified_at));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNull($user->approval_reviewed_by);
        $this->assertNull($user->approval_reviewed_at);
        $this->assertNull($user->rejection_reason);
        $this->assertNotNull(RegistrationRequest::sole()->completed_at);
        $this->assertNull(RegistrationRequest::sole()->session_token_hash);
        $this->assertNull(RegistrationRequest::sole()->session_expires_at);
        $this->assertGuest();
        $this->get(route('registration.awaiting-approval'))->assertOk()->assertSee('Your account is awaiting Director approval.');
        Event::assertNotDispatched(Registered::class);
        Notification::assertNothingSent();
        Queue::assertPushed(SendRegistrationVerification::class, 1);

        $this->withSession([EmailRegistrationService::SESSION_KEY => $session])
            ->post(route('registration.complete.store'), $this->payload())
            ->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        $this->get($link)->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_expired_verification_link_is_rejected_at_the_exact_deadline(): void
    {
        $this->requestLink();
        $link = $this->verificationLink();
        $this->travel(30)->minutes();
        $response = $this->get($link);
        $response->assertRedirect(route('register'))->assertSessionHasErrors('registration')
            ->assertSessionMissing(EmailRegistrationService::SESSION_KEY);
        $this->assertStringNotContainsString($this->linkToken(), json_encode(session()->all()));
        $this->assertNull(RegistrationRequest::sole()->verified_at);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_tampered_link_does_not_consume_the_valid_link(): void
    {
        $this->requestLink();
        $token = $this->linkToken();
        $tampered = ($token[0] === 'a' ? 'b' : 'a').substr($token, 1);
        foreach ([$tampered, 'not-a-token', str_repeat('0', 64), $token.'0'] as $invalid) {
            $this->get(route('registration.verify', ['token' => $invalid]))
                ->assertRedirect(route('register'))->assertSessionHasErrors('registration')
                ->assertSessionMissing(EmailRegistrationService::SESSION_KEY);
        }
        $this->get($this->verificationLink())->assertRedirect(route('registration.complete'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_link_replay_cannot_establish_a_session_in_another_browser(): void
    {
        $this->requestLink();
        $link = $this->verificationLink();
        $this->get($link)->assertRedirect(route('registration.complete'));
        $this->flushSession();
        $this->get($link)->assertRedirect(route('register'))->assertSessionHasErrors('registration')
            ->assertSessionMissing(EmailRegistrationService::SESSION_KEY);
        $this->get(route('registration.complete'))->assertRedirect(route('register'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_resend_replaces_the_link_in_the_same_request_row(): void
    {
        $this->requestLink();
        $oldLink = $this->verificationLink();
        $id = RegistrationRequest::sole()->id;
        $this->travel(61)->seconds();
        $this->requestLink();
        $this->assertDatabaseCount('registration_requests', 1);
        $this->assertSame($id, RegistrationRequest::sole()->id);
        $this->get($oldLink)->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        $this->get($this->verificationLink())->assertRedirect(route('registration.complete'));
        Queue::assertPushed(SendRegistrationVerification::class, 2);
    }

    public function test_resend_revokes_an_already_verified_session_including_copied_session_data(): void
    {
        $session = $this->verifiedSession();
        $this->travel(61)->seconds();
        $this->requestLink();
        $newLink = $this->verificationLink();
        $this->withSession([EmailRegistrationService::SESSION_KEY => $session])
            ->get(route('registration.complete'))->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        $this->withSession([EmailRegistrationService::SESSION_KEY => $session])
            ->post(route('registration.complete.store'), $this->payload())
            ->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 0);
        $this->get($newLink)->assertRedirect(route('registration.complete'));
        $this->post(route('registration.complete.store'), $this->payload())->assertRedirect(route('registration.awaiting-approval'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_session_expires_at_fifteen_minutes_even_if_the_form_was_opened(): void
    {
        $this->verifiedSession();
        $this->get(route('registration.complete'))->assertOk();
        $this->travel(15)->minutes();
        $this->post(route('registration.complete.store'), $this->payload())
            ->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(RegistrationRequest::sole()->completed_at);
    }

    public function test_a_verified_session_remains_valid_when_the_original_link_later_expires(): void
    {
        $this->requestLink();
        $this->travel(29)->minutes();
        $this->get($this->verificationLink())->assertRedirect(route('registration.complete'));
        $this->travel(14)->minutes();
        $this->post(route('registration.complete.store'), $this->payload())->assertRedirect(route('registration.awaiting-approval'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_completion_and_confirmation_cannot_be_accessed_without_a_verified_session(): void
    {
        $this->get(route('registration.complete'))->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        $this->post(route('registration.complete.store'), $this->payload() + ['email' => self::EMAIL, 'token' => str_repeat('a', 64)])
            ->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        $this->get(route('registration.awaiting-approval'))->assertRedirect(route('register'));
        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    #[DataProvider('invalidSessions')]
    public function test_a_forged_registration_session_cannot_complete(string $kind): void
    {
        $session = $this->verifiedSession();
        $forged = match ($kind) {
            'wrong token' => [...$session, 'token' => str_repeat('0', 64)],
            'hash instead of token' => [...$session, 'token' => hash('sha256', $session['token'])],
            'wrong id' => [...$session, 'id' => $session['id'] + 1],
            'string id' => [...$session, 'id' => (string) $session['id']],
            'array token' => [...$session, 'token' => ['invalid']],
            'missing token' => ['id' => $session['id']],
        };
        $this->withSession([EmailRegistrationService::SESSION_KEY => $forged])
            ->post(route('registration.complete.store'), $this->payload())
            ->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(RegistrationRequest::sole()->completed_at);
    }

    public function test_an_existing_email_is_not_mailed_or_changed_including_mixed_case_legacy_emails(): void
    {
        $legacy = User::factory()->create(['email' => 'NEW-Caseworker@Example.Test', 'role' => 'project_director']);
        $legacy->refresh();
        $before = $legacy->getRawOriginal();
        $this->requestLink();
        $this->assertDatabaseCount('registration_requests', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        Mail::assertNothingOutgoing();
    }

    public function test_an_email_claimed_after_verification_cannot_be_overwritten_by_completion(): void
    {
        $this->verifiedSession();
        $legacy = User::factory()->create(['email' => self::EMAIL, 'role' => 'project_director']);
        $legacy->refresh();
        $before = $legacy->getRawOriginal();
        $this->post(route('registration.complete.store'), $this->payload())->assertSessionHasErrors('registration');
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
        $this->assertNull(RegistrationRequest::sole()->completed_at);
        $this->assertGuest();
    }

    public function test_interleaved_completions_can_create_only_one_account(): void
    {
        $session = $this->verifiedSession();
        $service = app(EmailRegistrationService::class);
        $contenderRejected = false;
        // Interleave a second submission after the first claim, before the first user insert.
        // Both use the same guarded :memory: database; no external worker or database is used.
        User::creating(function () use ($service, $session, &$contenderRejected) {
            try {
                $service->complete($session, ['name' => 'Competing Caseworker', 'password' => self::PASSWORD]);
            } catch (ValidationException $exception) {
                $contenderRejected = isset($exception->errors()['registration']);
            }
        });
        $this->post(route('registration.complete.store'), $this->payload())->assertRedirect(route('registration.awaiting-approval'));
        $this->assertTrue($contenderRejected);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('New Caseworker', User::sole()->name);
        $this->assertNotNull(RegistrationRequest::sole()->completed_at);
    }

    public function test_atomic_completion_rechecks_a_session_invalidated_after_the_form_was_authorized(): void
    {
        $session = $this->verifiedSession();
        $service = app(EmailRegistrationService::class);
        $this->assertNotNull($service->activeRequest($session));
        $service->requestLink(self::EMAIL);
        try {
            $service->complete($session, ['name' => 'Old Form', 'password' => self::PASSWORD]);
            $this->fail('A superseded session must not complete.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('registration', $exception->errors());
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_concurrent_duplicate_email_insert_returns_validation_and_rolls_back_the_claim(): void
    {
        $this->verifiedSession();
        User::creating(function (User $user) {
            DB::table('users')->insert([
                'name' => 'Competing memory fixture', 'email' => $user->email,
                'password' => Hash::make(self::PASSWORD), 'role' => 'caseworker',
            ]);
        });
        $this->post(route('registration.complete.store'), $this->payload())->assertSessionHasErrors('registration')
            ->assertSessionMissing('registration_completed')->assertSessionMissing('_old_input.password');
        // The simulated competing insert is in the same memory transaction and also rolls back.
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(RegistrationRequest::sole()->completed_at);
        $this->assertNotNull(RegistrationRequest::sole()->session_token_hash);
    }

    public function test_failed_account_save_rolls_back_session_consumption_and_never_reports_success(): void
    {
        $this->verifiedSession();
        User::saving(fn () => false);
        $this->post(route('registration.complete.store'), $this->payload())->assertSessionHasErrors('registration')
            ->assertSessionMissing('registration_completed');
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(RegistrationRequest::sole()->completed_at);
        $this->assertNotNull(RegistrationRequest::sole()->session_token_hash);
    }

    #[DataProvider('invalidCompletion')]
    public function test_completion_reuses_password_validation_and_keeps_passwords_out_of_old_input(array $fields, string $error): void
    {
        $this->verifiedSession();
        $response = $this->post(route('registration.complete.store'), array_replace($this->payload(), $fields));
        $response->assertRedirect(route('registration.complete'))->assertSessionHasErrors($error)
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(RegistrationRequest::sole()->completed_at);
        $this->get(route('registration.complete'))->assertOk()->assertDontSee(self::PASSWORD);
    }

    public function test_configured_password_defaults_are_used(): void
    {
        $this->verifiedSession();
        Password::defaults(fn () => Password::min(16)->mixedCase()->numbers()->symbols());
        try {
            $this->post(route('registration.complete.store'), $this->payload('abcdefghijklmnop'))
                ->assertSessionHasErrors('password');
            $this->assertDatabaseCount('users', 0);
        } finally {
            Password::defaults(fn () => null);
        }
    }

    public function test_email_and_ip_limits_apply_to_requests_and_resends(): void
    {
        $this->requestLink();
        $hash = RegistrationRequest::sole()->token_hash;
        $this->post(route('registration.request'), ['email' => strtoupper(self::EMAIL)])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame($hash, RegistrationRequest::sole()->token_hash);
        Queue::assertPushed(SendRegistrationVerification::class, 1);
        for ($i = 1; $i < 10; $i++) {
            $this->post(route('registration.request'), ['email' => 'different-'.$i.'@example.test'])->assertRedirect(route('register'));
        }
        $this->post(route('registration.request'), ['email' => 'ip-limit@example.test'])->assertStatus(429);
        Queue::assertPushed(SendRegistrationVerification::class, 10);
    }

    public function test_hourly_email_limit_cannot_be_bypassed_by_changing_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)]);
            $this->requestLink();
            $this->travel(61)->seconds();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100'])
            ->post(route('registration.request'), ['email' => self::EMAIL])->assertStatus(429);
        Queue::assertPushed(SendRegistrationVerification::class, 5);
    }

    public function test_mail_origin_uses_app_url_instead_of_request_host(): void
    {
        config(['app.url' => 'https://registration.example.test']);
        $this->withServerVariables(['HTTP_HOST' => 'untrusted.example.test'])->requestLink();
        Queue::pushed(SendRegistrationVerification::class)->last()->handle(app(EmailRegistrationService::class));
        $url = Mail::sent(RegistrationVerificationMail::class)->last()->content()->with['verificationUrl'];
        $this->assertSame('registration.example.test', parse_url($url, PHP_URL_HOST));
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
    }

    public function test_mail_template_contains_the_link_only_in_the_fake_email(): void
    {
        $this->requestLink();
        Queue::pushed(SendRegistrationVerification::class)->last()->handle(app(EmailRegistrationService::class));
        $mail = Mail::sent(RegistrationVerificationMail::class)->last();
        $rendered = $mail->render();
        $this->assertStringContainsString('Verify email', $rendered);
        $this->assertStringContainsString($this->linkToken(), $rendered);
        $this->get(route('register'))->assertDontSee($this->linkToken());
    }

    #[DataProvider('unsafeMailers')]
    public function test_log_mail_transport_is_blocked_outside_local_including_fallbacks(string $mailer): void
    {
        config(['mail.default' => $mailer]);
        $this->post(route('registration.request'), ['email' => self::EMAIL])->assertSessionHasErrors('registration');
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_log_transport_is_blocked_even_locally_to_keep_tokens_out_of_logs(): void
    {
        config(['mail.default' => 'log']);
        $this->app['env'] = 'local';
        try {
            $this->withSession(['_token' => 'registration-csrf-fixture'])
                ->post(route('registration.request'), ['email' => self::EMAIL, '_token' => 'registration-csrf-fixture'])
                ->assertRedirect(route('register'))->assertSessionHasErrors('registration');
            Mail::assertNothingOutgoing();
            Queue::assertNothingPushed();
            $this->assertDatabaseCount('registration_requests', 0);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_enqueue_failure_invalidates_the_new_link_without_logging_details_during_queue_dispatch_failure(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Synthetic sensitive transport details'));
        Log::shouldReceive('warning')->once()->with('Registration verification email could not be queued.');
        Log::shouldReceive('error')->once()->with('Email registration failed.', ['exception' => RuntimeException::class]);
        $this->post(route('registration.request'), ['email' => self::EMAIL])->assertStatus(503)
            ->assertContent('Registration is temporarily unavailable. Please try again later.')
            ->assertHeaderMissing('Location')->assertSessionMissing('status')->assertSessionHasNoErrors()
            ->assertDontSee('Synthetic sensitive transport details');
        $this->assertNull(RegistrationRequest::sole()->token_hash);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unexpected_verification_failure_never_exposes_a_bearer_link_in_debug_or_logs(): void
    {
        $this->requestLink();
        $token = $this->linkToken();
        config(['app.debug' => true]);
        $this->mock(EmailRegistrationService::class, function ($mock) use ($token) {
            $mock->shouldReceive('verify')->once()->andThrow(new RuntimeException('Synthetic failure with '.$token));
        });
        Log::shouldReceive('error')->once()->with('Email registration failed.', ['exception' => RuntimeException::class]);
        $this->get($this->verificationLink())->assertStatus(503)->assertDontSee($token)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringNotContainsString($token, json_encode(session()->all()));
    }

    public function test_authenticated_accounts_cannot_register_and_existing_login_behavior_is_preserved(): void
    {
        $user = User::factory()->create(['role' => 'project_director', 'approval_status' => 'approved']);
        $user->refresh();
        $before = $user->getRawOriginal();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false))->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->get(route('register'))->assertRedirect(route('dashboard'));
        $this->post(route('registration.request'), ['email' => self::EMAIL])->assertRedirect(route('dashboard'));
        $this->post(route('registration.complete.store'), $this->payload())->assertRedirect(route('dashboard'));
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('registration_requests', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_both_post_endpoints_keep_standard_csrf_protection(): void
    {
        $this->verifiedSession();
        $this->app['env'] = 'production';
        try {
            $this->post(route('registration.request'), ['email' => self::EMAIL])->assertStatus(419);
            $this->post(route('registration.complete.store'), $this->payload())->assertStatus(419);
            $this->assertDatabaseCount('users', 0);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    #[DataProvider('invalidEmails')]
    public function test_invalid_email_inputs_are_rejected_before_storage_or_mail(mixed $email): void
    {
        $this->post(route('registration.request'), ['email' => $email])->assertSessionHasErrors('email');
        $this->get(route('register'))->assertOk();
        $this->assertDatabaseCount('registration_requests', 0);
        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingOutgoing();
    }

    public static function invalidSessions(): array
    {
        return array_map(fn ($kind) => [$kind], ['wrong token', 'hash instead of token', 'wrong id', 'string id', 'array token', 'missing token']);
    }

    public static function invalidCompletion(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'array name' => [['name' => ['invalid']], 'name'],
            'long name' => [['name' => str_repeat('n', 256)], 'name'],
            'missing password' => [['password' => ''], 'password'],
            'array password' => [['password' => ['invalid']], 'password'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'mismatched confirmation' => [['password_confirmation' => 'different'], 'password'],
            'missing confirmation' => [['password_confirmation' => ''], 'password'],
        ];
    }

    public static function unsafeMailers(): array
    {
        return ['log' => ['log'], 'failover with log' => ['failover']];
    }

    public static function invalidEmails(): array
    {
        return ['missing' => [''], 'invalid' => ['not-an-email'], 'array' => [['invalid']], 'too long' => [str_repeat('e', 256).'@example.test']];
    }

    private function requestLink(): void
    {
        $this->post(route('registration.request'), ['email' => self::EMAIL])
            ->assertRedirect(route('register'))->assertSessionHasNoErrors();
    }

    private function verificationLink(): string
    {
        $job = Queue::pushed(SendRegistrationVerification::class)->last();

        return rtrim((string) config('app.url'), '/').route('registration.verify', [
            'token' => Crypt::decryptString($job->encryptedToken),
        ], absolute: false);
    }

    private function linkToken(): string
    {
        return basename(parse_url($this->verificationLink(), PHP_URL_PATH));
    }

    private function verifiedSession(): array
    {
        $this->requestLink();
        $this->get($this->verificationLink())->assertRedirect(route('registration.complete'));

        return session(EmailRegistrationService::SESSION_KEY);
    }

    private function payload(string $password = self::PASSWORD): array
    {
        return ['name' => 'New Caseworker', 'password' => $password, 'password_confirmation' => $password];
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
