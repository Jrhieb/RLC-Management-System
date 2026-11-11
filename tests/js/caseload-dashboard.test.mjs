import { afterEach, test } from "node:test";
import assert from "node:assert/strict";
import { caseloadDashboard } from "../../resources/js/caseload-dashboard.js";

const cleanups = [];
afterEach(() => cleanups.splice(0).forEach((model) => model.destroy()));
const settle = () => new Promise((resolve) => setImmediate(resolve));
const deferred = () => {
    let resolve;
    const promise = new Promise((yes) => {
        resolve = yes;
    });
    return { promise, resolve };
};
const response = (counts = { total: 3, active: 2, inactive: 1 }, preview = "fresh rows") => ({
    ok: true,
    redirected: false,
    json: async () => ({ counts, preview }),
});

function fixture({ hidden = false, request: customRequest } = {}) {
    const listeners = new Set(),
        timers = new Map(),
        calls = [],
        replacements = [];
    let timer = 0;
    const counts = Object.fromEntries(
        ["total", "active", "inactive"].map((key) => [key, { textContent: "0" }]),
    );
    const search = { value: "000007", selectionStart: 3, selectionEnd: 5 };
    const page = {
        hidden,
        activeElement: search,
        addEventListener(event, callback) {
            assert.equal(event, "visibilitychange");
            listeners.add(callback);
        },
        removeEventListener(event, callback) {
            listeners.delete(callback);
        },
    };
    let preview = {
        rows: "initial rows",
        dataset: { caseloadPreviewVersion: "initial rows" },
        contains: (element) => element === "preview-link",
    };
    const root = {
        dataset: { endpoint: "/dashboard/caseload" },
        querySelector(selector) {
            if (selector === "[data-caseload-preview]") return preview;
            const key = selector.match(/data-caseload-count="(\w+)"/)?.[1];
            assert.ok(key, "Only counts and preview are queried");
            return counts[key];
        },
    };
    const model = caseloadDashboard({
        document: page,
        request: (url, options) => {
            calls.push({ url, options });
            return customRequest ? customRequest(url, options) : Promise.resolve(response());
        },
        schedule(callback, delay) {
            assert.equal(delay, 30000);
            timers.set(++timer, callback);
            return timer;
        },
        unschedule: (id) => timers.delete(id),
        parse: (html) =>
            html === "invalid"
                ? null
                : {
                      rows: html,
                      dataset: { caseloadPreviewVersion: html },
                      contains: (element) => element === "preview-link",
                  },
        replace(previous, next) {
            assert.equal(previous, preview);
            preview = next;
            replacements.push(next.rows);
        },
    });
    model.$root = root;
    cleanups.push(model);
    return {
        model,
        page,
        root,
        search,
        counts,
        timers,
        listeners,
        calls,
        replacements,
        tick() {
            [...timers.values()].forEach((callback) => callback());
        },
        visibility(hidden) {
            page.hidden = hidden;
            [...listeners].forEach((callback) => callback());
        },
    };
}

test("visible dashboard polls only its small endpoint and keeps the server-rendered initial data", async () => {
    const f = fixture();
    f.model.init();
    assert.equal(f.calls.length, 0);
    assert.equal(f.timers.size, 1);
    f.tick();
    await settle();
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].url, "/dashboard/caseload");
    assert.equal(f.calls[0].options.credentials, "same-origin");
    assert.equal(f.calls[0].options.cache, "no-store");
    assert.deepEqual(f.calls[0].options.headers, { Accept: "application/json" });
    assert.deepEqual(
        Object.fromEntries(Object.entries(f.counts).map(([key, node]) => [key, node.textContent])),
        { total: "3", active: "2", inactive: "1" },
    );
    assert.deepEqual(f.replacements, ["fresh rows"]);
});

test("unchanged preview stays mounted without reloading protected photos while counts still refresh", async () => {
    let number = 0;
    const f = fixture({
        request: async () =>
            response({ total: ++number, active: number, inactive: 0 }, "same first five"),
    });
    await f.model.refresh();
    await f.model.refresh();
    assert.deepEqual(f.replacements, ["same first five"]);
    assert.equal(f.counts.total.textContent, "2");
});

test("refresh leaves the search value, caret and focused input mounted", async () => {
    const f = fixture();
    const focused = f.page.activeElement;
    await f.model.refresh();
    assert.equal(f.page.activeElement, focused);
    assert.equal(f.search.value, "000007");
    assert.deepEqual([f.search.selectionStart, f.search.selectionEnd], [3, 5]);
    assert.equal(f.replacements.length, 1);
});

test("a focused preview link stays mounted while counts update and rows catch up when focus moves", async () => {
    const f = fixture();
    f.page.activeElement = "preview-link";
    await f.model.refresh();
    assert.equal(f.counts.total.textContent, "3");
    assert.equal(f.replacements.length, 0);
    assert.equal(f.page.activeElement, "preview-link");
    f.page.activeElement = f.search;
    await f.model.refresh();
    assert.equal(f.replacements.length, 1);
});

test("hidden documents neither poll nor request and visibility resumes with exactly one timer", async () => {
    const f = fixture({ hidden: true });
    f.model.init();
    await f.model.refresh();
    assert.equal(f.timers.size, 0);
    assert.equal(f.calls.length, 0);
    f.visibility(false);
    await settle();
    assert.equal(f.calls.length, 1);
    assert.equal(f.timers.size, 1);
    f.visibility(false);
    await settle();
    assert.equal(f.timers.size, 1);
    f.visibility(true);
    f.tick();
    await settle();
    assert.equal(f.timers.size, 0);
    assert.equal(f.calls.length, 2);
});

test("polling and manual refresh cannot overlap requests", async () => {
    const pending = deferred();
    const f = fixture({ request: () => pending.promise });
    f.model.init();
    f.tick();
    f.tick();
    void f.model.refresh();
    assert.equal(f.calls.length, 1);
    pending.resolve(response());
    await settle();
    assert.equal(f.model.refreshing, false);
    f.tick();
    await settle();
    assert.equal(f.calls.length, 2);
});

test("hiding aborts an in-flight refresh and an old response cannot overwrite fresh assignments", async () => {
    const old = deferred(),
        latest = deferred();
    let number = 0;
    const f = fixture({ request: () => (++number === 1 ? old : latest).promise });
    f.model.init();
    f.tick();
    f.visibility(true);
    assert.equal(f.calls[0].options.signal.aborted, true);
    f.visibility(false);
    assert.equal(f.calls.length, 2);
    latest.resolve(response({ total: 1, active: 1, inactive: 0 }, "reassigned rows"));
    await settle();
    old.resolve(response({ total: 99, active: 99, inactive: 0 }, "stale rows"));
    await settle();
    assert.equal(f.counts.total.textContent, "1");
    assert.deepEqual(f.replacements, ["reassigned rows"]);
});

test("navigation replacement destroys old timers/listeners and aborts its requests", async () => {
    const pending = deferred();
    const old = fixture({ request: () => pending.promise });
    old.model.init();
    old.tick();
    const next = fixture();
    next.model.init();
    assert.equal(old.model.disposed, true);
    assert.equal(old.timers.size, 0);
    assert.equal(old.listeners.size, 0);
    assert.equal(old.calls[0].options.signal.aborted, true);
    assert.equal(next.timers.size, 1);
    old.model.destroy();
    assert.equal(next.timers.size, 1);
    pending.resolve(response());
    await settle();
    assert.equal(old.replacements.length, 0);
    next.model.destroy();
    assert.equal(next.timers.size, 0);
    assert.equal(next.listeners.size, 0);
});

test("reinitializing the same dashboard cleans up its request and keeps exactly one timer and listener", async () => {
    const pending = deferred();
    const f = fixture({ request: () => pending.promise });
    f.model.init();
    f.tick();
    f.model.init();
    assert.equal(f.calls[0].options.signal.aborted, true);
    assert.equal(f.model.disposed, false);
    assert.equal(f.model.refreshing, false);
    assert.equal(f.timers.size, 1);
    assert.equal(f.listeners.size, 1);
    pending.resolve(response());
    await settle();
    assert.equal(f.replacements.length, 0);
    f.tick();
    await settle();
    assert.equal(f.calls.length, 2);
    assert.equal(f.replacements.length, 1);
});

for (const [name, result] of [
    ["HTTP access denial", { ok: false }],
    ["session redirect", { ok: true, redirected: true }],
    ["invalid count", response({ total: -1, active: 0, inactive: 0 })],
    ["invalid preview", response({ total: 3, active: 2, inactive: 1 }, "invalid")],
]) {
    test(`${name} preserves previous counts and preview with accessible retry feedback`, async () => {
        const f = fixture({ request: async () => result });
        await f.model.refresh();
        assert.equal(f.counts.total.textContent, "0");
        assert.equal(f.replacements.length, 0);
        assert.match(f.model.error, /Showing previous results/);
        assert.equal(f.model.refreshing, false);
    });
}

test("network errors show a safe message and the next successful refresh clears it", async () => {
    let number = 0;
    const f = fixture({
        request: async () => {
            if (++number === 1) throw new Error("Private transport details");
            return response();
        },
    });
    await f.model.refresh();
    assert.match(f.model.error, /Try Refresh caseload/);
    assert.ok(!f.model.error.includes("Private"));
    await f.model.refresh();
    assert.equal(f.model.error, "");
    assert.equal(f.replacements.length, 1);
});
