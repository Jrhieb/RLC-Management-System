<x-layouts::app :title="'Archived Beneficiaries'">
    @php
        $restoreIds = $beneficiaries->getCollection()
            ->filter(fn ($beneficiary) => \Illuminate\Support\Facades\Gate::allows('restore', $beneficiary))
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    @endphp
    <div class="rlc-dashboard rlc-registry" x-data="{
        mode: 'list', selecting: false, selected: [], pending: [], saving: false,
        pageIds: {{ \Illuminate\Support\Js::from($restoreIds) }},
        selectAll() { this.selecting = true; this.selected = [...this.pageIds]; },
        clearSelection() { this.selected = []; this.selecting = false; },
        confirmRestore(ids) { this.pending = [...ids]; this.$refs.restoreDialog.showModal(); }
    }">
        
        <header class="rlcr-header">
            <div>
                <p class="rlcr-eyebrow">Risen Lord Church Phils., Inc.</p>
                <h1>Archived Beneficiaries</h1>
                <p>Restore retained profiles to return them to the beneficiaries directory.</p>
            </div>
            <div class="rlcr-actions">
                <a href="{{ route('beneficiaries.index') }}" class="rlcr-button rlcr-button-secondary">Back to Beneficiaries</a>
            </div>
        </header>
        @if (session('success'))
            <div role="status" class="rlcr-success">{{ session('success') }}</div>
        @endif
        <section class="rlcr-directory" aria-labelledby="rlcr-directory-title">
            <div class="rlcr-directory-top">
                <div class="rlcr-directory-title">
                    <span class="rlcr-title-icon"><x-rlcp-icon name="users" /></span>
                    <div><h2 id="rlcr-directory-title">Archived Directory <span>{{ number_format($beneficiaries->total()) }}</span></h2>
                    <p>{{ $search !== '' ? 'Records matching your search' : 'Retained profiles · Restore records to return them to the main list' }}</p></div>
                </div>
                <div class="rlcr-view-switch" role="group" aria-label="Directory view">
                    <button type="button" x-on:click="mode = 'list'" :aria-pressed="(mode === 'list').toString()" :class="{ 'is-selected': mode === 'list' }" aria-label="List view" title="List view">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h1M3 12h1M3 18h1" /></svg>
                    </button>
                    <button type="button" x-on:click="mode = 'cards'" :aria-pressed="(mode === 'cards').toString()" :class="{ 'is-selected': mode === 'cards' }" aria-label="Card view" title="Card view">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                    </button>
                </div>
{{-- rlcp-bulk-restore-controls --}}
                @can('viewArchived', \App\Models\Beneficiary::class)
                    <details class="rlcr-more" x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false">
                        <summary aria-label="Selection options" title="Selection options"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></summary>
                        <div class="rlcr-menu">
                            <button type="button" x-on:click="selecting = true; $el.closest('details').open = false">Select records</button>
                            <button type="button" x-on:click="selectAll(); $el.closest('details').open = false">Select all on this page</button>
                            <button type="button" x-on:click="clearSelection(); $el.closest('details').open = false">Clear selection</button>
                        </div>
                    </details>
                @endcan

            </div>
            <div class="rlcr-filter-row">
                <form method="GET" action="{{ route('beneficiaries.archived') }}" class="rlcr-search-form"
                      x-data="{ focused: false, term: {{ \Illuminate\Support\Js::from($search) }} }">
                    <label for="rlcr-search" class="rlcr-sr-only">Search by name or beneficiary ID</label>
                    <div class="rlcr-search-field">
                        <span x-show="!focused &amp;&amp; !term.trim()" aria-hidden="true"><x-rlcp-icon name="search" /></span>
                        <input id="rlcr-search" type="search" name="search" value="{{ $search }}" maxlength="100"
                               placeholder="Search by name or beneficiary ID" autocomplete="off" spellcheck="false"
                               x-model="term" x-on:focus="focused = true" x-on:blur="focused = false">
                    </div>
                    <button type="submit" class="rlcr-button rlcr-button-primary">Search</button>
                    @if ($search !== '')
                        <a href="{{ route('beneficiaries.archived') }}" class="rlcr-button rlcr-button-secondary">Clear</a>
                    @endif
                </form>
                <span class="rlcr-sort">Recently archived</span>
            </div>
            @if ($search !== '')
                <p role="status" class="rlcr-search-summary">{{ number_format($beneficiaries->total()) }} {{ $beneficiaries->total() === 1 ? 'result' : 'results' }} for <strong>“{{ $search }}”</strong></p>
            @endif
@if ($errors->has('beneficiary_ids') || $errors->has('beneficiary_ids.*'))
                <div class="rlcr-bulk-error" role="alert">{{ $errors->first('beneficiary_ids') ?: $errors->first('beneficiary_ids.*') }}</div>
            @endif
            @can('viewArchived', \App\Models\Beneficiary::class)
                <div class="rlcr-selection" x-cloak x-show="selecting">
                    <label><input type="checkbox" :checked="pageIds.length > 0 &amp;&amp; selected.length === pageIds.length"
                        x-effect="$el.indeterminate = selected.length > 0 &amp;&amp; selected.length < pageIds.length"
                        x-on:change="selected = $event.target.checked ? [...pageIds] : []" :disabled="!pageIds.length"> Select all on this page</label>
                    <span x-text="selected.length + ' selected'"></span>
                    <button type="button" class="rlcr-button rlcr-button-secondary" :disabled="selected.length === 0"
                        x-on:click="confirmRestore(selected)">Restore Selected</button>
                    <button type="button" class="rlcr-button rlcr-button-secondary" x-on:click="clearSelection()">Cancel</button>
                </div>
            @endcan

            @if ($beneficiaries->count())
                <div class="rlcr-table-wrap" :class="{ 'rlcr-cards': mode === 'cards' }">
                    <table class="rlcr-table">
                        <caption class="rlcr-sr-only">Archived beneficiaries with ID, archive date, and restore actions.</caption>
                        <thead><tr><th scope="col">Beneficiary</th><th scope="col">Beneficiary ID</th><th scope="col">Status</th><th scope="col">Archived</th><th scope="col">Actions</th></tr></thead>
                        <tbody>
                        @foreach ($beneficiaries as $beneficiary)
                            <tr>
                                <td class="rlcr-person-cell">
                                    <div class="rlcr-person">
@can('restore', $beneficiary)
                                            <input class="rlcr-checkbox" type="checkbox" x-cloak x-show="selecting"
                                                value="{{ $beneficiary->id }}" x-model="selected"
                                                aria-label="{{ 'Select '.$beneficiary->full_name }}">
                                        @endcan

                                        <div class="rlcr-avatar" x-data="{ failed: false }">
                                            @if ($beneficiary->photo_path)
                                                <img src="{{ route('beneficiaries.archived-photo', $beneficiary->id) }}" alt="" loading="lazy" width="46" height="46" x-show="!failed" x-on:error="failed = true">
                                                <span x-cloak x-show="failed">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($beneficiary->full_name, 0, 1)) }}</span>
                                            @else
                                                <span>{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($beneficiary->full_name, 0, 1)) }}</span>
                                            @endif
                                        </div>
                                        <div class="rlcr-person-name">
                                            <strong>{{ $beneficiary->full_name }}</strong>
                                            <span>Archived profile</span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Beneficiary ID"><span class="rlcr-id">{{ $beneficiary->beneficiary_id }}</span></td>
                                <td data-label="Status"><span class="rlcr-status rlcr-status-neutral"><span aria-hidden="true"></span>Archived</span></td>
                                <td data-label="Archived" class="rlcr-date-cell">{{ $beneficiary->deleted_at?->copy()->timezone('Asia/Manila')->format('m/d/Y') ?? 'Not recorded' }}</td>
                                <td class="rlcr-row-actions">
                                    <details class="rlcr-more" x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false">
                                        <summary aria-label="{{ 'Actions for '.$beneficiary->full_name }}" title="Beneficiary actions"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></summary>
                                        <div class="rlcr-menu">
                                            @can('restore', $beneficiary)
                                                <button type="button" x-on:click="confirmRestore(['{{ $beneficiary->id }}']); $el.closest('details').open = false">Restore</button>
                                            @endcan
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <footer class="rlcr-pagination">
                    <p>Showing {{ $beneficiaries->firstItem() }}–{{ $beneficiaries->lastItem() }} of {{ number_format($beneficiaries->total()) }} {{ $search !== '' ? 'matching records' : 'archived profiles' }}</p>
                    <div>{{ $beneficiaries->links() }}</div>
                </footer>
            @else
                <div class="rlcr-empty">
                    <span class="rlcr-empty-icon"><x-rlcp-icon name="users" /></span>
                    <h3>No archived beneficiaries found</h3>
                    <p>{{ $search !== '' ? 'Try another name or beneficiary ID.' : 'Archived records will appear here.' }}</p>
                    @if ($search !== '')
                        <a href="{{ route('beneficiaries.archived') }}" class="rlcr-button rlcr-button-secondary">Clear search</a>
                    @endif
                </div>
            @endif
        </section>
@can('viewArchived', \App\Models\Beneficiary::class)
        <dialog x-ref="restoreDialog" class="rlcr-restore-dialog" aria-labelledby="rlcr-restore-title"
            x-on:cancel="if (saving) $event.preventDefault()">
            <h2 id="rlcr-restore-title">Restore selected beneficiaries?</h2>
            <p><strong x-text="pending.length"></strong> selected. These profiles will return to the beneficiaries directory. Their photos, attendance, and assistance history will remain attached.</p>
            <form method="POST" action="{{ route('beneficiaries.bulk-restore') }}"
                x-on:submit="if (saving) { $event.preventDefault(); } else { saving = true; }">
                @csrf
                <template x-for="id in pending" :key="id"><input type="hidden" name="beneficiary_ids[]" :value="id"></template>
                <div class="rlcr-actions">
                    <button type="button" class="rlcr-button rlcr-button-secondary" :disabled="saving" x-on:click="$refs.restoreDialog.close()">Cancel</button>
                    <button type="submit" class="rlcr-button rlcr-button-primary" :disabled="saving || !pending.length" :aria-busy="saving">
                        <span x-cloak x-show="saving" class="rlcr-saving-spinner" aria-hidden="true"></span>
                        <span x-text="saving ? 'Restoring...' : 'Restore'"></span>
                    </button>
                </div>
                <p role="status" x-cloak x-show="saving">Saving changes. Please wait...</p>
            </form>
        </dialog>
        @endcan

    </div>
    @once
    <style>
    .rlc-registry { animation:rlcr-enter .35s ease both; }
    .rlc-registry [x-cloak] { display:none !important; }
    .rlcr-header { display:flex; justify-content:space-between; align-items:flex-end; gap:24px; padding:30px 0 26px; }
    .rlcr-eyebrow { margin:0 0 9px; text-transform:uppercase; letter-spacing:.15em; color:var(--rlc-muted); font-size:10px; font-weight:600; }
    .rlcr-header h1 { margin:0; font-size:34px; font-weight:750; letter-spacing:-.04em; line-height:1.2; }
    .rlcr-header>div>p:last-child { margin:9px 0 0; color:var(--rlc-muted); font-size:13px; line-height:1.6; }
    .rlcr-actions { display:flex; align-items:center; gap:10px; flex-shrink:0; flex-wrap:wrap; }
    .rlcr-button { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; padding:10px 16px; border:1px solid transparent; border-radius:12px; font-size:12px; font-weight:650; white-space:nowrap; transition:background .15s,transform .15s; }
    .rlcr-button:hover { transform:translateY(-1px); }
    .rlcr-button .rlc-icon { width:18px; height:18px; }
    .rlcr-button-primary { background:var(--rlcp-primary, #7439f7); color:white; box-shadow:0 4px 12px var(--rlcp-focus-wash, #7439f720); }
    .rlcr-button-primary:hover { background:var(--rlcp-primary, #6329de); }
    .rlcr-button-secondary { background:var(--rlc-surface); border-color:var(--rlc-border); color:var(--rlc-ink); }
    .rlcr-button-secondary:hover { background:var(--rlc-tint); }
    .rlcr-directory { background:var(--rlc-surface); border:1px solid var(--rlc-border); border-radius:22px; overflow:hidden; box-shadow:0 8px 30px var(--rlcp-shadow-faint, #34307704); }
    .rlcr-directory-top { padding:23px 24px; display:flex; justify-content:space-between; align-items:center; gap:20px; }
    .rlcr-directory-title { display:flex; align-items:center; gap:12px; min-width:0; }
    .rlcr-title-icon { display:flex; align-items:center; justify-content:center; width:43px; height:43px; background:var(--rlc-tint); color:var(--rlc-violet); border-radius:13px; flex-shrink:0; }
    .rlcr-directory-title h2 { margin:0; font-size:16px; font-weight:700; line-height:1.5; }
    .rlcr-directory-title h2>span { display:inline-block; margin-left:6px; background:var(--rlc-tint); color:var(--rlc-violet); font-size:11px; padding:1px 8px; border-radius:7px; vertical-align:middle; }
    .rlcr-directory-title p { margin:4px 0 0; color:var(--rlc-muted); font-size:11px; line-height:1.6; }
    .rlcr-view-switch { display:flex; gap:3px; padding:4px; border:1px solid var(--rlc-border); border-radius:11px; flex-shrink:0; }
    .rlcr-view-switch button { padding:7px; color:var(--rlc-muted); border:0; border-radius:7px; }
    .rlcr-view-switch button.is-selected { color:var(--rlc-violet); background:var(--rlc-tint); }
    .rlcr-view-switch svg { width:18px; height:18px; }
    .rlcr-filter-row { display:flex; justify-content:space-between; align-items:center; gap:20px; padding:0 24px 20px; }
    .rlcr-search-form { display:flex; align-items:center; gap:8px; width:620px; max-width:100%; }
    .rlcr-search-field { display:flex; align-items:center; gap:9px; flex:1; min-width:0; border:1px solid var(--rlc-border); background:var(--rlc-soft); border-radius:12px; padding:10px 13px; min-height:42px; }
    .rlcr-search-field .rlc-icon { width:18px; height:18px; color:var(--rlc-muted); }
    .rlcr-search-field input { width:100%; min-width:0; border:0; padding:0; font-size:12px; background:transparent; color:var(--rlc-ink); outline:none !important; box-shadow:none !important; }
    .rlcr-search-field:has(input:focus-visible) { border-color:var(--rlc-muted); }
    .rlcr-search-field input::placeholder { color:var(--rlc-muted); }
    .rlcr-sort { font-size:11px; color:var(--rlc-muted); white-space:nowrap; }
    .rlcr-search-summary { margin:0; padding:0 24px 18px; font-size:12px; color:var(--rlc-muted); overflow-wrap:anywhere; }
    .rlcr-search-summary strong { color:var(--rlc-ink); }
    .rlcr-table { width:100%; border-collapse:collapse; text-align:left; }
    .rlcr-table thead { background:var(--rlc-soft); }
    .rlcr-table th { padding:13px 24px; font-size:10px; font-weight:650; color:var(--rlc-muted); border-top:1px solid var(--rlc-border); border-bottom:1px solid var(--rlc-border); white-space:nowrap; }
    .rlcr-table td { padding:18px 24px; border-bottom:1px solid var(--rlc-border); font-size:12px; }
    .rlcr-table tbody tr { transition:background .15s; }
    .rlcr-table tbody tr:hover { background:var(--rlc-soft); }
    .rlcr-table tbody tr:last-child td { border-bottom:0; }
    .rlcr-person { display:flex; gap:12px; align-items:center; }
    .rlcr-avatar { display:flex; align-items:center; justify-content:center; flex-shrink:0; width:46px; height:46px; border-radius:50%; background:var(--rlc-tint); color:var(--rlc-violet); font-size:18px; font-weight:700; overflow:hidden; }
    .rlcr-avatar img { width:46px; height:46px; object-fit:cover; }
    .rlcr-person-name { min-width:0; }
    .rlcr-person-name>a,.rlcr-person-name>strong { font-weight:650; color:var(--rlc-ink); font-size:13px; overflow-wrap:anywhere; }
    .rlcr-person-name>a:hover { color:var(--rlc-violet); }
    .rlcr-person-name>span { display:block; font-size:10px; color:var(--rlc-muted); margin-top:4px; }
    .rlcr-id { font-variant-numeric:tabular-nums; overflow-wrap:anywhere; }
    .rlcr-status { display:inline-flex; align-items:center; gap:6px; border-radius:20px; padding:5px 10px; font-size:10px; font-weight:650; }
    .rlcr-status>span { width:5px; height:5px; border-radius:50%; background:currentColor; }
    .rlcr-status-active { background:#e0f7eb; color:#147b4d; }
    .dark .rlcr-status-active { background:#19392d; color:#77dfa7; }
    .rlcr-status-neutral { background:var(--rlc-soft); color:var(--rlc-muted); border:1px solid var(--rlc-border); }
    .rlcr-date-cell { color:var(--rlc-muted); white-space:nowrap; }
    .rlcr-profile-link { display:inline-flex; align-items:center; gap:5px; color:var(--rlc-violet); font-weight:650; font-size:11px; white-space:nowrap; }
    .rlcr-profile-link .rlc-icon { width:14px; height:14px; }
    .rlcr-edit-link { display:inline-block; font-size:11px; margin-left:13px; color:var(--rlc-muted); }
    .rlcr-edit-link:hover { color:var(--rlc-violet); }
    .rlcr-pagination { padding:20px 24px; border-top:1px solid var(--rlc-border); }
    .rlcr-pagination>p { margin:0 0 13px; font-size:11px; color:var(--rlc-muted); }
    .rlcr-empty { padding:45px 20px 50px; text-align:center; border-top:1px solid var(--rlc-border); }
    .rlcr-empty-icon { display:inline-flex; align-items:center; justify-content:center; width:64px; height:64px; background:var(--rlc-tint); color:var(--rlc-violet); border-radius:19px; }
    .rlcr-empty-icon .rlc-icon { width:30px; height:30px; }
    .rlcr-empty h3 { font-size:17px; font-weight:700; margin:18px 0 7px; }
    .rlcr-empty p { margin:0 0 20px; color:var(--rlc-muted); font-size:12px; }
    .rlcr-success { margin-bottom:18px; border:1px solid #b3e4c9; background:#e0f7eb; color:#147b4d; padding:13px 17px; border-radius:12px; font-size:12px; }
    .dark .rlcr-success { background:#19392d; color:#77dfa7; border-color:#295b44; }
    .rlcr-sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
    .rlcr-cards .rlcr-table,.rlcr-cards .rlcr-table tbody { display:block; }
    .rlcr-cards .rlcr-table thead { display:none; }
    .rlcr-cards .rlcr-table tbody { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; padding:0 24px 24px; }
    .rlcr-cards .rlcr-table tr { display:block; border:1px solid var(--rlc-border); border-radius:16px; padding:18px; }
    .rlcr-cards .rlcr-table td { display:block; padding:7px 0; border:0; }
    .rlcr-cards .rlcr-person-cell { padding:0 0 15px !important; }
    .rlcr-cards td[data-label] { display:flex; justify-content:space-between; align-items:center; gap:12px; }
    .rlcr-cards td[data-label]::before { content:attr(data-label); font-size:10px; color:var(--rlc-muted); }
    .rlcr-cards .rlcr-row-actions { border-top:1px solid var(--rlc-border); margin-top:10px; padding-top:14px; }
    @keyframes rlcr-enter { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
    @media(max-width:1199px) { .rlcr-header { align-items:flex-start; flex-direction:column; gap:18px; }.rlcr-table th,.rlcr-table td { padding-left:16px; padding-right:16px; }.rlcr-cards .rlcr-table tbody { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media(max-width:767px) {
        .rlcr-header { padding-top:24px; }.rlcr-header h1 { font-size:29px; }.rlcr-header>div>p:last-child { font-size:12px; }
        .rlcr-directory-top { padding:18px 16px; gap:10px; }.rlcr-directory-title h2 { font-size:14px; }.rlcr-title-icon { display:none; }
        .rlcr-filter-row { padding:0 16px 18px; flex-wrap:wrap; gap:9px; }.rlcr-search-form { width:100%; flex-wrap:wrap; }.rlcr-search-field { flex-basis:100%; }.rlcr-sort { margin-left:auto; }
        .rlcr-search-summary { padding:0 16px 16px; }.rlcr-directory { border-radius:18px; }
        .rlcr-table,.rlcr-table tbody { display:block; }.rlcr-table thead { display:none; }.rlcr-table tbody,.rlcr-cards .rlcr-table tbody { display:grid; grid-template-columns:1fr; gap:12px; padding:0 16px 16px; }
        .rlcr-table tr,.rlcr-cards .rlcr-table tr { display:block; border:1px solid var(--rlc-border); border-radius:14px; padding:16px; }
        .rlcr-table td { display:block; border:0; padding:7px 0; }.rlcr-table .rlcr-person-cell { padding:0 0 13px; }
        .rlcr-table td[data-label] { display:flex; align-items:center; justify-content:space-between; gap:12px; }.rlcr-table td[data-label]::before { content:attr(data-label); color:var(--rlc-muted); font-size:10px; }
        .rlcr-table .rlcr-row-actions { border-top:1px solid var(--rlc-border); margin-top:10px; padding-top:14px; }.rlcr-pagination { padding:17px 16px; }
        .rlcr-view-switch { display:none; }.rlcr-actions { width:100%; }.rlcr-actions .rlcr-button { flex:1; }
    }
    @media(prefers-reduced-motion:reduce) { .rlc-registry { animation:none; }.rlcr-button,.rlcr-table tbody tr { transition:none; }.rlcr-button:hover { transform:none; } }

    /* rlcp-bulk-restore-style */
    .rlcr-directory { overflow:visible; }
    .rlcr-directory-top { justify-content:flex-start; }
    .rlcr-view-switch { margin-left:auto; }
    .rlcr-more { position:relative; display:inline-block; }
    .rlcr-more summary { display:flex; align-items:center; justify-content:center; width:38px; height:38px; border:1px solid var(--rlc-border); border-radius:10px; cursor:pointer; list-style:none; font-size:26px; line-height:1; }
    .rlcr-more summary::-webkit-details-marker { display:none; }
    .rlcr-more[open] { z-index:20; }
    .rlcr-menu { position:absolute; right:0; top:calc(100% + 5px); width:200px; padding:6px; background:#fff; border:1px solid var(--rlc-border); border-radius:12px; box-shadow:0 8px 24px #0002; }
    .dark .rlcr-menu { background:var(--rlcp-surface, #191a23); }
    .rlcr-menu>a,.rlcr-menu>button { display:block; width:100%; text-align:left; padding:10px; border-radius:7px; font-size:12px; }
    .rlcr-menu>a:hover,.rlcr-menu>button:hover { background:var(--rlc-tint); }
    .rlcr-selection { display:flex; flex-wrap:wrap; align-items:center; gap:12px; padding:14px 24px; border-top:1px solid var(--rlc-border); background:var(--rlc-soft); font-size:12px; }
    .rlcr-selection label { display:flex; align-items:center; gap:8px; }
    .rlcr-checkbox,.rlcr-selection input { width:17px; height:17px; accent-color:var(--rlcp-primary, #7439f7); flex-shrink:0; }
    .rlcr-button:disabled { opacity:.5; cursor:wait; transform:none; }
    .rlcr-bulk-error { margin:0 24px 16px; color:#b91c1c; font-size:13px; }
    .rlcr-restore-dialog { margin:auto; max-width:480px; width:calc(100% - 32px); padding:26px; border-radius:20px; background:var(--rlc-surface); color:var(--rlc-ink); border:1px solid var(--rlc-border); }
    .rlcr-restore-dialog::backdrop { background:#0007; }
    .rlcr-restore-dialog h2 { font-size:20px; font-weight:700; }
    .rlcr-restore-dialog p { font-size:13px; line-height:1.7; margin:15px 0; }
    .rlcr-saving-spinner { width:16px; height:16px; border:2px solid #fff6; border-top-color:white; border-radius:50%; animation:rlcr-spin .7s linear infinite; }
    @keyframes rlcr-spin { to { transform:rotate(360deg); } }
    @media(prefers-reduced-motion:reduce) { .rlcr-saving-spinner { animation:none; } }


    /* rlcp-kebab-spacing */
    .rlcr-directory-top { gap:10px; }
    .rlcr-more summary { width:40px; height:40px; padding:0; color:var(--rlc-muted); }
    .rlcr-more summary:hover,.rlcr-more[open] summary { background:var(--rlc-tint); color:var(--rlc-violet); }
    .rlcr-more summary:focus-visible { outline:2px solid var(--rlc-violet); outline-offset:3px; }
    .rlcr-row-actions .rlcr-more summary { width:34px; height:34px; }
    .rlcr-menu { top:calc(100% + 8px); }
    @media(min-width:768px) {
        .rlcr-table th,.rlcr-table td { padding-left:20px; padding-right:20px; }
        .rlcr-table td { padding-top:14px; padding-bottom:14px; }
        .rlcr-table th:last-child,.rlcr-table .rlcr-row-actions { width:90px; text-align:center; }
        .rlcr-cards .rlcr-table .rlcr-row-actions { width:auto; text-align:right; }
    }
    @media(max-width:767px) {
        .rlcr-directory-top>.rlcr-more { margin-left:auto; }
        .rlcr-table .rlcr-row-actions { text-align:right; }
    }
    /* Keep card actions beside the profile header without a menu footer. */
    .rlc-registry .rlcr-cards .rlcr-table tr { position:relative; min-width:0; overflow:visible; }
    .rlc-registry .rlcr-cards .rlcr-table tr:has(.rlcr-row-actions .rlcr-more[open]) { z-index:21; }
    .rlc-registry .rlcr-cards .rlcr-table .rlcr-person-cell { padding-right:46px !important; }
    .rlc-registry .rlcr-cards .rlcr-person-name { flex:1; }
    .rlc-registry .rlcr-cards .rlcr-table .rlcr-row-actions { position:absolute; top:18px; right:18px; left:18px; display:flex; justify-content:flex-end; width:auto; margin:0; padding:0; border:0; pointer-events:none; }
    .rlc-registry .rlcr-cards .rlcr-row-actions .rlcr-more { position:static; pointer-events:auto; }
    .rlc-registry .rlcr-cards .rlcr-row-actions .rlcr-menu { max-width:100%; text-align:left; overflow-wrap:anywhere; }
    @media(max-width:767px) {
        .rlc-registry .rlcr-table tr { position:relative; min-width:0; overflow:visible; }
        .rlc-registry .rlcr-table tr:has(.rlcr-row-actions .rlcr-more[open]) { z-index:21; }
        .rlc-registry .rlcr-table .rlcr-person-cell { padding-right:46px !important; }
        .rlc-registry .rlcr-person-name { flex:1; }
        .rlc-registry .rlcr-table .rlcr-row-actions,
        .rlc-registry .rlcr-cards .rlcr-table .rlcr-row-actions { position:absolute; top:16px; right:16px; left:16px; display:flex; justify-content:flex-end; width:auto; margin:0; padding:0; border:0; pointer-events:none; }
        .rlc-registry .rlcr-row-actions .rlcr-more { position:static; pointer-events:auto; }
        .rlc-registry .rlcr-row-actions .rlcr-menu { max-width:100%; text-align:left; overflow-wrap:anywhere; }
    }
    </style>
    @endonce
</x-layouts::app>
