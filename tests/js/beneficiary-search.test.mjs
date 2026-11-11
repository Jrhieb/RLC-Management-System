import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

// Execute the production Alpine object shared by Dashboard and Registry.
const template = readFileSync(new URL('../../resources/views/components/rlcp-beneficiary-search.blade.php', import.meta.url), 'utf8');
const source = /x-data="([\s\S]*?)"/.exec(template)[1].replaceAll('&amp;', '&');
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const json = results => ({ ok: true, json: async () => ({ results }) });
const person = (id, name = 'Angel Reyes') => ({ id, name, beneficiary_id: '0027947780', url: '/beneficiaries/' + id, photo_url: null });
const settle = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };

function fixture({ registry = true, value = '', caseworker = '', status = '', request = async () => json([]), bounds = { left: 20, top: 100, bottom: 142, width: 320 } } = {}) {
    let time = 0, timerId = 0, submits = 0, focused = 0, popover = false;
    const timers = new Map(), calls = [], navigations = [], scrolls = [], listeners = new Map(), styles = {};
    const fields = { caseworker: { value: String(caseworker) }, status: { value: status } };
    const form = {
        action: 'https://rlc.test/beneficiaries', elements: { namedItem: key => fields[key] },
        requestSubmit() { submits++; listeners.get('submit')?.(); },
        addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name),
    };
    const input = { form, value, focus() { focused++; } };
    const root = { dataset: { registry: String(registry), value, endpoint: 'https://rlc.test/dashboard/beneficiary-suggestions', optionPrefix: registry ? 'rlcr-search-option-' : 'rlc-search-option-' }, closest: () => form };
    const dropdown = { matches: () => popover, showPopover() { popover = true; }, hidePopover() { popover = false; }, style: { setProperty: (key, value) => styles[key] = value } };
    const model = runInNewContext('(' + source + ')', {
        AbortController, URL,
        fetch: (url, options) => { calls.push({ url: new URL(url), options }); return request(url, options); },
        clearTimeout: id => timers.delete(id),
        setTimeout: (fn, delay) => { const id = ++timerId; timers.set(id, { fn, at: time + delay }); return id; },
        window: { location: { origin: 'https://rlc.test', assign: url => navigations.push(url) }, innerWidth: 390, innerHeight: 844 },
        document: { getElementById: id => ({ scrollIntoView: options => scrolls.push({ id, options }) }) },
    });
    Object.assign(model, { $root: root, $refs: { input, dropdown, field: { getBoundingClientRect: () => bounds } }, $nextTick: fn => fn() });
    model.init();
    return { model, calls, navigations, scrolls, fields, styles, dropdown, input, listeners,
        get submits() { return submits; }, get focused() { return focused; }, get popover() { return popover; },
        type(term, event = {}) { model.term = term; input.value = term; model.changed(event); },
        tick(ms) { time += ms; for (const [id, timer] of [...timers]) if (timer.at <= time) { timers.delete(id); timer.fn(); } },
    };
}

test('Registry restores server-rendered search and waits 250ms after the latest keystroke', async () => {
    const f = fixture({ value: 'Existing' }); assert.equal(f.model.term, 'Existing'); assert.equal(f.model.open, false);
    f.type('A'); assert.equal(f.model.loading, true); f.tick(200); assert.equal(f.calls.length, 0);
    f.type('Angel'); f.tick(249); assert.equal(f.calls.length, 0); f.tick(1); await settle();
    assert.equal(f.calls.length, 1); assert.equal(f.calls[0].url.searchParams.get('search'), 'Angel'); assert.equal(f.model.loading, false);
});

test('suggestions and View all links use current caseworker/status filters and reset page', async () => {
    const f = fixture({ caseworker: '7', status: 'inactive' }); f.type(' 0027947780 '); f.tick(250); await settle();
    assert.equal(f.calls[0].url.pathname, '/dashboard/beneficiary-suggestions');
    assert.deepEqual(Object.fromEntries(f.calls[0].url.searchParams), { search: '0027947780', caseworker: '7', status: 'inactive' });
    assert.equal(f.calls[0].options.credentials, 'same-origin'); assert.equal(f.calls[0].options.headers.Accept, 'application/json');
    const all = new URL(f.model.searchUrl('https://rlc.test/beneficiaries?page=3'));
    assert.deepEqual(Object.fromEntries(all.searchParams), { search: '0027947780', caseworker: '7', status: 'inactive' });
    f.fields.caseworker.value = 'unassigned'; f.fields.status.value = '';
    assert.equal(new URL(f.model.searchUrl(f.input.form.action)).searchParams.get('caseworker'), 'unassigned');
    assert.equal(new URL(f.model.searchUrl(f.input.form.action)).searchParams.has('status'), false);
});

test('typing cancels the prior request and an older result cannot replace newer suggestions', async () => {
    const old = deferred(), recent = deferred(); let requestNumber = 0;
    const f = fixture({ request: () => (++requestNumber === 1 ? old : recent).promise });
    f.type('Old'); f.tick(250); const oldSignal = f.calls[0].options.signal;
    f.type('New'); assert.equal(oldSignal.aborted, true); f.tick(250);
    recent.resolve(json([person(2, 'New')])); await settle(); assert.equal(f.model.results[0].name, 'New');
    old.resolve(json([person(1, 'Old')])); await settle(); assert.equal(f.model.results[0].name, 'New'); assert.equal(f.model.error, '');
});

test('a stale JSON response or failure cannot clear newer loading/error state', async () => {
    const body = deferred(), recent = deferred(); let number = 0;
    const f = fixture({ request: async () => ++number === 1 ? { ok: true, json: () => body.promise } : recent.promise });
    f.type('Old'); f.tick(250); await settle(); f.type('New'); f.tick(250); await settle();
    body.resolve({ results: [person(1)] }); await settle(); assert.equal(f.model.loading, true); assert.equal(f.model.results.length, 0);
    recent.reject(new Error('Offline')); await settle(); assert.match(f.model.error, /Unable to search/); assert.equal(f.model.loading, false);
});

test('stale debounce callbacks never start a request', async () => {
    const f = fixture(); f.type('Old'); const oldVersion = f.model.version; f.type('New');
    await f.model.lookup(oldVersion); assert.equal(f.calls.length, 0); f.tick(250); await settle(); assert.equal(f.calls.length, 1);
});

test('loading, empty results and successful necessary fields remain distinct', async () => {
    const response = deferred(), f = fixture({ request: () => response.promise }); f.type('Angel'); f.tick(250);
    assert.equal(f.model.open, true); assert.equal(f.model.loading, true); assert.equal(f.model.error, '');
    response.resolve(json([])); await settle(); assert.equal(f.model.results.length, 0); assert.equal(f.model.loading, false); assert.equal(f.model.error, '');
    const withPhoto = fixture({ request: async () => json([{ ...person(1), photo_url: '/beneficiaries/1/photo' }]) });
    withPhoto.type('Angel'); withPhoto.tick(250); await settle(); assert.equal(withPhoto.model.results[0].beneficiary_id, '0027947780');
    assert.equal(withPhoto.model.results[0].photo_url, '/beneficiaries/1/photo');
});

for (const [name, request] of [
    ['HTTP/session failures', async () => ({ ok: false })],
    ['invalid response shape', async () => ({ ok: true, json: async () => ({ results: 'bad' }) })],
    ['non-JSON responses', async () => ({ ok: true, json: async () => { throw new Error('Invalid JSON'); } })],
    ['network failures', async () => { throw new Error('Offline'); }],
]) test(name + ' show request error and allow Enter to apply the typed search', async () => {
    const f = fixture({ request }); f.type('Angel'); f.tick(250); await settle();
    assert.match(f.model.error, /Unable to search/); assert.equal(f.model.loading, false); assert.equal(f.model.results.length, 0);
    let prevented = false; f.model.select({ preventDefault() { prevented = true; } }); assert.equal(prevented, true); assert.equal(f.submits, 1);
});

test('Up/Down wrap through suggestions and active Enter follows the profile without submitting the list', async () => {
    const f = fixture({ request: async () => json([person(1), person(2), person(3)]) }); f.type('Angel'); f.tick(250); await settle();
    f.model.move(-1); assert.equal(f.model.active, 2); assert.equal(f.scrolls.at(-1).id, 'rlcr-search-option-2');
    f.model.move(1); assert.equal(f.model.active, 0); f.model.move(1); assert.equal(f.model.active, 1);
    let prevented = false; f.model.select({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true); assert.deepEqual(f.navigations, ['/beneficiaries/2']); assert.equal(f.submits, 0);
});

test('Enter without an active suggestion applies search even while checking', () => {
    const f = fixture({ caseworker: 'unassigned', status: 'active' }); f.type('Typed search');
    f.model.select({ preventDefault() {} }); assert.equal(f.submits, 1); assert.equal(f.model.open, false);
    assert.equal(f.input.value, 'Typed search'); assert.equal(f.fields.caseworker.value, 'unassigned'); assert.equal(f.fields.status.value, 'active');
    f.tick(250); assert.equal(f.calls.length, 0);
});

test('clear resets search, retains other form filters, focuses input, and cancels stale responses', async () => {
    const response = deferred(), f = fixture({ value: 'Angel', caseworker: '7', status: 'inactive', request: () => response.promise });
    f.model.changed(); f.tick(250); const signal = f.calls[0].options.signal; f.model.clear();
    assert.equal(f.model.term, ''); assert.equal(f.input.value, ''); assert.equal(f.focused, 1); assert.equal(f.submits, 1);
    assert.equal(f.fields.caseworker.value, '7'); assert.equal(f.fields.status.value, 'inactive'); assert.equal(signal.aborted, true);
    response.resolve(json([person(1)])); await settle(); assert.equal(f.model.results.length, 0); assert.equal(f.model.open, false);
});

test('native clear/backspace submits an empty search without losing caseworker/status', () => {
    const f = fixture({ value: 'Angel', caseworker: '7', status: 'active' }); f.type('');
    assert.equal(f.submits, 1); assert.equal(f.model.open, false); assert.equal(f.calls.length, 0);
    assert.equal(f.fields.caseworker.value, '7'); assert.equal(f.fields.status.value, 'active');
});

test('Escape/outside dismissal cancels work and prevents late results from repopulating the popup', async () => {
    const response = deferred(), f = fixture({ request: () => response.promise }); f.type('Angel'); f.tick(250); f.model.dismiss();
    assert.equal(f.calls[0].options.signal.aborted, true); assert.equal(f.model.active, -1); assert.equal(f.model.loading, false);
    response.resolve(json([person(1)])); await settle(); assert.equal(f.model.results.length, 0); assert.equal(f.model.open, false); assert.equal(f.model.term, 'Angel');
});

test('form submission and destruction remove pending work and listeners', async () => {
    const response = deferred(), f = fixture({ request: () => response.promise }); f.type('Angel'); f.tick(250);
    f.input.form.requestSubmit(); assert.equal(f.model.open, false); assert.equal(f.calls[0].options.signal.aborted, true);
    f.model.destroy(); assert.equal(f.listeners.size, 0); response.resolve(json([person(1)])); await settle(); assert.equal(f.model.results.length, 0);
});

test('IME composition does not fetch or submit until composition finishes', async () => {
    const f = fixture(); f.type('Name', { isComposing: true }); f.tick(250); assert.equal(f.calls.length, 0);
    f.model.select({ isComposing: true, preventDefault() {} }); assert.equal(f.submits, 0);
    f.model.changed(); f.tick(250); await settle(); assert.equal(f.calls.length, 1);
});

test('Registry popover fits the viewport, flips above when needed, and closes for off-screen inputs', () => {
    const f = fixture({ bounds: { left: 300, top: 700, bottom: 742, width: 400 } }); f.type('Angel'); f.model.positionDropdown();
    assert.equal(f.popover, true); assert.equal(f.dropdown.style.width, '366px'); assert.equal(f.dropdown.style.left, '12px');
    assert.equal(f.dropdown.style.top, 'auto'); assert.equal(f.dropdown.style.bottom, '152px'); assert.equal(f.styles['--search-available-height'], '680px');
    const gone = fixture({ bounds: { left: 20, top: -60, bottom: -18, width: 300 } }); gone.type('Angel'); gone.model.positionDropdown();
    assert.equal(gone.model.open, false);
});

test('Dashboard default still uses the shared endpoint, its own IDs, and native Enter without Registry filters', async () => {
    const f = fixture({ registry: false, caseworker: '7', status: 'inactive', request: async () => json([person(1)]) });
    f.type('Angel'); f.tick(250); await settle(); assert.deepEqual(Object.fromEntries(f.calls[0].url.searchParams), { search: 'Angel' });
    assert.equal(f.listeners.size, 0); assert.equal(f.model.optionId(0), 'rlc-search-option-0');
    let prevented = false; f.model.select({ preventDefault() { prevented = true; } }); assert.equal(prevented, false); assert.equal(f.submits, 0);
    f.model.move(1); f.model.select({ preventDefault() {} }); assert.deepEqual(f.navigations, ['/beneficiaries/1']);
});
