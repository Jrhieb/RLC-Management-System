<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendRegistrationVerification;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Services\EmailRegistrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use RuntimeException;
use Tests\Support\TestIsolation;
use Tests\TestCase;

class RegistrationPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        Vite::useHotFile($this->app->storagePath('framework/testing/unused-registration.hot'));
        URL::forceRootUrl('http://rlcp-homepage.test');

        // Minimal synthetic schema in the existing guarded SQLite :memory:
        // connection. No migration commands or disk database access.
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('caseworker');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('approval_status')->default('pending');
            $table->unsignedBigInteger('approval_reviewed_by')->nullable();
            $table->timestamp('approval_reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('registration_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->char('session_token_hash', 64)->nullable()->unique();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        $this->beforeApplicationDestroyed(fn () => DB::purge('sqlite'));
    }

    public function test_home_and_direct_register_share_the_email_form_and_do_not_dispatch_on_get(): void
    {
        foreach (['home' => 'homepage', 'register' => 'register'] as $route => $fixture) {
            $response = $this->get(route($route))->assertOk()
                ->assertSee('Join the')->assertSee('RLCP Team')
                ->assertSee('data-registration-email', false)
                ->assertSee('name="_token"', false)
                ->assertSee('autocomplete="email"', false)
                ->assertSee(route('registration.request'), false)
                ->assertSee('Become a Caseworker');
            $this->assertSame(1, preg_match('/<form\b[^>]*data-registration-form[^>]*>.*?<\/form>/s', $response->getContent(), $registrationForm));
            $this->assertStringNotContainsString('name="password"', $registrationForm[0]);
            $this->assertSame(1, substr_count($response->getContent(), 'data-registration-form'));
            $this->exportFixture($fixture, $response->getContent());
        }
        $this->get(route('home'))->assertSee('href="'.route('register').'" data-registration-open', false);
        $this->get(route('register'))->assertHeader('Referrer-Policy', 'no-referrer');
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_invalid_email_preserves_validation_and_dispatches_nothing(): void
    {
        $this->postJson(route('registration.request'), ['email' => 'not-an-email'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public function test_accepted_response_preserves_generic_queue_confirmation_privacy_and_rate_limits(): void
    {
        User::create(['name' => 'Existing test account', 'email' => 'existing@example.test', 'password' => 'Only-Test-Password-123!']);
        $new = $this->postJson(route('registration.request'), ['email' => 'new@example.test'])->assertStatus(202);
        $new->assertExactJson(['message' => 'If this email is available, a verification email has been queued. Check your inbox shortly.']);
        $this->postJson(route('registration.request'), ['email' => 'existing@example.test'])
            ->assertStatus(202)->assertExactJson($new->json());
        $this->postJson(route('registration.request'), ['email' => 'new@example.test'])->assertTooManyRequests();
        Queue::assertPushed(SendRegistrationVerification::class, 1);
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_dispatch_failure_is_generic_and_does_not_claim_queued_or_delivered(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Private synthetic failure details'));
        $this->postJson(route('registration.request'), ['email' => 'dispatch@example.test'])
            ->assertServiceUnavailable()->assertSee('Registration is temporarily unavailable.')
            ->assertDontSee('Private synthetic failure details')->assertDontSee('has been queued');
        $this->assertNull(RegistrationRequest::sole()->token_hash);
        Mail::assertNothingOutgoing();
    }

    public function test_native_email_post_still_redirects_to_direct_registration_with_feedback(): void
    {
        $this->post(route('registration.request'), ['email' => 'native@example.test'])
            ->assertRedirect(route('register'))->assertSessionHas('status');
        $this->get(route('register'))->assertOk()->assertSee('Resend verification code')
            ->assertSee('If this email is available, a verification email has been queued.');
        Queue::assertPushed(SendRegistrationVerification::class, 1);
        Mail::assertNothingOutgoing();
    }

    public function test_verification_link_works_with_a_fresh_session_and_completion_stays_pending(): void
    {
        $token = $this->requestToken();
        $this->flushSession(); // Simulates opening the emailed link on another device.
        $this->get(route('registration.verify', ['token' => $token]))->assertRedirect(route('registration.complete'));
        $sessionToken = session(EmailRegistrationService::SESSION_KEY)['token'];
        $complete = $this->get(route('registration.complete'))->assertOk()->assertSee('Complete Registration')
            ->assertSee('aria-current="step"', false)->assertSee('caseworker@example.test')
            ->assertDontSee($token)->assertDontSee($sessionToken);
        $this->assertSame(2, substr_count($complete->getContent(), 'autocomplete="new-password"'));
        $this->exportFixture('complete', $complete->getContent());
        $this->post(route('registration.complete.store'), [
            'name' => 'Synthetic Caseworker', 'password' => 'Only-Test-Password-123!',
            'password_confirmation' => 'Only-Test-Password-123!', 'role' => 'project_director',
            'approval_status' => 'approved',
        ])->assertRedirect(route('registration.awaiting-approval'))->assertSessionMissing(EmailRegistrationService::SESSION_KEY);
        $user = User::sole();
        $this->assertSame('caseworker', $user->role);
        $this->assertSame('pending', $user->approval_status);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('Only-Test-Password-123!', $user->password));
        $this->assertGuest();
        $awaiting = $this->get(route('registration.awaiting-approval'))->assertOk()
            ->assertSee('Your account is awaiting Director approval.')->assertSee('You have not been logged in.');
        $this->exportFixture('awaiting', $awaiting->getContent());
        $this->flushSession();
        $this->get(route('registration.verify', ['token' => $token]))->assertRedirect(route('register'));
        Mail::assertNothingOutgoing();
    }

    public function test_invalid_and_expired_links_keep_existing_validation_and_redirects(): void
    {
        $this->get(route('registration.verify', ['token' => 'not-valid']))->assertRedirect(route('register'))
            ->assertSessionHasErrors('registration');
        $token = $this->requestToken();
        $this->travel(30)->minutes();
        $this->get(route('registration.verify', ['token' => $token]))->assertRedirect(route('register'))
            ->assertSessionHasErrors('registration');
        $this->get(route('registration.complete'))->assertRedirect(route('register'));
        $this->get(route('registration.awaiting-approval'))->assertRedirect(route('register'));
        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_failed_completion_does_not_flash_passwords_and_renders_empty_fields(): void
    {
        $token = $this->requestToken();
        $this->get(route('registration.verify', ['token' => $token]));
        $this->from(route('registration.complete'))->post(route('registration.complete.store'), [
            'name' => 'Synthetic Caseworker', 'password' => 'short', 'password_confirmation' => 'different',
        ])->assertRedirect(route('registration.complete'))->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $response = $this->withCookie(config('session.cookie'), $this->app['session']->getId())
            ->get(route('registration.complete'))->assertOk()->assertSee('Synthetic Caseworker')
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('value="short"', false)->assertDontSee('value="different"', false);
        $this->exportFixture('complete-invalid', $response->getContent());
        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingOutgoing();
    }

    private function requestToken(): string
    {
        $this->postJson(route('registration.request'), ['email' => 'caseworker@example.test'])->assertStatus(202);

        return Crypt::decryptString(Queue::pushed(SendRegistrationVerification::class)->sole()->encryptedToken);
    }

    private function exportFixture(string $name, string $html): void
    {
        $directory = getenv('REGISTRATION_UI_FIXTURES');
        if (is_string($directory) && $directory !== '') {
            TestIsolation::assertTemporaryPaths([$directory], sys_get_temp_dir());
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }
}
