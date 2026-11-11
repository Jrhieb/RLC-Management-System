<?php

namespace Tests\Feature;

use App\Models\ProgramActivity;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportExportIntegrityTest extends TestCase
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
            'id' => 10, 'title' => 'Synthetic activity', 'activity_date' => '2026-06-01',
            'development_area' => 'physical', 'created_by' => $this->other->id,
        ]);
        $this->beforeApplicationDestroyed(function (): void {
            Mail::assertNothingOutgoing();
            Notification::assertNothingSent();
        });
    }

    #[DataProvider('dayBoundaries')]
    public function test_event_dates_and_distinct_totals_agree_across_reports_csv_and_pdf(
        string $day, array $utc, string $displayDay, bool $director
    ): void {
        $this->seedBoundary($utc);
        if ($director) {
            $this->actor->forceFill(['role' => 'project_director'])->save();
        }
        $ids = $director ? [103, 107, 106, 105, 102, 101] : [103, 102, 101];
        $beneficiaryIds = $director ? ['B002', 'B007', 'B006', 'B005', 'B001', 'B001'] : ['B002', 'B001', 'B001'];
        $dates = ['date_from' => $day, 'date_to' => $day];
        $response = $this->get(route('reports.index', $dates))->assertOk()
            ->assertViewHas('attendanceCount', $director ? 6 : 3)
            ->assertViewHas('participatingBeneficiariesCount', $director ? 5 : 2)
            ->assertViewHas('assistanceReleasesCount', $director ? 6 : 3)
            ->assertViewHas('assistedBeneficiariesCount', $director ? 5 : 2)
            ->assertViewHas('attendances', fn ($rows) => $rows->pluck('id')->all() === $ids)
            ->assertViewHas('assistanceTransactions', fn ($rows) => $rows->pluck('id')->all() === $ids);
        foreach (['attendance', 'assistance'] as $type) {
            $params = ['type' => $type] + $dates;
            foreach (['reports.export', 'reports.pdf'] as $route) {
                $response->assertSee(route($route, $params));
            }
            $rows = $this->csv($this->get(route('reports.export', $params))->assertOk()->streamedContent());
            $this->assertSame($beneficiaryIds, $this->column($rows, 'Beneficiary ID'));
            $times = $this->column($rows, $type === 'attendance' ? 'Check-in Time (PHT)' : 'Released At (PHT)');
            $this->assertSame($displayDay.' 23:59:59', $times[0]);
            $this->assertSame($displayDay.' 00:30:00', $times[count($times) - 2]);
            $this->assertSame($displayDay.' 00:00:00', $times[count($times) - 1]);
            $this->expectPdf($type, $ids, $day, $day, [
                $displayDay.' 11:59 PM', $displayDay.' 12:30 AM', $displayDay.' 12:00 AM',
            ]);
            $this->get(route('reports.pdf', $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public static function dayBoundaries(): array
    {
        $month = ['2027-01-31 15:59:59', '2027-01-31 16:00:00', '2027-01-31 16:30:00', '2027-02-01 15:59:59', '2027-02-01 16:00:00'];
        $year = ['2026-12-31 15:59:59', '2026-12-31 16:00:00', '2026-12-31 16:30:00', '2027-01-01 15:59:59', '2027-01-01 16:00:00'];

        return [
            'month Caseworker' => ['2027-02-01', $month, '02/01/2027', false],
            'month Director' => ['2027-02-01', $month, '02/01/2027', true],
            'year Caseworker' => ['2027-01-01', $year, '01/01/2027', false],
            'year Director' => ['2027-01-01', $year, '01/01/2027', true],
        ];
    }

    #[DataProvider('searchTerms')]
    public function test_activity_search_has_the_same_literal_and_wildcard_behavior_in_rows_and_csv(string $search, array $ids): void
    {
        foreach ([1 => 'Quote "O\'Neil"', 2 => 'Percent % symbol', 3 => 'Under_score', 4 => 'Zoë 雪', 5 => 'Plain text'] as $id => $name) {
            $this->beneficiary($id, $name);
            $this->record('attendance', $id, $id, '2027-01-01 16:30:00');
        }
        $this->beneficiary(6, 'Hidden % _ Zoë 雪', $this->other->id);
        $this->record('attendance', 6, 6, '2027-01-01 16:30:00');
        $params = ['activity' => $this->activity, 'search' => $search, 'caseworker_id' => $this->other->id, 'beneficiary_id' => 6];
        $response = $this->get(route('activities.show', $params))->assertOk()
            ->assertViewHas('search', trim($search))
            ->assertViewHas('counts', ['total' => 5, 'manual' => 5, 'test_uid' => 0, 'nfc' => 0])
            ->assertViewHas('attendances', fn ($rows) => $rows->total() === count($ids) && $rows->pluck('id')->all() === $ids)
            ->assertDontSee('Hidden');
        $response->assertSee(route('activities.attendance.export', ['activity' => $this->activity, 'search' => trim($search)]));
        $rows = $this->csv($this->get(route('activities.attendance.export', $params + ['page' => 999]))->assertOk()->streamedContent());
        $this->assertSame(array_map(fn ($id) => sprintf('B%03d', $id), $ids), $this->column($rows, 'Beneficiary ID'));
    }

    public static function searchTerms(): array
    {
        return [
            'quotes' => ['"O\'Neil"', [1]],
            'percent wildcard' => ['%', [5, 4, 3, 2, 1]],
            'underscore wildcard' => ['_', [5, 4, 3, 2, 1]],
            'internal underscore' => ['Under_score', [3]],
            'trim and unicode' => ['  Zoë 雪  ', [4]],
            'bound SQL text' => ["%' OR 1=1 --", []],
            'empty search' => ['', [5, 4, 3, 2, 1]],
        ];
    }

    #[DataProvider('csvKinds')]
    public function test_csv_bytes_headers_escaping_formula_cells_and_leading_zero_ids_keep_the_existing_contract(string $kind): void
    {
        $this->actor->forceFill(['name' => " \t@Recorder"])->save();
        $this->activity->forceFill(['title' => "=Activity, \"quoted\"\n雪"])->save();
        $pairs = [
            ['=1+1', "'=1+1"], ['+legitimate', "'+legitimate"],
            ['-legitimate', "'-legitimate"], ['@legitimate', "'@legitimate"],
            ["\t=SUM(1)", "'\t=SUM(1)"], ["\r=SUM(1)", "'\r=SUM(1)"],
            ["\tplain", "\tplain"], ["\rplain", "\rplain"],
            ["\xEF\xBB\xBF=1", "'\xEF\xBB\xBF=1"],
            ["O\"Brien, Zoë 雪\nSecond line", "O\"Brien, Zoë 雪\nSecond line"],
            ["'=already quoted", "'=already quoted"],
        ];
        $type = $kind === 'assistance' ? 'assistance' : 'attendance';
        foreach ($pairs as $index => [$input]) {
            $id = $index + 1;
            $this->beneficiary($id, $input, identifier: sprintf('%05d', $id));
            $this->record($type, $id, $id, '2027-01-01 16:30:00');
        }
        $dates = ['date_from' => '2027-01-02', 'date_to' => '2027-01-02'];
        $url = $kind === 'activity' ? route('activities.attendance.export', $this->activity)
            : route('reports.export', ['type' => $type] + $dates);
        $filename = $kind === 'activity' ? 'activity-10-attendance-20270116-003000.csv'
            : $type.'-report-2027-01-02-to-2027-01-02.csv';
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename='.$filename)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $content = $response->streamedContent();
        $rows = $this->csv($content);
        $headers = match ($kind) {
            'attendance' => ['Beneficiary Name', 'Beneficiary ID', 'Activity', 'Activity Date', 'Check-in Time (PHT)', 'Source', 'Recorded By'],
            'assistance' => ['Beneficiary Name', 'Beneficiary ID', 'Category', 'Assistance', 'Quantity', 'Released At (PHT)', 'Notes', 'Recorded By'],
            'activity' => ['Activity', 'Activity Date', 'Beneficiary Name', 'Beneficiary ID', 'Status', 'Check-in Time (PHT)', 'Source', 'Recorded By'],
        };
        $headerBytes = match ($kind) {
            'attendance' => '"Beneficiary Name","Beneficiary ID",Activity,"Activity Date","Check-in Time (PHT)",Source,"Recorded By"',
            'assistance' => '"Beneficiary Name","Beneficiary ID",Category,Assistance,Quantity,"Released At (PHT)",Notes,"Recorded By"',
            'activity' => 'Activity,"Activity Date","Beneficiary Name","Beneficiary ID",Status,"Check-in Time (PHT)",Source,"Recorded By"',
        };
        $this->assertSame($headers, $rows[0]);
        $this->assertStringStartsWith("\xEF\xBB\xBF".$headerBytes."\n", $content);
        $this->assertStringEndsWith("\n", $content);
        $this->assertFalse(str_ends_with($content, "\r\n"));
        $this->assertCount(12, $rows);
        $this->assertSame(array_reverse(array_column($pairs, 1)), $this->column($rows, 'Beneficiary Name'));
        $this->assertSame(['00011', '00010', '00009', '00008', '00007', '00006', '00005', '00004', '00003', '00002', '00001'], $this->column($rows, 'Beneficiary ID'));
        $this->assertSame(array_fill(0, 11, "' \t@Recorder"), $this->column($rows, 'Recorded By'));
        $this->assertStringContainsString("\"O\"\"Brien, Zoë 雪\nSecond line\"", $content);
        if ($kind === 'assistance') {
            $this->assertSame(array_fill(0, 11, "'-Legitimate assistance"), $this->column($rows, 'Assistance'));
            $this->assertSame(array_fill(0, 11, "'\r=NOTE(1)"), $this->column($rows, 'Notes'));
            $this->assertSame(array_fill(0, 11, '7'), $this->column($rows, 'Quantity'));
        } else {
            $this->assertSame(array_fill(0, 11, "'=Activity, \"quoted\"\n雪"), $this->column($rows, 'Activity'));
            $this->assertSame(array_fill(0, 11, 'Manual'), $this->column($rows, 'Source'));
            $this->assertSame(array_fill(0, 11, $kind === 'activity' ? '2026-06-01' : '06/01/2026'), $this->column($rows, 'Activity Date'));
        }
    }

    public static function csvKinds(): array
    {
        return [['attendance'], ['assistance'], ['activity']];
    }

    #[DataProvider('defaultDates')]
    public function test_empty_results_and_missing_bounds_use_the_same_pht_defaults_in_every_report_output(array $query, string $from, string $to): void
    {
        $response = $this->get(route('reports.index', $query))->assertOk()
            ->assertViewHas('dateFrom', $from)->assertViewHas('dateTo', $to)
            ->assertViewHas('attendanceCount', 0)->assertViewHas('participatingBeneficiariesCount', 0)
            ->assertViewHas('assistanceReleasesCount', 0)->assertViewHas('assistedBeneficiariesCount', 0)
            ->assertViewHas('attendances', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('assistanceTransactions', fn ($rows) => $rows->total() === 0);
        foreach (['attendance', 'assistance'] as $type) {
            $params = ['type' => $type] + $query;
            foreach (['reports.export', 'reports.pdf'] as $route) {
                $response->assertSee(route($route, ['type' => $type, 'date_from' => $from, 'date_to' => $to]));
            }
            $csv = $this->get(route('reports.export', $params))->assertOk()
                ->assertHeader('Content-Disposition', 'attachment; filename='.$type.'-report-'.$from.'-to-'.$to.'.csv');
            $this->assertCount(1, $this->csv($csv->streamedContent()));
            $this->expectPdf($type, [], $from, $to);
            $this->get(route('reports.pdf', $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        $this->get(route('activities.show', $this->activity))->assertOk()
            ->assertViewHas('counts', ['total' => 0, 'manual' => 0, 'test_uid' => 0, 'nfc' => 0])
            ->assertViewHas('attendances', fn ($rows) => $rows->total() === 0);
        $this->assertCount(1, $this->csv($this->get(route('activities.attendance.export', $this->activity))->assertOk()->streamedContent()));
    }

    public static function defaultDates(): array
    {
        return [
            'both absent' => [[], '2027-01-01', '2027-01-16'],
            'only start' => [['date_from' => '2027-01-05'], '2027-01-05', '2027-01-16'],
            'only end' => [['date_to' => '2027-01-10'], '2027-01-01', '2027-01-10'],
            'empty bounds' => [['date_from' => '', 'date_to' => ''], '2027-01-01', '2027-01-16'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_report_ranges_return_existing_validation_responses_before_export(array $query, string $field): void
    {
        $urls = [route('reports.index', $query)];
        foreach (['attendance', 'assistance'] as $type) {
            foreach (['reports.export', 'reports.pdf'] as $route) {
                $urls[] = route($route, ['type' => $type] + $query);
            }
        }
        Pdf::shouldReceive('loadView')->never();
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->from(route('reports.index'))->get($url)->assertRedirect(route('reports.index'))->assertSessionHasErrors($field);
        }
    }

    public static function invalidDates(): array
    {
        return [
            'reversed' => [['date_from' => '2027-01-02', 'date_to' => '2027-01-01'], 'date_to'],
            'malformed' => [['date_from' => 'tomorrow'], 'date_from'],
            'impossible date' => [['date_to' => '2027-02-30'], 'date_to'],
            'array' => [['date_from' => ['2027-01-01']], 'date_from'],
            'partial future defaults reverse' => [['date_from' => '2027-02-01'], 'date_to'],
        ];
    }

    #[DataProvider('invalidSearches')]
    public function test_malformed_activity_search_is_rejected_consistently_before_rows_or_csv(mixed $search): void
    {
        foreach (['activities.show', 'activities.attendance.export'] as $route) {
            $url = route($route, ['activity' => $this->activity, 'search' => $search]);
            $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('search');
            $this->from(route('activities.show', $this->activity))->get($url)
                ->assertRedirect(route('activities.show', $this->activity))->assertSessionHasErrors('search');
        }
    }

    public static function invalidSearches(): array
    {
        return [['search' => ['array']], [str_repeat('x', 101)]];
    }

    #[DataProvider('paginationValues')]
    public function test_unknown_invalid_and_out_of_range_pages_do_not_change_totals_or_export_scope(array $pages, array $expected): void
    {
        $this->beneficiary(1, 'Only assigned synthetic person');
        $this->beneficiary(2, 'Hidden synthetic person', $this->other->id);
        foreach (['attendance', 'assistance'] as $type) {
            $this->record($type, 1, 1, '2027-01-01 16:30:00');
            $this->record($type, 2, 2, '2027-01-01 16:30:00');
        }
        $params = $pages + ['date_from' => '2027-01-02', 'date_to' => '2027-01-02', 'caseworker_id' => $this->other->id];
        $this->get(route('reports.index', $params))->assertOk()->assertViewHas('attendanceCount', 1)
            ->assertViewHas('assistanceReleasesCount', 1)->assertViewHas('participatingBeneficiariesCount', 1)
            ->assertViewHas('assistedBeneficiariesCount', 1)
            ->assertViewHas('attendances', fn ($rows) => $rows->pluck('id')->all() === $expected)
            ->assertViewHas('assistanceTransactions', fn ($rows) => $rows->pluck('id')->all() === $expected);
        foreach (['attendance', 'assistance'] as $type) {
            $rows = $this->csv($this->get(route('reports.export', ['type' => $type] + $params))->assertOk()->streamedContent());
            $this->assertSame(['B001'], $this->column($rows, 'Beneficiary ID'));
        }
        $this->get(route('activities.show', ['activity' => $this->activity] + $pages))->assertOk()
            ->assertViewHas('counts.total', 1)
            ->assertViewHas('attendances', fn ($rows) => $rows->pluck('id')->all() === $expected);
        $rows = $this->csv($this->get(route('activities.attendance.export', ['activity' => $this->activity] + $pages))->assertOk()->streamedContent());
        $this->assertSame(['B001'], $this->column($rows, 'Beneficiary ID'));
    }

    public static function paginationValues(): array
    {
        return [
            'unknown name' => [['unknown_page' => 999], [1]],
            'negative' => [['attendance_page' => -1, 'assistance_page' => -1, 'page' => -1], [1]],
            'not numeric' => [['attendance_page' => 'invalid', 'assistance_page' => 'invalid', 'page' => 'invalid'], [1]],
            'array' => [['attendance_page' => [2], 'assistance_page' => [2], 'page' => [2]], [1]],
            'out of range' => [['attendance_page' => 999, 'assistance_page' => 999, 'page' => 999], []],
        ];
    }

    public function test_unknown_export_types_and_activity_ids_return_existing_not_found_or_validation_responses(): void
    {
        foreach (['reports.export', 'reports.pdf'] as $route) {
            $this->get(route($route, ['type' => 'unknown']))->assertNotFound();
        }
        $this->getJson(route('attendance.index', ['activity_id' => 999]))->assertUnprocessable()->assertJsonValidationErrors('activity_id');
        $this->get(route('activities.attendance.export', ['activity' => 999]))->assertNotFound();
        $this->getJson(route('attendance.index'))->assertOk()->assertJsonPath('activity_id', null)->assertJsonPath('counts', null);
    }

    private function seedBoundary(array $utc): void
    {
        ProgramActivity::forceCreate([
            'id' => 20, 'title' => 'Second synthetic activity', 'activity_date' => '2026-05-01',
            'development_area' => 'cognitive', 'created_by' => $this->other->id,
        ]);
        foreach (range(1, 7) as $id) {
            $this->beneficiary($id, 'Synthetic boundary person '.$id, $id === 5 ? $this->other->id : $this->actor->id);
        }
        DB::table('beneficiaries')->where('id', 2)->update(['status' => 'inactive']);
        DB::table('beneficiaries')->where('id', 6)->update(['caseworker_id' => null]);
        DB::table('beneficiaries')->where('id', 7)->update(['deleted_at' => '2027-03-01 00:00:00']);
        foreach (['attendance', 'assistance'] as $type) {
            foreach ([
                [100, 3, $utc[0], 10], [101, 1, $utc[1], 10], [102, 1, $utc[2], 20],
                [103, 2, $utc[3], 10], [104, 4, $utc[4], 10],
                [105, 5, $utc[2], 10], [106, 6, $utc[2], 10], [107, 7, $utc[2], 10],
            ] as [$id, $beneficiary, $time, $activity]) {
                // Excluded event rows have an in-range creation timestamp;
                // included event rows were entered years later.
                $created = in_array($id, [100, 104], true) ? $utc[2] : '2030-01-01 00:00:00';
                $this->record($type, $id, $beneficiary, $time, $activity, $created);
            }
        }
    }

    private function beneficiary(int $id, string $name, ?int $owner = null, ?string $identifier = null): void
    {
        DB::table('beneficiaries')->insert([
            'id' => $id, 'beneficiary_id' => $identifier ?? sprintf('B%03d', $id), 'full_name' => $name,
            'caseworker_id' => $owner ?? $this->actor->id, 'status' => 'active', 'registered_at' => '2026-01-01',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function record(string $type, int $id, int $beneficiary, string $time, int $activity = 10, string $created = '2027-01-15 00:00:00'): void
    {
        $common = ['id' => $id, 'beneficiary_id' => $beneficiary, 'recorded_by' => $this->actor->id,
            'created_at' => $created, 'updated_at' => $created];
        DB::table($type === 'attendance' ? 'activity_attendances' : 'assistance_transactions')->insert($common + ($type === 'attendance' ? [
            'program_activity_id' => $activity, 'checked_in_at' => $time, 'source' => 'manual',
        ] : [
            'submission_token' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'category' => 'education', 'item_name' => '-Legitimate assistance', 'quantity' => 7,
            'released_at' => $time, 'notes' => "\r=NOTE(1)",
        ]));
    }

    private function expectPdf(string $type, array $ids, string $from, string $to, array $displayTimes = []): void
    {
        $renderer = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $renderer->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $renderer->shouldReceive('setOption')->once()->with('isRemoteEnabled', false)->andReturnSelf();
        $renderer->shouldReceive('setOption')->once()->with('isPhpEnabled', false)->andReturnSelf();
        $filename = $type.'-report-'.$from.'-to-'.$to.'.pdf';
        $renderer->shouldReceive('download')->once()->with($filename)->andReturn(response('%PDF-isolated-fixture', 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename='.$filename,
        ]));
        Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) use ($renderer, $type, $ids, $from, $to, $displayTimes) {
            $this->assertSame('reports.pdf', $view);
            $this->assertSame($type, $data['type']);
            $this->assertSame($ids, $data['records']->pluck('id')->all());
            $this->assertSame($from, $data['dateFrom']);
            $this->assertSame($to, $data['dateTo']);
            $this->assertSame('Asia/Manila', $data['generatedAt']->timezoneName);
            $this->assertSame('2027-01-16 00:30:00', $data['generatedAt']->format('Y-m-d H:i:s'));
            $this->assertSame($this->actor->name, $data['generatedBy']);
            $html = view($view, $data)->render();
            foreach ($displayTimes as $time) {
                $this->assertStringContainsString($time, $html);
            }

            return $renderer;
        });
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

    private function column(array $rows, string $name): array
    {
        return array_column(array_slice($rows, 1), array_search($name, $rows[0], true));
    }
}
