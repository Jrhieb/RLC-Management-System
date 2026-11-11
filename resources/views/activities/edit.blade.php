<x-layouts::app :title="'Edit Activity'">
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
        <div>
            <a href="{{ route('activities.show', $activity) }}"
               class="text-sm text-violet-600 dark:text-violet-400">
                &larr; Back to activity
            </a>
            <h1 class="mt-3 text-3xl font-bold">Edit Activity</h1>
            <p class="mt-2 text-sm text-zinc-500">Fields marked * are required.</p>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">Please check the following:</p>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('activities.update', $activity) }}"
              class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            @csrf
            @method('PUT')

            <div>
                <label for="title" class="mb-2 block text-sm font-medium">Activity title *</label>
                <input id="title" name="title" type="text" required maxlength="255"
                       value="{{ old('title', $activity->title) }}"
                       class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="activity_date" class="mb-2 block text-sm font-medium">Date *</label>
                    <input id="activity_date" name="activity_date" type="date" required
                           value="{{ old('activity_date', $activity->activity_date->format('Y-m-d')) }}"
                           class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                </div>

                <div>
                    <label for="start_time" class="mb-2 block text-sm font-medium">Start time</label>
                    <input id="start_time" name="start_time" type="time"
                           value="{{ old('start_time', $activity->start_time ? substr($activity->start_time, 0, 5) : '') }}"
                           class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                </div>
            </div>

            <div>
                <label for="development_area" class="mb-2 block text-sm font-medium">Development area *</label>
                <select id="development_area" name="development_area" required
                        class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    <option value="">Select an area</option>
                    <option value="physical" @selected(old('development_area', $activity->development_area) === 'physical')>Physical</option>
                    <option value="cognitive" @selected(old('development_area', $activity->development_area) === 'cognitive')>Cognitive</option>
                    <option value="socio_emotional" @selected(old('development_area', $activity->development_area) === 'socio_emotional')>Socio-emotional</option>
                    <option value="spiritual" @selected(old('development_area', $activity->development_area) === 'spiritual')>Spiritual</option>
                </select>
            </div>

            <div>
                <label for="location" class="mb-2 block text-sm font-medium">Location</label>
                <input id="location" name="location" type="text" maxlength="255"
                       value="{{ old('location', $activity->location) }}"
                       class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
            </div>

            <div>
                <label for="description" class="mb-2 block text-sm font-medium">Description</label>
                <textarea id="description" name="description" rows="5" maxlength="5000"
                          class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">{{ old('description', $activity->description) }}</textarea>
            </div>

            <div class="flex flex-wrap gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                <button type="submit"
                        class="rounded-xl bg-violet-600 px-6 py-3 text-sm font-semibold text-white hover:bg-violet-700">
                    Save Changes
                </button>
                <a href="{{ route('activities.show', $activity) }}"
                   class="rounded-xl border border-zinc-300 px-6 py-3 text-sm dark:border-zinc-600">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</x-layouts::app>