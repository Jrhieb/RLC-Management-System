<?php

namespace App\Enums;

enum PpiApplicationStatus: string
{
    case Submitted = 'submitted';
    case DidNotPassScreening = 'did_not_pass_screening';
    case UnderReview = 'under_review';
    case AssessmentComplete = 'assessment_complete';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted — Awaiting Review',
            self::DidNotPassScreening => 'Did Not Pass PPI Screening',
            self::UnderReview => 'Under review',
            self::AssessmentComplete => 'Assessment complete',
            self::Closed => 'Closed',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Closed],
            self::UnderReview => [self::AssessmentComplete, self::Closed],
            self::AssessmentComplete => [self::Closed],
            self::DidNotPassScreening, self::Closed => [],
        };
    }

    public function screeningMessage(): string
    {
        return $this === self::DidNotPassScreening
            ? 'Thank you for taking the time to apply to RLCP. After our initial review, we’re unable to move your application to the next stage of the program.'
            : 'Thank you for applying to RLCP. Your application has been submitted. Please wait for further notice from us by email or phone.';
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
