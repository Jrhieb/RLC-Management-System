<?php

namespace App\Http\Responses;

use App\Models\ActivityAttendance;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AttendanceCheckInResponse
{
    public static function make(ActivityAttendance $attendance, Beneficiary $beneficiary, ProgramActivity $activity, bool $created): JsonResponse
    {
        return response()->json([
            'result' => $created ? 'created' : 'duplicate',
            'message' => $created ? 'Checked in successfully' : 'Already checked in',
            'activity' => ['id' => $activity->id, 'title' => $activity->title],
            'beneficiary' => [
                'full_name' => $beneficiary->full_name,
                'beneficiary_id' => $beneficiary->beneficiary_id,
                'status' => $beneficiary->status,
                'photo_url' => $beneficiary->photo_path && Gate::allows('view', $beneficiary)
                    ? route('beneficiaries.photo', $beneficiary) : null,
            ],
            'source' => $attendance->source,
            'checked_in_at' => $attendance->checked_in_at->copy()->timezone('Asia/Manila')->format('M d, Y - g:i A'),
        ], $created ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
