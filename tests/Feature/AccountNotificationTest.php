<?php

namespace Tests\Feature;

use App\Actions\ReviewCaseworkerAccount;
use App\Jobs\SendRegistrationVerification;
use App\Mail\AccountDecisionMail;
use App\Models\AccountNotification;
use App\Models\Beneficiary;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Services\AccountNotificationService;
use App\Services\EmailRegistrationService;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AccountNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Use real commit boundaries in the guarded memory database. Do not run
        // historical migration down() methods during cleanup; discard the PDO.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->beforeApplicationDestroyed(fn () => DB::purge('sqlite'));
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_link_requests_verification_and_invalid_completion_do_not_notify_directors(): void
    {
        Queue::fake([SendRegistrationVerification::class]);
        User::factory()->create(['role' => 'project_director']);
        $service = app(EmailRegistrationService::class);
        $service->requestLink('new@example.test');
        $this->assertDatabaseCount('account_notifications', 0);
        $this->post(route('registration.complete.store'), ['name' => 'Bypass', 'password' => 'Password-123!'])
            ->assertRedirect(route('register'));
        $this->assertDatabaseCount('account_notifications', 0);
        $session = $this->registration('verified@example.test');
        $this->assertDatabaseCount('account_notifications', 0);
        RegistrationRequest::whereKey($session['id'])->update(['session_expires_at' => now()]);
        try {
            $service->complete($session, $this->registrationInput());
            $this->fail('Expired completion must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('account_notifications', 0);
        }
        Mail::assertNothingQueued();
    }

    public function test_completed_registration_notifies_only_verified_directors_once_and_remains_a_guest(): void
    {
        $directors = collect(['pending', 'approved', 'rejected'])->map(fn ($status) => User::factory()->create([
            'role' => 'project_director', 'approval_status' => $status,
        ]));
        User::factory()->unverified()->create(['role' => 'project_director']);
        User::factory()->create();
        User::factory()->create(['role' => 'admin']);
        $session = $this->registration();
        $this->withSession([EmailRegistrationService::SESSION_KEY => $session])->post(route('registration.complete.store'),
            $this->registrationInput() + ['password_confirmation' => $this->registrationInput()['password']])
            ->assertRedirect(route('registration.awaiting-approval'))->assertSessionHasNoErrors();
        $this->assertGuest();
        $account = User::where('email', 'new@example.test')->sole();
        $this->assertSame('pending', $account->approval_status);
        $this->assertTrue($account->hasVerifiedEmail());
        $this->assertEqualsCanonicalizing($directors->pluck('id')->all(), AccountNotification::pluck('recipient_id')->all());
        $this->assertSame([AccountNotification::REQUEST], AccountNotification::distinct()->pluck('kind')->all());
        $this->assertSame([$account->id], AccountNotification::distinct()->pluck('account_id')->all());
        try {
            app(EmailRegistrationService::class)->complete($session, $this->registrationInput());
            $this->fail('A completed registration cannot replay.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('account_notifications', 3);
        }
        $this->actingAs($directors->first())->getJson(route('account.notifications.index'))->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('unread_count', 1)
            ->assertJsonPath('items.0.url', route('account-requests.index', absolute: false));
        Mail::assertNothingQueued();
    }

    public function test_registration_and_all_director_notifications_rollback_if_storage_fails(): void
    {
        User::factory()->count(2)->create(['role' => 'project_director']);
        $session = $this->registration();
        $inserts = 0;
        AccountNotification::creating(function () use (&$inserts) {
            if (++$inserts === 2) {
                throw new RuntimeException('Simulated storage failure.');
            }
        });
        try {
            app(EmailRegistrationService::class)->complete($session, $this->registrationInput());
            $this->fail('Notification storage failure must roll back registration.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 2);
            $this->assertDatabaseCount('account_notifications', 0);
            $this->assertNull(RegistrationRequest::find($session['id'])->completed_at);
            $this->assertNotNull(app(EmailRegistrationService::class)->activeRequest($session));
        }
        Mail::assertNothingQueued();
    }

    public function test_outer_registration_rollback_leaves_no_notifications_and_retry_can_complete_once(): void
    {
        User::factory()->create(['role' => 'project_director']);
        $session = $this->registration();
        DB::beginTransaction();
        app(EmailRegistrationService::class)->complete($session, $this->registrationInput());
        $this->assertDatabaseCount('account_notifications', 1);
        DB::rollBack();
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertDatabaseCount('users', 1);
        app(EmailRegistrationService::class)->complete($session, $this->registrationInput());
        $this->assertDatabaseCount('account_notifications', 1);
        Mail::assertNothingQueued();
    }

    #[DataProvider('decisions')]
    public function test_decision_notification_is_transactional_and_mail_waits_for_outer_commit(string $decision, string $status, string $kind): void
    {
        $director = $this->director();
        $account = User::factory()->pendingApproval()->create();
        $insertLevel = null;
        DB::listen(function ($query) use (&$insertLevel) {
            if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'account_notifications')) {
                $insertLevel = DB::transactionLevel();
            }
        });
        DB::beginTransaction();
        $this->assertTrue($this->decide($director, $account, $decision));
        $notification = AccountNotification::sole();
        $this->assertSame($account->id, $notification->recipient_id);
        $this->assertSame($account->id, $notification->account_id);
        $this->assertSame($kind, $notification->kind);
        $this->assertGreaterThan(1, $insertLevel);
        $this->assertSame($status, $account->fresh()->approval_status);
        Mail::assertNothingOutgoing();
        DB::commit();
        $this->assertSame(0, DB::transactionLevel());
        Mail::assertQueued(AccountDecisionMail::class, fn ($mail) => $mail->hasTo($account->email) && $mail->decision === $status
            && $mail instanceof ShouldQueueAfterCommit);
        Mail::assertQueuedCount(1);
        Mail::assertNothingSent();
    }

    #[DataProvider('repeatedDecisions')]
    public function test_repeated_or_changed_decisions_do_not_duplicate_notifications_or_email(string $first, string $repeat): void
    {
        $director = $this->director();
        $account = User::factory()->pendingApproval()->create();
        $this->assertTrue($this->decide($director, $account, $first));
        $notification = AccountNotification::sole()->getRawOriginal();
        $before = $account->fresh()->getRawOriginal();
        $this->travel(5)->minutes();
        $this->assertFalse($this->decide($this->director(), $account, $repeat));
        $this->assertSame($before, $account->fresh()->getRawOriginal());
        $this->assertSame($notification, AccountNotification::sole()->getRawOriginal());
        $this->assertDatabaseCount('account_notifications', 1);
        Mail::assertQueuedCount(1);
    }

    public function test_interleaved_decisions_create_one_notification_and_one_mail(): void
    {
        $first = $this->director();
        $second = $this->director();
        $account = User::factory()->pendingApproval()->create();
        $contended = false;
        DB::listen(function ($query) use ($second, $account, &$contended) {
            if (! $contended && str_starts_with($query->sql, 'update') && str_contains($query->sql, 'approval_reviewed_by')) {
                $contended = true;
                $this->assertFalse($this->decide($second, $account, 'reject'));
            }
        });
        $this->assertTrue($this->decide($first, $account));
        $this->assertTrue($contended);
        $this->assertSame($first->id, $account->fresh()->approval_reviewed_by);
        $this->assertSame(AccountNotification::APPROVED, AccountNotification::sole()->kind);
        Mail::assertQueuedCount(1);
    }

    public function test_rolled_back_decision_discards_stored_notification_and_after_commit_email(): void
    {
        $director = $this->director();
        $account = User::factory()->pendingApproval()->create();
        DB::beginTransaction();
        $this->assertTrue($this->decide($director, $account));
        $this->assertDatabaseCount('account_notifications', 1);
        Mail::assertNothingOutgoing();
        DB::rollBack();
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame('pending', $account->fresh()->approval_status);
        $this->assertNull($account->fresh()->approval_reviewed_at);
        Mail::assertNothingOutgoing();
        $this->assertTrue($this->decide($director, $account, 'reject'));
        $this->assertDatabaseCount('account_notifications', 1);
        Mail::assertQueuedCount(1);
    }

    public function test_notification_insert_failure_rolls_back_the_review_and_never_queues_email(): void
    {
        $director = $this->director();
        $account = User::factory()->pendingApproval()->create();
        AccountNotification::creating(fn () => throw new RuntimeException('Simulated storage failure.'));
        try {
            $this->decide($director, $account);
            $this->fail('Notification failure must roll back review.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $account->fresh()->approval_status);
            $this->assertNull($account->fresh()->approval_reviewed_by);
            $this->assertDatabaseCount('account_notifications', 0);
        }
        Mail::assertNothingOutgoing();
    }

    public function test_queue_failure_leaves_successful_decision_and_notification_and_logs_only_static_message(): void
    {
        $director = $this->director();
        $account = User::factory()->pendingApproval()->create();
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('queue')->once()->with(Mockery::type(AccountDecisionMail::class))->andReturnUsing(function () {
            $this->assertSame(0, DB::transactionLevel());
            throw new RuntimeException('Transport details must not be logged.');
        });
        Mail::shouldReceive('to')->once()->with($account->email)->andReturn($pending);
        Log::spy();
        $this->actingAs($director)->postJson(route('account-requests.review', $account), ['decision' => 'approve', 'confirmed' => 1])
            ->assertOk();
        $this->assertSame('approved', $account->fresh()->approval_status);
        $this->assertDatabaseCount('account_notifications', 1);
        Log::shouldHaveReceived('warning')->once()->with('Account decision email could not be queued.');
        Log::shouldNotHaveReceived('error');
    }

    public function test_worker_transport_failure_cannot_undo_a_committed_decision(): void
    {
        $director = $this->director();
        $account = User::factory()->pendingApproval()->create();
        $this->decide($director, $account);
        $mail = Mail::queued(AccountDecisionMail::class)->sole();
        $factory = Mockery::mock(MailFactory::class);
        $mailer = Mockery::mock(Mailer::class);
        $factory->shouldReceive('mailer')->andReturn($mailer);
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('Simulated delivery failure.'));
        try {
            (new SendQueuedMailable($mail))->handle($factory);
            $this->fail('The fake transport must fail.');
        } catch (RuntimeException) {
            $this->assertSame('approved', $account->fresh()->approval_status);
            $this->assertDatabaseCount('account_notifications', 1);
        }
    }

    #[DataProvider('decisions')]
    public function test_decision_email_uses_registered_address_safe_content_and_configured_origin(string $decision, string $status, string $kind): void
    {
        config(['app.url' => 'https://rlc.example']);
        $director = $this->director();
        $account = User::factory()->pendingApproval()->create(['password' => 'Private-password-marker-123!']);
        Beneficiary::create(['beneficiary_id' => 'PRIVATE-BENEFICIARY', 'full_name' => 'Private Beneficiary Marker',
            'registered_at' => '2026-10-09', 'status' => 'active']);
        $this->actingAs($director)->withServerVariables(['HTTP_HOST' => 'untrusted.example'])
            ->postJson(route('account-requests.review', $account), ['decision' => $decision, 'confirmed' => 1,
                'email' => 'attacker@example.test', 'rejection_reason' => 'Private-reason-marker-token-password'])
            ->assertOk();
        $mail = Mail::queued(AccountDecisionMail::class)->sole();
        $this->assertTrue($mail->hasTo($account->email));
        $this->assertFalse($mail->hasTo('attacker@example.test'));
        $html = $mail->render();
        $this->assertStringContainsString('https://rlc.example'.route($status === 'approved' ? 'login' : 'account.status', absolute: false), $html);
        $this->assertStringContainsString($status === 'approved' ? 'Sign In' : 'View account status', $html);
        $payload = $html.serialize($mail).AccountNotification::sole()->toJson();
        foreach (['Private-password-marker-123!', $account->password, 'Private Beneficiary Marker', 'PRIVATE-BENEFICIARY',
            'Private-reason-marker-token-password', 'untrusted.example', 'register/verify'] as $private) {
            $this->assertStringNotContainsString($private, $payload);
        }
        $this->assertSame($kind, AccountNotification::sole()->kind);
    }

    public function test_unique_event_keys_preserve_read_state_and_prevent_second_dispatch(): void
    {
        $director = $this->director();
        $account = User::factory()->pendingApproval()->create();
        $this->decide($director, $account);
        $notification = AccountNotification::sole();
        AccountNotification::whereKey($notification->id)->update(['read_at' => now()]);
        DB::transaction(fn () => app(AccountNotificationService::class)->decisionSaved($account->fresh()));
        $this->assertDatabaseCount('account_notifications', 1);
        $this->assertNotNull($notification->fresh()->read_at);
        Mail::assertQueuedCount(1);
    }

    #[DataProvider('caseworkerStates')]
    public function test_caseworkers_can_read_only_their_own_account_notifications_without_staff_access_bypass(string $status, bool $verified): void
    {
        $account = User::factory()->create(['approval_status' => $status, 'email_verified_at' => $verified ? now() : null]);
        $other = User::factory()->create();
        $own = $this->notification($account);
        $foreign = $this->notification($other);
        $forged = $this->notification($account, AccountNotification::APPROVED, $other);
        $unrelated = $this->notification($account, 'unrelated_event');
        $this->actingAs($account)->getJson(route('account.notifications.index', ['recipient_id' => $other->id, 'account_id' => $other->id]))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $own->id)->assertJsonPath('unread_count', 1)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(route('account.notifications.count'))->assertOk()->assertExactJson(['unread_count' => 1]);
        foreach ([$foreign, $forged, $unrelated] as $hidden) {
            $this->postJson(route('account.notifications.read', $hidden->id))->assertNotFound();
            $this->assertNull($hidden->fresh()->read_at);
        }
        if ($status !== 'approved' || ! $verified) {
            $this->getJson(route('dashboard.notifications'))->assertForbidden();
        }
        $this->postJson(route('account.notifications.read', $own->id))->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_director_list_count_and_read_actions_are_recipient_scoped(): void
    {
        $director = $this->director();
        $other = $this->director();
        $applicant = User::factory()->pendingApproval()->create();
        $own = $this->notification($director, AccountNotification::REQUEST, $applicant);
        $foreign = $this->notification($other, AccountNotification::REQUEST, $applicant);
        $this->actingAs($director)->getJson(route('account.notifications.index'))->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $own->id)->assertJsonPath('items.0.url', route('account-requests.index', absolute: false));
        $this->postJson(route('account.notifications.read', $foreign->id))->assertNotFound();
        $this->postJson(route('account.notifications.read-all'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertNotNull($own->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_guests_other_roles_and_unverified_directors_cannot_use_account_notification_routes(): void
    {
        $this->getJson(route('account.notifications.index'))->assertUnauthorized();
        $this->postJson(route('account.notifications.read-all'))->assertUnauthorized();
        foreach ([['role' => 'admin'], ['role' => 'project_director', 'email_verified_at' => null]] as $fields) {
            $this->actingAs(User::factory()->create($fields));
            $this->getJson(route('account.notifications.index'))->assertForbidden();
            $this->getJson(route('account.notifications.count'))->assertForbidden();
            $this->postJson(route('account.notifications.read-all'))->assertForbidden();
        }
    }

    public function test_mark_read_is_idempotent_and_mark_all_does_not_change_other_recipients_or_unrelated_kinds(): void
    {
        $account = User::factory()->pendingApproval()->create();
        $first = $this->notification($account);
        $second = $this->notification($account);
        $foreign = $this->notification(User::factory()->create());
        $unrelated = $this->notification($account, 'unrelated_event');
        $this->actingAs($account)->postJson(route('account.notifications.read', $first->id))->assertOk()->assertJsonPath('unread_count', 1);
        $read = $first->fresh()->read_at;
        $this->travel(2)->minutes();
        $this->postJson(route('account.notifications.read', $first->id))->assertOk()->assertJsonPath('unread_count', 1);
        $this->assertTrue($first->fresh()->read_at->equalTo($read));
        $this->postJson(route('account.notifications.read-all'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertNull($unrelated->fresh()->read_at);
        $this->assertTrue($first->fresh()->read_at->equalTo($read));
    }

    public function test_unread_count_covers_all_records_beyond_the_twenty_item_list(): void
    {
        $account = User::factory()->create();
        for ($i = 0; $i < 22; $i++) {
            $this->notification($account);
        }
        $this->actingAs($account)->getJson(route('account.notifications.index'))->assertOk()->assertJsonCount(20, 'items')->assertJsonPath('unread_count', 22);
        $this->postJson(route('account.notifications.read-all'))->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_notification_changes_require_csrf(): void
    {
        $account = User::factory()->pendingApproval()->create();
        $notification = $this->notification($account);
        $this->actingAs($account);
        $this->app['env'] = 'production';
        try {
            $this->postJson(route('account.notifications.read', $notification->id))->assertStatus(419);
            $this->postJson(route('account.notifications.read-all'))->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_bell_is_unique_on_staff_and_status_pages_and_activity_is_separate_and_restricted(): void
    {
        $this->actingAs($this->director());
        foreach (['dashboard', 'account-requests.index'] as $route) {
            $html = $this->get(route($route))->assertOk()->assertSee('Your notifications')->assertSee('Activity feed')->getContent();
            $this->assertSame(1, substr_count($html, 'id="rlc-notification-panel"'));
        }
        $this->actingAs(User::factory()->pendingApproval()->create());
        $page = $this->get(route('account.status'))->assertOk()->assertSee('Your notifications')->assertDontSee('Activity feed')
            ->assertDontSee(route('dashboard.notifications', absolute: false));
        $this->assertSame(1, substr_count($page->getContent(), 'id="rlc-notification-panel"'));
        $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk()
            ->assertSee('Your notifications')->assertDontSee('Activity feed');
    }

    private function registration(string $email = 'new@example.test'): array
    {
        $token = bin2hex(random_bytes(32));
        $id = DB::table('registration_requests')->insertGetId(['email' => $email, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        $session = app(EmailRegistrationService::class)->verify($token);
        $this->assertSame($id, $session['id']);

        return $session;
    }

    private function registrationInput(): array
    {
        return ['name' => 'New Caseworker', 'password' => 'Private-registration-password-123!'];
    }

    private function director(): User
    {
        return User::factory()->create(['role' => 'project_director']);
    }

    private function decide(User $director, User $account, string $decision = 'approve'): bool
    {
        return app(ReviewCaseworkerAccount::class)->execute($director, $account, ['decision' => $decision,
            'confirmed' => 1, 'rejection_reason' => 'Review explanation.']);
    }

    private function notification(User $recipient, string $kind = AccountNotification::APPROVED, ?User $account = null): AccountNotification
    {
        return AccountNotification::create(['recipient_id' => $recipient->id, 'account_id' => ($account ?? $recipient)->id,
            'kind' => $kind, 'event_key' => 'fixture:'.Str::uuid()]);
    }

    public static function decisions(): array
    {
        return [['approve', 'approved', AccountNotification::APPROVED], ['reject', 'rejected', AccountNotification::REJECTED]];
    }

    public static function repeatedDecisions(): array
    {
        return [['approve', 'approve'], ['approve', 'reject'], ['reject', 'approve'], ['reject', 'reject']];
    }

    public static function caseworkerStates(): array
    {
        return [['pending', true], ['pending', false], ['rejected', true], ['rejected', false], ['approved', true], ['approved', false]];
    }
}
