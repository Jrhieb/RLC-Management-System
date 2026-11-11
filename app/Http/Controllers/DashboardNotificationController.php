<?php

namespace App\Http\Controllers;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardNotificationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Beneficiary::class);
        Gate::authorize('viewAny', ProgramActivity::class);
        $items = collect();
        foreach (ActivityAttendance::query()->visibleTo($request->user())->with(['activity', 'beneficiary'])->latest('created_at')->latest('id')->limit(8)->get() as $record) {
            if (! $record->activity || ! Gate::allows('view', $record->activity)) {
                continue;
            }
            $items->push([
                'id' => 'attendance-'.$record->id,
                'title' => 'Attendance recorded',
                'detail' => ($record->beneficiary?->full_name ?? 'Beneficiary unavailable').' — '.$record->activity->title,
                'url' => route('activities.show', $record->activity),
                'time' => $record->created_at->copy()->timezone('Asia/Manila')->format('m/d/Y g:i A').' PHT',
                'sort' => $record->created_at->getTimestamp(),
            ]);
        }
        foreach (AssistanceTransaction::query()->visibleTo($request->user())->with('beneficiary')->latest('created_at')->latest('id')->limit(8)->get() as $record) {
            $beneficiary = $record->beneficiary;
            if ($beneficiary && ! Gate::allows('view', $beneficiary)) {
                continue;
            }
            $items->push([
                'id' => 'assistance-'.$record->id,
                'title' => 'Assistance release recorded',
                'detail' => ($beneficiary?->full_name ?? 'Beneficiary unavailable').' — '.$record->item_name,
                'url' => $beneficiary && ! $beneficiary->trashed()
                    ? route('beneficiaries.show', $beneficiary)
                    : route('reports.index', ['date_from' => $record->released_at->copy()->timezone('Asia/Manila')->toDateString(), 'date_to' => $record->released_at->copy()->timezone('Asia/Manila')->toDateString()]),
                'time' => $record->created_at->copy()->timezone('Asia/Manila')->format('m/d/Y g:i A').' PHT',
                'sort' => $record->created_at->getTimestamp(),
            ]);
        }
        foreach (ProgramActivity::query()->latest('created_at')->latest('id')->limit(8)->get() as $record) {
            if (! Gate::allows('view', $record)) {
                continue;
            }
            $items->push([
                'id' => 'activity-'.$record->id,
                'title' => 'New activity added',
                'detail' => $record->title,
                'url' => route('activities.show', $record),
                'time' => $record->created_at->copy()->timezone('Asia/Manila')->format('m/d/Y g:i A').' PHT',
                'sort' => $record->created_at->getTimestamp(),
            ]);
        }

        return response()->json(['items' => $items->sortByDesc('sort')->take(8)->values()])->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
