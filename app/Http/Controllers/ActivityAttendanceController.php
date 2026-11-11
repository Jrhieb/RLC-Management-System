<?php

namespace App\Http\Controllers;

use App\Http\Responses\AttendanceCheckInResponse;
use App\Models\ActivityAttendance;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ActivityAttendanceController extends Controller
{
    public function store(Request $request, ProgramActivity $activity): RedirectResponse|JsonResponse
    {
        Gate::authorize('recordAttendance', $activity);
        $validated = $request->validate([
            'beneficiary_id' => ['required', 'string', 'max:50'],
        ]);

        [$beneficiary, $attendance, $inserted] = DB::transaction(function () use ($validated, $request, $activity) {
            $beneficiary = Beneficiary::query()
                ->visibleTo($request->user())
                ->where('beneficiary_id', trim($validated['beneficiary_id']))
                ->where('status', 'active')->lockForUpdate()->first();

            if (! $beneficiary) {
                throw ValidationException::withMessages(['beneficiary_id' => 'No active beneficiary found with that ID.']);
            }

            Gate::authorize('view', $beneficiary);

            $now = now('UTC');
            $inserted = DB::table('activity_attendances')->insertOrIgnore([
                'program_activity_id' => $activity->id,
                'beneficiary_id' => $beneficiary->id,
                'checked_in_at' => $now,
                'source' => 'manual',
                'recorded_by' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $attendance = ActivityAttendance::query()->where('program_activity_id', $activity->id)
                ->where('beneficiary_id', $beneficiary->id)->firstOrFail();

            return [$beneficiary, $attendance, $inserted];
        });

        if ($request->expectsJson()) {
            return AttendanceCheckInResponse::make($attendance, $beneficiary, $activity, $inserted !== 0);
        }

        if ($inserted === 0) {
            return redirect()
                ->route('activities.show', $activity)
                ->with('success', 'Attendance already recorded for this beneficiary and activity.');
        }

        return redirect()
            ->route('activities.show', $activity)
            ->with('success', $beneficiary->full_name.' checked in successfully.');
    }
}
