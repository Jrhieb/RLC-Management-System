<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use App\Policies\BeneficiaryPolicy;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BeneficiarySearchTest extends TestCase
{
    use RefreshDatabase;

    private int $recordNumber = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Live beneficiary search tests require guarded SQLite :memory:.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_suggestions_reuse_protected_urls_and_only_return_necessary_fields_without_writing_records(): void
    {
        Storage::fake('local');
        $this->director();
        $path = UploadedFile::fake()->image('private.jpg')->store('beneficiary-photos', 'local');
        $record = $this->record(['full_name' => 'Angel Reyes', 'beneficiary_id' => '0027947780', 'photo_path' => $path,
            'nfc_uid' => '04A1B2C3', 'address' => 'Private address', 'guardian_contact' => 'Private contact']);
        $before = DB::table('beneficiaries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $response = $this->getJson(route('beneficiaries.suggestions', ['search' => ' Angel ']))->assertOk()
            ->assertExactJson(['results' => [[
                'id' => $record->id, 'name' => 'Angel Reyes', 'beneficiary_id' => '0027947780',
                'url' => route('beneficiaries.show', $record), 'photo_url' => route('beneficiaries.photo', $record),
            ]]])->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach ([$path, '04A1B2C3', 'Private address', 'Private contact', 'caseworker_id', 'photo_path'] as $private) {
            $response->assertDontSee($private);
        }
        $this->getJson(route('beneficiaries.suggestions', ['search' => '0027947780']))->assertOk()
            ->assertJsonPath('results.0.beneficiary_id', '0027947780')->assertJsonCount(1, 'results');
        $this->get(route('beneficiaries.photo', $record))->assertOk();
        Auth::forgetGuards();
        $this->getJson(route('beneficiaries.photo', $record))->assertUnauthorized();
        $this->assertSame($before, DB::table('beneficiaries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_suggestions_are_alphabetical_with_id_ties_limited_to_eight_and_exclude_archived(): void
    {
        $this->director();
        $expected = [];
        foreach ([16, 8, 3, 12, 5, 2, 11, 4, 17, 9] as $id) {
            $expected[] = $this->record(['id' => $id, 'full_name' => 'Alex Rivera'])->id;
        }
        $this->record(['id' => 30, 'full_name' => 'Alex Archived'])->delete();
        sort($expected);
        $response = $this->getJson(route('beneficiaries.suggestions', ['search' => 'Alex']))->assertOk()->assertJsonCount(8, 'results');
        $this->assertSame(array_slice($expected, 0, 8), array_column($response->json('results'), 'id'));
        $this->assertNull($response->json('results.0.photo_url'));
    }

    public function test_director_suggestions_combine_status_and_caseworker_filters(): void
    {
        $actor = User::factory()->create(['role' => 'caseworker']);
        $other = User::factory()->create(['role' => 'caseworker']);
        $unverified = User::factory()->create(['role' => 'caseworker', 'email_verified_at' => null]);
        $this->director();
        $active = $this->record(['full_name' => 'Alpha Active', 'caseworker_id' => $other->id]);
        $inactive = $this->record(['full_name' => 'Alpha Inactive', 'status' => 'inactive', 'caseworker_id' => $other->id]);
        $otherIdMatch = $this->record(['beneficiary_id' => 'Alpha-ID', 'full_name' => 'Zoe', 'caseworker_id' => $actor->id]);
        $unassigned = $this->record(['full_name' => 'Alpha Unassigned']);
        $inactiveUnassigned = $this->record(['full_name' => 'Alpha Unassigned Inactive', 'status' => 'inactive']);
        $unverifiedMatch = $this->record(['full_name' => 'Alpha Unverified', 'caseworker_id' => $unverified->id]);
        $this->record(['full_name' => 'Alpha Archived', 'caseworker_id' => $other->id])->delete();
        $this->record(['full_name' => 'Alpha Archived Unassigned'])->delete();
        $cases = [
            [['caseworker' => $other->id, 'status' => 'active'], [$active->id]],
            [['caseworker' => $other->id, 'status' => 'inactive'], [$inactive->id]],
            [['caseworker' => $other->id], [$active->id, $inactive->id]],
            [['caseworker' => $actor->id], [$otherIdMatch->id]],
            [['caseworker' => 'unassigned', 'status' => 'active'], [$unassigned->id]],
            [['caseworker' => 'unassigned', 'status' => 'inactive'], [$inactiveUnassigned->id]],
            [['caseworker' => $unverified->id], [$unverifiedMatch->id]],
        ];
        foreach ($cases as [$filters, $expected]) {
            $response = $this->getJson(route('beneficiaries.suggestions', ['search' => 'Alpha'] + $filters))->assertOk();
            $this->assertSame($expected, array_column($response->json('results'), 'id'));
            $index = $this->get(route('beneficiaries.index', ['search' => 'Alpha'] + $filters))->assertOk();
            $this->assertSame($expected, $index->viewData('beneficiaries')->pluck('id')->all());
        }
        $this->getJson(route('beneficiaries.suggestions', ['search' => 'Alpha']))->assertOk()->assertJsonCount(6, 'results');
        $this->get(route('beneficiaries.show', $active))->assertOk();
    }

    #[DataProvider('emptySearches')]
    public function test_empty_and_unmatched_searches_return_an_empty_result_list(array $query): void
    {
        $this->director();
        $this->record();
        $this->getJson(route('beneficiaries.suggestions', $query))->assertOk()->assertExactJson(['results' => []]);
    }

    public static function emptySearches(): array
    {
        return [[[]], [['search' => '']], [['search' => '   ']], [['search' => 'Not found']]];
    }

    #[DataProvider('invalidQueries')]
    public function test_malformed_or_invalid_filters_are_rejected_without_returning_unfiltered_records(array $query, string $error): void
    {
        $director = $this->director();
        $this->record();
        if (($query['caseworker'] ?? null) === 'director') {
            $query['caseworker'] = $director->id;
        }
        $this->getJson(route('beneficiaries.suggestions', $query + ['search' => 'Search']))
            ->assertUnprocessable()->assertJsonValidationErrors($error)->assertJsonMissingPath('results');
        $this->assertDatabaseCount('beneficiaries', 1);
    }

    public static function invalidQueries(): array
    {
        return [[['search' => ['invalid']], 'search'], [['search' => str_repeat('a', 101)], 'search'],
            [['status' => 'archived'], 'status'], [['status' => ['active']], 'status'],
            [['caseworker' => ['unassigned']], 'caseworker'], [['caseworker' => '-1'], 'caseworker'],
            [['caseworker' => '0'], 'caseworker'], [['caseworker' => '999999'], 'caseworker'],
            [['caseworker' => 'director'], 'caseworker'], [['caseworker' => '1 or 1=1'], 'caseworker']];
    }

    public function test_suggestion_endpoint_authorizes_every_returned_profile(): void
    {
        $this->director();
        $allowed = $this->record(['full_name' => 'Search Allowed']);
        $this->record(['beneficiary_id' => 'BLOCKED', 'full_name' => 'Search Blocked', 'photo_path' => 'beneficiary-photos/blocked.jpg']);
        Gate::policy(Beneficiary::class, get_class(new class extends BeneficiaryPolicy
        {
            public function view(User $user, Beneficiary $beneficiary): bool
            {
                return parent::view($user, $beneficiary) && $beneficiary->beneficiary_id !== 'BLOCKED';
            }
        }));
        $this->getJson(route('beneficiaries.suggestions', ['search' => 'Search']))->assertOk()->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.id', $allowed->id)->assertDontSee('BLOCKED')->assertDontSee('Search Blocked');
    }

    #[DataProvider('accessCases')]
    public function test_existing_suggestion_authentication_verification_and_permissions_are_preserved(?string $role, bool $verified, int $status): void
    {
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role, 'email_verified_at' => $verified ? now() : null]));
        }
        $this->getJson(route('beneficiaries.suggestions', ['search' => 'Search']))->assertStatus($status);
    }

    public static function accessCases(): array
    {
        return [[null, false, 401], ['admin', true, 403], ['caseworker', false, 403],
            ['project_director', false, 403], ['caseworker', true, 200], ['project_director', true, 200]];
    }

    public function test_registry_filters_actual_results_without_suggestions_or_an_arrow_button(): void
    {
        $this->director();
        $caseworker = User::factory()->create(['role' => 'caseworker']);
        $this->record(['full_name' => 'Search Match', 'caseworker_id' => $caseworker->id]);
        $response = $this->get(route('beneficiaries.index', ['search' => 'Search', 'caseworker' => $caseworker->id, 'status' => 'active']))->assertOk()
            ->assertSee('data-registry-results', false)->assertSee('Updating records')
            ->assertSee('Unable to update records.')->assertSee('Clear search')
            ->assertDontSee(route('beneficiaries.suggestions'));
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//form[@class="rlcr-search-form"]//input[@id="rlcr-search" and @name="search" and @value="Search" and @aria-controls="rlcr-results"]'));
        $this->assertCount(0, $xpath->query('//form[@class="rlcr-search-form"]//form | //form[@class="rlcr-search-form"]//button[@type="submit"]'));
        $this->assertCount(1, $xpath->query('//form[@class="rlcr-search-form"]//button[@data-clear-beneficiary-search and @type="button"]'));
        $this->assertCount(0, $xpath->query('//form[@class="rlcr-search-form"]//input[@role="combobox"] | //ul[@id="rlcr-search-results"] | //div[@class="rlcr-search-wrap"]//*[@popover]'));
        $this->assertCount(1, $xpath->query('//select[@name="caseworker"]/option[@selected and @value="'.$caseworker->id.'"]'));
        $this->assertCount(1, $xpath->query('//select[@name="status"]/option[@selected and @value="active"]'));
        $this->assertCount(1, $xpath->query('//*[@data-assignment-row]'));
    }

    public function test_dashboard_keeps_the_existing_default_search_form_and_unfiltered_endpoint(): void
    {
        $this->director();
        $response = $this->get(route('dashboard'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//div[@class="rlc-search-wrap" and @data-registry="false"]//form[@method="GET"]//input[@id="dashboard-beneficiary-search"]'));
        $this->assertCount(1, $xpath->query('//ul[@id="rlc-search-results" and @role="listbox"]'));
        $this->assertCount(0, $xpath->query('//div[@class="rlc-search-wrap" and @data-registry="false"]//*[self::button[@data-clear-beneficiary-search] or self::div[@popover="manual"] or self::select[@name="caseworker"]]'));
        $active = $this->record(['full_name' => 'Dashboard Match Active']);
        $inactive = $this->record(['full_name' => 'Dashboard Match Inactive', 'status' => 'inactive']);
        $this->getJson(route('beneficiaries.suggestions', ['search' => 'Dashboard Match']))->assertOk()
            ->assertJsonPath('results.0.id', $active->id)->assertJsonPath('results.1.id', $inactive->id);
    }

    private function director(): User
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($director);

        return $director;
    }

    private function record(array $attributes = []): Beneficiary
    {
        $record = new Beneficiary;
        $record->forceFill($attributes + ['beneficiary_id' => 'SEARCH-'.++$this->recordNumber, 'full_name' => 'Search Test Beneficiary',
            'registered_at' => '2026-10-07', 'status' => 'active'])->save();

        return $record->refresh();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
