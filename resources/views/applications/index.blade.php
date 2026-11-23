<x-layouts::app :title="__('Applications')">
    <div class="mx-auto max-w-5xl space-y-6">
        <flux:heading size="xl">Applications</flux:heading>
        @can('viewAny', \App\Models\PpiHouseholdApplication::class)
            <a class="underline" href="{{ route('ppi-applications.index') }}">Open PPI applications</a>
        @endcan
        <p>Applications pending staff review.</p>
        @forelse ($applications as $application)
            <article class="rounded-xl border p-4">
                <a href="{{ route('applications.show', $application) }}">{{ $application->guardian_name }}</a>
                <p>Reference: {{ $application->contactReference() }} · {{ $application->status === 'pending_review' ? 'Pending Review' : $application->status }}</p>
            </article>
        @empty
            <p>No applications have been received.</p>
        @endforelse
        {{ $applications->links() }}
    </div>
</x-layouts::app>
