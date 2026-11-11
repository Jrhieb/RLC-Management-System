<?php

namespace Tests\Feature;

use App\Models\ProgramActivity;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportExportChunkingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $other;

    private ProgramActivity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2027-01-15 16:30:00', 'UTC'));
        $this->actor = User::factory()->create();
        $this->other = User::factory()->create();
        $this->actingAs($this->actor);
        $this->activity = ProgramActivity::forceCreate([
            'id' => 10, 'title' => 'Synthetic organization activity', 'activity_date' => '2026-01-01',
            'development_area' => 'physical', 'created_by' => $this->other->id,
        ]);
        $this->beforeApplicationDestroyed(function (): void {
            Mail::assertNothingOutgoing();
            Notification::assertNothingSent();
        });
    }

    #[DataProvider('csvKinds')]
    public function test_csv_traverses_three_batches_with_ties_and_nonmonotonic_event_ids(string $kind): void
    {
        $type = $kind === 'assistance' ? 'assistance' : 'attendance';
        $this->seedHistory($type, 450);
        // Each time group has 225 records. The newer group has odd IDs, so an
        // ID-only cursor would skip valid even IDs when the time group changes.
        $expected = array_merge(range(449, 1, -2), range(450, 2, -2));
        $this->get(route('reports.index', $this->dates()))->assertOk()
            ->assertViewHas($type === 'attendance' ? 'attendanceCount' : 'assistanceReleasesCount', 450)
            ->assertViewHas($type === 'attendance' ? 'participatingBeneficiariesCount' : 'assistedBeneficiariesCount', 450)
            ->assertViewHas($type === 'attendance' ? 'attendances' : 'assistanceTransactions',
                fn ($rows) => $rows->total() === 450 && $rows->pluck('id')->all() === range(449, 431, -2));
        $pageName = $type === 'attendance' ? 'attendance_page' : 'assistance_page';
        $viewName = $type === 'attendance' ? 'attendances' : 'assistanceTransactions';
        $this->get(route('reports.index', $this->dates() + [$pageName => 2]))->assertOk()
            ->assertViewHas($viewName, fn ($rows) => $rows->pluck('id')->all() === range(429, 411, -2));
        $this->get(route('reports.index', $this->dates() + [$pageName => 45]))->assertOk()
            ->assertViewHas($viewName, fn ($rows) => $rows->pluck('id')->all() === range(20, 2, -2));
        if ($kind === 'activity') {
            $this->get(route('activities.show', $this->activity))->assertOk()
                ->assertViewHas('counts', ['total' => 450, 'manual' => 150, 'test_uid' => 150, 'nfc' => 150]);
        }
        $response = $this->get($this->csvUrl($kind))->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $rows = $this->csv($response->streamedContent());
        $ids = $this->ids($rows);
        $this->assertSame($expected, $ids);
        $this->assertCount(450, array_unique($ids));
        $this->assertSame(1, count(array_filter($rows, fn ($row) => $row === $rows[0])));
        $this->assertSame('000449', $rows[1][array_search('Beneficiary ID', $rows[0], true)]);
    }

    #[DataProvider('csvKinds')]
    public function test_insert_between_csv_batches_does_not_duplicate_or_skip_existing_rows(string $kind): void
    {
        $type = $kind === 'assistance' ? 'assistance' : 'attendance';
        $this->seedHistory($type, 450);
        $table = $type === 'attendance' ? 'activity_attendances' : 'assistance_transactions';
        $inserted = false;
        // A deterministic single-connection interleaving, NOT real concurrency.
        // Insert a newer first-sorting row after the first 200 rows were fetched.
        DB::listen(function (QueryExecuted $query) use (&$inserted, $table, $type): void {
            if (! $inserted && str_starts_with($query->sql, 'select * from "'.$table.'"')
                && str_contains($query->sql, 'limit 200')) {
                $inserted = true;
                DB::table('beneficiaries')->insert($this->beneficiaryRow(2000, $this->actor->id));
                DB::table($table)->insert($this->recordRow($type, 2000, '2027-01-02 00:30:00'));
            }
        });
        $response = $this->get($this->csvUrl($kind))->assertOk();
        $ids = $this->ids($this->csv($response->streamedContent()));
        $this->assertTrue($inserted);
        $this->assertCount(count($ids), array_unique($ids), 'An existing record must never appear twice.');
        $this->assertSame(array_merge(range(449, 1, -2), range(450, 2, -2)), $ids);
    }

    #[DataProvider('reportTypes')]
    public function test_pdf_limit_is_applied_after_role_and_date_filters_and_csv_is_not_truncated(string $type): void
    {
        $this->seedHistory($type, 1001, true);
        $day = ['date_from' => '2027-01-01', 'date_to' => '2027-01-01', 'type' => $type];
        $filename = $type.'-report-2027-01-01-to-2027-01-01.pdf';
        $renderer = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $renderer->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $renderer->shouldReceive('setOption')->once()->with('isRemoteEnabled', false)->andReturnSelf();
        $renderer->shouldReceive('setOption')->once()->with('isPhpEnabled', false)->andReturnSelf();
        $renderer->shouldReceive('download')->once()->with($filename)->andReturn(response('%PDF-isolated-fixture', 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename='.$filename,
        ]));
        Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) use ($renderer) {
            $this->assertSame('reports.pdf', $view);
            $this->assertSame(range(1000, 1), $data['records']->pluck('id')->all());
            $this->assertTrue($data['records']->every(fn ($row) => $row->beneficiary->caseworker_id === $this->actor->id));

            return $renderer;
        });
        $this->get(route('reports.pdf', $day + ['caseworker_id' => $this->other->id]))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Disposition', 'attachment; filename='.$filename);
        // The wider period matches exactly 1,001 OWN rows. It must fail, not
        // silently deliver the first 1,000, and must not call the renderer again.
        $wide = $this->dates() + ['type' => $type];
        $this->getJson(route('reports.pdf', $wide))->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->from(route('reports.index'))->get(route('reports.pdf', $wide))
            ->assertRedirect(route('reports.index'))->assertSessionHasErrors('date_from');
        $this->get(route('reports.index', $this->dates()))->assertOk()
            ->assertViewHas($type === 'attendance' ? 'attendanceCount' : 'assistanceReleasesCount', 1001);
        $csv = $this->get(route('reports.export', $wide))->assertOk()->streamedContent();
        $this->assertSame(array_merge([1001], range(1000, 1)), $this->ids($this->csv($csv)));
        // Director sees the in-range hidden history as well: over the limit.
        $this->actingAs(User::factory()->create(['role' => 'project_director']))
            ->getJson(route('reports.pdf', $day))->assertUnprocessable()->assertJsonValidationErrors('date_from');
    }

    public static function csvKinds(): array
    {
        return [['attendance'], ['assistance'], ['activity']];
    }

    public static function reportTypes(): array
    {
        return [['attendance'], ['assistance']];
    }

    private function seedHistory(string $type, int $count, bool $pdfBoundary = false): void
    {
        $beneficiaries = [];
        $records = [];
        for ($id = 1; $id <= $count; $id++) {
            $beneficiaries[] = $this->beneficiaryRow($id, $this->actor->id);
            $newer = $pdfBoundary ? $id === 1001 : $id % 2 === 1;
            $records[] = $this->recordRow($type, $id, $newer ? '2027-01-02 00:30:00' : '2027-01-01 00:30:00');
        }
        foreach ([[9001, $this->other->id, false], [9002, null, false], [9003, $this->actor->id, true]] as [$id, $owner, $archived]) {
            $beneficiaries[] = $this->beneficiaryRow($id, $owner, $archived);
            $records[] = $this->recordRow($type, $id, '2027-01-01 00:30:00');
        }
        foreach (array_chunk($beneficiaries, 50) as $batch) {
            DB::table('beneficiaries')->insert($batch);
        }
        foreach (array_chunk($records, 50) as $batch) {
            DB::table($type === 'attendance' ? 'activity_attendances' : 'assistance_transactions')->insert($batch);
        }
    }

    private function beneficiaryRow(int $id, ?int $owner, bool $archived = false): array
    {
        return [
            'id' => $id, 'beneficiary_id' => sprintf('%06d', $id), 'full_name' => 'Synthetic export person '.$id,
            'registered_at' => '2026-01-01', 'status' => $id === 1 ? 'inactive' : 'active', 'caseworker_id' => $owner,
            'deleted_at' => $archived ? '2027-01-15 12:00:00' : null,
            'created_at' => '2027-01-15 00:00:00', 'updated_at' => '2027-01-15 00:00:00',
        ];
    }

    private function recordRow(string $type, int $id, string $eventTime): array
    {
        $common = ['id' => $id, 'beneficiary_id' => $id, 'recorded_by' => $this->actor->id,
            'created_at' => '2027-01-15 00:00:00', 'updated_at' => '2027-01-15 00:00:00'];

        return $type === 'attendance' ? $common + [
            'program_activity_id' => $this->activity->id, 'checked_in_at' => $eventTime,
            'source' => ['manual', 'nfc_test', 'nfc'][$id % 3],
        ] : $common + [
            'submission_token' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'category' => 'education', 'item_name' => 'Synthetic export item '.$id,
            'quantity' => 7, 'released_at' => $eventTime, 'notes' => 'Synthetic notes',
        ];
    }

    private function dates(): array
    {
        return ['date_from' => '2027-01-01', 'date_to' => '2027-01-02'];
    }

    private function csvUrl(string $kind): string
    {
        $forged = ['caseworker_id' => $this->other->id, 'beneficiary_id' => 9001, 'page' => 99];

        return $kind === 'activity'
            ? route('activities.attendance.export', ['activity' => $this->activity] + $forged)
            : route('reports.export', ['type' => $kind] + $this->dates() + $forged);
    }

    private function csv(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function ids(array $rows): array
    {
        $column = array_search('Beneficiary ID', $rows[0], true);

        return array_map(fn ($row) => (int) $row[$column], array_slice($rows, 1));
    }
}
