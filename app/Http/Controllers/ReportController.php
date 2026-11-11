<?php

namespace App\Http\Controllers;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Beneficiary::class);
        Gate::authorize('viewAny', ProgramActivity::class);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $today = Carbon::now('Asia/Manila');

        $dateFrom = $validated['date_from']
            ?? $today->copy()->startOfMonth()->toDateString();

        $dateTo = $validated['date_to']
            ?? $today->toDateString();

        if ($dateTo < $dateFrom) {
            throw ValidationException::withMessages([
                'date_to' => 'End date must be on or after the start date.',
            ]);
        }

        // Include the whole selected date range in Philippine time.
        $start = Carbon::createFromFormat('!Y-m-d', $dateFrom, 'Asia/Manila')
            ->utc();

        $end = Carbon::createFromFormat('!Y-m-d', $dateTo, 'Asia/Manila')
            ->addDay()
            ->utc();

        $attendanceQuery = ActivityAttendance::query()
            ->visibleTo($request->user())
            ->where('checked_in_at', '>=', $start)
            ->where('checked_in_at', '<', $end);

        $assistanceQuery = AssistanceTransaction::query()
            ->visibleTo($request->user())
            ->where('released_at', '>=', $start)
            ->where('released_at', '<', $end);

        $attendanceCount = (clone $attendanceQuery)->count();

        $participatingBeneficiariesCount = (clone $attendanceQuery)
            ->distinct()
            ->count('beneficiary_id');

        $assistanceReleasesCount = (clone $assistanceQuery)->count();

        $assistedBeneficiariesCount = (clone $assistanceQuery)
            ->distinct()
            ->count('beneficiary_id');

        $attendances = (clone $attendanceQuery)
            ->with(['activity', 'beneficiary', 'recorder'])
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'attendance_page')
            ->withQueryString();

        $assistanceTransactions = (clone $assistanceQuery)
            ->with(['beneficiary', 'recorder'])
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'assistance_page')
            ->withQueryString();

        return view('reports.index', compact(
            'dateFrom',
            'dateTo',
            'attendanceCount',
            'participatingBeneficiariesCount',
            'assistanceReleasesCount',
            'assistedBeneficiariesCount',
            'attendances',
            'assistanceTransactions'
        ));
    }
}
