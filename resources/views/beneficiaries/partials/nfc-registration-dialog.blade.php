<dialog data-nfc-dialog id="nfc-registration-dialog"
        aria-labelledby="nfc-registration-title" aria-describedby="nfc-registration-status"
        class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-2xl border border-violet-200 bg-white p-0 text-zinc-900 shadow-xl backdrop:bg-black/60 dark:border-violet-900 dark:bg-zinc-900 dark:text-white">
    <div class="flex items-start justify-between gap-4 border-b border-violet-100 bg-violet-50 p-6 dark:border-violet-900 dark:bg-violet-950">
        <div>
            <h2 id="nfc-registration-title" class="text-xl font-bold">Register NFC Card</h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $nfcName ?? 'The card will be linked when you save this beneficiary.' }}</p>
        </div>
        <button type="button" data-nfc-close aria-label="Close NFC registration"
                class="rounded-lg px-3 py-1 text-2xl hover:bg-violet-100 dark:hover:bg-violet-900">&times;</button>
    </div>

    <div class="space-y-5 p-6">
        <div class="text-center">
            <svg data-nfc-card-icon class="mx-auto mb-4 size-20 text-violet-600 motion-safe:animate-pulse dark:text-violet-400"
                 viewBox="0 0 80 80" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
                <rect x="10" y="22" width="52" height="38" rx="7" />
                <path d="M10 33h52M20 48h12M65 22c6 4 6 12 0 16M70 16c11 7 11 21 0 28" stroke-linecap="round" />
            </svg>
            <svg data-nfc-loading-icon hidden class="mx-auto mb-4 size-16 text-violet-600 motion-safe:animate-spin dark:text-violet-400"
                 viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
                <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <svg data-nfc-success-icon hidden class="mx-auto mb-4 size-16 text-violet-600 dark:text-violet-400"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10" /><path d="m7 12 3 3 7-7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <p id="nfc-registration-status" data-nfc-status role="status" aria-live="polite" class="font-semibold motion-safe:animate-pulse">Waiting for card…</p>
        </div>

        <form data-nfc-scan-form method="POST" action="{{ $nfcUrl }}" class="space-y-3">
            @csrf
            @if (! $nfcPending)
                @method('PUT')
            @endif
            <input data-nfc-input id="nfc-registration-input" name="nfc_uid" type="text" disabled
                   maxlength="64" autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="none" tabindex="-1"
                   aria-label="NFC card reader" aria-describedby="nfc-registration-status" class="sr-only">
            <div data-nfc-manual-fields id="nfc-registration-manual-fields" hidden class="space-y-3">
                <label for="nfc-registration-manual-input" class="block text-sm font-medium">Card identifier</label>
                <input data-nfc-manual-input id="nfc-registration-manual-input" type="text" required disabled
                       maxlength="64" autocomplete="off" autocapitalize="off" spellcheck="false"
                       aria-describedby="nfc-registration-help" placeholder="0027947780 or 04:A1:B2:C3"
                       class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 font-mono focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500 dark:border-zinc-600 dark:bg-zinc-800">
                <p id="nfc-registration-help" class="text-sm text-zinc-500 dark:text-zinc-400">Enter the identifier printed by your card reader.</p>
                <button type="submit" class="w-full rounded-xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-700">{{ $nfcPending ? 'Check card' : 'Register card' }}</button>
            </div>
            <button type="button" data-nfc-manual-toggle aria-controls="nfc-registration-manual-fields" aria-expanded="false"
                    class="mx-auto block rounded-lg px-3 py-2 text-sm text-violet-600 hover:bg-violet-50 dark:text-violet-400 dark:hover:bg-violet-950">Enter card manually</button>
        </form>

        @if (! $nfcPending)
            <div data-nfc-confirmation hidden class="space-y-4 rounded-xl border border-violet-200 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950">
                <p class="text-sm">Replace <span data-nfc-existing class="break-all font-mono font-semibold"></span> with <span data-nfc-candidate class="break-all font-mono font-semibold"></span>?</p>
                <button type="button" data-nfc-replace class="w-full rounded-xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-700">Replace card</button>
            </div>
        @endif

        <div class="flex flex-wrap justify-end gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
            <button type="button" data-nfc-scan-again hidden class="rounded-xl px-4 py-2 text-sm font-semibold text-violet-600 hover:bg-violet-50 dark:text-violet-400 dark:hover:bg-violet-950">Scan another card</button>
            <button type="button" data-nfc-close class="rounded-xl border border-zinc-300 px-5 py-2 text-sm font-semibold hover:bg-zinc-100 dark:border-zinc-600 dark:hover:bg-zinc-800">Close / Cancel</button>
        </div>
    </div>
</dialog>
