<?php

namespace App\Http\Controllers;

use App\Enums\PpiApplicationStatus;
use App\Http\Requests\StorePpiApplicationRequest;
use App\Http\Requests\UpdatePpiApplicationStatusRequest;
use App\Http\Resources\PpiHouseholdApplicationResource;
use App\Models\PpiDirectorNotification;
use App\Models\PpiHouseholdApplication;
use App\Models\PpiQuestion;
use App\Services\PpiApplicationStatusService;
use App\Services\PpiApplicationSubmissionService;
use App\Services\PpiGuardianResultDelivery;
use App\Services\PpiScorecardResolver;
use App\Services\PpiScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PpiApplicationController extends Controller
{
    public function application(Request $request, PpiScorecardResolver $scorecards): Response
    {
        $definition = json_decode(file_get_contents(database_path('seeders/philippines-ppi-2018.json')), true, flags: JSON_THROW_ON_ERROR);
        $scorecard = $scorecards->intakeConfigured() && PpiGuardianResultDelivery::configured() ? $scorecards->active() : null;
        $intakeOpen = $scorecards->readyScorecard($scorecard);
        $submissionKey = null;

        if ($intakeOpen) {
            $submissionKey = (string) Str::uuid();
            $openedForms = $request->session()->get('ppi_opened_forms', []);
            $request->session()->put('ppi_opened_forms', array_slice([...$openedForms, $submissionKey], -10));
        }

        $guidance = array_column($definition['questions'], 'guidance', 'code');
        $questions = $intakeOpen
            ? $scorecard->questions->map(fn (PpiQuestion $question): array => [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'guidance' => $guidance[$question->code] ?? null,
                'options' => $question->options->map->only(['id', 'label'])->all(),
            ])->all()
            : array_map(fn (array $question): array => [
                'id' => $question['code'],
                'prompt' => $question['prompt'],
                'guidance' => $question['guidance'] ?? null,
                'options' => array_map(fn (array $option): array => ['id' => $option['code'], 'label' => $option['label']], $question['options']),
            ], $definition['questions']);

        return response()->view('public.application', compact('intakeOpen', 'submissionKey', 'questions'))
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'same-origin');
    }

    public function store(
        StorePpiApplicationRequest $request,
        PpiScoringService $scoring,
        PpiApplicationSubmissionService $submissions,
        PpiGuardianResultDelivery $delivery,
    ): JsonResponse {
        $data = $request->validated();
        $sessionKey = $request->session()->get('ppi_submission_key');
        abort_unless(
            in_array($data['submission_key'], $request->session()->get('ppi_opened_forms', []), true)
            || (is_string($sessionKey) && hash_equals($sessionKey, $data['submission_key'])),
            403,
        );

        $existing = PpiHouseholdApplication::withTrashed()
            ->where('submission_key', $data['submission_key'])
            ->first();

        if ($existing !== null) {
            $delivery->sendOnce($existing);

            return $this->submissionResponse($existing, 200);
        }

        $scorecard = $request->attributes->get('ppi_scorecard');
        abort_if($scorecard === null, 503, 'PPI scorecard is not configured.');

        $score = $scoring->score($scorecard, $data['answers']);
        $application = $submissions->submit($data, $scorecard, $score);
        $delivery->sendOnce($application);

        return $this->submissionResponse($application, 201);
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PpiHouseholdApplication::class);

        $screenedOut = $request->query('screening') === 'not-passed';
        $applications = PpiHouseholdApplication::query()
            ->where('status', $screenedOut ? '=' : '!=', PpiApplicationStatus::DidNotPassScreening->value)
            ->withCount('members')
            ->lowestScoreFirst()
            ->paginate(25)->withQueryString();

        if (! $request->expectsJson()) {
            return response()->view('applications.ppi-index', compact('applications', 'screenedOut'))
                ->header('Cache-Control', 'private, no-store');
        }

        return PpiHouseholdApplicationResource::collection($applications)
            ->response()
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function show(Request $request, PpiHouseholdApplication $application): Response
    {
        Gate::authorize('view', $application);

        $application->load(['scorecard', 'members', 'answers.question', 'answers.option', 'statusEvents.actor']);

        if (! $request->expectsJson()) {
            PpiDirectorNotification::query()->forRecipient($request->user())->unread()
                ->where('ppi_household_application_id', $application->id)->update(['read_at' => now()]);

            return response()->view('applications.ppi-show', compact('application'))
                ->header('Cache-Control', 'private, no-store');
        }

        return (new PpiHouseholdApplicationResource($application))
            ->response()
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function beginAssessment(Request $request, PpiHouseholdApplication $application, PpiApplicationStatusService $statuses): Response
    {
        Gate::authorize('updateStatus', $application);

        return redirect()->route('applications.show', $statuses->beginAssessment($application, $request->user()));
    }

    public function updateStatus(
        UpdatePpiApplicationStatusRequest $request,
        PpiHouseholdApplication $application,
        PpiApplicationStatusService $statuses,
    ): Response {
        $updated = $statuses->transition(
            $application,
            PpiApplicationStatus::from((string) $request->validated('status')),
            $request->user(),
            $request->validated('note') !== null ? (string) $request->validated('note') : null,
        );

        if (! $request->expectsJson()) {
            return redirect()->route('ppi-applications.show', $updated);
        }

        $updated->load(['scorecard', 'members', 'answers.question', 'answers.option', 'statusEvents.actor']);

        return (new PpiHouseholdApplicationResource($updated))
            ->response()
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function submissionResponse(PpiHouseholdApplication $application, int $status): JsonResponse
    {
        $screening = $application->status === PpiApplicationStatus::DidNotPassScreening
            ? PpiApplicationStatus::DidNotPassScreening : PpiApplicationStatus::Submitted;
        $result = ['status' => $screening->value, 'message' => $screening->screeningMessage()];
        if ($screening === PpiApplicationStatus::Submitted) {
            $result['reference'] = $application->reference;
        }

        return response()->json($result, $status, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
