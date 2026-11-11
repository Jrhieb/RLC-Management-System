<?php

namespace Tests\Feature;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\User;
use App\Policies\BeneficiaryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BeneficiaryArchiveRestoreTest extends TestCase
{
    use RefreshDatabase;

    private int $beneficiaryNumber = 0;

    protected function beforeRefreshingDatabase(): void
    {
        // Refuse to migrate or mutate a persistent database, even with cached config.
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('These tests require the isolated SQLite :memory: test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    #[DataProvider('actions')]
    public function test_guests_cannot_archive_or_restore(string $action, bool $bulk): void
    {
        $beneficiary = $this->beneficiary($action === 'restore');
        $before = $this->beneficiarySnapshot();

        $this->submit($action, $bulk, [$beneficiary->id])->assertRedirect(route('login'));

        $this->assertSame($before, $this->beneficiarySnapshot());
    }

    #[DataProvider('unauthorizedActions')]
    public function test_only_project_directors_can_archive_and_restore(string $role, string $action, bool $bulk): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $beneficiary = $this->beneficiary($action === 'restore');
        $before = $this->beneficiarySnapshot();

        $this->submit($action, $bulk, [$beneficiary->id])->assertForbidden();

        $this->assertSame($before, $this->beneficiarySnapshot());
    }

    public function test_caseworkers_cannot_access_archived_records_or_archive_controls(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $this->beneficiary();
        $archived = $this->beneficiary(true, ['photo_path' => 'beneficiary-photos/archived.jpg']);

        $this->get(route('beneficiaries.archived'))->assertForbidden();
        $this->get(route('beneficiaries.archived-photo', $archived->id))->assertForbidden();
        $this->get(route('beneficiaries.index'))->assertOk()
            ->assertDontSee('Archive Selected')
            ->assertDontSee('Select all on this page')
            ->assertDontSee('View Archived');
    }

    #[DataProvider('bulkModes')]
    public function test_archive_and_restore_retain_photos_and_history_and_return_records_to_the_directory(bool $bulk): void
    {
        Storage::fake('local');
        $director = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($director);
        $activityId = DB::table('program_activities')->insertGetId([
            'title' => 'Test activity',
            'activity_date' => '2026-01-10',
            'development_area' => 'physical',
            'created_by' => $director->id,
        ]);
        $selected = [];
        $histories = [];

        for ($i = 0; $i < ($bulk ? 2 : 1); $i++) {
            $photoPath = 'beneficiary-photos/test-'.$i.'.jpg';
            Storage::disk('local')->put($photoPath, 'test photo '.$i);
            $record = $this->beneficiary(false, [
                'photo_path' => $photoPath,
                'birthdate' => '2016-01-01',
                'address' => 'Test address',
                'guardian_name' => 'Test guardian',
                'guardian_contact' => '09123456789',
                'school' => 'Test school',
                'grade_level' => '4',
            ]);
            $attendance = ActivityAttendance::create([
                'program_activity_id' => $activityId,
                'beneficiary_id' => $record->id,
                'checked_in_at' => '2026-01-10 09:00:00',
                'source' => 'manual',
                'recorded_by' => $director->id,
            ]);
            $assistance = AssistanceTransaction::create([
                'beneficiary_id' => $record->id,
                'category' => 'education',
                'item_name' => 'School supplies',
                'quantity' => 2,
                'released_at' => '2026-01-11 09:00:00',
                'notes' => 'Retained assistance history',
                'recorded_by' => $director->id,
            ]);
            $selected[] = $record;
            $histories[$record->id] = [
                'profile' => Arr::except($record->refresh()->getRawOriginal(), ['deleted_at', 'updated_at']),
                'attendance' => $attendance->refresh()->getRawOriginal(),
                'assistance' => $assistance->refresh()->getRawOriginal(),
            ];
        }

        $unselected = $this->beneficiary();
        $ids = array_map(fn (Beneficiary $record) => $record->id, $selected);

        $this->submit('archive', $bulk, $ids)
            ->assertRedirect(route($bulk ? 'beneficiaries.index' : 'beneficiaries.archived'))
            ->assertSessionHas('success');

        foreach ($selected as $record) {
            $this->assertSoftDeleted($record);
            $this->assertRetained($record, $histories[$record->id]);
            $this->get(route('beneficiaries.show', $record->id))->assertNotFound();
            $this->get(route('beneficiaries.photo', $record->id))->assertNotFound();
            $this->get(route('beneficiaries.archived-photo', $record->id))->assertOk()
                ->assertStreamedContent(Storage::disk('local')->get($record->photo_path));
            $this->get(route('beneficiaries.index', ['search' => $record->beneficiary_id]))
                ->assertOk()->assertViewHas('beneficiaries', fn ($records) => $records->isEmpty());
            $this->get(route('beneficiaries.archived', ['search' => $record->beneficiary_id]))
                ->assertOk()->assertSee($record->full_name);
        }

        $this->assertNotSoftDeleted($unselected);
        $stillArchived = $this->beneficiary(true);

        $this->submit('restore', $bulk, $ids)
            ->assertRedirect($bulk ? route('beneficiaries.archived') : route('beneficiaries.show', $ids[0]))
            ->assertSessionHas('success');

        foreach ($selected as $record) {
            $this->assertNotSoftDeleted($record);
            $this->assertRetained($record, $histories[$record->id]);
            $this->get(route('beneficiaries.index', ['search' => $record->beneficiary_id]))
                ->assertOk()->assertSee($record->full_name)
                ->assertViewHas('beneficiaries', fn ($records) => $records->contains('id', $record->id));
            $this->get(route('beneficiaries.archived', ['search' => $record->beneficiary_id]))
                ->assertOk()->assertViewHas('beneficiaries', fn ($records) => $records->isEmpty());
            $this->get(route('beneficiaries.photo', $record->id))->assertOk()
                ->assertStreamedContent(Storage::disk('local')->get($record->photo_path));
        }

        $this->assertNotSoftDeleted($unselected);
        $this->assertSoftDeleted($stillArchived);
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_bulk_selections_make_no_partial_changes(string $action, string $selection, string $error): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $valid = $this->beneficiary($action === 'restore');
        $wrongState = $this->beneficiary($action !== 'restore');
        $before = $this->beneficiarySnapshot();
        $payload = match ($selection) {
            'missing' => [],
            'empty' => ['beneficiary_ids' => []],
            'scalar' => ['beneficiary_ids' => $valid->id],
            'non-integer' => ['beneficiary_ids' => [$valid->id, 'invalid']],
            'nested' => ['beneficiary_ids' => [$valid->id, [$wrongState->id]]],
            'zero' => ['beneficiary_ids' => [$valid->id, 0]],
            'negative' => ['beneficiary_ids' => [$valid->id, -1]],
            'duplicate' => ['beneficiary_ids' => [$valid->id, (string) $valid->id]],
            'unavailable' => ['beneficiary_ids' => [$valid->id, $wrongState->id + 1000]],
            'wrong-state' => ['beneficiary_ids' => [$valid->id, $wrongState->id]],
            'too-many' => ['beneficiary_ids' => range(1, 101)],
        };

        $this->postJson(route('beneficiaries.bulk-'.$action), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertSame($before, $this->beneficiarySnapshot());
    }

    #[DataProvider('bulkActions')]
    public function test_bulk_actions_authorize_each_selected_record_before_changing_any(string $action): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $first = $this->beneficiary($action === 'restore');
        $denied = $this->beneficiary($action === 'restore');
        $before = $this->beneficiarySnapshot();
        $policy = new class($denied->id) extends BeneficiaryPolicy
        {
            public array $checkedIds = [];

            public function __construct(private int $deniedId) {}

            public function delete(User $user, Beneficiary $beneficiary): bool
            {
                $this->checkedIds[] = $beneficiary->id;

                return $beneficiary->id !== $this->deniedId && parent::delete($user, $beneficiary);
            }

            public function restore(User $user, Beneficiary $beneficiary): bool
            {
                $this->checkedIds[] = $beneficiary->id;

                return $beneficiary->id !== $this->deniedId && parent::restore($user, $beneficiary);
            }
        };
        $this->app->instance($policy::class, $policy);
        Gate::policy(Beneficiary::class, $policy::class);

        $this->submit($action, true, [$denied->id, $first->id])->assertForbidden();

        $this->assertSame([$first->id, $denied->id], $policy->checkedIds);
        $this->assertSame($before, $this->beneficiarySnapshot());
    }

    #[DataProvider('bulkActions')]
    public function test_bulk_actions_roll_back_if_a_later_record_fails(string $action): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $first = $this->beneficiary($action === 'restore');
        $second = $this->beneficiary($action === 'restore');
        $before = $this->beneficiarySnapshot();
        $fail = function (Beneficiary $record) use ($second): void {
            if ($record->id === $second->id) {
                throw new RuntimeException('Simulated bulk write failure.');
            }
        };

        if ($action === 'archive') {
            Beneficiary::deleting($fail);
        } else {
            Beneficiary::restoring($fail);
        }

        $this->withoutExceptionHandling();

        try {
            $this->submit($action, true, [$first->id, $second->id]);
            $this->fail('The simulated write failure should have been thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated bulk write failure.', $exception->getMessage());
        }

        $this->assertSame($before, $this->beneficiarySnapshot());
    }

    #[DataProvider('bulkActions')]
    public function test_select_all_contains_only_records_on_the_current_page_and_search(string $action): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));

        for ($i = 0; $i < 13; $i++) {
            $last = $this->beneficiary($action === 'restore');
        }

        $route = $action === 'archive' ? 'beneficiaries.index' : 'beneficiaries.archived';

        foreach ([1 => 10, 2 => 3] as $page => $count) {
            $response = $this->get(route($route, ['page' => $page]))->assertOk();
            $records = $response->viewData('beneficiaries')->getCollection();
            $this->assertCount($count, $records);
            $ids = $records->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
            $response->assertSee('pageIds: '.Js::from($ids)->toHtml(), false);
        }

        $this->get(route($route, ['search' => $last->beneficiary_id]))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->count() === 1 && $records->contains('id', $last->id))
            ->assertSee('pageIds: '.Js::from([(string) $last->id])->toHtml(), false);
    }

    #[DataProvider('bulkActions')]
    public function test_single_actions_reject_records_in_the_wrong_state(string $action): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $record = $this->beneficiary($action === 'archive');
        $before = $this->beneficiarySnapshot();

        $this->submit($action, false, [$record->id])->assertNotFound();

        $this->assertSame($before, $this->beneficiarySnapshot());
    }

    public static function actions(): array
    {
        return [
            'single archive' => ['archive', false],
            'bulk archive' => ['archive', true],
            'single restore' => ['restore', false],
            'bulk restore' => ['restore', true],
        ];
    }

    public static function unauthorizedActions(): array
    {
        $cases = [];

        foreach (['caseworker', 'admin'] as $role) {
            foreach (self::actions() as $name => $action) {
                $cases[$role.' '.$name] = [$role, ...$action];
            }
        }

        return $cases;
    }

    public static function bulkModes(): array
    {
        return ['single' => [false], 'bulk' => [true]];
    }

    public static function bulkActions(): array
    {
        return ['archive' => ['archive'], 'restore' => ['restore']];
    }

    public static function invalidSelections(): array
    {
        $cases = [];

        foreach (['archive', 'restore'] as $action) {
            foreach (['missing', 'empty', 'scalar', 'non-integer', 'nested', 'zero', 'negative', 'duplicate', 'unavailable', 'wrong-state', 'too-many'] as $selection) {
                $error = in_array($selection, ['non-integer', 'nested', 'zero', 'negative', 'duplicate'])
                    ? 'beneficiary_ids.1' : 'beneficiary_ids';
                $cases[$action.' '.$selection] = [$action, $selection, $error];
            }
        }

        return $cases;
    }

    private function beneficiary(bool $archived = false, array $attributes = []): Beneficiary
    {
        $number = ++$this->beneficiaryNumber;
        $record = Beneficiary::create($attributes + [
            'beneficiary_id' => sprintf('TEST-%03d', $number),
            'full_name' => sprintf('Test Beneficiary %03d', $number),
            'registered_at' => '2026-01-01',
            'status' => 'active',
        ]);

        if ($archived) {
            $record->delete();
        }

        return $record;
    }

    private function beneficiarySnapshot(): array
    {
        return Beneficiary::withTrashed()->orderBy('id')->get()
            ->map(fn (Beneficiary $record) => $record->getRawOriginal())->all();
    }

    private function submit(string $action, bool $bulk, array $ids): TestResponse
    {
        if ($bulk) {
            return $this->post(route('beneficiaries.bulk-'.$action), ['beneficiary_ids' => $ids]);
        }

        return $action === 'archive'
            ? $this->delete(route('beneficiaries.destroy', $ids[0]))
            : $this->post(route('beneficiaries.restore', $ids[0]));
    }

    private function assertRetained(Beneficiary $record, array $history): void
    {
        $this->assertSame($history['profile'], Arr::except($record->refresh()->getRawOriginal(), ['deleted_at', 'updated_at']));
        $this->assertDatabaseHas('activity_attendances', $history['attendance']);
        $this->assertDatabaseHas('assistance_transactions', $history['assistance']);
        Storage::disk('local')->assertExists($record->photo_path);
        $this->assertSame($record->id, ActivityAttendance::findOrFail($history['attendance']['id'])->beneficiary->id);
        $this->assertSame($record->id, AssistanceTransaction::findOrFail($history['assistance']['id'])->beneficiary->id);
    }
}
