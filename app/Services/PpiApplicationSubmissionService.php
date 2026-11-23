<?php

namespace App\Services;

use App\Enums\PpiApplicationStatus;
use App\Exceptions\DuplicatePpiHouseholdException;
use App\Models\PpiHouseholdApplication;
use App\Models\PpiScorecard;
use App\Support\PpiHouseholdKey;
use App\Support\PpiScoreResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PpiApplicationSubmissionService
{
    public function __construct(
        private PpiApplicationReferenceGenerator $references,
        private PpiApplicationStatusService $statuses,
        private PpiDirectorNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, PpiScorecard $scorecard, PpiScoreResult $score): PpiHouseholdApplication
    {
        $householdKey = PpiHouseholdKey::make(
            contactNumber: (string) $data['contact_number'],
            barangay: (string) $data['barangay'],
            cityMunicipality: (string) $data['city_municipality'],
            completeAddress: (string) $data['complete_address'],
        );

        try {
            return DB::transaction(function () use ($data, $scorecard, $score, $householdKey): PpiHouseholdApplication {
                $submittedAt = now();
                $application = new PpiHouseholdApplication;
                $application->fill(Arr::only($data, [
                    'child_first_name',
                    'child_middle_name',
                    'child_last_name',
                    'child_suffix',
                    'child_sex',
                    'child_date_of_birth',
                    'guardian_name',
                    'guardian_relationship',
                    'contact_number',
                    'email',
                    'barangay',
                    'city_municipality',
                    'complete_address',
                ]));
                $application->reference = $this->references->generate();
                $application->submission_key = (string) $data['submission_key'];
                $application->household_key = $householdKey;
                $application->ppi_scorecard_id = $scorecard->id;
                $application->ppi_score = $score->score;
                $application->status = $score->score <= (int) config('ppi.passing_score')
                    ? PpiApplicationStatus::Submitted
                    : PpiApplicationStatus::DidNotPassScreening;
                $application->guardian_delivery_status = 'pending';
                $application->privacy_acknowledged_at = $submittedAt;
                $application->submitted_at = $submittedAt;
                $application->scored_at = $submittedAt;
                $application->save();

                foreach ($score->answers as $answer) {
                    $application->answers()->create([
                        'ppi_question_id' => $answer->questionId,
                        'ppi_answer_option_id' => $answer->optionId,
                        'points_awarded' => $answer->points,
                    ]);
                }

                foreach (array_values($data['household_members'] ?? []) as $position => $member) {
                    $application->members()->create([
                        'name' => $member['name'],
                        'relationship' => $member['relationship'] ?? null,
                        'monthly_income' => $member['monthly_income'] ?? null,
                        'position' => $position + 1,
                    ]);
                }

                $this->statuses->recordSubmission($application);
                $this->notifications->recordNewApplication($application);

                return $application;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existingSubmission = PpiHouseholdApplication::withTrashed()
                ->where('submission_key', (string) $data['submission_key'])
                ->first();

            if ($existingSubmission !== null) {
                return $existingSubmission;
            }

            if (PpiHouseholdApplication::withTrashed()->where('household_key', $householdKey)->exists()) {
                throw new DuplicatePpiHouseholdException(previous: $exception);
            }

            throw $exception;
        }
    }
}
