<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use App\Policies\BeneficiaryPolicy;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BeneficiaryRegistryTest extends TestCase
{
    use RefreshDatabase;

    private int $recordNumber = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Registry tests require the isolated SQLite :memory: test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_registry_renders_real_records_columns_protected_photos_and_existing_actions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $record = $this->record([
            'beneficiary_id' => '0027947780', 'full_name' => 'Angel Reyes', 'photo_path' => 'beneficiary-photos/private.jpg',
        ]);
        $this->record(['full_name' => 'Carlo Cruz']);
        $response = $this->get(route('beneficiaries.index'))->assertOk()
            ->assertSee('Beneficiary Registry')->assertSee('All Beneficiaries')
            ->assertSee('Angel Reyes')->assertSee('0027947780')->assertSee('10/07/2026')
            ->assertSee(route('beneficiaries.photo', $record))->assertDontSee('beneficiary-photos/private.jpg')
            ->assertSee(route('beneficiaries.show', $record))->assertSee(route('beneficiaries.edit', $record))
            ->assertSee(route('beneficiaries.bulk-archive'))->assertSee(route('beneficiaries.archived'))
            ->assertSee(route('beneficiaries.create'))->assertSee('Archive Selected')
            ->assertSee('aria-label="List view"', false)->assertSee('aria-label="Grid view"', false)
            ->assertDontSee('Add Caseworker')->assertSee('Caseworker Caseloads')->assertSee('Assign / Reassign Caseworker');

        $xpath = $this->xpath($response->getContent());
        $columns = [];
        foreach ($xpath->query('//table[@class="rlcr-table"]/thead/tr/th') as $column) {
            $columns[] = trim($column->textContent);
        }
        $this->assertSame(['', 'Beneficiary', 'Beneficiary ID', 'Caseworker', 'Registered date', 'Status', 'Actions'], $columns);
        $this->assertCount(2, $xpath->query('//table[@class="rlcr-table"]/tbody/tr/td[1]/input[@type="checkbox"]'));
        $this->assertCount(1, $xpath->query('//a[@data-flux-sidebar-item and normalize-space(.)="Registry"]'));
        $this->assertCount(1, $xpath->query('//div[@class="rlcr-avatar"]/span[normalize-space(.)="CC"]'));
        $this->assertCount(1, $xpath->query('//select[@name="caseworker"]'));
    }

    public function test_full_names_sort_alphabetically_with_record_id_breaking_ties_across_pages(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $this->record(['id' => 70, 'full_name' => 'Zoe Santos']);
        $this->record(['id' => 60, 'full_name' => 'Abel Santos']);
        $ids = [35, 11, 8, 19, 3, 16, 5, 44, 6, 2, 13, 26, 1];
        foreach ($ids as $id) {
            $this->record(['id' => $id, 'full_name' => 'Alex Rivera']);
        }
        $archived = $this->record(['id' => 90, 'full_name' => 'Aaron Archived']);
        $archived->delete();
        sort($ids);
        $expected = [60, ...$ids, 70];
        $actual = [];
        foreach ([1, 2] as $page) {
            $response = $this->get(route('beneficiaries.index', ['page' => $page]))->assertOk()->assertDontSee('Aaron Archived');
            $records = $response->viewData('beneficiaries');
            $this->assertSame(15, $records->total());
            array_push($actual, ...$records->pluck('id')->all());
        }
        $this->assertSame($expected, $actual);
        $this->assertSame($expected, $this->get(route('beneficiaries.index'))->viewData('beneficiaries')->pluck('id')
            ->merge($this->get(route('beneficiaries.index', ['page' => 2]))->viewData('beneficiaries')->pluck('id'))->all());
    }

    public function test_search_and_status_filter_share_pagination_and_never_include_archived_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        for ($i = 0; $i < 12; $i++) {
            $this->record(['full_name' => 'Alex Registry '.$i]);
        }
        $this->record(['beneficiary_id' => 'Alex-inactive', 'full_name' => 'Other person', 'status' => 'inactive']);
        $this->record(['full_name' => 'Alex inactive', 'status' => 'inactive']);
        $this->record(['full_name' => 'Other active']);
        $archived = $this->record(['full_name' => 'Alex archived']);
        $archived->delete();
        $before = Beneficiary::withTrashed()->orderBy('id')->get()->toArray();

        $response = $this->get(route('beneficiaries.index', ['search' => ' Alex ', 'status' => 'active']))->assertOk()
            ->assertViewHas('search', 'Alex')->assertViewHas('status', 'active')
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 12 && $records->count() === 10);
        $xpath = $this->xpath($response->getContent());
        $next = $xpath->query('//nav[@class="rlcr-page-links"]/a[@rel="next"]')->item(0);
        $this->assertNotNull($next);
        parse_str(parse_url($next->getAttribute('href'), PHP_URL_QUERY), $query);
        $this->assertSame(['search' => 'Alex', 'status' => 'active', 'page' => '2'], $query);
        $this->get($next->getAttribute('href'))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 12 && $records->count() === 2);
        $this->get(route('beneficiaries.index', ['search' => 'Alex', 'status' => 'inactive']))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 2 && $records->every(fn ($record) => $record->status === 'inactive'));
        $zeroId = $this->record(['beneficiary_id' => '000007', 'full_name' => 'Zero ID beneficiary']);
        $this->get(route('beneficiaries.index', ['search' => '000007']))->assertOk()->assertSee('000007')
            ->assertViewHas('beneficiaries', fn ($records) => $records->count() === 1 && $records->first()->id === $zeroId->id);
        $this->assertSame($before, Beneficiary::withTrashed()->where('id', '!=', $zeroId->id)->orderBy('id')->get()->toArray());
    }

    public function test_caseworkers_keep_profile_actions_without_selection_or_archive_controls(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $record = $this->record();
        $response = $this->get(route('beneficiaries.index'))->assertOk()
            ->assertSee(route('beneficiaries.show', $record))->assertSee(route('beneficiaries.edit', $record))
            ->assertDontSee(route('beneficiaries.create'))->assertDontSee('Add Beneficiary')->assertDontSee('Archive Selected')
            ->assertDontSee('View Archived')->assertDontSee('Selection options')->assertDontSee('Select all on this page');
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(0, $xpath->query('//table[@class="rlcr-table"]//input[@type="checkbox"]'));
        $this->assertCount(0, $xpath->query('//table[@class="rlcr-table"]//*[@class="rlcr-selection-cell"]'));
        $this->assertCount(0, $xpath->query('//dialog[@x-ref="archiveDialog"]'));
    }

    public function test_select_all_respects_per_record_delete_policy_and_the_filtered_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $allowed = $this->record(['beneficiary_id' => '000001', 'full_name' => 'Alex Allowed']);
        $blocked = $this->record(['beneficiary_id' => '000002', 'full_name' => 'Alex Blocked']);
        $this->record(['full_name' => 'Zoe outside search']);
        Gate::policy(Beneficiary::class, get_class(new class extends BeneficiaryPolicy
        {
            public function delete(User $user, Beneficiary $beneficiary): bool
            {
                return parent::delete($user, $beneficiary) && $beneficiary->beneficiary_id !== '000002';
            }

            public function assignCaseworker(User $user, ?Beneficiary $beneficiary = null): bool
            {
                return parent::assignCaseworker($user, $beneficiary) && $beneficiary?->beneficiary_id !== '000002';
            }
        }));
        $response = $this->get(route('beneficiaries.index', ['search' => 'Alex']))->assertOk()
            ->assertSee('pageIds: '.Js::from([(string) $allowed->id])->toHtml(), false);
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//table[@class="rlcr-table"]/tbody//input[@type="checkbox"]'));
        $this->assertCount(1, $xpath->query('//*[@id="rlcr-actions-'.$allowed->id.'"]/button[normalize-space(.)="Archive"]'));
        $this->assertCount(0, $xpath->query('//*[@id="rlcr-actions-'.$blocked->id.'"]/button[normalize-space(.)="Archive"]'));
    }

    public function test_selection_cells_and_bulk_actions_start_hidden_and_controls_support_both_views(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $record = $this->record();
        $response = $this->get(route('beneficiaries.index'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(2, $xpath->query('//*[@class="rlcr-selection-cell" and @x-cloak and @x-show="selecting"]'));
        $this->assertCount(1, $xpath->query('//*[@data-start-selection and @aria-controls="rlcr-selection-controls" and @x-show="!selecting"]'));
        $this->assertCount(1, $xpath->query('//*[@id="rlcr-selection-controls" and @role="group" and @x-cloak and @x-show="selecting"]'));
        $this->assertCount(1, $xpath->query('//*[@id="rlcr-selection-controls"]//label'));
        $gridLabel = $xpath->query('//*[@id="rlcr-selection-controls"]//label')->item(0);
        $this->assertSame("mode === 'cards'", $gridLabel->getAttribute('x-show'));
        $this->assertCount(2, $xpath->query('//input[@data-select-all and @type="checkbox" and @aria-label="Select all on this page"]'));
        $this->assertCount(1, $xpath->query('//*[@id="rlcr-selection-controls"]//button[@data-cancel-selection and normalize-space(.)="Cancel selection"]'));
        $this->assertCount(1, $xpath->query('//button[@data-assignment-bulk and @x-cloak and @x-show="canAssignSelection"]'));
        $this->assertCount(1, $xpath->query('//button[@data-archive-bulk and @x-cloak and @x-show="canArchiveSelection"]'));
        $checkbox = $xpath->query('//table[@class="rlcr-table"]//input[@value="'.$record->id.'"]')->item(0);
        $this->assertNotNull($checkbox);
        $this->assertSame('!selecting || refreshing', $checkbox->getAttribute(':disabled'));
        $this->assertStringContainsString('toggleSelection', $checkbox->getAttribute('x-on:change'));
        $this->assertStringContainsString('selected.includes', $checkbox->getAttribute(':checked'));
        $this->assertSame('polite', $xpath->query('//*[@id="rlcr-selection-controls"]//span[@role="status"]')->item(0)->getAttribute('aria-live'));
    }

    public function test_page_selection_includes_only_records_with_a_permitted_existing_bulk_action(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $both = $this->record(['full_name' => 'Alex A Both']);
        $archiveOnly = $this->record(['full_name' => 'Alex B Archive', 'beneficiary_id' => 'ARCHIVE-ONLY']);
        $assignOnly = $this->record(['full_name' => 'Alex C Assign', 'beneficiary_id' => 'ASSIGN-ONLY']);
        $blocked = $this->record(['full_name' => 'Alex D Blocked', 'beneficiary_id' => 'BLOCKED']);
        $this->record(['full_name' => 'Zoe Outside search']);
        $this->record(['full_name' => 'Alex Archived'])->delete();
        Gate::policy(Beneficiary::class, get_class(new class extends BeneficiaryPolicy
        {
            public function delete(User $user, Beneficiary $beneficiary): bool
            {
                return parent::delete($user, $beneficiary) && ! in_array($beneficiary->beneficiary_id, ['ASSIGN-ONLY', 'BLOCKED'], true);
            }

            public function assignCaseworker(User $user, ?Beneficiary $beneficiary = null): bool
            {
                return parent::assignCaseworker($user, $beneficiary) && ! in_array($beneficiary?->beneficiary_id, ['ARCHIVE-ONLY', 'BLOCKED'], true);
            }
        }));
        $response = $this->get(route('beneficiaries.index', ['search' => 'Alex']))->assertOk()
            ->assertSee('pageIds: '.Js::from([(string) $both->id, (string) $archiveOnly->id])->toHtml(), false)
            ->assertSee('assignmentPageIds: '.Js::from([(string) $both->id, (string) $assignOnly->id])->toHtml(), false)
            ->assertSee('selectablePageIds: '.Js::from([(string) $both->id, (string) $archiveOnly->id, (string) $assignOnly->id])->toHtml(), false);
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(3, $xpath->query('//table[@class="rlcr-table"]//tbody//input[@type="checkbox"]'));
        $this->assertCount(0, $xpath->query('//table[@class="rlcr-table"]//input[@value="'.$blocked->id.'"]'));
        $this->assertCount(1, $xpath->query('//*[@id="rlcr-actions-'.$assignOnly->id.'"]//button[@data-assignment-row]'));
        $this->assertCount(0, $xpath->query('//*[@id="rlcr-actions-'.$assignOnly->id.'"]//button[normalize-space(.)="Archive"]'));
        $this->assertDatabaseCount('beneficiaries', 6);
    }

    public function test_selectable_ids_are_limited_to_the_current_alphabetical_filtered_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $caseworker = User::factory()->create(['role' => 'caseworker']);
        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $ids[] = (string) $this->record(['full_name' => 'Alex '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'caseworker_id' => $caseworker->id])->id;
        }
        $this->record(['full_name' => 'Alex Inactive', 'status' => 'inactive', 'caseworker_id' => $caseworker->id]);
        $this->record(['full_name' => 'Alex Unassigned']);
        $this->record(['full_name' => 'Zoe Outside search', 'caseworker_id' => $caseworker->id]);
        $filters = ['search' => 'Alex', 'status' => 'active', 'caseworker' => $caseworker->id];
        foreach ([1, 2] as $page) {
            $expected = array_slice($ids, ($page - 1) * 10, 10);
            $response = $this->get(route('beneficiaries.index', $filters + ['page' => $page]))->assertOk()
                ->assertSee('selectablePageIds: '.Js::from($expected)->toHtml(), false);
            $xpath = $this->xpath($response->getContent());
            $actual = [];
            foreach ($xpath->query('//table[@class="rlcr-table"]//tbody//input[@type="checkbox"]') as $checkbox) {
                $actual[] = $checkbox->getAttribute('value');
            }
            $this->assertSame($expected, $actual);
        }
    }

    public function test_empty_filters_escape_search_text_and_unknown_status_does_not_filter_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
        $this->get(route('beneficiaries.index'))->assertOk()->assertSee('No beneficiaries found')
            ->assertSee('A Director can assign beneficiaries to your caseload.')->assertDontSee('Add Beneficiary')
            ->assertDontSee(route('beneficiaries.create'));
        $record = $this->record();
        foreach (['unsupported', ['active']] as $status) {
            $this->get(route('beneficiaries.index', ['status' => $status]))->assertOk()->assertViewHas('status', '')
                ->assertViewHas('beneficiaries', fn ($records) => $records->first()->id === $record->id);
        }
        $this->get(route('beneficiaries.index', ['search' => '<script>alert(1)</script>']))->assertOk()
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('No beneficiaries found')->assertSee('Clear filters');
    }

    public function test_live_fragments_update_rows_count_pagination_caseload_and_permissions_together_without_writes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $caseworker = User::factory()->create(['role' => 'caseworker', 'name' => 'Registry Caseworker']);
        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $ids[] = (string) $this->record([
                'full_name' => 'Alex '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'beneficiary_id' => '000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'caseworker_id' => $caseworker->id, 'photo_path' => 'beneficiary-photos/private.jpg',
            ])->id;
        }
        $this->record(['full_name' => 'Alex Inactive', 'status' => 'inactive', 'caseworker_id' => $caseworker->id]);
        $this->record(['full_name' => 'Alex Unassigned']);
        $this->record(['full_name' => 'Alex Archived', 'caseworker_id' => $caseworker->id])->delete();
        $before = Beneficiary::withTrashed()->orderBy('id')->get()->toArray();
        $filters = ['search' => 'Alex', 'caseworker' => $caseworker->id, 'status' => 'active'];

        foreach ([1, 2] as $page) {
            $response = $this->get(route('beneficiaries.index', $filters + ['page' => $page]), ['X-Registry-Fragment' => 'results'])
                ->assertOk()->assertSee('12 results')->assertSee('Registry Caseworker')->assertDontSee('Alex Archived')
                ->assertDontSee('<html', false)->assertDontSee('rlcr-search-form')
                ->assertDontSee('beneficiary-photos/private.jpg')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $xpath = $this->xpath($response->getContent());
            $this->assertCount(0, $xpath->query('//dialog[@id="rlcr-assignment-dialog"]'));
            $expected = array_slice($ids, ($page - 1) * 10, 10);
            $metadata = json_decode($xpath->query('//*[@data-registry-results]')->item(0)->getAttribute('data-registry-page'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $metadata['archiveIds']);
            $this->assertSame($expected, $metadata['selectableIds']);
            $this->assertSame($expected, array_map('strval', array_keys($metadata['assignmentRecords'])));
            $this->assertSame(route('beneficiaries.create', ['caseworker_id' => $caseworker->id]), $metadata['createUrl']);
            $this->assertCount(count($expected), $xpath->query('//table[@class="rlcr-table"]/tbody/tr'));
            $this->assertCount(1, $xpath->query('//nav[@class="rlcr-page-links"]/*[@aria-current="page" and normalize-space(.)="'.$page.'"]'));
            $this->assertCount(1, $xpath->query('//*[@data-registry-caseload-heading]//*[@data-selected-caseload]'));
            $this->assertCount(0, $xpath->query('//*[@id="rlcr-selection-controls"] | //button[@data-cancel-selection] | //*[@data-remove-selected]'));
            $first = Beneficiary::findOrFail($expected[0]);
            $response->assertSee(route('beneficiaries.show', $first))->assertSee(route('beneficiaries.photo', $first))
                ->assertSee('id="rlcr-actions-'.$first->id.'"', false);
        }

        $this->get(route('beneficiaries.index', ['search' => '000001']), ['X-Registry-Fragment' => 'results'])
            ->assertOk()->assertSee('1 result')->assertSee('000001')->assertDontSee('000002');
        $empty = $this->get(route('beneficiaries.index', ['search' => 'No match']), ['X-Registry-Fragment' => 'results'])
            ->assertOk()->assertSee('0 results')->assertSee('No beneficiaries found');
        $emptyXpath = $this->xpath($empty->getContent());
        $this->assertCount(0, $emptyXpath->query('//table | //nav[@class="rlcr-page-links"]'));
        $this->assertSame([], json_decode($emptyXpath->query('//*[@data-registry-results]')->item(0)->getAttribute('data-registry-page'), true)['selectableIds']);
        $this->assertSame($before, Beneficiary::withTrashed()->orderBy('id')->get()->toArray());
    }

    public function test_selected_record_details_and_controls_remain_outside_replaced_results(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $record = $this->record(['full_name' => 'Rhieb One', 'beneficiary_id' => '000001']);
        $response = $this->actingAs($director)->get(route('beneficiaries.index'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@id="rlcr-selection-controls" and not(ancestor::*[@data-registry-results])]'));
        $this->assertCount(1, $xpath->query('//*[@id="rlcr-selection-controls"]//ul[@aria-label="Selected beneficiaries"]//button[@data-remove-selected]'));
        $this->assertCount(0, $xpath->query('//*[@data-registry-results]//*[@id="rlcr-selection-controls"]'));
        $this->assertSame('rlcp.registry.selection.v1.'.$director->id,
            $xpath->query('//*[@data-registry-selection]')->item(0)->getAttribute('data-registry-selection-key'));
        $metadata = json_decode($xpath->query('//*[@data-registry-results]')->item(0)->getAttribute('data-registry-page'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Rhieb One', $metadata['selectionRecords'][$record->id]['name']);
        $this->assertSame('000001', $metadata['selectionRecords'][$record->id]['beneficiary_id']);
        $this->assertTrue($metadata['selectionRecords'][$record->id]['canArchive']);
        $this->assertTrue($metadata['selectionRecords'][$record->id]['canAssign']);
        $this->assertSame([(string) $record->id], $metadata['visibleIds']);
    }

    #[DataProvider('crossSearchBulkActions')]
    public function test_bulk_actions_use_all_three_selected_ids_across_rhieb_and_julyotskie_searches(string $action): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $caseworker = User::factory()->create(['role' => 'caseworker']);
        $first = $this->record(['full_name' => 'Rhieb One', 'beneficiary_id' => '000001']);
        $second = $this->record(['full_name' => 'Rhieb Two', 'beneficiary_id' => '000002']);
        $third = $this->record(['full_name' => 'Julyotskie', 'beneficiary_id' => '000003']);
        $other = $this->record(['full_name' => 'Other Beneficiary']);
        $before = $other->fresh()->getRawOriginal();
        $selectedIds = [];
        foreach (['rhieb', 'Julyotskie'] as $search) {
            $response = $this->get(route('beneficiaries.index', ['search' => $search]), ['X-Registry-Fragment' => 'results'])->assertOk();
            $xpath = $this->xpath($response->getContent());
            $metadata = json_decode($xpath->query('//*[@data-registry-results]')->item(0)->getAttribute('data-registry-page'), true, flags: JSON_THROW_ON_ERROR);
            array_push($selectedIds, ...$metadata['selectableIds']);
        }
        $this->assertSame(array_map('strval', [$first->id, $second->id, $third->id]), $selectedIds);
        $payload = ['beneficiary_ids' => $selectedIds];
        if ($action === 'assign-caseworker') {
            $payload['caseworker_id'] = $caseworker->id;
        }
        $this->post(route('beneficiaries.'.$action, ['search' => 'Julyotskie']), $payload)
            ->assertSessionHasNoErrors()->assertSessionHas('registry_selection_complete', true);
        foreach ([$first, $second, $third] as $record) {
            if ($action === 'assign-caseworker') {
                $this->assertSame($caseworker->id, $record->fresh()->caseworker_id);
            } else {
                $this->assertSoftDeleted($record);
            }
        }
        $this->assertSame($before, $other->fresh()->getRawOriginal());
        $this->get(route('beneficiaries.index', ['search' => 'Julyotskie']))->assertOk()
            ->assertSee('data-registry-selection-complete="true"', false);
    }

    #[DataProvider('crossSearchBulkActions')]
    public function test_failed_bulk_actions_preserve_all_submitted_ids_and_never_mark_selection_complete(string $action): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $ids = array_map(fn ($name) => (string) $this->record(['full_name' => $name])->id, ['Rhieb One', 'Rhieb Two', 'Julyotskie']);
        $before = Beneficiary::withTrashed()->orderBy('id')->get()->toArray();
        $payload = ['beneficiary_ids' => $ids];
        $bag = 'default';
        $field = 'beneficiary_ids';
        if ($action === 'assign-caseworker') {
            $payload['caseworker_id'] = 999999;
            $bag = 'assignCaseworker';
            $field = 'caseworker_id';
        } else {
            $payload['beneficiary_ids'][] = '999999';
        }
        $this->from(route('beneficiaries.index', ['search' => 'Julyotskie']))
            ->post(route('beneficiaries.'.$action), $payload)
            ->assertRedirect(route('beneficiaries.index', ['search' => 'Julyotskie']))
            ->assertSessionHasErrors([$field], null, $bag)
            ->assertSessionHasInput('beneficiary_ids', $payload['beneficiary_ids'])
            ->assertSessionMissing('registry_selection_complete')->assertSessionMissing('success');
        $this->assertSame($before, Beneficiary::withTrashed()->orderBy('id')->get()->toArray());
    }

    public static function crossSearchBulkActions(): array
    {
        return [['assign-caseworker'], ['bulk-archive']];
    }

    public function test_live_fragments_reuse_per_record_permissions_and_do_not_add_caseworker_bulk_controls(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($director);
        $allowed = $this->record(['full_name' => 'Allowed']);
        $blocked = $this->record(['full_name' => 'Blocked', 'beneficiary_id' => 'BLOCKED']);
        Gate::policy(Beneficiary::class, get_class(new class extends BeneficiaryPolicy
        {
            public function delete(User $user, Beneficiary $beneficiary): bool
            {
                return parent::delete($user, $beneficiary) && $beneficiary->beneficiary_id !== 'BLOCKED';
            }

            public function assignCaseworker(User $user, ?Beneficiary $beneficiary = null): bool
            {
                return parent::assignCaseworker($user, $beneficiary) && $beneficiary?->beneficiary_id !== 'BLOCKED';
            }
        }));
        $response = $this->get(route('beneficiaries.index'), ['X-Registry-Fragment' => 'results'])->assertOk();
        $xpath = $this->xpath($response->getContent());
        $metadata = json_decode($xpath->query('//*[@data-registry-results]')->item(0)->getAttribute('data-registry-page'), true);
        $this->assertSame([(string) $allowed->id], $metadata['selectableIds']);
        $this->assertCount(0, $xpath->query('//input[@type="checkbox" and @value="'.$blocked->id.'"]'));
        $caseworker = User::factory()->create(['role' => 'caseworker']);
        $allowed->forceFill(['caseworker_id' => $caseworker->id])->save();
        $this->actingAs($caseworker);
        $this->get(route('beneficiaries.index'), ['X-Registry-Fragment' => 'results'])->assertOk()
            ->assertSee(route('beneficiaries.show', $allowed))->assertSee(route('beneficiaries.edit', $allowed))
            ->assertDontSee('rlcr-selection-controls')->assertDontSee('data-select-all')->assertDontSee('data-assignment-row')
            ->assertDontSee(route('beneficiaries.create'))->assertDontSee('Add Beneficiary');
    }

    #[DataProvider('accessCases')]
    public function test_registry_keeps_existing_authentication_verification_and_permissions(?string $role, bool $verified, int $status): void
    {
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role, 'email_verified_at' => $verified ? now() : null]));
        }
        $this->getJson(route('beneficiaries.index'))->assertStatus($status);
        $this->getJson(route('beneficiaries.index'), ['X-Registry-Fragment' => 'results'])->assertStatus($status);
    }

    public static function accessCases(): array
    {
        return [[null, false, 401], ['admin', true, 403], ['caseworker', false, 403],
            ['caseworker', true, 200], ['project_director', true, 200]];
    }

    private function record(array $attributes = []): Beneficiary
    {
        $record = new Beneficiary;
        $record->forceFill($attributes + [
            'beneficiary_id' => 'REGISTRY-'.++$this->recordNumber, 'full_name' => 'Registry Test Beneficiary',
            'registered_at' => '2026-10-07', 'status' => 'active',
            // Existing Registry UI checks use the actor's own synthetic records.
            'caseworker_id' => auth()->user()?->isCaseworker() ? auth()->id() : null,
        ])->save();

        return $record;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
