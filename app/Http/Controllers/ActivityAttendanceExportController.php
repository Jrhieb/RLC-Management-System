<?php

namespace App\Http\Controllers;

use App\Models\ProgramActivity;
use App\Support\ExportRows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityAttendanceExportController extends Controller
{
    public function __invoke(Request $request, ProgramActivity $activity): StreamedResponse
    {
        Gate::authorize('view', $activity);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim($validated['search'] ?? '');

        $query = $activity->attendances()
            ->visibleTo($request->user())
            ->with(['beneficiary', 'recorder'])
            ->matchingBeneficiary($search)
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id');

        $filename = 'activity-'.$activity->id.'-attendance-'
            .now('Asia/Manila')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($activity, $query) {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                throw new \RuntimeException('Could not open CSV output.');
            }

            try {
                // UTF-8 marker for spreadsheet applications.
                fwrite($output, "\xEF\xBB\xBF");

                fputcsv($output, [
                    'Activity',
                    'Activity Date',
                    'Beneficiary Name',
                    'Beneficiary ID',
                    'Status',
                    'Check-in Time (PHT)',
                    'Source',
                    'Recorded By',
                ], ',', '"', '');

                foreach (ExportRows::descending($query, 'checked_in_at') as $attendance) {
                    $row = [
                        $activity->title,
                        $activity->activity_date->format('Y-m-d'),
                        $attendance->beneficiary?->full_name ?? 'Archived beneficiary',
                        $attendance->beneficiary?->beneficiary_id ?? 'Unavailable',
                        'Checked in',
                        $attendance->checked_in_at->copy()
                            ->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
                        match ($attendance->source) {
                            'nfc' => 'NFC', 'nfc_test' => 'Test UID', default => ucfirst($attendance->source)
                        },
                        $attendance->recorder?->name ?? 'Former staff member',
                    ];

                    fputcsv($output, array_map(
                        fn ($value) => $this->safeCell($value),
                        $row
                    ), ',', '"', '');
                }
            } finally {
                fclose($output);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeCell(mixed $value): string
    {
        $text = (string) $value;

        // Prevent user-entered text from becoming spreadsheet formulas.
        if (preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
