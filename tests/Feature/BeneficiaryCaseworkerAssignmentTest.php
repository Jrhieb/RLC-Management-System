<?php

namespace Tests\Feature;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\User;
use App\Policies\BeneficiaryPolicy;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BeneficiaryCaseworkerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private int $recordNumber = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Caseworker assignment tests require SQLite :memory: in testing before any migrations.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        Mail::fake();
    }

    public function test_director_assigns_distinct_records_in_one_transaction_without_changing_profiles_cards_history_or_users(): void
    {
        Storage::fake('local');
        $director = $this->director();
        $caseworker = $this->caseworker();
        $first = $this->record(['beneficiary_id' => '0027947780', 'nfc_uid' => '0027947780', 'photo_path' => 'beneficiary-photos/assigned.jpg']);
        $second = $this->record(['nfc_uid' => 'A1B2C3D4', 'status' => 'inactive']);
        $other = $this->record();
        Storage::disk('local')->put($first->photo_path, 'protected photo fixture');
        $activityId = DB::table('program_activities')->insertGetId([
            'title' => 'Assignment memory activity', 'activity_date' => '2026-10-07',
            'development_area' => 'physical', 'created_by' => $director->id,
        ]);
        ActivityAttendance::create(['program_activity_id' => $activityId, 'beneficiary_id' => $first->id,
            'checked_in_at' => '2026-10-07 09:00:00', 'source' => 'nfc', 'recorded_by' => $director->id]);
        AssistanceTransaction::create(['beneficiary_id' => $first->id, 'category' => 'education', 'item_name' => 'Supplies',
            'quantity' => 1, 'released_at' => '2026-10-07 10:00:00', 'recorded_by' => $director->id]);
        $profiles = array_map(fn ($record) => Arr::except($record->getRawOriginal(), ['caseworker_id', 'updated_at']), [$first, $second]);
        $otherBefore = $other->getRawOriginal();
        $usersBefore = $this->snapshot('users');
        $attendanceBefore = $this->snapshot('activity_attendances');
        $assistanceBefore = $this->snapshot('assistance_transactions');
        $level = DB::transactionLevel();
        $saveLevels = [];
        Beneficiary::saving(function () use (&$saveLevels) {
            $saveLevels[] = DB::transactionLevel();
        });

        $this->post(route('beneficiaries.assign-caseworker', ['search' => 'Assignment', 'status' => 'active', 'page' => 2]),
            ['beneficiary_ids' => [(string) $second->id, $first->id], 'caseworker_id' => $caseworker->id,
                'nfc_uid' => '9999999999', 'full_name' => 'Attempted overwrite', 'status' => 'inactive'])
            ->assertRedirect(route('beneficiaries.index', ['search' => 'Assignment', 'status' => 'active', 'page' => 2]))
            ->assertSessionHasNoErrors()->assertSessionHas('success', '2 beneficiaries assigned to '.$caseworker->name.'.');
        foreach ([$first, $second] as $i => $record) {
            $record->refresh();
            $this->assertSame($caseworker->id, $record->caseworker_id);
            $this->assertSame($profiles[$i], Arr::except($record->getRawOriginal(), ['caseworker_id', 'updated_at']));
            $this->assertTrue($record->caseworker->is($caseworker));
        }
        $this->assertSame([$level + 1, $level + 1], $saveLevels);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $this->assertSame($usersBefore, $this->snapshot('users'));
        $this->assertSame($attendanceBefore, $this->snapshot('activity_attendances'));
        $this->assertSame($assistanceBefore, $this->snapshot('assistance_transactions'));
        $this->assertSame('protected photo fixture', Storage::disk('local')->get($first->photo_path));
        $this->assertSame([$first->id, $second->id], $caseworker->assignedBeneficiaries()->orderBy('id')->pluck('id')->all());
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_director_can_reassign_one_record_and_registry_eagerly_displays_the_saved_caseworker(): void
    {
        $this->director();
        $previous = $this->caseworker(['name' => 'Previous Caseworker']);
        $destination = $this->caseworker(['name' => 'Destination Caseworker']);
        $record = $this->record(['caseworker_id' => $previous->id]);
        $unassigned = $this->record(['full_name' => 'Unassigned Beneficiary']);
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => $destination->id])
            ->assertSessionHasNoErrors()->assertSessionHas('success', '1 beneficiary assigned to Destination Caseworker.');
        $this->assertSame($destination->id, $record->fresh()->caseworker_id);
        $this->assertNull($unassigned->fresh()->caseworker_id);
        $response = $this->get(route('beneficiaries.index'))->assertOk()->assertSee('Destination Caseworker')->assertSee('Unassigned');
        $this->assertTrue($response->viewData('beneficiaries')->every(fn ($item) => $item->relationLoaded('caseworker')));
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//td[@data-label="Caseworker"]//span[normalize-space(.)="Destination Caseworker"]'));
        $this->assertCount(1, $xpath->query('//td[@data-label="Caseworker"]//span[normalize-space(.)="Unassigned"]'));
    }

    #[DataProvider('unassignmentCases')]
    public function test_director_can_explicitly_unassign_one_or_multiple_records(bool $bulk, mixed $destination): void
    {
        $this->director();
        $caseworker = $this->caseworker();
        $selected = [$this->record(['caseworker_id' => $caseworker->id])];
        if ($bulk) {
            $selected[] = $this->record(['caseworker_id' => $this->caseworker()->id]);
        }
        $other = $this->record(['caseworker_id' => $caseworker->id]);
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => array_column($selected, 'id'), 'caseworker_id' => $destination])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Caseworker unassigned from '.count($selected).' '.($bulk ? 'beneficiaries.' : 'beneficiary.'));
        foreach ($selected as $record) {
            $this->assertNull($record->fresh()->caseworker_id);
        }
        $this->assertSame($caseworker->id, $other->fresh()->caseworker_id);
    }

    #[DataProvider('unauthorizedActors')]
    public function test_backend_rejects_guests_other_roles_and_unverified_directors(?string $role, bool $verified, int $status): void
    {
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role, 'email_verified_at' => $verified ? now() : null]));
        }
        $record = $this->record();
        $caseworker = $this->caseworker();
        $before = $this->snapshot('beneficiaries');
        $this->postJson(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => $caseworker->id])
            ->assertStatus($status);
        $this->assertSame($before, $this->snapshot('beneficiaries'));
        Notification::assertNothingSent();
    }

    public function test_guest_web_requests_require_login_and_unverified_directors_require_verification(): void
    {
        $record = $this->record();
        $payload = ['beneficiary_ids' => [$record->id], 'caseworker_id' => null];
        $this->post(route('beneficiaries.assign-caseworker'), $payload)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create(['role' => 'project_director']));
        $this->post(route('beneficiaries.assign-caseworker'), $payload)->assertRedirect(route('verification.notice'));
        $this->assertNull($record->fresh()->caseworker_id);
    }

    #[DataProvider('invalidDestinations')]
    public function test_only_existing_verified_caseworkers_are_eligible(string $role, bool $verified, bool $exists): void
    {
        $this->director();
        $destination = User::factory()->create(['role' => $role, 'email_verified_at' => $verified ? now() : null]);
        if (! $exists) {
            $destination->delete();
        }
        $records = [$this->record(), $this->record()];
        $before = $this->snapshot('beneficiaries');
        $this->from(route('beneficiaries.index'))->post(route('beneficiaries.assign-caseworker'),
            ['beneficiary_ids' => array_column($records, 'id'), 'caseworker_id' => $destination->id])
            ->assertRedirect(route('beneficiaries.index'))->assertSessionHasErrors(['caseworker_id'], null, 'assignCaseworker')
            ->assertSessionMissing('success');
        $this->assertSame($before, $this->snapshot('beneficiaries'));
    }

    public function test_destination_is_rechecked_when_an_account_becomes_ineligible_after_the_registry_opens(): void
    {
        $this->director();
        $destination = $this->caseworker();
        $record = $this->record();
        $this->get(route('beneficiaries.index'))->assertViewHas('caseworkers', fn ($accounts) => $accounts->contains('id', $destination->id));
        $destination->email_verified_at = null;
        $destination->save();
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => $destination->id])
            ->assertSessionHasErrors(['caseworker_id'], null, 'assignCaseworker')->assertSessionMissing('success');
        $this->assertNull($record->fresh()->caseworker_id);
    }

    #[DataProvider('invalidBulkFields')]
    public function test_invalid_bulk_fields_are_rejected_without_partial_changes(array $fields, string $error): void
    {
        $this->director();
        $caseworker = $this->caseworker();
        $records = [$this->record(), $this->record(['caseworker_id' => $caseworker->id])];
        $before = $this->snapshot('beneficiaries');
        $payload = $fields + ['beneficiary_ids' => array_column($records, 'id'), 'caseworker_id' => $caseworker->id];
        $this->post(route('beneficiaries.assign-caseworker'), $payload)
            ->assertSessionHasErrors([$error], null, 'assignCaseworker')->assertSessionMissing('success');
        $this->assertSame($before, $this->snapshot('beneficiaries'));
    }

    public function test_omitting_the_destination_does_not_implicitly_unassign_records(): void
    {
        $this->director();
        $caseworker = $this->caseworker();
        $record = $this->record(['caseworker_id' => $caseworker->id]);
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id]])
            ->assertSessionHasErrors(['caseworker_id'], null, 'assignCaseworker');
        $this->assertSame($caseworker->id, $record->fresh()->caseworker_id);
    }

    #[DataProvider('duplicateIds')]
    public function test_duplicate_beneficiary_ids_are_rejected_even_with_different_scalar_representations(string $representation): void
    {
        $this->director();
        $caseworker = $this->caseworker();
        $record = $this->record();
        $duplicate = match ($representation) {
            'integer' => $record->id, 'string' => (string) $record->id, default => '0'.$record->id
        };
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id, $duplicate], 'caseworker_id' => $caseworker->id])
            ->assertSessionHasErrors(['beneficiary_ids.0'], null, 'assignCaseworker')->assertSessionMissing('success');
        $this->assertNull($record->fresh()->caseworker_id);
    }

    #[DataProvider('unavailableSelections')]
    public function test_missing_or_archived_records_reject_the_whole_selection(bool $archived): void
    {
        $this->director();
        $destination = $this->caseworker();
        $first = $this->record();
        $unavailable = $this->record();
        if ($archived) {
            $unavailable->delete();
        } else {
            $unavailable->forceDelete();
        }
        $before = $this->snapshot('beneficiaries');
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$first->id, $unavailable->id], 'caseworker_id' => $destination->id])
            ->assertSessionHasErrors(['beneficiary_ids'], null, 'assignCaseworker')->assertSessionMissing('success');
        $this->assertSame($before, $this->snapshot('beneficiaries'));
    }

    public function test_every_selected_record_is_authorized_before_any_save(): void
    {
        $this->director();
        $destination = $this->caseworker();
        $allowed = $this->record();
        $blocked = $this->record(['beneficiary_id' => 'BLOCKED']);
        $before = $this->snapshot('beneficiaries');
        $policy = new class extends BeneficiaryPolicy
        {
            public static array $authorized = [];

            public function assignCaseworker(User $user, ?Beneficiary $beneficiary = null): bool
            {
                if ($beneficiary) {
                    self::$authorized[] = $beneficiary->id;
                }

                return parent::assignCaseworker($user, $beneficiary) && $beneficiary?->beneficiary_id !== 'BLOCKED';
            }
        };
        Gate::policy(Beneficiary::class, get_class($policy));
        $saves = 0;
        Beneficiary::saving(function () use (&$saves) {
            $saves++;
        });
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$allowed->id, $blocked->id], 'caseworker_id' => $destination->id])
            ->assertForbidden()->assertSessionMissing('success');
        $this->assertSame([$allowed->id, $blocked->id], $policy::$authorized);
        $this->assertSame(0, $saves);
        $this->assertSame($before, $this->snapshot('beneficiaries'));
    }

    public function test_a_cancelled_second_save_rolls_back_the_first_assignment_and_returns_feedback(): void
    {
        $this->director();
        $destination = $this->caseworker();
        $first = $this->record();
        $second = $this->record(['caseworker_id' => $this->caseworker()->id]);
        $before = $this->snapshot('beneficiaries');
        Beneficiary::saving(fn (Beneficiary $record) => $record->id !== $second->id);
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$first->id, $second->id], 'caseworker_id' => $destination->id])
            ->assertSessionHasErrors(['beneficiary_ids'], null, 'assignCaseworker')->assertSessionMissing('success');
        $this->assertSame($before, $this->snapshot('beneficiaries'));
    }

    public function test_an_exception_on_the_second_save_rolls_back_all_assignments(): void
    {
        $this->director();
        $destination = $this->caseworker();
        $first = $this->record();
        $second = $this->record();
        $before = $this->snapshot('beneficiaries');
        Beneficiary::saving(function (Beneficiary $record) use ($second) {
            if ($record->id === $second->id) {
                throw new RuntimeException('Synthetic assignment write failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$first->id, $second->id], 'caseworker_id' => $destination->id]);
            $this->fail('The simulated write failure should be thrown.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic assignment write failure.', $error->getMessage());
        }
        $this->assertSame($before, $this->snapshot('beneficiaries'));
        $this->assertFalse(session()->has('success'));
    }

    public function test_director_modal_lists_only_eligible_accounts_sorted_by_name_and_preserves_validation_selection(): void
    {
        $this->director();
        $zoe = $this->caseworker(['name' => 'Zoe Caseworker']);
        $alex = $this->caseworker(['name' => 'Alex Caseworker']);
        $this->caseworker(['name' => 'Unverified Caseworker', 'email_verified_at' => null]);
        User::factory()->create(['name' => 'Other Role', 'role' => 'admin']);
        $record = $this->record(['caseworker_id' => $alex->id, 'beneficiary_id' => '000007']);
        $response = $this->get(route('beneficiaries.index'))->assertOk()->assertSee('Assign / Reassign Caseworker')->assertSee('Current assignment');
        $this->assertSame([$alex->id, $zoe->id], $response->viewData('caseworkers')->pluck('id')->all());
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//dialog[@data-assignment-dialog and @aria-labelledby="rlcr-assignment-title" and @aria-describedby]'));
        $this->assertCount(1, $xpath->query('//button[@data-assignment-bulk and @aria-haspopup="dialog"]'));
        $this->assertCount(1, $xpath->query('//button[@data-assignment-row and @aria-haspopup="dialog"]'));
        $this->assertCount(1, $xpath->query('//form[@data-assignment-form]/input[@name="_token"]'));
        $this->assertCount(1, $xpath->query('//select[@data-assignment-destination]/option[@value="unassign" and normalize-space(.)="Unassign"]'));
        $this->assertCount(4, $xpath->query('//select[@data-assignment-destination]/option'));
        $this->assertCount(0, $xpath->query('//select[@data-assignment-destination]/option[contains(., "Unverified") or contains(., "Other Role")]'));
        $this->from(route('beneficiaries.index'))->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => 999999])
            ->assertSessionHasErrors(['caseworker_id'], null, 'assignCaseworker')->assertSessionHasInput('beneficiary_ids', [$record->id]);
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get(route('beneficiaries.index'))
            ->assertOk()->assertSee('Choose an approved caseworker with a verified email');
        $xpath = $this->xpath($response->getContent());
        $state = $xpath->query('//*[@data-assignment-controller]')->item(0);
        $this->assertStringContainsString('if (true)', $state->getAttribute('x-init'));
        $this->assertStringContainsString('ids: '.Js::from([(string) $record->id])->toHtml(), $state->getAttribute('x-data'));
        $this->assertStringContainsString('000007', $state->getAttribute('x-data'));
        $this->assertStringContainsString('Alex Caseworker', $state->getAttribute('x-data'));
        $this->assertSame($alex->id, $record->fresh()->caseworker_id);
    }

    public function test_malformed_old_selection_does_not_break_registry_or_modal_rendering(): void
    {
        $this->director();
        $this->from(route('beneficiaries.index'))->post(route('beneficiaries.assign-caseworker'),
            ['beneficiary_ids' => [['invalid']], 'caseworker_id' => ['invalid']])
            ->assertSessionHasErrors(['beneficiary_ids.0', 'caseworker_id'], null, 'assignCaseworker');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('beneficiaries.index'))->assertOk();
    }

    public function test_caseworkers_only_access_assigned_profiles_and_never_receive_assignment_controls(): void
    {
        $actor = $this->caseworker();
        $other = $this->caseworker();
        $records = [$this->record(), $this->record(['caseworker_id' => $actor->id]), $this->record(['caseworker_id' => $other->id])];
        $this->actingAs($actor);
        $response = $this->get(route('beneficiaries.index'))->assertOk()->assertDontSee('data-assignment-dialog', false)
            ->assertDontSee('data-assignment-row', false)->assertDontSee('data-assignment-bulk', false)
            ->assertDontSee('Assign / Reassign Caseworker')->assertDontSee(route('beneficiaries.assign-caseworker'));
        $this->assertSame(1, $response->viewData('beneficiaries')->total());
        foreach ($records as $record) {
            $status = $record->caseworker_id === $actor->id ? 200 : 403;
            $this->get(route('beneficiaries.show', $record))->assertStatus($status);
            $this->get(route('beneficiaries.edit', $record))->assertStatus($status);
        }
        $this->get(route('beneficiaries.create'))->assertRedirect(route('beneficiaries.index'))
            ->assertSessionHas('error', 'Only Directors can add beneficiaries.');
    }

    public function test_archive_and_restore_keep_assignment_and_card_and_archived_records_cannot_be_reassigned(): void
    {
        $this->director();
        $caseworker = $this->caseworker();
        $record = $this->record(['caseworker_id' => $caseworker->id, 'nfc_uid' => '0027947780']);
        $this->delete(route('beneficiaries.destroy', $record))->assertRedirect(route('beneficiaries.archived'));
        $this->assertSame($caseworker->id, $record->fresh()->caseworker_id);
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => null])
            ->assertSessionHasErrors(['beneficiary_ids'], null, 'assignCaseworker');
        $this->post(route('beneficiaries.restore', $record->id))->assertRedirect(route('beneficiaries.show', $record));
        $this->assertSame($caseworker->id, $record->fresh()->caseworker_id);
        $this->assertSame('0027947780', $record->fresh()->nfc_uid);
    }

    public function test_director_can_assign_a_new_beneficiary_without_making_assignment_mass_assignable(): void
    {
        $this->director();
        $caseworker = $this->caseworker();
        $this->post(route('beneficiaries.store'), ['full_name' => 'New Beneficiary', 'beneficiary_id' => '000008',
            'registered_at' => '2026-10-07', 'caseworker_id' => $caseworker->id])->assertSessionHasNoErrors();
        $this->assertSame($caseworker->id, Beneficiary::where('beneficiary_id', '000008')->sole()->caseworker_id);
        $this->assertNotContains('caseworker_id', (new Beneficiary)->getFillable());
    }

    public function test_migration_leaves_existing_beneficiaries_unassigned_and_supports_rollback(): void
    {
        $record = $this->record(['nfc_uid' => '0027947780']);
        $migration = require database_path('migrations/2026_10_07_000001_add_caseworker_id_to_beneficiaries_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('beneficiaries', 'caseworker_id'));
        $before = $this->snapshot('beneficiaries');
        $migration->up();
        $this->assertTrue(Schema::hasColumn('beneficiaries', 'caseworker_id'));
        $this->assertNull($record->fresh()->caseworker_id);
        $after = array_map(fn ($row) => Arr::except($row, 'caseworker_id'), $this->snapshot('beneficiaries'));
        $this->assertSame($before, $after);
    }

    public function test_deleting_a_caseworker_nulls_assignment_without_deleting_beneficiaries_or_cards(): void
    {
        $caseworker = $this->caseworker();
        $record = $this->record(['caseworker_id' => $caseworker->id, 'nfc_uid' => '0027947780']);
        $caseworker->delete();
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertNull($record->fresh()->caseworker_id);
        $this->assertSame('0027947780', $record->fresh()->nfc_uid);
    }

    public function test_database_foreign_key_rejects_nonexistent_assignment(): void
    {
        $record = $this->record();
        try {
            DB::table('beneficiaries')->where('id', $record->id)->update(['caseworker_id' => 999999]);
            $this->fail('The assignment foreign key should reject a missing user.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('FOREIGN KEY', $error->getMessage());
        }
        $this->assertNull($record->fresh()->caseworker_id);
    }

    public function test_existing_csrf_protection_is_preserved(): void
    {
        $this->director();
        $record = $this->record();
        $this->app['env'] = 'production';
        try {
            $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => null])->assertStatus(419);
            $this->assertNull($record->fresh()->caseworker_id);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public static function unassignmentCases(): array
    {
        return [[false, null], [true, null], [false, 'unassign'], [true, 'unassign']];
    }

    public static function unauthorizedActors(): array
    {
        return [[null, false, 401], ['caseworker', true, 403], ['caseworker', false, 403],
            ['project_director', false, 403], ['admin', true, 403], ['unknown', true, 403]];
    }

    public static function invalidDestinations(): array
    {
        return [['project_director', true, true], ['admin', true, true], ['unknown', true, true],
            ['caseworker', false, true], ['caseworker', true, false]];
    }

    public static function invalidBulkFields(): array
    {
        return [
            'missing selection' => [['beneficiary_ids' => null], 'beneficiary_ids'],
            'empty selection' => [['beneficiary_ids' => []], 'beneficiary_ids'],
            'scalar selection' => [['beneficiary_ids' => '1'], 'beneficiary_ids'],
            'too many' => [['beneficiary_ids' => range(1, 101)], 'beneficiary_ids'],
            'nested ID' => [['beneficiary_ids' => [[]]], 'beneficiary_ids.0'],
            'missing ID' => [['beneficiary_ids' => [null]], 'beneficiary_ids.0'],
            'text ID' => [['beneficiary_ids' => ['invalid']], 'beneficiary_ids.0'],
            'zero ID' => [['beneficiary_ids' => [0]], 'beneficiary_ids.0'],
            'negative ID' => [['beneficiary_ids' => [-1]], 'beneficiary_ids.0'],
            'decimal ID' => [['beneficiary_ids' => [1.5]], 'beneficiary_ids.0'],
            'array destination' => [['caseworker_id' => ['invalid']], 'caseworker_id'],
            'text destination' => [['caseworker_id' => 'invalid'], 'caseworker_id'],
            'zero destination' => [['caseworker_id' => 0], 'caseworker_id'],
            'negative destination' => [['caseworker_id' => -1], 'caseworker_id'],
            'decimal destination' => [['caseworker_id' => 1.5], 'caseworker_id'],
        ];
    }

    public static function duplicateIds(): array
    {
        return [['integer'], ['string'], ['leading zero']];
    }

    public static function unavailableSelections(): array
    {
        return [[false], [true]];
    }

    private function director(): User
    {
        $user = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($user);

        return $user;
    }

    private function caseworker(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'caseworker']);
    }

    private function record(array $attributes = []): Beneficiary
    {
        $record = new Beneficiary;
        $record->forceFill($attributes + ['beneficiary_id' => 'ASSIGN-'.++$this->recordNumber,
            'full_name' => 'Assignment Test Beneficiary', 'registered_at' => '2026-10-07', 'status' => 'active'])->save();

        return $record->refresh();
    }

    private function snapshot(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
