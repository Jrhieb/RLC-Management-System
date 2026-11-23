<?php

namespace App\Services;

use App\Models\PpiScorecard;

class PpiScorecardResolver
{
    private ?PpiScorecard $active = null;

    private bool $resolved = false;

    public function intakeConfigured(): bool
    {
        return config('ppi.intake.enabled') === true
            && filled(config('ppi.intake.privacy_contact'))
            && filled(config('ppi.intake.privacy_acknowledgment'))
            && filled(config('ppi.intake.retention_notice'));
    }

    public function readyScorecard(?PpiScorecard $scorecard): bool
    {
        return $scorecard !== null
            && $scorecard->country === 'PH'
            && $scorecard->version === config('ppi.scorecard.version')
            && $scorecard->questions->count() === (int) config('ppi.scorecard.required_questions')
            && $scorecard->questions->every(fn ($question): bool => $question->options->count() >= 2);
    }

    public function active(): ?PpiScorecard
    {
        if ($this->resolved) {
            return $this->active;
        }

        $this->resolved = true;
        $this->active = PpiScorecard::query()
            ->active()
            ->with(['questions', 'questions.options'])
            ->latest('effective_from')
            ->latest('id')
            ->first();

        return $this->active;
    }

    public function forget(): void
    {
        $this->active = null;
        $this->resolved = false;
    }
}
