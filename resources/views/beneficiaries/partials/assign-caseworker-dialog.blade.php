@php
    $assignmentErrors = $errors->getBag('assignCaseworker');
    $oldAssignmentIds = is_array(old('beneficiary_ids')) ? old('beneficiary_ids') : [];
    $oldAssignmentIds = collect($oldAssignmentIds)->filter(fn ($id) => (is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0)
        ->take(100)->map(fn ($id) => (string) (int) $id)->unique()->values()->all();
    $oldDestination = old('caseworker_id');
    $oldDestination = $oldDestination === null && session()->hasOldInput('caseworker_id') ? 'unassign'
        : ((is_string($oldDestination) || is_int($oldDestination)) ? (string) $oldDestination : '');
@endphp
<div data-assignment-controller x-data="{
    ids: {{ \Illuminate\Support\Js::from($assignmentErrors->any() ? $oldAssignmentIds : []) }},
    destination: {{ \Illuminate\Support\Js::from($assignmentErrors->any() ? $oldDestination : '') }},
    records: {{ \Illuminate\Support\Js::from($assignmentRecords) }},
    saving: false, opener: null,
    openDialog(nextIds, trigger = null, retainDestination = false) {
        if (this.saving) return;
        this.ids = [...nextIds].map(String);
        this.records = { ...this.records, ...Alpine.$data(this.$el.closest('[data-registry-selection]')).records };
        this.opener = trigger || document.querySelector('[aria-controls=rlcr-selection-menu]');
        if (!retainDestination) this.destination = '';
        this.$refs.dialog.showModal();
        this.$nextTick(() => this.$refs.destination.focus());
    },
    currentAssignment() {
        const assignments = this.ids.map(id => this.records[id]);
        if (assignments.some(record => !record)) return 'Some selected records are unavailable';
        if (!assignments.length) return 'No beneficiaries selected';
        return new Set(assignments.map(record => record.caseworker_id)).size === 1
            ? assignments[0].caseworker_name : 'Mixed assignments';
    },
    closeDialog() { if (!this.saving) this.$refs.dialog.close(); }
}"
     x-on:open-caseworker-assignment.window="openDialog($event.detail.ids, $event.detail.opener)"
     x-on:registry-page-updated.window="records = { ...records, ...$event.detail.assignmentRecords }"
     x-on:pageshow.window="if ($event.persisted) saving = false"
     x-init="if ({{ \Illuminate\Support\Js::from($assignmentErrors->any()) }}) $nextTick(() => openDialog(ids, null, true))">
    <dialog id="rlcr-assignment-dialog" data-assignment-dialog x-ref="dialog" class="rlcr-caseworker-dialog rlcr-assignment-dialog"
            aria-labelledby="rlcr-assignment-title" aria-describedby="rlcr-assignment-description rlcr-assignment-help"
            :aria-busy="saving" x-on:cancel="if (saving) $event.preventDefault()" x-on:close="opener?.focus()">
        <div class="rlcr-caseworker-heading">
            <div>
                <h2 id="rlcr-assignment-title">Assign / Reassign Caseworker</h2>
                <p id="rlcr-assignment-description"><strong x-text="ids.length"></strong> <span x-text="ids.length === 1 ? 'beneficiary selected' : 'beneficiaries selected'"></span>. Changes apply to all selected records.</p>
            </div>
            <button type="button" class="rlcr-caseworker-close" aria-label="Close caseworker assignment"
                    :disabled="saving" x-on:click="closeDialog()">&times;</button>
        </div>
        <form method="POST" data-assignment-form action="{{ route('beneficiaries.assign-caseworker', request()->only(['search', 'status', 'caseworker', 'page'])) }}"
              x-on:submit="if (saving || !ids.length || !destination) { $event.preventDefault(); } else { saving = true; }">
            @csrf
            <template x-for="id in ids" :key="id"><input type="hidden" name="beneficiary_ids[]" :value="id"></template>
            <input type="hidden" name="caseworker_id" :value="destination">
            <div class="rlcr-caseworker-fields">
                @if ($assignmentErrors->any())
                    <div id="rlcr-assignment-errors" role="alert" class="rlcr-caseworker-error-summary">
                        @foreach ($assignmentErrors->all() as $message)<p>{{ $message }}</p>@endforeach
                    </div>
                @endif
                <div class="rlcr-assignment-summary">
                    <template x-if="ids.length === 1 && records[ids[0]]">
                        <p><strong x-text="records[ids[0]].name"></strong><span x-text="records[ids[0]].beneficiary_id"></span></p>
                    </template>
                    <dl><dt>Current assignment</dt><dd x-text="currentAssignment()"></dd></dl>
                </div>
                <div class="rlcr-caseworker-field">
                    <label for="rlcr-assignment-destination">Destination caseworker</label>
                    <select id="rlcr-assignment-destination" data-assignment-destination x-ref="destination" x-model="destination" required :disabled="saving"
                            aria-invalid="{{ $assignmentErrors->has('caseworker_id') ? 'true' : 'false' }}"
                            aria-describedby="rlcr-assignment-help{{ $assignmentErrors->any() ? ' rlcr-assignment-errors' : '' }}">
                        <option value="" disabled>Choose a caseworker or Unassign</option>
                        <option value="unassign">Unassign</option>
                        @foreach ($caseworkers as $caseworker)
                            <option value="{{ $caseworker->id }}">{{ $caseworker->name }} &mdash; {{ $caseworker->email }}</option>
                        @endforeach
                    </select>
                </div>
                <p id="rlcr-assignment-help" class="rlcr-caseworker-setup">
                    Only approved caseworker accounts with verified email are available. Select Unassign to remove the current assignment.
                    @if ($caseworkers->isEmpty()) No eligible caseworkers are available yet. @endif
                </p>
            </div>
            <div class="rlcr-caseworker-footer">
                <p role="status" x-cloak x-show="saving">Saving assignments&hellip;</p>
                <div class="rlcr-actions">
                    <button type="button" class="rlcr-button rlcr-button-secondary" :disabled="saving" x-on:click="closeDialog()">Cancel</button>
                    <button type="submit" data-assignment-submit class="rlcr-button rlcr-button-primary" :disabled="saving || !ids.length || !destination" :aria-busy="saving">
                        <span x-cloak x-show="saving" class="rlcr-saving-spinner" aria-hidden="true"></span>
                        <span x-text="saving ? 'Saving...' : 'Save assignment'">Save assignment</span>
                    </button>
                </div>
            </div>
        </form>
    </dialog>
</div>
