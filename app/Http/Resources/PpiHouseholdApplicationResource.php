<?php

namespace App\Http\Resources;

use App\Models\PpiApplicationAnswer;
use App\Models\PpiApplicationStatusEvent;
use App\Models\PpiHouseholdApplication;
use App\Models\PpiHouseholdMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PpiHouseholdApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PpiHouseholdApplication $application */
        $application = $this->resource;

        return [
            'id' => $application->id,
            'reference' => $application->reference,
            'status' => $application->status->value,
            'status_label' => $application->status->label(),
            'ppi_score' => $application->ppi_score,
            'guardian_delivery_status' => $application->guardian_delivery_status,
            'scorecard' => $this->whenLoaded('scorecard', fn (): array => [
                'name' => $application->scorecard?->name,
                'version' => $application->scorecard?->version,
            ]),
            'child' => [
                'first_name' => $application->child_first_name,
                'middle_name' => $application->child_middle_name,
                'last_name' => $application->child_last_name,
                'suffix' => $application->child_suffix,
                'sex' => $application->child_sex,
                'date_of_birth' => $application->child_date_of_birth->format('Y-m-d'),
            ],
            'guardian' => [
                'name' => $application->guardian_name,
                'relationship' => $application->guardian_relationship,
                'contact_number' => $application->contact_number,
                'email' => $application->email,
            ],
            'address' => [
                'barangay' => $application->barangay,
                'city_municipality' => $application->city_municipality,
                'complete_address' => $application->complete_address,
            ],
            'household_members' => $this->whenLoaded('members', fn () => $application->members
                ->map(fn (PpiHouseholdMember $member): array => [
                    'name' => $member->name,
                    'relationship' => $member->relationship,
                    'monthly_income' => $member->monthly_income,
                ])
                ->values()),
            'household_members_count' => $this->whenCounted('members'),
            'answers' => $this->whenLoaded('answers', fn () => $application->answers
                ->sortBy(fn (PpiApplicationAnswer $answer): int => $answer->question->position)
                ->map(fn (PpiApplicationAnswer $answer): array => [
                    'question_code' => $answer->question?->code,
                    'question' => $answer->question?->prompt,
                    'option_code' => $answer->option?->code,
                    'option' => $answer->option?->label,
                    'points' => $answer->points_awarded,
                ])
                ->values()),
            'status_history' => $this->whenLoaded('statusEvents', fn () => $application->statusEvents
                ->map(fn (PpiApplicationStatusEvent $event): array => [
                    'from_status' => $event->from_status?->value,
                    'from_status_label' => $event->from_status?->label(),
                    'to_status' => $event->to_status->value,
                    'to_status_label' => $event->to_status->label(),
                    'actor' => $event->actor?->name,
                    'note' => $event->note,
                    'created_at' => $event->created_at->toIso8601String(),
                ])
                ->values()),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'created_at' => $application->created_at?->toIso8601String(),
        ];
    }
}
