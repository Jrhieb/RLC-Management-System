<?php

namespace Tests\Feature;

use App\Models\ActivityAttendance;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceTimestampIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private ProgramActivity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::factory()->create();
        $this->activity = ProgramActivity::forceCreate([
            'title' => 'Synthetic year boundary activity', 'activity_date' => '2027-01-01',
            'development_area' => 'physical', 'created_by' => User::factory()->create()->id,
        ]);
        $this->actingAs($this->actor);
        $this->beforeApplicationDestroyed(fn () => Mail::assertNothingOutgoing());
    }

    #[DataProvider('paths')]
    public function test_server_audit_values_are_utc_and_pht_display_crosses_the_year_boundary(string $path, string $source): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC'));
        $beneficiary = $this->beneficiary('BOUNDARY-001', 'A0000001');
        $forgedActor = User::factory()->create();
        $this->postJson(route($path, $this->activity), $this->input($path, $beneficiary) + [
            'source' => 'nfc', 'recorded_by' => $forgedActor->id, 'user_id' => $forgedActor->id,
            'role' => 'project_director', 'caseworker_id' => $forgedActor->id,
            'program_activity_id' => 999999, 'activity_id' => 999999,
            'checked_in_at' => '2000-01-01 00:00:00', 'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ])->assertCreated()->assertJsonPath('source', $source)
            ->assertJsonPath('activity.id', $this->activity->id)
            ->assertJsonPath('checked_in_at', 'Jan 01, 2027 - 12:30 AM');

        $row = ActivityAttendance::sole();
        foreach (['checked_in_at', 'created_at', 'updated_at'] as $column) {
            $this->assertSame('2026-12-31 16:30:00', $row->getRawOriginal($column));
        }
        $this->assertSame($source, $row->source);
        $this->assertSame($this->actor->id, $row->recorded_by);
        $this->assertSame($beneficiary->id, $row->beneficiary_id);
        $this->assertSame($this->activity->id, $row->program_activity_id);
        $this->assertSame('2026-12-31T16:30:00.000000Z', $row->toArray()['checked_in_at']);
        $this->get(route('activities.show', $this->activity))->assertOk()->assertSee('Jan 01, 2027 - 12:30 AM');
        $this->get(route('beneficiaries.show', $beneficiary))->assertOk()->assertSee('Jan 01, 2027 · 12:30 AM');
        $csv = $this->get(route('activities.attendance.export', $this->activity))->assertOk()->streamedContent();
        $this->assertStringContainsString('2027-01-01 00:30:00', $csv);
    }

    #[DataProvider('paths')]
    public function test_reports_use_the_complete_manila_day_across_the_year_boundary(string $path, string $source): void
    {
        foreach ([
            ['BEFORE-START', '2026-12-31 15:59:59'],
            ['AT-START', '2026-12-31 16:00:00'],
            ['BEFORE-END', '2027-01-01 15:59:59'],
            ['AT-END', '2027-01-01 16:00:00'],
        ] as $number => [$identifier, $utc]) {
            $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
            $beneficiary = $this->beneficiary($identifier, sprintf('A%07d', $number));
            $this->postJson(route($path, $this->activity), $this->input($path, $beneficiary))->assertCreated();
        }
        $dates = ['date_from' => '2027-01-01', 'date_to' => '2027-01-01'];
        $this->get(route('reports.index', $dates))->assertOk()
            ->assertViewHas('attendanceCount', 2)->assertViewHas('participatingBeneficiariesCount', 2)
            ->assertViewHas('attendances', fn ($rows) => $rows->pluck('beneficiary.beneficiary_id')->all() === ['BEFORE-END', 'AT-START'])
            ->assertSee('Jan 01, 2027 - 12:00 AM')->assertSee('Jan 01, 2027 - 11:59 PM')
            ->assertDontSee('BEFORE-START')->assertDontSee('AT-END');
        $params = $dates + ['type' => 'attendance'];
        $csv = $this->get(route('reports.export', $params))->assertOk()->streamedContent();
        $this->assertStringContainsString('AT-START', $csv);
        $this->assertStringContainsString('BEFORE-END', $csv);
        $this->assertStringContainsString('01/01/2027 00:00:00', $csv);
        $this->assertStringContainsString('01/01/2027 23:59:59', $csv);
        $this->assertStringNotContainsString('BEFORE-START', $csv);
        $this->assertStringNotContainsString('AT-END', $csv);

        Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) use ($source) {
            $this->assertSame(['BEFORE-END', 'AT-START'], $data['records']->pluck('beneficiary.beneficiary_id')->all());
            $this->assertTrue($data['records']->every(fn ($record) => $record->source === $source));
            $html = view($view, $data)->render();
            $this->assertStringContainsString('01/01/2027 12:00 AM', $html);
            $this->assertStringContainsString('01/01/2027 11:59 PM', $html);
            $this->assertStringNotContainsString('BEFORE-START', $html);
            $this->assertStringNotContainsString('AT-END', $html);
            $renderer = new \Barryvdh\DomPDF\PDF(app('dompdf'), app('config'), app('files'), app('view'));

            return $renderer->loadHTML($html);
        });
        $pdf = $this->get(route('reports.pdf', $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public static function paths(): array
    {
        return [
            'manual' => ['activities.attendance.store', 'manual'],
            'NFC' => ['activities.attendance.nfc', 'nfc_test'],
        ];
    }

    private function beneficiary(string $identifier, string $uid): Beneficiary
    {
        return Beneficiary::forceCreate([
            'beneficiary_id' => $identifier, 'full_name' => 'Synthetic '.$identifier,
            'registered_at' => '2026-10-01', 'status' => 'active',
            'caseworker_id' => $this->actor->id, 'nfc_uid' => $uid,
        ]);
    }

    private function input(string $path, Beneficiary $beneficiary): array
    {
        return $path === 'activities.attendance.nfc'
            ? ['nfc_uid' => $beneficiary->nfc_uid] : ['beneficiary_id' => $beneficiary->beneficiary_id];
    }
}
