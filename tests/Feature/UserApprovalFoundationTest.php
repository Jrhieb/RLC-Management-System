<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UserApprovalFoundationTest extends TestCase
{
    private const APPROVAL_COLUMNS = [
        'approval_status', 'approval_reviewed_by', 'approval_reviewed_at', 'rejection_reason',
    ];

    private function assertIsolatedTestEnvironment(): void
    {
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:'
            || config('mail.default') !== 'array'
            || config('session.driver') !== 'array'
            || config('cache.default') !== 'array'
            || config('queue.default') !== 'sync') {
            throw new RuntimeException('Approval foundation tests require guarded SQLite :memory: and isolated mail, sessions, cache, and queues.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestEnvironment();
        Notification::fake();
        Mail::fake();

        // Exercise upgrades without a wrapping transaction; discard the memory database at teardown.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->beforeApplicationDestroyed(fn () => DB::purge('sqlite'));
    }

    public function test_upgrade_backfills_only_existing_caseworkers_without_changing_legacy_data(): void
    {
        $migration = $this->approvalMigration();
        $migration->down();
        $users = $this->legacyFixtures();
        $usersBefore = $this->snapshot('users');
        $relatedBefore = $this->relatedSnapshots();

        $migration->up();

        $this->assertSame($usersBefore, $this->usersWithoutApprovalFields());
        $this->assertSame($relatedBefore, $this->relatedSnapshots());
        foreach ($users as $kind => $id) {
            $account = User::findOrFail($id);
            $this->assertSame(in_array($kind, ['verified', 'unverified'], true) ? 'approved' : 'pending', $account->approval_status);
            $this->assertNull($account->approval_reviewed_by);
            $this->assertNull($account->approval_reviewed_at);
            $this->assertNull($account->rejection_reason);
        }
        $verified = User::findOrFail($users['verified']);
        $unverified = User::findOrFail($users['unverified']);
        $this->assertTrue($verified->hasVerifiedEmail());
        $this->assertTrue($verified->isEligibleCaseworker());
        $this->assertNull($unverified->email_verified_at);
        $this->assertFalse($unverified->isEligibleCaseworker());
        $this->assertSame([$verified->id], User::eligibleCaseworkers()->pluck('id')->all());
        $this->assertSame(1, $verified->assignedBeneficiaries()->count());
        $this->assertTrue(User::findOrFail($users['director'])->isProjectDirector());
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_new_accounts_default_to_pending_in_the_model_and_database_after_upgrade(): void
    {
        $migration = $this->approvalMigration();
        $migration->down();
        $legacy = $this->legacyFixtures();
        $migration->up();

        $this->assertSame('pending', (new User)->approval_status);
        $account = User::create([
            'name' => 'New default approval fixture',
            'email' => 'default-approval@example.test',
            'password' => 'Approval-test-password-123!',
        ]);
        $this->assertSame('pending', $account->approval_status);
        $this->assertSame('pending', $account->fresh()->approval_status);
        $this->assertFalse($account->fresh()->isEligibleCaseworker());

        $id = DB::table('users')->insertGetId([
            'name' => 'New approval fixture',
            'email' => 'new-approval@example.test',
            'password' => Hash::make('Approval-test-password-123!'),
            'email_verified_at' => '2026-10-08 01:00:00',
        ]);
        $inserted = User::findOrFail($id);
        $this->assertSame('caseworker', $inserted->role);
        $this->assertSame('pending', $inserted->approval_status);
        $this->assertNull($inserted->approval_reviewed_by);
        $this->assertNull($inserted->approval_reviewed_at);
        $this->assertNull($inserted->rejection_reason);
        $this->assertFalse($inserted->isApproved());
        $this->assertFalse($inserted->isEligibleCaseworker());
        $this->assertSame('approved', User::findOrFail($legacy['verified'])->approval_status);
    }

    #[DataProvider('eligibilityCases')]
    public function test_eligibility_requires_caseworker_role_verified_email_and_approved_status(string $role, bool $verified, string $status): void
    {
        $account = User::factory()->create([
            'role' => $role,
            'email_verified_at' => $verified ? now() : null,
            'approval_status' => $status,
        ]);
        $eligible = $role === 'caseworker' && $verified && $status === 'approved';

        $this->assertSame($status === 'approved', $account->isApproved());
        $this->assertSame($role === 'caseworker', $account->isCaseworker());
        $this->assertSame($eligible, $account->isEligibleCaseworker());
        $this->assertSame($eligible, User::eligibleCaseworkers()->whereKey($account->id)->exists());
    }

    public function test_approval_fields_remain_protected_from_mass_assignment(): void
    {
        $account = User::create([
            'name' => 'Protected approval fixture',
            'email' => 'protected-approval@example.test',
            'password' => 'Approval-test-password-123!',
            'approval_status' => 'approved',
            'approval_reviewed_by' => 999,
            'approval_reviewed_at' => '2026-10-08 01:00:00',
            'rejection_reason' => 'Untrusted review input',
        ])->fresh();

        $this->assertSame('pending', $account->approval_status);
        $this->assertNull($account->approval_reviewed_by);
        $this->assertNull($account->approval_reviewed_at);
        $this->assertNull($account->rejection_reason);
    }

    public function test_review_metadata_is_cast_when_explicitly_saved(): void
    {
        $reviewer = User::factory()->create(['role' => 'project_director']);
        $account = User::factory()->create();
        $account->approval_status = 'rejected';
        $account->approval_reviewed_by = (string) $reviewer->id;
        $account->approval_reviewed_at = '2026-10-08 01:00:00';
        $account->rejection_reason = 'Isolated review fixture';
        $account->save();
        $account->refresh();

        $this->assertSame($reviewer->id, $account->approval_reviewed_by);
        $this->assertInstanceOf(CarbonInterface::class, $account->approval_reviewed_at);
        $this->assertSame('2026-10-08 01:00:00', $account->approval_reviewed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Isolated review fixture', $account->rejection_reason);
        $this->assertFalse($account->isApproved());
    }

    public function test_database_rejects_an_unknown_approval_status(): void
    {
        $account = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $account->id)->update(['approval_status' => 'unknown']);
    }

    public function test_reviewed_by_references_an_existing_user(): void
    {
        $account = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $account->id)->update(['approval_reviewed_by' => 999999]);
    }

    public function test_rollback_removes_only_approval_fields_and_preserves_existing_data(): void
    {
        $users = $this->legacyFixtures();
        DB::table('users')->where('id', $users['verified'])->update([
            'approval_status' => 'approved',
            'approval_reviewed_by' => $users['director'],
            'approval_reviewed_at' => '2026-10-08 01:00:00',
        ]);
        $usersBefore = $this->usersWithoutApprovalFields();
        $relatedBefore = $this->relatedSnapshots();

        $this->approvalMigration()->down();

        foreach (self::APPROVAL_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column));
        }
        $this->assertSame($usersBefore, $this->snapshot('users'));
        $this->assertSame($relatedBefore, $this->relatedSnapshots());
    }

    public static function eligibilityCases(): array
    {
        $cases = [];
        foreach (['caseworker', 'project_director', 'other'] as $role) {
            foreach ([false, true] as $verified) {
                foreach (['pending', 'approved', 'rejected'] as $status) {
                    $cases[$role.' '.$status.' '.($verified ? 'verified' : 'unverified')] = [$role, $verified, $status];
                }
            }
        }

        return $cases;
    }

    private function approvalMigration(): Migration
    {
        return require database_path('migrations/2026_10_08_000001_add_caseworker_approval_fields_to_users_table.php');
    }

    private function legacyFixtures(): array
    {
        $users = [];
        foreach (['verified' => 'caseworker', 'unverified' => 'caseworker', 'director' => 'project_director', 'other' => 'other'] as $kind => $role) {
            $users[$kind] = DB::table('users')->insertGetId([
                'name' => 'Legacy '.$kind.' approval fixture',
                'email' => $kind.'-legacy-approval@example.test',
                'role' => $role,
                'email_verified_at' => $kind === 'unverified' ? null : '2026-10-07 01:00:00',
                'password' => Hash::make('Legacy-approval-password-123!'),
                'remember_token' => 'legacy-'.$kind.'-remember-fixture',
                'two_factor_secret' => encrypt('Legacy approval factor fixture'),
                'two_factor_recovery_codes' => encrypt(json_encode(['Legacy approval recovery fixture'])),
                'two_factor_confirmed_at' => '2026-10-07 01:00:00',
                'created_at' => '2026-10-06 01:00:00',
                'updated_at' => '2026-10-07 01:00:00',
            ]);
        }
        foreach (['verified', 'unverified'] as $kind) {
            DB::table('beneficiaries')->insert([
                'beneficiary_id' => 'APPROVAL-'.$kind,
                'full_name' => 'Legacy assigned '.$kind.' fixture',
                'registered_at' => '2026-10-07',
                'status' => 'active',
                'caseworker_id' => $users[$kind],
                'deleted_at' => $kind === 'unverified' ? '2026-10-07 02:00:00' : null,
                'created_at' => '2026-10-06 01:00:00',
                'updated_at' => '2026-10-07 01:00:00',
            ]);
            DB::table('sessions')->insert([
                'id' => 'approval-'.$kind.'-session-fixture',
                'user_id' => $users[$kind],
                'payload' => base64_encode('Isolated legacy session fixture'),
                'last_activity' => 1791334800,
            ]);
            DB::table('passkeys')->insert([
                'user_id' => $users[$kind],
                'name' => 'Legacy '.$kind.' passkey fixture',
                'credential_id' => 'approval-'.$kind.'-credential-fixture',
                'credential' => json_encode(['fixture' => 'isolated approval test']),
                'last_used_at' => '2026-10-07 01:00:00',
                'created_at' => '2026-10-06 01:00:00',
                'updated_at' => '2026-10-07 01:00:00',
            ]);
        }

        return $users;
    }

    private function snapshot(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function usersWithoutApprovalFields(): array
    {
        return array_map(fn ($row) => Arr::except($row, self::APPROVAL_COLUMNS), $this->snapshot('users'));
    }

    private function relatedSnapshots(): array
    {
        return [
            'beneficiaries' => $this->snapshot('beneficiaries'),
            'sessions' => $this->snapshot('sessions'),
            'passkeys' => $this->snapshot('passkeys'),
        ];
    }
}
