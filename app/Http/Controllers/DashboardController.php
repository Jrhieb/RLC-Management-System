<?php

namespace App\Http\Controllers;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use App\Services\CaseworkerCaseload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, CaseworkerCaseload $caseload): View
    {
        Gate::authorize('viewAny', Beneficiary::class);
        Gate::authorize('viewAny', ProgramActivity::class);

        if ($request->user()->isCaseworker()) {
            Gate::authorize('viewMyCaseload', Beneficiary::class);

            return view('dashboard.caseworker', $caseload->summary($request->user()) + [
                'upcomingActivities' => $this->upcomingActivities(),
            ]);
        }

        $activeBeneficiariesCount = Beneficiary::query()
            ->where('status', 'active')
            ->count();

        $activitiesCount = ProgramActivity::query()->count();
        $attendanceCount = ActivityAttendance::query()->count();
        $assistanceReleasesCount = AssistanceTransaction::query()->count();

        $today = Carbon::now('Asia/Manila')->startOfDay();

        $todaysActivities = ProgramActivity::query()
            ->whereDate('activity_date', $today->toDateString())
            ->orderByRaw('start_time IS NULL')
            ->orderBy('start_time')
            ->orderBy('id')
            ->limit(5)
            ->get();

        $upcomingActivities = $this->upcomingActivities();

        $recentAssistance = AssistanceTransaction::query()
            ->with(['beneficiary', 'recorder'])
            ->whereHas('beneficiary')
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        $attendanceTrend = collect();

        for ($offset = 6; $offset >= 0; $offset--) {
            $day = $today->copy()->subDays($offset);

            $count = ActivityAttendance::query()
                ->where('checked_in_at', '>=', $day->copy()->utc())
                ->where('checked_in_at', '<', $day->copy()->addDay()->utc())
                ->count();

            $attendanceTrend->push([
                'label' => $day->format('D'),
                'date' => $day->format('M d'),
                'count' => $count,
            ]);
        }

        return view('dashboard', compact(
            'activeBeneficiariesCount',
            'activitiesCount',
            'attendanceCount',
            'assistanceReleasesCount',
            'todaysActivities',
            'upcomingActivities',
            'recentAssistance',
            'attendanceTrend'
        ));
    }

    public function caseload(Request $request, CaseworkerCaseload $caseload): JsonResponse
    {
        Gate::authorize('viewMyCaseload', Beneficiary::class);
        $summary = $caseload->summary($request->user());

        return response()->json([
            'counts' => $summary['caseloadCounts'],
            'preview' => view('dashboard.partials.caseload-preview', $summary)->render(),
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function upcomingActivities(): Collection
    {
        return ProgramActivity::query()->whereDate('activity_date', '>', Carbon::now('Asia/Manila')->toDateString())
            ->orderBy('activity_date')->orderByRaw('start_time IS NULL')->orderBy('start_time')->limit(5)->get();
    }
}
