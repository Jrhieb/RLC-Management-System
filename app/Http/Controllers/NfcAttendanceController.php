<?php

namespace App\Http\Controllers;

use App\Http\Responses\AttendanceCheckInResponse;
use App\Models\ActivityAttendance;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use App\Support\NfcCardUid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class NfcAttendanceController extends Controller
{
    public function store(Request $request, ProgramActivity $activity): RedirectResponse|JsonResponse
    {
        Gate::authorize('recordAttendance', $activity);

        $request->validate([
            'nfc_uid' => ['required', 'string', 'max:64'],
        ]);

        $request->merge(['nfc_uid' => NfcCardUid::normalize($request->input('nfc_uid'))]);

        $validated = $request->validate([
            'nfc_uid' => NfcCardUid::formatRules(),
        ], NfcCardUid::messages());

        [$beneficiary, $attendance] = DB::transaction(function () use ($validated, $request, $activity) {
            $beneficiary = Beneficiary::withTrashed()->where('nfc_uid', $validated['nfc_uid'])
                ->visibleTo($request->user())
                ->lockForUpdate()->first();

            if (! $beneficiary) {
                throw ValidationException::withMessages(['nfc_uid' => 'No beneficiary is linked to this card UID.']);
            }

            if ($beneficiary->trashed() || $beneficiary->status !== 'active') {
                throw ValidationException::withMessages(['nfc_uid' => 'This beneficiary is inactive or archived and cannot be checked in.']);
            }

            Gate::authorize('view', $beneficiary);

            $attendance = ActivityAttendance::query()->firstOrCreate(
                ['program_activity_id' => $activity->id, 'beneficiary_id' => $beneficiary->id],
                ['checked_in_at' => now('UTC'), 'source' => 'nfc_test', 'recorded_by' => $request->user()->id]
            );

            return [$beneficiary, $attendance];
        });

        if ($request->expectsJson()) {
            return AttendanceCheckInResponse::make($attendance, $beneficiary, $activity, $attendance->wasRecentlyCreated);
        }

        return redirect()
            ->route('activities.show', $activity)
            ->with('success', $attendance->wasRecentlyCreated
                ? $beneficiary->full_name.' checked in successfully using the test UID.'
                : 'Attendance already recorded for this beneficiary and activity.');
    }
}
