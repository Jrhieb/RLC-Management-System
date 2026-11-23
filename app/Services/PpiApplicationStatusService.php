<?php

namespace App\Services;

use App\Enums\PpiApplicationStatus;
use App\Exceptions\InvalidPpiStatusTransitionException;
use App\Models\BeneficiaryApplication;
use App\Models\PpiApplicationStatusEvent;
use App\Models\PpiHouseholdApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PpiApplicationStatusService
{
    public function beginAssessment(PpiHouseholdApplication $application, User $director): BeneficiaryApplication
    {
        return DB::transaction(function () use ($application, $director): BeneficiaryApplication {
            $record = PpiHouseholdApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($record->beneficiaryApplication !== null) {
                return $record->beneficiaryApplication;
            }

            abort_if(in_array($record->status, [PpiApplicationStatus::DidNotPassScreening, PpiApplicationStatus::Closed], true), 409);

            $assessment = BeneficiaryApplication::query()->create([
                'reference' => (string) Str::uuid(),
                'guardian_name' => $record->guardian_name,
                'guardian_relationship' => $record->guardian_relationship,
                'contact_number' => $record->contact_number,
                'preferred_contact' => $record->email ? 'email' : 'phone',
                'email' => $record->email,
                'barangay' => $record->barangay,
                'municipality' => $record->city_municipality,
                'reason' => "Household assessment for {$record->child_first_name} {$record->child_last_name}.",
                'status' => 'under_review',
            ]);
            DB::table('beneficiary_application_audits')->insert([
                'beneficiary_application_id' => $assessment->id,
                'actor_id' => $director->id,
                'event' => 'ppi_selected_for_assessment',
                'status' => $assessment->status,
                'created_at' => now(),
            ]);
            $record->beneficiaryApplication()->associate($assessment);
            $record->save();

            if ($record->status === PpiApplicationStatus::Submitted) {
                $this->transition($record, PpiApplicationStatus::UnderReview, $director, 'Selected for household assessment.');
            }

            return $assessment;
        });
    }

    public function recordSubmission(PpiHouseholdApplication $application): PpiApplicationStatusEvent
    {
        return $application->statusEvents()->create([
            'from_status' => null,
            'to_status' => $application->status,
            'actor_id' => null,
            'note' => 'Application submitted.',
        ]);
    }

    public function transition(
        PpiHouseholdApplication $application,
        PpiApplicationStatus $status,
        ?User $actor = null,
        ?string $note = null,
    ): PpiHouseholdApplication {
        return DB::transaction(function () use ($application, $status, $actor, $note): PpiHouseholdApplication {
            $record = PpiHouseholdApplication::query()->lockForUpdate()->findOrFail($application->id);
            $from = $record->status;

            if (! $from->canTransitionTo($status)) {
                throw new InvalidPpiStatusTransitionException(
                    "Cannot change PPI application status from {$from->value} to {$status->value}.",
                );
            }

            $record->status = $status;

            if ($status === PpiApplicationStatus::UnderReview) {
                $record->reviewed_at = now();
                $record->reviewed_by = $actor?->id;
            }

            $record->save();

            $record->statusEvents()->create([
                'from_status' => $from,
                'to_status' => $status,
                'actor_id' => $actor?->id,
                'note' => $note,
            ]);

            return $record;
        });
    }
}
