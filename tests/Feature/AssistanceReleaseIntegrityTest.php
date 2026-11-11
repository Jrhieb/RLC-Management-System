<?php

namespace Tests\Feature;

use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssistanceReleaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Beneficiary $beneficiary;

    private int $beneficiaryNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.debug' => false]);
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00', 'UTC'));
        $this->actor = User::factory()->create();
        $this->beneficiary = $this->beneficiary($this->actor);
        $this->actingAs($this->actor);
        $this->beforeApplicationDestroyed(function (): void {
            Mail::assertNothingOutgoing();
            Notification::assertNothingSent();
        });
    }

    public function test_replay_normalizes_token_case_and_preserves_every_original_column(): void
    {
        $data = $this->data();
        $this->post($this->url(), $data)->assertRedirect(route('beneficiaries.show', $this->beneficiary))
            ->assertSessionHas('success', 'Assistance release recorded successfully.');
        $original = $this->snapshot();
        $this->travel(5)->minutes();
        $this->post($this->url(), ['submission_token' => strtoupper($data['submission_token']), 'quantity' => '3'] + $data)
            ->assertRedirect(route('beneficiaries.show', $this->beneficiary))
            ->assertSessionHas('success', 'This release was already saved. No duplicate record was created.');
        $this->assertSame($original, $this->snapshot());
        $this->assertDatabaseCount('assistance_transactions', 1);
    }

    #[DataProvider('changedDetails')]
    public function test_changed_replay_is_generic_and_does_not_modify_the_original(string $field, mixed $value): void
    {
        $data = $this->data();
        $this->post($this->url(), $data)->assertRedirect();
        $original = $this->snapshot();
        $url = $this->url();
        if ($field === 'beneficiary') {
            $url = route('beneficiaries.assistance.store', $this->beneficiary($this->actor));
        } elseif ($field === 'actor') {
            $this->actingAs(User::factory()->create(['role' => 'project_director']));
        } else {
            $data[$field] = $value;
        }
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('submission_token')
            ->assertJsonMissingPath('transaction')->assertJsonMissingPath('beneficiary')
            ->assertDontSee($this->beneficiary->full_name)->assertDontSee('Synthetic private note');
        $this->assertSame($original, $this->snapshot());
    }

    public static function changedDetails(): array
    {
        return [
            ['quantity', 4], ['category', 'healthcare'], ['item_name', 'Different synthetic item'],
            ['notes', 'Different note'], ['released_at', '2026-10-18T00:30'],
            ['beneficiary', null], ['actor', null],
        ];
    }

    public function test_form_conflict_keeps_existing_validation_redirect_behavior(): void
    {
        $data = $this->data();
        $this->post($this->url(), $data)->assertRedirect();
        $original = $this->snapshot();
        $this->from(route('beneficiaries.show', $this->beneficiary))->post($this->url(), ['quantity' => 4] + $data)
            ->assertRedirect(route('beneficiaries.show', $this->beneficiary))->assertSessionHasErrors('submission_token');
        $this->assertSame($original, $this->snapshot());
    }

    public function test_new_token_allows_an_identical_legitimate_separate_release(): void
    {
        $data = $this->data();
        $this->post($this->url(), $data)->assertRedirect();
        $this->post($this->url(), ['submission_token' => (string) Str::uuid()] + $data)->assertRedirect()
            ->assertSessionHas('success', 'Assistance release recorded successfully.');
        $rows = $this->snapshot();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]['id'], $rows[1]['id']);
        $this->assertNotSame($rows[0]['submission_token'], $rows[1]['submission_token']);
        unset($rows[0]['id'], $rows[0]['submission_token'], $rows[1]['id'], $rows[1]['submission_token']);
        $this->assertSame($rows[0], $rows[1]);
    }

    #[DataProvider('ineligibleReplayStates')]
    public function test_replay_rechecks_current_beneficiary_eligibility(string $state, int $status): void
    {
        $data = $this->data();
        $this->post($this->url(), $data)->assertRedirect();
        $original = $this->snapshot();
        match ($state) {
            'inactive' => $this->beneficiary->update(['status' => 'inactive']),
            'archived' => $this->beneficiary->delete(),
            'reassigned' => $this->beneficiary->forceFill(['caseworker_id' => User::factory()->create()->id])->save(),
            'unassigned' => $this->beneficiary->forceFill(['caseworker_id' => null])->save(),
        };
        $this->postJson($this->url(), $data)->assertStatus($status)->assertDontSee('Synthetic private note');
        $this->assertSame($original, $this->snapshot());
    }

    public static function ineligibleReplayStates(): array
    {
        return [['inactive', 403], ['archived', 404], ['reassigned', 403], ['unassigned', 403]];
    }

    public function test_another_owners_token_does_not_disclose_or_change_their_release(): void
    {
        $other = User::factory()->create();
        $hidden = $this->beneficiary($other);
        $data = $this->data(['notes' => 'Other-owner confidential history', 'item_name' => 'Other-owner confidential item']);
        $this->actingAs($other)->post(route('beneficiaries.assistance.store', $hidden), $data)->assertRedirect();
        $original = $this->snapshot();
        $this->actingAs($this->actor)->postJson($this->url(), $this->data(['submission_token' => $data['submission_token']]))
            ->assertUnprocessable()->assertJsonValidationErrors('submission_token')
            ->assertDontSee($hidden->full_name)->assertDontSee($hidden->beneficiary_id)
            ->assertDontSee('Other-owner confidential');
        $this->assertSame($original, $this->snapshot());
    }

    public function test_global_token_index_and_foreign_keys_are_enforced_and_legacy_null_tokens_survive(): void
    {
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertTrue(collect(Schema::getIndexes('assistance_transactions'))->contains(
            fn ($index) => $index['unique'] && $index['columns'] === ['submission_token']
        ));
        $data = $this->data();
        DB::table('assistance_transactions')->insert($this->row($data));
        $other = $this->beneficiary($this->actor);
        try {
            DB::table('assistance_transactions')->insert(['beneficiary_id' => $other->id] + $this->row($data));
            $this->fail('Submission token uniqueness must be global.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertContains('submission_token', $exception->columns);
        }
        foreach (range(1, 2) as $unused) {
            DB::table('assistance_transactions')->insert(['submission_token' => null] + $this->row($data));
        }
        $this->assertDatabaseCount('assistance_transactions', 3);
        foreach (['beneficiary_id', 'recorded_by'] as $foreignKey) {
            try {
                DB::table('assistance_transactions')->insert([$foreignKey => 999999] + $this->row($this->data()));
                $this->fail('An invalid foreign key must fail.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('FOREIGN KEY constraint failed', $exception->getMessage());
            }
        }
        $this->post($this->url(), $this->data())->assertRedirect();
        $this->assertDatabaseCount('assistance_transactions', 4);
    }

    public function test_cancelled_save_is_not_reported_as_a_successful_replay_and_can_be_retried(): void
    {
        $data = $this->data();
        AssistanceTransaction::saving(fn () => false);
        $this->postJson($this->url(), $data)->assertServerError()->assertSessionMissing('success');
        $this->assertDatabaseCount('assistance_transactions', 0);
        AssistanceTransaction::flushEventListeners();
        $this->post($this->url(), $data)->assertRedirect()->assertSessionHas('success', 'Assistance release recorded successfully.');
        $this->assertDatabaseCount('assistance_transactions', 1);
    }

    #[DataProvider('writeFailures')]
    public function test_unrelated_database_failure_never_becomes_a_replay_and_keeps_the_token_retryable(string $failure): void
    {
        $data = $this->data();
        AssistanceTransaction::creating(function (AssistanceTransaction $record) use ($failure): void {
            if ($failure === 'foreign key') {
                $record->beneficiary_id = 999999;

                return;
            }
            // Simulated SQLITE_BUSY, not an actual second process or disk DB.
            $previous = new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
            $previous->errorInfo = ['HY000', 5, 'database is locked'];
            throw new QueryException('sqlite', 'insert into assistance_transactions', [], $previous);
        });
        $this->postJson($this->url(), $data)->assertServerError()->assertSessionMissing('success')
            ->assertJsonMissingPath('transaction');
        $this->assertDatabaseCount('assistance_transactions', 0);
        AssistanceTransaction::flushEventListeners();
        $this->post($this->url(), $data)->assertRedirect()->assertSessionHas('success', 'Assistance release recorded successfully.');
        $this->assertDatabaseCount('assistance_transactions', 1);
    }

    public static function writeFailures(): array
    {
        return [['foreign key'], ['SQLite busy']];
    }

    #[DataProvider('simulatedConflicts')]
    public function test_race_simulation_recovers_only_a_submission_token_unique_conflict(bool $unrelated, bool $changed = false): void
    {
        $data = $this->data();
        $winner = $this->row($data);
        if ($changed) {
            $winner['quantity'] = 4;
        }
        $winnerId = null;
        $attempts = 0;
        $conflictColumns = [];
        // Seed a simulated winner after the empty lookup returns, before insert.
        // This uses one memory connection and proves no multi-process concurrency.
        DB::listen(function (QueryExecuted $query) use (&$winnerId, $winner): void {
            if ($winnerId === null && str_starts_with($query->sql, 'select * from "assistance_transactions"')) {
                $winnerId = DB::table('assistance_transactions')->insertGetId($winner);
            }
        });
        AssistanceTransaction::creating(function () use (&$attempts, &$winnerId, &$conflictColumns, $winner, $unrelated): void {
            $attempts++;
            if ($unrelated) {
                // Force an actual primary-key UNIQUE failure, not a token failure.
                try {
                    DB::table('assistance_transactions')->insert(['id' => $winnerId] + $winner);
                } catch (UniqueConstraintViolationException $exception) {
                    $conflictColumns = $exception->columns;
                    throw $exception;
                }
            }
        });
        $response = $this->postJson($this->url(), $data);
        if ($unrelated) {
            $this->assertSame(['id'], $conflictColumns);
            $response->assertServerError()->assertSessionMissing('success');
        } elseif ($changed) {
            $response->assertUnprocessable()->assertJsonValidationErrors('submission_token')->assertSessionMissing('success');
        } else {
            $response->assertRedirect()->assertSessionHas('success', 'This release was already saved. No duplicate record was created.');
        }
        $this->assertSame(1, $attempts);
        $this->assertDatabaseCount('assistance_transactions', 1);
        $actual = $this->snapshot()[0];
        $expected = ['id' => $winnerId] + $winner;
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    public static function simulatedConflicts(): array
    {
        return ['token race' => [false], 'changed token race' => [false, true], 'unrelated primary key' => [true]];
    }

    #[DataProvider('invalidRoutes')]
    public function test_invalid_beneficiary_route_references_return_404_and_create_nothing(string $id, bool $create): void
    {
        $url = '/beneficiaries/'.$id.'/assistance';
        if ($create) {
            $this->get($url.'/create')->assertNotFound();
        } else {
            $this->postJson($url, $this->data())->assertNotFound();
        }
        $this->assertDatabaseCount('assistance_transactions', 0);
    }

    public static function invalidRoutes(): array
    {
        $cases = [];
        foreach (['999999', 'malformed', 'null', '', '1e0', '1.0'] as $id) {
            foreach ([true, false] as $create) {
                $cases[] = [$id, $create];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_release_payload_returns_validation_errors_without_writes(string $field, mixed $value, bool $missing = false): void
    {
        $data = $this->data([$field => $value]);
        if ($missing) {
            unset($data[$field]);
        }
        $this->postJson($this->url(), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('assistance_transactions', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing token' => ['submission_token', null, true],
            'null token' => ['submission_token', null],
            'malformed token' => ['submission_token', 'not-a-uuid'],
            'wrong-type token' => ['submission_token', ['not-a-uuid']],
            'numeric token' => ['submission_token', 123],
            'overlong token' => ['submission_token', str_repeat('a', 1000)],
            'missing category' => ['category', null, true],
            'null category' => ['category', null],
            'unknown category' => ['category', 'unsupported'],
            'wrong-type category' => ['category', ['education']],
            'missing quantity' => ['quantity', null, true],
            'null quantity' => ['quantity', null],
            'zero quantity' => ['quantity', 0],
            'negative quantity' => ['quantity', -1],
            'fractional quantity' => ['quantity', 1.5],
            'wrong-type quantity' => ['quantity', [1]],
            'boolean quantity' => ['quantity', true],
            'nonnumeric quantity' => ['quantity', 'one'],
            'quantity above maximum' => ['quantity', 100001],
            'oversized quantity' => ['quantity', str_repeat('9', 30)],
            'missing item' => ['item_name', null, true],
            'blank item' => ['item_name', '   '],
            'wrong-type item' => ['item_name', ['item']],
            'overlong item' => ['item_name', str_repeat('i', 256)],
            'wrong-type notes' => ['notes', ['note']],
            'overlong notes' => ['notes', str_repeat('n', 5001)],
            'missing release date' => ['released_at', null, true],
            'invalid calendar date' => ['released_at', '2026-02-30T00:30'],
            'invalid month' => ['released_at', '2026-13-01T00:30'],
            'missing release time' => ['released_at', '2026-10-17'],
            'wrong date separator' => ['released_at', '2026/10/17 00:30'],
            'unsupported seconds' => ['released_at', '2026-10-17T00:30:00'],
            'unsupported timezone suffix' => ['released_at', '2026-10-17T00:30+08:00'],
            'wrong-type release date' => ['released_at', ['2026-10-17T00:30']],
        ];
    }

    #[DataProvider('validBounds')]
    public function test_existing_categories_quantity_bounds_and_optional_notes_remain_valid(string $category, int|string $quantity, ?string $notes): void
    {
        $this->post($this->url(), $this->data([
            'category' => $category, 'quantity' => $quantity, 'notes' => $notes,
            'item_name' => str_repeat('i', 255),
        ]))->assertRedirect()->assertSessionHas('success', 'Assistance release recorded successfully.');
        $row = AssistanceTransaction::sole();
        $this->assertSame($category, $row->category);
        $this->assertSame((int) $quantity, $row->quantity);
        $this->assertSame($notes === '' ? null : $notes, $row->notes);
    }

    public static function validBounds(): array
    {
        return [
            ['education', 1, str_repeat('n', 5000)], ['healthcare', 100000, null],
            ['gifts', '3', 'Synthetic notes'], ['other', 5, ''],
        ];
    }

    #[DataProvider('releaseDates')]
    public function test_staff_release_dates_cross_utc_pht_boundary_and_audit_fields_cannot_be_spoofed(string $pht, string $utc): void
    {
        $this->travelTo(Carbon::parse('2030-06-15 10:20:00', 'UTC'));
        $other = User::factory()->create();
        $hidden = $this->beneficiary($other);
        $this->post($this->url(), $this->data(['released_at' => $pht]) + [
            'id' => 999999, 'beneficiary_id' => $hidden->id, 'recorded_by' => $other->id,
            'user_id' => $other->id, 'caseworker_id' => $other->id, 'role' => 'project_director',
            'created_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-01 00:00:00',
            'status' => 'inactive', 'unsupported_state' => 'void',
        ])->assertRedirect(route('beneficiaries.show', $this->beneficiary));
        $row = AssistanceTransaction::sole();
        $this->assertNotSame(999999, $row->id);
        $this->assertSame($this->beneficiary->id, $row->beneficiary_id);
        $this->assertSame($this->actor->id, $row->recorded_by);
        $this->assertSame($utc, $row->getRawOriginal('released_at'));
        $this->assertSame('2030-06-15 10:20:00', $row->getRawOriginal('created_at'));
        $this->assertSame('2030-06-15 10:20:00', $row->getRawOriginal('updated_at'));
        $this->assertSame(str_replace(' ', 'T', $utc).'.000000Z', $row->toArray()['released_at']);
        $this->assertSame($this->actor->id, $this->beneficiary->fresh()->caseworker_id);
        $this->assertSame('active', $this->beneficiary->fresh()->status);
        $this->assertArrayNotHasKey('unsupported_state', $row->getAttributes());
        $display = Carbon::createFromFormat('!Y-m-d\TH:i', $pht, 'Asia/Manila');
        $this->get(route('beneficiaries.show', $this->beneficiary))->assertOk()
            ->assertSee($display->format('M d, Y · g:i A').' PHT');
        $dates = ['date_from' => $display->toDateString(), 'date_to' => $display->toDateString()];
        $this->get(route('reports.index', $dates))->assertOk()->assertViewHas('assistanceReleasesCount', 1);
        $previousDay = $display->copy()->subDay()->toDateString();
        $this->get(route('reports.index', ['date_from' => $previousDay, 'date_to' => $previousDay]))
            ->assertOk()->assertViewHas('assistanceReleasesCount', 0);
        $params = $dates + ['type' => 'assistance'];
        $csv = $this->get(route('reports.export', $params))->assertOk()->streamedContent();
        $this->assertStringContainsString($display->format('m/d/Y H:i:s'), $csv);
        Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) use ($display, $utc) {
            $this->assertCount(1, $data['records']);
            $this->assertSame($utc, $data['records']->sole()->getRawOriginal('released_at'));
            $html = view($view, $data)->render();
            $this->assertStringContainsString($display->format('m/d/Y g:i A'), $html);
            $renderer = new \Barryvdh\DomPDF\PDF(app('dompdf'), app('config'), app('files'), app('view'));

            return $renderer->loadHTML($html);
        });
        $pdf = $this->get(route('reports.pdf', $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public static function releaseDates(): array
    {
        return [
            'backdated release' => ['2027-01-01T00:30', '2026-12-31 16:30:00'],
            'future release' => ['2031-01-01T00:30', '2030-12-31 16:30:00'],
        ];
    }

    private function data(array $overrides = []): array
    {
        return $overrides + [
            'submission_token' => (string) Str::uuid(), 'category' => 'education',
            'item_name' => 'Synthetic item', 'quantity' => 3, 'released_at' => '2026-10-17T00:30',
            'notes' => 'Synthetic private note',
        ];
    }

    private function row(array $data): array
    {
        return [
            'beneficiary_id' => $this->beneficiary->id, 'submission_token' => strtolower($data['submission_token']),
            'category' => $data['category'], 'item_name' => $data['item_name'], 'quantity' => (int) $data['quantity'],
            'released_at' => Carbon::createFromFormat('!Y-m-d\TH:i', $data['released_at'], 'Asia/Manila')->utc()->toDateTimeString(),
            'notes' => $data['notes'], 'recorded_by' => $this->actor->id,
            'created_at' => now('UTC')->toDateTimeString(), 'updated_at' => now('UTC')->toDateTimeString(),
        ];
    }

    private function beneficiary(User $owner): Beneficiary
    {
        $number = ++$this->beneficiaryNumber;

        return Beneficiary::forceCreate([
            'beneficiary_id' => 'ASSIST-SYNTHETIC-'.$number, 'full_name' => 'Synthetic Assistance Person '.$number,
            'registered_at' => '2026-10-01', 'status' => 'active', 'caseworker_id' => $owner->id,
        ]);
    }

    private function url(): string
    {
        return route('beneficiaries.assistance.store', $this->beneficiary);
    }

    private function snapshot(): array
    {
        return DB::table('assistance_transactions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
}
