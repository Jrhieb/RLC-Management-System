import assert from 'node:assert/strict';
import test from 'node:test';
import { AttendanceScanFeedback, AttendanceScanSound, createWorkspace } from '../../resources/js/attendance-workspace.js';

class Clock {
    now = 0;
    next = 0;
    active = new Map();
    history = new Map();
    set = (fn, delay) => {
        const id = ++this.next;
        const timer = { fn, delay, at: this.now + delay };
        this.active.set(id, timer);
        this.history.set(id, timer);
        return id;
    };
    clear = (id) => this.active.delete(id);
    tick(milliseconds) {
        const until = this.now + milliseconds;
        while (true) {
            const due = [...this.active].filter(([, timer]) => timer.at <= until)
                .sort((a, b) => a[1].at - b[1].at)[0];
            if (!due) break;
            this.now = due[1].at;
            this.active.delete(due[0]);
            due[1].fn();
        }
        this.now = until;
    }
}

class Element {
    value = '';
    textContent = '';
    hidden = false;
    disabled = false;
    readOnly = false;
    checked = false;
    dataset = {};
    style = {};
    attributes = new Map();
    listeners = new Map();
    addEventListener(type, listener, options = {}) {
        const listeners = this.listeners.get(type) || [];
        listeners.push({ listener, options });
        this.listeners.set(type, listeners);
    }
    emit(type, properties = {}) {
        const event = { target: this, currentTarget: this, preventDefault() {}, ...properties };
        for (const { listener, options } of this.listeners.get(type) || []) {
            if (!options.signal?.aborted) listener(event);
        }
    }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); if (name === 'src') this.src = ''; }
    getBoundingClientRect() { return { left: 100, top: 100, width: 370, height: 300 }; }
    closest() { return null; }
    focus() {
        if (!this.disabled && !this.fieldset?.disabled) this.document.activeElement = this;
    }
}

class Audio {
    state = 'running';
    currentTime = 0;
    destination = {};
    notes = [];
    closed = false;
    createOscillator() {
        return {
            frequency: { setValueAtTime: (value) => this.notes.push(value) },
            connect() {}, disconnect() {}, start() {}, stop() {},
        };
    }
    createGain() {
        return {
            gain: { setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {} },
            connect() {}, disconnect() {},
        };
    }
    close() { this.closed = true; return Promise.resolve(); }
}

const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
const response = (status, data) => ({ status, ok: status >= 200 && status < 300, json: async () => data });
const result = (kind = 'created', overrides = {}) => response(kind === 'created' ? 201 : 200, {
    result: kind,
    activity: { id: 7, title: 'TEST NFC Activity' },
    beneficiary: {
        full_name: 'Synthetic NFC Tester',
        beneficiary_id: 'TEST-RECORD',
        status: 'active',
        photo_url: '/synthetic-photo',
    },
    checked_in_at: 'Oct 22, 2026 - 9:30 AM',
    source: 'nfc_test',
    ...overrides,
});

function workspace(t, replies, { audioBlocked = false } = {}) {
    const clock = new Clock();
    const document = new Element();
    const window = new Element();
    document.documentElement = { clientWidth: 1200, clientHeight: 800 };
    window.innerWidth = 1200;
    window.innerHeight = 800;
    const selectors = [
        '[data-activity-selector]', '[name="nfc_uid"]', '[name="beneficiary_id"]',
        '[data-profile-card]', '[data-card-header]', '[data-workspace-notice]', '[data-refresh-records]',
        '[name="search"]', '[data-scan-mute]', '[data-activity-confirm]', '[data-start-scanning]',
        '[data-stop-scanning]', '[data-uid-fields]', '[data-uid-submit]', '[data-manual-submit]',
        '[data-retry-panel]', '[data-scanner-status]', '[data-profile-photo]', '[data-profile-initials]',
        '[data-profile-name]', '[data-profile-time]', '[data-profile-time-label]', '[data-profile-activity]',
        '[data-profile-activity-meta]', '[data-card-title]', '[data-card-result]', '[data-card-icon]',
        '[data-scan-announcement]', '[data-scan-error-announcement]', '[data-attendance-export]',
        '[data-attendance-records]', '[data-activity-form]', '[data-uid-form]', '[data-manual-form]',
        '[data-retry-scan]', '[data-attendance-search]', '[data-clear-search]', '[data-card-close]',
        '[data-card-toggle]', '[data-card-body]',
    ];
    const nodes = new Map(selectors.map((selector) => [selector, new Element()]));
    for (const element of nodes.values()) element.document = document;
    const find = (selector) => nodes.get(selector);
    find('[data-activity-selector]').value = '7';
    find('[data-profile-card]').hidden = true;
    find('[name="nfc_uid"]').fieldset = find('[data-uid-fields]');
    const root = new Element();
    root.isConnected = true;
    root.querySelector = find;
    root.dataset.config = JSON.stringify({
        canRecord: true,
        activity: { id: 7, title: 'TEST NFC Activity', date: 'Oct 22, 2026', location: 'Synthetic venue' },
        uidUrl: 'http://attendance.test/activities/7/attendance/nfc',
        manualUrl: 'http://attendance.test/activities/7/attendance',
        recordsUrl: 'http://attendance.test/attendance',
        exportUrl: 'http://attendance.test/activities/7/attendance/export',
    });
    document.querySelector = (selector) => selector === 'meta[name="csrf-token"]' ? { content: 'synthetic-csrf' } : null;
    const requests = [];
    const audio = [];
    const globals = { document, window, location: new URL('http://attendance.test/attendance?activity_id=7'),
        setTimeout: clock.set, clearTimeout: clock.clear,
        AudioContext: class extends Audio {
            constructor() {
                super();
                if (audioBlocked) throw new Error('Synthetic audio failure');
                audio.push(this);
            }
        },
        fetch: async (url, options) => {
            requests.push({ url: String(url), options });
            if (options.method === 'POST') {
                assert.ok(replies.length > 0, 'Every POST must have a synthetic mocked response');
                const next = replies.shift();
                if (next instanceof Error) throw next;
                return await next;
            }
            return response(200, { activity_id: 7, records_html: '<p>Synthetic records</p>', counts: {} });
        },
    };
    const saved = new Map(Object.keys(globals).map((key) => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    for (const [key, value] of Object.entries(globals)) Object.defineProperty(globalThis, key, { value, writable: true, configurable: true });
    const cleanup = createWorkspace(root);
    t.after(() => {
        cleanup();
        for (const [key, descriptor] of saved) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        }
    });
    const scan = (uid = '00' + '1'.repeat(8)) => {
        find('[name="nfc_uid"]').value = uid;
        find('[data-uid-form]').emit('submit');
    };
    return { clock, document, root, find, scan, audio, cleanup, requests,
        start: () => find('[data-start-scanning]').emit('click'),
        posts: () => requests.filter(({ options }) => options.method === 'POST'),
    };
}

test('submitting shows Checking card without claiming success or changing the UID string', async (t) => {
    let finish;
    const h = workspace(t, [new Promise((resolve) => { finish = resolve; })]);
    h.start();
    const uid = '00' + 'aB'.repeat(6);
    h.scan(uid);
    assert.equal(h.find('[data-card-result]').textContent, 'Checking card…');
    assert.equal(h.find('[data-profile-card]').dataset.result, 'pending');
    assert.equal(h.find('[data-profile-card]').attributes.get('aria-busy'), 'true');
    assert.equal(JSON.parse(h.posts()[0].options.body).nfc_uid === uid, true);
    assert.equal(h.find('[name="nfc_uid"]').value, '');
    assert.equal(h.audio[0].notes.length, 0);
    finish(result());
    await flush();
    h.clock.tick(450);
    await flush();
});

test('a confirmed save shows a green check, returned name/photo, polite feedback, focus, then Ready', async (t) => {
    const h = workspace(t, [result()]);
    h.start();
    h.scan();
    h.document.activeElement = h.find('[name="search"]');
    await flush();
    assert.equal(h.find('[data-profile-card]').dataset.result, 'created');
    assert.equal(h.find('[data-card-icon]').textContent, '✓');
    assert.equal(h.find('[data-card-result]').textContent, 'Checked in successfully');
    assert.equal(h.find('[data-profile-name]').textContent, 'Synthetic NFC Tester');
    assert.equal(h.find('[data-profile-photo]').src, 'http://attendance.test/synthetic-photo');
    assert.equal(h.find('[data-profile-photo]').hidden, false);
    assert.match(h.find('[data-scan-announcement]').textContent, /Checked in successfully.*Synthetic NFC Tester/);
    assert.equal(h.find('[data-scan-error-announcement]').textContent, '');
    assert.equal(h.find('[data-profile-card]').attributes.get('aria-busy'), 'false');
    assert.equal(h.document.activeElement, h.find('[name="nfc_uid"]'));
    assert.ok(h.audio[0].notes.length > 0);
    h.clock.tick(450);
    await flush();
    assert.equal(h.find('[data-profile-card]').hidden, false);
    assert.equal(h.find('[data-scanner-status]').textContent, 'Checked in successfully');
    h.clock.tick(3550);
    assert.equal(h.find('[data-profile-card]').hidden, true);
    assert.equal(h.find('[data-scanner-status]').textContent, 'Ready for next card.');
});

test('duplicate uses the original returned PHT time and does not refresh records', async (t) => {
    const h = workspace(t, [result('duplicate')]);
    h.start();
    h.scan();
    await flush();
    assert.equal(h.find('[data-profile-card]').dataset.result, 'duplicate');
    assert.equal(h.find('[data-card-icon]').textContent, '↺');
    assert.equal(h.find('[data-card-result]').textContent, 'Already checked in');
    assert.equal(h.find('[data-profile-time]').textContent, 'Oct 22, 2026 - 9:30 AM');
    assert.equal(h.find('[data-profile-time-label]').textContent, 'Original check-in time (PHT)');
    assert.match(h.find('[data-scan-announcement]').textContent, /9:30 AM PHT/);
    assert.equal(h.requests.length, 1);
    assert.equal(h.document.activeElement, h.find('[name="nfc_uid"]'));
});

test('missing time is omitted and an external or missing photo uses initials', async (t) => {
    const h = workspace(t, [result('duplicate', { checked_in_at: null,
        beneficiary: { full_name: 'Synthetic NFC Tester', photo_url: 'https://external.invalid/photo' } })]);
    h.start();
    h.scan();
    await flush();
    assert.equal(h.find('[data-card-result]').textContent, 'Already checked in');
    assert.equal(h.find('[data-profile-time]').hidden, true);
    assert.equal(h.find('[data-profile-time-label]').hidden, true);
    assert.equal(h.find('[data-profile-photo]').hidden, true);
    assert.equal(h.find('[data-profile-initials]').textContent, 'SN');
});

test('known rejection is assertive, returns focus and permits the next card', async (t) => {
    const message = 'No beneficiary is linked to this card UID.';
    const h = workspace(t, [response(422, { errors: { nfc_uid: [message] } }), result()]);
    h.start();
    h.scan();
    await flush();
    assert.equal(h.find('[data-profile-card]').dataset.result, 'error');
    assert.equal(h.find('[data-card-icon]').textContent, '!');
    assert.equal(h.find('[data-scan-error-announcement]').textContent, message);
    assert.equal(h.find('[data-scan-announcement]').textContent, '');
    assert.equal(h.document.activeElement, h.find('[name="nfc_uid"]'));
    h.clock.tick(450);
    await flush();
    h.clock.tick(3550);
    assert.equal(h.find('[data-scanner-status]').textContent, 'Ready for next card.');
    h.scan();
    await flush();
    assert.equal(h.find('[data-card-result]').textContent, 'Checked in successfully');
});

test('unrecognized validation diagnostics are replaced with a safe fallback', async (t) => {
    const h = workspace(t, [response(422, { errors: { nfc_uid: ['Synthetic private exception detail'] } })]);
    h.start();
    h.scan();
    await flush();
    assert.equal(h.find('[data-card-result]').textContent, 'Invalid UID or beneficiary ID. Check the value and try again.');
    assert.doesNotMatch(h.find('[data-scan-error-announcement]').textContent, /private|exception/i);
});

test('network failure retains queued scans for explicit retry and never announces Ready while paused', async (t) => {
    const h = workspace(t, [new Error('Synthetic private transport detail'), result(), result('duplicate')]);
    h.start();
    h.scan();
    h.scan();
    await flush();
    assert.equal(h.posts().length, 1);
    assert.equal(h.find('[data-retry-panel]').hidden, false);
    assert.match(h.find('[data-card-result]').textContent, /Connection error.*not confirmed/);
    assert.doesNotMatch(h.find('[data-card-result]').textContent, /private transport/);
    assert.equal(h.document.activeElement, h.find('[name="nfc_uid"]'));
    assert.equal(h.find('[name="nfc_uid"]').readOnly, true);
    h.clock.tick(4000);
    await flush();
    assert.match(h.find('[data-scanner-status]').textContent, /^Submission paused/);
    assert.equal(h.posts().length, 1);
    h.find('[data-retry-scan]').emit('click');
    await flush();
    h.clock.tick(450);
    await flush();
    assert.equal(h.posts().length, 3);
    assert.equal(h.find('[data-card-result]').textContent, 'Already checked in');
    assert.equal(h.document.activeElement, h.find('[name="nfc_uid"]'));
});

for (const [label, reply] of [
    ['server exception', response(500, { message: 'Synthetic private exception detail', exception: 'PrivateClass' })],
    ['malformed confirmation', response(200, { result: 'created', activity: { id: 7 } })],
    ['unconfirmed success status', { ...result(), status: 202 }],
]) {
    test(label + ' shows generic error without success or raw diagnostics', async (t) => {
        const h = workspace(t, [reply]);
        h.start();
        h.scan();
        await flush();
        assert.equal(h.find('[data-profile-card]').dataset.result, 'error');
        assert.equal(h.find('[data-retry-panel]').hidden, false);
        assert.doesNotMatch(h.find('[data-card-result]').textContent, /private|PrivateClass|successfully/i);
        assert.equal(h.document.activeElement, h.find('[name="nfc_uid"]'));
    });
}

test('mute toggle suppresses only sounds and preserves focus and scanning', async (t) => {
    const h = workspace(t, [result(), result('duplicate')]);
    assert.equal(h.audio.length, 0);
    h.start();
    h.find('[data-scan-mute]').checked = true;
    h.find('[data-scan-mute]').emit('change');
    h.scan();
    await flush();
    assert.equal(h.find('[data-card-result]').textContent, 'Checked in successfully');
    assert.equal(h.audio[0].notes.length, 0);
    h.clock.tick(450);
    await flush();
    h.find('[data-scan-mute]').checked = false;
    h.find('[data-scan-mute]').emit('change');
    assert.equal(h.document.activeElement, h.find('[name="nfc_uid"]'));
    h.scan();
    await flush();
    assert.ok(h.audio[0].notes.length > 0);
    assert.equal(h.find('[data-card-result]').textContent, 'Already checked in');
});

test('blocked audio initialization leaves save feedback, queue and focus working', async (t) => {
    const h = workspace(t, [result()], { audioBlocked: true });
    h.start();
    h.scan();
    await flush();
    assert.equal(h.find('[data-card-result]').textContent, 'Checked in successfully');
    assert.equal(h.document.activeElement, h.find('[name="nfc_uid"]'));
    h.clock.tick(450);
    await flush();
    h.clock.tick(3550);
    assert.equal(h.find('[data-scanner-status]').textContent, 'Ready for next card.');
});

test('rapid scans stay ordered and an older result timer cannot hide the newer card', async (t) => {
    const h = workspace(t, [result(), result('duplicate')]);
    h.start();
    h.scan('00' + '2'.repeat(8));
    h.scan('00' + '3'.repeat(8));
    await flush();
    assert.equal(h.posts().length, 1);
    const oldTimer = [...h.clock.history.values()].find((timer) => timer.delay === 4000);
    h.clock.tick(450);
    await flush();
    assert.equal(h.posts().length, 2);
    assert.equal(h.find('[data-card-result]').textContent, 'Already checked in');
    oldTimer.fn();
    assert.equal(h.find('[data-profile-card]').hidden, false);
    assert.equal(h.find('[data-card-result]').textContent, 'Already checked in');
    h.clock.tick(3550);
    await flush();
    assert.equal(h.find('[data-profile-card]').hidden, false);
    h.clock.tick(450);
    assert.equal(h.find('[data-profile-card]').hidden, true);
    assert.equal(h.posts().length, 2);
    const values = h.posts().map(({ options }) => JSON.parse(options.body).nfc_uid);
    assert.equal(values[0] === '00' + '2'.repeat(8), true);
    assert.equal(values[1] === '00' + '3'.repeat(8), true);
});

test('all supported UID lengths and separators pass through as strings without normalization', async (t) => {
    const values = [
        '00' + '1'.repeat(8),
        '00' + 'a'.repeat(6),
        '00' + 'b'.repeat(12),
        '00' + 'c'.repeat(18),
        ' 00 : aa - BB : cc ',
    ];
    const h = workspace(t, values.map(() => result('duplicate')));
    h.start();
    for (const value of values) {
        h.scan(value);
        await flush();
        h.clock.tick(450);
        await flush();
    }
    assert.equal(h.posts().length, values.length);
    const payloads = h.posts().map(({ options }) => JSON.parse(options.body).nfc_uid);
    assert.equal(payloads.every((value, index) => typeof value === 'string' && value === values[index]), true);
});

test('teardown clears result timers, closes audio and ignores late timer callbacks', async (t) => {
    const h = workspace(t, [result()]);
    h.start();
    h.scan();
    await flush();
    const oldTimer = [...h.clock.history.values()].find((timer) => timer.delay === 4000);
    h.cleanup();
    h.root.isConnected = false;
    h.find('[data-card-result]').textContent = 'Detached workspace';
    oldTimer.fn();
    assert.equal(h.find('[data-card-result]').textContent, 'Detached workspace');
    assert.equal([...h.clock.active.values()].some((timer) => timer.delay === 4000), false);
    assert.equal(h.audio[0].closed, true);
});

test('audio outcomes have distinct short tones and audio failures never escape', async () => {
    const audio = new Audio();
    const sound = new AttendanceScanSound(() => audio);
    sound.initialize();
    const patterns = [];
    for (const outcome of ['created', 'duplicate', 'error']) {
        audio.notes = [];
        sound.play(outcome);
        patterns.push(audio.notes.join(','));
    }
    assert.equal(new Set(patterns).size, 3);
    assert.ok(patterns.every(Boolean));
    sound.muted = true;
    audio.notes = [];
    sound.play('created');
    assert.equal(audio.notes.length, 0);
    sound.muted = false;
    audio.createOscillator = () => { throw new Error('Synthetic audio-device failure'); };
    assert.doesNotThrow(() => sound.play('error'));
    sound.destroy();
    assert.equal(audio.closed, true);
    const blocked = new AttendanceScanSound(() => ({
        state: 'suspended',
        resume: () => Promise.reject(new Error('Synthetic browser audio policy')),
        close: () => Promise.reject(new Error('Synthetic audio close failure')),
    }));
    assert.doesNotThrow(() => blocked.initialize());
    assert.doesNotThrow(() => blocked.play('created'));
    assert.doesNotThrow(() => blocked.destroy());
    await flush();
});

test('new pending scans and teardown invalidate older dismissal callbacks', () => {
    const clock = new Clock();
    let ready = 0;
    const rendered = [];
    const feedback = new AttendanceScanFeedback({
        render: (_, state) => rendered.push(state), ready: () => ready++,
        focus() {}, sound: { play() {} }, setTimer: clock.set, clearTimer: clock.clear,
    });
    feedback.show({}, 'created', 'Checked in successfully');
    const first = [...clock.history.values()][0];
    feedback.show({}, 'pending', 'Checking card…');
    first.fn();
    assert.equal(ready, 0);
    assert.equal(clock.active.size, 0);
    feedback.show({}, 'duplicate', 'Already checked in');
    const latest = [...clock.history.values()].at(-1);
    feedback.destroy();
    latest.fn();
    feedback.show({}, 'error', 'Ignored after teardown');
    assert.equal(ready, 0);
    assert.deepEqual(rendered, ['created', 'pending', 'duplicate']);
    assert.equal(clock.active.size, 0);
});

test('dragging the existing profile card postpones dismissal until it is released', () => {
    const clock = new Clock();
    let dragging = true;
    let ready = 0;
    const feedback = new AttendanceScanFeedback({
        render() {}, ready: () => ready++, focus() {}, sound: { play() {} },
        canDismiss: () => !dragging, setTimer: clock.set, clearTimer: clock.clear,
    });
    feedback.show({}, 'created', 'Checked in successfully');
    clock.tick(5000);
    assert.equal(ready, 0);
    dragging = false;
    feedback.scheduleDismissal();
    clock.tick(3999);
    assert.equal(ready, 0);
    clock.tick(1);
    assert.equal(ready, 1);
});

test('native browser timer APIs retain their Window receiver', (t) => {
    const clock = new Clock();
    t.mock.method(globalThis, 'setTimeout', function (fn, delay) {
        assert.equal(this === globalThis, true);
        return clock.set(fn, delay);
    });
    t.mock.method(globalThis, 'clearTimeout', function (id) {
        assert.equal(this === globalThis, true);
        clock.clear(id);
    });
    let ready = 0;
    const feedback = new AttendanceScanFeedback({
        render() {}, ready: () => ready++, focus() {}, sound: { play() {} },
    });
    feedback.show({}, 'created', 'Checked in successfully');
    clock.tick(4000);
    assert.equal(ready, 1);
    feedback.destroy();
});

test('manual entry and UID scans continue to share one ordered request queue', async (t) => {
    const h = workspace(t, [result(), result('duplicate')]);
    h.start();
    h.find('[name="beneficiary_id"]').value = 'SYNTHETIC-RECORD';
    h.find('[data-manual-form]').emit('submit');
    h.scan();
    await flush();
    assert.equal(h.posts().length, 1);
    assert.deepEqual(Object.keys(JSON.parse(h.posts()[0].options.body)), ['beneficiary_id']);
    assert.equal(h.document.activeElement, h.find('[name="beneficiary_id"]'));
    h.clock.tick(450);
    await flush();
    assert.equal(h.posts().length, 2);
    assert.deepEqual(Object.keys(JSON.parse(h.posts()[1].options.body)), ['nfc_uid']);
    assert.equal(h.document.activeElement, h.find('[name="nfc_uid"]'));
});
