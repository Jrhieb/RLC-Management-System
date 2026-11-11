@if ($activity && $attendances)
    <p class="rlca-matching" role="status">{{ number_format($attendances->total()) }} matching {{ $attendances->total() === 1 ? 'record' : 'records' }}</p>
    <div class="rlca-table-scroll" tabindex="0" role="region" aria-label="Attendance records table">
        <table class="rlca-table">
            <caption class="sr-only">Check-ins for {{ $activity->title }}</caption>
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Beneficiary <span class="rlca-column-hint">Name / ID</span></th>
                    <th scope="col">Check-in time (PHT)</th>
                    <th scope="col">Source</th>
                    <th scope="col">Recorded by</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($attendances as $attendance)
                    <tr>
                        <td>{{ $attendances->firstItem() + $loop->index }}</td>
                        <td>
                            <div class="rlca-table-person">
                                <span class="rlca-table-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::substr(\Illuminate\Support\Str::initials($attendance->beneficiary?->full_name ?? 'Unknown', true), 0, 2) }}</span>
                                <div>
                                    @if ($attendance->beneficiary?->trashed())
                                        <strong>{{ $attendance->beneficiary->full_name }}</strong>
                                        <span class="rlca-tag">Archived</span>
                                    @elseif ($attendance->beneficiary)
                                        <a href="{{ route('beneficiaries.show', $attendance->beneficiary) }}">{{ $attendance->beneficiary->full_name }}</a>
                                    @else
                                        Beneficiary unavailable
                                    @endif
                                    <span class="rlca-table-id">{{ $attendance->beneficiary?->beneficiary_id ?? '—' }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="rlca-time">{{ $attendance->checked_in_at->copy()->timezone('Asia/Manila')->format('M d, Y - g:i A') }}</td>
                        <td><span class="rlca-tag rlca-source" data-source="{{ $attendance->source }}">{{ match ($attendance->source) { 'nfc' => 'NFC', 'nfc_test' => 'Test UID', default => ucfirst($attendance->source) } }}</span></td>
                        <td>{{ $attendance->recorder?->name ?? 'Former staff member' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">{{ $search !== '' ? 'No check-ins match your search.' : 'No attendance recorded for this activity yet.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="rlca-pagination" data-attendance-pagination>
        @if ($attendances->hasPages())
            {{ $attendances->onEachSide(1)->links() }}
        @else
            <p class="rlca-muted">Showing {{ $attendances->firstItem() ?? 0 }}–{{ $attendances->lastItem() ?? 0 }} of {{ number_format($attendances->total()) }}</p>
        @endif
    </div>
@else
    <p class="rlca-empty">Select an activity to view its attendance records.</p>
@endif
