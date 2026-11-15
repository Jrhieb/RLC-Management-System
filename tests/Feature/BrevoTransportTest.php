<?php

namespace Tests\Feature;

use App\Mail\BrevoTransport;
use App\Models\RegistrationRequest;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class BrevoTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_email_contains_a_working_https_button(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test-message'], 201)]);
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'test-only-key']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        URL::forceRootUrl('https://staging.example.test');
        URL::forceScheme('https');

        $user = User::factory()->create();
        $token = Password::createToken($user);
        $channel = new MailChannel(app('mail.manager'), app(Markdown::class));
        $channel->send($user, new ResetPassword($token));

        $requests = Http::recorded();
        $this->assertCount(1, $requests);
        $html = $requests->first()[0]['htmlContent'];
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $buttons = (new \DOMXPath($document))->query('//a[contains(normalize-space(.), "Reset Password")]');
        $this->assertSame(1, $buttons->length);
        $link = $buttons->item(0)->getAttribute('href');
        $this->assertTrue(str_starts_with($link, 'https://staging.example.test/reset-password/'));
        $this->get($link)->assertOk()->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false);
        $this->post(route('password.update'), [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login', absolute: false));
    }

    public function test_sync_registration_sends_existing_code_and_requires_verification(): void
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test-message'], 201)]);
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'test-only-key']);
        Mail::swap(Mail::getFacadeRoot()->manager);

        $this->post(route('registration.request'), ['email' => 'owned-test@example.test'])
            ->assertRedirect(route('register'))->assertSessionHas('status');
        Http::assertSentCount(1);
        $this->assertNull(RegistrationRequest::sole()->verified_at);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('jobs', 0);
        $code = substr(Crypt::decryptString(session('registration_code_challenge')), 0, 6);
        Http::assertSent(fn ($request) => str_contains($request['htmlContent'], $code));
        $this->post(route('registration.code.verify'), ['code' => $code])
            ->assertRedirect(route('registration.complete'));
        $this->assertNotNull(RegistrationRequest::sole()->fresh()->verified_at);
        $this->post(route('registration.code.verify'), ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_registration_uses_sync_connection_and_provider_rejection_is_not_success(): void
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'private-content'], 401)]);
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'test-only-key']);
        Mail::swap(Mail::getFacadeRoot()->manager);

        $this->post(route('registration.request'), ['email' => 'owned-test@example.test'])
            ->assertStatus(503)->assertSessionMissing('status')->assertDontSee('private-content');
        Http::assertSentCount(1);
        $this->assertNull(RegistrationRequest::sole()->token_hash);
        $this->assertNull(RegistrationRequest::sole()->verified_at);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_laravel_mailer_sends_over_https(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'test-message'], 201)]);
        config(['mail.mailers.brevo' => ['transport' => 'brevo', 'key' => 'test-only-key'],
            'mail.from' => ['address' => 'sender@example.test', 'name' => 'RLC']]);
        Mail::getFacadeRoot()->manager->mailer('brevo')->raw('Verification content', function ($mail): void {
            $mail->to('recipient@example.test')->subject('Verify registration');
        });
        Http::assertSent(fn ($request) => $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->hasHeader('api-key', 'test-only-key')
            && $request['sender']['email'] === 'sender@example.test'
            && $request['to'][0]['email'] === 'recipient@example.test'
            && trim($request['textContent']) === 'Verification content');
        Http::assertSentCount(1);
    }

    #[DataProvider('failedResponses')]
    public function test_failure_is_sanitized(int $status, array $body): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response($body, $status)]);
        try {
            (new BrevoTransport('test-only-key'))->send($this->email());
            $this->fail('Rejected delivery must fail.');
        } catch (TransportException $exception) {
            $this->assertSame('Brevo email delivery was not accepted.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('private-content', (string) $exception);
        }
        Http::assertSentCount(1);
    }

    public static function failedResponses(): array
    {
        return ['unauthorized' => [401, ['message' => 'private-content']],
            'sender rejected' => [400, []], 'limited' => [429, []], 'server failure' => [500, []],
            'missing id' => [201, []], 'empty id' => [201, ['messageId' => '']]];
    }

    public function test_connection_failure_is_sanitized(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::failedConnection('private-content')]);
        try {
            (new BrevoTransport('test-only-key'))->send($this->email());
            $this->fail('Connection failure must fail.');
        } catch (TransportException $exception) {
            $this->assertSame('Brevo email delivery was not accepted.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_missing_key_fails_without_network_request(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        try {
            (new BrevoTransport(''))->send($this->email());
            $this->fail('Missing credentials must fail.');
        } catch (TransportException $exception) {
            $this->assertNull($exception->getPrevious());
        }
        Http::assertNothingSent();
    }

    public function test_provider_diagnostic_contains_only_fixed_category_and_http_status(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response(['code' => 'private-content', 'message' => 'private-content'], 401)]);
        Log::spy();
        try {
            (new BrevoTransport('test-only-key'))->send($this->email());
        } catch (TransportException) {
        }
        Log::shouldHaveReceived('warning')->once()->with('Brevo email transport failure.', [
            'category' => 'provider_rejected', 'http_status' => 401,
        ]);
    }

    public function test_missing_key_diagnostic_never_exposes_values(): void
    {
        Http::preventStrayRequests();
        Log::spy();
        try {
            (new BrevoTransport(''))->send($this->email());
        } catch (TransportException) {
        }
        Log::shouldHaveReceived('warning')->once()->with('Brevo email transport failure.', [
            'category' => 'missing_key', 'http_status' => null,
        ]);
    }

    private function email(): Email
    {
        return (new Email)->from('sender@example.test')->to('recipient@example.test')
            ->subject('Verify')->html('<p>private-content</p>')->text('private-content');
    }
}
