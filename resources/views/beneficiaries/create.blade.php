<x-layouts::app :title="'Add Beneficiary'">
    @php
        $pendingNfcUid = is_string(old('nfc_uid')) ? old('nfc_uid') : null;
        $oldCaseworkerId = old('caseworker_id', $selectedCaseworkerId);
        $caseworkerSelection = (is_string($oldCaseworkerId) || is_int($oldCaseworkerId))
            ? (string) ($caseworkers->first(fn ($account) => (string) $account->id === (string) $oldCaseworkerId)?->id ?? '')
            : '';
    @endphp
    <div data-nfc-registration
         data-config="{{ json_encode(['mode' => 'pending', 'method' => 'POST', 'url' => route('beneficiaries.nfc.check'), 'nfcUid' => $pendingNfcUid, 'pendingError' => $errors->first('nfc_uid')]) }}"
         class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-8">
        <div>
            <a href="{{ route('beneficiaries.index') }}"
               class="text-sm text-violet-600 dark:text-violet-400">
                &larr; Back to beneficiaries
            </a>
            <h1 class="mt-3 text-3xl font-bold">Add Beneficiary</h1>
            <p class="mt-1 text-sm text-zinc-500">
                Register a beneficiary already approved by RLCP.
                Fields marked * are required.
            </p>
        </div>

        @if ($errors->any())
            <div role="alert"
                 class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">Please check the following:</p>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" data-beneficiary-create-form
              action="{{ route('beneficiaries.store') }}"
              enctype="multipart/form-data"
              class="space-y-8 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            @csrf

            <section class="space-y-4">
                <h2 class="text-lg font-semibold">Profile photo</h2>

                <div>
                    <label for="photo" class="mb-2 block text-sm font-medium">
                        Upload photo
                    </label>
                    <input id="photo" name="photo" type="file"
                           accept="image/jpeg,image/png,image/webp"
                           aria-describedby="photo-help"
                           class="block w-full rounded-xl border border-zinc-300 p-3 text-sm dark:border-zinc-600">
                    <p id="photo-help" class="mt-2 text-xs text-zinc-500">
                        Optional. JPG, PNG, or WebP. Maximum 10 MB.
                        If validation fails, select your photo again.
                    </p>
                </div>
            </section>

            <section class="space-y-4">
                <h2 class="text-lg font-semibold">Beneficiary information</h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="beneficiary_id" class="mb-2 block text-sm font-medium">
                            Beneficiary ID *
                        </label>
                        <input id="beneficiary_id" name="beneficiary_id"
                               value="{{ old('beneficiary_id') }}"
                               required maxlength="50"
                               class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>

                    <div>
                        <label for="full_name" class="mb-2 block text-sm font-medium">
                            Full name *
                        </label>
                        <input id="full_name" name="full_name"
                               value="{{ old('full_name') }}"
                               required maxlength="255"
                               class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>

                    <div>
                        <label for="registered_at" class="mb-2 block text-sm font-medium">
                            Registration date *
                        </label>
                        <input id="registered_at" name="registered_at" type="date"
                               value="{{ old('registered_at', now()->toDateString()) }}"
                               required
                               class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>

                    <div>
                        <label for="birthdate" class="mb-2 block text-sm font-medium">
                            Birthdate
                        </label>
                        <input id="birthdate" name="birthdate" type="date"
                               value="{{ old('birthdate') }}"
                               max="{{ now()->toDateString() }}"
                               class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>

                    <div class="md:col-span-2">
                        <label for="address" class="mb-2 block text-sm font-medium">
                            Address
                        </label>
                        <textarea id="address" name="address" rows="3"
                                  maxlength="1000"
                                  class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">{{ old('address') }}</textarea>
                    </div>
                </div>

                <p class="text-sm text-zinc-500">
                    New beneficiaries will have an Active status.
                </p>
            </section>

            @can('assignCaseworker', \App\Models\Beneficiary::class)
                <section class="space-y-4" data-beneficiary-caseworker>
                    <h2 class="text-lg font-semibold">Caseworker assignment <span class="text-sm font-normal text-zinc-500 dark:text-zinc-400">(optional)</span></h2>
                    <div class="min-w-0">
                        <label for="caseworker_id" class="mb-2 block text-sm font-medium">Caseworker</label>
                        <select id="caseworker_id" name="caseworker_id"
                                aria-invalid="{{ $errors->has('caseworker_id') ? 'true' : 'false' }}"
                                aria-describedby="caseworker-help{{ $errors->has('caseworker_id') ? ' caseworker-error' : '' }}"
                                class="w-full min-w-0 rounded-xl border border-zinc-300 bg-white px-4 py-3 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/20 dark:border-zinc-600 dark:bg-zinc-800">
                            <option value="" @selected($caseworkerSelection === '')>Unassigned</option>
                            @foreach ($caseworkers as $caseworker)
                                <option value="{{ $caseworker->id }}" @selected($caseworkerSelection === (string) $caseworker->id)>{{ $caseworker->name }} &mdash; {{ $caseworker->email }}</option>
                            @endforeach
                        </select>
                        <p id="caseworker-help" class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            Only approved caseworkers with verified email are available. Leave Unassigned to assign later.
                            @if ($caseworkers->isEmpty()) No eligible caseworkers are available yet. @endif
                        </p>
                        @if ($preselectionUnavailable && ! session()->hasOldInput('caseworker_id'))
                            <p role="status" class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">The requested caseworker is unavailable. Choose an eligible caseworker or leave Unassigned.</p>
                        @endif
                        @error('caseworker_id')
                            <p id="caseworker-error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </section>
            @endcan

            <section class="space-y-4">
                <h2 class="text-lg font-semibold">Parent or guardian</h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="guardian_name" class="mb-2 block text-sm font-medium">
                            Parent / guardian name
                        </label>
                        <input id="guardian_name" name="guardian_name"
                               value="{{ old('guardian_name') }}"
                               maxlength="255"
                               class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>

                    <div>
                        <label for="guardian_contact" class="mb-2 block text-sm font-medium">
                            Contact number
                        </label>
                        <input id="guardian_contact" name="guardian_contact" type="tel"
                               value="{{ old('guardian_contact') }}"
                               maxlength="30" placeholder="09XXXXXXXXX"
                               class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                </div>
            </section>

            <section class="space-y-4">
                <h2 class="text-lg font-semibold">Education</h2>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="school" class="mb-2 block text-sm font-medium">
                            School
                        </label>
                        <input id="school" name="school"
                               value="{{ old('school') }}"
                               maxlength="255"
                               class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>

                    <div>
                        <label for="grade_level" class="mb-2 block text-sm font-medium">
                            Grade level
                        </label>
                        <input id="grade_level" name="grade_level"
                               value="{{ old('grade_level') }}"
                               maxlength="50" placeholder="e.g. Grade 5"
                               class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                </div>
            </section>

            <section class="space-y-4">
                <h2 class="text-lg font-semibold">NFC Card <span class="text-sm font-normal text-zinc-500">(optional)</span></h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">The card will be linked when you save this beneficiary.</p>
                <input type="hidden" name="nfc_uid" data-nfc-pending-input value="{{ $pendingNfcUid }}">
                <p class="text-sm">Pending card: <span data-nfc-linked-uid class="break-all font-mono font-semibold">{{ $pendingNfcUid ?? 'No card selected' }}</span></p>
                <p data-nfc-pending-status role="status" aria-live="polite" class="text-sm text-zinc-500 dark:text-zinc-400">{{ $errors->first('nfc_uid') ?: ($pendingNfcUid ? 'Card ready to link' : '') }}</p>
                <div class="flex flex-wrap gap-3">
                    <button type="button" data-nfc-open disabled
                            class="rounded-xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">Register NFC Card</button>
                    <button type="button" data-nfc-remove @if (! $pendingNfcUid) hidden @endif
                            class="rounded-xl border border-zinc-300 px-5 py-3 text-sm font-semibold hover:bg-zinc-100 disabled:opacity-50 dark:border-zinc-600 dark:hover:bg-zinc-800">Remove card</button>
                </div>
            </section>

            <div class="flex flex-wrap gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <button type="submit" data-beneficiary-submit
                        class="rounded-xl bg-violet-600 px-6 py-3 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">
                    Save Beneficiary
                </button>
                <a href="{{ route('beneficiaries.index') }}"
                   class="rounded-xl border border-zinc-300 px-6 py-3 text-sm dark:border-zinc-600">
                    Cancel
                </a>
            </div>
        </form>
        @include('beneficiaries.partials.nfc-registration-dialog', [
            'nfcUrl' => route('beneficiaries.nfc.check'),
            'nfcPending' => true,
        ])
    </div>
</x-layouts::app>
