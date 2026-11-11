<?php

namespace Tests\Feature;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CaseworkerCaseloadTest extends TestCase
{
    use RefreshDatabase;

    private int $recordNumber = 0;

    protected function setUp(): void
    {
        // The shared bootstrap checks SQLite :memory: and fakes delivery before
        // RefreshDatabase can migrate. No application database is used.
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_empty_personal_dashboard_and_list_exclude_everyone_elses_beneficiaries(): void
    {
        $caseworker = User::factory()->create();
        $this->record(['full_name' => 'Someone Else', 'caseworker_id' => User::factory()->create()->id]);
        $this->record(['full_name' => 'Unassigned Person']);
        $this->actingAs($caseworker)->get(route('dashboard'))->assertOk()->assertViewIs('dashboard.caseworker')
            ->assertViewHas('caseloadCounts', ['total' => 0, 'active' => 0, 'inactive' => 0])
            ->assertViewHas('caseloadPreview', fn ($records) => $records->isEmpty())
            ->assertSee('No assigned beneficiaries yet.')->assertSee(route('my-caseload.index'))
            ->assertDontSee('Someone Else')->assertDontSee('Unassigned Person')->assertDontSee('Attendance Overview');
        $this->get(route('my-caseload.index'))->assertOk()->assertViewIs('beneficiaries.index')
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 0)
            ->assertSee('No assigned beneficiaries yet.')->assertDontSee('Someone Else');
        $this->getJson(route('dashboard.caseload'))->assertOk()->assertJsonPath('counts', ['total' => 0, 'active' => 0, 'inactive' => 0]);
    }

    public function test_dashboard_counts_all_assigned_statuses_and_previews_only_the_first_five_in_alphabetical_order(): void
    {
        $caseworker = User::factory()->create();
        $expected = collect();
        foreach (['Zoe', 'Alex', 'Chris', 'Bella', 'Alex', 'Daisy', 'Emma'] as $i => $name) {
            $expected->push($this->record(['full_name' => $name, 'caseworker_id' => $caseworker->id,
                'status' => $i < 4 ? 'active' : 'inactive', 'beneficiary_id' => sprintf('000%04d', $i)]));
        }
        $expected = $expected->sort(fn ($a, $b) => strcmp($a->full_name, $b->full_name) ?: $a->id <=> $b->id)->values();
        $this->record(['full_name' => 'Archived First', 'caseworker_id' => $caseworker->id])->delete();
        $this->record(['full_name' => 'Other Caseworker', 'caseworker_id' => User::factory()->create()->id]);
        $this->record(['full_name' => 'Unassigned']);
        $response = $this->actingAs($caseworker)->get(route('dashboard'))->assertOk()
            ->assertViewHas('caseloadCounts', ['total' => 7, 'active' => 4, 'inactive' => 3])
            ->assertViewHas('caseloadPreview', fn ($records) => $records->pluck('id')->all() === $expected->take(5)->pluck('id')->all())
            ->assertSee('0000001')->assertDontSee('Archived First')->assertDontSee('Other Caseworker');
        $this->assertCount(5, $this->xpath($response->getContent())->query('//ul[@class="rlc-caseload-preview"]/li'));
        $this->getJson(route('dashboard.caseload', ['search' => 'no match', 'status' => 'inactive', 'page' => 9]))
            ->assertOk()->assertJsonPath('counts', ['total' => 7, 'active' => 4, 'inactive' => 3]);
    }

    public function test_my_caseload_combines_search_status_ordering_ties_and_pagination_with_only_personal_filter_links(): void
    {
        $caseworker = User::factory()->create();
        $expected = collect();
        for ($i = 12; $i >= 0; $i--) {
            $expected->push($this->record(['full_name' => sprintf('Alex %02d', $i), 'caseworker_id' => $caseworker->id]));
        }
        $expected->push($this->record(['full_name' => 'Alex 00', 'caseworker_id' => $caseworker->id]));
        $expected = $expected->sort(fn ($a, $b) => strcmp($a->full_name, $b->full_name) ?: $a->id <=> $b->id)->values();
        $this->record(['full_name' => 'Alex inactive', 'status' => 'inactive', 'caseworker_id' => $caseworker->id]);
        $this->record(['full_name' => 'Alex foreign', 'caseworker_id' => User::factory()->create()->id]);
        $this->record(['full_name' => 'Alex unassigned']);
        $this->record(['full_name' => 'Alex archived', 'caseworker_id' => $caseworker->id])->delete();
        $filters = ['search' => 'Alex', 'status' => 'active'];
        $response = $this->actingAs($caseworker)->get(route('my-caseload.index', $filters + ['caseworker' => 'unassigned', 'tab' => 'caseloads']))
            ->assertOk()->assertViewHas('beneficiaries', fn ($records) => $records->total() === 14 && $records->count() === 10)
            ->assertViewHas('tab', 'beneficiaries')->assertViewHas('registryFilters', $filters);
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(0, $xpath->query('//select[@name="caseworker"]'));
        $this->assertSame(route('my-caseload.index'), $xpath->query('//form[@class="rlcr-search-form"]')->item(0)->getAttribute('action'));
        $next = $xpath->query('//nav[@class="rlcr-page-links"]/a[@rel="next"]')->item(0)->getAttribute('href');
        $this->assertSame('/my-caseload', parse_url($next, PHP_URL_PATH));
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame($filters + ['page' => '2'], $query);
        $second = $this->get($next)->assertOk()->assertViewHas('beneficiaries', fn ($records) => $records->count() === 4);
        $this->assertSame($expected->pluck('id')->all(), $response->viewData('beneficiaries')->pluck('id')
            ->merge($second->viewData('beneficiaries')->pluck('id'))->all());
        $this->assertTrue($response->viewData('beneficiaries')->every(fn ($record) => $record->relationLoaded('caseworker')));
        $this->get(route('my-caseload.index', ['search' => 'Alex', 'status' => 'inactive']))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 1 && $records->first()->full_name === 'Alex inactive');
    }

    public function test_zero_prefixed_ids_remain_strings_and_grouped_search_cannot_escape_assignment_scope(): void
    {
        $caseworker = User::factory()->create();
        $own = $this->record(['beneficiary_id' => '0027947780', 'caseworker_id' => $caseworker->id, 'status' => 'inactive']);
        $this->record(['beneficiary_id' => '0027947781', 'caseworker_id' => User::factory()->create()->id, 'status' => 'inactive']);
        $this->record(['full_name' => '0027947780 Foreign Name']);
        $this->actingAs($caseworker)->get(route('my-caseload.index', ['search' => '0027947780', 'status' => 'inactive']))
            ->assertOk()->assertSee('0027947780')->assertDontSee('Foreign Name')
            ->assertViewHas('beneficiaries', fn ($records) => $records->pluck('id')->all() === [$own->id]);
        $this->getJson(route('dashboard.caseload'))->assertOk()->assertSee('0027947780');
    }

    #[DataProvider('forgedFilters')]
    public function test_client_owner_and_archived_filters_cannot_change_dashboard_list_or_fragment_ownership(array $forged): void
    {
        $caseworker = User::factory()->create();
        $other = User::factory()->create();
        $own = $this->record(['full_name' => 'Own Person', 'caseworker_id' => $caseworker->id]);
        $this->record(['full_name' => 'Foreign Person', 'caseworker_id' => $other->id]);
        $this->record(['full_name' => 'Unassigned Person']);
        $this->record(['full_name' => 'Archived Person', 'caseworker_id' => $caseworker->id])->delete();
        $forged = array_map(fn ($value) => $value === 'foreign' ? (string) $other->id : $value, $forged);
        $this->actingAs($caseworker)->get(route('dashboard', $forged))->assertOk()
            ->assertViewHas('caseloadCounts', ['total' => 1, 'active' => 1, 'inactive' => 0]);
        $this->get(route('my-caseload.index', $forged))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->pluck('id')->all() === [$own->id])
            ->assertViewHas('registryFilters', [])->assertDontSee('Foreign Person')->assertDontSee('Archived Person');
        $this->get(route('my-caseload.index', $forged), ['X-Registry-Fragment' => 'results'])->assertOk()
            ->assertSee('Own Person')->assertDontSee('Foreign Person')->assertDontSee('Archived Person')
            ->assertDontSee('<html', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(route('dashboard.caseload', $forged))->assertOk()
            ->assertJsonPath('counts', ['total' => 1, 'active' => 1, 'inactive' => 0])
            ->assertDontSee('Foreign Person')->assertDontSee('Archived Person')->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function forgedFilters(): array
    {
        return [
            'foreign caseworker' => [['caseworker' => 'foreign', 'caseworker_id' => 'foreign', 'user_id' => 'foreign']],
            'unassigned' => [['caseworker' => 'unassigned']],
            'nested owners' => [['caseworker' => ['foreign'], 'caseworker_id' => ['foreign'], 'user' => ['id' => 2]]],
            'archived and all caseloads' => [['tab' => 'caseloads', 'archived' => '1', 'withTrashed' => '1', 'scope' => 'all']],
        ];
    }

    public function test_assignment_reassignment_and_unassignment_appear_on_every_refresh_without_assignment_email(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $record = $this->record(['beneficiary_id' => '000007']);
        $this->assertCaseload($first, []);
        $this->actingAs($director)->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => $first->id])->assertRedirect();
        $this->assertCaseload($first, [$record]);
        $this->assertCaseload($second, []);
        $this->actingAs($director)->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => $second->id])->assertRedirect();
        $this->assertCaseload($first, []);
        $this->assertCaseload($second, [$record]);
        $this->actingAs($director)->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => 'unassign'])->assertRedirect();
        $this->assertCaseload($first, []);
        $this->assertCaseload($second, []);
        $this->assertSame('000007', $record->fresh()->beneficiary_id);
        $this->assertDatabaseCount('account_notifications', 4);
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_beneficiary_creation_assignment_and_archive_restore_use_the_same_current_caseload(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $caseworker = User::factory()->create();
        $this->actingAs($director)->post(route('beneficiaries.store'), ['beneficiary_id' => '000008', 'full_name' => 'Created Assigned',
            'registered_at' => '2026-10-01', 'caseworker_id' => $caseworker->id])->assertRedirect()->assertSessionHasNoErrors();
        $record = Beneficiary::where('beneficiary_id', '000008')->sole();
        $this->assertCaseload($caseworker, [$record]);
        $this->actingAs($director)->delete(route('beneficiaries.destroy', $record))->assertRedirect();
        $this->assertCaseload($caseworker, []);
        $this->actingAs($director)->post(route('beneficiaries.restore', $record))->assertRedirect();
        $this->assertCaseload($caseworker, [$record]);
        $this->assertDatabaseCount('account_notifications', 1);
        Mail::assertNothingOutgoing();
    }

    public function test_status_changes_refresh_counts_preview_and_status_filters_without_changing_the_owner(): void
    {
        $caseworker = User::factory()->create();
        $record = $this->record(['full_name' => 'Assigned Status Change', 'beneficiary_id' => '000009', 'caseworker_id' => $caseworker->id]);
        $this->record(['full_name' => 'Foreign Inactive', 'status' => 'inactive', 'caseworker_id' => User::factory()->create()->id]);
        $this->record(['full_name' => 'Archived Inactive', 'status' => 'inactive', 'caseworker_id' => $caseworker->id])->delete();

        $initial = $this->actingAs($caseworker)->getJson(route('dashboard.caseload'))->assertOk()
            ->assertJsonPath('counts', ['total' => 1, 'active' => 1, 'inactive' => 0])->json('preview');
        $record->update(['status' => 'inactive']);
        $refreshed = $this->getJson(route('dashboard.caseload'))->assertOk()
            ->assertJsonPath('counts', ['total' => 1, 'active' => 0, 'inactive' => 1])->json('preview');
        $this->assertNotSame($initial, $refreshed);
        $this->assertStringContainsString('Inactive', $refreshed);
        $this->assertStringContainsString('000009', $refreshed);
        $this->assertStringNotContainsString('Foreign Inactive', $refreshed);
        $this->assertStringNotContainsString('Archived Inactive', $refreshed);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('caseloadCounts', ['total' => 1, 'active' => 0, 'inactive' => 1]);
        $this->get(route('my-caseload.index', ['status' => 'active']))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 0);
        $this->get(route('my-caseload.index', ['status' => 'inactive']))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->pluck('id')->all() === [$record->id]);
    }

    public function test_directors_retain_organization_wide_counts_history_and_upcoming_activities(): void
    {
        $director = User::factory()->create(['role' => 'project_director', 'approval_status' => 'pending']);
        $one = $this->record(['full_name' => 'First Caseload', 'caseworker_id' => User::factory()->create()->id]);
        $this->record(['full_name' => 'Second Caseload', 'caseworker_id' => User::factory()->create()->id]);
        $this->record(['full_name' => 'Unassigned']);
        $this->record(['status' => 'inactive']);
        $archived = $this->record(['full_name' => 'Archived Assistance']);
        $archived->delete();
        $activity = $this->activity();
        ActivityAttendance::create(['program_activity_id' => $activity->id, 'beneficiary_id' => $one->id,
            'checked_in_at' => now(), 'source' => 'manual', 'recorded_by' => $director->id]);
        AssistanceTransaction::create(['beneficiary_id' => $archived->id, 'category' => 'food', 'item_name' => 'Organization Release',
            'quantity' => 1, 'released_at' => now(), 'recorded_by' => $director->id]);
        $this->actingAs($director)->get(route('dashboard'))->assertOk()->assertViewIs('dashboard')
            ->assertViewHas('activeBeneficiariesCount', 3)->assertViewHas('activitiesCount', 1)
            ->assertViewHas('attendanceCount', 1)->assertViewHas('assistanceReleasesCount', 1)
            ->assertSee('Attendance Overview')->assertSee('Organization Release')->assertSee('Archived Assistance')
            ->assertSee('Organization-wide activities')->assertDontSee('data-caseload-dashboard', false)
            ->assertDontSee(route('my-caseload.index'));
        $this->get(route('my-caseload.index'))->assertForbidden();
        $this->getJson(route('dashboard.caseload'))->assertForbidden();
    }

    public function test_caseworker_upcoming_activities_remain_authorized_organization_wide_and_separate_from_personal_data(): void
    {
        $caseworker = User::factory()->create();
        $this->activity(['title' => 'Past Activity', 'activity_date' => now('Asia/Manila')->subDay()->toDateString()]);
        $this->activity(['title' => 'Today Activity', 'activity_date' => now('Asia/Manila')->toDateString()]);
        $expected = collect();
        foreach (range(1, 6) as $day) {
            $expected->push($this->activity(['title' => 'Upcoming '.$day, 'activity_date' => now('Asia/Manila')->addDays($day)->toDateString()]));
        }
        $this->actingAs($caseworker)->get(route('dashboard'))->assertOk()->assertSee('Organization-wide activities')
            ->assertSee('Upcoming 1')->assertSee('Upcoming 5')->assertDontSee('Upcoming 6')->assertDontSee('Past Activity')->assertDontSee('Today Activity')
            ->assertViewHas('upcomingActivities', fn ($records) => $records->pluck('id')->all() === $expected->take(5)->pluck('id')->all());
        $this->get(route('activities.show', $expected->first()))->assertOk();
    }

    #[DataProvider('restrictedAccounts')]
    public function test_pending_and_rejected_caseworkers_cannot_access_personal_pages_fragments_or_polling(string $status, bool $verified): void
    {
        $caseworker = User::factory()->create(['approval_status' => $status, 'email_verified_at' => $verified ? now() : null]);
        $this->record(['caseworker_id' => $caseworker->id, 'full_name' => 'Restricted Personal Record']);
        $this->actingAs($caseworker);
        foreach (['dashboard', 'my-caseload.index', 'dashboard.caseload'] as $route) {
            $this->get(route($route))->assertRedirect(route('account.status'));
            $this->getJson(route($route))->assertForbidden()->assertDontSee('Restricted Personal Record');
        }
        $this->get(route('my-caseload.index'), ['X-Registry-Fragment' => 'results'])->assertRedirect(route('account.status'));
        $this->get(route('account.status'))->assertOk()->assertDontSee(route('my-caseload.index'));
    }

    public static function restrictedAccounts(): array
    {
        return ['pending verified' => ['pending', true], 'pending unverified' => ['pending', false],
            'rejected verified' => ['rejected', true], 'rejected unverified' => ['rejected', false]];
    }

    public function test_guests_unverified_approved_accounts_and_other_roles_cannot_access_caseload_routes(): void
    {
        foreach (['dashboard', 'my-caseload.index', 'dashboard.caseload'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
            $this->getJson(route($route))->assertUnauthorized();
        }
        $this->actingAs(User::factory()->unverified()->create());
        foreach (['dashboard', 'my-caseload.index', 'dashboard.caseload'] as $route) {
            $this->get(route($route))->assertRedirect(route('verification.notice'));
            $this->getJson(route($route))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['dashboard', 'my-caseload.index', 'dashboard.caseload'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_registry_profile_edit_photo_and_search_require_assignment(): void
    {
        Storage::fake('local');
        $caseworker = User::factory()->create();
        $other = $this->record(['full_name' => 'Other Caseload Profile', 'photo_path' => 'beneficiary-photos/other.jpg',
            'caseworker_id' => User::factory()->create()->id]);
        Storage::disk('local')->put($other->photo_path, 'protected fixture photo');
        $this->actingAs($caseworker)->get(route('beneficiaries.index'))->assertOk()->assertDontSee('Other Caseload Profile');
        $this->get(route('beneficiaries.show', $other))->assertForbidden();
        $this->get(route('beneficiaries.edit', $other))->assertForbidden();
        $this->get(route('beneficiaries.photo', $other))->assertForbidden();
        $this->getJson(route('beneficiaries.suggestions', ['search' => 'Other Caseload']))->assertOk()->assertExactJson(['results' => []]);
        $this->get(route('my-caseload.index'))->assertOk()->assertDontSee('Other Caseload Profile');
        $this->getJson(route('dashboard.caseload'))->assertOk()->assertDontSee('Other Caseload Profile')->assertDontSee('beneficiary-photos/other.jpg');
    }

    public function test_personal_preview_and_registry_reuse_protected_photos_and_escape_names(): void
    {
        Storage::fake('local');
        $caseworker = User::factory()->create();
        $record = $this->record(['full_name' => '<script>bad-name</script>', 'beneficiary_id' => '000123',
            'photo_path' => 'beneficiary-photos/own.jpg', 'caseworker_id' => $caseworker->id]);
        Storage::disk('local')->put($record->photo_path, 'protected fixture photo');
        foreach (['dashboard', 'my-caseload.index'] as $route) {
            $this->actingAs($caseworker)->get(route($route))->assertOk()->assertSee($record->full_name)
                ->assertDontSee($record->full_name, false)->assertSee(route('beneficiaries.photo', $record))
                ->assertDontSee('beneficiary-photos/own.jpg')->assertSee('000123');
        }
        $json = $this->getJson(route('dashboard.caseload'))->assertOk()->json('preview');
        $this->assertStringContainsString(e($record->full_name), $json);
        $this->assertStringContainsString(route('beneficiaries.photo', $record), $json);
        $record->delete();
        $this->get(route('beneficiaries.photo', $record))->assertNotFound();
    }

    public function test_invalid_scalar_or_array_display_filters_are_normalized_and_out_of_range_pages_keep_personal_navigation(): void
    {
        $caseworker = User::factory()->create();
        $own = $this->record(['caseworker_id' => $caseworker->id]);
        $this->actingAs($caseworker)->get(route('my-caseload.index', ['search' => ['bad'], 'status' => ['active']]))->assertOk()
            ->assertViewHas('search', '')->assertViewHas('status', '')->assertViewHas('beneficiaries', fn ($records) => $records->pluck('id')->all() === [$own->id]);
        $page = $this->get(route('my-caseload.index', ['page' => 9]))->assertOk()->assertSee('Return to first page');
        $this->assertSame(route('my-caseload.index'), $this->xpath($page->getContent())
            ->query('//a[@data-registry-navigation and contains(text(), "Return to first page")]')->item(0)->getAttribute('href'));
        $this->get(route('my-caseload.index', ['search' => 'no match']))->assertOk()->assertSee('No beneficiaries found')->assertDontSee('No assigned beneficiaries yet.');
    }

    public function test_responsive_shared_ui_has_one_bell_clear_sections_labels_and_exportable_isolated_fixtures(): void
    {
        $caseworker = User::factory()->create(['name' => 'Approved Test Caseworker']);
        foreach (range(1, 13) as $i) {
            $this->record(['full_name' => 'Assigned Beneficiary '.sprintf('%02d', $i), 'caseworker_id' => $caseworker->id, 'status' => $i % 2 ? 'active' : 'inactive']);
        }
        $this->activity();
        $dashboard = $this->actingAs($caseworker)->get(route('dashboard'))->assertOk();
        $list = $this->get(route('my-caseload.index'))->assertOk();
        $xpath = $this->xpath($dashboard->getContent());
        $this->assertCount(3, $xpath->query('//*[@data-caseload-count]'));
        $this->assertCount(1, $xpath->query('//*[@data-caseload-dashboard]'));
        $this->assertCount(1, $xpath->query('//*[@id="rlc-notification-panel"]'));
        $this->assertCount(1, $xpath->query('//section[@aria-label="Organization-wide upcoming activities"]'));
        $this->assertCount(1, $xpath->query('//input[@id="dashboard-beneficiary-search"]'));
        $xpath = $this->xpath($list->getContent());
        $this->assertCount(1, $xpath->query('//input[@id="rlcr-search" and @maxlength="100"]'));
        $this->assertCount(1, $xpath->query('//select[@name="status"]'));
        $this->assertCount(0, $xpath->query('//select[@name="caseworker"]'));
        $this->assertCount(1, $xpath->query('//button[@aria-label="Grid view"]'));
        // Optional local browser fixtures contain only these memory-only test
        // records. They never boot the real application or contain credentials.
        if (getenv('CASELOAD_UI_FIXTURES') === '1') {
            $directory = storage_path('framework/testing/caseload-ui');
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/dashboard.html', $dashboard->getContent());
            file_put_contents($directory.'/my-caseload.html', $list->getContent());
        }
    }

    public function test_read_only_caseload_routes_do_not_change_records_or_send_mail_or_notifications(): void
    {
        $caseworker = User::factory()->create();
        $this->record(['caseworker_id' => $caseworker->id]);
        $before = DB::table('beneficiaries')->orderBy('id')->get()->toJson();
        $this->actingAs($caseworker)->get(route('dashboard'))->assertOk();
        $this->get(route('my-caseload.index'))->assertOk();
        $this->getJson(route('dashboard.caseload'))->assertOk();
        $this->assertSame($before, DB::table('beneficiaries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('account_notifications', 0);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    private function assertCaseload(User $user, array $records): void
    {
        $counts = ['total' => count($records), 'active' => count($records), 'inactive' => 0];
        $expectedIds = array_map(fn ($record) => $record->id, $records);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewHas('caseloadCounts', $counts)
            ->assertViewHas('caseloadPreview', fn ($preview) => $preview->pluck('id')->all() === $expectedIds);
        $preview = $this->getJson(route('dashboard.caseload'))->assertOk()->assertJsonPath('counts', $counts)->json('preview');
        $xpath = $this->xpath($preview);
        $links = $xpath->query('//ul[@class="rlc-caseload-preview"]//div[@class="rlcr-person-name"]/a');
        $this->assertSame(array_map(fn ($record) => route('beneficiaries.show', $record), $records),
            array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array($links)));
        if ($records === []) {
            $this->assertStringContainsString('No assigned beneficiaries yet.', $preview);
        }
        $this->get(route('my-caseload.index'))->assertOk()->assertViewHas('beneficiaries',
            fn ($result) => $result->pluck('id')->all() === $expectedIds);
    }

    private function record(array $attributes = []): Beneficiary
    {
        $number = ++$this->recordNumber;

        return Beneficiary::forceCreate($attributes + ['beneficiary_id' => sprintf('000%04d', $number),
            'full_name' => 'Beneficiary '.$number, 'registered_at' => '2026-10-01', 'status' => 'active']);
    }

    private function activity(array $attributes = []): ProgramActivity
    {
        return ProgramActivity::create($attributes + ['title' => 'Organization Activity',
            'activity_date' => now('Asia/Manila')->addDay()->toDateString(), 'development_area' => 'spiritual', 'start_time' => '09:00', 'location' => 'Community Center']);
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
