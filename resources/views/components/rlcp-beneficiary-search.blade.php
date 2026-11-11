@props(['registry' => false, 'value' => ''])
@php
    $inputId = $registry ? 'rlcr-search' : 'dashboard-beneficiary-search';
    $listId = $registry ? 'rlcr-search-results' : 'rlc-search-results';
    $optionPrefix = $registry ? 'rlcr-search-option-' : 'rlc-search-option-';
@endphp
{{-- Shared Dashboard search; Registry uses its existing filter form. --}}
<div class="rlc-search-wrap{{ $registry ? ' rlcr-search-wrap' : '' }}"
     x-data="{
        term: '', focused: false, results: [], open: false, loading: false, error: '', active: -1,
        timer: null, controller: null, version: 0, submitListener: null,
        get registry() { return this.$root.dataset.registry === 'true'; },
        init() {
            this.term = this.$root.dataset.value;
            if (this.registry) {
                this.submitListener = () => this.dismiss();
                this.$root.closest('form').addEventListener('submit', this.submitListener);
            }
        },
        changed(event) {
            clearTimeout(this.timer);
            this.controller?.abort();
            const version = ++this.version;
            this.results = []; this.active = -1; this.error = '';
            if (this.registry && event?.isComposing) { this.open = false; this.loading = false; return; }
            if (!this.term.trim()) {
                this.open = false; this.loading = false;
                if (this.registry && event) this.$refs.input.form.requestSubmit();
                return;
            }
            this.open = true; this.loading = true;
            this.timer = setTimeout(() => this.lookup(version), 250);
        },
        searchUrl(endpoint) {
            const url = new URL(endpoint, window.location.origin);
            if (this.term.trim()) url.searchParams.set('search', this.term.trim());
            else url.searchParams.delete('search');
            if (this.registry) {
                for (const key of ['caseworker', 'status']) {
                    const value = this.$refs.input.form.elements.namedItem(key)?.value;
                    if (value) url.searchParams.set(key, value);
                    else url.searchParams.delete(key);
                }
                url.searchParams.delete('page');
            }
            return url.href;
        },
        async lookup(version) {
            if (version !== this.version) return;
            const controller = new AbortController();
            this.controller = controller;
            try {
                const response = await fetch(this.searchUrl(this.$root.dataset.endpoint), {
                    signal: controller.signal,
                    credentials: 'same-origin', headers: { Accept: 'application/json' }
                });
                if (!response.ok) throw new Error('Search failed');
                const data = await response.json();
                if (version !== this.version) return;
                if (!Array.isArray(data.results)) throw new Error('Invalid response');
                this.results = data.results;
            } catch (error) {
                if (version !== this.version || error.name === 'AbortError') return;
                this.error = 'Unable to search. Try again or press Enter to search.';
            } finally {
                if (version === this.version) {
                    this.loading = false;
                    if (this.registry && this.open) this.$nextTick(() => this.positionDropdown());
                }
                if (this.controller === controller) this.controller = null;
            }
        },
        move(step) {
            if (!this.results.length) return;
            this.open = true;
            this.active = this.registry && this.active < 0
                ? (step > 0 ? 0 : this.results.length - 1)
                : (this.active + step + this.results.length) % this.results.length;
            this.$nextTick(() => document.getElementById(this.optionId(this.active))?.scrollIntoView({ block: 'nearest' }));
        },
        optionId(index) { return this.$root.dataset.optionPrefix + index; },
        select(event) {
            if (this.registry && (event.isComposing || event.keyCode === 229)) { event.preventDefault(); return; }
            if (this.open &amp;&amp; this.active >= 0 &amp;&amp; this.results[this.active]) {
                event.preventDefault(); window.location.assign(this.results[this.active].url);
            } else if (this.registry) {
                event.preventDefault(); this.dismiss(); this.$refs.input.form.requestSubmit();
            }
        },
        clear() {
            this.term = ''; this.$refs.input.value = ''; this.dismiss();
            this.$refs.input.focus(); this.$refs.input.form.requestSubmit();
        },
        dismiss() {
            this.open = false;
            if (this.registry) {
                this.active = -1;
                clearTimeout(this.timer); this.controller?.abort(); this.version++;
                this.results = []; this.error = ''; this.loading = false;
            }
        },
        positionDropdown() {
            if (!this.registry || !this.open) return;
            const dropdown = this.$refs.dropdown;
            if (!dropdown.matches(':popover-open')) dropdown.showPopover();
            const bounds = this.$refs.field.getBoundingClientRect();
            if (bounds.bottom < 0 || bounds.top > window.innerHeight) { this.dismiss(); return; }
            const width = Math.min(bounds.width, window.innerWidth - 24);
            const below = window.innerHeight - bounds.bottom - 20;
            const above = bounds.top - 20;
            const upwards = below < 200 && above > below;
            const available = Math.max(64, upwards ? above : below);
            dropdown.style.width = width + 'px';
            dropdown.style.left = Math.max(12, Math.min(bounds.left, window.innerWidth - width - 12)) + 'px';
            dropdown.style.setProperty('--search-available-height', available + 'px');
            dropdown.style.top = upwards ? 'auto' : (bounds.bottom + 8) + 'px';
            dropdown.style.bottom = upwards ? (window.innerHeight - bounds.top + 8) + 'px' : 'auto';
        },
        destroy() {
            clearTimeout(this.timer); this.controller?.abort(); this.version++;
            if (this.submitListener) this.$root.closest('form')?.removeEventListener('submit', this.submitListener);
            if (this.registry && this.$refs.dropdown?.matches(':popover-open')) this.$refs.dropdown.hidePopover();
        }
     }"
     data-endpoint="{{ route('beneficiaries.suggestions') }}"
     data-registry="{{ $registry ? 'true' : 'false' }}" data-value="{{ $value }}" data-option-prefix="{{ $optionPrefix }}"
     x-on:click.outside="dismiss()"
     x-on:keydown.escape.prevent.stop="dismiss(); active = -1"
     x-on:focusout="if (!$el.contains($event.relatedTarget)) dismiss()"
     x-on:resize.window="if (registry) positionDropdown()" x-on:scroll.window="if (registry) positionDropdown()"
     x-effect="if (registry) { if (open) $nextTick(() => positionDropdown()); else $refs.dropdown?.hidePopover(); }">
    @if (! $registry)
    <form method="GET" action="{{ route('beneficiaries.index') }}" class="rlc-search">
    @else
    <div class="rlcr-search-field" x-ref="field">
    @endif
        <span data-beneficiary-search-icon x-show="!focused && !(registry ? term.length : term.trim())" aria-hidden="true">
            <x-rlcp-icon name="search" />
        </span>
        <label class="sr-only" for="{{ $inputId }}">Search beneficiaries by name or ID</label>
        <input id="{{ $inputId }}" name="search" type="search" maxlength="100" value="{{ $value }}" x-ref="input"
               placeholder="{{ $registry ? 'Search by name or beneficiary ID' : 'Search beneficiaries or ID...' }}" autocomplete="off" spellcheck="false"
               role="combobox" aria-autocomplete="list" aria-controls="{{ $listId }}"
               :aria-expanded="open.toString()"
               :aria-activedescendant="open &amp;&amp; active >= 0 ? optionId(active) : null"
               x-model="term" x-on:input="changed($event)" x-on:compositionend="if (registry) changed()"
               x-on:focus="focused = true; if (term.trim()) { if (registry) changed(); else open = true; }"
               x-on:blur="focused = false"
               x-on:keydown.arrow-down.prevent="move(1)"
               x-on:keydown.arrow-up.prevent="move(-1)"
               x-on:keydown.enter="select($event)">
        @if ($registry)
            <button type="button" data-clear-beneficiary-search x-cloak x-show="term.length" x-on:click="clear()" aria-label="Clear search" title="Clear search">
                <svg class="rlc-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
            </button>
        </div>
        @else
    </form>
        @endif
    <div x-ref="dropdown" @if ($registry) popover="manual" @endif x-cloak x-show="open" x-transition.opacity.duration.150ms class="rlc-search-dropdown">
        <p class="rlc-search-message" role="status" aria-live="polite"
           x-show="loading || error || results.length === 0"
           x-text="loading ? 'Searching...' : (error || 'No beneficiaries found.')"></p>
        <ul id="{{ $listId }}" role="listbox" aria-label="Beneficiary suggestions" :aria-busy="loading.toString()">
            <template x-for="(person, index) in results" :key="person.id">
                <li role="presentation">
                    <a :id="optionId(index)" :href="person.url"
                       role="option" :aria-selected="(active === index).toString()"
                       class="rlc-search-result" :class="{ 'rlc-search-selected': active === index }"
                       x-on:mouseenter="active = index" x-on:mousedown.prevent>
                        <span class="rlc-search-avatar" x-data="{ failed: false }">
                            <template x-if="person.photo_url &amp;&amp; !failed">
                                <img :src="person.photo_url" alt="" x-on:error="failed = true">
                            </template>
                            <span x-show="!person.photo_url || failed" x-text="person.name.trim().charAt(0).toUpperCase()"></span>
                        </span>
                        <span class="rlc-search-person">
                            <strong x-text="person.name"></strong>
                            <span x-text="'ID: ' + person.beneficiary_id"></span>
                        </span>
                        <x-rlcp-icon name="chevron" />
                    </a>
                </li>
            </template>
        </ul>
        <a x-show="!loading &amp;&amp; !error &amp;&amp; results.length > 0"
           :href="searchUrl({{ Illuminate\Support\Js::from(route('beneficiaries.index')) }})"
           class="rlc-search-all">View all matching beneficiaries <span aria-hidden="true">&rarr;</span></a>
    </div>
</div>
@once
<style>
.rlc-search-wrap { position:relative; width:340px; max-width:100%; }
.rlc-search-wrap .rlc-search { width:100%; margin:0; }
.rlc-search-dropdown[x-cloak] { display:none !important; }
.rlc-search-dropdown { position:absolute; top:calc(100% + 8px); left:0; right:0; z-index:50; overflow:hidden; border:1px solid var(--rlc-border); border-radius:16px; background:var(--rlc-surface); color:var(--rlc-ink); box-shadow:0 16px 40px #0003; }
.rlc-search-dropdown ul { list-style:none; margin:0; padding:5px; max-height:340px; overflow-y:auto; }
.rlc-search-result { display:flex; align-items:center; gap:12px; padding:11px; border-radius:11px; text-decoration:none; color:var(--rlc-ink); transition:background .15s; }
.rlc-search-result:hover,.rlc-search-result:focus-visible,.rlc-search-selected { background:var(--rlc-tint); outline:2px solid transparent; }
.rlc-search-avatar { width:42px; height:42px; flex-shrink:0; border-radius:50%; background:var(--rlc-tint); color:var(--rlc-violet); display:flex; align-items:center; justify-content:center; font-weight:700; overflow:hidden; }
.rlc-search-avatar img { width:100%; height:100%; object-fit:cover; }
.rlc-search-person { flex:1; min-width:0; }
.rlc-search-person strong { display:block; font-size:13px; overflow-wrap:anywhere; }
.rlc-search-person>span { display:block; margin-top:3px; font-size:11px; color:var(--rlc-muted); }
.rlc-search-result>.rlc-icon { width:16px; color:var(--rlc-muted); flex-shrink:0; }
.rlc-search-message { margin:0; padding:18px; font-size:13px; color:var(--rlc-muted); }
.rlc-search-all { display:flex; justify-content:space-between; border-top:1px solid var(--rlc-border); padding:13px 16px; font-size:12px; color:var(--rlc-violet); }
@media(max-width:1023px) { .rlc-search-wrap { margin-right:auto; } }
@media(max-width:639px) { .rlc-search-wrap { width:100%; order:3; margin:0; }.rlc-search-dropdown ul { max-height:260px; } }
@media(prefers-reduced-motion:reduce) { .rlc-search-result { transition:none; } }
</style>
@endonce
@once
<style>
/* rlc-search-clean-ui */
.rlc-search-wrap .rlc-search {
    padding: 8px 16px;
}

.rlc-search-wrap .rlc-search:focus-within {
    border-color: var(--rlc-border);
    box-shadow: none;
}

.rlc-search-wrap .rlc-search input,
.rlc-search-wrap .rlc-search input:focus,
.rlc-search-wrap .rlc-search input:focus-visible {
    outline: none !important;
    box-shadow: none !important;
    border: 0;
    background: transparent;
}

/* Subtle keyboard focus on the container */
.rlc-search-wrap .rlc-search:has(input:focus-visible) {
    border-color: var(--rlc-muted);
}
</style>
@endonce
