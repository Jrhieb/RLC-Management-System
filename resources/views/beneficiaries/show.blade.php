<x-layouts::app :title="$beneficiary->full_name">
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
        <a href="{{ route('beneficiaries.index') }}"
           class="text-sm font-medium text-violet-600 dark:text-violet-400">
            &larr; Back to beneficiaries
        </a>

        <div class="flex justify-end">
            <a href="{{ route('beneficiaries.edit', $beneficiary) }}"
               class="rounded-xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-700">
                Edit Profile
            </a>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3">
            {{-- rlc-archive-link-permission --}}
@can('viewArchived', \App\Models\Beneficiary::class)
<a href="{{ route('beneficiaries.archived') }}"
               class="rounded-xl border border-zinc-300 px-5 py-3 text-sm dark:border-zinc-600">
                View Archived
            </a>
@endcan

            {{-- rlc-archive-button-permission --}}
@can('delete', $beneficiary)
<form method="POST"
                  action="{{ route('beneficiaries.destroy', $beneficiary) }}"
                  onsubmit="return confirm('Archive this beneficiary? The profile and photo will be retained, and you can restore the record later.');">
                @csrf
                @method('DELETE')

                <button type="submit"
                        class="rounded-xl bg-red-600 px-5 py-3 text-sm font-semibold text-white hover:bg-red-700">
                    Archive Beneficiary
                </button>
            </form>
@endcan
        </div>

        {{-- rlc-active-assistance-button --}}
        @can('update', $beneficiary)
            @if ($beneficiary->status === 'active')
                <div class="flex justify-end">
                    <button type="button"
        onclick="document.getElementById('assistance-release-dialog').showModal()"
        class="rounded-xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-700">
    Record Assistance Release
</button>
                </div>
            @endif
        @endcan

        @if (session('success'))
            <div role="status"
                 class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="h-24 bg-violet-600"></div>

            <div class="px-6 pb-6">
                <div class="-mt-12 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div> 
                        @if ($beneficiary->photo_path)
                            <img src="{{ route('beneficiaries.photo', $beneficiary) }}"
                                 alt="Profile photo of {{ $beneficiary->full_name }}"
                                 class="size-24 rounded-2xl border-4 border-white bg-white object-cover dark:border-zinc-900">
                        @else
                            <div class="flex size-24 items-center justify-center rounded-2xl border-4 border-white bg-violet-100 text-3xl font-bold text-violet-700 dark:border-zinc-900">
                                {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($beneficiary->full_name, 0, 1)) }}
                            </div>
                        @endif

                        <h1 class="mt-4 break-words text-2xl font-bold">
                            {{ $beneficiary->full_name }}
                        </h1>
                        <p class="mt-1 text-sm text-zinc-500">
                            Beneficiary ID: {{ $beneficiary->beneficiary_id }}
                        </p>
                    </div>

                    <span @class([
                        'self-start rounded-full px-4 py-2 text-sm font-semibold',
                        'bg-green-100 text-green-800' => $beneficiary->status === 'active',
                        'bg-zinc-100 text-zinc-700' => $beneficiary->status !== 'active',
                    ])>
                        {{ ucfirst($beneficiary->status) }}
                    </span>
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="mb-5 text-lg font-semibold">Personal information</h2>

                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm text-zinc-500">Registration date</dt>
                        <dd class="mt-1 font-medium">
                            {{ $beneficiary->registered_at?->format('F d, Y') ?? 'Not recorded' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500">Birthdate</dt>
                        <dd class="mt-1 font-medium">
                            {{ $beneficiary->birthdate?->format('F d, Y') ?? 'Not recorded' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500">Age</dt>
                        <dd class="mt-1 font-medium">
                            {{ $beneficiary->birthdate ? $beneficiary->birthdate->age . ' years old' : 'Not recorded' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-zinc-500">Address</dt>
                        <dd class="mt-1 whitespace-pre-line break-words font-medium">{{ $beneficiary->address ?: 'Not recorded' }}</dd>
                    </div>
                </dl>
            </section>

            <div class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h2 class="mb-5 text-lg font-semibold">Parent or guardian</h2>

                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm text-zinc-500">Name</dt>
                            <dd class="mt-1 break-words font-medium">
                                {{ $beneficiary->guardian_name ?: 'Not recorded' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-zinc-500">Contact number</dt>
                            <dd class="mt-1 font-medium">
                                {{ $beneficiary->guardian_contact ?: 'Not recorded' }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h2 class="mb-5 text-lg font-semibold">Education</h2>

                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm text-zinc-500">School</dt>
                            <dd class="mt-1 break-words font-medium">
                                {{ $beneficiary->school ?: 'Not recorded' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-zinc-500">Grade level</dt>
                            <dd class="mt-1 font-medium">
                                {{ $beneficiary->grade_level ?: 'Not recorded' }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-lg font-semibold">Attendance History</h2>
            <p class="mt-1 text-sm text-zinc-500">
                Activities attended by this beneficiary ({{ $attendances->total() }}).
            </p>

            <div class="mt-5 space-y-3">
                @forelse ($attendances as $attendance)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div>
                            <a href="{{ route('activities.show', $attendance->activity) }}"
                               class="font-semibold text-violet-600 hover:underline dark:text-violet-400">
                                {{ $attendance->activity->title }}
                            </a>
                            <p class="text-sm text-zinc-500">
                                {{ ucwords(str_replace('_', ' ', $attendance->activity->development_area)) }}
                                · Activity date: {{ $attendance->activity->activity_date->format('M d, Y') }}
                            </p>
                        </div>
                        <div class="text-sm text-zinc-500">
                            <p>Checked in: {{ $attendance->checked_in_at->timezone('Asia/Manila')->format('M d, Y · g:i A') }} PHT</p>
                            <p>Via {{ match ($attendance->source) { 'nfc' => 'NFC', 'nfc_test' => 'Test UID', default => ucfirst($attendance->source) } }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No attendance recorded yet.</p>
                @endforelse
            </div>

            <div class="mt-5">{{ $attendances->links() }}</div>
        </section>
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-lg font-semibold">Assistance History</h2>
            <p class="mt-1 text-sm text-zinc-500">
                Recorded releases for this beneficiary ({{ $assistanceTransactions->total() }}).
            </p>

            <div class="mt-5 space-y-3">
                @forelse ($assistanceTransactions as $transaction)
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold">{{ $transaction->item_name }}</p>
                                <p class="text-sm text-zinc-500">
                                    {{ ucfirst($transaction->category) }}
                                    · Quantity: {{ $transaction->quantity }}
                                </p>
                            </div>
                            <p class="text-sm text-zinc-500">
                                {{ $transaction->released_at->timezone('Asia/Manila')->format('M d, Y · g:i A') }} PHT
                            </p>
                        </div>

                        @if ($transaction->notes)
                            <p class="mt-3 whitespace-pre-line text-sm">{{ $transaction->notes }}</p>
                        @endif

                        <p class="mt-3 text-xs text-zinc-500">
                            Recorded by {{ $transaction->recorder?->name ?? 'Former staff member' }}
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No assistance releases recorded yet.</p>
                @endforelse
            </div>

            <div class="mt-5">{{ $assistanceTransactions->links() }}</div>
        </section>
        {{-- rlc-beneficiary-nfc --}}
        @can('update', $beneficiary)
            <section data-nfc-registration
                     data-config="{{ json_encode(['url' => route('beneficiaries.nfc.update', $beneficiary), 'nfcUid' => $beneficiary->nfc_uid]) }}"
                     class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold">NFC Card</h2>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Register an NFC card for this beneficiary.
                </p>

                <p class="mt-4 text-sm">
                    Current UID:
                    <span data-nfc-linked-uid class="break-all font-mono font-semibold">{{ $beneficiary->nfc_uid ?? 'No card linked' }}</span>
                </p>

                @error('nfc_uid')
                    <p role="alert" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                <button type="button" data-nfc-open disabled
                        class="mt-5 rounded-xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">
                    Register NFC Card
                </button>

                <form method="POST" data-nfc-unlink @if (! $beneficiary->nfc_uid) hidden @endif
                      action="{{ route('beneficiaries.nfc.destroy', $beneficiary) }}"
                      class="mt-4"
                      onsubmit="return confirm('Unlink this card from the beneficiary?');">
                    @csrf
                    @method('DELETE')

                    <button type="submit"
                            class="rounded-xl border border-red-300 px-5 py-3 text-sm font-semibold text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-950">
                        Unlink Card
                    </button>
                </form>
                @include('beneficiaries.partials.nfc-registration-dialog', [
                    'nfcUrl' => route('beneficiaries.nfc.update', $beneficiary),
                    'nfcName' => $beneficiary->full_name,
                    'nfcPending' => false,
                ])
            </section>
        @endcan

    </div>
        {{-- rlc-assistance-modal --}}
        @can('update', $beneficiary)
            @if ($beneficiary->status === 'active')
                <dialog id="assistance-release-dialog"
                        aria-labelledby="assistance-dialog-title"
                        x-data
                        @if ($errors->hasAny(['category', 'item_name', 'quantity', 'released_at', 'notes', 'submission_token']))
                            x-init="$el.showModal()"
                        @endif
                        class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 text-zinc-900 shadow-xl backdrop:bg-black/60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    <div class="mb-5 flex items-start justify-between gap-4">
                        <div>
                            <h2 id="assistance-dialog-title" class="text-xl font-bold">Record Assistance Release</h2>
                            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $beneficiary->full_name }} ({{ $beneficiary->beneficiary_id }})
                            </p>
                        </div>
                        <button type="button" aria-label="Close assistance form"
                                onclick="this.closest('dialog').close()"
                                class="rounded-lg px-3 py-2 text-xl hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            &times;
                        </button>
                    </div>

                    @if ($errors->hasAny(['category', 'item_name', 'quantity', 'released_at', 'notes', 'submission_token']))
                        <div role="alert" class="mb-5 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                            <p class="font-semibold">Please check the following:</p>
                            <ul class="mt-2 list-inside list-disc">
                                @foreach (['category', 'item_name', 'quantity', 'released_at', 'notes', 'submission_token'] as $field)
                                    @foreach ($errors->get($field) as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                @endforeach
                            </ul>
                        </div>
                    @endif
<form x-data="{ submitting: false }"
      @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" method="POST"
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
                    <option value="other" @selected(old('category'  ) === 'other')>Other</option>
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
                <button type="submit" :disabled="submitting" :aria-busy="submitting"
        class="inline-flex items-center justify-center gap-2 rounded-xl bg-violet-600 px-6 py-3 text-sm font-semibold text-white hover:bg-violet-700 disabled:cursor-wait disabled:opacity-60">
    <svg x-show="submitting" style="display:none;" class="size-5 animate-spin"
         viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
        <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
    </svg>
    <span x-text="submitting ? 'Saving...' : 'Record Release'">Record Release</span>
</button>
                <button type="button" onclick="this.closest('dialog').close()"
        class="rounded-xl border border-zinc-300 px-6 py-3 text-sm dark:border-zinc-600">
    Cancel
</button>
            </div>
        {{-- rlc-assistance-saving-animation --}}
<div x-show="submitting" style="display:none;"
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
    <div role="status" aria-live="polite"
         class="rounded-2xl bg-white p-8 text-center shadow-xl dark:bg-zinc-800">
        <svg class="mx-auto mb-4 size-12 animate-spin text-violet-600"
             viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
            <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <p class="text-lg font-semibold">Saving release...</p>
        <p class="mt-2 text-sm text-zinc-500">Please wait.</p>
    </div>
</div>
</form>
                </dialog>
            @endif
        @endcan
</x-layouts::app>
