const pendingCardMessage = 'Card ready — save the beneficiary to complete registration.';

export class NfcRegistration {
    constructor(config, { request = (...args) => globalThis.fetch(...args), onChange = () => {} } = {}) {
        this.config = config;
        this.request = request;
        this.onChange = onChange;
        this.pendingMode = config.mode === 'pending';
        this.linkedUid = this.pendingMode ? null : config.nfcUid ?? null;
        this.pendingUid = this.pendingMode ? config.nfcUid ?? null : null;
        this.pendingError = config.pendingError ?? '';
        this.isOpen = false;
        this.state = 'idle';
        this.message = '';
        this.candidate = null;
        this.expectedUid = null;
        this.version = 0;
        this.checkRequest = null;
    }

    get busy() {
        return this.state === 'checking' || this.state === 'saving';
    }

    get cardUid() {
        return this.pendingMode ? this.pendingUid : this.linkedUid;
    }

    get scanning() {
        return this.isOpen && (this.state === 'idle' || this.state === 'error');
    }

    open() {
        if (this.busy) return false;
        this.isOpen = true;
        this.reset();
        return true;
    }

    reset() {
        if (this.busy) return;
        this.candidate = null;
        this.expectedUid = null;
        this.state = 'idle';
        this.message = 'Waiting for card…';
        this.onChange(this);
    }

    close() {
        this.isOpen = false;
        this.candidate = null;
        this.expectedUid = null;
        // A check is safe to cancel. Once a save is sent, let its result update
        // the linked card even when the popup has been closed.
        if (this.state !== 'saving') {
            this.version++;
            this.checkRequest?.abort();
            this.checkRequest = null;
            this.state = 'idle';
            this.message = '';
        }
        this.onChange(this);
    }

    async submit(value) {
        if (!this.scanning) return;
        const candidate = value.trim();
        if (!candidate) return;
        this.candidate = candidate;
        const version = ++this.version;
        const controller = new AbortController();
        this.checkRequest = controller;
        this.state = 'checking';
        this.message = 'Checking NFC card...';
        this.onChange(this);

        try {
            const result = await this.send({ nfc_uid: candidate, check_only: true }, controller.signal);
            if (version !== this.version || !this.isOpen) return;
            this.checkRequest = null;
            if (!this.pendingMode && result.response.ok && result.data.status === 'ready') {
                await this.save(candidate, version);
            } else {
                this.apply(result, false);
            }
        } catch (error) {
            if (version !== this.version || error.name === 'AbortError') return;
            this.fail('Could not check the card. Please try again.');
        } finally {
            if (this.checkRequest === controller) this.checkRequest = null;
        }
    }

    async confirm() {
        if (this.pendingMode || !this.isOpen || this.state !== 'confirmation') return;
        await this.save(this.candidate, ++this.version, this.expectedUid);
    }

    async save(candidate, version, expectedUid = null) {
        if (this.pendingMode) return;
        this.state = 'saving';
        this.message = 'Saving NFC card...';
        this.onChange(this);
        try {
            const result = await this.send({
                nfc_uid: candidate,
                confirm_replace: expectedUid !== null,
                expected_nfc_uid: expectedUid,
            });
            if (version !== this.version) return;
            this.apply(result, true);
        } catch {
            if (version !== this.version) return;
            this.fail('Could not confirm the save. Tap the same card again to check its registration.');
        }
    }

    async send(payload, signal) {
        const response = await this.request(this.config.url, {
            method: this.config.method ?? (this.pendingMode ? 'POST' : 'PUT'),
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.config.token,
            },
            body: JSON.stringify(payload),
            signal,
        });
        return { response, data: await response.json() };
    }

    apply({ response, data }, saving) {
        if (this.pendingMode && response.ok && data.status === 'ready' && typeof data.nfc_uid === 'string') {
            this.pendingUid = data.nfc_uid;
            this.pendingError = '';
            this.state = 'ready';
            this.message = pendingCardMessage;
        } else if (!this.pendingMode && response.ok && typeof data.nfc_uid === 'string'
            && (data.status === 'already_registered' || (saving && data.status === 'registered'))) {
            this.linkedUid = data.nfc_uid;
            this.state = data.status === 'registered' ? 'success' : 'already';
            this.message = data.status === 'registered' ? 'NFC card registered successfully' : 'Already registered';
        } else if (!this.pendingMode && response.status === 409 && data.status === 'replacement_required'
            && typeof data.nfc_uid === 'string') {
            this.linkedUid = data.nfc_uid;
            this.expectedUid = data.nfc_uid;
            this.state = 'confirmation';
            this.message = 'This beneficiary already has a different card. Replace it?';
        } else {
            const message = response.status === 419 ? `Your session expired. Reload the ${this.pendingMode ? 'form' : 'profile'} and try again.`
                : response.status === 401 ? 'Please sign in again to register a card.'
                    : response.status === 403 ? 'You do not have permission to register this card.'
                        : response.status === 404 ? 'This beneficiary is no longer available.'
                            : response.status === 422 ? data.errors?.nfc_uid?.[0]
                                : null;
            this.fail(message || 'Could not confirm registration. Please try again.');
            return;
        }
        if (!this.isOpen) {
            this.state = 'idle';
            this.message = '';
        }
        this.onChange(this);
    }

    fail(message) {
        this.state = this.isOpen ? 'error' : 'idle';
        this.message = this.isOpen ? message : '';
        this.onChange(this);
    }

    removePending() {
        if (!this.pendingMode) return;
        this.version++;
        this.checkRequest?.abort();
        this.checkRequest = null;
        this.pendingUid = null;
        this.pendingError = '';
        this.state = 'idle';
        this.reset();
    }

    destroy() {
        this.close();
        this.version++;
        this.onChange = () => {};
    }
}

export function mountNfcRegistration(root) {
    const find = (selector) => root.querySelector(selector);
    const dialog = find('[data-nfc-dialog]');
    const input = find('[data-nfc-input]');
    const manualInput = find('[data-nfc-manual-input]');
    const manualToggle = find('[data-nfc-manual-toggle]');
    const form = find('[data-nfc-scan-form]');
    const openButton = find('[data-nfc-open]');
    const status = find('[data-nfc-status]');
    const lifetime = new AbortController();
    const listen = (element, event, handler) => element?.addEventListener(event, handler, { signal: lifetime.signal });
    const show = (selector, visible) => {
        const element = find(selector);
        if (element) element.hidden = !visible;
    };
    const write = (selector, value) => {
        const element = find(selector);
        if (element) element.textContent = value;
    };
    let wasScanning = false;
    let manualMode = false;
    const activeInput = () => manualMode ? manualInput : input;

    const model = new NfcRegistration({
        ...JSON.parse(root.dataset.config),
        token: find('[name="_token"]').value,
    }, { onChange: render });

    function render(current) {
        if (!current.isOpen) manualMode = false;
        write('[data-nfc-linked-uid]', current.cardUid ?? (current.pendingMode ? 'No card selected' : 'No card linked'));
        show('[data-nfc-unlink]', Boolean(current.linkedUid));
        const pendingInput = find('[data-nfc-pending-input]');
        if (pendingInput) {
            pendingInput.value = current.pendingUid ?? '';
            show('[data-nfc-remove]', Boolean(current.pendingUid));
            find('[data-nfc-remove]').disabled = current.busy;
            const pendingStatus = find('[data-nfc-pending-status]');
            pendingStatus.textContent = current.pendingError || (current.pendingUid ? pendingCardMessage : '');
            pendingStatus.setAttribute('role', current.pendingError ? 'alert' : 'status');
        }
        const saveButton = find('[data-beneficiary-submit]');
        if (saveButton) saveButton.disabled = current.busy;
        openButton.disabled = current.busy;
        dialog.setAttribute('aria-busy', String(current.busy));
        status.textContent = current.message;
        status.setAttribute('role', current.state === 'error' ? 'alert' : 'status');
        status.classList.toggle('text-red-600', current.state === 'error');
        status.classList.toggle('dark:text-red-400', current.state === 'error');
        status.classList.toggle('motion-safe:animate-pulse', current.state === 'idle' && current.scanning && !manualMode);
        find('[data-nfc-card-icon]').hidden = !current.scanning;
        find('[data-nfc-loading-icon]').hidden = !current.busy;
        find('[data-nfc-success-icon]').hidden = !['success', 'already', 'ready'].includes(current.state);
        show('[data-nfc-confirmation]', current.state === 'confirmation');
        write('[data-nfc-existing]', current.expectedUid ?? '');
        write('[data-nfc-candidate]', current.candidate ?? '');
        find('[data-nfc-scan-again]').hidden = !['success', 'already', 'confirmation', 'ready'].includes(current.state);
        form.hidden = !current.scanning;
        input.disabled = !current.scanning || manualMode;
        manualInput.disabled = !current.scanning || !manualMode;
        show('[data-nfc-manual-fields]', current.scanning && manualMode);
        manualToggle.textContent = manualMode ? 'Back to scanning' : 'Enter card manually';
        manualToggle.setAttribute('aria-expanded', String(manualMode));
        if (!current.isOpen) {
            input.value = '';
            manualInput.value = '';
            if (dialog.open) dialog.close();
        }
        if (current.scanning && !wasScanning) {
            queueMicrotask(() => { if (model.scanning && dialog.open) activeInput().focus(); });
        }
        wasScanning = current.scanning;
    }

    listen(openButton, 'click', () => {
        if (model.busy) return;
        manualMode = false;
        if (!model.open()) return;
        input.value = '';
        manualInput.value = '';
        dialog.showModal();
        input.focus();
    });
    function submitCard() {
        if (!dialog.open || !model.scanning) return;
        const value = activeInput().value;
        if (!value.trim()) return;
        // Each reader scan starts fresh, including after a rejected card.
        if (!manualMode) input.value = '';
        void model.submit(value);
    }
    for (const field of [input, manualInput]) {
        listen(field, 'keydown', (event) => {
            if (event.key !== 'Enter' || event.isComposing) return;
            event.preventDefault();
            if (!event.repeat && field === activeInput()) submitCard();
        });
    }
    listen(form, 'submit', (event) => {
        event.preventDefault();
        submitCard();
    });
    listen(manualToggle, 'click', () => {
        if (!dialog.open || !model.scanning) return;
        manualMode = !manualMode;
        input.value = '';
        manualInput.value = '';
        model.reset();
        activeInput().focus();
    });
    for (const button of root.querySelectorAll('[data-nfc-close]')) {
        listen(button, 'click', () => model.close());
    }
    listen(dialog, 'cancel', (event) => {
        event.preventDefault();
        model.close();
    });
    listen(dialog, 'close', () => { if (model.isOpen && !dialog.open) model.close(); });
    listen(find('[data-nfc-replace]'), 'click', () => { void model.confirm(); });
    listen(find('[data-nfc-remove]'), 'click', () => model.removePending());
    listen(find('[data-beneficiary-create-form]'), 'submit', (event) => {
        if (model.busy) event.preventDefault();
        else model.close();
    });
    listen(find('[data-nfc-scan-again]'), 'click', () => {
        manualMode = false;
        input.value = '';
        manualInput.value = '';
        model.reset();
    });
    render(model);

    return () => {
        lifetime.abort();
        model.destroy();
        if (dialog.open) dialog.close();
    };
}

if (typeof document !== 'undefined') {
    let cleanup;
    let activeRoot;
    const initialize = () => {
        const root = document.querySelector('[data-nfc-registration]');
        if (root === activeRoot) return;
        cleanup?.();
        activeRoot = root;
        cleanup = root ? mountNfcRegistration(root) : null;
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
    document.addEventListener('livewire:navigated', initialize);
}
