<?php

namespace App\Http\Controllers;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ReportPdfExportController extends Controller
{
    public function __invoke(Request $request, string $type): Response
    {
        Gate::authorize('viewAny', Beneficiary::class);
        Gate::authorize('viewAny', ProgramActivity::class);

        abort_unless(in_array($type, ['attendance', 'assistance'], true), 404);

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

        $start = Carbon::createFromFormat('!Y-m-d', $dateFrom, 'Asia/Manila')
            ->utc();

        $end = Carbon::createFromFormat('!Y-m-d', $dateTo, 'Asia/Manila')
            ->addDay()
            ->utc();

        if ($type === 'attendance') {
            $query = ActivityAttendance::query()
                ->visibleTo($request->user())
                ->with(['activity', 'beneficiary', 'recorder'])
                ->where('checked_in_at', '>=', $start)
                ->where('checked_in_at', '<', $end)
                ->orderByDesc('checked_in_at')
                ->orderByDesc('id');
        } else {
            $query = AssistanceTransaction::query()
                ->visibleTo($request->user())
                ->with(['beneficiary', 'recorder'])
                ->where('released_at', '>=', $start)
                ->where('released_at', '<', $end)
                ->orderByDesc('released_at')
                ->orderByDesc('id');
        }

        // Bound PDF size; CSV remains available for larger exports.
        $records = $query->limit(1001)->get();

        if ($records->count() > 1000) {
            throw ValidationException::withMessages([
                'date_from' => 'PDF exports support up to 1,000 records. Choose a shorter date range or download CSV.',
            ]);
        }

        $generatedAt = Carbon::now('Asia/Manila');
        $generatedBy = $request->user()->name;

        $pdf = Pdf::loadView('reports.pdf', compact(
            'type',
            'dateFrom',
            'dateTo',
            'records',
            'generatedAt',
            'generatedBy'
        ))
            ->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false);

        $filename = $type.'-report-'.$dateFrom.'-to-'.$dateTo.'.pdf';

        return $pdf->download($filename)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
