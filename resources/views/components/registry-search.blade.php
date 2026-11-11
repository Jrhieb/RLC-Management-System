@props(['value' => ''])

<div class="rlcr-search-wrap" x-data="{ term: {{ \Illuminate\Support\Js::from($value) }} }">
    <div class="rlcr-search-field">
        <span data-beneficiary-search-icon x-show="!term.length" aria-hidden="true">
            <x-rlcp-icon name="search" />
        </span>
        <label class="rlcr-sr-only" for="rlcr-search">Search beneficiaries by name or ID</label>
        <input id="rlcr-search" name="search" type="search" maxlength="100" value="{{ $value }}"
               placeholder="Search by name or beneficiary ID" autocomplete="off" spellcheck="false"
               aria-controls="rlcr-results" x-model="term">
        <button type="button" data-clear-beneficiary-search x-cloak x-show="term.length" aria-label="Clear search" title="Clear search">
            <svg class="rlc-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
        </button>
    </div>
</div>
