<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountApproval;
use App\Http\Middleware\EnsureStaffAccess;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkeys;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountApprovalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[DataProvider('restrictedAccounts')]
    public function test_pending_and_rejected_caseworkers_authenticate_and_go_directly_to_their_status_screen(string $status, bool $verified): void
    {
        $user = $this->caseworker($status, $verified);
        $this->withSession(['url.intended' => route('beneficiaries.index')]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('account.status'))->assertSessionHasNoErrors()->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user);
        $page = $this->get(route('account.status'))->assertOk()->assertSee($user->email)->assertSee(ucfirst($status))
            ->assertSee(route('logout'))->assertDontSee(route('beneficiaries.index'))->assertDontSee(route('account-requests.index'));
        if ($status === 'rejected') {
            $page->assertSee('Rejection reason')->assertSee('Review explanation.');
        } else {
            $page->assertSee('Your account is awaiting Director approval.');
        }
        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    #[DataProvider('restrictedAccounts')]
    public function test_restricted_caseworkers_cannot_read_staff_pages_photos_settings_or_json_or_mutate_records(string $status, bool $verified): void
    {
        Storage::fake('local');
        $user = $this->caseworker($status, $verified);
        $record = $this->record(['photo_path' => 'beneficiary-photos/protected.jpg']);
        Storage::disk('local')->put($record->photo_path, 'private fixture photo');
        $before = DB::table('beneficiaries')->get()->toArray();
        $this->actingAs($user);
        $pages = [
            route('dashboard'), route('beneficiaries.index'), route('beneficiaries.create'), route('beneficiaries.show', $record),
            route('beneficiaries.edit', $record), route('beneficiaries.archived'), route('beneficiaries.photo', $record),
            route('beneficiaries.archived-photo', $record), route('reports.index'), route('profile.edit'), route('appearance.edit'),
        ];
        foreach ($pages as $url) {
            $this->get($url)->assertRedirect(route('account.status'))->assertDontSee('private fixture photo');
            $this->getJson($url)->assertForbidden()->assertDontSee($record->full_name)->assertDontSee('private fixture photo');
        }
        foreach ([route('beneficiaries.suggestions', ['q' => 'Private']), route('dashboard.notifications')] as $url) {
            $this->getJson($url)->assertForbidden()->assertDontSee($record->full_name);
        }
        $this->postJson(route('beneficiaries.store'), [])->assertForbidden();
        $this->putJson(route('beneficiaries.update', $record), ['full_name' => 'Attempted change'])->assertForbidden();
        $this->deleteJson(route('beneficiaries.destroy', $record))->assertForbidden();
        $this->postJson(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => null])->assertForbidden();
        $this->assertEquals($before, DB::table('beneficiaries')->get()->toArray());
        $this->assertAuthenticatedAs($user);
    }

    public function test_status_screen_is_own_account_only_and_escapes_rejection_text(): void
    {
        $user = $this->caseworker('rejected');
        $other = User::factory()->pendingApproval()->create(['email' => 'another-applicant@example.test']);
        $user->rejection_reason = '<script>alert("rejected")</script>';
        $user->save();
        $this->actingAs($user)->get(route('account.status', ['user' => $other->id]))->assertOk()
            ->assertSee($user->email)->assertDontSee($other->email)->assertSee($user->rejection_reason)
            ->assertDontSee('<script>alert("rejected")</script>', false);
        $this->get(route('verification.notice'))->assertRedirect(route('account.status'));
    }

    public function test_guest_status_requires_login_and_other_roles_are_denied_staff_access(): void
    {
        $this->get(route('account.status'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('account.status'))->assertForbidden();
        $this->get(route('dashboard'))->assertForbidden();
        $this->getJson(route('beneficiaries.suggestions'))->assertForbidden();
    }

    public function test_approved_legacy_caseworkers_and_verified_directors_keep_existing_access_and_intended_login_redirects(): void
    {
        foreach (['caseworker', 'project_director'] as $role) {
            $this->flushSession();
            $user = User::factory()->create(['role' => $role, 'approval_status' => $role === 'project_director' ? 'pending' : 'approved']);
            $this->withSession(['url.intended' => route('beneficiaries.index')]);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect(route('beneficiaries.index'))->assertSessionHasNoErrors();
            $this->get(route('beneficiaries.index'))->assertOk();
            $this->get(route('dashboard'))->assertOk();
            $this->get(route('account.status'))->assertRedirect(route('dashboard'));
            $this->post(route('logout'));
        }
    }

    public function test_unverified_approved_caseworkers_and_directors_still_need_email_verification_for_staff_access(): void
    {
        foreach (['caseworker', 'project_director'] as $role) {
            $user = User::factory()->unverified()->create(['role' => $role]);
            $this->actingAs($user);
            $this->get(route('beneficiaries.index'))->assertRedirect(route('verification.notice'));
            $this->getJson(route('beneficiaries.suggestions'))->assertForbidden();
            $this->get(route('account.status'))->assertRedirect(route('verification.notice'));
            $this->get(route('verification.notice'))->assertOk();
        }
    }

    public function test_new_approval_releases_the_status_screen_and_staff_access_without_assigning_anyone(): void
    {
        $user = $this->caseworker('pending');
        $director = User::factory()->create(['role' => 'project_director']);
        $record = $this->record();
        $this->actingAs($user)->get(route('beneficiaries.index'))->assertRedirect(route('account.status'));
        $this->actingAs($director)->post(route('account-requests.review', $user), ['decision' => 'approve', 'confirmed' => '1'])
            ->assertRedirect(route('account-requests.index'));
        $this->actingAs($user->fresh());
        $this->get(route('account.status'))->assertRedirect(route('dashboard'));
        $this->get(route('beneficiaries.index'))->assertOk();
        $this->assertNull($record->fresh()->caseworker_id);
    }

    public function test_pending_users_can_confirm_passwords_manage_security_and_passkeys_without_staff_navigation(): void
    {
        $user = $this->caseworker('pending');
        $this->actingAs($user);
        $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
        $this->get(route('password.confirm'))->assertOk();
        $this->postJson(route('password.confirm.store'), ['password' => 'password'])->assertSuccessful();
        $this->getJson(route('password.confirmation'))->assertOk()->assertJsonPath('confirmed', true);
        $this->get(route('security.edit'))->assertOk()->assertSee('Update password')->assertSee(route('account.status'))
            ->assertDontSee(route('beneficiaries.index'))->assertDontSee(route('profile.edit'))->assertDontSee('Account Requests');
        $this->getJson(route('passkey.registration-options'))->assertOk()->assertJsonStructure(['options']);
        $this->getJson(route('passkey.confirm-options'))->assertOk()->assertJsonStructure(['options']);
        $this->postJson(route('two-factor.enable'))->assertSuccessful();
        $this->getJson(route('two-factor.qr-code'))->assertOk();
        $this->deleteJson(route('two-factor.disable'))->assertSuccessful();
        $this->assertSame('pending', $user->fresh()->approval_status);
        Mail::assertNothingOutgoing();
    }

    public function test_pending_user_email_verification_returns_to_status_and_cannot_grant_staff_access(): void
    {
        $user = $this->caseworker('pending', false);
        $this->actingAs($user);
        $this->get(route('verification.notice'))->assertOk();
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($link)->assertRedirect(route('account.status'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get(route('verification.notice'))->assertRedirect(route('account.status'));
        $this->get(route('beneficiaries.index'))->assertRedirect(route('account.status'));
    }

    public function test_pending_user_two_factor_challenge_and_recovery_login_still_work_and_return_to_status(): void
    {
        $user = User::factory()->pendingApproval()->withTwoFactor()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->get(route('two-factor.login'))->assertOk();
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'invalid'])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])->assertRedirect(route('account.status'));
        $this->assertAuthenticatedAs($user);
        $this->getJson(route('beneficiaries.suggestions'))->assertForbidden();
    }

    public function test_pending_json_login_keeps_fortify_response_and_cannot_read_staff_json(): void
    {
        $user = $this->caseworker('pending');
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('two_factor', false);
        $this->assertAuthenticatedAs($user);
        $this->getJson(route('beneficiaries.suggestions'))->assertForbidden();
    }

    public function test_passkey_login_uses_its_existing_protocol_and_returns_the_pending_account_status_url(): void
    {
        $user = $this->caseworker('pending');
        $this->getJson(route('passkey.login-options'))->assertOk()->assertJsonStructure(['options']);
        $passkeyClass = Passkeys::passkeyModel();
        $passkey = new $passkeyClass;
        $passkey->setRelation('user', $user);
        $this->mock(VerifyPasskey::class, fn ($mock) => $mock->shouldReceive('__invoke')->once()->andReturn($passkey));
        $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $credentialId = $encode('credential-fixture');
        $credential = [
            'id' => $credentialId, 'rawId' => $credentialId, 'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $encode(json_encode(['type' => 'webauthn.get', 'challenge' => $encode('fixture-challenge'), 'origin' => config('app.url')])),
                'authenticatorData' => $encode(str_repeat("\0", 37)), 'signature' => $encode('fixture-signature'), 'userHandle' => null,
            ],
        ];
        $this->postJson(route('passkey.login'), ['credential' => $credential, 'remember' => false])
            ->assertOk()->assertJsonPath('redirect', route('account.status'));
        $this->assertAuthenticatedAs($user);
        $this->getJson(route('beneficiaries.suggestions'))->assertForbidden();
    }

    #[DataProvider('restrictedApprovalStates')]
    public function test_livewire_rechecks_original_profile_route_after_a_signed_snapshot_becomes_restricted(string $status): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $snapshot = $this->snapshot($this->get(route('profile.edit'))->assertOk()->getContent(), 'settings.profile');
        $before = $user->fresh()->name;
        User::query()->whereKey($user->id)->update(['approval_status' => $status]);
        $this->actingAs($user->fresh());
        $this->livewireUpdate($snapshot, ['name' => 'Disallowed profile edit'], 'updateProfileInformation')->assertForbidden();
        $this->assertSame($before, $user->fresh()->name);
    }

    public function test_pending_security_livewire_updates_remain_available_and_preserve_the_approval_state(): void
    {
        $user = $this->caseworker('pending');
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $snapshot = $this->snapshot($this->get(route('security.edit'))->assertOk()->getContent(), 'settings.security');
        $this->livewireUpdate($snapshot, ['current_password' => 'password', 'password' => 'Security-Password-123!',
            'password_confirmation' => 'Security-Password-123!'], 'updatePassword')->assertOk();
        $this->assertTrue(Hash::check('Security-Password-123!', $user->fresh()->password));
        $this->assertSame('pending', $user->fresh()->approval_status);
    }

    public function test_livewire_rechecks_email_verification_on_security_updates(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $snapshot = $this->snapshot($this->get(route('security.edit'))->assertOk()->getContent(), 'settings.security');
        User::query()->whereKey($user->id)->update(['email_verified_at' => null]);
        $this->actingAs($user->fresh());
        $this->livewireUpdate($snapshot, [], 'updatePassword')->assertForbidden();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_livewire_rechecks_expired_password_confirmation_on_security_updates(): void
    {
        $user = $this->caseworker('pending');
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $snapshot = $this->snapshot($this->get(route('security.edit'))->assertOk()->getContent(), 'settings.security');
        $this->withSession(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1]);
        $this->livewireUpdate($snapshot, ['current_password' => 'password', 'password' => 'Changed-Password-123!',
            'password_confirmation' => 'Changed-Password-123!'], 'updatePassword')->assertStatus(423);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_all_staff_routes_and_livewire_persistence_have_approval_and_verification_protection(): void
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->getName() === 'dashboard' || str_starts_with($route->uri(), 'beneficiaries')
                || str_starts_with($route->uri(), 'reports') || str_starts_with($route->uri(), 'activities')
                || in_array($route->uri(), ['attendance', 'nfc', 'nfc/lookup', 'dashboard/notifications', 'dashboard/beneficiary-suggestions'])) {
                $middleware = $route->gatherMiddleware();
                $this->assertContains('auth', $middleware);
                $this->assertContains('verified', $middleware);
                $this->assertContains(EnsureStaffAccess::class, $middleware);
            }
        }
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();
        $this->assertContains(EnsureAccountApproval::class, $persistent);
        $this->assertContains(EnsureStaffAccess::class, $persistent);
        $this->assertContains(EnsureEmailIsVerified::class, $persistent);
        $this->assertContains(RequirePasswordConfirmation::class, $persistent);
    }

    #[DataProvider('ineligibleDestinations')]
    public function test_both_assignment_paths_reject_unverified_or_unapproved_caseworkers(string $status, bool $verified): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $target = $this->caseworker($status, $verified);
        $record = $this->record();
        $this->actingAs($director);
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => $target->id])
            ->assertSessionHasErrors('caseworker_id', null, 'assignCaseworker');
        $this->post(route('beneficiaries.store'), ['beneficiary_id' => 'INELIGIBLE-CREATE', 'full_name' => 'Ineligible Assignment',
            'registered_at' => '2026-10-09', 'caseworker_id' => $target->id])->assertSessionHasErrors('caseworker_id');
        $this->assertNull($record->fresh()->caseworker_id);
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->get(route('beneficiaries.create'))->assertOk()->assertViewHas('caseworkers', fn ($accounts) => ! $accounts->contains('id', $target->id));
        $this->get(route('beneficiaries.index'))->assertOk()->assertViewHas('caseworkers', fn ($accounts) => ! $accounts->contains('id', $target->id));
    }

    public function test_both_assignment_paths_allow_only_verified_approved_caseworkers(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $target = User::factory()->create();
        $record = $this->record();
        $this->actingAs($director);
        $this->get(route('beneficiaries.create'))->assertOk()
            ->assertViewHas('caseworkers', fn ($accounts) => $accounts->pluck('id')->all() === [$target->id]);
        $this->get(route('beneficiaries.index'))->assertOk()
            ->assertViewHas('caseworkers', fn ($accounts) => $accounts->pluck('id')->all() === [$target->id]);
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => $target->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($target->id, $record->fresh()->caseworker_id);
        $this->post(route('beneficiaries.store'), ['beneficiary_id' => 'ELIGIBLE-CREATE', 'full_name' => 'Eligible Assignment',
            'registered_at' => '2026-10-09', 'caseworker_id' => $target->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($target->id, Beneficiary::where('beneficiary_id', 'ELIGIBLE-CREATE')->sole()->caseworker_id);
    }

    public static function restrictedAccounts(): array
    {
        return [['pending', true], ['pending', false], ['rejected', true], ['rejected', false]];
    }

    public static function restrictedApprovalStates(): array
    {
        return [['pending'], ['rejected']];
    }

    public static function ineligibleDestinations(): array
    {
        return [['pending', true], ['pending', false], ['rejected', true], ['rejected', false], ['approved', false]];
    }

    private function caseworker(string $status, bool $verified = true): User
    {
        return User::factory()->create(['approval_status' => $status, 'email_verified_at' => $verified ? now() : null,
            'rejection_reason' => $status === 'rejected' ? 'Review explanation.' : null]);
    }

    private function record(array $fields = []): Beneficiary
    {
        return Beneficiary::create($fields + ['beneficiary_id' => 'PRIVATE-RECORD', 'full_name' => 'Private Beneficiary',
            'registered_at' => '2026-10-09', 'status' => 'active']);
    }

    private function snapshot(string $html, string $component): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            if (json_decode($snapshot, true)['memo']['name'] === $component) {
                return $snapshot;
            }
        }
        $this->fail('The rendered page must contain the expected Livewire component.');
    }

    private function livewireUpdate(string $snapshot, array $updates, string $method): TestResponse
    {
        return $this->withHeader('X-Livewire', 'true')->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $method, 'params' => []]]]],
        ]);
    }
}
