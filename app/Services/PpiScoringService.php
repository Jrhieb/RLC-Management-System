<?php

namespace App\Services;

use App\Exceptions\PpiScorecardConfigurationException;
use App\Models\PpiScorecard;
use App\Support\PpiScoredAnswer;
use App\Support\PpiScoreResult;
use Illuminate\Validation\ValidationException;

class PpiScoringService
{
    /**
     * Score the answers against the stored scorecard weights.
     *
     * This service never applies a passing threshold and never approves or
     * rejects an application. It only validates the answer set and totals the
     * points defined by the official scorecard.
     *
     * @param  array<int, array{question_id?: mixed, option_id?: mixed}>  $answers
     *
     * @throws ValidationException
     * @throws PpiScorecardConfigurationException
     */
    public function score(PpiScorecard $scorecard, array $answers): PpiScoreResult
    {
        $scorecard->loadMissing(['questions.options']);

        $questions = $scorecard->questions;
        $requiredQuestions = (int) config('ppi.scorecard.required_questions');

        if ($requiredQuestions < 1 || $questions->count() !== $requiredQuestions) {
            throw new PpiScorecardConfigurationException(
                "The active PPI scorecard must contain exactly {$requiredQuestions} questions.",
            );
        }

        $questionsById = $questions->keyBy('id');
        $errors = [];
        $scoredAnswers = [];

        if (count($answers) !== $questions->count()) {
            $errors['answers'] = "Answer all {$questions->count()} PPI questions.";
        }

        foreach ($answers as $index => $answer) {
            $questionId = (int) ($answer['question_id'] ?? 0);
            $optionId = (int) ($answer['option_id'] ?? 0);
            $question = $questionsById->get($questionId);

            if ($question === null) {
                $errors["answers.{$index}.question_id"] = 'The selected question is not part of the active scorecard.';

                continue;
            }

            if (isset($scoredAnswers[$questionId])) {
                $errors["answers.{$index}.question_id"] = 'Each PPI question may be answered only once.';

                continue;
            }

            $option = $question->options->first(
                static fn ($candidate): bool => $candidate->id === $optionId,
            );

            if ($option === null) {
                $errors["answers.{$index}.option_id"] = 'The selected answer is not valid for this question.';

                continue;
            }

            $scoredAnswers[$questionId] = new PpiScoredAnswer(
                questionId: $questionId,
                optionId: $optionId,
                points: $option->points,
            );
        }

        if ($questionsById->keys()->diff(array_keys($scoredAnswers))->isNotEmpty()) {
            $errors['answers'] = "Answer all {$questions->count()} PPI questions.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $orderedAnswers = array_values($scoredAnswers);
        $score = array_sum(array_map(
            static fn (PpiScoredAnswer $answer): int => $answer->points,
            $orderedAnswers,
        ));

        return new PpiScoreResult(score: $score, answers: $orderedAnswers);
    }
}
