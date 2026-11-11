<?php

namespace Tests\Feature;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssignedBeneficiaryAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $first;

    private User $second;

    private User $empty;

    private ProgramActivity $activity;

    private array $records;

    protected function setUp(): void
    {
        // TestCase guards SQLite :memory: before RefreshDatabase and fakes mail.
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
        Storage::fake('local');
        $this->director = User::factory()->create(['role' => 'project_director', 'name' => 'Synthetic Director']);
        $this->first = User::factory()->create(['name' => 'Synthetic Caseworker One']);
        $this->second = User::factory()->create(['name' => 'Synthetic Caseworker Two']);
        $this->empty = User::factory()->create(['name' => 'Synthetic Empty Caseworker']);
        $this->activity = ProgramActivity::forceCreate([
            'title' => 'Synthetic Organization Activity', 'activity_date' => '2026-10-14',
            'development_area' => 'physical', 'created_by' => $this->director->id,
        ]);
        $this->records = [];
        foreach ([
            'own' => [$this->first->id, 'active', 'A0000001'],
            'inactive' => [$this->first->id, 'inactive', 'A0000002'],
            'other' => [$this->second->id, 'active', 'B0000001'],
            'unassigned' => [null, 'active', 'C0000001'],
            'archived' => [$this->first->id, 'active', 'D0000001'],
        ] as $label => [$owner, $status, $uid]) {
            $record = new Beneficiary;
            $record->forceFill([
                'beneficiary_id' => '000-SYNTHETIC-'.$label, 'full_name' => 'Synthetic Person '.$label,
                'caseworker_id' => $owner, 'status' => $status, 'registered_at' => '2026-10-01',
                'nfc_uid' => $uid, 'photo_path' => 'beneficiary-photos/synthetic-'.$label.'.png',
            ])->save();
            Storage::disk('local')->put($record->photo_path, 'synthetic-photo-'.$label);
            ActivityAttendance::create([
                'program_activity_id' => $this->activity->id, 'beneficiary_id' => $record->id,
                'checked_in_at' => now('UTC'), 'source' => $label === 'own' ? 'manual' : 'nfc_test',
                'recorded_by' => $this->director->id,
            ]);
            AssistanceTransaction::create([
                'beneficiary_id' => $record->id, 'submission_token' => (string) Str::uuid(),
                'category' => 'education', 'item_name' => 'Synthetic item '.$label,
                'quantity' => 1, 'released_at' => now('UTC'), 'notes' => 'Synthetic history '.$label,
                'recorded_by' => $this->director->id,
            ]);
            if ($label === 'archived') {
                $record->delete();
            }
            $this->records[$label] = $record;
        }
    }

    public function test_policies_and_scopes_fail_closed_and_directors_retain_history(): void
    {
        foreach ([$this->director, $this->first, $this->second, $this->empty, null,
            User::factory()->pendingApproval()->create(),
            User::factory()->create(['approval_status' => 'rejected']),
            User::factory()->unverified()->create(),
            User::factory()->create(['role' => 'applicant']),
        ] as $user) {
            $expected = $user?->isProjectDirector() ? 5 : match ($user?->id) {
                $this->first->id => 2, $this->second->id => 1, default => 0,
            };
            $this->assertSame($expected, Beneficiary::withTrashed()->visibleTo($user)->count());
            $this->assertSame($expected, ActivityAttendance::visibleTo($user)->count());
            $this->assertSame($expected, AssistanceTransaction::visibleTo($user)->count());
            foreach ($this->records as $label => $record) {
                $allowed = $user?->canAccessStaff() && $label !== 'archived'
                    && ($user->isProjectDirector() || $record->caseworker_id === $user->id);
                $this->assertSame((bool) $allowed, Gate::forUser($user)->allows('view', $record));
                $this->assertSame((bool) $allowed, Gate::forUser($user)->allows('update', $record));
            }
        }
    }

    public function test_registry_my_caseload_fragments_filters_and_hidden_data_use_the_authenticated_owner(): void
    {
        foreach ([[$this->first, 2], [$this->second, 1]] as [$user, $total]) {
            $this->actingAs($user);
            foreach (['beneficiaries.index', 'my-caseload.index'] as $route) {
                foreach ([[], ['caseworker' => 'unassigned'], ['caseworker' => $this->director->id],
                    ['caseworker' => [$this->second->id], 'owner_id' => $this->second->id,
                        'caseworker_id' => $this->second->id, 'withTrashed' => 1, 'archived' => 1],
                ] as $forged) {
                    $response = $this->get(route($route, $forged))->assertOk()
                        ->assertViewHas('beneficiaries', fn ($rows) => $rows->total() === $total
                            && $rows->every(fn ($row) => $row->caseworker_id === $user->id && ! $row->trashed()));
                    $this->assertHiddenRecordsAbsent($response->getContent(), $user);
                    $fragment = $this->get(route($route, $forged), ['X-Registry-Fragment' => 'results'])->assertOk();
                    $this->assertHiddenRecordsAbsent($fragment->getContent(), $user);
                    $fragment->assertDontSee('data-assignment-row')->assertDontSee('<html', false);
                }
                $this->get(route($route, ['status' => 'inactive']))->assertOk()
                    ->assertViewHas('beneficiaries', fn ($rows) => $rows->total() === ($user->is($this->first) ? 1 : 0));
                $this->get(route($route, ['search' => $this->records['unassigned']->beneficiary_id]))->assertOk()
                    ->assertViewHas('beneficiaries', fn ($rows) => $rows->total() === 0);
            }
        }
    }

    public function test_visibility_is_applied_before_pagination_and_suggestion_limits(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $record = new Beneficiary;
            $record->forceFill([
                'beneficiary_id' => 'SYNTHETIC-PAGE-'.$i, 'full_name' => 'A Synthetic Page '.sprintf('%02d', $i),
                'registered_at' => '2026-10-01', 'status' => 'active', 'caseworker_id' => $this->first->id,
            ])->save();
        }
        $this->actingAs($this->second)->get(route('beneficiaries.index'))->assertOk()
            ->assertViewHas('beneficiaries', fn ($rows) => $rows->total() === 1 && $rows->count() === 1);
        $this->getJson(route('beneficiaries.suggestions', ['search' => 'Synthetic']))->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $this->records['other']->id);
        $this->actingAs($this->first)->get(route('beneficiaries.index', ['page' => 2]))->assertOk()
            ->assertViewHas('beneficiaries', fn ($rows) => $rows->total() === 16 && $rows->count() === 6);
        $this->getJson(route('beneficiaries.suggestions', ['search' => 'Synthetic']))->assertOk()->assertJsonCount(8, 'results');
        foreach (['unassigned', (string) $this->second->id] as $owner) {
            $this->getJson(route('beneficiaries.suggestions', ['search' => 'Synthetic', 'caseworker' => $owner]))
                ->assertOk()->assertExactJson(['results' => []]);
        }
    }

    public function test_folder_cards_only_contain_the_caseworkers_own_total(): void
    {
        $response = $this->actingAs($this->first)->get(route('beneficiaries.index', ['tab' => 'caseloads']))->assertOk()
            ->assertViewHas('unassignedCount', 0)
            ->assertViewHas('registryCaseworkers', fn ($accounts) => $accounts->pluck('id')->all() === [$this->first->id]
                && $accounts->first()->assigned_beneficiaries_count === 2)
            ->assertSee('rlcr-folder-art')->assertDontSee($this->second->name)->assertDontSee('data-caseload="unassigned"', false);
        $this->assertHiddenRecordsAbsent($response->getContent(), $this->first);
        $this->actingAs($this->director)->get(route('beneficiaries.index', ['tab' => 'caseloads']))->assertOk()
            ->assertViewHas('unassignedCount', 1)->assertSee($this->first->name)->assertSee($this->second->name)
            ->assertSee('data-caseload="unassigned"', false);
    }

    public function test_dashboard_preview_counts_and_notifications_exclude_hidden_history_but_keep_activities(): void
    {
        $this->actingAs($this->first);
        $response = $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('caseloadCounts', ['total' => 2, 'active' => 1, 'inactive' => 1])
            ->assertSee($this->activity->title);
        $this->assertHiddenRecordsAbsent($response->getContent(), $this->first);
        $response = $this->getJson(route('dashboard.caseload'))->assertOk()
            ->assertJsonPath('counts', ['total' => 2, 'active' => 1, 'inactive' => 1]);
        $this->assertHiddenRecordsAbsent($response->getContent(), $this->first);
        // Newer hidden events must not consume the visible query's eight-row limit.
        for ($i = 0; $i < 10; $i++) {
            AssistanceTransaction::create([
                'beneficiary_id' => $this->records['other']->id, 'submission_token' => (string) Str::uuid(),
                'category' => 'education', 'item_name' => 'Hidden synthetic extra '.$i,
                'quantity' => 1, 'released_at' => now(), 'recorded_by' => $this->director->id,
            ]);
        }
        $response = $this->getJson(route('dashboard.notifications'))->assertOk()->assertJsonCount(5, 'items');
        $this->assertHiddenRecordsAbsent($response->getContent(), $this->first);
        $response->assertSee($this->activity->title)->assertSee('Synthetic item own')->assertDontSee('Hidden synthetic extra');
        $this->actingAs($this->director)->get(route('dashboard'))->assertOk()
            ->assertViewHas('activeBeneficiariesCount', 3)->assertViewHas('attendanceCount', 5)
            ->assertViewHas('assistanceReleasesCount', 15);
    }

    #[DataProvider('profileTargets')]
    public function test_profile_edit_photo_and_assistance_access(string $target, int $status): void
    {
        $record = $this->records[$target];
        $this->actingAs($this->first);
        foreach (['beneficiaries.show', 'beneficiaries.edit', 'beneficiaries.photo', 'beneficiaries.assistance.create'] as $route) {
            $response = $this->getJson(route($route, $record))->assertStatus($status);
            if ($status !== 200) {
                $response->assertDontSee($record->full_name)->assertDontSee($record->beneficiary_id)
                    ->assertDontSee($record->photo_path)->assertDontSee('Synthetic history '.$target);
            }
        }
        if ($status === 200) {
            $this->get(route('beneficiaries.show', $record))->assertOk()->assertSee('Synthetic history own');
            $this->get(route('beneficiaries.photo', $record))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->putJson(route('beneficiaries.update', $record), $this->updateData($record))->assertStatus($status === 200 ? 302 : $status);
        $this->postJson(route('beneficiaries.assistance.store', $record), $this->assistanceData())
            ->assertStatus($status === 200 ? 302 : $status);
    }

    public static function profileTargets(): array
    {
        return ['own' => ['own', 200], 'other owner' => ['other', 403], 'unassigned' => ['unassigned', 403], 'archive' => ['archived', 404]];
    }

    public function test_caseworker_update_cannot_reassign_or_unassign_and_bulk_actions_remain_director_only(): void
    {
        $record = $this->records['own'];
        $this->actingAs($this->first)->putJson(route('beneficiaries.update', $record),
            $this->updateData($record) + ['caseworker_id' => $this->second->id, 'deleted_at' => now()])->assertRedirect();
        $this->assertSame($this->first->id, $record->fresh()->caseworker_id);
        $this->assertFalse($record->fresh()->trashed());
        foreach (['assign-caseworker', 'bulk-archive', 'bulk-restore'] as $action) {
            $this->postJson(route('beneficiaries.'.$action), [
                'beneficiary_ids' => array_map(fn ($record) => $record->id, $this->records),
                'caseworker_id' => $this->first->id,
            ])->assertForbidden();
        }
        $this->deleteJson(route('beneficiaries.destroy', $record))->assertForbidden();
        $this->postJson(route('beneficiaries.restore', $this->records['archived']->id))->assertForbidden();
        $this->getJson(route('beneficiaries.archived-photo', $this->records['archived']->id))->assertForbidden();
        $this->getJson(route('account-requests.index'))->assertForbidden();
        $this->assertDatabaseCount('beneficiaries', 5);
        $this->assertSame($this->second->id, $this->records['other']->fresh()->caseworker_id);
    }

    public function test_nfc_registration_unlink_lookup_and_both_attendance_submissions_require_current_assignment(): void
    {
        $this->actingAs($this->first);
        $newActivity = ProgramActivity::forceCreate([
            'title' => 'Synthetic New Activity', 'activity_date' => '2026-10-14',
            'development_area' => 'physical', 'created_by' => $this->director->id,
        ]);
        foreach (['other', 'unassigned', 'archived'] as $target) {
            $record = $this->records[$target];
            $status = $target === 'archived' ? 404 : 403;
            $this->putJson(route('beneficiaries.nfc.update', $record), ['nfc_uid' => 'F0000001'])->assertStatus($status);
            $this->deleteJson(route('beneficiaries.nfc.destroy', $record))->assertStatus($status);
            $this->postJson(route('nfc.lookup'), ['nfc_uid' => $record->nfc_uid])->assertUnprocessable()->assertDontSee($record->full_name);
            $this->postJson(route('activities.attendance.nfc', $newActivity), ['nfc_uid' => $record->nfc_uid])
                ->assertUnprocessable()->assertDontSee($record->full_name);
            $this->postJson(route('activities.attendance.store', $newActivity), ['beneficiary_id' => $record->beneficiary_id])
                ->assertUnprocessable()->assertDontSee($record->full_name);
            $this->assertSame($record->nfc_uid, $record->fresh()->nfc_uid);
        }
        $own = $this->records['own'];
        $this->postJson(route('nfc.lookup'), ['nfc_uid' => $own->nfc_uid])->assertRedirect(route('beneficiaries.show', $own));
        $this->postJson(route('activities.attendance.store', $newActivity), ['beneficiary_id' => $own->beneficiary_id])
            ->assertCreated()->assertJsonPath('beneficiary.beneficiary_id', $own->beneficiary_id);
        $this->postJson(route('activities.attendance.nfc', $newActivity), ['nfc_uid' => $own->nfc_uid])->assertOk()->assertJsonPath('result', 'duplicate');
        $this->deleteJson(route('beneficiaries.nfc.destroy', $own))->assertRedirect();
        $this->assertNull($own->fresh()->nfc_uid);
        $this->putJson(route('beneficiaries.nfc.update', $own), ['nfc_uid' => 'F0000001'])->assertOk()->assertJsonPath('status', 'registered');
        $response = $this->putJson(route('beneficiaries.nfc.update', $own), ['nfc_uid' => $this->records['other']->nfc_uid]);
        $response->assertUnprocessable()->assertDontSee($this->records['other']->full_name)
            ->assertDontSee($this->records['other']->beneficiary_id);
        $this->assertSame('F0000001', $own->fresh()->nfc_uid);
        $this->assertDatabaseCount('activity_attendances', 6);
    }

    public function test_attendance_rows_search_and_source_counts_are_scoped_while_activities_stay_organization_wide(): void
    {
        $this->actingAs($this->first);
        foreach (['activities.show' => ['activity' => $this->activity], 'attendance.index' => ['activity_id' => $this->activity->id]] as $route => $params) {
            $response = $this->get(route($route, $params))->assertOk()
                ->assertViewHas('counts', ['total' => 2, 'manual' => 1, 'test_uid' => 1, 'nfc' => 0])
                ->assertViewHas('attendances', fn ($rows) => $rows->total() === 2)->assertSee($this->activity->title);
            $this->assertHiddenRecordsAbsent($response->getContent(), $this->first);
            $response = $this->getJson(route($route, $params + ['search' => $this->records['other']->beneficiary_id]))
                ->assertOk()->assertJsonPath('counts.total', 2);
            $this->assertStringNotContainsString('Synthetic Person', $response->json('records_html'));
            $this->assertHiddenRecordsAbsent($response->getContent(), $this->first);
        }
        $this->get(route('activities.index'))->assertOk()->assertSee($this->activity->title);
        $this->get(route('activities.edit', $this->activity))->assertOk();
        $this->actingAs($this->director)->getJson(route('activities.show', $this->activity))->assertOk()->assertJsonPath('counts.total', 5);
    }

    #[DataProvider('reportActors')]
    public function test_reports_csv_and_real_pdf_downloads_only_include_visible_records(string $actor, int $total): void
    {
        $user = $this->{$actor};
        $this->actingAs($user);
        $response = $this->get(route('reports.index', $this->reportDates()))->assertOk()
            ->assertViewHas('attendanceCount', $total)->assertViewHas('participatingBeneficiariesCount', $total)
            ->assertViewHas('assistanceReleasesCount', $total)->assertViewHas('assistedBeneficiariesCount', $total)
            ->assertViewHas('attendances', fn ($rows) => $rows->total() === $total)
            ->assertViewHas('assistanceTransactions', fn ($rows) => $rows->total() === $total);
        if (! $user->isProjectDirector()) {
            $this->assertHiddenRecordsAbsent($response->getContent(), $user);
        }
        $export = $this->get(route('activities.attendance.export', $this->activity))->assertOk();
        $this->assertExportMembership($export->streamedContent(), $user, $total);
        // Inspect the exact HTML/data passed to the real installed PDF renderer.
        Pdf::shouldReceive('loadView')->twice()->andReturnUsing(function ($view, $data) use ($user, $total) {
            $this->assertSame($total, $data['records']->count());
            $html = view($view, $data)->render();
            if (! $user->isProjectDirector()) {
                $this->assertHiddenRecordsAbsent($html, $user);
                $this->assertTrue($data['records']->every(fn ($row) => $row->beneficiary->caseworker_id === $user->id
                    && ! $row->beneficiary->trashed()));
            }

            $renderer = new \Barryvdh\DomPDF\PDF(app('dompdf'), app('config'), app('files'), app('view'));

            return $renderer->loadHTML($html);
        });
        foreach (['attendance', 'assistance'] as $type) {
            $params = $this->reportDates() + ['type' => $type, 'caseworker_id' => $this->director->id];
            $export = $this->get(route('reports.export', $params))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
            $this->assertExportMembership($export->streamedContent(), $user, $total);
            $pdf = $this->get(route('reports.pdf', $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        }
    }

    public static function reportActors(): array
    {
        return [['director', 5], ['first', 2], ['second', 1], ['empty', 0]];
    }

    public function test_reassignment_and_unassignment_revoke_all_prior_access_on_subsequent_requests(): void
    {
        $record = $this->records['own'];
        // Check the data actually passed to the installed PDF renderer after
        // each owner change, as compressed PDF bytes alone can hide leaked text.
        Pdf::shouldReceive('loadView')->times(4)->andReturnUsing(function ($view, $data) {
            $user = auth()->user();
            $this->assertCount(1, $data['records']);
            $this->assertTrue($data['records']->every(fn ($row) => $row->beneficiary->caseworker_id === $user->id
                && ! $row->beneficiary->trashed()));
            $html = view($view, $data)->render();
            $this->assertHiddenRecordsAbsent($html, $user);
            $renderer = new \Barryvdh\DomPDF\PDF(app('dompdf'), app('config'), app('files'), app('view'));

            return $renderer->loadHTML($html);
        });
        $this->actingAs($this->director)->post(route('beneficiaries.assign-caseworker'), [
            'beneficiary_ids' => [$record->id], 'caseworker_id' => $this->second->id,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($this->second->id, $record->fresh()->caseworker_id);
        $this->assertRevoked($this->first, $record);
        $this->actingAs($this->second)->get(route('beneficiaries.show', $record))->assertOk()->assertSee('Synthetic history own');
        $this->getJson(route('dashboard.caseload'))->assertOk()->assertJsonPath('counts.total', 2);
        $this->actingAs($this->director)->post(route('beneficiaries.assign-caseworker'), [
            'beneficiary_ids' => [$record->id], 'caseworker_id' => 'unassign',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertRevoked($this->second, $record);
        $this->actingAs($this->director)->get(route('beneficiaries.show', $record))->assertOk()->assertSee('Synthetic history own');
        $this->assertDatabaseCount('activity_attendances', 5);
        $this->assertDatabaseCount('assistance_transactions', 5);
        Mail::assertNothingOutgoing();
    }

    public function test_zero_assignment_states_do_not_include_other_people_or_totals(): void
    {
        $this->actingAs($this->empty);
        $this->get(route('beneficiaries.index'))->assertOk()->assertViewHas('beneficiaries', fn ($rows) => $rows->total() === 0)
            ->assertSee('No beneficiaries found');
        $this->get(route('my-caseload.index'))->assertOk()->assertSee('No assigned beneficiaries yet.');
        $this->getJson(route('dashboard.caseload'))->assertOk()->assertJsonPath('counts', ['total' => 0, 'active' => 0, 'inactive' => 0]);
        $response = $this->getJson(route('dashboard.notifications'))->assertOk()->assertJsonCount(1, 'items')->assertSee($this->activity->title);
        $this->assertHiddenRecordsAbsent($response->getContent(), $this->empty);
        $this->getJson(route('activities.show', $this->activity))->assertOk()->assertJsonPath('counts.total', 0);
        $this->getJson(route('beneficiaries.suggestions', ['search' => 'Synthetic']))->assertOk()->assertExactJson(['results' => []]);
    }

    #[DataProvider('restrictedAccounts')]
    public function test_existing_account_and_guest_restrictions_cover_internal_reads_writes_and_downloads(?string $role, ?string $approval, bool $verified, int $status): void
    {
        if ($role !== null) {
            $user = User::factory()->create(['role' => $role, 'approval_status' => $approval,
                'email_verified_at' => $verified ? now() : null]);
            $this->actingAs($user);
        }
        foreach (['dashboard', 'dashboard.caseload', 'dashboard.notifications', 'beneficiaries.index', 'my-caseload.index',
            'beneficiaries.suggestions', 'reports.index', 'nfc.index', 'attendance.index', 'activities.index',
        ] as $route) {
            $this->getJson(route($route))->assertStatus($status);
        }
        foreach (['beneficiaries.show', 'beneficiaries.edit', 'beneficiaries.photo', 'beneficiaries.assistance.create'] as $route) {
            $this->getJson(route($route, $this->records['own']))->assertStatus($status);
        }
        foreach (['attendance', 'assistance'] as $type) {
            $this->getJson(route('reports.export', ['type' => $type]))->assertStatus($status);
            $this->getJson(route('reports.pdf', ['type' => $type]))->assertStatus($status);
        }
        $this->getJson(route('activities.attendance.export', $this->activity))->assertStatus($status);
        $this->postJson(route('activities.attendance.store', $this->activity), ['beneficiary_id' => $this->records['own']->beneficiary_id])->assertStatus($status);
        $this->postJson(route('activities.attendance.nfc', $this->activity), ['nfc_uid' => $this->records['own']->nfc_uid])->assertStatus($status);
        $this->postJson(route('nfc.lookup'), ['nfc_uid' => $this->records['own']->nfc_uid])->assertStatus($status);
        $this->putJson(route('beneficiaries.nfc.update', $this->records['own']), ['nfc_uid' => 'F0000001'])->assertStatus($status);
        $this->deleteJson(route('beneficiaries.nfc.destroy', $this->records['own']))->assertStatus($status);
        $this->putJson(route('beneficiaries.update', $this->records['own']), $this->updateData($this->records['own']))->assertStatus($status);
        $this->postJson(route('beneficiaries.assistance.store', $this->records['own']), $this->assistanceData())->assertStatus($status);
        $this->assertDatabaseCount('activity_attendances', 5);
        $this->assertDatabaseCount('assistance_transactions', 5);
        Mail::assertNothingOutgoing();
    }

    public static function restrictedAccounts(): array
    {
        return ['guest' => [null, null, false, 401], 'pending' => ['caseworker', 'pending', true, 403],
            'rejected' => ['caseworker', 'rejected', true, 403], 'unverified' => ['caseworker', 'approved', false, 403],
            'applicant' => ['applicant', 'approved', true, 403]];
    }

    public function test_caseworker_creation_is_denied_without_creating_or_assigning_a_record(): void
    {
        $before = Beneficiary::withTrashed()->orderBy('id')->get()->toArray();
        $this->actingAs($this->first)->get(route('beneficiaries.create'))->assertRedirect(route('beneficiaries.index'))
            ->assertSessionHas('error', 'Only Directors can add beneficiaries.');
        $this->post(route('beneficiaries.store'), [
            'beneficiary_id' => 'SYNTHETIC-NEW-UNASSIGNED', 'full_name' => 'Synthetic New Unassigned', 'registered_at' => '2026-10-13',
        ])->assertForbidden();
        $this->postJson(route('beneficiaries.store'), [
            'beneficiary_id' => 'SYNTHETIC-FORGED-OWNER', 'full_name' => 'Synthetic Forged', 'registered_at' => '2026-10-13',
            'caseworker_id' => $this->first->id,
        ])->assertForbidden();
        $this->assertSame($before, Beneficiary::withTrashed()->orderBy('id')->get()->toArray());
        Mail::assertNothingOutgoing();
    }

    public function test_optional_browser_fixtures_are_rendered_only_from_synthetic_memory_records(): void
    {
        if (getenv('ASSIGNED_ACCESS_UI_FIXTURES') !== '1') {
            $this->assertTrue(true);

            return;
        }
        $directory = storage_path('framework/testing/assigned-access-ui');
        File::ensureDirectoryExists($directory);
        foreach ([
            'caseworker-registry' => [$this->first, 'beneficiaries.index', []],
            'caseworker-caseloads' => [$this->first, 'beneficiaries.index', ['tab' => 'caseloads']],
            'director-caseloads' => [$this->director, 'beneficiaries.index', ['tab' => 'caseloads']],
            'empty-registry' => [$this->empty, 'beneficiaries.index', []],
            'caseworker-dashboard' => [$this->first, 'dashboard', []],
            'caseworker-attendance' => [$this->first, 'activities.show', ['activity' => $this->activity]],
            'caseworker-reports' => [$this->first, 'reports.index', $this->reportDates()],
        ] as $name => [$user, $route, $params]) {
            $response = $this->actingAs($user)->get(route($route, $params))->assertOk();
            if (! $user->isProjectDirector()) {
                $this->assertHiddenRecordsAbsent($response->getContent(), $user);
            }
            File::put($directory.'/'.$name.'.html', $response->getContent());
        }
    }

    private function assertHiddenRecordsAbsent(string $content, User $user): void
    {
        foreach ($this->records as $label => $record) {
            if ($record->fresh()->caseworker_id === $user->id && ! $record->fresh()->trashed()) {
                continue;
            }
            foreach ([$record->full_name, $record->beneficiary_id, $record->photo_path,
                route('beneficiaries.show', $record), route('beneficiaries.photo', $record),
                'Synthetic history '.$label, 'Synthetic item '.$label,
            ] as $private) {
                $this->assertStringNotContainsString($private, $content);
            }
        }
    }

    private function assertExportMembership(string $csv, User $user, int $total): void
    {
        $this->assertSame($total, substr_count($csv, '000-SYNTHETIC-'));
        if (! $user->isProjectDirector()) {
            $this->assertHiddenRecordsAbsent($csv, $user);
        } else {
            $this->assertStringContainsString('000-SYNTHETIC-archived', $csv);
        }
    }

    private function assertRevoked(User $user, Beneficiary $record): void
    {
        $this->actingAs($user);
        foreach (['beneficiaries.show', 'beneficiaries.edit', 'beneficiaries.photo', 'beneficiaries.assistance.create'] as $route) {
            $this->getJson(route($route, $record))->assertForbidden();
        }
        $this->head(route('beneficiaries.photo', $record))->assertForbidden();
        $this->get(route('beneficiaries.photo', $record), ['If-Modified-Since' => 'Wed, 01 Jan 2031 00:00:00 GMT'])
            ->assertForbidden();
        $this->putJson(route('beneficiaries.update', $record), $this->updateData($record))->assertForbidden();
        $this->postJson(route('beneficiaries.assistance.store', $record), $this->assistanceData())->assertForbidden();
        $this->putJson(route('beneficiaries.nfc.update', $record), ['nfc_uid' => 'F0000001'])->assertForbidden();
        $this->deleteJson(route('beneficiaries.nfc.destroy', $record))->assertForbidden();
        $this->postJson(route('nfc.lookup'), ['nfc_uid' => $record->nfc_uid])->assertUnprocessable();
        $this->postJson(route('activities.attendance.nfc', $this->activity), ['nfc_uid' => $record->nfc_uid])->assertUnprocessable();
        $this->postJson(route('activities.attendance.store', $this->activity), ['beneficiary_id' => $record->beneficiary_id])->assertUnprocessable();
        foreach (['beneficiaries.index', 'my-caseload.index'] as $route) {
            $this->get(route($route, $this->reportDates()))->assertOk()->assertDontSee($record->full_name)->assertDontSee($record->beneficiary_id);
        }
        $this->get(route('reports.index', $this->reportDates()))->assertOk()
            ->assertViewHas('attendanceCount', 1)->assertViewHas('participatingBeneficiariesCount', 1)
            ->assertViewHas('assistanceReleasesCount', 1)->assertViewHas('assistedBeneficiariesCount', 1)
            ->assertViewHas('attendances', fn ($rows) => $rows->total() === 1)
            ->assertViewHas('assistanceTransactions', fn ($rows) => $rows->total() === 1)
            ->assertDontSee($record->full_name)->assertDontSee($record->beneficiary_id);
        $this->getJson(route('dashboard.caseload'))->assertOk()->assertJsonPath('counts.total', 1)->assertDontSee($record->full_name);
        $this->getJson(route('dashboard.notifications'))->assertOk()->assertDontSee($record->full_name);
        $this->getJson(route('beneficiaries.suggestions', ['search' => $record->beneficiary_id]))->assertOk()->assertExactJson(['results' => []]);
        $this->getJson(route('activities.show', $this->activity))->assertOk()
            ->assertJsonPath('counts', ['total' => 1, 'manual' => 0, 'test_uid' => 1, 'nfc' => 0])
            ->assertDontSee($record->full_name);
        $activityCsv = $this->get(route('activities.attendance.export', $this->activity))->assertOk()->streamedContent();
        $this->assertExportMembership($activityCsv, $user, 1);
        foreach (['attendance', 'assistance'] as $type) {
            $csv = $this->get(route('reports.export', $this->reportDates() + ['type' => $type]))->assertOk()->streamedContent();
            $this->assertExportMembership($csv, $user, 1);
            $pdf = $this->get(route('reports.pdf', $this->reportDates() + ['type' => $type]))->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        }
    }

    private function reportDates(): array
    {
        return ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];
    }

    private function updateData(Beneficiary $record): array
    {
        return ['beneficiary_id' => $record->beneficiary_id, 'full_name' => $record->full_name, 'registered_at' => '2026-10-01'];
    }

    private function assistanceData(): array
    {
        return ['submission_token' => (string) Str::uuid(), 'category' => 'education',
            'item_name' => 'Synthetic new assistance', 'quantity' => 1, 'released_at' => '2026-10-13T12:00'];
    }
}
