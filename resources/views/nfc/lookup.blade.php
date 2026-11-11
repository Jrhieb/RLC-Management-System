<x-layouts::app :title="'NFC Lookup'">
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
        <div>
            <h1 class="text-3xl font-bold">NFC Lookup</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                Find an active beneficiary using their linked card UID.
            </p>
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <form method="POST" action="{{ route('nfc.lookup') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="nfc_uid" class="mb-2 block text-sm font-medium">
                        Card UID
                    </label>
                    <input id="nfc_uid" name="nfc_uid" type="text"
                           required maxlength="64"
                           value="{{ old('nfc_uid') }}"
                           autocomplete="off" spellcheck="false"
                           placeholder="e.g. 04A1B2C3D4E5F6"
                           @error('nfc_uid') aria-invalid="true" aria-describedby="nfc_uid-error" @enderror
                           class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 font-mono dark:border-zinc-600 dark:bg-zinc-800">

                    @error('nfc_uid')
                        <p id="nfc_uid-error" role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit"
                            class="rounded-xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-700">
                        Find Beneficiary
                    </button>
                    <a href="{{ route('beneficiaries.index') }}"
                       class="rounded-xl border border-zinc-300 px-5 py-3 text-sm font-semibold hover:bg-zinc-100 dark:border-zinc-600 dark:hover:bg-zinc-800">
                        Browse Beneficiaries
                    </a>
                </div>
            </form>

            <p class="mt-6 border-t border-zinc-200 pt-4 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                You can enter a test UID while the reader is unavailable.
                This lookup opens the profile; attendance and assistance are recorded through their respective forms.
            </p>
        </section>
    </div>
</x-layouts::app>