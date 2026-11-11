<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BeneficiaryCaseloadsTest extends TestCase
{
    use RefreshDatabase;

    private int $recordNumber = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Caseload tests require the isolated SQLite :memory: test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_caseworker_filter_lists_real_caseworkers_in_name_order_including_unverified_accounts(): void
    {
        $this->director();
        $zoe = $this->caseworker(['name' => 'Zoe Santos']);
        $alex = $this->caseworker(['name' => 'Alex Cruz', 'email_verified_at' => null]);
        User::factory()->create(['name' => 'Not a Caseworker', 'role' => 'admin']);
        $record = $this->record(['caseworker_id' => $alex->id, 'beneficiary_id' => '000007']);

        $response = $this->get(route('beneficiaries.index', ['caseworker' => $alex->id]))->assertOk()
            ->assertViewHas('caseworkerFilter', (string) $alex->id)->assertViewHas('caseloadName', 'Alex Cruz')
            ->assertViewHas('beneficiaries', fn ($records) => $records->pluck('id')->all() === [$record->id])
            ->assertSee('000007')->assertSee('Back to All Beneficiaries');
        $xpath = $this->xpath($response->getContent());
        $options = $xpath->query('//select[@name="caseworker"]/option');
        $this->assertSame(['All Caseworkers', 'Unassigned', 'Alex Cruz', 'Zoe Santos'],
            array_map(fn ($option) => trim($option->textContent), iterator_to_array($options)));
        $this->assertSame((string) $alex->id, $xpath->query('//select[@name="caseworker"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertCount(0, $xpath->query('//*[@data-assignment-destination]/option[@value="'.$alex->id.'"]'));
        $this->assertCount(1, $xpath->query('//*[@data-assignment-destination]/option[@value="'.$zoe->id.'"]'));
    }

    public function test_combined_filters_preserve_alphabetical_order_ties_and_pagination(): void
    {
        $this->director();
        $caseworker = $this->caseworker();
        $other = $this->caseworker();
        $expected = [];
        for ($i = 12; $i >= 0; $i--) {
            $expected[] = $this->record(['full_name' => 'Alex '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'caseworker_id' => $caseworker->id]);
        }
        $expected[] = $this->record(['full_name' => 'Alex 00', 'caseworker_id' => $caseworker->id]);
        $this->record(['full_name' => 'Alex inactive', 'status' => 'inactive', 'caseworker_id' => $caseworker->id]);
        $this->record(['full_name' => 'Alex other caseworker', 'caseworker_id' => $other->id]);
        $this->record(['full_name' => 'Alex unassigned']);
        $this->record(['full_name' => 'Zoe', 'caseworker_id' => $caseworker->id]);
        $this->record(['full_name' => 'Alex archived', 'caseworker_id' => $caseworker->id])->delete();
        usort($expected, fn ($a, $b) => strcmp($a->full_name, $b->full_name) ?: $a->id <=> $b->id);
        $filters = ['search' => 'Alex', 'status' => 'active', 'caseworker' => (string) $caseworker->id];

        $response = $this->get(route('beneficiaries.index', $filters))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 14 && $records->count() === 10);
        $xpath = $this->xpath($response->getContent());
        $next = $xpath->query('//nav[@class="rlcr-page-links"]/a[@rel="next"]')->item(0)->getAttribute('href');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame($filters + ['page' => '2'], $query);
        $second = $this->get($next)->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 14 && $records->count() === 4);
        $this->assertSame(array_map(fn ($record) => $record->id, $expected),
            $response->viewData('beneficiaries')->pluck('id')->merge($second->viewData('beneficiaries')->pluck('id'))->all());
        $this->assertTrue($response->viewData('beneficiaries')->every(fn ($record) => $record->relationLoaded('caseworker')));
        $this->get(route('beneficiaries.index', array_replace($filters, ['status' => 'inactive'])))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 1 && $records->first()->full_name === 'Alex inactive');
    }

    public function test_unassigned_filter_combines_exact_zero_prefixed_id_search_and_status(): void
    {
        $this->director();
        $caseworker = $this->caseworker();
        $match = $this->record(['beneficiary_id' => '0027947780', 'status' => 'inactive']);
        $this->record(['beneficiary_id' => '0027947781', 'status' => 'active']);
        $this->record(['beneficiary_id' => '0027947782', 'status' => 'inactive', 'caseworker_id' => $caseworker->id]);
        $this->record(['beneficiary_id' => '0027947783', 'status' => 'inactive'])->delete();
        $response = $this->get(route('beneficiaries.index', ['caseworker' => 'unassigned', 'search' => '002794', 'status' => 'inactive']))
            ->assertOk()->assertSee('0027947780')->assertViewHas('caseloadName', 'Unassigned')
            ->assertViewHas('beneficiaries', fn ($records) => $records->pluck('id')->all() === [$match->id]);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame('Unassigned', trim($xpath->query('//*[@data-selected-caseload]/div/h3')->item(0)->textContent));
        $this->assertSame('unassigned', $xpath->query('//select[@name="caseworker"]/option[@selected]')->item(0)->getAttribute('value'));
    }

    public function test_caseload_cards_use_real_counts_excluding_archived_and_include_inactive_unassigned_and_zero_counts(): void
    {
        $this->director();
        $zoe = $this->caseworker(['name' => 'Zoe Santos']);
        $alex = $this->caseworker(['name' => 'Alex Cruz', 'email_verified_at' => null]);
        $empty = $this->caseworker(['name' => 'Empty Caseworker']);
        User::factory()->create(['name' => 'Hidden Admin', 'role' => 'admin']);
        $active = $this->record(['caseworker_id' => $zoe->id]);
        $inactive = $this->record(['caseworker_id' => $zoe->id, 'status' => 'inactive']);
        $archived = $this->record(['caseworker_id' => $zoe->id]);
        $archived->delete();
        $this->record(['caseworker_id' => $alex->id]);
        $this->record();
        $this->record(['status' => 'inactive']);
        $this->record()->delete();

        // Caseload totals cover all non-archived records, independent of table filters.
        $response = $this->get(route('beneficiaries.index', ['tab' => 'caseloads', 'search' => 'missing', 'status' => 'active']))
            ->assertOk()->assertSee('Caseworker Caseloads')->assertSee('No beneficiaries assigned')
            ->assertDontSee('Hidden Admin')->assertViewHas('unassignedCount', 2)->assertViewHas('beneficiaries', null);
        $this->assertSame([$alex->id => 1, $empty->id => 0, $zoe->id => 2],
            $response->viewData('registryCaseworkers')->pluck('assigned_beneficiaries_count', 'id')->all());
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(4, $xpath->query('//a[@data-caseload]'));
        $this->assertSame('ZS', trim($xpath->query('//a[@data-caseload="'.$zoe->id.'"]//span[@aria-hidden="true"]')->item(0)->textContent));
        $this->assertSame('2', trim($xpath->query('//a[@data-caseload="unassigned"]//strong')->item(0)->textContent));
        $this->assertCount(0, $xpath->query('//table[@class="rlcr-table"]'));
        $link = $xpath->query('//a[@data-caseload="'.$zoe->id.'"]')->item(0)->getAttribute('href');
        $filtered = $this->get($link)->assertOk()->assertViewHas('caseloadName', 'Zoe Santos');
        $this->assertEqualsCanonicalizing([$active->id, $inactive->id], $filtered->viewData('beneficiaries')->pluck('id')->all());
        $filtered->assertDontSee($archived->beneficiary_id);
        $this->get($xpath->query('//a[@data-caseload="unassigned"]')->item(0)->getAttribute('href'))->assertOk()
            ->assertViewHas('caseworkerFilter', 'unassigned')->assertViewHas('beneficiaries', fn ($records) => $records->total() === 2);
    }

    public function test_counts_update_after_archive_restore_assignment_and_unassignment(): void
    {
        $this->director();
        $first = $this->caseworker();
        $second = $this->caseworker();
        $record = $this->record(['caseworker_id' => $first->id, 'nfc_uid' => '0027947780']);
        $this->assertCounts([$first->id => 1, $second->id => 0], 0);
        $this->delete(route('beneficiaries.destroy', $record))->assertRedirect();
        $this->assertCounts([$first->id => 0, $second->id => 0], 0);
        $this->post(route('beneficiaries.restore', $record))->assertRedirect();
        $this->assertCounts([$first->id => 1, $second->id => 0], 0);
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => $second->id])->assertRedirect();
        $this->assertCounts([$first->id => 0, $second->id => 1], 0);
        $this->post(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => 'unassign'])->assertRedirect();
        $this->assertCounts([$first->id => 0, $second->id => 0], 1);
        $this->assertSame('0027947780', $record->fresh()->nfc_uid);
    }

    public function test_caseload_counts_use_a_bounded_number_of_queries_as_accounts_grow(): void
    {
        $this->director();
        $first = $this->caseworker();
        $this->record(['caseworker_id' => $first->id]);
        $small = $this->caseloadQueries();
        for ($i = 0; $i < 24; $i++) {
            $account = $this->caseworker(['name' => 'Caseworker '.$i]);
            $this->record(['caseworker_id' => $account->id]);
        }
        $large = $this->caseloadQueries();
        $this->assertSame(count($small), count($large));
        $this->assertCount(1, array_filter($large, fn ($query) => str_contains($query['query'], 'from "users"')));
        $countQuery = array_values(array_filter($large, fn ($query) => str_contains($query['query'], 'assigned_beneficiaries_count')));
        $this->assertCount(1, $countQuery);
        $this->assertStringContainsString('"beneficiaries"."deleted_at" is null', $countQuery[0]['query']);
    }

    public function test_assignment_controls_and_redirects_reuse_the_filtered_registry(): void
    {
        $this->director();
        $from = $this->caseworker(['name' => 'Current Caseworker']);
        $to = $this->caseworker(['name' => 'Destination Caseworker']);
        $selected = $this->record(['full_name' => 'Alex Selected', 'caseworker_id' => $from->id]);
        $this->record(['full_name' => 'Alex Other', 'caseworker_id' => $to->id]);
        $filters = ['search' => 'Alex', 'status' => 'active', 'caseworker' => (string) $from->id, 'page' => '1'];
        $response = $this->get(route('beneficiaries.index', $filters))->assertOk()
            ->assertSee('pageIds: '.Js::from([(string) $selected->id])->toHtml(), false);
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@data-assignment-row]'));
        $this->assertCount(1, $xpath->query('//*[@data-assignment-bulk]'));
        $this->assertSame(route('beneficiaries.assign-caseworker', $filters), $xpath->query('//form[@data-assignment-form]')->item(0)->getAttribute('action'));
        $this->post(route('beneficiaries.assign-caseworker', $filters), ['beneficiary_ids' => [$selected->id], 'caseworker_id' => $to->id])
            ->assertRedirect(route('beneficiaries.index', $filters))->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 beneficiary assigned to Destination Caseworker.');
        $this->get(route('beneficiaries.index', $filters))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->isEmpty());
        $this->assertSame($to->id, $selected->fresh()->caseworker_id);
    }

    public function test_invalid_bulk_selection_keeps_assignments_and_reopens_modal_in_filtered_registry(): void
    {
        $this->director();
        $from = $this->caseworker();
        $to = $this->caseworker();
        $record = $this->record(['caseworker_id' => $from->id]);
        $archived = $this->record(['caseworker_id' => $from->id]);
        $archived->delete();
        $filters = ['caseworker' => (string) $from->id, 'status' => 'active'];
        $url = route('beneficiaries.index', $filters);
        $this->from($url)->post(route('beneficiaries.assign-caseworker', $filters),
            ['beneficiary_ids' => [$record->id, $archived->id], 'caseworker_id' => $to->id])
            ->assertRedirect($url)->assertSessionHasErrors(['beneficiary_ids'], null, 'assignCaseworker')->assertSessionMissing('success');
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get($url)->assertOk()
            ->assertSee('Some selected records are unavailable or archived')->assertViewHas('caseworkerFilter', (string) $from->id);
        $this->assertStringContainsString('if (true)', $this->xpath($response->getContent())->query('//*[@data-assignment-controller]')->item(0)->getAttribute('x-init'));
        $this->assertSame($from->id, $record->fresh()->caseworker_id);
        $this->assertSame($from->id, $archived->fresh()->caseworker_id);
    }

    public function test_empty_states_and_tabs_offer_working_navigation(): void
    {
        $this->director();
        $this->get(route('beneficiaries.index', ['tab' => 'caseloads']))->assertOk()
            ->assertSee('No caseworker accounts yet')->assertSee('No unassigned beneficiaries')->assertViewHas('unassignedCount', 0);
        $account = $this->caseworker(['name' => 'Empty Caseworker']);
        $response = $this->get(route('beneficiaries.index', ['caseworker' => $account->id]))->assertOk()
            ->assertSee('No non-archived beneficiaries are assigned to Empty Caseworker.')->assertSee('Back to All Beneficiaries');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(route('beneficiaries.index'), $xpath->query('//*[@data-selected-caseload]/a')->item(0)->getAttribute('href'));
        $this->assertCount(1, $xpath->query('//nav[@aria-label="Registry tabs"]/a[@aria-current="page" and normalize-space(.)="All Beneficiaries"]'));
        $tabUrl = $xpath->query('//nav[@aria-label="Registry tabs"]/a[normalize-space(.)="Caseworker Caseloads"]')->item(0)->getAttribute('href');
        $cards = $this->get($tabUrl)->assertOk()->assertDontSee('No caseworker accounts yet')->assertSee('No beneficiaries assigned');
        $this->assertCount(1, $this->xpath($cards->getContent())->query('//nav[@aria-label="Registry tabs"]/a[@aria-current="page" and normalize-space(.)="Caseworker Caseloads"]'));
        $this->get(route('beneficiaries.index', ['caseworker' => 'unassigned']))->assertOk()->assertSee('There are no unassigned beneficiaries.');
        $this->get(route('beneficiaries.index', ['caseworker' => $account->id, 'search' => 'Missing']))->assertOk()
            ->assertSee('Try another name, beneficiary ID, caseworker, or status.')->assertSee('Clear filters');
        $this->record(['caseworker_id' => $account->id]);
        $this->get(route('beneficiaries.index', ['caseworker' => $account->id, 'page' => 9]))->assertOk()
            ->assertSee('There are no beneficiaries on this page.')->assertSee('Return to first page');
    }

    #[DataProvider('unsupportedFilters')]
    public function test_unsupported_caseworker_filters_fall_back_to_all_without_errors(mixed $filter): void
    {
        $director = $this->director();
        $record = $this->record();
        if ($filter === 'director') {
            $filter = $director->id;
        }
        $this->get(route('beneficiaries.index', ['caseworker' => $filter]))->assertOk()
            ->assertViewHas('caseworkerFilter', '')->assertViewHas('caseloadName', null)
            ->assertViewHas('beneficiaries', fn ($records) => $records->pluck('id')->all() === [$record->id]);
    }

    public static function unsupportedFilters(): array
    {
        return [['missing'], ['999999'], ['-1'], [['unassigned']], ['director']];
    }

    public function test_caseworkers_only_browse_their_own_caseload_without_assignment_permissions(): void
    {
        $actor = $this->caseworker();
        $other = $this->caseworker();
        $this->actingAs($actor);
        $record = $this->record(['caseworker_id' => $other->id]);
        $own = $this->record(['caseworker_id' => $actor->id]);
        $this->record();
        $this->get(route('beneficiaries.index', ['tab' => 'caseloads']))->assertOk()->assertSee($actor->name)
            ->assertDontSee($other->name)->assertDontSee('data-caseload="unassigned"', false)->assertDontSee('Add Caseworker');
        $response = $this->get(route('beneficiaries.index', ['caseworker' => $other->id]))->assertOk()
            ->assertDontSee(route('beneficiaries.show', $record))->assertSee(route('beneficiaries.show', $own))
            ->assertDontSee('Assign / Reassign Caseworker')
            ->assertViewHas('beneficiaries', fn ($records) => $records->pluck('id')->all() === [$own->id]);
        $this->assertCount(0, $this->xpath($response->getContent())->query('//*[@data-assignment-controller]'));
        $this->get(route('beneficiaries.show', $record))->assertForbidden();
        $this->get(route('beneficiaries.index'))->assertOk()->assertViewHas('beneficiaries', fn ($records) => $records->total() === 1);
        $this->postJson(route('beneficiaries.assign-caseworker'), ['beneficiary_ids' => [$record->id], 'caseworker_id' => $actor->id])->assertForbidden();
    }

    #[DataProvider('unauthorizedActors')]
    public function test_caseload_tab_keeps_existing_authentication_and_verification(?string $role, bool $verified, int $status): void
    {
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role, 'email_verified_at' => $verified ? now() : null]));
        }
        $this->getJson(route('beneficiaries.index', ['tab' => 'caseloads']))->assertStatus($status);
    }

    public static function unauthorizedActors(): array
    {
        return [[null, false, 401], ['admin', true, 403], ['caseworker', false, 403], ['project_director', false, 403]];
    }

    public function test_caseworker_names_and_malformed_query_values_render_safely(): void
    {
        $this->director();
        $account = $this->caseworker(['name' => '<script>alert(1)</script>']);
        $this->get(route('beneficiaries.index', ['caseworker' => $account->id, 'search' => ['invalid'], 'status' => ['active'], 'tab' => ['caseloads']]))
            ->assertOk()->assertSee($account->name)->assertDontSee($account->name, false)
            ->assertViewHas('search', '')->assertViewHas('status', '')->assertViewHas('tab', 'beneficiaries');
        $this->get(route('beneficiaries.index', ['tab' => 'caseloads']))->assertOk()
            ->assertSee($account->name)->assertDontSee($account->name, false);
    }

    private function caseloadQueries(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->get(route('beneficiaries.index', ['tab' => 'caseloads']))->assertOk();

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
    }

    private function assertCounts(array $expected, int $unassigned): void
    {
        $response = $this->get(route('beneficiaries.index', ['tab' => 'caseloads']))->assertOk()->assertViewHas('unassignedCount', $unassigned);
        $this->assertEquals($expected, $response->viewData('registryCaseworkers')->pluck('assigned_beneficiaries_count', 'id')->all());
    }

    private function director(): User
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($director);

        return $director;
    }

    private function caseworker(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'caseworker']);
    }

    private function record(array $attributes = []): Beneficiary
    {
        $record = new Beneficiary;
        $record->forceFill($attributes + [
            'beneficiary_id' => 'CASELOAD-'.++$this->recordNumber, 'full_name' => 'Caseload Test Beneficiary',
            'registered_at' => '2026-10-07', 'status' => 'active',
        ])->save();

        return $record->refresh();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
