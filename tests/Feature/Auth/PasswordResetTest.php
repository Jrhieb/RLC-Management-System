<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_link_response_does_not_reveal_whether_an_account_exists(): void
    {
        $user = User::factory()->create();

        $known = $this->postJson(route('password.email'), ['email' => $user->email]);
        $unknown = $this->postJson(route('password.email'), ['email' => 'unknown@example.invalid']);
        $throttled = $this->postJson(route('password.email'), ['email' => $user->email]);

        $known->assertOk();
        $unknown->assertOk()->assertExactJson($known->json());
        $throttled->assertOk()->assertExactJson($known->json());
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_reset_email_contains_an_https_link_to_the_application(): void
    {
        $user = User::factory()->create();
        $this->post('https://staging.example.test/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            $this->assertSame('https', parse_url($mail->actionUrl, PHP_URL_SCHEME));
            $this->assertSame('staging.example.test', parse_url($mail->actionUrl, PHP_URL_HOST));
            $this->assertStringContainsString('href=', $mail->render());

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', 'Your password has been reset successfully. You can now log in using your new password.')
                ->assertRedirect(route('login', absolute: false));

            $this->assertGuest();
            $this->get(route('login'))->assertOk()
                ->assertSee('Your password has been reset successfully. You can now log in using your new password.')
                ->assertSee('Log in')->assertDontSee('name="password"', false);
            $this->get(route('login'))->assertOk()->assertSee('name="password"', false);

            return true;
        });
    }

    public function test_invalid_and_expired_tokens_cannot_change_a_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'password' => 'new-password', 'password_confirmation' => 'new-password'];

        $this->post(route('password.update'), $payload + ['token' => 'invalid-token'])->assertSessionHasErrors();
        $this->travel(61)->minutes();
        $this->post(route('password.update'), $payload + ['token' => $token])->assertSessionHasErrors();
        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_a_reset_token_is_single_use(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'caseworker', 'approval_status' => 'pending', 'email_verified_at' => null])->save();
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];

        $this->post(route('password.update'), $payload)->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertSame('caseworker', $user->fresh()->role);
        $this->assertSame('pending', $user->fresh()->approval_status);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($user->fresh()->canAccessStaff());
        $this->post(route('password.update'), $payload)->assertSessionHasErrors();
    }
}
