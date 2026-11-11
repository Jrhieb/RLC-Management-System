<?php

namespace Tests\Feature;

use App\Models\ActivityAttendance;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private ProgramActivity $activity;

    private Beneficiary $beneficiary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::factory()->create();
        $this->activity = ProgramActivity::forceCreate([
            'title' => 'Synthetic organization activity', 'activity_date' => '2026-10-17',
            'development_area' => 'physical', 'created_by' => User::factory()->create()->id,
        ]);
        $this->beneficiary = Beneficiary::forceCreate([
            'beneficiary_id' => 'INTEGRITY-001', 'full_name' => 'Synthetic Attendance Person',
            'registered_at' => '2026-10-01', 'status' => 'active',
            'caseworker_id' => $this->actor->id, 'nfc_uid' => 'A0000001',
        ]);
        $this->actingAs($this->actor);
        $this->beforeApplicationDestroyed(fn () => Mail::assertNothingOutgoing());
    }

    public function test_manual_repeat_preserves_the_complete_original_row(): void
    {
        $url = route('activities.attendance.store', $this->activity);
        $data = ['beneficiary_id' => $this->beneficiary->beneficiary_id];
        $this->postJson($url, $data)->assertCreated();
        $original = ActivityAttendance::sole()->getRawOriginal();
        $this->travel(5)->minutes();
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $this->postJson($url, $data + ['source' => 'nfc', 'recorded_by' => 999999])
            ->assertOk()->assertJsonPath('result', 'duplicate')->assertJsonPath('source', 'manual');
        $this->assertDatabaseCount('activity_attendances', 1);
        $this->assertSame($original, ActivityAttendance::sole()->getRawOriginal());
    }

    #[DataProvider('paths')]
    public function test_the_same_beneficiary_can_attend_distinct_activities(string $path): void
    {
        $second = $this->activity->replicate();
        $second->save();
        foreach ([$this->activity, $second] as $activity) {
            $this->postJson(route($path, $activity), $this->input($path))->assertCreated();
        }
        $this->assertDatabaseCount('activity_attendances', 2);
        $this->assertSame([$this->activity->id, $second->id], ActivityAttendance::orderBy('id')->pluck('program_activity_id')->all());
    }

    #[DataProvider('invalidManualReferences')]
    public function test_invalid_manual_beneficiary_references_return_422_without_writes(mixed $id): void
    {
        $this->postJson(route('activities.attendance.store', $this->activity), ['beneficiary_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('beneficiary_id')->assertJsonMissingPath('beneficiary');
        $this->assertDatabaseCount('activity_attendances', 0);
    }

    public static function invalidManualReferences(): array
    {
        return [[null], [''], [[]], [1], ['1'], ['unknown-external-id'], [str_repeat('X', 51)]];
    }

    #[DataProvider('invalidActivities')]
    public function test_invalid_activity_route_references_return_404_without_writes(string $suffix, string $id): void
    {
        $this->postJson('/activities/'.$id.'/attendance'.$suffix, [
            'beneficiary_id' => $this->beneficiary->beneficiary_id, 'nfc_uid' => $this->beneficiary->nfc_uid,
        ])->assertNotFound();
        $this->assertDatabaseCount('activity_attendances', 0);
    }

    public static function invalidActivities(): array
    {
        $cases = [];
        foreach (['', '/nfc'] as $suffix) {
            foreach (['999999', 'malformed', '0', 'null', '', '1e0', '1.0'] as $id) {
                $cases[] = [$suffix, $id];
            }
        }

        return $cases;
    }

    public function test_database_composite_unique_constraint_exists_and_rejects_duplicate_rows(): void
    {
        $this->assertTrue(collect(Schema::getIndexes('activity_attendances'))->contains(
            fn ($index) => $index['unique'] && $index['columns'] === ['program_activity_id', 'beneficiary_id']
        ));
        DB::table('activity_attendances')->insert($this->row());
        try {
            DB::table('activity_attendances')->insert($this->row());
            $this->fail('The database accepted a duplicate attendance pair.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('activity_attendances', 1);
        }
    }

    #[DataProvider('foreignKeys')]
    public function test_database_rejects_missing_activity_or_beneficiary_rows(string $column): void
    {
        try {
            DB::table('activity_attendances')->insert([$column => 999999] + $this->row());
            $this->fail('The database accepted an orphan attendance row.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $exception->getMessage());
            $this->assertDatabaseCount('activity_attendances', 0);
        }
    }

    public static function foreignKeys(): array
    {
        return [['program_activity_id'], ['beneficiary_id']];
    }

    #[DataProvider('paths')]
    public function test_simulated_insert_race_returns_the_winning_row_without_overwriting_it(string $path): void
    {
        // This deliberately interleaves operations on ONE SQLite connection.
        // It exercises conflict handling; it is not a concurrent-write proof.
        $winner = ['recorded_by' => User::factory()->create()->id,
            'source' => $path === 'activities.attendance.nfc' ? 'manual' : 'nfc_test'] + $this->row();
        $seeded = false;
        $createAttempts = 0;
        if ($path === 'activities.attendance.nfc') {
            DB::listen(function (QueryExecuted $query) use (&$seeded, $winner) {
                // The first lookup has already returned no rows; another writer
                // is simulated before firstOrCreate attempts its insert.
                if (! $seeded && str_starts_with($query->sql, 'select * from "activity_attendances"')) {
                    $seeded = true;
                    DB::table('activity_attendances')->insert($winner);
                }
            });
            ActivityAttendance::creating(function () use (&$createAttempts) {
                $createAttempts++;
            });
        } else {
            DB::connection()->beforeExecuting(function (string $sql) use (&$seeded, $winner) {
                if (! $seeded && str_starts_with($sql, 'insert or ignore into "activity_attendances"')) {
                    $seeded = true;
                    DB::table('activity_attendances')->insert($winner);
                }
            });
        }

        $this->postJson(route($path, $this->activity), $this->input($path))
            ->assertOk()->assertJsonPath('result', 'duplicate')->assertJsonPath('source', $winner['source']);
        $this->assertTrue($seeded);
        if ($path === 'activities.attendance.nfc') {
            $this->assertSame(1, $createAttempts, 'The unique-conflict recovery path must be exercised.');
        }
        $this->assertDatabaseCount('activity_attendances', 1);
        $this->assertDatabaseHas('activity_attendances', $winner);
    }

    public function test_unlinked_card_cannot_resolve_or_check_in_but_manual_attendance_remains_available(): void
    {
        $uid = $this->beneficiary->nfc_uid;
        $this->delete(route('beneficiaries.nfc.destroy', $this->beneficiary))->assertRedirect();
        $this->assertNull($this->beneficiary->fresh()->nfc_uid);
        foreach (['nfc.lookup', 'activities.attendance.nfc'] as $route) {
            $url = $route === 'nfc.lookup' ? route($route) : route($route, $this->activity);
            $this->postJson($url, ['nfc_uid' => $uid])->assertUnprocessable()
                ->assertDontSee($this->beneficiary->full_name)->assertDontSee($this->beneficiary->beneficiary_id);
        }
        $this->assertDatabaseCount('activity_attendances', 0);
        $this->postJson(route('activities.attendance.store', $this->activity), [
            'beneficiary_id' => $this->beneficiary->beneficiary_id,
        ])->assertCreated();
    }

    #[DataProvider('paths')]
    public function test_guest_form_submissions_redirect_to_login_without_writes(string $path): void
    {
        $this->app['auth']->forgetGuards();
        $this->post(route($path, $this->activity), $this->input($path))->assertRedirect(route('login'));
        $this->assertDatabaseCount('activity_attendances', 0);
    }

    public static function paths(): array
    {
        return ['manual' => ['activities.attendance.store'], 'NFC' => ['activities.attendance.nfc']];
    }

    private function input(string $path): array
    {
        return $path === 'activities.attendance.nfc'
            ? ['nfc_uid' => $this->beneficiary->nfc_uid]
            : ['beneficiary_id' => $this->beneficiary->beneficiary_id];
    }

    private function row(): array
    {
        return [
            'program_activity_id' => $this->activity->id, 'beneficiary_id' => $this->beneficiary->id,
            'checked_in_at' => '2026-10-17 00:30:00', 'created_at' => '2026-10-17 00:30:00',
            'updated_at' => '2026-10-17 00:30:00', 'source' => 'manual', 'recorded_by' => $this->actor->id,
        ];
    }
}
