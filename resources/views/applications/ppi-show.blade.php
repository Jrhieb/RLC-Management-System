<x-layouts::app :title="$application->reference">
    <div class="mx-auto max-w-5xl space-y-6">
        <a class="underline" href="{{ route('ppi-applications.index') }}">Back to PPI applications</a>
        <header class="space-y-2">
            <h1 class="text-2xl font-semibold">{{ $application->child_first_name }} {{ $application->child_middle_name }} {{ $application->child_last_name }} {{ $application->child_suffix }}</h1>
            <p>{{ $application->reference }} · {{ $application->status->label() }}</p>
            <p><strong>PPI score:</strong> {{ $application->ppi_score ?? 'Unavailable' }} · {{ $application->scorecard?->version }}</p>
            <p><strong>Guardian notification:</strong> {{ match ($application->guardian_delivery_status) {
                'accepted' => 'Accepted by provider',
                'failed' => 'Delivery failed',
                'pending', 'sending' => 'Awaiting delivery confirmation',
                default => 'No delivery recorded',
            } }}</p>
        </header>
        <section class="space-y-2">
            <h2 class="text-lg font-semibold">Household details</h2>
            <p>{{ ucfirst($application->child_sex) }} · Born {{ $application->child_date_of_birth->format('M j, Y') }}</p>
            <p>{{ $application->guardian_name }} ({{ $application->guardian_relationship }}) · {{ $application->contact_number }} @if ($application->email) · {{ $application->email }} @endif</p>
            <p>{{ $application->complete_address }}, {{ $application->barangay }}, {{ $application->city_municipality }}</p>
            <h3 class="font-semibold">Household members</h3>
            @forelse ($application->members as $member)
                <p>{{ $member->name }} @if ($member->relationship) · {{ $member->relationship }} @endif @if ($member->monthly_income !== null) · ₱{{ $member->monthly_income }} monthly @endif</p>
            @empty
                <p>No household members listed.</p>
            @endforelse
        </section>
        <section class="space-y-2">
            <h2 class="text-lg font-semibold">PPI answer breakdown</h2>
            <ol class="list-decimal space-y-2 pl-5">
                @foreach ($application->answers->sortBy(fn ($answer) => $answer->question?->position) as $answer)
                    <li><strong>{{ $answer->question?->prompt }}</strong><br>{{ $answer->option?->label }} · {{ $answer->points_awarded }} points</li>
                @endforeach
            </ol>
        </section>
        @if ($application->beneficiaryApplication)
            <a class="underline" href="{{ route('applications.show', $application->beneficiaryApplication) }}">Open staff assessment</a>
        @elseif ($application->status->allowedTransitions() !== [])
            <form method="post" action="{{ route('ppi-applications.assessment.store', $application) }}">
                @csrf
                <button class="rounded border px-4 py-2" type="submit">Select for household assessment</button>
            </form>
        @endif
        @if ($application->status->allowedTransitions() !== [])
            <form method="post" action="{{ route('ppi-applications.status.update', $application) }}" class="space-y-2">
                @csrf
                @method('PATCH')
                <label class="block font-semibold" for="ppi-review-status">Review status</label>
                <select id="ppi-review-status" name="status" required class="rounded border p-2">
                    @foreach ($application->status->allowedTransitions() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
                @error('status')<p role="alert">{{ $message }}</p>@enderror
                <label class="block font-semibold" for="ppi-review-note">Note (optional)</label>
                <textarea id="ppi-review-note" name="note" maxlength="500" class="block w-full rounded border p-2"></textarea>
                @error('note')<p role="alert">{{ $message }}</p>@enderror
                <button class="rounded border px-4 py-2" type="submit">Save review status</button>
            </form>
        @endif
    </div>
</x-layouts::app>
