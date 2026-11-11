// One version covers the debounce, request, response body, and DOM update.
export class RegistryLiveSearch {
    constructor({
        request = (...args) => globalThis.fetch(...args),
        pending,
        apply,
        failed,
        settled,
        schedule = (callback, delay) => globalThis.setTimeout(callback, delay),
        unschedule = (timer) => globalThis.clearTimeout(timer),
    }) {
        Object.assign(this, { request, pending, apply, failed, settled, schedule, unschedule });
        this.version = 0;
        this.controller = null;
        this.timer = null;
        this.url = null;
    }

    change(url, { debounce = false, composing = false } = {}) {
        this.unschedule(this.timer);
        this.controller?.abort();
        const version = ++this.version;
        this.url = url;
        this.pending();
        if (composing) return;
        if (debounce) this.timer = this.schedule(() => this.load(url, version), 250);
        else void this.load(url, version);
    }

    async load(url, version) {
        if (version !== this.version) return;
        const controller = new AbortController();
        this.controller = controller;
        let applied = false;
        try {
            const response = await this.request(url, {
                signal: controller.signal,
                credentials: "same-origin",
                headers: { Accept: "text/html", "X-Registry-Fragment": "results" },
            });
            if (!response.ok || response.redirected) throw new Error("Registry request failed");
            const html = await response.text();
            if (version !== this.version) return;
            this.apply(html, url);
            applied = true;
        } catch (error) {
            if (version !== this.version || error.name === "AbortError") return;
            this.failed();
        } finally {
            if (version === this.version) this.settled(applied);
            if (this.controller === controller) this.controller = null;
        }
    }

    destroy() {
        this.unschedule(this.timer);
        this.controller?.abort();
        this.version++;
    }
}

export function registrySearchUrl(form) {
    const url = new URL(form.action);
    for (const name of ["search", "caseworker", "status"]) {
        const value = form.elements.namedItem(name)?.value.trim();
        if (value) url.searchParams.set(name, value);
        else url.searchParams.delete(name);
    }
    url.searchParams.delete("page");
    return url.href;
}

export function registryRestoreFilters(form, url) {
    for (const name of ["search", "caseworker", "status"]) {
        const filter = form.elements.namedItem(name);
        if (filter) filter.value = new URL(url).searchParams.get(name) || "";
    }
}

// Keep a per-user, per-tab draft through validation redirects. The backend still
// validates every submitted ID and permission; cached permissions only drive UI.
export class RegistrySelectionPersistence {
    constructor(storage, key) {
        Object.assign(this, { storage, key });
    }

    restore(model, completed = false) {
        try {
            if (completed) this.storage?.removeItem(this.key);
            const saved = completed ? null : JSON.parse(this.storage?.getItem(this.key) || "null");
            model.clearSelection();
            model.selecting = false;
            if (!saved?.selecting || !Array.isArray(saved.selected)) return;
            const records = {};
            const selected = [...new Set(saved.selected)]
                .filter((id) => typeof id === "string" && /^[1-9]\d*$/.test(id))
                .filter((id) => {
                    const record = saved.records?.[id];
                    if (
                        !record ||
                        typeof record.name !== "string" ||
                        typeof record.beneficiary_id !== "string" ||
                        typeof record.canArchive !== "boolean" ||
                        typeof record.canAssign !== "boolean"
                    )
                        return false;
                    records[id] = record;
                    return true;
                })
                .slice(0, model.selectionLimit);
            model.records = { ...records, ...model.records };
            model.selected = selected;
            model.selecting = true;
        } catch {
            // Storage restrictions must not prevent selecting the current page.
        }
    }

    save(model) {
        try {
            if (!model.selecting) this.storage?.removeItem(this.key);
            else
                this.storage?.setItem(
                    this.key,
                    JSON.stringify({
                        selecting: true,
                        selected: model.selected,
                        records: Object.fromEntries(
                            model.selected.map((id) => [id, model.records[id]]),
                        ),
                    }),
                );
        } catch {
            // Live updates still preserve the mounted selection without storage.
        }
    }
}

export function mountRegistry(root) {
    const form = root.querySelector(".rlcr-search-form");
    if (!form) return () => {};
    const input = form.elements.namedItem("search");
    const loading = root.querySelector("[data-registry-loading]");
    const error = root.querySelector("[data-registry-error]");
    const lifetime = new AbortController();
    const model = () => window.Alpine.$data(root);
    const listen = (target, name, handler) =>
        target.addEventListener(name, handler, { signal: lifetime.signal });
    let storage;
    try {
        storage = window.sessionStorage;
    } catch {}
    const persistence = new RegistrySelectionPersistence(
        storage,
        root.dataset.registrySelectionKey,
    );
    let selectionReady = false;
    const restoreSelection = () => {
        if (selectionReady) return;
        selectionReady = true;
        persistence.restore(
            model(),
            root.dataset.registrySelectionComplete === "true" ||
                !root.querySelector("[data-start-selection]"),
        );
        model().updatePage(
            JSON.parse(root.querySelector("[data-registry-results]").dataset.registryPage),
        );
        persistence.save(model());
        root.dataset.registrySelectionComplete = "false";
    };
    listen(root, "registry-selection-ready", restoreSelection);
    if (root._x_dataStack) restoreSelection();
    listen(root, "registry-selection-changed", () => persistence.save(model()));
    listen(window, "pageshow", (event) => {
        if (event.persisted) {
            persistence.restore(model());
            model().updatePage(
                JSON.parse(root.querySelector("[data-registry-results]").dataset.registryPage),
            );
        }
    });
    let composing = false;

    const search = new RegistryLiveSearch({
        pending() {
            model().refreshing = true;
            const results = root.querySelector("[data-registry-results]");
            results.inert = true;
            results.setAttribute("aria-busy", "true");
            loading.hidden = false;
            error.hidden = true;
        },
        apply(html, url) {
            const document = new DOMParser().parseFromString(html, "text/html");
            const next = document.querySelector("[data-registry-results]");
            const heading = document.querySelector("[data-registry-caseload-heading]");
            if (!next || !heading) throw new Error("Invalid Registry response");
            const page = JSON.parse(next.dataset.registryPage);
            if (
                !Array.isArray(page.archiveIds) ||
                !Array.isArray(page.selectableIds) ||
                !page.assignmentRecords ||
                typeof page.assignmentRecords !== "object" ||
                !page.selectionRecords ||
                typeof page.selectionRecords !== "object"
            ) {
                throw new Error("Invalid Registry permissions");
            }

            // Keep the Alpine selection owner, search field, and toolbar mounted.
            window.Alpine.mutateDom(() => {
                const previous = root.querySelector("[data-registry-results]");
                window.Alpine.destroyTree(previous);
                previous.replaceWith(next);
                root.querySelector("[data-registry-caseload-heading]").replaceWith(heading);
                model().updatePage(page);
                window.Alpine.initTree(next);
            });

            root.dispatchEvent(
                new CustomEvent("registry-page-updated", {
                    bubbles: true,
                    detail: { assignmentRecords: page.assignmentRecords },
                }),
            );
            const assignmentForm = root.querySelector("[data-assignment-form]");
            if (assignmentForm) {
                const action = new URL(assignmentForm.action);
                action.search = new URL(url).search;
                assignmentForm.action = action.href;
            }
            const create = root.querySelector("[data-registry-create]");
            if (create) create.href = page.createUrl;
            const tab = root.querySelector("[data-registry-tab]");
            const firstPage = new URL(url);
            firstPage.searchParams.delete("page");
            if (tab) tab.href = firstPage.href;
            window.history.replaceState(window.history.state, "", url);
        },
        failed() {
            error.hidden = false;
        },
        settled(applied) {
            loading.hidden = true;
            if (applied) {
                model().refreshing = false;
                const results = root.querySelector("[data-registry-results]");
                results.inert = false;
                results.setAttribute("aria-busy", "false");
            }
        },
    });

    const refresh = (debounce = false) =>
        search.change(registrySearchUrl(form), { debounce, composing });
    listen(input, "input", (event) => {
        composing = event.isComposing || composing;
        refresh(true);
    });
    listen(input, "compositionstart", () => {
        composing = true;
        refresh(true);
    });
    listen(input, "compositionend", () => {
        composing = false;
        refresh(true);
    });
    listen(input, "keydown", (event) => {
        if (event.key === "Enter" && (composing || event.isComposing || event.keyCode === 229))
            event.preventDefault();
    });
    listen(form, "change", (event) => {
        if (["caseworker", "status"].includes(event.target.name)) refresh();
    });
    listen(form, "submit", (event) => {
        event.preventDefault();
        if (!composing) refresh();
    });
    listen(root.querySelector("[data-clear-beneficiary-search]"), "click", () => {
        composing = false;
        input.value = "";
        window.Alpine.$data(input.closest(".rlcr-search-wrap")).term = "";
        input.focus();
        refresh();
    });
    listen(root.querySelector("[data-registry-retry]"), "click", () => search.change(search.url));
    listen(root, "click", (event) => {
        const link = event.target.closest(".rlcr-page-links a, [data-registry-navigation]");
        if (
            !link ||
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        )
            return;
        event.preventDefault();
        const url = new URL(link.href);
        registryRestoreFilters(form, url);
        window.Alpine.$data(input.closest(".rlcr-search-wrap")).term = input.value;
        search.change(url.href);
    });

    return () => {
        lifetime.abort();
        search.destroy();
    };
}

if (typeof document !== "undefined") {
    let cleanup;
    let activeRoot;
    const initialize = () => {
        const root = document.querySelector("[data-registry-selection]");
        if (root === activeRoot) return;
        cleanup?.();
        activeRoot = root;
        cleanup = root ? mountRegistry(root) : null;
    };
    if (document.readyState === "loading")
        document.addEventListener("DOMContentLoaded", initialize, { once: true });
    else initialize();
    document.addEventListener("livewire:navigated", initialize);
    document.addEventListener("livewire:navigating", () => {
        cleanup?.();
        cleanup = null;
        activeRoot = null;
    });
}
