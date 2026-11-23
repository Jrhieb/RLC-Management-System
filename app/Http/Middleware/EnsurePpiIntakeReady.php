<?php

namespace App\Http\Middleware;

use App\Services\PpiGuardianResultDelivery;
use App\Services\PpiScorecardResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePpiIntakeReady
{
    public function __construct(private PpiScorecardResolver $scorecards) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->scorecards->intakeConfigured(), 403);

        $scorecard = $this->scorecards->active();
        abort_if($scorecard === null, 503, 'PPI scorecard is not configured.');

        abort_unless(
            $this->scorecards->readyScorecard($scorecard),
            503,
            'PPI scorecard is incomplete.',
        );
        abort_unless(PpiGuardianResultDelivery::configured(), 503, 'Application notifications are not configured.');

        $request->attributes->set('ppi_scorecard', $scorecard);

        return $next($request);
    }
}
