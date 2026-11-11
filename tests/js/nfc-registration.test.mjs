import assert from 'node:assert/strict';
import test from 'node:test';
import { NfcRegistration, mountNfcRegistration } from '../../resources/js/nfc-registration.js';

const uid = '0027947780';
const oldUid = '04A1B2C3';
const config = { url: '/beneficiaries/1/nfc', token: 'test-token', nfcUid: null };
const pendingConfig = { ...config, url: '/beneficiaries/nfc/check', mode: 'pending', method: 'POST' };
const pendingMessage = 'Card ready — save the beneficiary to complete registration.';
const reply = (status, data) => ({ status, ok: status >= 200 && status < 300, json: async () => data });
const ready = () => reply(200, { status: 'ready', nfc_uid: uid });
const saved = () => reply(200, { status: 'registered', nfc_uid: uid });
const replacement = (current = oldUid) => reply(409, { status: 'replacement_required', nfc_uid: current });
const deferred = () => {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
};
const settle = () => new Promise((resolve) => setImmediate(resolve));
const enter = (options = {}) => Object.assign(new Event('keydown', { cancelable: true }), { key: 'Enter', ...options });

test('capture is inactive before opening and after closing', async () => {
    let calls = 0;
    const model = new NfcRegistration(config, { request: async () => { calls++; return ready(); } });
    await model.submit(uid);
    model.open();
    assert.equal(model.message, 'Waiting for card…');
    assert.equal(model.scanning, true);
    model.close();
    await model.submit(uid);
    assert.equal(model.scanning, false);
    assert.equal(calls, 0);
});

test('empty identifiers never start checking in either registration mode', async () => {
    for (const mode of [config, pendingConfig]) {
        let calls = 0;
        const model = new NfcRegistration(mode, { request: async () => { calls++; return ready(); } });
        model.open();
        for (const value of ['', '  ', '\r\n']) await model.submit(value);
        assert.equal(calls, 0);
        assert.equal(model.state, 'idle');
        assert.equal(model.message, 'Waiting for card…');
        assert.equal(model.candidate, null);
    }
});

test('checks then saves once, keeps leading zeros, and reports success only after saving', async () => {
    const check = deferred();
    const save = deferred();
    const calls = [];
    const model = new NfcRegistration(config, { request: (url, options) => {
        calls.push({ url, ...options, payload: JSON.parse(options.body) });
        return calls.length === 1 ? check.promise : save.promise;
    } });
    model.open();
    const pending = model.submit(uid);
    await model.submit('different scan');
    assert.equal(model.state, 'checking');
    assert.equal(model.scanning, false);
    assert.equal(model.linkedUid, null);
    assert.equal(calls.length, 1);
    check.resolve(ready());
    await settle();
    assert.equal(model.state, 'saving');
    assert.equal(model.linkedUid, null);
    assert.equal(calls.length, 2);
    assert.equal(calls[0].payload.check_only, true);
    for (const call of calls) {
        assert.equal(call.url, config.url);
        assert.equal(call.method, 'PUT');
        assert.equal(call.credentials, 'same-origin');
        assert.equal(call.headers['X-CSRF-TOKEN'], config.token);
        assert.equal(call.payload.nfc_uid, uid);
        assert.equal(typeof call.payload.nfc_uid, 'string');
    }
    save.resolve(saved());
    await pending;
    assert.equal(model.state, 'success');
    assert.equal(model.message, 'NFC card registered successfully');
    assert.equal(model.linkedUid, uid);
});

test('same card shows Already registered without a save', async () => {
    let calls = 0;
    const model = new NfcRegistration({ ...config, nfcUid: uid }, { request: async () => {
        calls++;
        return reply(200, { status: 'already_registered', nfc_uid: uid });
    } });
    model.open();
    await model.submit(uid);
    assert.equal(calls, 1);
    assert.equal(model.message, 'Already registered');
    assert.equal(model.state, 'already');
    assert.equal(model.scanning, false);
});

test('default requests keep the browser fetch receiver', async () => {
    const originalFetch = globalThis.fetch;
    let calls = 0;
    globalThis.fetch = async function () {
        assert.equal(this, globalThis);
        return ++calls === 1 ? ready() : saved();
    };
    try {
        const model = new NfcRegistration(config);
        model.open();
        await model.submit(uid);
        assert.equal(calls, 2);
        assert.equal(model.state, 'success');
        assert.equal(model.linkedUid, uid);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('replacement saves only after confirmation and includes the exact previous card', async () => {
    const calls = [];
    const model = new NfcRegistration({ ...config, nfcUid: oldUid }, { request: async (url, options) => {
        calls.push(JSON.parse(options.body));
        return calls.length === 1 ? replacement() : saved();
    } });
    model.open();
    await model.submit(uid);
    assert.equal(calls.length, 1);
    assert.equal(model.state, 'confirmation');
    assert.equal(model.linkedUid, oldUid);
    assert.equal(model.scanning, false);
    await model.confirm();
    assert.deepEqual(calls[1], { nfc_uid: uid, confirm_replace: true, expected_nfc_uid: oldUid });
    assert.equal(model.linkedUid, uid);
    assert.equal(model.state, 'success');
});

test('cancelling replacement leaves the linked card intact and discards confirmation', async () => {
    let calls = 0;
    const model = new NfcRegistration({ ...config, nfcUid: oldUid }, { request: async () => {
        calls++;
        return replacement();
    } });
    model.open();
    await model.submit(uid);
    model.close();
    await model.confirm();
    assert.equal(calls, 1);
    assert.equal(model.linkedUid, oldUid);
    assert.equal(model.candidate, null);
    model.open();
    assert.equal(model.state, 'idle');
});

test('a newer linked card requires a new confirmation', async () => {
    const calls = [];
    const responses = [replacement(), replacement('04A1B2C4'), saved()];
    const model = new NfcRegistration(config, { request: async (url, options) => {
        calls.push(JSON.parse(options.body));
        return responses.shift();
    } });
    model.open();
    await model.submit(uid);
    await model.confirm();
    assert.equal(model.state, 'confirmation');
    assert.equal(model.linkedUid, '04A1B2C4');
    await model.confirm();
    assert.equal(calls[2].expected_nfc_uid, '04A1B2C4');
    assert.equal(model.state, 'success');
});

test('conflicts during checking or saving show an error without changing the card', async () => {
    for (const duringSave of [false, true]) {
        let calls = 0;
        const model = new NfcRegistration(config, { request: async () => {
            calls++;
            return duringSave && calls === 1 ? ready()
                : reply(422, { errors: { nfc_uid: ['This NFC card is already linked to another beneficiary.'] } });
        } });
        model.open();
        await model.submit(uid);
        assert.equal(model.state, 'error');
        assert.equal(model.scanning, true);
        assert.equal(model.linkedUid, null);
        assert.equal(model.message, 'This NFC card is already linked to another beneficiary.');
    }
});

test('closing cancels a check and a late check response cannot initiate a save', async () => {
    const check = deferred();
    const calls = [];
    const model = new NfcRegistration(config, { request: (url, options) => {
        calls.push(options);
        return check.promise;
    } });
    model.open();
    const pending = model.submit(uid);
    model.close();
    assert.equal(calls[0].signal.aborted, true);
    model.open();
    check.resolve(ready());
    await pending;
    assert.equal(calls.length, 1);
    assert.equal(model.state, 'idle');
    assert.equal(model.linkedUid, null);
});

test('closing during saving stops capture but applies the eventual saved card without reopening', async () => {
    const save = deferred();
    let calls = 0;
    const model = new NfcRegistration(config, { request: async () => ++calls === 1 ? ready() : save.promise });
    model.open();
    const pending = model.submit(uid);
    await settle();
    assert.equal(model.state, 'saving');
    model.close();
    await model.submit('another scan');
    assert.equal(model.open(), false);
    assert.equal(model.scanning, false);
    save.resolve(saved());
    await pending;
    assert.equal(calls, 2);
    assert.equal(model.isOpen, false);
    assert.equal(model.linkedUid, uid);
    assert.equal(model.busy, false);
});

test('network or non-JSON save failures never report success', async () => {
    for (const nonJson of [false, true]) {
        let calls = 0;
        const model = new NfcRegistration(config, { request: async () => {
            if (++calls === 1) return ready();
            if (nonJson) return { json: async () => { throw new SyntaxError('HTML login response'); } };
            throw new Error('Connection lost');
        } });
        model.open();
        await model.submit(uid);
        assert.equal(model.state, 'error');
        assert.equal(model.linkedUid, null);
        assert.match(model.message, /Could not confirm the save/);
        assert.equal(model.scanning, true);
    }
});

test('authorization and session errors are displayed without saving', async () => {
    for (const status of [401, 403, 404, 419, 500]) {
        let calls = 0;
        const model = new NfcRegistration(config, { request: async () => { calls++; return reply(status, {}); } });
        model.open();
        await model.submit(uid);
        assert.equal(calls, 1);
        assert.equal(model.state, 'error');
        assert.equal(model.linkedUid, null);
        assert.ok(model.message);
    }
});

test('pending mode checks once and stages the identifier without linking or reporting registration success', async () => {
    const calls = [];
    const model = new NfcRegistration(pendingConfig, { request: async (url, options) => {
        calls.push({ url, ...options, payload: JSON.parse(options.body) });
        return ready();
    } });
    model.open();
    await model.submit(uid);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, pendingConfig.url);
    assert.equal(calls[0].method, 'POST');
    assert.equal(calls[0].payload.nfc_uid, uid);
    assert.equal(typeof calls[0].payload.nfc_uid, 'string');
    assert.equal(model.pendingUid, uid);
    assert.equal(model.linkedUid, null);
    assert.equal(model.state, 'ready');
    assert.equal(model.message, pendingMessage);
    assert.equal(model.scanning, false);
    await model.confirm();
    await model.save(uid, model.version);
    assert.equal(calls.length, 1);
});

test('pending mode uses the canonical hex identifier returned by the shared validator', async () => {
    const model = new NfcRegistration(pendingConfig, { request: async () => reply(200, { status: 'ready', nfc_uid: '04A1B2C3D4E5F6' }) });
    model.open();
    await model.submit('04:a1:b2:c3:d4:e5:f6');
    assert.equal(model.pendingUid, '04A1B2C3D4E5F6');
    assert.equal(model.message, pendingMessage);
});

test('a restored pending card survives opening and cancelling another scan', async () => {
    const model = new NfcRegistration({ ...pendingConfig, nfcUid: uid });
    assert.equal(model.pendingUid, uid);
    assert.equal(model.linkedUid, null);
    model.open();
    model.close();
    assert.equal(model.pendingUid, uid);
    assert.equal(model.scanning, false);
});

test('an occupied or invalid new card cannot replace the previously pending card', async () => {
    let calls = 0;
    const model = new NfcRegistration({ ...pendingConfig, nfcUid: uid }, { request: async () => {
        calls++;
        return reply(422, { errors: { nfc_uid: ['This NFC card is already linked to another beneficiary.'] } });
    } });
    model.open();
    await model.submit('0000000001');
    assert.equal(calls, 1);
    assert.equal(model.pendingUid, uid);
    assert.equal(model.state, 'error');
    assert.equal(model.linkedUid, null);
});

test('pending mode rejects profile save or replacement responses', async () => {
    for (const response of [saved(), reply(200, { status: 'already_registered', nfc_uid: uid }), replacement()]) {
        const model = new NfcRegistration(pendingConfig, { request: async () => response });
        model.open();
        await model.submit(uid);
        assert.equal(model.pendingUid, null);
        assert.equal(model.linkedUid, null);
        assert.equal(model.state, 'error');
    }
});

test('closing a pending check prevents a late response from selecting a card', async () => {
    const check = deferred();
    let signal;
    const model = new NfcRegistration(pendingConfig, { request: (url, options) => {
        signal = options.signal;
        return check.promise;
    } });
    model.open();
    const pending = model.submit(uid);
    model.close();
    assert.equal(signal.aborted, true);
    check.resolve(ready());
    await pending;
    assert.equal(model.pendingUid, null);
    assert.equal(model.isOpen, false);
});

test('removing a pending card clears it and cancels a check that could restore it', async () => {
    const check = deferred();
    const model = new NfcRegistration({ ...pendingConfig, nfcUid: oldUid }, { request: () => check.promise });
    model.open();
    const pending = model.submit(uid);
    model.removePending();
    check.resolve(ready());
    await pending;
    assert.equal(model.pendingUid, null);
    assert.equal(model.state, 'idle');
});

// A small EventTarget fixture exercises popup event scope and cleanup without dependencies.
class Element extends EventTarget {
    constructor() {
        super();
        this.hidden = false;
        this.disabled = false;
        this.value = '';
        this.textContent = '';
        this.open = false;
        this.focusCount = 0;
        this.attributes = {};
        this.classList = { toggle() {} };
    }
    setAttribute(name, value) { this.attributes[name] = value; }
    focus() { this.focusCount++; }
    showModal() { this.open = true; }
    close() {
        this.open = false;
        const notify = () => this.dispatchEvent(new Event('close'));
        if (this.deferClose) queueMicrotask(notify);
        else notify();
    }
}

function popup(popupConfig = config) {
    const selectors = ['data-nfc-dialog', 'data-nfc-input', 'data-nfc-scan-form', 'data-nfc-open',
        'data-nfc-status', 'data-nfc-linked-uid', 'data-nfc-unlink', 'data-nfc-card-icon',
        'data-nfc-loading-icon', 'data-nfc-success-icon', 'data-nfc-confirmation', 'data-nfc-existing',
        'data-nfc-candidate', 'data-nfc-scan-again', 'data-nfc-replace', 'data-nfc-manual-input',
        'data-nfc-manual-fields', 'data-nfc-manual-toggle'];
    const elements = Object.fromEntries(selectors.map((selector) => [`[${selector}]`, new Element()]));
    if (popupConfig.mode === 'pending') {
        for (const name of ['data-nfc-unlink', 'data-nfc-confirmation', 'data-nfc-existing', 'data-nfc-candidate', 'data-nfc-replace']) {
            delete elements[`[${name}]`];
        }
        for (const name of ['data-nfc-pending-input', 'data-nfc-pending-status', 'data-nfc-remove', 'data-beneficiary-create-form', 'data-beneficiary-submit']) {
            elements[`[${name}]`] = new Element();
        }
    }
    elements['[name="_token"]'] = Object.assign(new Element(), { value: config.token });
    const closes = [new Element(), new Element()];
    const root = {
        dataset: { config: JSON.stringify(popupConfig) },
        querySelector: (selector) => elements[selector],
        querySelectorAll: () => closes,
    };
    return { root, elements, closes };
}

test('pending popup fills only the beneficiary hidden input and supports removing the card before submission', async () => {
    const originalFetch = globalThis.fetch;
    const check = deferred();
    let calls = 0;
    globalThis.fetch = () => { calls++; return check.promise; };
    const { root, elements, closes } = popup(pendingConfig);
    const cleanup = mountNfcRegistration(root);
    try {
        elements['[data-nfc-open]'].dispatchEvent(new Event('click'));
        elements['[data-nfc-input]'].value = uid;
        elements['[data-nfc-scan-form]'].dispatchEvent(new Event('submit', { cancelable: true }));
        assert.equal(elements['[data-beneficiary-submit]'].disabled, true);
        const earlySubmit = new Event('submit', { cancelable: true });
        elements['[data-beneficiary-create-form]'].dispatchEvent(earlySubmit);
        assert.equal(earlySubmit.defaultPrevented, true);
        assert.equal(elements['[data-nfc-pending-input]'].value, '');
        check.resolve(ready());
        await settle();
        assert.equal(calls, 1);
        assert.equal(elements['[data-nfc-pending-input]'].value, uid);
        assert.equal(elements['[data-nfc-pending-status]'].textContent, pendingMessage);
        assert.equal(elements['[data-beneficiary-submit]'].disabled, false);
        closes[1].dispatchEvent(new Event('click'));
        assert.equal(elements['[data-nfc-pending-input]'].value, uid);
        const submit = new Event('submit', { cancelable: true });
        elements['[data-beneficiary-create-form]'].dispatchEvent(submit);
        assert.equal(submit.defaultPrevented, false);
        elements['[data-nfc-remove]'].dispatchEvent(new Event('click'));
        assert.equal(elements['[data-nfc-pending-input]'].value, '');
        assert.equal(elements['[data-nfc-linked-uid]'].textContent, 'No card selected');
        assert.equal(elements['[data-nfc-remove]'].hidden, true);
    } finally {
        cleanup();
        globalThis.fetch = originalFetch;
    }
});

test('reader Enter automatically checks and stages or saves, ignoring empty, composing and repeated scans', async () => {
    const originalFetch = globalThis.fetch;
    try {
        for (const mode of [config, pendingConfig]) {
            const check = deferred();
            const save = deferred();
            const calls = [];
            globalThis.fetch = (url, options) => {
                calls.push(JSON.parse(options.body));
                return calls.length === 1 ? check.promise : save.promise;
            };
            const { root, elements } = popup(mode);
            const cleanup = mountNfcRegistration(root);
            const find = (name) => elements[`[data-nfc-${name}]`];
            try {
                find('open').dispatchEvent(new Event('click'));
                assert.equal(find('manual-fields').hidden, true);
                assert.equal(find('manual-input').disabled, true);
                assert.equal(find('manual-toggle').textContent, 'Enter card manually');
                assert.equal(find('status').textContent, 'Waiting for card…');
                find('input').dispatchEvent(enter());
                find('input').value = '   ';
                find('input').dispatchEvent(enter());
                find('input').value = uid;
                find('input').dispatchEvent(enter({ repeat: true }));
                find('input').dispatchEvent(enter({ isComposing: true }));
                assert.equal(calls.length, 0);
                const scan = enter();
                find('input').dispatchEvent(scan);
                assert.equal(scan.defaultPrevented, true);
                assert.equal(find('input').value, '');
                assert.equal(find('input').disabled, true);
                assert.equal(find('status').textContent, 'Checking NFC card...');
                assert.equal(find('loading-icon').hidden, false);
                find('input').dispatchEvent(enter());
                find('scan-form').dispatchEvent(new Event('submit', { cancelable: true }));
                assert.equal(calls.length, 1);
                assert.equal(calls[0].nfc_uid, uid);
                check.resolve(ready());
                await settle();
                if (mode.mode === 'pending') {
                    assert.equal(calls.length, 1);
                    assert.equal(find('pending-input').value, uid);
                    assert.equal(find('status').textContent, pendingMessage);
                } else {
                    assert.equal(calls.length, 2);
                    assert.equal(find('status').textContent, 'Saving NFC card...');
                    assert.equal(find('linked-uid').textContent, 'No card linked');
                    find('input').dispatchEvent(enter());
                    assert.equal(calls.length, 2);
                    save.resolve(saved());
                    await settle();
                    assert.equal(find('linked-uid').textContent, uid);
                    assert.equal(find('status').textContent, 'NFC card registered successfully');
                }
            } finally {
                cleanup();
            }
        }
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('manual fallback shares validation, focuses its field, and returns to a fresh reader scan', async () => {
    const originalFetch = globalThis.fetch;
    const calls = [];
    globalThis.fetch = async (url, options) => {
        calls.push(JSON.parse(options.body));
        return calls.length === 1 ? reply(422, { errors: { nfc_uid: ['Invalid card identifier.'] } })
            : reply(200, { status: 'ready', nfc_uid: '04A1B2C3' });
    };
    const { root, elements, closes } = popup(pendingConfig);
    const cleanup = mountNfcRegistration(root);
    const find = (name) => elements[`[data-nfc-${name}]`];
    try {
        find('open').dispatchEvent(new Event('click'));
        find('manual-toggle').dispatchEvent(new Event('click'));
        assert.equal(find('manual-fields').hidden, false);
        assert.equal(find('input').disabled, true);
        assert.equal(find('manual-input').disabled, false);
        assert.ok(find('manual-input').focusCount > 0);
        assert.equal(find('manual-toggle').attributes['aria-expanded'], 'true');
        find('manual-input').dispatchEvent(enter());
        assert.equal(calls.length, 0);
        find('manual-input').value = 'invalid';
        find('scan-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();
        assert.equal(find('status').textContent, 'Invalid card identifier.');
        assert.equal(find('manual-input').value, 'invalid');
        find('manual-input').value = ' 04:a1:b2:c3 ';
        find('manual-input').dispatchEvent(enter());
        await settle();
        assert.equal(calls[1].nfc_uid, '04:a1:b2:c3');
        assert.equal(find('pending-input').value, '04A1B2C3');
        assert.equal(find('status').textContent, pendingMessage);
        find('scan-again').dispatchEvent(new Event('click'));
        await settle();
        assert.equal(find('manual-fields').hidden, true);
        assert.equal(find('input').disabled, false);
        find('manual-toggle').dispatchEvent(new Event('click'));
        find('manual-input').value = uid;
        find('manual-toggle').dispatchEvent(new Event('click'));
        assert.equal(find('manual-fields').hidden, true);
        assert.equal(find('manual-input').disabled, true);
        assert.equal(find('manual-input').value, '');
        assert.equal(find('input').value, '');
        assert.equal(find('manual-toggle').attributes['aria-expanded'], 'false');
        closes[0].dispatchEvent(new Event('click'));
        find('manual-input').dispatchEvent(enter());
        assert.equal(calls.length, 2);
        find('open').dispatchEvent(new Event('click'));
        assert.equal(find('manual-fields').hidden, true);
        assert.equal(find('input').disabled, false);
        assert.equal(find('status').textContent, 'Waiting for card…');
    } finally {
        cleanup();
        globalThis.fetch = originalFetch;
    }
});

test('a rejected reader scan clears its buffer before the next card', async () => {
    const originalFetch = globalThis.fetch;
    const calls = [];
    globalThis.fetch = async (url, options) => {
        calls.push(JSON.parse(options.body));
        return calls.length === 1 ? reply(422, { errors: { nfc_uid: ['Card is already linked.'] } }) : ready();
    };
    const { root, elements } = popup(pendingConfig);
    const cleanup = mountNfcRegistration(root);
    try {
        elements['[data-nfc-open]'].dispatchEvent(new Event('click'));
        const input = elements['[data-nfc-input]'];
        input.value = '0000000001';
        input.dispatchEvent(enter());
        await settle();
        assert.equal(input.value, '');
        assert.equal(input.disabled, false);
        input.value += uid;
        input.dispatchEvent(enter());
        await settle();
        assert.deepEqual(calls.map((call) => call.nfc_uid), ['0000000001', uid]);
        assert.equal(elements['[data-nfc-pending-input]'].value, uid);
    } finally {
        cleanup();
        globalThis.fetch = originalFetch;
    }
});

test('a server validation conflict retains the pending identifier and shows its error until removed', () => {
    const message = 'This NFC card is already linked to another beneficiary.';
    const { root, elements } = popup({ ...pendingConfig, nfcUid: uid, pendingError: message });
    const cleanup = mountNfcRegistration(root);
    try {
        assert.equal(elements['[data-nfc-pending-input]'].value, uid);
        assert.equal(elements['[data-nfc-pending-status]'].textContent, message);
        elements['[data-nfc-remove]'].dispatchEvent(new Event('click'));
        assert.equal(elements['[data-nfc-pending-input]'].value, '');
        assert.equal(elements['[data-nfc-pending-status]'].textContent, '');
    } finally {
        cleanup();
    }
});

test('a delayed native close event cannot close a newly reopened popup', async () => {
    const { root, elements, closes } = popup();
    const dialog = elements['[data-nfc-dialog]'];
    const openButton = elements['[data-nfc-open]'];
    dialog.deferClose = true;
    const cleanup = mountNfcRegistration(root);
    try {
        openButton.dispatchEvent(new Event('click'));
        closes[0].dispatchEvent(new Event('click'));
        openButton.dispatchEvent(new Event('click'));
        await settle();
        assert.equal(dialog.open, true);
        assert.equal(elements['[data-nfc-input]'].disabled, false);
    } finally {
        cleanup();
    }
});

test('popup focuses only its scan input, submits only its form, and removes capture on Close and Escape', async () => {
    const originalFetch = globalThis.fetch;
    let requests = 0;
    globalThis.fetch = async () => { requests++; return reply(200, { status: 'already_registered', nfc_uid: uid }); };
    const { root, elements, closes } = popup();
    const find = (name) => elements[`[data-nfc-${name}]`];
    const cleanup = mountNfcRegistration(root);
    try {
        assert.equal(find('input').disabled, true);
        find('open').dispatchEvent(new Event('click'));
        assert.equal(find('dialog').open, true);
        assert.equal(find('input').disabled, false);
        assert.ok(find('input').focusCount > 0);
        const outside = new Element();
        const typing = new Event('keydown', { cancelable: true });
        outside.dispatchEvent(typing);
        assert.equal(typing.defaultPrevented, false);
        assert.equal(requests, 0);
        find('input').value = uid;
        const submit = new Event('submit', { cancelable: true });
        find('scan-form').dispatchEvent(submit);
        assert.equal(submit.defaultPrevented, true);
        await settle();
        assert.equal(requests, 1);
        assert.equal(find('linked-uid').textContent, uid);
        closes[0].dispatchEvent(new Event('click'));
        assert.equal(find('dialog').open, false);
        assert.equal(find('input').disabled, true);
        assert.equal(find('input').value, '');
        find('scan-form').dispatchEvent(new Event('submit', { cancelable: true }));
        assert.equal(requests, 1);
        find('open').dispatchEvent(new Event('click'));
        const escape = new Event('cancel', { cancelable: true });
        find('dialog').dispatchEvent(escape);
        assert.equal(escape.defaultPrevented, true);
        assert.equal(find('input').disabled, true);
        assert.equal(find('dialog').open, false);
        cleanup();
        find('open').dispatchEvent(new Event('click'));
        assert.equal(find('dialog').open, false);
    } finally {
        cleanup();
        globalThis.fetch = originalFetch;
    }
});
