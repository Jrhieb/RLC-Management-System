<?php

namespace App\Http\Controllers;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use App\Support\ExportRows;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $type): StreamedResponse
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

            $headers = [
                'Beneficiary Name',
                'Beneficiary ID',
                'Activity',
                'Activity Date',
                'Check-in Time (PHT)',
                'Source',
                'Recorded By',
            ];
        } else {
            $query = AssistanceTransaction::query()
                ->visibleTo($request->user())
                ->with(['beneficiary', 'recorder'])
                ->where('released_at', '>=', $start)
                ->where('released_at', '<', $end)
                ->orderByDesc('released_at')
                ->orderByDesc('id');

            $headers = [
                'Beneficiary Name',
                'Beneficiary ID',
                'Category',
                'Assistance',
                'Quantity',
                'Released At (PHT)',
                'Notes',
                'Recorded By',
            ];
        }

        $filename = $type.'-report-'.$dateFrom.'-to-'.$dateTo.'.csv';

        return response()->streamDownload(function () use ($type, $query, $headers) {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                throw new \RuntimeException('Could not open CSV output.');
            }

            try {
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, $headers, ',', '"', '');

                foreach (ExportRows::descending($query, $type === 'attendance' ? 'checked_in_at' : 'released_at') as $record) {
                    $name = $record->beneficiary?->full_name
                        ?? 'Archived beneficiary';

                    $beneficiaryId = $record->beneficiary?->beneficiary_id
                        ?? 'Unavailable';

                    $recorder = $record->recorder?->name
                        ?? 'Former staff member';

                    if ($type === 'attendance') {
                        $row = [
                            $name,
                            $beneficiaryId,
                            $record->activity?->title ?? 'Activity unavailable',
                            $record->activity?->activity_date?->format('m/d/Y') ?? '',
                            $record->checked_in_at->copy()
                                ->timezone('Asia/Manila')->format('m/d/Y H:i:s'),
                            match ($record->source) {
                                'nfc' => 'NFC', 'nfc_test' => 'Test UID', default => ucfirst($record->source)
                            },
                            $recorder,
                        ];
                    } else {
                        $row = [
                            $name,
                            $beneficiaryId,
                            ucfirst($record->category),
                            $record->item_name,
                            $record->quantity,
                            $record->released_at->copy()
                                ->timezone('Asia/Manila')->format('m/d/Y H:i:s'),
                            $record->notes ?? '',
                            $recorder,
                        ];
                    }

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

        if (preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
