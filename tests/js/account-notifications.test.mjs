import { afterEach, test } from "node:test";
import assert from "node:assert/strict";
import { accountNotificationBell } from "../../resources/js/account-notifications.js";

const cleanup = [];
afterEach(() => cleanup.splice(0).forEach((bell) => bell.destroy()));
const settle = () => new Promise((resolve) => setImmediate(resolve));
const response = (data, ok = true) => ({ ok, json: async () => data });

function harness({ hidden = false, request: customRequest, activity = true } = {}) {
    const listeners = new Set();
    const timers = new Map();
    const calls = [];
    const destinations = [];
    let timerId = 0;
    const page = {
        hidden,
        addEventListener: (event, callback) => {
            assert.equal(event, "visibilitychange");
            listeners.add(callback);
        },
        removeEventListener: (event, callback) => listeners.delete(callback),
    };
    const bell = accountNotificationBell({
        document: page,
        request: (url, options) => {
            calls.push({ url, options });
            return customRequest
                ? customRequest(url, options)
                : Promise.resolve(response({ unread_count: 3, items: [] }));
        },
        schedule: (callback, delay) => {
            assert.equal(delay, 30000);
            timers.set(++timerId, callback);
            return timerId;
        },
        unschedule: (id) => timers.delete(id),
        navigate: (url) => destinations.push(url),
    });
    bell.$root = {
        dataset: {
            endpoint: "/account/notifications",
            countEndpoint: "/account/notifications/unread-count",
            readAllEndpoint: "/account/notifications/read-all",
            csrf: "csrf-fixture",
            activityEndpoint: activity ? "/dashboard/notifications" : "",
        },
    };
    cleanup.push(bell);
    return {
        bell,
        page,
        timers,
        calls,
        destinations,
        listeners,
        visibility(hidden) {
            page.hidden = hidden;
            listeners.forEach((callback) => callback());
        },
        tick() {
            [...timers.values()].forEach((callback) => callback());
        },
    };
}

test("unread count loads and refreshes while visible even with the dropdown closed", async () => {
    const h = harness();
    h.bell.init();
    await settle();
    assert.equal(h.bell.open, false);
    assert.equal(h.bell.unreadCount, 3);
    assert.equal(h.timers.size, 1);
    h.tick();
    await settle();
    assert.equal(h.calls.length, 2);
    assert.ok(h.calls.every(({ url }) => url.endsWith("/unread-count")));
    h.bell.toggle();
    await settle();
    assert.ok(h.calls.some(({ url }) => url === "/dashboard/notifications"));
    assert.ok(h.calls.some(({ url }) => url === "/account/notifications"));
    h.bell.close();
    assert.equal(h.timers.size, 1);
    h.tick();
    await settle();
    assert.equal(h.calls.at(-1).url, "/account/notifications/unread-count");
});

test("hidden pages do not poll and repeated visibility changes keep only one timer", async () => {
    const h = harness({ hidden: true });
    h.bell.init();
    assert.equal(h.calls.length, 0);
    assert.equal(h.timers.size, 0);
    h.visibility(false);
    await settle();
    assert.equal(h.calls.length, 1);
    assert.equal(h.timers.size, 1);
    h.visibility(false);
    await settle();
    assert.equal(h.timers.size, 1);
    h.visibility(true);
    assert.equal(h.timers.size, 0);
    h.tick();
    await settle();
    assert.equal(h.calls.length, 2);
});

test("navigation replacement aborts old requests and removes old timers and listeners", async () => {
    const old = harness({
        request: (url, options) =>
            new Promise((resolve, reject) => {
                options.signal.addEventListener("abort", () =>
                    reject(Object.assign(new Error("Aborted"), { name: "AbortError" })),
                );
            }),
    });
    old.bell.init();
    const current = harness();
    current.bell.init();
    await settle();
    assert.equal(old.timers.size, 0);
    assert.equal(old.listeners.size, 0);
    assert.equal(old.calls[0].options.signal.aborted, true);
    assert.equal(old.bell.disposed, true);
    assert.equal(old.bell.countError, "");
    assert.equal(current.timers.size, 1);
    old.bell.destroy();
    assert.equal(current.timers.size, 1);
    current.bell.destroy();
    assert.equal(current.timers.size, 0);
    assert.equal(current.listeners.size, 0);
});

test("restricted-account bells never request the activity feed", async () => {
    const h = harness({ activity: false });
    h.bell.init();
    h.bell.toggle();
    await settle();
    h.tick();
    await settle();
    assert.ok(h.calls.every(({ url }) => url.startsWith("/account/notifications")));
});

test("marking read uses recipient-owned endpoint and CSRF and following the link marks it first", async () => {
    const h = harness({
        request: async (url, options) =>
            response(
                options.method === "POST"
                    ? { unread_count: 0, read_at: "2026-10-09T01:00:00Z" }
                    : { unread_count: 1, items: [] },
            ),
    });
    h.bell.init();
    await settle();
    const item = {
        read_at: null,
        read_url: "/account/notifications/uuid/read",
        url: "/account/status",
    };
    await h.bell.visit(item);
    assert.equal(h.calls.at(-1).options.method, "POST");
    assert.equal(h.calls.at(-1).options.headers["X-CSRF-TOKEN"], "csrf-fixture");
    assert.equal(h.calls.at(-1).options.credentials, "same-origin");
    assert.equal(h.bell.unreadCount, 0);
    assert.equal(item.read_at, "2026-10-09T01:00:00Z");
    assert.deepEqual(h.destinations, ["/account/status"]);
    const before = h.calls.length;
    await h.bell.markRead(item);
    assert.equal(h.calls.length, before);
});

test("assignment notices use the existing polling and read controls before visiting My Caseload", async () => {
    const item = {
        id: "assignment-notice",
        title: "Caseload updated",
        message: "Your caseload changed: 3 added, 2 removed.",
        read_at: null,
        read_url: "/account/notifications/assignment-notice/read",
        url: "/my-caseload",
    };
    let unread = 0;
    const h = harness({
        request: async (url, options) => {
            if (options.method === "POST") {
                unread = 0;
                return response({ unread_count: 0, read_at: "2026-10-10T01:00:00Z" });
            }
            return response({ unread_count: unread, items: unread ? [item] : [] });
        },
    });
    h.bell.init();
    await settle();
    assert.equal(h.bell.unreadCount, 0);
    unread = 1;
    h.tick();
    await settle();
    assert.equal(h.bell.unreadCount, 1);
    assert.equal(h.timers.size, 1);
    h.bell.toggle();
    await settle();
    assert.equal(h.bell.items[0].message, "Your caseload changed: 3 added, 2 removed.");
    await h.bell.visit(h.bell.items[0]);
    assert.equal(h.calls.at(-1).url, item.read_url);
    assert.equal(h.calls.at(-1).options.method, "POST");
    assert.equal(item.read_at, "2026-10-10T01:00:00Z");
    assert.equal(h.bell.unreadCount, 0);
    assert.deepEqual(h.destinations, ["/my-caseload"]);
    assert.equal(h.timers.size, 1);
});

test("a failed mark-read does not navigate or remove the unread state", async () => {
    const h = harness({
        request: async (url, options) => response({ unread_count: 1 }, options.method !== "POST"),
    });
    h.bell.init();
    await settle();
    const item = {
        read_at: null,
        read_url: "/account/notifications/uuid/read",
        url: "/account/status",
    };
    await h.bell.visit(item);
    assert.deepEqual(h.destinations, []);
    assert.equal(item.read_at, null);
    assert.equal(h.bell.unreadCount, 1);
    assert.match(h.bell.error, /Unable to mark/);
    assert.equal(h.bell.marking, false);
});

test("an old count response cannot restore the unread count after marking read", async () => {
    let resolveCount;
    const h = harness({
        request: (url, options) =>
            options.method === "POST"
                ? Promise.resolve(response({ unread_count: 0, read_at: "2026-10-09T01:00:00Z" }))
                : new Promise((resolve) => {
                      resolveCount = resolve;
                  }),
    });
    h.bell.init();
    const item = { read_at: null, read_url: "/account/notifications/uuid/read" };
    await h.bell.markRead(item);
    resolveCount(response({ unread_count: 8 }));
    await settle();
    assert.equal(h.bell.unreadCount, 0);
});

test("mark all read preserves existing read timestamps and uses its own POST endpoint", async () => {
    const h = harness({ request: async () => response({ unread_count: 0 }) });
    h.bell.items = [{ read_at: null }, { read_at: "already-read" }];
    assert.equal(await h.bell.markRead(), true);
    assert.equal(h.calls[0].url, "/account/notifications/read-all");
    assert.equal(h.calls[0].options.method, "POST");
    assert.ok(h.bell.items[0].read_at);
    assert.equal(h.bell.items[1].read_at, "already-read");
});

test("count and activity failures use separate messages and preserve the last valid count", async () => {
    const h = harness({
        request: async () => response({ message: "Private server error" }, false),
    });
    h.bell.unreadCount = 2;
    await h.bell.loadCount();
    await h.bell.loadActivity();
    assert.equal(h.bell.unreadCount, 2);
    assert.match(h.bell.countError, /Unread count is unavailable/);
    assert.match(h.bell.activityError, /Unable to load activity/);
    assert.ok(!JSON.stringify(h.bell).includes("Private server error"));
});
