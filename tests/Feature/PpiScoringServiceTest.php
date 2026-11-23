<?php

namespace Tests\Feature;

use App\Exceptions\PpiScorecardConfigurationException;
use App\Models\PpiAnswerOption;
use App\Models\PpiQuestion;
use App\Models\PpiScorecard;
use App\Services\PpiScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\PpiTestScorecard;
use Tests\TestCase;

class PpiScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scores_all_answers_with_the_points_stored_on_the_scorecard(): void
    {
        $scorecard = PpiTestScorecard::create();

        $result = app(PpiScoringService::class)->score(
            $scorecard,
            PpiTestScorecard::answers($scorecard),
        );

        $this->assertSame(55, $result->score);
        $this->assertCount(10, $result->answers);
        $this->assertSame(
            array_sum(range(1, 10)),
            array_sum(array_map(
                static fn ($answer): int => $answer->points,
                $result->answers,
            )),
        );
    }

    public function test_negative_weights_are_totaled_without_a_passing_threshold(): void
    {
        $scorecard = PpiScorecard::factory()->create();

        foreach (range(1, 10) as $position) {
            $question = PpiQuestion::factory()->for($scorecard, 'scorecard')->create([
                'code' => 'Q'.$position,
                'prompt' => 'Fictional negative question '.$position,
                'position' => $position,
            ]);

            PpiAnswerOption::factory()->for($question, 'question')->create([
                'code' => 'A',
                'label' => 'Fictional negative option',
                'points' => -3,
                'position' => 1,
            ]);

            PpiAnswerOption::factory()->for($question, 'question')->create([
                'code' => 'B',
                'label' => 'Fictional positive option',
                'points' => 7,
                'position' => 2,
            ]);
        }

        $scorecard = $scorecard->fresh(['questions.options']);

        $result = app(PpiScoringService::class)->score(
            $scorecard,
            PpiTestScorecard::answers($scorecard, 'A'),
        );

        $this->assertSame(-30, $result->score);
    }

    public function test_rejects_missing_and_duplicate_answers(): void
    {
        $scorecard = PpiTestScorecard::create();
        $answers = PpiTestScorecard::answers($scorecard);
        $answers[9] = $answers[0];

        try {
            app(PpiScoringService::class)->score($scorecard, $answers);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers', $exception->errors());
            $this->assertArrayHasKey('answers.9.question_id', $exception->errors());
        }
    }

    public function test_rejects_an_option_from_another_question(): void
    {
        $scorecard = PpiTestScorecard::create();
        $otherScorecard = PpiTestScorecard::create('test-2');
        $answers = PpiTestScorecard::answers($scorecard);
        $answers[0]['option_id'] = (int) $otherScorecard->questions->first()?->options->first()?->id;

        try {
            app(PpiScoringService::class)->score($scorecard, $answers);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers.0.option_id', $exception->errors());
        }
    }

    public function test_rejects_a_question_that_is_not_on_the_scorecard(): void
    {
        $scorecard = PpiTestScorecard::create();
        $answers = PpiTestScorecard::answers($scorecard);
        $answers[0]['question_id'] = 999999;

        try {
            app(PpiScoringService::class)->score($scorecard, $answers);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers.0.question_id', $exception->errors());
        }
    }

    public function test_rejects_a_scorecard_that_does_not_have_ten_questions(): void
    {
        $scorecard = PpiScorecard::factory()->create();

        foreach (range(1, 9) as $position) {
            PpiQuestion::factory()->for($scorecard, 'scorecard')->create([
                'code' => 'Q'.$position,
                'position' => $position,
            ]);
        }

        $this->expectException(PpiScorecardConfigurationException::class);

        app(PpiScoringService::class)->score(
            $scorecard->fresh(['questions.options']),
            [],
        );
    }
}
