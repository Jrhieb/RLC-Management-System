<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PhoneVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private string $code = '';

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.brevo.sms_enabled' => true, 'services.brevo.sms_key' => 'synthetic-key',
            'services.brevo.sms_sender' => 'RLCSystem', 'services.brevo.sms_daily_limit' => 20,
            'services.brevo.sms_country_codes' => ['63']]);
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/v3/transactionalSMS/send' => function ($request) {
            preg_match('/\b([0-9]{6})\b/', $request['content'], $match);
            $this->code = $match[1] ?? '';

            return Http::response(['messageId' => 123456], 201);
        }]);
        $this->account = User::factory()->create(['role' => 'caseworker', 'approval_status' => 'pending']);
        $this->actingAs($this->account);
    }

    private function savePhone(string $phone = '0917 123 4567'): void
    {
        $this->post(route('account.phone.save'), ['phone_number' => $phone])->assertSessionHasNoErrors();
    }

    private function sendCode(): void
    {
        $this->post(route('account.phone.send'))->assertSessionHasNoErrors()->assertSessionHas('status');
    }

    public function test_sms_is_disabled_by_default_and_no_mutations_or_requests_are_allowed(): void
    {
        config(['services.brevo.sms_enabled' => false]);
        foreach (['save', 'send', 'verify'] as $action) {
            $this->post(route('account.phone.'.$action), ['phone_number' => '09171234567', 'phone_code' => '123456'])->assertNotFound();
        }
        $this->assertNull($this->account->fresh()->phone_number);
        Http::assertNothingSent();
    }

    #[DataProvider('numbers')]
    public function test_numbers_are_normalized(string $input, string $expected): void
    {
        $this->savePhone($input);
        $this->assertSame($expected, $this->account->fresh()->phone_number);
        Http::assertNothingSent();
    }

    public static function numbers(): array
    {
        return [['0917 123 4567', '+639171234567'], ['+63 (917) 123-4567', '+639171234567'],
            ['00639171234567', '+639171234567'], ['+1 202 555 0123', '+12025550123']];
    }

    public function test_invalid_numbers_are_rejected_and_unapproved_countries_cannot_send(): void
    {
        foreach (['123', '09171234567 extension 5', '+6309171234567', '+63212345678', '+99999999999999999'] as $input) {
            $this->post(route('account.phone.save'), ['phone_number' => $input])->assertSessionHasErrors('phone_number');
        }
        $this->savePhone('+12025550123');
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        Http::assertNothingSent();
    }

    public function test_code_is_hashed_bound_to_user_and_verifies_only_phone(): void
    {
        $this->savePhone();
        $this->sendCode();
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $this->code);
        $user = $this->account->fresh();
        $this->assertNotNull($user->phone_verification_hash);
        $this->assertStringNotContainsString($this->code, $user->phone_verification_hash);
        $this->assertArrayNotHasKey('phone_verification_hash', $user->toArray());
        Http::assertSent(fn ($r) => $r->url() === 'https://api.brevo.com/v3/transactionalSMS/send'
            && $r['recipient'] === '+639171234567' && $r['type'] === 'transactional'
            && $r['sender'] === 'RLCSystem' && strlen($r['content']) <= 160);
        $other = User::factory()->create(['role' => 'caseworker']);
        $this->actingAs($other)->post(route('account.phone.verify'), ['phone_code' => $this->code])->assertSessionHasErrors('phone_code');
        $verifiedEmail = $user->email_verified_at;
        $this->actingAs($this->account)->post(route('account.phone.verify'), ['phone_code' => $this->code])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNull($user->phone_verification_hash);
        $this->assertEquals($verifiedEmail, $user->email_verified_at);
        $this->assertSame('pending', $user->approval_status);
        $this->get(route('dashboard'))->assertRedirect(route('account.status'));
        $this->post(route('account.phone.verify'), ['phone_code' => $this->code])->assertSessionHasErrors('phone_code');
    }

    public function test_expired_code_is_rejected_at_exact_deadline(): void
    {
        $this->savePhone();
        $this->sendCode();
        $this->travelTo($this->account->fresh()->phone_verification_expires_at);
        $this->post(route('account.phone.verify'), ['phone_code' => $this->code])->assertSessionHasErrors('phone_code');
        $this->assertNull($this->account->fresh()->phone_verified_at);
    }

    public function test_five_wrong_attempts_invalidate_code_and_otp_is_not_flashed(): void
    {
        $this->savePhone();
        $this->sendCode();
        $wrong = $this->code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('account.phone.verify'), ['phone_code' => $wrong])->assertSessionHasErrors('phone_code');
        }
        $this->post(route('account.phone.verify'), ['phone_code' => $this->code])->assertSessionHasErrors('phone_code');
        $this->assertNull($this->account->fresh()->phone_verification_hash);
        $this->post(route('account.phone.verify'), ['phone_code' => 'invalid'])->assertSessionMissing('_old_input.phone_code');
    }

    public function test_resending_invalidates_old_code_and_phone_changes_clear_all_verification(): void
    {
        $this->savePhone();
        $this->sendCode();
        $old = $this->code;
        $this->travel(61)->seconds();
        $this->sendCode();
        $this->assertNotSame($old, $this->code);
        $this->post(route('account.phone.verify'), ['phone_code' => $old])->assertSessionHasErrors('phone_code');
        $this->savePhone('09181234567');
        $this->post(route('account.phone.verify'), ['phone_code' => $this->code])->assertSessionHasErrors('phone_code');
        $this->assertNull($this->account->fresh()->phone_verified_at);
        $this->travel(61)->seconds();
        $this->sendCode();
        $this->post(route('account.phone.verify'), ['phone_code' => $this->code])->assertSessionHasNoErrors();
        $this->savePhone('09191234567');
        $this->assertNull($this->account->fresh()->phone_verified_at);
        $this->assertNull($this->account->fresh()->phone_verification_hash);
    }

    public function test_cooldown_and_user_budget_survive_number_changes(): void
    {
        $this->savePhone();
        $this->sendCode();
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        for ($i = 0; $i < 2; $i++) {
            $this->travel(61)->seconds();
            $this->sendCode();
        }
        $this->savePhone('09181234567');
        $this->travel(61)->seconds();
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        Http::assertSentCount(3);
    }

    public function test_global_budget_counts_failed_provider_attempts(): void
    {
        config(['services.brevo.sms_daily_limit' => 1]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'sensitive-provider-content'], 402)]);
        $this->savePhone();
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number')->assertDontSee('sensitive-provider-content');
        $this->assertNull($this->account->fresh()->phone_verification_hash);
        $this->travel(61)->seconds();
        $other = User::factory()->create(['role' => 'caseworker']);
        $this->actingAs($other);
        $this->savePhone('09181234567');
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        Http::assertSentCount(1);
    }

    public function test_phone_changed_during_provider_call_cannot_activate_the_old_challenge(): void
    {
        $this->savePhone();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => function () {
            app(PhoneVerificationService::class)->savePhone($this->account, '+639181234567');

            return Http::response(['messageId' => 123], 201);
        }]);
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        $this->assertNull($this->account->fresh()->phone_verification_hash);
        $this->assertNull($this->account->fresh()->phone_verified_at);
    }

    public function test_email_verification_and_authentication_are_required(): void
    {
        $this->account->forceFill(['email_verified_at' => null])->save();
        $this->post(route('account.phone.send'))->assertRedirect(route('verification.notice'));
        $this->app['auth']->forgetGuards();
        $this->post(route('account.phone.send'))->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_zero_budget_prevents_any_sms_even_if_feature_is_enabled(): void
    {
        config(['services.brevo.sms_daily_limit' => 0]);
        $this->savePhone();
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        Http::assertNothingSent();
    }

    public function test_shared_phone_and_daily_user_limits_bound_sends(): void
    {
        $this->savePhone();
        $this->sendCode();
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs(User::factory()->create(['role' => 'caseworker']));
            $this->savePhone();
            $this->sendCode();
        }
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $this->savePhone();
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        Http::assertSentCount(3);

        $this->actingAs($this->account);
        $this->savePhone('09181234567');
        for ($i = 0; $i < 4; $i++) {
            $this->travel(3601)->seconds();
            $this->sendCode();
        }
        $this->travel(3601)->seconds();
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        Http::assertSentCount(7);
    }

    public function test_ip_limit_blocks_new_accounts_and_budget_lock_contention_sends_nothing(): void
    {
        RateLimiter::hit('sms-ip-hour:'.hash('sha256', '127.0.0.1'), 3600);
        for ($i = 1; $i < 10; $i++) {
            RateLimiter::hit('sms-ip-hour:'.hash('sha256', '127.0.0.1'), 3600);
        }
        $this->savePhone();
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        RateLimiter::clear('sms-ip-hour:'.hash('sha256', '127.0.0.1'));
        $lock = Cache::lock('phone-verification-budget', 10);
        $lock->get();
        try {
            $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number');
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_pending_account_can_render_optional_phone_page_without_staff_access(): void
    {
        $this->savePhone();
        $this->get(route('account.phone.show'))->assertOk()->assertSee('Send or resend code')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('dashboard'))->assertRedirect(route('account.status'));
    }

    #[DataProvider('providerFailures')]
    public function test_sms_rejections_do_not_leave_a_verifiable_code(int $status, array $body): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response($body, $status)]);
        $this->savePhone();
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number')->assertSessionMissing('status');
        $this->assertNull($this->account->fresh()->phone_verification_hash);
        $this->assertNull($this->account->fresh()->phone_verified_at);
        Http::assertSentCount(1);
    }

    public static function providerFailures(): array
    {
        return [[401, []], [400, []], [429, []], [500, []], [201, []], [201, ['messageId' => 0]]];
    }

    public function test_sms_connection_failure_is_sanitized_and_not_retried(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::failedConnection('synthetic-secret-otp')]);
        $this->savePhone();
        $this->post(route('account.phone.send'))->assertSessionHasErrors('phone_number')->assertDontSee('synthetic-secret-otp');
        $this->assertNull($this->account->fresh()->phone_verification_hash);
        $this->assertNull($this->account->fresh()->phone_verified_at);
    }
}
