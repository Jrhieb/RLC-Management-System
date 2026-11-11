<?php

namespace Tests\Feature;

use App\Models\ActivityAttendance;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use App\Models\User;
use App\Policies\ProgramActivityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AttendanceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private int $beneficiaryNumber = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Attendance tests require the isolated SQLite :memory: test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_workspace_requires_an_explicit_activity_and_keeps_activity_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $activity = $this->activity();

        $this->get(route('attendance.index'))->assertOk()->assertViewHas('activity', null)
            ->assertSee('Manage Activities')->assertSee(route('activities.index'))
            ->assertSee($activity->title)->assertSee('Oct 05, 2026')->assertSee('Test venue')
            ->assertSee('data-start-scanning disabled', false)
            ->assertDontSee('data-uid-form', false);
        $this->get(route('attendance.index', ['activity_id' => $activity->id]))
            ->assertOk()->assertViewHas('activity', fn ($selected) => $selected->is($activity))
            ->assertSee('Receiving check-ins')->assertSee('Test UID mode')->assertSee('data-uid-fields disabled', false)
            ->assertSee('Mute scan sounds')->assertSee('data-scan-mute', false)
            ->assertSee('name="nfc_uid" type="password"', false)
            ->assertSee('data-scan-announcement role="status" aria-live="polite"', false)
            ->assertSee('data-scan-error-announcement role="alert" aria-live="assertive"', false);
        $this->get(route('activities.show', $activity))->assertOk()->assertSee('Manage Activities');
        $this->get(route('activities.index'))->assertOk();
        $this->get(route('activities.create'))->assertOk();
        $this->get(route('activities.edit', $activity))->assertOk();
        $this->getJson(route('attendance.index', ['activity_id' => 999999]))->assertUnprocessable();
    }

    public function test_counts_are_real_and_independent_of_search_and_other_activities(): void
    {
        $user = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($user);
        $activity = $this->activity();
        foreach (['manual', 'nfc_test', 'nfc'] as $source) {
            $this->attendance($activity, $this->beneficiary(['full_name' => 'Selected '.$source]), $source, $user);
        }
        $this->attendance($this->activity(), $this->beneficiary(['full_name' => 'Other activity']), 'nfc', $user);

        $response = $this->getJson(route('attendance.index', ['activity_id' => $activity->id, 'search' => 'Selected manual']));
        $response->assertOk()->assertJsonPath('activity_id', $activity->id)
            ->assertJsonPath('counts', ['total' => 3, 'manual' => 1, 'test_uid' => 1, 'nfc' => 1]);
        $this->assertStringContainsString('Selected manual', $response->json('records_html'));
        $this->assertStringNotContainsString('Selected nfc', $response->json('records_html'));
        $this->assertStringNotContainsString('Other activity', $response->json('records_html'));
    }

    public function test_records_and_csv_share_search_scope_pagination_history_and_pht(): void
    {
        $user = User::factory()->create(['role' => 'project_director', 'name' => 'Test Recorder']);
        $this->actingAs($user);
        $activity = $this->activity();
        for ($i = 0; $i < 12; $i++) {
            $beneficiary = $this->beneficiary(['full_name' => sprintf('Matching Person %02d', $i)]);
            $this->attendance($activity, $beneficiary, 'nfc_test', $user);
            if ($i === 11) {
                $beneficiary->delete();
            }
        }
        $this->attendance($activity, $this->beneficiary(['full_name' => 'Excluded person']), 'manual', $user);
        $this->attendance($this->activity(), $this->beneficiary(['full_name' => 'Matching other activity']), 'manual', $user);

        $response = $this->get(route('attendance.index', ['activity_id' => $activity->id, 'search' => ' Matching ']));
        $response->assertOk()->assertViewHas('attendances', fn ($rows) => $rows->count() === 10 && $rows->total() === 12)
            ->assertSee('Archived')->assertSee('Test Recorder')->assertSee('Oct 05, 2026 - 8:30 AM')
            ->assertSee('Test UID')->assertDontSee('Excluded person')->assertDontSee('Matching other activity');
        $this->get(route('attendance.index', ['activity_id' => $activity->id, 'search' => 'Matching', 'page' => 2]))
            ->assertOk()->assertViewHas('attendances', fn ($rows) => $rows->count() === 2 && $rows->total() === 12);

        $export = $this->get(route('activities.attendance.export', ['activity' => $activity, 'search' => 'Matching']));
        $export->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $export->streamedContent();
        $this->assertSame(12, substr_count($csv, 'Matching Person'));
        $this->assertStringContainsString('2026-10-05 08:30:00', $csv);
        $this->assertStringContainsString('Test Recorder', $csv);
        $this->assertStringNotContainsString('Excluded person', $csv);
        $this->assertStringNotContainsString('Matching other activity', $csv);
        $record = Beneficiary::withTrashed()->where('full_name', 'Matching Person 11')->firstOrFail();
        $this->getJson(route('attendance.index', ['activity_id' => $activity->id, 'search' => $record->beneficiary_id]))
            ->assertOk()->assertJsonPath('counts.total', 13);
    }

    #[DataProvider('normalizedUids')]
    public function test_test_uids_normalize_and_retain_the_test_source(string $stored, string $input): void
    {
        $user = User::factory()->create(['role' => 'caseworker']);
        $this->actingAs($user);
        $activity = $this->activity();
        $beneficiary = $this->beneficiary();
        $beneficiary->forceFill(['nfc_uid' => $stored])->save();

        $this->postJson(route('activities.attendance.nfc', $activity), ['nfc_uid' => $input, 'source' => 'nfc'])
            ->assertCreated()->assertJsonPath('result', 'created')->assertJsonPath('message', 'Checked in successfully')
            ->assertJsonPath('beneficiary.beneficiary_id', $beneficiary->beneficiary_id)
            ->assertJsonPath('activity.id', $activity->id)->assertJsonPath('source', 'nfc_test');
        $this->assertDatabaseHas('activity_attendances', [
            'program_activity_id' => $activity->id, 'beneficiary_id' => $beneficiary->id,
            'source' => 'nfc_test', 'recorded_by' => $user->id,
        ]);
    }

    public function test_duplicate_uid_requests_return_confirmation_without_replacing_the_original_record(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $activity = $this->activity();
        $beneficiary = $this->beneficiary();
        $url = route('activities.attendance.nfc', $activity);
        $this->postJson($url, ['nfc_uid' => $beneficiary->nfc_uid])->assertCreated();
        $original = ActivityAttendance::sole()->getRawOriginal();
        $caseworker = User::factory()->create(['role' => 'caseworker']);
        $beneficiary->forceFill(['caseworker_id' => $caseworker->id])->save();
        $this->actingAs($caseworker);
        $this->travel(5)->minutes();
        $this->postJson($url, ['nfc_uid' => $beneficiary->nfc_uid])->assertOk()
            ->assertJsonPath('result', 'duplicate')->assertJsonPath('message', 'Already checked in');
        $this->assertDatabaseCount('activity_attendances', 1);
        $this->assertSame($original, ActivityAttendance::sole()->getRawOriginal());
    }

    #[DataProvider('firstSources')]
    public function test_manual_and_uid_requests_share_duplicate_protection(string $firstSource): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $activity = $this->activity();
        $beneficiary = $this->beneficiary();
        $manual = route('activities.attendance.store', $activity);
        $uid = route('activities.attendance.nfc', $activity);
        $manualData = ['beneficiary_id' => ' '.$beneficiary->beneficiary_id.' '];
        $uidData = ['nfc_uid' => $beneficiary->nfc_uid];
        $this->postJson($firstSource === 'manual' ? $manual : $uid, $firstSource === 'manual' ? $manualData : $uidData)
            ->assertCreated();
        $this->postJson($firstSource === 'manual' ? $uid : $manual, $firstSource === 'manual' ? $uidData : $manualData)
            ->assertOk()->assertJsonPath('result', 'duplicate')->assertJsonPath('source', $firstSource);
        $this->assertDatabaseCount('activity_attendances', 1);
        $this->assertSame($firstSource, ActivityAttendance::sole()->source);
    }

    #[DataProvider('invalidUids')]
    public function test_invalid_uids_do_not_create_attendance(mixed $uid): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $this->postJson(route('activities.attendance.nfc', $this->activity()), ['nfc_uid' => $uid])
            ->assertUnprocessable()->assertJsonValidationErrors('nfc_uid')->assertJsonMissingPath('beneficiary');
        $this->assertDatabaseCount('activity_attendances', 0);
    }

    public function test_unknown_uid_is_rejected_without_profile_data(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $this->postJson(route('activities.attendance.nfc', $this->activity()), ['nfc_uid' => 'ABCDEF12'])
            ->assertUnprocessable()->assertJsonValidationErrors('nfc_uid')->assertJsonMissingPath('beneficiary');
        $this->assertDatabaseCount('activity_attendances', 0);
    }

    #[DataProvider('registeredDecimalCards')]
    public function test_registered_decimal_cards_work_for_lookup_and_attendance(string $uid, bool $lookup): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $beneficiary = $this->beneficiary();
        $beneficiary->forceFill(['nfc_uid' => null])->save();
        $this->putJson(route('beneficiaries.nfc.update', $beneficiary), ['nfc_uid' => $uid])
            ->assertOk()->assertJsonPath('status', 'registered');
        $this->assertSame($uid, $beneficiary->fresh()->nfc_uid);

        if ($lookup) {
            $this->postJson(route('nfc.lookup'), ['nfc_uid' => $uid])
                ->assertRedirect(route('beneficiaries.show', $beneficiary));
            $this->assertDatabaseCount('activity_attendances', 0);
        } else {
            $this->postJson(route('activities.attendance.nfc', $this->activity()), ['nfc_uid' => $uid])
                ->assertCreated()->assertJsonPath('source', 'nfc_test')
                ->assertJsonPath('beneficiary.beneficiary_id', $beneficiary->beneficiary_id);
            $this->assertDatabaseCount('activity_attendances', 1);
        }
    }

    public static function registeredDecimalCards(): array
    {
        return [
            'leading zeros lookup' => ['0027947780', true],
            'leading zeros check-in' => ['0027947780', false],
            'all zeros lookup' => ['0000000000', true],
            'all zeros check-in' => ['0000000000', false],
        ];
    }

    #[DataProvider('unavailableBeneficiaries')]
    public function test_inactive_and_archived_beneficiaries_cannot_use_either_check_in(string $status, bool $archived): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $activity = $this->activity();
        $beneficiary = $this->beneficiary(['status' => $status]);
        if ($archived) {
            $beneficiary->delete();
        }
        $this->postJson(route('activities.attendance.nfc', $activity), ['nfc_uid' => $beneficiary->nfc_uid])
            ->assertUnprocessable()->assertJsonValidationErrors('nfc_uid')->assertJsonMissingPath('beneficiary');
        $this->postJson(route('activities.attendance.store', $activity), ['beneficiary_id' => $beneficiary->beneficiary_id])
            ->assertUnprocessable()->assertJsonValidationErrors('beneficiary_id')->assertJsonMissingPath('beneficiary');
        $this->assertDatabaseCount('activity_attendances', 0);
    }

    #[DataProvider('permissionCases')]
    public function test_auth_verified_and_role_permissions_apply_to_workspace_and_writes(?string $role, bool $verified, int $status): void
    {
        if ($role) {
            $this->actingAs(User::factory()->create([
                'role' => $role, 'email_verified_at' => $verified ? now() : null,
            ]));
        }
        $activity = $this->activity();
        $beneficiary = $this->beneficiary();
        $this->getJson(route('attendance.index', ['activity_id' => $activity->id]))->assertStatus($status);
        $this->getJson(route('activities.attendance.export', $activity))->assertStatus($status);
        $this->postJson(route('activities.attendance.nfc', $activity), ['nfc_uid' => $beneficiary->nfc_uid])->assertStatus($status);
        $this->postJson(route('activities.attendance.store', $activity), ['beneficiary_id' => $beneficiary->beneficiary_id])->assertStatus($status);
        $this->assertDatabaseCount('activity_attendances', 0);
    }

    public function test_record_attendance_permission_is_checked_for_the_selected_activity(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $denied = $this->activity();
        $allowed = $this->activity();
        $beneficiary = $this->beneficiary();
        $policy = new class($denied->id) extends ProgramActivityPolicy
        {
            public function __construct(private int $deniedId) {}

            public function recordAttendance(User $user, ProgramActivity $activity): bool
            {
                return $activity->id !== $this->deniedId && parent::recordAttendance($user, $activity);
            }
        };
        $this->app->instance(ProgramActivityPolicy::class, $policy);
        Gate::policy(ProgramActivity::class, ProgramActivityPolicy::class);
        $this->get(route('attendance.index', ['activity_id' => $denied->id]))->assertOk()->assertDontSee('data-uid-form', false);
        $this->postJson(route('activities.attendance.nfc', $denied), ['nfc_uid' => $beneficiary->nfc_uid])->assertForbidden();
        $this->postJson(route('activities.attendance.store', $denied), ['beneficiary_id' => $beneficiary->beneficiary_id])->assertForbidden();
        $this->postJson(route('activities.attendance.nfc', $allowed), ['nfc_uid' => $beneficiary->nfc_uid])->assertCreated();
        $this->assertDatabaseCount('activity_attendances', 1);
    }

    public function test_card_response_only_contains_required_profile_information_and_protected_photo_url(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $beneficiary = $this->beneficiary([
            'photo_path' => 'beneficiary-photos/attendance-test.jpg',
            'guardian_name' => 'Private guardian', 'address' => 'Private address',
        ]);
        Storage::disk('local')->put($beneficiary->photo_path, 'test photo');
        $photoUrl = route('beneficiaries.photo', $beneficiary);
        $response = $this->postJson(route('activities.attendance.nfc', $this->activity()), ['nfc_uid' => $beneficiary->nfc_uid]);
        $response->assertCreated()->assertJsonPath('beneficiary.photo_url', $photoUrl)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['full_name', 'beneficiary_id', 'status', 'photo_url'], array_keys($response->json('beneficiary')));
        foreach (['Private guardian', 'Private address', $beneficiary->photo_path, $beneficiary->nfc_uid] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->get($photoUrl)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get($photoUrl)->assertRedirect(route('login'));
    }

    public function test_csrf_is_still_required_outside_the_testing_bypass(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $activity = $this->activity();
        $beneficiary = $this->beneficiary();
        // The database guard has already run; only disable Laravel's CSRF test bypass.
        $this->app['env'] = 'csrf-check';
        $this->postJson(route('activities.attendance.nfc', $activity), ['nfc_uid' => $beneficiary->nfc_uid])->assertStatus(419);
        $this->postJson(route('activities.attendance.store', $activity), ['beneficiary_id' => $beneficiary->beneficiary_id])->assertStatus(419);
        $this->assertDatabaseCount('activity_attendances', 0);
        $this->app['env'] = 'testing';
    }

    public function test_non_javascript_form_fallbacks_remain_available(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $activity = $this->activity();
        $manual = $this->beneficiary();
        $uid = $this->beneficiary();
        $this->post(route('activities.attendance.store', $activity), ['beneficiary_id' => $manual->beneficiary_id])
            ->assertRedirect(route('activities.show', $activity))->assertSessionHas('success');
        $this->post(route('activities.attendance.nfc', $activity), ['nfc_uid' => $uid->nfc_uid])
            ->assertRedirect(route('activities.show', $activity))->assertSessionHas('success');
        $this->assertDatabaseCount('activity_attendances', 2);
    }

    public static function normalizedUids(): array
    {
        return [
            ['04A1B2C3', ' 04:a1-b2 c3 '],
            ['04A1B2C3D4E5F6', '04:a1:b2:c3:d4:e5:f6'],
            ['04A1B2C3D4E5F6070809', '04-a1-b2-c3-d4-e5-f6-07-08-09'],
        ];
    }

    public static function firstSources(): array
    {
        return [['manual'], ['nfc_test']];
    }

    public static function invalidUids(): array
    {
        return [[null], [''], [[]], [12345678], ['GGHHIIJJ'], ['1234567'], ['123456789'], [str_repeat('A', 65)], ['04/A1/B2/C3']];
    }

    public static function unavailableBeneficiaries(): array
    {
        return [['inactive', false], ['active', true], ['inactive', true]];
    }

    public static function permissionCases(): array
    {
        return [[null, true, 401], ['admin', true, 403], ['caseworker', false, 403], ['project_director', false, 403]];
    }

    private function activity(): ProgramActivity
    {
        $activity = new ProgramActivity([
            'title' => 'Test activity', 'activity_date' => '2026-10-05',
            'development_area' => 'physical', 'location' => 'Test venue', 'start_time' => '08:30',
        ]);
        $activity->created_by = User::factory()->create(['role' => 'caseworker'])->id;
        $activity->save();

        return $activity;
    }

    private function beneficiary(array $attributes = []): Beneficiary
    {
        $number = ++$this->beneficiaryNumber;
        $beneficiary = Beneficiary::create($attributes + [
            'beneficiary_id' => sprintf('ATT-%03d', $number), 'full_name' => 'Attendance person '.$number,
            'registered_at' => '2026-01-01', 'status' => 'active',
        ]);
        // Successful Caseworker check-ins use explicitly owned test fixtures.
        if (auth()->user()?->isCaseworker()) {
            $beneficiary->forceFill(['caseworker_id' => auth()->id()])->save();
        }
        $beneficiary->forceFill(['nfc_uid' => strtoupper(str_pad(dechex($number), 8, '0', STR_PAD_LEFT))])->save();

        return $beneficiary;
    }

    private function attendance(ProgramActivity $activity, Beneficiary $beneficiary, string $source, User $recorder): ActivityAttendance
    {
        return ActivityAttendance::create([
            'program_activity_id' => $activity->id, 'beneficiary_id' => $beneficiary->id,
            'source' => $source, 'recorded_by' => $recorder->id, 'checked_in_at' => '2026-10-05 00:30:00',
        ]);
    }
}
