<x-layouts::app title="PPI Applications">
    <div class="mx-auto max-w-5xl space-y-6">
        <h1 class="text-2xl font-semibold">PPI Applications</h1>
        <nav aria-label="Application lists" class="flex flex-wrap gap-4">
            <a class="underline" href="{{ route('ppi-applications.index') }}" @unless ($screenedOut) aria-current="page" @endunless>Review queue</a>
            <a class="underline" href="{{ route('ppi-applications.index', ['screening' => 'not-passed']) }}" @if ($screenedOut) aria-current="page" @endif>Did not pass screening</a>
            <a class="underline" href="{{ route('applications.index') }}">Existing applications</a>
        </nav>
        @unless ($screenedOut)<p>Applications are ordered from lowest to highest PPI score.</p>@endunless
        @forelse ($applications as $application)
            <article class="rounded-xl border p-4">
                <a class="font-semibold underline" href="{{ route('ppi-applications.show', $application) }}">{{ $application->child_first_name }} {{ $application->child_last_name }}</a>
                <p>{{ $application->reference }} · {{ $application->status->label() }} · PPI score {{ $application->ppi_score ?? 'Unavailable' }}</p>
            </article>
        @empty
            <p>No applications in this list.</p>
        @endforelse
        {{ $applications->links() }}
    </div>
</x-layouts::app>
