let activeBell = null;

export function accountNotificationBell({
    document: page = globalThis.document,
    request = (...args) => globalThis.fetch(...args),
    schedule = (callback, delay) => globalThis.setInterval(callback, delay),
    unschedule = (timer) => globalThis.clearInterval(timer),
    navigate = (url) => globalThis.location.assign(url),
} = {}) {
    return {
        open: false,
        disposed: false,
        unreadCount: 0,
        items: [],
        activity: [],
        loading: false,
        activityLoading: false,
        countLoading: false,
        marking: false,
        error: "",
        activityError: "",
        countError: "",
        timer: null,
        revision: 0,
        requests: new Set(),
        visibilityHandler: null,
        init() {
            activeBell?.destroy();
            activeBell = this;
            this.disposed = false;
            this.visibilityHandler = () => {
                if (page.hidden) this.stopPolling();
                else {
                    this.startPolling();
                    if (this.open) this.refresh();
                }
            };
            page.addEventListener("visibilitychange", this.visibilityHandler);
            this.startPolling();
        },
        startPolling() {
            this.stopPolling();
            if (page.hidden || this.disposed) return;
            void this.loadCount();
            this.timer = schedule(() => {
                if (page.hidden) return this.stopPolling();
                void this.loadCount();
                if (this.open) void this.refresh();
            }, 30000);
        },
        stopPolling() {
            if (this.timer !== null) unschedule(this.timer);
            this.timer = null;
        },
        async json(url, method = "GET") {
            const controller = new AbortController();
            this.requests.add(controller);
            try {
                const response = await request(url, {
                    method,
                    credentials: "same-origin",
                    cache: "no-store",
                    signal: controller.signal,
                    headers: {
                        Accept: "application/json",
                        ...(method === "POST" ? { "X-CSRF-TOKEN": this.$root.dataset.csrf } : {}),
                    },
                });
                if (!response.ok) throw new Error("Notification request failed");
                return await response.json();
            } finally {
                this.requests.delete(controller);
            }
        },
        async loadCount() {
            if (this.countLoading || this.disposed || page.hidden) return;
            this.countLoading = true;
            const revision = this.revision;
            try {
                const data = await this.json(this.$root.dataset.countEndpoint);
                if (!Number.isSafeInteger(data.unread_count) || data.unread_count < 0)
                    throw new Error("Invalid count");
                if (!this.disposed && revision === this.revision) {
                    this.unreadCount = data.unread_count;
                    this.countError = "";
                }
            } catch (error) {
                if (!this.disposed && error.name !== "AbortError")
                    this.countError = "Unread count is unavailable. Try Refresh.";
            } finally {
                this.countLoading = false;
            }
        },
        async loadItems() {
            if (this.loading || this.disposed) return;
            this.loading = true;
            this.error = "";
            const revision = this.revision;
            try {
                const data = await this.json(this.$root.dataset.endpoint);
                if (!Array.isArray(data.items) || !Number.isSafeInteger(data.unread_count))
                    throw new Error("Invalid notifications");
                if (!this.disposed && revision === this.revision) {
                    this.items = data.items;
                    this.unreadCount = data.unread_count;
                }
            } catch (error) {
                if (!this.disposed && error.name !== "AbortError")
                    this.error = "Unable to load notifications. Try Refresh.";
            } finally {
                this.loading = false;
            }
        },
        async loadActivity() {
            const url = this.$root.dataset.activityEndpoint;
            if (!url || this.activityLoading || this.disposed) return;
            this.activityLoading = true;
            this.activityError = "";
            try {
                const data = await this.json(url);
                if (!Array.isArray(data.items)) throw new Error("Invalid activity");
                if (!this.disposed) this.activity = data.items;
            } catch (error) {
                if (!this.disposed && error.name !== "AbortError")
                    this.activityError = "Unable to load activity. Try Refresh.";
            } finally {
                this.activityLoading = false;
            }
        },
        refresh() {
            return Promise.all([this.loadCount(), this.loadItems(), this.loadActivity()]);
        },
        toggle() {
            this.open = !this.open;
            if (this.open) void this.refresh();
        },
        close() {
            this.open = false;
        },
        async markRead(item = null) {
            if (this.marking || this.disposed) return false;
            if (item?.read_at) return true;
            this.marking = true;
            this.error = "";
            ++this.revision;
            try {
                const data = await this.json(
                    item ? item.read_url : this.$root.dataset.readAllEndpoint,
                    "POST",
                );
                if (this.disposed) return false;
                this.unreadCount = data.unread_count;
                if (item) item.read_at = data.read_at;
                else
                    this.items.forEach((entry) => {
                        entry.read_at ||= new Date().toISOString();
                    });
                return true;
            } catch (error) {
                if (!this.disposed && error.name !== "AbortError")
                    this.error = "Unable to mark notifications as read. Try again.";
                return false;
            } finally {
                this.marking = false;
            }
        },
        async visit(item) {
            if (await this.markRead(item)) navigate(item.url);
        },
        destroy() {
            this.disposed = true;
            this.stopPolling();
            page.removeEventListener("visibilitychange", this.visibilityHandler);
            this.requests.forEach((controller) => controller.abort());
            this.requests.clear();
            if (activeBell === this) activeBell = null;
        },
    };
}

if (typeof document !== "undefined") {
    const register = () =>
        window.Alpine.data("accountNotificationBell", () => accountNotificationBell());
    if (window.Alpine) register();
    else document.addEventListener("alpine:init", register, { once: true });
}
