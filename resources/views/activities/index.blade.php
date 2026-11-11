<x-layouts::app :title="'Program Activities'">
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold">Program Activities</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    View activities across the four development areas.
                </p>
            </div>

            <a href="{{ route('activities.create') }}"
               class="rounded-xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-700">
                + Add Activity
            </a>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($activities as $activity)
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-400">
                        {{ ucwords(str_replace('_', ' ', $activity->development_area)) }}
                    </p>

                    <h2 class="mt-2 text-lg font-semibold">
                        {{ $activity->title }}
                    </h2>

                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $activity->activity_date->format('M d, Y') }}
                        @if ($activity->start_time)
                            · {{ \Illuminate\Support\Carbon::parse($activity->start_time)->format('g:i A') }}
                        @endif
                    </p>

                    @if ($activity->location)
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $activity->location }}
                        </p>
                    @endif

                    <a href="{{ route('activities.show', $activity) }}"
                       class="mt-5 inline-block rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">
                        View Activity
                    </a>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center md:col-span-2 xl:col-span-3 dark:border-zinc-700">
                    <p class="font-semibold">No activities yet.</p>
                    <p class="mt-2 text-sm text-zinc-500">Add the first program activity to get started.</p>
                </div>
            @endforelse
        </div>

        {{ $activities->links() }}
    </div>
</x-layouts::app>