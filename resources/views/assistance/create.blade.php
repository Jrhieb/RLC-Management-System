<x-layouts::app :title="'Record Assistance Release'">
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
        <div>
            <a href="{{ route('beneficiaries.show', $beneficiary) }}"
               class="text-sm text-violet-600 dark:text-violet-400">
                &larr; Back to beneficiary profile
            </a>
            <h1 class="mt-3 text-3xl font-bold">Record Assistance Release</h1>
            <p class="mt-2 text-sm text-zinc-500">
                Beneficiary: <strong>{{ $beneficiary->full_name }}</strong>
                ({{ $beneficiary->beneficiary_id }})
            </p>
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

        <form method="POST"
              action="{{ route('beneficiaries.assistance.store', $beneficiary) }}"
              class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            @csrf
            <input type="hidden" name="submission_token"
                   value="{{ old('submission_token', (string) \Illuminate\Support\Str::uuid()) }}">

            <div>
                <label for="category" class="mb-2 block text-sm font-medium">Category *</label>
                <select id="category" name="category" required
                        class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    <option value="">Select category</option>
                    <option value="education" @selected(old('category') === 'education')>Education</option>
                    <option value="healthcare" @selected(old('category') === 'healthcare')>Healthcare</option>
                    <option value="gifts" @selected(old('category') === 'gifts')>Gifts</option>
                    <option value="other" @selected(old('category') === 'other')>Other</option>
                </select>
            </div>

            <div>
                <label for="item_name" class="mb-2 block text-sm font-medium">Assistance or item name *</label>
                <input id="item_name" name="item_name" type="text" required maxlength="255"
                       value="{{ old('item_name') }}" placeholder="e.g. School supplies"
                       class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="quantity" class="mb-2 block text-sm font-medium">Quantity *</label>
                    <input id="quantity" name="quantity" type="number" required min="1" max="100000"
                           value="{{ old('quantity', 1) }}"
                           class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                </div>
                <div>
                    <label for="released_at" class="mb-2 block text-sm font-medium">Release date and time (PHT) *</label>
                    <input id="released_at" name="released_at" type="datetime-local" required
                           value="{{ old('released_at', \Illuminate\Support\Carbon::now('Asia/Manila')->format('Y-m-d\TH:i')) }}"
                           class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                </div>
            </div>

            <div>
                <label for="notes" class="mb-2 block text-sm font-medium">Notes</label>
                <textarea id="notes" name="notes" rows="4" maxlength="5000"
                          class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">{{ old('notes') }}</textarea>
            </div>

            <div class="flex flex-wrap gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                <button type="submit"
                        class="rounded-xl bg-violet-600 px-6 py-3 text-sm font-semibold text-white hover:bg-violet-700">
                    Record Release
                </button>
                <a href="{{ route('beneficiaries.show', $beneficiary) }}"
                   class="rounded-xl border border-zinc-300 px-6 py-3 text-sm dark:border-zinc-600">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</x-layouts::app>