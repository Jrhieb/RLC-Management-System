<?php

namespace Tests\Feature;

use App\Models\PpiScorecard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PpiScorecardImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_the_verified_may_2018_philippines_scorecard(): void
    {
        $this->artisan('ppi:import-scorecard', [
            'file' => database_path('seeders/philippines-ppi-2018.json'),
            '--activate' => true,
        ])->assertSuccessful();

        $scorecard = PpiScorecard::query()->with('questions.options')->sole();
        $this->assertSame('ph-2015-fies-2018-05', $scorecard->version);
        $this->assertSame([
            [0, 8, 8, 6, 9, 8, 5, 3, 8, 5, 2, 0, 6, 2, 12, 8, 0],
            [0, 9, 18], [0, 6, 8], [0, 4, 6], [0, 6, 8], [0, 6],
            [0, 5, 10, 7, 6, 5], [0, 14], [0, 11], [0, 7],
        ], $scorecard->questions->map(fn ($question): array => $question->options->pluck('points')->all())->all());
    }

    public function test_imports_the_fictional_fixture_and_activates_it(): void
    {
        $this->artisan('ppi:import-scorecard', [
            'file' => base_path('tests/Fixtures/fictional-ppi-scorecard.json'),
            '--activate' => true,
        ])->assertSuccessful();

        $scorecard = PpiScorecard::query()->sole();
        $this->assertTrue($scorecard->is_active);
        $this->assertSame('fixture-1', $scorecard->version);
        $this->assertSame(10, $scorecard->questions()->count());
        $this->assertSame(20, $scorecard->questions()->withCount('options')->get()->sum('options_count'));
        $this->assertDatabaseHas('ppi_answer_options', [
            'code' => 'A',
            'label' => 'Fictional option A',
            'points' => 10,
        ]);
        $this->assertStringStartsWith('FICTIONAL TEST FIXTURE', $scorecard->source_reference);
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public function test_rejects_a_scorecard_without_ten_questions(): void
    {
        $this->artisan('ppi:import-scorecard', [
            'file' => base_path('tests/Fixtures/invalid-ppi-scorecard-nine-questions.json'),
            '--activate' => true,
        ])->assertFailed();

        $this->assertDatabaseCount('ppi_scorecards', 0);
        $this->assertDatabaseCount('ppi_questions', 0);
        $this->assertDatabaseCount('ppi_answer_options', 0);
    }

    public function test_rejects_a_malformed_json_file(): void
    {
        $this->artisan('ppi:import-scorecard', [
            'file' => base_path('tests/Fixtures/malformed-ppi-scorecard.json'),
        ])->assertFailed();

        $this->assertDatabaseCount('ppi_scorecards', 0);
    }

    public function test_rejects_a_missing_file(): void
    {
        $this->artisan('ppi:import-scorecard', [
            'file' => base_path('tests/Fixtures/missing-ppi-scorecard.json'),
        ])->assertFailed();

        $this->assertDatabaseCount('ppi_scorecards', 0);
    }

    public function test_duplicate_versions_are_rejected_without_partial_data(): void
    {
        $file = base_path('tests/Fixtures/fictional-ppi-scorecard.json');

        $this->artisan('ppi:import-scorecard', ['file' => $file])->assertSuccessful();
        $this->artisan('ppi:import-scorecard', ['file' => $file])->assertFailed();

        $this->assertDatabaseCount('ppi_scorecards', 1);
        $this->assertDatabaseCount('ppi_questions', 10);
        $this->assertDatabaseCount('ppi_answer_options', 20);
    }
}
