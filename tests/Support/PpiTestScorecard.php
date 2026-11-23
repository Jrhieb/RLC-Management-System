<?php

namespace Tests\Support;

use App\Models\PpiAnswerOption;
use App\Models\PpiQuestion;
use App\Models\PpiScorecard;
use Illuminate\Support\Str;

final class PpiTestScorecard
{
    public static function create(string $version = 'test-1'): PpiScorecard
    {
        $scorecard = PpiScorecard::factory()->create([
            'name' => 'Fictional PPI Test Scorecard',
            'version' => $version,
        ]);

        foreach (range(1, 10) as $position) {
            $question = PpiQuestion::factory()->for($scorecard, 'scorecard')->create([
                'code' => 'Q'.$position,
                'prompt' => 'Fictional PPI question '.$position,
                'position' => $position,
            ]);

            PpiAnswerOption::factory()->for($question, 'question')->create([
                'code' => 'A',
                'label' => 'Fictional option A for question '.$position,
                'points' => $position,
                'position' => 1,
            ]);

            PpiAnswerOption::factory()->for($question, 'question')->create([
                'code' => 'B',
                'label' => 'Fictional option B for question '.$position,
                'points' => 0,
                'position' => 2,
            ]);
        }

        return $scorecard->fresh(['questions.options']);
    }

    /**
     * @return list<array{question_id: int, option_id: int}>
     */
    public static function answers(PpiScorecard $scorecard, string $optionCode = 'A'): array
    {
        return $scorecard->questions
            ->map(fn (PpiQuestion $question): array => [
                'question_id' => $question->id,
                'option_id' => (int) $question->options->firstWhere('code', $optionCode)?->id,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{question_id: int, option_id: int}>  $answers
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $answers, array $overrides = []): array
    {
        return array_merge([
            'submission_key' => (string) Str::uuid(),
            'child_first_name' => 'Fictional',
            'child_middle_name' => 'Test',
            'child_last_name' => 'Child',
            'child_suffix' => null,
            'child_sex' => 'female',
            'child_date_of_birth' => '2015-05-10',
            'guardian_name' => 'Fictional Guardian',
            'guardian_relationship' => 'Parent',
            'contact_number' => '09171234567',
            'email' => 'fictional@example.test',
            'barangay' => 'Fictional Barangay',
            'city_municipality' => 'Fictional City',
            'complete_address' => '123 Fictional Street',
            'privacy_acknowledgment' => '1',
            'answers' => $answers,
            'household_members' => [
                ['name' => 'Fictional Member', 'relationship' => 'Mother', 'monthly_income' => 5000],
            ],
        ], $overrides);
    }
}
