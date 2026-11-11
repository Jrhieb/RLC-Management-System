import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { RegistrySelectionPersistence } from '../../resources/js/beneficiary-registry.js';

const template = readFileSync(
    new URL('../../resources/views/beneficiaries/index.blade.php', import.meta.url),
    'utf8',
);
const decode = (value) => value.replaceAll('&amp;', '&');
const production = decode(/data-registry-selection x-data="([\s\S]*?)"/.exec(template)[1]);
const handler = (name) => decode(new RegExp(name + '="([^"]*)"').exec(template)[1]);

function page({
    archive = ['1', '2'],
    assignment = ['1', '2'],
    selectable = [...new Set([...archive, ...assignment])],
    details = {},
} = {}) {
    const selectionRecords = Object.fromEntries(
        selectable.map((id) => [
            id,
            {
                name: 'Beneficiary ' + id,
                beneficiary_id: '00000' + id,
                caseworker_name: 'Unassigned',
                caseworker_id: null,
                ...details[id],
                canArchive: archive.includes(id),
                canAssign: assignment.includes(id),
            },
        ]),
    );
    return {
        archiveIds: archive,
        assignmentRecords: Object.fromEntries(assignment.map((id) => [id, selectionRecords[id]])),
        selectableIds: selectable,
        visibleIds: selectable,
        selectionRecords,
    };
}

function fixture({
    archive = ['1', '2'],
    assignment = ['1', '2'],
    selectable = [...new Set([...archive, ...assignment])],
} = {}) {
    let focused = '',
        dialogOpens = 0,
        dialogCloses = 0,
        layoutReady = false;
    const ticks = [],
        frames = [];
    const metadata = page({ archive, assignment, selectable });
    const source = production.replace(/{{[\s\S]*?}}/g, (expression) =>
        JSON.stringify(
            expression.includes('$selectionRecords')
                ? metadata.selectionRecords
                : expression.includes('$archiveIds')
                  ? archive
                  : expression.includes('$assignmentRecords')
                    ? assignment
                    : selectable,
        ),
    );
    const model = runInNewContext('(' + source + ')', {
        requestAnimationFrame: (fn) => frames.push(fn),
    });
    const root = {
        querySelectorAll: () => [
            {
                getClientRects: () =>
                    layoutReady && model.selecting && model.mode === 'cards' ? [{}] : [],
                focus: () => (focused = 'grid-select-all'),
            },
            {
                getClientRects: () =>
                    layoutReady && model.selecting && model.mode === 'list' ? [{}] : [],
                focus: () => (focused = 'list-select-all'),
            },
        ],
        querySelector: () => ({ focus: () => (focused = 'toolbar-menu') }),
    };
    Object.assign(model, {
        $el: { closest: () => root },
        $dispatch() {},
        $nextTick: (fn) => ticks.push(fn),
        $refs: { archiveDialog: { showModal: () => dialogOpens++, close: () => dialogCloses++ } },
    });
    return {
        model,
        get focused() {
            return focused;
        },
        get dialogOpens() {
            return dialogOpens;
        },
        get dialogCloses() {
            return dialogCloses;
        },
        flushTicks() {
            while (ticks.length) ticks.shift()();
        },
        flush() {
            this.flushTicks();
            layoutReady = true;
            while (frames.length) frames.shift()();
        },
        event(name, event = {}) {
            runInNewContext('with (model) { ' + handler(name) + ' }', {
                model,
                $event: event,
                $refs: model.$refs,
            });
        },
    };
}

test('selection is off by default and bulk actions require checked records', () => {
    const f = fixture();
    assert.equal(f.model.selecting, false);
    assert.equal(f.model.selected.length, 0);
    assert.equal(f.model.allSelected, false);
    assert.equal(f.model.partlySelected, false);
    assert.equal(f.model.canArchiveSelection, false);
    assert.equal(f.model.canAssignSelection, false);
});

for (const mode of ['list', 'cards'])
    test(
        'starting ' + mode + ' selection focuses its visible Select All without checking records',
        () => {
            const f = fixture();
            f.model.mode = mode;
            f.model.startSelection();
            f.flush();
            assert.equal(f.model.selecting, true);
            assert.equal(f.model.selected.length, 0);
            assert.equal(f.focused, mode === 'list' ? 'list-select-all' : 'grid-select-all');
        },
    );

test('focus waits until newly revealed selection controls have layout', () => {
    const f = fixture();
    f.model.mode = 'cards';
    f.model.startSelection();
    f.flushTicks();
    assert.equal(f.model.selecting, true);
    assert.equal(f.focused, '');
    f.flush();
    assert.equal(f.focused, 'grid-select-all');
});

test('an empty or entirely ineligible page cannot enter selection mode', () => {
    const f = fixture({ archive: [], assignment: [] });
    f.model.startSelection();
    f.flush();
    assert.equal(f.model.selecting, false);
    assert.equal(f.focused, '');
});

test('Select All uses only permitted current-page identifiers and owns its selected array', () => {
    const f = fixture({ archive: ['2', '7'], assignment: ['2', '8'], selectable: ['2', '7', '8'] });
    f.model.selectAll();
    assert.deepEqual([...f.model.selected], ['2', '7', '8']);
    assert.equal(f.model.allSelected, true);
    f.model.selected.pop();
    assert.deepEqual([...f.model.selectablePageIds], ['2', '7', '8']);
    assert.equal(f.model.partlySelected, true);
});

test('unchecking Select All or the last row retains explicit selection mode', () => {
    const f = fixture();
    f.model.selectAll();
    f.model.selectAll(false);
    assert.equal(f.model.selecting, true);
    assert.equal(f.model.selected.length, 0);
    assert.equal(f.model.partlySelected, false);
    f.model.selected = ['1'];
    f.model.selected = [];
    assert.equal(f.model.selecting, true);
});

test('Cancel clears selection and pending archive IDs, exits mode, and returns keyboard focus', () => {
    const f = fixture();
    f.model.selectAll();
    f.model.pending = ['1'];
    f.model.cancelSelection();
    f.flush();
    assert.equal(f.model.selecting, false);
    assert.equal(f.model.selected.length, 0);
    assert.equal(f.model.pending.length, 0);
    assert.equal(f.focused, 'toolbar-menu');
    assert.equal(f.model.canArchiveSelection, false);
    assert.equal(f.model.canAssignSelection, false);
});

test('permitted bulk actions require authorization for every selected record', () => {
    const f = fixture({ archive: ['1', '2'], assignment: ['1', '3'] });
    f.model.startSelection();
    f.model.selected = ['2'];
    assert.equal(f.model.canArchiveSelection, true);
    assert.equal(f.model.canAssignSelection, false);
    f.model.selected = ['3'];
    assert.equal(f.model.canArchiveSelection, false);
    assert.equal(f.model.canAssignSelection, true);
    f.model.selected = ['1'];
    assert.equal(f.model.canArchiveSelection, true);
    assert.equal(f.model.canAssignSelection, true);
    f.model.selected = ['2', '3'];
    assert.equal(f.model.canArchiveSelection, false);
    assert.equal(f.model.canAssignSelection, false);
    f.model.selected = ['outside-page'];
    assert.equal(f.model.canArchiveSelection, false);
    assert.equal(f.model.canAssignSelection, false);
});

test('switching between list and grid retains checked records and selection mode', () => {
    const f = fixture();
    f.model.startSelection();
    f.model.selected = ['1'];
    f.model.mode = 'cards';
    assert.equal(f.model.selecting, true);
    assert.deepEqual([...f.model.selected], ['1']);
    assert.equal(f.model.partlySelected, true);
    f.model.mode = 'list';
    assert.deepEqual([...f.model.selected], ['1']);
});

test('Rhieb selections survive Julyotskie search and all three reach archive and assignment forms', () => {
    const f = fixture();
    const rhieb = page({
        details: {
            1: { name: 'Rhieb One', beneficiary_id: '000001' },
            2: { name: 'Rhieb Two', beneficiary_id: '000002' },
        },
    });
    f.model.updatePage(rhieb);
    f.model.selectAll();
    f.model.updatePage(
        page({
            archive: ['3'],
            assignment: ['3'],
            details: {
                3: { name: 'Julyotskie', beneficiary_id: '000003' },
            },
        }),
    );
    assert.deepEqual([...f.model.selected], ['1', '2']);
    assert.equal(f.model.records['1'].name, 'Rhieb One');
    assert.equal(f.model.allSelected, false);
    assert.equal(f.model.partlySelected, false);
    f.model.toggleSelection('3', true);
    assert.deepEqual([...f.model.selected], ['1', '2', '3']);
    assert.equal(f.model.selected.length, 3);
    assert.equal(f.model.canArchiveSelection, true);
    assert.equal(f.model.canAssignSelection, true);
    f.model.confirmArchive(f.model.selected);
    assert.deepEqual([...f.model.pending], ['1', '2', '3']);
    assert.ok(template.includes('{ ids: [...selected], opener: $el }'));
    assert.ok(template.includes('x-for="id in pending"'));
    f.model.updatePage(rhieb);
    assert.equal(f.model.allSelected, true);
    assert.equal(f.model.records['3'].name, 'Julyotskie');
});

test('pending or failed results cannot select an outdated page or expose bulk actions', () => {
    const f = fixture();
    f.model.refreshing = true;
    f.model.startSelection();
    f.model.selectAll();
    assert.equal(f.model.selecting, false);
    assert.equal(f.model.selected.length, 0);
    f.model.selected = ['1'];
    assert.equal(f.model.canArchiveSelection, false);
    assert.equal(f.model.canAssignSelection, false);
    f.model.cancelSelection();
    assert.equal(f.model.selected.length, 0);
});

test('page Select All adds and removes permitted visible IDs while retaining other selections', () => {
    const f = fixture();
    f.model.selectAll();
    f.model.updatePage(page({ archive: ['3', '4'], assignment: ['3', '4'] }));
    f.model.toggleSelection('blocked', true);
    f.model.toggleSelection('3', true);
    assert.equal(f.model.partlySelected, true);
    f.model.selectAll();
    assert.deepEqual([...f.model.selected], ['1', '2', '3', '4']);
    assert.equal(f.model.allSelected, true);
    f.model.selectAll(false);
    assert.deepEqual([...f.model.selected], ['1', '2']);
    assert.equal(f.model.allSelected, false);
    assert.equal(f.model.partlySelected, false);
});

test('repeated filter, pagination, caseload and empty-result updates retain selections and mode', () => {
    const f = fixture();
    f.model.selectAll();
    for (let i = 0; i < 12; i++) {
        f.model.mode = i % 2 ? 'cards' : 'list';
        f.model.updatePage(page({ archive: [String(i + 10)], assignment: [String(i + 10)] }));
        f.model.updatePage(page({ archive: [], assignment: [] }));
        assert.deepEqual([...f.model.selected], ['1', '2']);
        assert.equal(f.model.selecting, true);
        assert.equal(f.model.canArchiveSelection, true);
        assert.equal(f.model.canAssignSelection, true);
    }
});

test('individual removal works for off-page and visible records, without exiting selection mode', () => {
    const f = fixture();
    f.model.selectAll();
    f.model.updatePage(page({ archive: ['3'], assignment: ['3'] }));
    f.model.selectAll();
    f.model.removeSelected('1');
    assert.deepEqual([...f.model.selected], ['2', '3']);
    f.model.removeSelected('3');
    assert.deepEqual([...f.model.selected], ['2']);
    assert.equal(f.model.allSelected, false);
    f.model.removeSelected('2');
    assert.equal(f.model.selected.length, 0);
    assert.equal(f.model.selecting, true);
});

test('fresh page permissions and display details replace cached values without dropping IDs', () => {
    const f = fixture();
    f.model.selectAll();
    f.model.updatePage(
        page({ archive: [], assignment: ['1', '2'], details: { 1: { name: 'Updated name' } } }),
    );
    assert.deepEqual([...f.model.selected], ['1', '2']);
    assert.equal(f.model.records['1'].name, 'Updated name');
    assert.equal(f.model.canArchiveSelection, false);
    assert.equal(f.model.canAssignSelection, true);
    f.model.updatePage({ ...page({ archive: [], assignment: [] }), visibleIds: ['1', '2'] });
    assert.deepEqual([...f.model.selected], ['1', '2']);
    assert.equal(f.model.canArchiveSelection, false);
    assert.equal(f.model.canAssignSelection, false);
});

test('the existing 100-record bulk limit retains earlier IDs and rejects additional selections', () => {
    const f = fixture();
    f.model.startSelection();
    f.model.selected = Array.from({ length: 99 }, (_, i) => String(i + 10));
    f.model.selectAll();
    assert.equal(f.model.selected.length, 100);
    assert.equal(f.model.selected[0], '10');
    assert.equal(f.model.selected.at(-1), '1');
    assert.match(f.model.selectionNotice, /100/);
    f.model.toggleSelection('2', true);
    assert.equal(f.model.selected.length, 100);
    f.model.removeSelected('10');
    f.model.toggleSelection('2', true);
    assert.equal(f.model.selected.at(-1), '2');
});

test('restoring a cached page retains selection but resets a saving archive dialog', () => {
    const f = fixture();
    f.model.selectAll();
    f.model.pending = ['1'];
    f.model.saving = true;
    f.event('x-on:pageshow.window');
    f.flush();
    assert.deepEqual([...f.model.selected], ['1', '2']);
    assert.equal(f.model.selecting, true);
    assert.equal(f.model.saving, false);
    assert.equal(f.dialogCloses, 1);
    assert.equal(f.focused, '');
});

function persistenceFixture() {
    const values = new Map();
    const storage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
    return {
        values,
        storage,
        persistence: new RegistrySelectionPersistence(storage, 'registry.user1'),
    };
}

test('cancelled and failed actions retain names, zero-prefixed IDs and permissions through redirects', () => {
    const f = fixture(),
        saved = persistenceFixture();
    f.model.selectAll();
    f.model.updatePage(page({ archive: ['3'], assignment: ['3'] }));
    f.model.selectAll();
    saved.persistence.save(f.model);
    const redirect = fixture({ archive: ['3'], assignment: ['3'] });
    saved.persistence.restore(redirect.model);
    assert.deepEqual([...redirect.model.selected], ['1', '2', '3']);
    assert.equal(redirect.model.records['1'].beneficiary_id, '000001');
    assert.equal(redirect.model.canAssignSelection, true);
    assert.equal(redirect.model.canArchiveSelection, true);
    assert.equal(redirect.model.selecting, true);
    redirect.model.confirmArchive(redirect.model.selected);
    assert.deepEqual([...redirect.model.pending], ['1', '2', '3']);
});

test('only successful bulk actions or Cancel selection clear the stored draft', () => {
    const f = fixture(),
        saved = persistenceFixture();
    f.model.selectAll();
    saved.persistence.save(f.model);
    saved.persistence.restore(f.model, true);
    assert.equal(f.model.selected.length, 0);
    assert.equal(f.model.selecting, false);
    assert.equal(saved.values.size, 0);
    f.model.selectAll();
    saved.persistence.save(f.model);
    f.model.cancelSelection();
    saved.persistence.save(f.model);
    assert.equal(saved.values.size, 0);
});

test('storage is user-scoped and invalid drafts or blocked storage do not break selection', () => {
    const f = fixture(),
        saved = persistenceFixture();
    f.model.selectAll();
    saved.persistence.save(f.model);
    const other = fixture();
    new RegistrySelectionPersistence(saved.storage, 'registry.user2').restore(other.model);
    assert.equal(other.model.selected.length, 0);
    saved.values.set(
        'registry.user1',
        JSON.stringify({
            selecting: true,
            selected: ['1', 'bad', '1'],
            records: { 1: f.model.records['1'] },
        }),
    );
    saved.persistence.restore(other.model);
    assert.deepEqual([...other.model.selected], ['1']);
    saved.values.set('registry.user1', '{invalid');
    assert.doesNotThrow(() => saved.persistence.restore(other.model));
    const blocked = new RegistrySelectionPersistence(
        {
            getItem() {
                throw Error('Blocked');
            },
            setItem() {
                throw Error('Blocked');
            },
        },
        'registry',
    );
    assert.doesNotThrow(() => blocked.save(f.model));
    assert.doesNotThrow(() => blocked.restore(f.model));
    assert.deepEqual([...f.model.selected], ['1', '2']);
});

test('individual row archive confirmation remains available outside selection mode', () => {
    const f = fixture();
    f.model.confirmArchive(['1']);
    assert.deepEqual([...f.model.pending], ['1']);
    assert.equal(f.dialogOpens, 1);
    assert.equal(f.model.selecting, false);
});
