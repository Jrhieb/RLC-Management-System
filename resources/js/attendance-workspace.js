// A single queue is shared by manual check-ins and reader-independent UID inputs.
// A request with an uncertain outcome stays at the head until explicitly retried.
export class AttendanceScanQueue {
    constructor(process, onChange = () => {}) {
        this.process = process;
        this.onChange = onChange;
        this.items = [];
        this.running = false;
        this.paused = false;
        this.task = Promise.resolve();
    }

    enqueue(job) {
        this.items.push(Object.freeze({
            ...job,
            activity: Object.freeze({ ...job.activity }),
        }));
        this.onChange(this);
        this.drain();
    }

    resume() {
        this.paused = false;
        this.drain();
    }

    drain() {
        if (this.running || this.paused || !this.items.length) return;
        this.running = true;
        this.onChange(this);
        this.task = (async () => {
            try {
                while (this.items.length && !this.paused) {
                    let outcome;
                    try {
                        outcome = await this.process(this.items[0]);
                    } catch {
                        outcome = { retry: true };
                    }
                    if (outcome?.retry) this.paused = true;
                    else this.items.shift();
                    this.onChange(this);
                }
            } finally {
                this.running = false;
                this.onChange(this);
            }
        })();
    }

    async waitForIdle() {
        await this.task;
    }
}

export class AttendanceScanSound {
    constructor(createContext = () => {
        const Context = globalThis.AudioContext || globalThis.webkitAudioContext;
        return Context ? new Context() : null;
    }) {
        this.createContext = createContext;
        this.muted = false;
        this.disposed = false;
    }

    initialize() {
        if (this.disposed) return;
        try {
            this.context ||= this.createContext();
            if (this.context?.state === 'suspended') {
                Promise.resolve(this.context.resume()).catch(() => {});
            }
        } catch { /* Audio is optional; visual feedback remains available. */ }
    }

    play(result) {
        if (this.muted || this.disposed || this.context?.state !== 'running') return;
        const notes = { created: [660, 880], duplicate: [440], error: [220, 160] }[result];
        if (!notes) return;
        try {
            const start = this.context.currentTime;
            notes.forEach((frequency, index) => {
                const oscillator = this.context.createOscillator();
                const gain = this.context.createGain();
                const at = start + index * 0.11;
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(frequency, at);
                gain.gain.setValueAtTime(0, at);
                gain.gain.linearRampToValueAtTime(0.06, at + 0.01);
                gain.gain.exponentialRampToValueAtTime(0.001, at + 0.09);
                oscillator.connect(gain);
                gain.connect(this.context.destination);
                oscillator.onended = () => {
                    try { oscillator.disconnect(); gain.disconnect(); } catch {}
                };
                oscillator.start(at);
                oscillator.stop(at + 0.1);
            });
        } catch { /* A blocked or unavailable audio device must never stop scanning. */ }
    }

    destroy() {
        this.disposed = true;
        try { Promise.resolve(this.context?.close()).catch(() => {}); } catch {}
    }
}

export class AttendanceScanFeedback {
    constructor({
        render, ready, focus, sound, canDismiss = () => true,
        setTimer = (fn, delay) => globalThis.setTimeout(fn, delay),
        clearTimer = (timer) => globalThis.clearTimeout(timer),
    }) {
        Object.assign(this, { render, ready, focus, sound, canDismiss, setTimer, clearTimer });
        this.id = 0;
        this.disposed = false;
    }

    show(job, result, message, payload = null) {
        if (this.disposed) return;
        this.cancelDismissal();
        this.current = { id: ++this.id, job, result, message };
        this.render(job, result, message, payload);
        if (result !== 'pending') {
            this.sound.play(result);
            this.focus(job);
            this.scheduleDismissal();
        }
    }

    cancelDismissal() {
        this.clearTimer(this.timer);
        this.timer = null;
    }

    scheduleDismissal() {
        this.cancelDismissal();
        if (this.disposed || !this.current || this.current.result === 'pending' || !this.canDismiss()) return;
        const id = this.current.id;
        this.timer = this.setTimer(() => {
            if (!this.disposed && this.current?.id === id && this.canDismiss()) this.dismiss();
        }, 4000);
    }

    dismiss() {
        this.cancelDismissal();
        this.current = null;
        this.id++;
        if (!this.disposed) this.ready();
    }

    destroy() {
        this.cancelDismissal();
        this.disposed = true;
        this.current = null;
        this.id++;
    }
}

function validationMessage(data) {
    // Only known validation messages are safe to repeat; never echo a UID or exception.
    const safe = new Set([
        'No beneficiary is linked to this card UID.',
        'This beneficiary is inactive or archived and cannot be checked in.',
        'No active beneficiary found with that ID.',
        'Enter a 10-digit card identifier or a hexadecimal UID with 8, 14, or 20 characters.',
        'The nfc uid field is required.',
        'The nfc uid field must be a string.',
        'The nfc uid field must not be greater than 64 characters.',
        'The beneficiary id field is required.',
        'The beneficiary id field must be a string.',
        'The beneficiary id field must not be greater than 50 characters.',
    ]);
    return Object.values(data?.errors || {}).flat().find((message) => safe.has(message))
        || 'Invalid UID or beneficiary ID. Check the value and try again.';
}

export function createWorkspace(root) {
    const config = JSON.parse(root.dataset.config);
    const find = (selector) => root.querySelector(selector);
    const listen = (target, event, handler, options = {}) => {
        target?.addEventListener(event, handler, { ...options, signal: lifetime.signal });
    };
    const lifetime = new AbortController();
    const selector = find('[data-activity-selector]');
    const uidInput = find('[name="nfc_uid"]');
    const manualInput = find('[name="beneficiary_id"]');
    const card = find('[data-profile-card]');
    const header = find('[data-card-header]');
    const notice = find('[data-workspace-notice]');
    const refreshButton = find('[data-refresh-records]');
    const searchInput = find('[name="search"]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        || find('[name="_token"]')?.value;
    let scanning = false;
    let search = config.search || '';
    let page = Number(new URL(location.href).searchParams.get('page')) || 1;
    let refreshRequest;
    let refreshVersion = 0;
    let drag;
    let positioned = false;
    const sound = new AttendanceScanSound();
    const mute = find('[data-scan-mute]');
    mute.checked = false;

    const selected = () => config.activity && selector.value === String(config.activity.id);
    function showNotice(message) {
        notice.textContent = message;
        notice.hidden = !message;
    }

    function controls(queue) {
        if (lifetime.signal.aborted) return;
        const pending = queue.items.length;
        selector.disabled = pending > 0;
        find('[data-activity-confirm]').disabled = pending > 0;
        find('[data-start-scanning]').disabled = !config.canRecord || !selected() || scanning || queue.paused;
        find('[data-stop-scanning]').disabled = !scanning;
        if (find('[data-uid-fields]')) {
            find('[data-uid-fields]').disabled = !config.canRecord || !selected();
            uidInput.readOnly = !scanning || queue.paused;
            find('[data-uid-submit]').disabled = !scanning || queue.paused;
        }
        if (find('[data-manual-submit]')) find('[data-manual-submit]').disabled = queue.paused || !selected();
        if (manualInput) manualInput.disabled = queue.paused || !selected();
        if (find('[data-retry-panel]')) find('[data-retry-panel]').hidden = !queue.paused;
        const status = find('[data-scanner-status]');
        if (!config.activity) status.textContent = 'Select and confirm an activity to enable scanning.';
        else if (!config.canRecord) status.textContent = 'You do not have permission to record attendance.';
        else if (queue.paused) status.textContent = 'Submission paused. Resolve the error and retry the retained submission.';
        else if (!selected()) status.textContent = 'Confirm the new activity with Use Activity before recording check-ins.';
        else if (scanning && feedback.current) status.textContent = feedback.current.message;
        else if (scanning && queue.running && pending) status.textContent = 'Checking card…';
        else status.textContent = (scanning
            ? 'Ready for next card.'
            : 'Scanning stopped. Start scanning to submit a Test UID.')
            + (pending ? ' ' + pending + ' submission(s) pending.' : '');
    }

    function viewportBounds() {
        const viewport = window.visualViewport;
        return {
            left: viewport?.offsetLeft || 0,
            top: viewport?.offsetTop || 0,
            width: viewport?.width || document.documentElement.clientWidth,
            height: viewport?.height || window.innerHeight,
        };
    }

    function clampCard(left, top) {
        const viewport = viewportBounds();
        card.style.maxHeight = Math.max(0, viewport.height - 24) + 'px';
        card.style.maxWidth = Math.max(0, viewport.width - 24) + 'px';
        const rect = card.getBoundingClientRect();
        const margin = 12;
        card.style.left = Math.max(viewport.left + margin,
            Math.min(left, viewport.left + viewport.width - rect.width - margin)) + 'px';
        card.style.top = Math.max(viewport.top + margin,
            Math.min(top, viewport.top + viewport.height - rect.height - margin)) + 'px';
        card.style.right = 'auto';
        card.style.bottom = 'auto';
        positioned = true;
    }

    function fitCard() {
        const viewport = viewportBounds();
        if (!card.hidden && (positioned || viewport.height < window.innerHeight || viewport.top || viewport.left)) {
            const rect = card.getBoundingClientRect();
            clampCard(rect.left, rect.top);
        }
    }

    function scheduleDismissal() {
        if (!drag && !card.hidden) feedback.scheduleDismissal();
    }

    function focusScanField(job) {
        if (!root.isConnected) return;
        const input = job.kind === 'uid' ? uidInput : manualInput;
        if (input && !input.disabled) input.focus({ preventScroll: true });
    }

    function renderCard(job, result, message, payload = null) {
        const photo = find('[data-profile-photo]');
        photo.hidden = true;
        photo.removeAttribute('src');
        find('[data-profile-initials]').hidden = false;
        const person = payload?.beneficiary;
        find('[data-profile-name]').textContent = person?.full_name || 'Awaiting beneficiary';
        find('[data-profile-initials]').textContent = person
            ? person.full_name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
            : '?';
        const time = typeof payload?.checked_in_at === 'string' ? payload.checked_in_at : '';
        find('[data-profile-time]').textContent = time;
        find('[data-profile-time]').hidden = !time;
        find('[data-profile-time-label]').hidden = !time;
        find('[data-profile-time-label]').textContent = result === 'duplicate'
            ? 'Original check-in time (PHT)' : 'Check-in time (PHT)';
        find('[data-profile-activity]').textContent = job.activity.title;
        find('[data-profile-activity-meta]').textContent = job.activity.date + ' · ' + job.activity.location;
        find('[data-card-title]').textContent = job.kind === 'uid' ? 'Test UID check-in' : 'Manual check-in';
        find('[data-card-result]').textContent = message;
        find('[data-card-icon]').textContent = { pending: '…', created: '✓', duplicate: '↺', error: '!' }[result];
        card.dataset.result = result;
        card.setAttribute('aria-busy', String(result === 'pending'));
        card.hidden = false;
        const announcement = [message, person?.full_name, time ? time + ' PHT' : ''].filter(Boolean).join('. ');
        find('[data-scan-announcement]').textContent = result === 'error' ? '' : announcement;
        find('[data-scan-error-announcement]').textContent = result === 'error' ? announcement : '';
        if (result === 'pending') find('[data-scanner-status]').textContent = message;
        if (person?.photo_url) {
            try {
                const url = new URL(person.photo_url, location.href);
                if (url.origin === location.origin) {
                    photo.src = url.href;
                    photo.hidden = false;
                    find('[data-profile-initials]').hidden = true;
                }
            } catch { /* Initials remain visible for an invalid photo URL. */ }
        }
        fitCard();
    }

    const feedback = new AttendanceScanFeedback({
        render: renderCard,
        ready: () => {
            card.hidden = true;
            controls(queue);
            if (scanning && !queue.paused && !queue.running) {
                find('[data-scan-announcement]').textContent = 'Ready for next card.';
                find('[data-scan-error-announcement]').textContent = '';
            }
        },
        focus: focusScanField,
        sound,
        canDismiss: () => !drag,
    });

    function recordsUrl() {
        const url = new URL(config.recordsUrl, location.href);
        url.searchParams.set('activity_id', config.activity.id);
        if (search) url.searchParams.set('search', search);
        if (page > 1) url.searchParams.set('page', page);
        return url;
    }

    function updateExport() {
        const link = find('[data-attendance-export]');
        if (!link) return;
        const url = new URL(config.exportUrl, location.href);
        if (search) url.searchParams.set('search', search);
        link.href = url.href;
    }

    async function refreshRecords() {
        if (!config.activity || lifetime.signal.aborted) return;
        refreshRequest?.abort();
        const request = new AbortController();
        refreshRequest = request;
        const version = ++refreshVersion;
        const timeout = setTimeout(() => request.abort(), 15000);
        try {
            const response = await fetch(recordsUrl(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: request.signal,
            });
            if (!response.ok) throw new Error('Records could not be loaded.');
            const data = await response.json();
            if (version !== refreshVersion || !root.isConnected) return;
            if (Number(data.activity_id) !== config.activity.id || typeof data.records_html !== 'string') {
                throw new Error('Unexpected activity records.');
            }
            find('[data-attendance-records]').innerHTML = data.records_html;
            for (const [key, value] of Object.entries(data.counts)) {
                const count = find('[data-attendance-count="' + key + '"]');
                if (count) count.textContent = Number(value).toLocaleString();
            }
            refreshButton.hidden = true;
            showNotice('');
            updateExport();
        } catch {
            if (version === refreshVersion && root.isConnected) {
                showNotice('Attendance records could not be refreshed. Use Refresh Records to try again.');
                refreshButton.hidden = false;
            }
        } finally {
            clearTimeout(timeout);
        }
    }

    async function process(job) {
        feedback.show(job, 'pending', job.kind === 'uid' ? 'Checking card…' : 'Checking beneficiary…');
        const request = new AbortController();
        const timeout = setTimeout(() => request.abort(), 30000);
        let response;
        let data;
        try {
            response = await fetch(job.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                body: JSON.stringify({ [job.kind === 'uid' ? 'nfc_uid' : 'beneficiary_id']: job.value }),
                signal: request.signal,
            });
            data = await response.json().catch(() => null);
        } catch {
            scanning = false;
            feedback.show(job, 'error', 'Connection error. Check-in is not confirmed. Retry this submission when connected.');
            return { retry: true };
        } finally {
            clearTimeout(timeout);
        }
        if (!response.ok) {
            if (response.status === 422) {
                feedback.show(job, 'error', validationMessage(data));
                await new Promise((resolve) => setTimeout(resolve, 450));
                return;
            }
            scanning = false;
            const message = {
                401: 'Your session has ended. Sign in again before retrying.',
                403: 'You do not have permission to record attendance for this activity.',
                419: 'Your session has expired. Reload and sign in before retrying.',
            }[response.status] || 'Server error. Check-in is not confirmed. Retry this submission.';
            feedback.show(job, 'error', message);
            return { retry: true };
        }
        if (!((response.status === 201 && data?.result === 'created')
            || (response.status === 200 && data?.result === 'duplicate'))
            || Number(data?.activity?.id) !== job.activity.id
            || typeof data?.beneficiary?.full_name !== 'string') {
            scanning = false;
            feedback.show(job, 'error', 'Unexpected server response. Check-in is not confirmed. Retry this submission.');
            return { retry: true };
        }
        feedback.show(job, data.result, data.result === 'created' ? 'Checked in successfully' : 'Already checked in', data);
        // Refresh failures do not turn a confirmed check-in into a failed submission.
        if (data.result === 'created') await refreshRecords();
        await new Promise((resolve) => setTimeout(resolve, 450));
    }

    const queue = new AttendanceScanQueue(process, controls);
    controls(queue);

    function submit(kind, value) {
        if (!config.canRecord || !selected() || queue.paused || (kind === 'uid' && !scanning)) return false;
        if (!String(value).trim()) {
            showNotice(kind === 'uid' ? 'Enter a Test UID.' : 'Enter a beneficiary ID.');
            return false;
        }
        queue.enqueue({ kind, value: String(value), url: kind === 'uid' ? config.uidUrl : config.manualUrl, activity: config.activity });
        return true;
    }

    listen(find('[data-start-scanning]'), 'click', () => {
        if (!config.canRecord || !selected() || queue.paused) return;
        sound.initialize();
        scanning = true;
        controls(queue);
        find('[data-scan-announcement]').textContent = find('[data-scanner-status]').textContent;
        uidInput?.focus();
    });
    listen(mute, 'change', () => {
        sound.muted = mute.checked;
        if (!sound.muted && scanning) sound.initialize();
        if (scanning || queue.paused) uidInput?.focus({ preventScroll: true });
    });
    listen(find('[data-stop-scanning]'), 'click', () => {
        scanning = false;
        controls(queue);
    });
    listen(selector, 'change', () => {
        scanning = false;
        controls(queue);
    });
    listen(find('[data-activity-form]'), 'submit', (event) => {
        if (queue.items.length) event.preventDefault();
    });
    listen(find('[data-uid-form]'), 'submit', (event) => {
        event.preventDefault();
        if (submit('uid', uidInput.value)) { uidInput.value = ''; uidInput.focus(); }
    });
    listen(find('[data-manual-form]'), 'submit', (event) => {
        event.preventDefault();
        if (submit('manual', manualInput.value)) manualInput.value = '';
    });
    // Future reader adapters can dispatch this event. Until confirmed, all UIDs
    // use the existing Test UID endpoint and remain source=nfc_test.
    listen(window, 'rlcp:attendance-uid', (event) => {
        if (typeof event.detail?.uid === 'string') {
            const accepted = submit('uid', event.detail.uid);
            window.dispatchEvent(new CustomEvent('rlcp:attendance-uid-accepted', { detail: { accepted } }));
        }
    });
    listen(find('[data-retry-scan]'), 'click', () => queue.resume());
    listen(find('[data-attendance-search]'), 'submit', (event) => {
        event.preventDefault();
        search = searchInput.value.trim();
        page = 1;
        updateExport();
        refreshRecords();
    });
    listen(find('[data-clear-search]'), 'click', (event) => {
        event.preventDefault();
        searchInput.value = '';
        search = '';
        page = 1;
        updateExport();
        refreshRecords();
    });
    listen(find('[data-attendance-records]'), 'click', (event) => {
        const link = event.target.closest('[data-attendance-pagination] a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        page = Number(new URL(link.href).searchParams.get('page')) || 1;
        refreshRecords();
    });
    listen(refreshButton, 'click', () => refreshRecords());
    listen(find('[data-profile-photo]'), 'error', () => {
        find('[data-profile-photo]').hidden = true;
        find('[data-profile-initials]').hidden = false;
    });
    listen(find('[data-card-close]'), 'click', () => {
        const job = feedback.current?.job;
        feedback.dismiss();
        if (job) focusScanField(job);
    });
    listen(find('[data-card-toggle]'), 'click', (event) => {
        const body = find('[data-card-body]');
        body.hidden = !body.hidden;
        event.currentTarget.textContent = body.hidden ? 'Expand' : 'Collapse';
        event.currentTarget.setAttribute('aria-expanded', String(!body.hidden));
        fitCard();
        scheduleDismissal();
    });
    listen(header, 'pointerdown', (event) => {
        if (event.target.closest('button') || event.button !== 0) return;
        const rect = card.getBoundingClientRect();
        drag = { x: event.clientX - rect.left, y: event.clientY - rect.top, pointerId: event.pointerId };
        feedback.cancelDismissal();
        header.setPointerCapture(event.pointerId);
        event.preventDefault();
    });
    listen(window, 'pointermove', (event) => {
        if (drag?.pointerId === event.pointerId) clampCard(event.clientX - drag.x, event.clientY - drag.y);
    });
    function endDrag() {
        if (!drag) return;
        drag = null;
        scheduleDismissal();
    }
    listen(window, 'pointerup', endDrag);
    listen(window, 'pointercancel', endDrag);
    listen(window, 'blur', endDrag);
    listen(header, 'keydown', (event) => {
        if (event.target !== header || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        const rect = card.getBoundingClientRect();
        const step = event.shiftKey ? 40 : 10;
        clampCard(rect.left + ({ ArrowLeft: -step, ArrowRight: step }[event.key] || 0),
            rect.top + ({ ArrowUp: -step, ArrowDown: step }[event.key] || 0));
        scheduleDismissal();
    });
    listen(window, 'resize', fitCard);
    listen(window.visualViewport, 'resize', fitCard);
    listen(window.visualViewport, 'scroll', fitCard);
    listen(window, 'beforeunload', (event) => {
        if (queue.items.length) { event.preventDefault(); event.returnValue = ''; }
    });
    listen(document, 'livewire:navigate', (event) => {
        if (queue.items.length) {
            event.preventDefault();
            showNotice('Finish or retry the pending submissions before leaving this activity.');
        }
    });

    return () => {
        lifetime.abort();
        refreshRequest?.abort();
        feedback.destroy();
        sound.destroy();
    };
}

if (typeof document !== 'undefined') {
    let cleanup;
    let activeRoot;
    const initialize = () => {
        const root = document.querySelector('[data-attendance-workspace]');
        if (root === activeRoot) return;
        cleanup?.();
        activeRoot = root;
        cleanup = root ? createWorkspace(root) : null;
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
    document.addEventListener('livewire:navigated', initialize);
}
