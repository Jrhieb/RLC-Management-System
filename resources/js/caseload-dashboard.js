let activeDashboard = null;

export function caseloadDashboard({
    document: page = globalThis.document,
    request = (...args) => globalThis.fetch(...args),
    schedule = (callback, delay) => globalThis.setInterval(callback, delay),
    unschedule = (timer) => globalThis.clearInterval(timer),
    parse = (html) =>
        new DOMParser().parseFromString(html, "text/html").querySelector("[data-caseload-preview]"),
    replace = (previous, next) => {
        const alpine = globalThis.Alpine;
        if (alpine)
            alpine.mutateDom(() => {
                alpine.destroyTree(previous);
                previous.replaceWith(next);
                alpine.initTree(next);
            });
        else previous.replaceWith(next);
    },
} = {}) {
    return {
        disposed: false,
        refreshing: false,
        error: "",
        timer: null,
        controller: null,
        version: 0,
        visibilityHandler: null,
        init() {
            activeDashboard?.destroy();
            activeDashboard = this;
            this.disposed = false;
            this.visibilityHandler = () => {
                if (page.hidden) this.pause();
                else {
                    this.startPolling();
                    void this.refresh();
                }
            };
            page.addEventListener("visibilitychange", this.visibilityHandler);
            // Initial counts/preview are server-rendered; wait for the first tick.
            this.startPolling();
        },
        startPolling() {
            this.stopPolling();
            if (this.disposed || page.hidden) return;
            this.timer = schedule(() => void this.refresh(), 30000);
        },
        stopPolling() {
            if (this.timer !== null) unschedule(this.timer);
            this.timer = null;
        },
        pause() {
            this.stopPolling();
            ++this.version;
            this.controller?.abort();
            this.controller = null;
            this.refreshing = false;
        },
        async refresh() {
            if (this.disposed || page.hidden || this.refreshing) return;
            this.refreshing = true;
            const controller = new AbortController();
            this.controller = controller;
            const version = ++this.version;
            try {
                const response = await request(this.$root.dataset.endpoint, {
                    signal: controller.signal,
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: { Accept: "application/json" },
                });
                if (!response.ok || response.redirected) throw new Error("Caseload refresh failed");
                const data = await response.json();
                if (this.disposed || page.hidden || version !== this.version) return;
                if (
                    !["total", "active", "inactive"].every(
                        (key) => Number.isSafeInteger(data.counts?.[key]) && data.counts[key] >= 0,
                    ) ||
                    typeof data.preview !== "string"
                )
                    throw new Error("Invalid caseload response");
                const next = parse(data.preview);
                if (!next) throw new Error("Invalid caseload preview");
                for (const key of ["total", "active", "inactive"]) {
                    this.$root.querySelector(`[data-caseload-count="${key}"]`).textContent =
                        new Intl.NumberFormat("en").format(data.counts[key]);
                }
                const previous = this.$root.querySelector("[data-caseload-preview]");
                // Search/toolbar are never replaced. Keep a focused preview link
                // mounted too; its rows catch up on the next unfocused refresh.
                if (
                    previous.dataset.caseloadPreviewVersion !==
                        next.dataset.caseloadPreviewVersion &&
                    !previous.contains(page.activeElement)
                )
                    replace(previous, next);
                this.error = "";
            } catch (error) {
                if (!this.disposed && version === this.version && error.name !== "AbortError")
                    this.error =
                        "Unable to refresh your caseload. Showing previous results. Try Refresh caseload.";
            } finally {
                if (this.controller === controller) {
                    this.controller = null;
                    this.refreshing = false;
                }
            }
        },
        destroy() {
            this.disposed = true;
            this.pause();
            page.removeEventListener("visibilitychange", this.visibilityHandler);
            if (activeDashboard === this) activeDashboard = null;
        },
    };
}

if (typeof document !== "undefined") {
    const register = () => window.Alpine.data("caseloadDashboard", () => caseloadDashboard());
    if (window.Alpine) register();
    else document.addEventListener("alpine:init", register, { once: true });
}
