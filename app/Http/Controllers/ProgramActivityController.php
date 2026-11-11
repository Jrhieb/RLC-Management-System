<?php

namespace App\Http\Controllers;

use App\Models\ProgramActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProgramActivityController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', ProgramActivity::class);

        $activities = ProgramActivity::query()
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->paginate(10);

        return view('activities.index', compact('activities'));
    }

    public function create(): View
    {
        Gate::authorize('create', ProgramActivity::class);

        return view('activities.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ProgramActivity::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'activity_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'development_area' => [
                'required',
                Rule::in(['physical', 'cognitive', 'socio_emotional', 'spiritual']),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $activity = new ProgramActivity($validated);
        $activity->created_by = $request->user()->id;
        $activity->save();

        return redirect()
            ->route('activities.show', $activity)
            ->with('success', 'Activity created successfully.');
    }

    public function edit(ProgramActivity $activity): View
    {
        Gate::authorize('update', $activity);

        return view('activities.edit', compact('activity'));
    }

    public function update(Request $request, ProgramActivity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'activity_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'development_area' => [
                'required',
                Rule::in(['physical', 'cognitive', 'socio_emotional', 'spiritual']),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $activity->update($validated);

        return redirect()
            ->route('activities.show', $activity)
            ->with('success', 'Activity updated successfully.');
    }

    public function attendance(Request $request): View|JsonResponse
    {
        Gate::authorize('viewAny', ProgramActivity::class);

        $validated = $request->validate([
            'activity_id' => ['nullable', 'integer', 'min:1', Rule::exists('program_activities', 'id')],
        ]);
        $activity = isset($validated['activity_id'])
            ? ProgramActivity::findOrFail($validated['activity_id']) : null;

        return $this->attendanceWorkspace($request, $activity);
    }

    public function show(Request $request, ProgramActivity $activity): View|JsonResponse
    {
        return $this->attendanceWorkspace($request, $activity);
    }

    private function attendanceWorkspace(Request $request, ?ProgramActivity $activity): View|JsonResponse
    {
        if ($activity) {
            Gate::authorize('view', $activity);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim($validated['search'] ?? '');

        $counts = null;
        $attendances = null;

        if ($activity) {
            $activity->load('creator');
            $activity->loadCount([
                'attendances' => fn ($query) => $query->visibleTo($request->user()),
                'attendances as manual_count' => fn ($query) => $query->visibleTo($request->user())->where('source', 'manual'),
                'attendances as test_uid_count' => fn ($query) => $query->visibleTo($request->user())->where('source', 'nfc_test'),
                'attendances as nfc_count' => fn ($query) => $query->visibleTo($request->user())->where('source', 'nfc'),
            ]);
            $counts = [
                'total' => $activity->attendances_count,
                'manual' => $activity->manual_count,
                'test_uid' => $activity->test_uid_count,
                'nfc' => $activity->nfc_count,
            ];
            $attendances = $activity->attendances()
                ->visibleTo($request->user())
                ->with(['beneficiary', 'recorder'])
                ->matchingBeneficiary($search)
                ->orderByDesc('checked_in_at')
                ->orderByDesc('id')
                ->paginate(10)
                ->withQueryString();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'activity_id' => $activity?->id,
                'counts' => $counts,
                'records_html' => view('activities.partials.attendance-records', compact('activity', 'attendances', 'search'))->render(),
            ])->header('Cache-Control', 'private, no-store');
        }

        $activities = ProgramActivity::query()->orderByDesc('activity_date')->orderByDesc('id')
            ->get(['id', 'title', 'activity_date', 'location']);

        return view('activities.show', compact(
            'activity',
            'activities',
            'attendances',
            'search',
            'counts'
        ));
    }
}
