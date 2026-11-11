@php
    $isMyCaseload = $isMyCaseload ?? false;
    $registryRoute = $isMyCaseload ? 'my-caseload.index' : 'beneficiaries.index';
@endphp
<x-layouts::app :title="$isMyCaseload ? 'My Caseload' : 'Beneficiary Registry'">
    @php
        $pageBeneficiaries = $beneficiaries?->getCollection() ?? collect();
        $canArchive = \Illuminate\Support\Facades\Gate::allows('viewArchived', \App\Models\Beneficiary::class);
        $canAssign = \Illuminate\Support\Facades\Gate::allows('assignCaseworker', \App\Models\Beneficiary::class);
        $archiveIds = ($canArchive ? $pageBeneficiaries : collect())
            ->filter(fn ($beneficiary) => \Illuminate\Support\Facades\Gate::allows('delete', $beneficiary))
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $assignmentRecords = ($canAssign ? $pageBeneficiaries : collect())
            ->filter(fn ($beneficiary) => \Illuminate\Support\Facades\Gate::allows('assignCaseworker', $beneficiary))
            ->mapWithKeys(fn ($beneficiary) => [(string) $beneficiary->id => [
                'name' => $beneficiary->full_name,
                'beneficiary_id' => $beneficiary->beneficiary_id,
                'caseworker_name' => $beneficiary->caseworker?->name ?? 'Unassigned',
                'caseworker_id' => $beneficiary->caseworker_id,
            ]])->all();
        $selectableIds = $pageBeneficiaries->filter(fn ($beneficiary) => in_array((string) $beneficiary->id, $archiveIds, true)
            || array_key_exists($beneficiary->id, $assignmentRecords))
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $selectionRecords = $pageBeneficiaries->filter(fn ($beneficiary) => in_array((string) $beneficiary->id, $selectableIds, true))
            ->mapWithKeys(fn ($beneficiary) => [(string) $beneficiary->id => [
                'name' => $beneficiary->full_name,
                'beneficiary_id' => $beneficiary->beneficiary_id,
                'caseworker_name' => $beneficiary->caseworker?->name ?? 'Unassigned',
                'caseworker_id' => $beneficiary->caseworker_id,
                'canArchive' => in_array((string) $beneficiary->id, $archiveIds, true),
                'canAssign' => array_key_exists($beneficiary->id, $assignmentRecords),
            ]])->all();
        $canSelect = $canArchive || $canAssign;
        $createParameters = $tab === 'beneficiaries' && $caseworkers->contains(fn ($account) => (string) $account->id === $caseworkerFilter)
            ? ['caseworker_id' => $caseworkerFilter] : [];
        $createUrl = \Illuminate\Support\Facades\Gate::allows('create', \App\Models\Beneficiary::class)
            ? route('beneficiaries.create', $createParameters) : null;
    @endphp
    <div class="rlc-dashboard rlc-registry rlc-registry-index" data-registry-selection x-data="{
        mode: 'list', selecting: false, selected: [], pending: [], saving: false, refreshing: false,
        records: {{ \Illuminate\Support\Js::from($selectionRecords) }}, selectionLimit: 100, selectionNotice: '',
        pageIds: {{ \Illuminate\Support\Js::from($archiveIds) }},
        assignmentPageIds: {{ \Illuminate\Support\Js::from(array_map('strval', array_keys($assignmentRecords))) }},
        selectablePageIds: {{ \Illuminate\Support\Js::from($selectableIds) }},
        get allSelected() { return this.selectablePageIds.length > 0 && this.selectablePageIds.every(id => this.selected.includes(id)); },
        get partlySelected() { return this.selectablePageIds.some(id => this.selected.includes(id)) && !this.allSelected; },
        get canArchiveSelection() { return !this.refreshing && this.selected.length > 0 && this.selected.every(id => this.records[id]?.canArchive); },
        get canAssignSelection() { return !this.refreshing && this.selected.length > 0 && this.selected.every(id => this.records[id]?.canAssign); },
        selectionChanged() { this.$dispatch('registry-selection-changed'); },
        updatePage(page) {
            const retained = Object.fromEntries(this.selected.filter(id => this.records[id]).map(id => [id, this.records[id]]));
            for (const id of this.selected) {
                if (page.visibleIds?.includes(id) && retained[id] && !page.selectionRecords[id])
                    retained[id] = { ...retained[id], canArchive: false, canAssign: false };
            }
            this.records = { ...retained, ...page.selectionRecords };
            this.pageIds = page.archiveIds;
            this.assignmentPageIds = Object.keys(page.assignmentRecords);
            this.selectablePageIds = page.selectableIds;
            this.selectionChanged();
        },
        startSelection() {
            if (this.refreshing || !this.selectablePageIds.length) return;
            this.selecting = true;
            this.selectionChanged();
            this.$nextTick(() => requestAnimationFrame(() => {
                Array.from(this.$el.closest('[data-registry-selection]').querySelectorAll('[data-select-all]'))
                    .find(input => input.getClientRects().length)?.focus();
            }));
        },
        toggleSelection(id, checked) {
            if (this.refreshing || !this.selecting || !this.selectablePageIds.includes(id)) return;
            if (!checked) { this.removeSelected(id); return; }
            if (this.selected.includes(id)) return;
            if (this.selected.length >= this.selectionLimit) { this.selectionNotice = 'Select up to 100 records per bulk action.'; return; }
            this.selected = [...this.selected, id];
            this.selectionNotice = '';
            this.selectionChanged();
        },
        removeSelected(id) {
            this.selected = this.selected.filter(selectedId => selectedId !== id);
            this.selectionNotice = '';
            this.selectionChanged();
        },
        selectAll(checked = true) {
            if (this.refreshing) return;
            this.selecting = true;
            this.selectionNotice = '';
            if (checked) {
                const combined = [...new Set([...this.selected, ...this.selectablePageIds])];
                this.selected = combined.slice(0, this.selectionLimit);
                if (combined.length > this.selectionLimit) this.selectionNotice = 'Select up to 100 records per bulk action.';
            } else this.selected = this.selected.filter(id => !this.selectablePageIds.includes(id));
            this.selectionChanged();
        },
        clearSelection() { this.selected = []; this.pending = []; this.selectionNotice = ''; },
        cancelSelection(restoreFocus = true) {
            this.clearSelection(); this.selecting = false;
            this.selectionChanged();
            if (restoreFocus) this.$nextTick(() => this.$el.closest('[data-registry-selection]').querySelector('[aria-controls=rlcr-selection-menu]')?.focus());
        },
        confirmArchive(ids) { this.pending = [...ids]; this.$refs.archiveDialog.showModal(); }
    }"
         data-registry-selection-key="rlcp.registry.selection.v1.{{ auth()->id() }}"
         data-registry-selection-complete="{{ session('registry_selection_complete') ? 'true' : 'false' }}"
         x-init="$dispatch('registry-selection-ready')"
         x-on:pageshow.window="saving = false; $refs.archiveDialog?.close()">
        <header class="rlcr-header">
            <div>
                <h1>{{ $isMyCaseload ? 'My Caseload' : 'Beneficiary Registry' }}</h1>
                <p>{{ $isMyCaseload ? 'Your assigned beneficiaries, excluding archived profiles.' : 'Manage beneficiary profiles and records.' }}</p>
            </div>
            <div class="rlcr-header-actions">
                @can('createCaseworker', \App\Models\User::class)
                    @include('beneficiaries.partials.add-caseworker-dialog')
                @endcan
                @can('create', \App\Models\Beneficiary::class)
                    <a data-registry-create href="{{ route('beneficiaries.create', $createParameters) }}" class="rlcr-button rlcr-button-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 4v16M4 12h16" /></svg>
                        Add Beneficiary
                    </a>
                @endcan
            </div>
        </header>

        @if (session('success'))
            <div role="status" class="rlcr-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div role="alert" class="rlcr-bulk-error">{{ session('error') }}</div>
        @endif

        <section aria-labelledby="rlcr-directory-title">
            <h2 id="rlcr-directory-title" class="rlcr-sr-only">{{ $isMyCaseload ? 'My Caseload' : ($tab === 'caseloads' ? 'Caseworker Caseloads' : 'All Beneficiaries') }}</h2>
            <div class="rlcr-tabs">
                <nav aria-label="Registry tabs" class="rlcr-tab-links">
                    @if ($isMyCaseload)
                        <a data-registry-tab href="{{ route($registryRoute, $registryFilters) }}" aria-current="page">My Caseload</a>
                        <a href="{{ route('beneficiaries.index') }}">All Beneficiaries</a>
                    @else
                    <a data-registry-tab href="{{ route('beneficiaries.index', $registryFilters) }}" @if ($tab === 'beneficiaries') aria-current="page" @endif>All Beneficiaries</a>
                    <a href="{{ route('beneficiaries.index', ['tab' => 'caseloads']) }}" @if ($tab === 'caseloads') aria-current="page" @endif>Caseworker Caseloads</a>
                    @endif
                </nav>
                <span class="rlcr-sort">Name: A&ndash;Z</span>
            </div>
            <div class="rlcr-directory">
                @if ($tab === 'caseloads')
                    @include('beneficiaries.partials.caseworker-caseloads')
                @else
                @fragment('registry-caseload')
                <div data-registry-caseload-heading>
                @if ($caseloadName !== null)
                    <div class="rlcr-caseload-heading" data-selected-caseload>
                        <div>
                            <p>Selected caseload</p>
                            <h3>{{ $caseloadName }}</h3>
                        </div>
                        <a data-registry-navigation href="{{ route('beneficiaries.index') }}" class="rlcr-button rlcr-button-secondary">Back to All Beneficiaries</a>
                    </div>
                @endif
                </div>
                @endfragment
                <div class="rlcr-filter-row">
                    <form method="GET" action="{{ route($registryRoute) }}" class="rlcr-search-form">
                        <x-registry-search :value="$search" />
                        @if (! $isMyCaseload && $canAssign)
                        <div class="rlcr-choice-filter rlcr-caseworker-filter">
                            <label for="rlcr-caseworker">Caseworker:</label>
                            <select id="rlcr-caseworker" name="caseworker">
                                <option value="" @selected($caseworkerFilter === '')>All Caseworkers</option>
                                <option value="unassigned" @selected($caseworkerFilter === 'unassigned')>Unassigned</option>
                                @foreach ($registryCaseworkers as $account)
                                    <option value="{{ $account->id }}" @selected($caseworkerFilter === (string) $account->id)>{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                        <div class="rlcr-choice-filter rlcr-status-filter">
                            <label for="rlcr-status">Status:</label>
                            <select id="rlcr-status" name="status">
                                <option value="" @selected($status === '')>All statuses</option>
                                <option value="active" @selected($status === 'active')>Active</option>
                                <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                            </select>
                        </div>
                    </form>
                    <div class="rlcr-tools">
                        <div class="rlcr-view-switch" role="group" aria-label="Registry view">
                            <button type="button" x-on:click="mode = 'list'" :aria-pressed="(mode === 'list').toString()"
                                    :class="{ 'is-selected': mode === 'list' }" aria-label="List view" title="List view">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h1M3 12h1M3 18h1" /></svg>
                            </button>
                            <button type="button" x-on:click="mode = 'cards'" :aria-pressed="(mode === 'cards').toString()"
                                    :class="{ 'is-selected': mode === 'cards' }" aria-label="Grid view" title="Grid view">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>
                            </button>
                        </div>
                        @can('viewArchived', \App\Models\Beneficiary::class)
                            <a href="{{ route('beneficiaries.archived') }}" class="rlcr-archived-link">View Archived</a>
                        @endcan
                        @if ($canSelect)
                            <x-registry-menu label="Selection options" id="rlcr-selection-menu">
                                <button type="button" data-start-selection x-show="!selecting" :disabled="refreshing || !selectablePageIds.length"
                                        aria-controls="rlcr-selection-controls" x-on:click="startSelection(); $el.closest('details').open = false">Select records</button>
                                <button type="button" x-cloak x-show="selecting" :disabled="refreshing || !selectablePageIds.length"
                                        x-on:click="selectAll(); $el.closest('details').open = false">Select all on this page</button>
                                <button type="button" x-cloak x-show="selecting"
                                        x-on:click="cancelSelection(); $el.closest('details').open = false">Cancel selection</button>
                            </x-registry-menu>
                        @endif
                    </div>
                </div>

                <div class="rlcr-live-feedback" aria-live="polite" aria-atomic="true">
                    <span data-registry-loading hidden><span class="rlcr-search-spinner" aria-hidden="true"></span> Updating records&hellip;</span>
                    <span data-registry-error hidden>Unable to update records. Showing previous results. <button type="button" data-registry-retry>Retry</button></span>
                </div>

                @if ($canSelect)
                    <div id="rlcr-selection-controls" class="rlcr-selection" role="group" aria-label="Selection controls" x-cloak x-show="selecting">
                        <label class="rlcr-select-page" x-cloak x-show="mode === 'cards'">
                            <input type="checkbox" data-select-all class="rlcr-checkbox" aria-label="Select all on this page"
                                   :checked="allSelected" x-effect="$el.indeterminate = partlySelected"
                                   x-on:change="selectAll($event.target.checked)" :disabled="refreshing || !selectablePageIds.length">
                            Select all on this page
                        </label>
                        <span role="status" aria-live="polite" aria-atomic="true" x-text="selected.length + ' selected'"></span>
                        @can('assignCaseworker', \App\Models\Beneficiary::class)
                            <button type="button" data-assignment-bulk class="rlcr-button rlcr-button-secondary" x-cloak x-show="canAssignSelection"
                                    aria-haspopup="dialog" aria-controls="rlcr-assignment-dialog"
                                    :disabled="!canAssignSelection"
                                    x-on:click="$dispatch('open-caseworker-assignment', { ids: [...selected], opener: $el })">Assign / Reassign Caseworker</button>
                        @endcan
                        @can('viewArchived', \App\Models\Beneficiary::class)
                            <button type="button" data-archive-bulk class="rlcr-button rlcr-button-secondary" x-cloak x-show="canArchiveSelection"
                                    :disabled="!canArchiveSelection" x-on:click="confirmArchive(selected)">Archive Selected</button>
                        @endcan
                        <span x-cloak x-show="!refreshing && selected.length && !canArchiveSelection && !canAssignSelection">No bulk action is permitted for this selection.</span>
                        <button type="button" data-cancel-selection class="rlcr-clear-selection" x-on:click="cancelSelection()">Cancel selection</button>
                        <p class="rlcr-selection-notice" role="status" x-cloak x-show="selectionNotice" x-text="selectionNotice"></p>
                        <ul class="rlcr-selected-records" aria-label="Selected beneficiaries" x-cloak x-show="selected.length">
                            <template x-for="id in selected" :key="id">
                                <li class="rlcr-selected-record" :data-selected-id="id">
                                    <span><strong x-text="records[id]?.name"></strong><span class="rlcr-id" x-text="records[id]?.beneficiary_id"></span></span>
                                    <button type="button" data-remove-selected :aria-label="'Remove ' + records[id]?.name + ' (' + records[id]?.beneficiary_id + ') from selection'"
                                            x-on:click="removeSelected(id)">Remove <span aria-hidden="true">&times;</span></button>
                                </li>
                            </template>
                        </ul>
                    </div>
                @endif

                @fragment('registry-results')
                <div id="rlcr-results" class="rlcr-results" data-registry-results data-registry-page="{{ json_encode([
                    'archiveIds' => $archiveIds, 'assignmentRecords' => $assignmentRecords, 'selectableIds' => $selectableIds,
                    'visibleIds' => $pageBeneficiaries->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                    'selectionRecords' => $selectionRecords, 'createUrl' => $createUrl,
                ]) }}">
                @if ($search !== '' || $status !== '' || $caseworkerFilter !== '')
                    <p role="status" class="rlcr-search-summary">
                        {{ number_format($beneficiaries->total()) }} {{ $beneficiaries->total() === 1 ? 'result' : 'results' }}
                        @if ($search !== '') for <strong>&ldquo;{{ $search }}&rdquo;</strong> @endif
                        @if ($status !== '') &middot; {{ ucfirst($status) }} @endif
                        @if ($caseloadName !== null) &middot; {{ $caseloadName }} @endif
                        <a data-registry-navigation href="{{ route($registryRoute) }}">Clear filters</a>
                    </p>
                @endif

                @if ($errors->has('beneficiary_ids') || $errors->has('beneficiary_ids.*'))
                    <div class="rlcr-bulk-error" role="alert">{{ $errors->first('beneficiary_ids') ?: $errors->first('beneficiary_ids.*') }}</div>
                @endif

                @if ($beneficiaries->count())
                    <div class="rlcr-table-wrap" :class="{ 'rlcr-cards': mode === 'cards', 'is-selecting': selecting }" tabindex="0" role="region" aria-label="Beneficiary results"
                         x-on:scroll="$el.querySelectorAll('[popover]').forEach(menu => menu.hidePopover())">
                        <table class="rlcr-table">
                            <caption class="rlcr-sr-only">Beneficiaries sorted A to Z by full name, then record ID.</caption>
                            <thead>
                                <tr>
                                    @if ($canSelect)
                                        <th scope="col" class="rlcr-selection-cell" x-cloak x-show="selecting">
                                            <input type="checkbox" data-select-all class="rlcr-checkbox" aria-label="Select all on this page"
                                                   :checked="allSelected" x-effect="$el.indeterminate = partlySelected"
                                                   x-on:change="selectAll($event.target.checked)" :disabled="refreshing || !selectablePageIds.length">
                                        </th>
                                    @endif
                                    <th scope="col">Beneficiary</th>
                                    <th scope="col">Beneficiary ID</th>
                                    <th scope="col">Caseworker</th>
                                    <th scope="col">Registered date</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="rlcr-actions-heading">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($beneficiaries as $beneficiary)
                                    <tr :class="{ 'is-selected': selected.includes('{{ $beneficiary->id }}') }">
                                        @if ($canSelect)
                                        <td class="rlcr-selection-cell" x-cloak x-show="selecting">
                                            @if (in_array((string) $beneficiary->id, $selectableIds, true))
                                                <input class="rlcr-checkbox" type="checkbox" value="{{ $beneficiary->id }}" :checked="selected.includes('{{ $beneficiary->id }}')"
                                                       x-on:change="toggleSelection('{{ $beneficiary->id }}', $event.target.checked); $event.target.checked = selected.includes('{{ $beneficiary->id }}')"
                                                       :disabled="!selecting || refreshing" aria-label="{{ 'Select '.$beneficiary->full_name }}">
                                            @endif
                                        </td>
                                        @endif
                                        <td class="rlcr-person-cell">
                                            <x-registry-beneficiary-person :beneficiary="$beneficiary" />
                                        </td>
                                        <td data-label="Beneficiary ID"><span class="rlcr-id">{{ $beneficiary->beneficiary_id }}</span></td>
                                        <td data-label="Caseworker" class="rlcr-caseworker-cell">
                                            @if ($beneficiary->caseworker)
                                                <div class="rlcr-person">
                                                    <span class="rlcr-avatar rlcr-caseworker-avatar" aria-hidden="true">{{ $beneficiary->caseworker->initials() }}</span>
                                                    <span class="rlcr-caseworker-name">{{ $beneficiary->caseworker->name }}</span>
                                                </div>
                                            @else
                                                <span class="rlcr-unassigned">Unassigned</span>
                                            @endif
                                        </td>
                                        <td data-label="Registered date" class="rlcr-date-cell">{{ $beneficiary->registered_at?->format('m/d/Y') ?? 'Not recorded' }}</td>
                                        <td data-label="Status"><x-registry-beneficiary-status :status="$beneficiary->status" /></td>
                                        <td class="rlcr-row-actions">
                                            <x-registry-menu :label="'Actions for '.$beneficiary->full_name" :id="'rlcr-actions-'.$beneficiary->id">
                                                @can('view', $beneficiary)
                                                    <a href="{{ route('beneficiaries.show', $beneficiary) }}">View Profile</a>
                                                @endcan
                                                @can('update', $beneficiary)
                                                    <a href="{{ route('beneficiaries.edit', $beneficiary) }}">Edit Profile</a>
                                                @endcan
                                                @can('assignCaseworker', $beneficiary)
                                                    <button type="button" data-assignment-row aria-haspopup="dialog" aria-controls="rlcr-assignment-dialog"
                                                            x-on:click="$el.closest('details').open = false; $dispatch('open-caseworker-assignment', { ids: ['{{ $beneficiary->id }}'], opener: $el.closest('details').querySelector('summary') })">Assign / Reassign Caseworker</button>
                                                @endcan
                                                @can('delete', $beneficiary)
                                                    <button type="button" x-on:click="confirmArchive(['{{ $beneficiary->id }}']); $el.closest('details').open = false">Archive</button>
                                                @endcan
                                            </x-registry-menu>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <footer class="rlcr-pagination">
                        <p>Showing {{ $beneficiaries->firstItem() }}&ndash;{{ $beneficiaries->lastItem() }} of {{ number_format($beneficiaries->total()) }} beneficiaries</p>
                        {{ $beneficiaries->onEachSide(1)->links('beneficiaries.partials.registry-pagination') }}
                    </footer>
                @else
                    <div class="rlcr-empty">
                        <span class="rlcr-empty-icon"><x-rlcp-icon name="users" /></span>
                        <h3>{{ $isMyCaseload && $search === '' && $status === '' && $beneficiaries->total() === 0 ? 'No assigned beneficiaries yet.' : 'No beneficiaries found' }}</h3>
                        @if ($beneficiaries->total() > 0)
                            <p>There are no beneficiaries on this page.</p>
                            <a data-registry-navigation href="{{ route($registryRoute, $registryFilters) }}" class="rlcr-button rlcr-button-secondary">Return to first page</a>
                        @elseif ($search !== '' || $status !== '')
                            <p>{{ $isMyCaseload ? 'Try another name, beneficiary ID, or status.' : 'Try another name, beneficiary ID, caseworker, or status.' }}</p>
                            <a data-registry-navigation href="{{ route($registryRoute) }}" class="rlcr-button rlcr-button-secondary">Clear filters</a>
                        @elseif ($isMyCaseload)
                            <p>A Director can assign beneficiaries to your caseload.</p>
                        @elseif ($caseloadName !== null)
                            <p>{{ $caseworkerFilter === 'unassigned' ? 'There are no unassigned beneficiaries.' : 'No non-archived beneficiaries are assigned to '.$caseloadName.'.' }}</p>
                            <a data-registry-navigation href="{{ route('beneficiaries.index') }}" class="rlcr-button rlcr-button-secondary">Back to All Beneficiaries</a>
                        @else
                            @can('create', \App\Models\Beneficiary::class)
                                <p>Register an approved beneficiary to get started.</p>
                                <a href="{{ route('beneficiaries.create') }}" class="rlcr-button rlcr-button-primary">Add Beneficiary</a>
                            @else
                                <p>A Director can assign beneficiaries to your caseload.</p>
                            @endcan
                        @endif
                    </div>
                @endif
                </div>
                @endfragment
                @endif
            </div>
        </section>

        @if ($tab === 'beneficiaries')
        @can('assignCaseworker', \App\Models\Beneficiary::class)
            @include('beneficiaries.partials.assign-caseworker-dialog')
        @endcan

        @can('viewArchived', \App\Models\Beneficiary::class)
            <dialog x-ref="archiveDialog" class="rlcr-archive-dialog" aria-labelledby="rlcr-archive-title"
                    x-on:cancel="if (saving) $event.preventDefault()">
                <h2 id="rlcr-archive-title">Archive selected beneficiaries?</h2>
                <p><strong x-text="pending.length"></strong> selected. Profiles, photos, attendance, and assistance history will be retained. You can restore them from View Archived.</p>
                <form method="POST" action="{{ route('beneficiaries.bulk-archive') }}"
                      x-on:submit="if (saving) { $event.preventDefault(); } else { saving = true; }">
                    @csrf
                    <template x-for="id in pending" :key="id"><input type="hidden" name="beneficiary_ids[]" :value="id"></template>
                    <div class="rlcr-actions">
                        <button type="button" class="rlcr-button rlcr-button-secondary" :disabled="saving" x-on:click="$refs.archiveDialog.close()">Cancel</button>
                        <button type="submit" class="rlcr-button rlcr-button-primary" :disabled="saving || !pending.length" :aria-busy="saving">
                            <span x-cloak x-show="saving" class="rlcr-saving-spinner" aria-hidden="true"></span>
                            <span x-text="saving ? 'Archiving...' : 'Archive'"></span>
                        </button>
                    </div>
                    <p role="status" x-cloak x-show="saving">Saving changes. Please wait...</p>
                </form>
            </dialog>
        @endcan
        @endif
    </div>
</x-layouts::app>
