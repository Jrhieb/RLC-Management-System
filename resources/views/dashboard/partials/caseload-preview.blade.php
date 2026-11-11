<div data-caseload-preview data-caseload-preview-version="{{ hash('sha256', $caseloadPreview->toJson()) }}">
    @if ($caseloadPreview->isEmpty())
        <div class="rlc-empty rlc-empty-compact" role="status"><x-rlcp-icon name="users" /><p>No assigned beneficiaries yet.</p></div>
    @else
        <ul class="rlc-caseload-preview" aria-label="My Caseload preview, sorted by name">
            @foreach ($caseloadPreview as $beneficiary)
                <li>
                    <x-registry-beneficiary-person :beneficiary="$beneficiary" />
                    <span class="rlcr-id">{{ $beneficiary->beneficiary_id }}</span>
                    <x-registry-beneficiary-status :status="$beneficiary->status" />
                </li>
            @endforeach
        </ul>
    @endif
</div>
